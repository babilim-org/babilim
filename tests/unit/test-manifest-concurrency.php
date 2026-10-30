<?php
/**
 * A real, two-process concurrency probe for Babilim_Manifest::save()'s
 * own cross-request locking -- not a simulation inside one PHP process
 * (this repo's own Babilim_Memory_Fs runs every call sequentially, so a
 * PHPUnit test built on it can prove the read-modify-write MERGE logic
 * correct, but can never actually race two writers against each other
 * the way two real WordPress requests would).
 *
 * Spawns two real, separate `php` subprocesses (each one a fresh
 * `require tests/bootstrap.php`, modeling one fresh WordPress request:
 * every `new Babilim_Manifest(new Babilim_Wp_Fs())` inside one process
 * reads `_manifest.json` once, at construction, exactly like production)
 * plus a third, read-only subprocess, all against one shared temp
 * directory and the SAME real WordPress core WP_Filesystem_Direct class
 * this suite pins for it (tools/wp-core/class-wp-filesystem-direct.php,
 * fetched by `make tools` -- see tests/support/manifest-concurrency-worker.php's
 * own docblock for why this repo's own, simpler fake filesystem isn't
 * what this probe needs: its move() is a plain rename(), never real
 * core's own delete-then-rename WP_Filesystem_Direct::move()). Each
 * worker's own writes alternate between one record every worker
 * contends on together (the real conflict case) and a record only that
 * one worker ever touches (the real "two separate requests, two
 * separate records" case).
 *
 * This is the exact same probe methodology, at reduced scale (a single
 * round of ~200 operations total, not the original investigation's own
 * ten rounds of ~4000), that first measured just how far short a
 * hash-compare-only version of save() (no lock) fell of "two records,
 * two writes, nothing lost": roughly a quarter of distinct records
 * lost, and hundreds of silent same-record overwrites, on real
 * WordPress core filesystem code (see docs/playground-results.md).
 *
 * What the workers share besides the directory is one options table
 * (tests/bootstrap.php's shared mode): every $wpdb statement on it is
 * exactly as atomic as the same statement on a real database and no
 * more, and the Options API on top of it reproduces WordPress core's own
 * per-request object caching and check-then-upsert add_option() -- never
 * an idealized mutex. A lock that is not genuinely atomic on a real site
 * therefore fails here too: the add_option()-based version this lock
 * replaced stalls one worker on every write it attempts and fails this
 * test on its time limit.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Manifest_Concurrency extends TestCase {

	private const ITERATIONS_PER_WORKER = 40;
	private const READER_SECONDS        = 2.0;

	/**
	 * Hard wall-clock limit for the whole probe, measured from the moment
	 * the three subprocesses are started (not from when their output has
	 * been read): past this, every subprocess still running is killed
	 * and the test fails.
	 */
	private const PROCESS_TIMEOUT = 45;

	/**
	 * Upper bound the probe's own elapsed time must stay within. The
	 * locked version completes in a few seconds; a lock that stalls a
	 * waiter until its own retry budget runs out (the failure shape an
	 * add_option()-based lock shows under WordPress's own option caching)
	 * blows well past this.
	 */
	private const MAX_ELAPSED_SECONDS = 20.0;

	/**
	 * Minimum share of all write attempts that must succeed. A genuine
	 * same-record conflict is the only acceptable failure; those are a
	 * small minority of the shared-record half of the attempts.
	 */
	private const MIN_SUCCESS_RATIO = 0.9;

	/** @var string|null */
	private $shared_dir;

	protected function setUp(): void {
		$plugin_dir = dirname( __DIR__, 2 );
		if ( ! file_exists( $plugin_dir . '/tools/wp-core/class-wp-filesystem-base.php' )
			|| ! file_exists( $plugin_dir . '/tools/wp-core/class-wp-filesystem-direct.php' )
		) {
			$this->markTestSkipped( 'Real WordPress core filesystem classes are not present under tools/wp-core/ -- run `make tools` (pinned by tag+SHA256 in the Makefile) before this test can run for real.' );
		}
		// Under BABILIM_TEST_TMP_ROOT (this PHPUnit process's own root,
		// removed when it exits -- see tests/bootstrap.php's own
		// docblock and tests/unit/test-hygiene.php), never the bare
		// system temp directory directly, even though the two worker
		// subprocesses spawned below each then define their OWN,
		// separate WP_LANG_DIR/ABSPATH under this exact directory
		// (never a fresh process root of their own -- they are
		// deliberately not full, independent bootstrap.php runs in that
		// sense, just this same directory tree).
		$this->shared_dir = BABILIM_TEST_TMP_ROOT . '/manifest-concurrency-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->shared_dir, 0777, true );
	}

	protected function tearDown(): void {
		if ( null !== $this->shared_dir ) {
			$this->rmtree( $this->shared_dir );
		}
	}

	/**
	 * The property this class's own cross-request locking exists for:
	 * two real, concurrent writers lose no distinct record, never
	 * silently let
	 * one same-record write clobber another, a concurrent reader never
	 * catches the file missing once it has ever existed, and the final
	 * file is always valid JSON.
	 *
	 * A genuine same-record conflict (both workers' own "shared" writes
	 * landing at close to the same moment, with neither's own baseline
	 * matching the other's already-written value) is expected and
	 * healthy, not asserted against here -- see
	 * Babilim_Manifest::write_conflict()'s own docblock and
	 * Test_Manifest's own coverage of that exact mechanism; the
	 * property this test exists to prove is that a conflict is always
	 * *reported*, never silently lost -- and that it is the ONLY reason a
	 * write may fail: a write dropped for any other reason (a lock that
	 * timed out, say) is itself a lost write, however cleanly the rest of
	 * the run went. Hence the floor on successful writes and the ceiling
	 * on elapsed time, too: a lock that makes writers wait out their
	 * whole retry budget (or never finish at all) must fail this test,
	 * not pass it with fewer writes to check.
	 */
	public function test_two_real_processes_writing_concurrently_lose_nothing_and_never_see_a_missing_file(): void {
		$result = $this->run_probe();

		$this->assertSame( array(), $result['lost_distinct'], 'a distinct record whose own set() returned true must always be on the final disk' );
		$this->assertSame( 0, $result['same_silent_overwrite'], 'two successful same-record writes must never share an identical baseline (one clobbering the other without either reporting it)' );
		$this->assertSame( 0, $result['reader']['missing_after_seen'], 'once the manifest file has existed, a concurrent reader must never see it missing again' );
		$this->assertTrue( $result['final_valid_json'], 'the final _manifest.json must be valid JSON' );

		// A write may only fail for one reason: a genuine same-record
		// conflict, reported as such. A distinct record never conflicts
		// with anything, so any failure there (a lock that timed out, a
		// write that didn't land) is a dropped write.
		$this->assertSame( array(), $result['unexpected_failures'], 'the only acceptable write failure is a same-record write reporting a genuine conflict' );
		$this->assertGreaterThanOrEqual(
			(int) ceil( self::MIN_SUCCESS_RATIO * $result['attempts'] ),
			$result['succeeded'],
			sprintf( 'at least %d%% of %d write attempts must succeed; %d did', (int) ( 100 * self::MIN_SUCCESS_RATIO ), $result['attempts'], $result['succeeded'] )
		);
		$this->assertSame( 2 * self::ITERATIONS_PER_WORKER, $result['attempts'], 'every worker iteration must have been attempted (none skipped as unavailable)' );
		$this->assertLessThanOrEqual( self::MAX_ELAPSED_SECONDS, $result['elapsed'], sprintf( 'the probe took %.1fs -- a writer stalled waiting for a lock', $result['elapsed'] ) );
	}

	/**
	 * Runs the actual probe: two real `php` subprocesses in `mixed`
	 * mode, one real `php` subprocess in `reader` mode, all started
	 * together against the same shared directory, waited for, then
	 * analyzed the same way tests/support/manifest-concurrency-worker.php's
	 * own docblock describes.
	 *
	 * @return array{lost_distinct: string[], same_silent_overwrite: int,
	 *               reader: array, final_valid_json: bool,
	 *               unexpected_failures: array, attempts: int,
	 *               succeeded: int, elapsed: float}
	 */
	private function run_probe(): array {
		$plugin_dir = dirname( __DIR__, 2 );
		$worker     = $plugin_dir . '/tests/support/manifest-concurrency-worker.php';
		$php        = PHP_BINARY;

		$specs = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );

		$started = microtime( true );
		$procs   = array(
			'w1' => proc_open( array( $php, $worker, $plugin_dir, $this->shared_dir, '1', 'mixed', (string) self::ITERATIONS_PER_WORKER ), $specs, $w1_pipes ),
			'w2' => proc_open( array( $php, $worker, $plugin_dir, $this->shared_dir, '2', 'mixed', (string) self::ITERATIONS_PER_WORKER ), $specs, $w2_pipes ),
			'rd' => proc_open( array( $php, $worker, $plugin_dir, $this->shared_dir, 'R', 'reader', (string) self::READER_SECONDS ), $specs, $rd_pipes ),
		);
		$pipes = array( 'w1' => $w1_pipes, 'w2' => $w2_pipes, 'rd' => $rd_pipes );

		$this->assertNotFalse( $procs['w1'], 'failed to start worker 1' );
		$this->assertNotFalse( $procs['w2'], 'failed to start worker 2' );
		$this->assertNotFalse( $procs['rd'], 'failed to start the reader' );

		$outputs = $this->collect( $procs, $pipes, $started );
		$elapsed = microtime( true ) - $started;
		$w1_out  = $outputs['w1'];
		$w2_out  = $outputs['w2'];
		$rd_out  = $outputs['rd'];

		$log1     = json_decode( $this->last_line( $w1_out['stdout'] ), true );
		$log2     = json_decode( $this->last_line( $w2_out['stdout'] ), true );
		$rd_stats = json_decode( $this->last_line( $rd_out['stdout'] ), true );

		$this->assertIsArray( $log1, "worker 1 did not produce a valid JSON log -- stderr:\n" . $w1_out['stderr'] . "\nstdout:\n" . $w1_out['stdout'] );
		$this->assertIsArray( $log2, "worker 2 did not produce a valid JSON log -- stderr:\n" . $w2_out['stderr'] . "\nstdout:\n" . $w2_out['stdout'] );
		$this->assertIsArray( $rd_stats, "the reader did not produce valid JSON stats -- stderr:\n" . $rd_out['stderr'] . "\nstdout:\n" . $rd_out['stdout'] );

		$manifest_path = $this->shared_dir . '/lang/babilim/_manifest.json';
		$raw           = @file_get_contents( $manifest_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the file's own presence is itself part of what this test checks.
		$decoded       = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$final_valid   = is_string( $raw ) && JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
		$records       = $final_valid ? ( $decoded['records'] ?? array() ) : array();

		$lost       = array();
		$succ_same  = array();
		$unexpected = array();
		$attempts   = 0;
		$succeeded  = 0;
		foreach ( array( $log1, $log2 ) as $log ) {
			foreach ( $log as $entry ) {
				if ( 'unavail' === ( $entry['op'] ?? null ) ) {
					continue;
				}
				++$attempts;
				if ( empty( $entry['ok'] ) ) {
					if ( 'same' !== ( $entry['op'] ?? null ) || 'conflict' !== ( $entry['why'] ?? null ) ) {
						$unexpected[] = $entry;
					}
					continue;
				}
				++$succeeded;
				if ( 'distinct' === $entry['op'] ) {
					$key = 'plugin|' . $entry['slug'] . '|de_DE';
					if ( ! array_key_exists( $key, $records ) ) {
						$lost[] = $entry['slug'];
					}
				} else {
					$succ_same[] = $entry;
				}
			}
		}

		$bases_seen = array();
		$duplicate_bases = 0;
		foreach ( $succ_same as $entry ) {
			$base_key = wp_json_encode( $entry['base'] );
			if ( isset( $bases_seen[ $base_key ] ) ) {
				++$duplicate_bases;
			}
			$bases_seen[ $base_key ] = true;
		}

		return array(
			'lost_distinct'          => $lost,
			'same_silent_overwrite'  => $duplicate_bases,
			'reader'                 => $rd_stats,
			'final_valid_json'       => $final_valid,
			'unexpected_failures'    => $unexpected,
			'attempts'               => $attempts,
			'succeeded'              => $succeeded,
			'elapsed'                => $elapsed,
		);
	}

	/**
	 * The last non-empty line of a worker's own stdout -- each worker
	 * echoes exactly one JSON line, but PHP itself can still emit a
	 * stray notice/deprecation line ahead of it on some builds; taking
	 * the LAST line is robust to that without silencing anything.
	 */
	private function last_line( string $output ): string {
		$lines = array_values( array_filter( explode( "\n", $output ), static function ( $line ) {
			return '' !== trim( $line );
		} ) );
		return array_pop( $lines ) ?? '';
	}

	/**
	 * Drains every subprocess's stdout/stderr without blocking on any one
	 * of them, until all have exited -- or until PROCESS_TIMEOUT seconds
	 * after $started (the moment they were launched), at which point every
	 * one still running is killed and the test fails.
	 *
	 * @param array<string,resource> $procs
	 * @param array<string,array>    $pipes
	 * @return array<string,array{stdout: string, stderr: string}>
	 */
	private function collect( array $procs, array $pipes, float $started ): array {
		$out = array();
		foreach ( $pipes as $name => $p ) {
			stream_set_blocking( $p[1], false );
			stream_set_blocking( $p[2], false );
			$out[ $name ] = array( 'stdout' => '', 'stderr' => '' );
		}

		$running  = $procs;
		$timedout = false;
		while ( ! empty( $running ) ) {
			foreach ( $running as $name => $proc ) {
				$out[ $name ]['stdout'] .= (string) stream_get_contents( $pipes[ $name ][1] );
				$out[ $name ]['stderr'] .= (string) stream_get_contents( $pipes[ $name ][2] );
				if ( ! proc_get_status( $proc )['running'] ) {
					unset( $running[ $name ] );
				}
			}
			if ( ! empty( $running ) && ( microtime( true ) - $started ) > self::PROCESS_TIMEOUT ) {
				$timedout = true;
				foreach ( $running as $proc ) {
					proc_terminate( $proc, 9 );
				}
				break;
			}
			usleep( 10000 );
		}

		foreach ( $pipes as $name => $p ) {
			$out[ $name ]['stdout'] .= (string) stream_get_contents( $p[1] );
			$out[ $name ]['stderr'] .= (string) stream_get_contents( $p[2] );
			fclose( $p[1] );
			fclose( $p[2] );
			proc_close( $procs[ $name ] );
		}

		if ( $timedout ) {
			$this->fail( sprintf( 'the probe did not finish within %d seconds of starting; still-running subprocesses were killed', self::PROCESS_TIMEOUT ) );
		}
		return $out;
	}

	private function rmtree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort test cleanup.
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$this->rmtree( $path . '/' . $name );
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort test cleanup.
	}
}
