<?php

namespace WP_CLI\Tests\Tests;

use RuntimeException;
use WP_CLI\Tests\Context\FeatureContext;
use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;
use ZipArchive;

class TestCoreZip extends TestCase {

	/**
	 * @var string
	 */
	public $temp_dir;

	protected function set_up(): void {
		parent::set_up();

		$this->temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-test-core-zip-', true );
		mkdir( $this->temp_dir );
	}

	protected function tear_down(): void {
		if ( $this->temp_dir && file_exists( $this->temp_dir ) ) {
			FeatureContext::remove_dir( $this->temp_dir );
		}

		parent::tear_down();
	}

	/**
	 * Build a ZIP file containing the given entries.
	 *
	 * @param string                $name    File name for the archive.
	 * @param array<string, string> $entries Map of entry path to file contents.
	 * @return string Path to the created archive.
	 */
	private function create_zip( $name, array $entries ): string {
		$zip_file = $this->temp_dir . DIRECTORY_SEPARATOR . $name;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_file, ZipArchive::CREATE ) === true );

		foreach ( $entries as $path => $contents ) {
			$zip->addFromString( $path, $contents );
		}

		$zip->close();

		return $zip_file;
	}

	/**
	 * Entries making up a minimal WordPress installation, below the given prefix.
	 *
	 * @param string $prefix
	 * @return array<string, string>
	 */
	private function wp_entries( $prefix = '' ): array {
		return [
			"{$prefix}wp-includes/version.php" => "<?php\n\$wp_version = '7.2-alpha-12345';\n",
			"{$prefix}wp-load.php"             => "<?php\n// wp-load\n",
			"{$prefix}wp-admin/index.php"      => "<?php\n// wp-admin\n",
		];
	}

	private function assertIsExtractedWordPress( string $dir ): void {
		$this->assertFileExists( $dir . '/wp-includes/version.php' );
		$this->assertFileExists( $dir . '/wp-load.php' );
		$this->assertFileExists( $dir . '/wp-admin/index.php' );
		$this->assertStringContainsString( '7.2-alpha-12345', (string) file_get_contents( $dir . '/wp-includes/version.php' ) );
	}

	/**
	 * WordPress.org release archives wrap everything in a `wordpress/` folder.
	 */
	public function testExtractsArchiveWithWordpressFolder(): void {
		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );
		$dest_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dest';

		FeatureContext::extract_wp_zip( $zip_file, $dest_dir );

		$this->assertIsExtractedWordPress( $dest_dir );
		$this->assertDirectoryDoesNotExist( $dest_dir . '/wordpress' );
	}

	/**
	 * Some WordPress core build artifacts wrap everything in a `build/` folder instead.
	 */
	public function testExtractsArchiveWithBuildFolder(): void {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Matches the artifact name used by WordPress core.
		$zip_file = $this->create_zip( 'wordpress.zip', $this->wp_entries( 'build/' ) );
		$dest_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dest';

		FeatureContext::extract_wp_zip( $zip_file, $dest_dir );

		$this->assertIsExtractedWordPress( $dest_dir );
		$this->assertDirectoryDoesNotExist( $dest_dir . '/build' );
	}

	public function testExtractsArchiveWithoutWrappingFolder(): void {
		$zip_file = $this->create_zip( 'flat.zip', $this->wp_entries() );
		$dest_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dest';

		FeatureContext::extract_wp_zip( $zip_file, $dest_dir );

		$this->assertIsExtractedWordPress( $dest_dir );
	}

	/**
	 * Archives created on macOS carry an additional `__MACOSX/` folder.
	 */
	public function testExtractsArchiveWithSiblingFolder(): void {
		$entries                              = $this->wp_entries( 'wordpress/' );
		$entries['__MACOSX/._wp-load.php']    = 'junk';
		$entries['__MACOSX/nested/._foo.php'] = 'junk';

		$zip_file = $this->create_zip( 'macos.zip', $entries );
		$dest_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dest';

		FeatureContext::extract_wp_zip( $zip_file, $dest_dir );

		$this->assertIsExtractedWordPress( $dest_dir );
	}

	/**
	 * The destination is replaced wholesale, so files from a previous extraction do not linger.
	 */
	public function testReplacesExistingDestination(): void {
		$dest_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dest';
		mkdir( $dest_dir . '/wp-content', 0777, true );
		file_put_contents( $dest_dir . '/wp-content/stale.php', '<?php // stale' );

		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );

		FeatureContext::extract_wp_zip( $zip_file, $dest_dir );

		$this->assertIsExtractedWordPress( $dest_dir );
		$this->assertFileDoesNotExist( $dest_dir . '/wp-content/stale.php' );
	}

	public function testThrowsOnArchiveWithoutWordPress(): void {
		$zip_file = $this->create_zip(
			'not-wordpress.zip',
			[
				'some-plugin/some-plugin.php' => "<?php\n// plugin\n",
			]
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'does not look like a WordPress archive' );

		FeatureContext::extract_wp_zip( $zip_file, $this->temp_dir . DIRECTORY_SEPARATOR . 'dest' );
	}

	public function testThrowsOnUnreadableArchive(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to open the zip file' );

		FeatureContext::extract_wp_zip( $this->temp_dir . DIRECTORY_SEPARATOR . 'missing.zip', $this->temp_dir . DIRECTORY_SEPARATOR . 'dest' );
	}

	/**
	 * Both `cache_wp_files()` and `download_wp()` need to agree on the cache directory,
	 * so it is worth pinning down how it is derived. These are internals, hence reflection.
	 *
	 * @param string $version
	 * @return string
	 */
	private function get_core_cache_dir( $version = '' ): string {
		$method = new \ReflectionMethod( FeatureContext::class, 'get_core_cache_dir' );
		$method->setAccessible( true );

		/** @var string $cache_dir */
		$cache_dir = $method->invoke( null, $version );

		return $cache_dir;
	}

	private function reset_core_zip(): void {
		$property = new \ReflectionProperty( FeatureContext::class, 'core_zip' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	public function testCacheDirIsDerivedFromWpVersionWithoutArchive(): void {
		putenv( 'WP_CLI_TEST_CORE_ZIP' );
		$this->reset_core_zip();

		$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-6.4.2', $this->get_core_cache_dir( '6.4.2' ) );
	}

	public function testCacheDirIsDerivedFromArchiveContents(): void {
		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );

		putenv( "WP_CLI_TEST_CORE_ZIP={$zip_file}" );
		$this->reset_core_zip();

		try {
			$expected = substr( (string) md5_file( $zip_file ), 0, 12 );
			$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-zip-' . $expected, $this->get_core_cache_dir() );
		} finally {
			putenv( 'WP_CLI_TEST_CORE_ZIP' );
			$this->reset_core_zip();
		}
	}

	/**
	 * A "Given a WP 6.4.2 installation" step must keep working while an archive is configured.
	 */
	public function testExplicitVersionTakesPrecedenceOverArchive(): void {
		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );

		putenv( "WP_CLI_TEST_CORE_ZIP={$zip_file}" );
		$this->reset_core_zip();

		try {
			$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-6.4.2', $this->get_core_cache_dir( '6.4.2' ) );
		} finally {
			putenv( 'WP_CLI_TEST_CORE_ZIP' );
			$this->reset_core_zip();
		}
	}

	public function testThrowsOnMissingConfiguredArchive(): void {
		putenv( 'WP_CLI_TEST_CORE_ZIP=' . $this->temp_dir . DIRECTORY_SEPARATOR . 'missing.zip' );
		$this->reset_core_zip();

		try {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'Could not read the WP_CLI_TEST_CORE_ZIP archive' );

			$this->get_core_cache_dir();
		} finally {
			putenv( 'WP_CLI_TEST_CORE_ZIP' );
			$this->reset_core_zip();
		}
	}
}
