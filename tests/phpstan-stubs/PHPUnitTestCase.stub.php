<?php
/**
 * Stand-in for PHPUnit\Framework\TestCase -- just the handful of
 * assertion methods tests/unit/test-bootstrap.php actually calls, so
 * PHPStan can resolve them without a Composer classmap for PHPUnit
 * (which ships here only as a phar, see the Makefile's `tools`
 * target).
 *
 * Assertions are static, as in PHPUnit itself, so both $this-> and
 * self:: calls resolve.
 *
 * Never loaded at runtime; PHPUnit's own phar provides the real class
 * then. Scanned only, not analysed -- see tests/phpstan.neon's
 * excludePaths.
 *
 * @package Babilim
 */

namespace PHPUnit\Framework;

abstract class TestCase {
	public static function assertTrue( $condition, string $message = '' ): void {}
	public static function assertFalse( $condition, string $message = '' ): void {}
	public static function assertSame( $expected, $actual, string $message = '' ): void {}
	public static function assertNotSame( $expected, $actual, string $message = '' ): void {}
	public static function assertNotFalse( $condition, string $message = '' ): void {}
	public static function assertNotEmpty( $actual, string $message = '' ): void {}
	public static function assertEmpty( $actual, string $message = '' ): void {}
	public static function assertInstanceOf( string $expected, $actual, string $message = '' ): void {}
	public static function assertNull( $actual, string $message = '' ): void {}
	public static function assertNotNull( $actual, string $message = '' ): void {}
	public static function assertCount( int $expectedCount, $haystack, string $message = '' ): void {}
	public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {}
	public static function assertContains( $needle, iterable $haystack, string $message = '' ): void {}
	public static function assertNotContains( $needle, iterable $haystack, string $message = '' ): void {}
	public static function assertArrayHasKey( $key, $array, string $message = '' ): void {}
	public static function assertArrayNotHasKey( $key, $array, string $message = '' ): void {}
	public static function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {}
	public static function assertIsString( $actual, string $message = '' ): void {}
	public static function assertIsArray( $actual, string $message = '' ): void {}
	public static function assertIsBool( $actual, string $message = '' ): void {}
	public static function assertGreaterThan( $expected, $actual, string $message = '' ): void {}
	public static function assertEquals( $expected, $actual, string $message = '' ): void {}
	public static function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {}
	public static function assertMatchesRegularExpression( string $pattern, string $string, string $message = '' ): void {}
	public static function assertFileDoesNotExist( string $filename, string $message = '' ): void {}
	public static function assertDirectoryDoesNotExist( string $directory, string $message = '' ): void {}
	public static function assertFileExists( string $filename, string $message = '' ): void {}
	public static function assertLessThanOrEqual( $expected, $actual, string $message = '' ): void {}
	public static function assertLessThan( $expected, $actual, string $message = '' ): void {}
	public static function assertDoesNotMatchRegularExpression( string $pattern, string $string, string $message = '' ): void {}
	public static function assertDirectoryExists( string $directory, string $message = '' ): void {}
	public static function assertGreaterThanOrEqual( $expected, $actual, string $message = '' ): void {}
	public function expectException( string $exception ): void {}
	public function addToAssertionCount( int $count ): void {}
	public static function fail( string $message = '' ): void {}
	public static function markTestSkipped( string $message = '' ): void {}
	protected function setUp(): void {}
	protected function tearDown(): void {}
}
