<?php
/**
 * The one-command SVN release script, driven end to end against a stub
 * `svn` on PATH. The real directory repository is never contacted.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Release_Script extends TestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	private function version(): string {
		$text = (string) file_get_contents( $this->root() . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/m', $text, $m ) );
		return $m[1];
	}

	private function stub_svn( string $dir, bool $existing_tag ): void {
		mkdir( $dir, 0777, true );
		$tag = $existing_tag ? ' "$dest/tags/' . $this->version() . '"' : '';
		$sh  = <<<SH
#!/usr/bin/env bash
echo "\$*" >> "$dir/svn.log"
case "\$1" in
	checkout)
		dest="\${@: -1}"
		mkdir -p "\$dest/trunk" "\$dest/assets" "\$dest/tags"$tag
		;;
	cp)
		cp -r "\$2" "\$3"
		;;
esac
exit 0
SH;
		file_put_contents( "$dir/svn", $sh . "\n" );
		chmod( "$dir/svn", 0755 );
	}

	/**
	 * @return array{0:int,1:string,2:array<int,string>,3:string,4:string}
	 */
	private function run_release( bool $existing_tag ): array {
		$base = BABILIM_TEST_TMP_ROOT . '/release-' . bin2hex( random_bytes( 4 ) );
		$stub = "$base/stub";
		$work = "$base/work";
		mkdir( $work, 0777, true );
		$this->stub_svn( $stub, $existing_tag );

		$out = array();
		exec( 'cd ' . escapeshellarg( $this->root() ) . ' && php scripts/build-zip.php 2>&1', $out, $zip_rc );
		$this->assertSame( 0, $zip_rc, implode( "\n", $out ) );

		$out = array();
		$cmd = 'cd ' . escapeshellarg( $this->root() )
			. ' && PATH=' . escapeshellarg( $stub ) . ':$PATH BABILIM_RELEASE_WORK=' . escapeshellarg( $work )
			. ' bash scripts/release.sh --dry-run --skip-checks 2>&1';
		exec( $cmd, $out, $rc );
		$log = is_file( "$stub/svn.log" ) ? file( "$stub/svn.log", FILE_IGNORE_NEW_LINES ) : array();
		return array( $rc, implode( "\n", $out ), (array) $log, $stub, $work );
	}

	public function test_dry_run_stages_trunk_assets_and_tag_in_order_and_never_commits(): void {
		$v = $this->version();
		list( $rc, $text, $log, , $work ) = $this->run_release( false );

		$this->assertSame( 0, $rc, $text );
		$this->assertStringStartsWith( 'checkout --depth immediates https://plugins.svn.wordpress.org/babilim', $log[0] );
		$this->assertSame( 1, count( preg_grep( '/^cp .*trunk .*tags\/' . preg_quote( $v, '/' ) . '$/', $log ) ) );
		$this->assertSame( array(), preg_grep( '/^commit/', $log ) );
		$this->assertFileExists( "$work/svn/trunk/babilim.php" );
		$this->assertFileDoesNotExist( "$work/svn/trunk/Makefile" );
		$this->assertFileExists( "$work/svn/assets/icon-128x128.png" );
		$this->assertFileExists( "$work/svn/assets/blueprints/blueprint.json" );
		$this->assertDirectoryDoesNotExist( "$work/svn/assets/src" );
		$this->assertFileExists( "$work/svn/tags/$v/readme.txt" );
		$this->assertSame( 1, count( preg_grep( '/^propset svn:mime-type image\/png .*banner-1544x500\.png/', $log ) ) );
		$this->assertStringContainsString( 'Delay auto-updates for 24 hours', $text );
	}

	public function test_git_push_advice_names_only_this_release_tag(): void {
		$v = $this->version();
		list( $rc, $text ) = $this->run_release( false );
		$this->assertSame( 0, $rc, $text );
		$this->assertStringContainsString( 'git push origin "v' . $v . '"', $text );
		$this->assertStringNotContainsString( 'push --tags', $text );
		$this->assertStringNotContainsString( 'push --tags', (string) file_get_contents( $this->root() . '/scripts/release.sh' ) );
		$this->assertStringNotContainsString( 'push --tags', (string) file_get_contents( $this->root() . '/docs/wporg-submission.md' ) );
	}

	public function test_an_existing_tag_stops_the_release_before_any_change(): void {
		$v = $this->version();
		list( $rc, $text, $log ) = $this->run_release( true );

		$this->assertNotSame( 0, $rc );
		$this->assertStringContainsString( "tag $v already exists", $text );
		$this->assertSame( array(), preg_grep( '/^(cp|add|rm|propset|commit)/', $log ) );
	}
}
