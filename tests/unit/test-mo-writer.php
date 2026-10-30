<?php
/**
 * Tests for Babilim_Mo_Writer -- the minimal GNU MO writer used to build
 * the merged `default` domain index.
 *
 * Round trips are read back with an independent, minimal MO reader
 * (babilim_test_read_mo(), a shared fixture at
 * tests/fixtures/mo-codec.php loaded by tests/bootstrap.php) rather than
 * WordPress's own MO class -- this repo's unit tests never require a
 * WordPress install, and no real
 * `pomo`/`MO` implementation is available in this sandbox (only
 * wordpress-stubs.php's function/class *signatures*, not real bodies).
 * The reader is written independently of the writer (unpack()-driven, no
 * shared helper) so a bug in one isn't masked by the same bug in the
 * other.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Mo_Writer extends TestCase {

	protected function setUp(): void {
		babilim_test_reset();
	}

	private function default_headers(): array {
		return array(
			'Project-Id-Version'        => 'Babilim',
			'MIME-Version'               => '1.0',
			'Content-Type'               => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'Language'                   => 'zh_TW',
		);
	}

	public function test_write_produces_the_gnu_mo_magic_number(): void {
		$bytes = Babilim_Mo_Writer::write( array( 'Save' => 'Save (translated)' ), $this->default_headers() );

		$magic = unpack( 'V', substr( $bytes, 0, 4 ) )[1];
		$this->assertSame( 0x950412de, $magic );
	}

	public function test_round_trips_a_single_plain_entry(): void {
		$bytes = Babilim_Mo_Writer::write( array( 'Save' => 'Save (translated)' ), $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		$this->assertSame( 'Save (translated)', $parsed['Save'] );
	}

	public function test_round_trips_a_msgctxt_entry(): void {
		$key   = "Widgets\x04Save";
		$bytes = Babilim_Mo_Writer::write( array( $key => 'Save (widget context)' ), $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		$this->assertSame( 'Save (widget context)', $parsed[ $key ] );
	}

	public function test_round_trips_a_plural_entry(): void {
		$key   = "1 item\x004 items";
		$bytes = Babilim_Mo_Writer::write( array( $key => array( '1 item (translated)', '%d items (translated)' ) ), $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		$this->assertSame( array( '1 item (translated)', '%d items (translated)' ), $parsed[ $key ] );
	}

	public function test_round_trips_multiple_entries_together(): void {
		$entries = array(
			'Save'                => 'Save (translated)',
			"Widgets\x04Save"     => 'Save (widget context)',
			"1 item\x004 items"   => array( '1 item (translated)', '%d items (translated)' ),
		);
		$bytes = Babilim_Mo_Writer::write( $entries, $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		foreach ( $entries as $key => $expected ) {
			$this->assertSame( $expected, $parsed[ $key ] );
		}
	}

	public function test_header_entry_carries_the_given_headers(): void {
		$bytes = Babilim_Mo_Writer::write( array( 'Save' => 'x' ), $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		$this->assertArrayHasKey( '', $parsed );
		$this->assertStringContainsString( 'Content-Type: text/plain; charset=UTF-8', $parsed[''] );
		$this->assertStringContainsString( 'Language: zh_TW', $parsed[''] );
	}

	public function test_entry_count_excludes_the_header_entry(): void {
		$entries = array(
			'Save'   => 'x',
			'Cancel' => 'y',
		);
		$bytes = Babilim_Mo_Writer::write( $entries, $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		// Two real entries plus the implicit "" header == 3 total.
		$this->assertCount( 3, $parsed );
	}

	public function test_write_with_no_entries_still_produces_a_readable_header_only_mo(): void {
		$bytes = Babilim_Mo_Writer::write( array(), $this->default_headers() );

		$parsed = babilim_test_read_mo( $bytes );

		$this->assertCount( 1, $parsed );
		$this->assertArrayHasKey( '', $parsed );
	}
}

// babilim_test_read_mo() (an independent, minimal GNU MO reader for
// tests -- see this file's top-of-file docblock for why it exists
// instead of relying on WordPress's own MO class) is a shared fixture
// now: tests/fixtures/mo-codec.php, loaded by tests/bootstrap.php -- see
// that fixture's own docblock for why it no longer lives only here.
