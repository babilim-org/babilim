<?php
/**
 * WordPress.org directory assets: icon and banner dimensions, and the
 * standalone SVG icon.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Test_Wporg_Assets extends TestCase {

	public function test_directory_icons_and_banners_have_the_exact_sizes_wordpress_org_expects(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			'icon-128x128.png'   => array( 128, 128 ),
			'icon-256x256.png'   => array( 256, 256 ),
			'banner-772x250.png' => array( 772, 250 ),
			'banner-1544x500.png' => array( 1544, 500 ),
		);
		foreach ( $files as $file => $size ) {
			$info = @getimagesize( $root . '/.wordpress-org/' . $file );
			$this->assertIsArray( $info, $file . ' is a readable image' );
			$this->assertSame( $size, array( $info[0], $info[1] ), $file );
			$this->assertSame( 'image/png', $info['mime'], $file );
		}
	}

	public function test_the_svg_icon_is_a_standalone_square_svg(): void {
		$root = dirname( __DIR__, 2 );
		$svg  = (string) @file_get_contents( $root . '/.wordpress-org/icon.svg' );
		$this->assertNotSame( '', $svg, 'icon.svg exists' );
		$this->assertStringContainsString( 'xmlns="http://www.w3.org/2000/svg"', $svg );
		$this->assertSame( 1, preg_match( '/viewBox="0 0 (\d+) (\d+)"/', $svg, $m ) );
		$this->assertSame( $m[1], $m[2] );
		$this->assertStringNotContainsString( '<text', $svg, 'the icon carries no text' );
		$this->assertSame( (string) @file_get_contents( $root . '/.wordpress-org/src/icon.svg' ), $svg );
	}

	public function test_the_blueprint_installs_the_directory_plugin_and_lands_on_its_screen_without_overriding_the_service(): void {
		$root = dirname( __DIR__, 2 );
		$b    = json_decode( (string) @file_get_contents( $root . '/.wordpress-org/blueprints/blueprint.json' ), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( '/wp-admin/admin.php?page=babilim', $b['landingPage'] );
		$this->assertTrue( $b['login'] );
		$this->assertSame( array( 'php' => '8.2', 'wp' => 'latest' ), $b['preferredVersions'] );
		$this->assertSame( 'wordpress.org/plugins', $b['steps'][0]['pluginData']['resource'] );
		$this->assertSame( 'babilim', $b['steps'][0]['pluginData']['slug'] );
		$this->assertTrue( $b['steps'][0]['options']['activate'] );
		$this->assertArrayNotHasKey( 'constants', $b, 'the preview talks to the real service or to nothing' );
		$this->assertArrayNotHasKey( '$schema', $b, 'the schema host is not on the public allow-list' );
		$this->assertCount( 1, $b['steps'] );
	}
}
