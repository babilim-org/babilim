<?php
/**
 * Stand-in for PHPUnit\Framework\TestCase -- just the handful of
 * assertion methods tests/unit/test-bootstrap.php actually calls, so
 * PHPStan can resolve them without a Composer classmap for PHPUnit
 * (which ships here only as a phar, see the Makefile's `tools`
 * target).
 *
 * Never loaded at runtime; PHPUnit's own phar provides the real class
 * then. Scanned only, not analysed -- see tests/phpstan.neon's
 * excludePaths.
 *
 * @package Babilim
 */

namespace PHPUnit\Framework;

abstract class TestCase {
	public function assertTrue( $condition, string $message = '' ): void {}
	public function assertFalse( $condition, string $message = '' ): void {}
	public function assertSame( $expected, $actual, string $message = '' ): void {}
	public function assertNotSame( $expected, $actual, string $message = '' ): void {}
	public function assertNotFalse( $condition, string $message = '' ): void {}
	public function assertNotEmpty( $actual, string $message = '' ): void {}
	public function assertEmpty( $actual, string $message = '' ): void {}
	public function assertInstanceOf( string $expected, $actual, string $message = '' ): void {}
	public function assertNull( $actual, string $message = '' ): void {}
	public function assertNotNull( $actual, string $message = '' ): void {}
	public function assertCount( int $expectedCount, $haystack, string $message = '' ): void {}
	public function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {}
	public function assertContains( $needle, iterable $haystack, string $message = '' ): void {}
	public function assertNotContains( $needle, iterable $haystack, string $message = '' ): void {}
	public function assertArrayHasKey( $key, $array, string $message = '' ): void {}
	public function assertArrayNotHasKey( $key, $array, string $message = '' ): void {}
	public function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {}
	public function assertIsString( $actual, string $message = '' ): void {}
	public function assertIsArray( $actual, string $message = '' ): void {}
	public function assertIsBool( $actual, string $message = '' ): void {}
	public function assertGreaterThan( $expected, $actual, string $message = '' ): void {}
	public function assertEquals( $expected, $actual, string $message = '' ): void {}
	public function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {}
	public function assertMatchesRegularExpression( string $pattern, string $string, string $message = '' ): void {}
	public function assertFileDoesNotExist( string $filename, string $message = '' ): void {}
	public function assertDirectoryDoesNotExist( string $directory, string $message = '' ): void {}
	public function assertFileExists( string $filename, string $message = '' ): void {}
	public function assertLessThanOrEqual( $expected, $actual, string $message = '' ): void {}
	public function assertLessThan( $expected, $actual, string $message = '' ): void {}
	public function assertDoesNotMatchRegularExpression( string $pattern, string $string, string $message = '' ): void {}
	public function assertDirectoryExists( string $directory, string $message = '' ): void {}
	public function assertGreaterThanOrEqual( $expected, $actual, string $message = '' ): void {}
	public function expectException( string $exception ): void {}
	public function addToAssertionCount( int $count ): void {}
	public function fail( string $message = '' ): void {}
	public function markTestSkipped( string $message = '' ): void {}
	protected function setUp(): void {}
	protected function tearDown(): void {}
}
