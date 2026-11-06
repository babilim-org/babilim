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
		$this->assertMatchesRegularExpression( self::EXIT_BLOCK, $script, 'status=1 on a FAIL or empty file, then (with only comment lines between) status=1 when no line is a PASS, and the status is the exit code' );
	}

	/**
	 * The runner's closing block, as one piece: the FAIL/empty rule, nothing
	 * but `#` comment lines, the all-SKIP rule, then the exit. Anything else
	 * between the two rules (say `status=0`) could turn a failed run green.
	 */
	private const EXIT_BLOCK = '/if \[ ! -s "\$\{RESULTS\}" \] \|\| grep -q \'\^FAIL\' "\$\{RESULTS\}"; then\n	status=1\nfi\n(?:#[^\n]*\n)*if \[ -s "\$\{RESULTS\}" \] && ! grep -q \'\^PASS\' "\$\{RESULTS\}"; then\n	echo "[^"\n]+" >&2\n	status=1\nfi\nexit "\$\{status\}"\s*$/';

	/**
	 * EXIT_BLOCK rejects the closing block with a line slipped in between
	 * the two rules, or with either rule's status=1 dropped.
	 */
	public function test_the_exit_block_check_rejects_a_line_between_the_rules(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/tests/playground/run-ux-flow.sh' );
		$this->assertMatchesRegularExpression( self::EXIT_BLOCK, $script );
		$skip_rule = "if [ -s \"\${RESULTS}\" ] && ! grep -q '^PASS'";
		$this->assertSame( 1, substr_count( $script, $skip_rule ) );
		$inserted = str_replace( $skip_rule, "status=0\n" . $skip_rule, $script );
		$this->assertDoesNotMatchRegularExpression( self::EXIT_BLOCK, $inserted, 'status=0 between the two rules' );
		$first = "grep -q '^FAIL' \"\${RESULTS}\"; then\n	status=1\n";
		$this->assertSame( 1, substr_count( $script, $first ) );
		$this->assertDoesNotMatchRegularExpression( self::EXIT_BLOCK, str_replace( $first, "grep -q '^FAIL' \"\${RESULTS}\"; then\n	:\n", $script ), 'the FAIL rule sets nothing' );
		$this->assertDoesNotMatchRegularExpression( self::EXIT_BLOCK, str_replace( "	status=1\nfi\nexit", "	:\nfi\nexit", $script ), 'the all-SKIP rule sets nothing' );
	}
}
