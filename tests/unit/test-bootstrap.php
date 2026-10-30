<?php
/**
 * Tests for the plugin's bootstrap: the constants and autoloader
 * babilim.php defines, and the top-level Babilim_Loader::register()
 * call.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Bootstrap extends TestCase {

	/**
	 * The exact source of the probe's error handler -- shared, word for
	 * word, by both probes below, so a test exercising one is
	 * necessarily testing the same code the other relies on. An earlier
	 * version had each probe carry its own hand-copied version of this logic;
	 * that let a regression in the one actually used to verify the
	 * BABILIM_API_BASE guard go undetected by the test meant to prove
	 * the handler is selective, since that test was really only
	 * checking its own separate copy.
	 */
	private const PROBE_ERROR_HANDLER_PHP = <<<'PHP'
function babilim_probe_error_handler( $errno, $errstr ) {
	global $babilim_probe_guard_missing;
	if ( false !== stripos( $errstr, 'already defined' ) ) {
		$babilim_probe_guard_missing = true;
		return true;
	}
	return false;
}
PHP;

	protected function setUp(): void {
		babilim_test_reset();
	}

	public function test_babilim_version_constant_is_defined(): void {
		$this->assertTrue( defined( 'BABILIM_VERSION' ) );
		$this->assertNotSame( '', BABILIM_VERSION );
	}

	public function test_babilim_file_and_dir_constants_point_at_the_plugin(): void {
		$this->assertTrue( defined( 'BABILIM_FILE' ) );
		$this->assertSame( dirname( __DIR__, 2 ) . '/babilim.php', BABILIM_FILE );

		$this->assertTrue( defined( 'BABILIM_DIR' ) );
		$this->assertSame( dirname( __DIR__, 2 ) . '/', BABILIM_DIR );
	}

	public function test_babilim_api_base_constant_has_the_product_domain_default(): void {
		$this->assertTrue( defined( 'BABILIM_API_BASE' ) );
		$this->assertSame( 'https://api.babilim.org', BABILIM_API_BASE );
	}

	/**
	 * Proves the defined() guard, not just the default value: a host
	 * that pre-defines BABILIM_API_BASE before this plugin loads must
	 * keep its own value. This can't be tested in-process (babilim.php
	 * has already loaded, with the default, by the time any test runs)
	 * so it runs babilim.php in a genuinely fresh PHP process where the
	 * constant is pre-defined first, and reads back what the plugin
	 * left it as.
	 *
	 * A guard-less `define()` on an already-defined constant doesn't
	 * change its value -- define() just fails silently and raises an
	 * E_WARNING -- so reading back BABILIM_API_BASE alone
	 * can't tell a real guard from one that was deleted. The probe
	 * installs the shared PROBE_ERROR_HANDLER_PHP (see that constant)
	 * to catch that specific "already defined" warning directly,
	 * instead of relying on it having gone to output or a log (which
	 * depends on this process's display_errors/log_errors ini, not on
	 * anything the guard itself does) -- proven below by running the
	 * probe with both explicitly off.
	 */
	public function test_babilim_api_base_constant_keeps_a_pre_defined_value(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/babilim.php';
		$probe       = '<?php'
			. "\ndefine( 'ABSPATH', " . var_export( ABSPATH, true ) . ' );'
			. "\ndefine( 'BABILIM_API_BASE', 'https://override.example.test' );"
			. "\nfunction add_action( \$tag, \$callback, \$priority = 10, \$accepted_args = 1 ) { return true; }"
			. "\nfunction add_filter( \$tag, \$callback, \$priority = 10, \$accepted_args = 1 ) { return true; }"
			. "\n\$babilim_probe_guard_missing = false;"
			. "\n" . self::PROBE_ERROR_HANDLER_PHP
			. "\nset_error_handler( 'babilim_probe_error_handler' );"
			. "\nrequire " . var_export( $plugin_file, true ) . ';'
			. "\nrestore_error_handler();"
			. "\necho BABILIM_API_BASE . '|' . ( \$babilim_probe_guard_missing ? 'GUARD_MISSING' : 'GUARD_OK' );";

		$probe_file = tempnam( BABILIM_TEST_TMP_ROOT, 'babilim-api-base-probe-' );
		$this->assertNotFalse( $probe_file );
		rename( $probe_file, $probe_file . '.php' );
		$probe_file .= '.php';
		file_put_contents( $probe_file, $probe );

		// display_errors/log_errors are forced off so a pass here can only
		// come from the error handler actually catching the warning, not
		// from it happening to land in this process's own output/log.
		$output = shell_exec(
			escapeshellarg( PHP_BINARY )
			. ' -d display_errors=0 -d log_errors=0'
			. ' ' . escapeshellarg( $probe_file )
			. ' 2>&1'
		);
		unlink( $probe_file );

		$parts = explode( '|', trim( (string) $output ), 2 );
		$this->assertSame( 'https://override.example.test', $parts[0] );
		$this->assertSame(
			'GUARD_OK',
			$parts[1] ?? null,
			'babilim.php must guard BABILIM_API_BASE with defined() -- a "constant already defined" warning fired, meaning it tried to redefine it'
		);
	}

	/**
	 * Proves the exact PROBE_ERROR_HANDLER_PHP callable (see that
	 * constant) is selective: it must only ever treat an "already
	 * defined" warning as the guard being missing, and must let every
	 * other warning through to PHP's normal handling (return false)
	 * rather than swallowing it. Run with display_errors=1 so a
	 * passed-through warning is actually visible in the output -- the
	 * opposite of the test above, which forces both off. Because this
	 * uses the exact same constant as that test, a mutation to the
	 * handler (e.g. reverting it to unconditionally `return true`) is
	 * caught here even though it wouldn't change that other test's own
	 * assertions.
	 */
	public function test_api_base_probe_handler_only_captures_already_defined_warnings(): void {
		$probe = '<?php'
			. "\n\$babilim_probe_guard_missing = false;"
			. "\n" . self::PROBE_ERROR_HANDLER_PHP
			. "\nset_error_handler( 'babilim_probe_error_handler' );"
			. "\n\$unrelated = array();"
			. "\necho \$unrelated['missing-key'];" // triggers an unrelated E_WARNING, not "already defined".
			. "\nrestore_error_handler();"
			. "\necho '|GUARD:' . ( \$babilim_probe_guard_missing ? 'MISSING' : 'OK' );";

		$probe_file = tempnam( BABILIM_TEST_TMP_ROOT, 'babilim-handler-probe-' );
		$this->assertNotFalse( $probe_file );
		rename( $probe_file, $probe_file . '.php' );
		$probe_file .= '.php';
		file_put_contents( $probe_file, $probe );

		$output = shell_exec(
			escapeshellarg( PHP_BINARY )
			. ' -d display_errors=1 -d log_errors=0'
			. ' ' . escapeshellarg( $probe_file )
			. ' 2>&1'
		);
		unlink( $probe_file );

		$this->assertStringContainsString(
			'Warning',
			(string) $output,
			'an unrelated warning must be let through (handler returns false), not swallowed'
		);
		$this->assertStringContainsString(
			'GUARD:OK',
			(string) $output,
			'an unrelated warning must never be mistaken for the "already defined" condition'
		);
	}

	public function test_autoloader_finds_babilim_paths_by_convention(): void {
		$this->assertTrue(
			class_exists( 'Babilim_Paths' ),
			'spl_autoload_register should map Babilim_Paths to includes/class-babilim-paths.php'
		);
	}

	public function test_autoloader_returns_gracefully_for_an_unknown_babilim_class(): void {
		$this->assertFalse(
			class_exists( 'Babilim_Nonexistent_Class', true ),
			'a Babilim_* class with no matching file must fail autoload cleanly, not fatal'
		);
	}

	public function test_autoload_candidates_are_empty_outside_the_babilim_prefix(): void {
		// Unlike the class_exists() check above, this is true regardless
		// of what files exist on disk -- it directly exercises the guard
		// babilim_autoload_candidates() applies before ever building a
		// path, not a side effect of the candidate file happening not to
		// exist.
		$this->assertSame( array(), babilim_autoload_candidates( 'Not_A_Babilim_Class' ) );
	}

	public function test_autoload_candidates_map_class_name_to_expected_paths(): void {
		$this->assertSame(
			array(
				BABILIM_DIR . 'includes/class-babilim-foo-bar.php',
				BABILIM_DIR . 'admin/class-babilim-foo-bar.php',
			),
			babilim_autoload_candidates( 'Babilim_Foo_Bar' )
		);
	}

	/**
	 * Covers both the top-level `Babilim_Loader::register();` call in
	 * babilim.php (deleting it leaves $registered false here, since
	 * this test never calls register() before the first assertion) and
	 * register()'s idempotence (a mutation that removed the guard and
	 * let the body re-run would bump $run_count on every call below,
	 * not just the first).
	 */
	public function test_loader_register_is_idempotent(): void {
		$reflection = new ReflectionClass( Babilim_Loader::class );

		$registered = $reflection->getProperty( 'registered' );
		$registered->setAccessible( true );
		$run_count = $reflection->getProperty( 'run_count' );
		$run_count->setAccessible( true );

		// babilim.php's top-level call (see tests/bootstrap.php) already
		// ran this once, before any test executes.
		$this->assertTrue( $registered->getValue(), 'babilim.php must call Babilim_Loader::register() at the top level' );
		$before = $run_count->getValue();

		Babilim_Loader::register();
		Babilim_Loader::register();

		$this->assertSame(
			$before,
			$run_count->getValue(),
			'register() must not re-run its body once already registered'
		);
	}

	public function test_loader_register_declares_a_void_return_type(): void {
		$reflection = new ReflectionMethod( Babilim_Loader::class, 'register' );
		$return_type = $reflection->getReturnType();

		$this->assertNotNull( $return_type, 'Babilim_Loader::register() must declare a return type' );
		$this->assertSame( 'void', (string) $return_type );
	}

	public function test_reset_does_not_erase_hooks_registered_at_plugin_load(): void {
		$this->assertNotEmpty(
			$GLOBALS['babilim_test_actions']['plugins_loaded'] ?? null,
			'babilim_test_reset() must not erase the add_action(plugins_loaded) call babilim.php makes at load time'
		);
	}

	public function test_bootstrap_fired_after_setup_theme_and_change_locale_once(): void {
		$this->assertSame(
			1,
			$GLOBALS['babilim_test_action_dispatch_counts']['after_setup_theme'] ?? 0,
			"tests/bootstrap.php must fire do_action('after_setup_theme') once, and that must survive reset"
		);
		$this->assertSame(
			1,
			$GLOBALS['babilim_test_action_dispatch_counts']['change_locale'] ?? 0,
			"tests/bootstrap.php must fire do_action('change_locale') once, and that must survive reset"
		);
	}
}
