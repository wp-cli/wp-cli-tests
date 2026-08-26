<?php

namespace WP_CLI\Tests\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * The WP_CLI_TEST_CORE_ZIP value the test process started with, if any.
	 *
	 * @var ?string
	 */
	private $original_core_zip;

	protected function set_up(): void {
		parent::set_up();

		$original                = getenv( 'WP_CLI_TEST_CORE_ZIP' );
		$this->original_core_zip = false === $original ? null : $original;

		$this->temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-test-core-zip-', true );
		mkdir( $this->temp_dir );
	}

	protected function tear_down(): void {
		// FeatureContext re-resolves the archive when the environment variable changes,
		// so restoring it is enough to leave the configuration as it was found.
		if ( null === $this->original_core_zip ) {
			putenv( 'WP_CLI_TEST_CORE_ZIP' );
		} else {
			putenv( 'WP_CLI_TEST_CORE_ZIP=' . $this->original_core_zip );
		}

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
	 * An archive that escapes its destination must be rejected rather than extracted.
	 *
	 * @dataProvider data_unsafe_entries
	 *
	 * @param string $entry
	 */
	#[DataProvider( 'data_unsafe_entries' )] // phpcs:ignore PHPCompatibility.Attributes.NewAttributes.PHPUnitAttributeFound
	public function testThrowsOnArchiveEscapingItsDestination( $entry ): void {
		$entries           = $this->wp_entries( 'wordpress/' );
		$entries[ $entry ] = 'escaped';

		$zip_file = $this->create_zip( 'unsafe.zip', $entries );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'would be extracted outside of its destination' );

		FeatureContext::extract_wp_zip( $zip_file, $this->temp_dir . DIRECTORY_SEPARATOR . 'dest' );
	}

	/**
	 * @return array<string, array<string>>
	 */
	public static function data_unsafe_entries(): array {
		return [
			'parent directory'        => [ '../escaped.txt' ],
			'nested parent directory' => [ 'wordpress/../../escaped.txt' ],
			'absolute path'           => [ '/etc/escaped.txt' ],
			'windows separator'       => [ '..\\escaped.txt' ],
			'windows drive letter'    => [ 'C:/escaped.txt' ],
		];
	}

	/**
	 * Both `cache_wp_files()` and `download_wp()` need to agree on the cache directory,
	 * so it is worth pinning down how it is derived.
	 */
	public function testCacheDirIsDerivedFromWpVersionWithoutArchive(): void {
		putenv( 'WP_CLI_TEST_CORE_ZIP' );

		$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-6.4.2', FeatureContext::get_core_cache_dir( '6.4.2' ) );
	}

	public function testCacheDirIsDerivedFromArchiveContents(): void {
		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );

		putenv( "WP_CLI_TEST_CORE_ZIP={$zip_file}" );

		$expected = substr( (string) md5_file( $zip_file ), 0, 12 );

		$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-zip-' . $expected, FeatureContext::get_core_cache_dir() );
	}

	/**
	 * A change of the environment variable must not be served from the memoized value.
	 */
	public function testCacheDirFollowsAChangedArchive(): void {
		$first  = $this->create_zip( 'first.zip', $this->wp_entries( 'wordpress/' ) );
		$second = $this->create_zip( 'second.zip', $this->wp_entries( 'build/' ) );

		putenv( "WP_CLI_TEST_CORE_ZIP={$first}" );
		$first_cache_dir = FeatureContext::get_core_cache_dir();

		putenv( "WP_CLI_TEST_CORE_ZIP={$second}" );
		$second_cache_dir = FeatureContext::get_core_cache_dir();

		$this->assertNotSame( $first_cache_dir, $second_cache_dir );
		$this->assertStringEndsWith( substr( (string) md5_file( $second ), 0, 12 ), $second_cache_dir );
	}

	/**
	 * A "Given a WP 6.4.2 installation" step must keep working while an archive is configured.
	 */
	public function testExplicitVersionTakesPrecedenceOverArchive(): void {
		$zip_file = $this->create_zip( 'release.zip', $this->wp_entries( 'wordpress/' ) );

		putenv( "WP_CLI_TEST_CORE_ZIP={$zip_file}" );

		$this->assertStringEndsWith( 'wp-cli-test-core-download-cache-6.4.2', FeatureContext::get_core_cache_dir( '6.4.2' ) );
	}

	public function testThrowsOnMissingConfiguredArchive(): void {
		putenv( 'WP_CLI_TEST_CORE_ZIP=' . $this->temp_dir . DIRECTORY_SEPARATOR . 'missing.zip' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not read the WP_CLI_TEST_CORE_ZIP archive' );

		FeatureContext::get_core_cache_dir();
	}
}
