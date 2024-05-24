<?php

use WP_CLI\Utils;
use WP_CLI\Tests\TestCase;

class TestWPVersionResolverTest extends TestCase {

	private $temp_file;

	protected function set_up() {
		parent::set_up();

		$this->temp_file = Utils\get_temp_dir() . 'wp-cli-tests-wp-versions.json';

		$fp = fopen( $this->temp_file, 'w' );
		fwrite( $fp, json_encode( $this->wp_versions_data() ) );
		fclose( $fp );
	}

	protected function tear_down() {
		if ( $this->temp_file && file_exists( $this->temp_file ) ) {
			unlink( $this->temp_file );
		}

		parent::tear_down();
	}

	private function wp_versions_data() {
		return array(
			'5.4'   => 'insecure',
			'5.9'   => 'insecure',
			'5.9.1' => 'insecure',
			'5.9.2' => 'insecure',
			'6.0'   => 'insecure',
			'6.0.1' => 'insecure',
			'6.0.2' => 'insecure',
			'6.1'   => 'insecure',
			'6.1.1' => 'insecure',
			'6.1.2' => 'insecure',
			'6.2'   => 'insecure',
			'6.2.1' => 'insecure',
			'6.2.2' => 'insecure',
			'6.5'   => 'insecure',
			'6.5.2' => 'latest',
		);
	}

	private function data_wp_version_resolver() {
		return array(
			array( '5.0', '5.0' ), // Does not match any version. So return as it is.
			array( '5', '5.9.2' ), // Return the latest major version.
			array( '5.9', '5.9.2' ), // Return the latest patch version.
			array( '5.9.1', '5.9.1' ), // Return the exact version.
			array( '6', '6.5.2' ), // Return the latest minor version.
			array( '6.0', '6.0.2' ), // Return the latest patch version.
			array( '6.0.0', '6.0' ), // Return the requested version.
			array( '', '6.5.2' ), // Return the latest version.
			array( 'latest', '6.5.2' ), // Return the latest version.
			array( 'some-mismatched-version', 'some-mismatched-version' ), // Does not match any version. So return as it is.
			array( '6.5-alpha', '6.5.2' ), // Return the latest version.
			array( '6.5-beta', '6.5.2' ), // Return the latest version.
			array( '6.5-rc', '6.5.2' ), // Return the latest version.
			array( '6.5-nightly', '6.5-nightly' ), // Does not match any version. So return as it is.
			array( '6.5.0.0', '6.5' ), // Return the latest version.
			array( '6.5.2.0', '6.5.2' ), // Return the latest version.
		);
	}

	/**
	 * @dataProvider data_wp_version_resolver
	 */
	public function test_wp_version_resolver( $env, $expected ) {
		if ( $env ) {
			putenv( "WP_VERSION=$env" );
		}

		$output = exec( 'php ' . dirname( dirname( __DIR__ ) ) . '/utils/wp-version-resolver.php' );

		// Reset the environment variable.
		putenv( 'WP_VERSION' );

		$this->assertSame( $expected, $output );
	}
}
