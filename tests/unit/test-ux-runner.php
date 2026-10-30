<?php
/**
 * The browser-flow runner must never report a green run it did not do:
 * without Playwright it writes an infra failure and exits non-zero.
 *
 * @package Babilim
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class Test_Ux_Runner extends TestCase {

	public function test_the_runner_fails_loudly_when_playwright_cannot_be_found(): void {
		$root = dirname( __DIR__, 2 );
		$out  = BABILIM_TEST_TMP_ROOT . '/ux-runner-' . bin2hex( random_bytes( 4 ) );
		$empty = $out . '-empty';
		mkdir( $empty, 0777, true );

		$command = sprintf(
			'cd %s && NODE_PATH=%s bash %s 6.6 3.1.54 %s callback 2>&1',
			escapeshellarg( $empty ),
			escapeshellarg( $empty ),
			escapeshellarg( $root . '/tests/playground/run-ux-flow.sh' ),
			escapeshellarg( $out )
		);
		$lines = array();
		$rc    = 0;
		exec( $command, $lines, $rc );

		$this->assertNotSame( 0, $rc, 'a run that could not drive a browser must not exit 0: ' . implode( "\n", $lines ) );
		$results = glob( $out . '/ux-results-*.txt' ) ?: array();
		$this->assertNotEmpty( $results, 'a results file is written' );
		$this->assertStringContainsString( 'FAIL infra playwright missing', (string) file_get_contents( $results[0] ) );
	}

	public function test_the_runner_exit_code_follows_the_results_file(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/tests/playground/run-ux-flow.sh' );
		$this->assertMatchesRegularExpression( '/grep -q \'\^FAIL\'/', $script, 'any FAIL line makes the run fail' );
		$this->assertMatchesRegularExpression( '/! -s "\$\{RESULTS\}"/', $script, 'an empty results file makes the run fail' );
		$this->assertMatchesRegularExpression( '/-s "\$\{RESULTS\}" \] \|\| grep -q \'\^FAIL\' "\$\{RESULTS\}"; then\s+status=1\s+fi\s+exit "\$\{status\}"\s*$/', $script, 'status=1 on a FAIL or empty file, and it is the exit code' );
	}
}
