<?php
/**
 * Test-suite hygiene: every scratch path a test creates must live under
 * this process's own root (BABILIM_TEST_TMP_ROOT, removed when the
 * process exits), never directly in the system temp directory.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Hygiene extends TestCase {

	public function test_no_test_file_creates_scratch_paths_outside_the_process_root(): void {
		$offenders = array();
		foreach ( glob( __DIR__ . '/*.php' ) ?: array() as $file ) {
			if ( __FILE__ === $file ) {
				continue;
			}
			foreach ( file( $file ) ?: array() as $number => $line ) {
				if ( preg_match( '/\bsys_get_temp_dir\s*\(/', $line ) ) {
					$offenders[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $offenders, 'use BABILIM_TEST_TMP_ROOT for scratch paths, not the system temp directory' );
	}

	public function test_the_process_root_is_created_under_the_system_temp_directory(): void {
		$this->assertDirectoryExists( BABILIM_TEST_TMP_ROOT );
		$this->assertSame( sys_get_temp_dir(), dirname( BABILIM_TEST_TMP_ROOT ) );
	}

	/**
	 * A binary file (a compiled .mo) holding a banned token must be reported
	 * as a finding, not abort the whole history scan as a grep error.
	 */
	public function test_the_history_scan_runs_to_completion_over_a_binary_blob(): void {
		$root = BABILIM_TEST_TMP_ROOT . '/history-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/scripts', 0777, true );
		$repo = dirname( __DIR__, 2 );
		copy( $repo . '/scripts/public-rules.sh', $root . '/scripts/public-rules.sh' );
		copy( $repo . '/scripts/public-history-check.sh', $root . '/scripts/public-history-check.sh' );
		// A task-code-shaped token, assembled here so this file does not carry one.
		$token = 'H' . '7';
		file_put_contents( $root . '/messages.mo', "\x00\x01\xff\xfe msgid \"{$token}\" \x00\x80\n" );
		file_put_contents( $root . '/readme.txt', "plain\n" );

		$git = 'git -C ' . escapeshellarg( $root ) . ' -c user.name=t -c user.email=t@example.org ';
		$out = array();
		exec( 'git init -q ' . escapeshellarg( $root ) . ' 2>&1 && ' . $git . 'add -A 2>&1 && ' . $git . 'commit -q -m "Add files" 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );

		$out = array();
		exec( 'bash ' . escapeshellarg( $root . '/scripts/public-history-check.sh' ) . ' 2>&1', $out, $rc );
		$text = implode( "\n", $out );
		babilim_test_rmtree( $root );

		$this->assertSame( 1, $rc, $text );
		$this->assertStringContainsString( 'blob/path pair(s) checked', $text );
		$this->assertStringNotContainsString( 'rule engine could not run', $text );
		$this->assertStringContainsString( 'messages.mo', $text );
	}

	/**
	 * The CJK and hostname rules read binary files too: a compiled .mo
	 * carrying either one is reported, not skipped.
	 */
	public function test_the_history_scan_reports_cjk_and_hostnames_inside_binary_blobs(): void {
		$root = BABILIM_TEST_TMP_ROOT . '/history-bin-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/scripts', 0777, true );
		$repo = dirname( __DIR__, 2 );
		copy( $repo . '/scripts/public-rules.sh', $root . '/scripts/public-rules.sh' );
		copy( $repo . '/scripts/public-history-check.sh', $root . '/scripts/public-history-check.sh' );
		// Assembled at run time so this file itself carries neither.
		file_put_contents( $root . '/cjk.mo', "\x00\x01\xff\xfe " . "\xe4\xb8\x80\xe4\xba\x8c" . " \x00\x80\n" );
		file_put_contents( $root . '/host.mo', "\x00\x01\xff\xfe see https://" . 'internal' . '-box.' . 'corp' . '.net/x' . " \x00\x80\n" );
		file_put_contents( $root . '/readme.txt', "plain\n" );

		$git = 'git -C ' . escapeshellarg( $root ) . ' -c user.name=t -c user.email=t@example.org ';
		$out = array();
		exec( 'git init -q ' . escapeshellarg( $root ) . ' 2>&1 && ' . $git . 'add -A 2>&1 && ' . $git . 'commit -q -m "Add files" 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );

		$out = array();
		exec( 'bash ' . escapeshellarg( $root . '/scripts/public-history-check.sh' ) . ' 2>&1', $out, $rc );
		$text = implode( "\n", $out );
		babilim_test_rmtree( $root );

		$this->assertSame( 1, $rc, $text );
		$this->assertStringContainsString( 'Chinese/Japanese/Korean', $text );
		$this->assertStringContainsString( 'cjk.mo', $text );
		$this->assertStringContainsString( 'hostname-shaped token', $text );
		$this->assertStringContainsString( 'host.mo', $text );
	}

	/**
	 * Rendered directory-listing PNGs are binary and skipped, but only in
	 * the directory-assets folder; their SVG sources stay scanned and may
	 * carry the SVG namespace URI.
	 */
	public function test_public_rules_skip_png_files_only_under_the_directory_assets_folder(): void {
		$root = BABILIM_TEST_TMP_ROOT . '/assets-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/.wordpress-org', 0777, true );
		mkdir( $root . '/admin/img', 0777, true );
		$repo = dirname( __DIR__, 2 );

		// Assembled at run time so this file itself carries no CJK.
		$png = "\x89PNG\r\n\x1a\n" . "\xe4\xb8\x80\xe4\xba\x8c";
		file_put_contents( $root . '/.wordpress-org/icon-128x128.png', $png );
		file_put_contents( $root . '/.wordpress-org/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>' );
		file_put_contents( $root . '/admin/img/stray.png', $png );
		file_put_contents( $root . '/readme.txt', "plain\n" );
		$list = $root . '/files.txt';
		file_put_contents( $list, ".wordpress-org/icon-128x128.png\n.wordpress-org/icon.svg\nadmin/img/stray.png\nreadme.txt\n" );

		$out = array();
		exec( 'bash ' . escapeshellarg( $repo . '/scripts/public-rules.sh' ) . ' scan-files ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $list ) . ' 2>&1', $out, $rc );
		$text = implode( "\n", $out );
		babilim_test_rmtree( $root );

		$this->assertSame( 1, $rc, $text );
		$this->assertStringContainsString( 'admin/img/stray.png', $text );
		$this->assertStringNotContainsString( 'icon-128x128.png', $text );
		$this->assertStringNotContainsString( 'www.w3.org', $text );
	}
}
