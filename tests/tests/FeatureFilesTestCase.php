<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

/**
 * Shared scaffolding for the tests of the scripts in `utils` that read the PHP
 * blocks embedded in Behat feature files.
 *
 * Both scripts are stand-alone and are exercised the way they are used, by
 * running them over a directory of feature files written into a temporary
 * directory, so both need the same setup around them.
 */
abstract class FeatureFilesTestCase extends TestCase {

	/**
	 * @var string
	 */
	public $temp_dir;

	/**
	 * @var string
	 */
	public $features_dir;

	/**
	 * @var string
	 */
	public $target_dir;

	/**
	 * Returns the name of the script under test, relative to the `utils` directory.
	 *
	 * @return string Name of the script.
	 */
	abstract protected function get_script_name(): string;

	/**
	 * Returns the flags to run the script under test with.
	 *
	 * @return string[] Flags to pass to the PHP binary.
	 */
	abstract protected function get_php_flags(): array;

	protected function set_up(): void {
		parent::set_up();

		$prefix = 'wp-cli-test-' . basename( $this->get_script_name(), '.php' ) . '-';

		$this->temp_dir     = Utils\get_temp_dir() . uniqid( $prefix, true );
		$this->features_dir = $this->temp_dir . '/features';
		$this->target_dir   = $this->temp_dir . '/extracted';

		mkdir( $this->temp_dir );
		mkdir( $this->features_dir );
	}

	protected function tear_down(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$this->remove_dir( $this->temp_dir );
		}

		parent::tear_down();
	}

	/**
	 * Recursively removes a directory and its contents.
	 *
	 * @param string $dir The directory to remove.
	 */
	private function remove_dir( $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Runs the script under test from within the temporary directory.
	 *
	 * @param string[] $args Arguments to pass to the script.
	 * @return array{output: string, exit_code: int} Combined output and exit code of the script.
	 */
	protected function run_script( array $args ): array {
		$script = dirname( dirname( __DIR__ ) ) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . $this->get_script_name();

		$command = escapeshellarg( PHP_BINARY );

		foreach ( $this->get_php_flags() as $flag ) {
			$command .= ' ' . $flag;
		}

		$command .= ' ' . escapeshellarg( $script );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$cd_command = Utils\is_windows() ? 'cd /d ' : 'cd ';
		$command    = $cd_command . escapeshellarg( $this->temp_dir ) . ' && ' . $command . ' 2>&1';

		$output    = array();
		$exit_code = 0;

		exec( $command, $output, $exit_code );

		return array(
			'output'    => implode( "\n", $output ),
			'exit_code' => $exit_code,
		);
	}

	/**
	 * Creates a feature file in the features directory.
	 *
	 * @param string $relative_path Path relative to the features directory.
	 * @param string $contents      Contents of the feature file.
	 * @return string Full path to the created file.
	 */
	protected function create_feature_file( $relative_path, $contents ): string {
		$path = $this->features_dir . '/' . $relative_path;

		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}

		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Returns the paths of all extracted files, relative to the target directory.
	 *
	 * Extraction only ever writes `.php` files, so anything else in the target
	 * directory was put there by something other than the script under test.
	 *
	 * @return string[] Sorted list of relative file paths.
	 */
	protected function get_extracted_files(): array {
		if ( ! is_dir( $this->target_dir ) ) {
			return array();
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->target_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$files = array();

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $this->target_dir ) + 1 ) );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Returns the contents of an extracted file.
	 *
	 * @param string $relative_path Path relative to the target directory.
	 * @return string Contents of the file.
	 */
	protected function get_extracted_contents( $relative_path ): string {
		$contents = file_get_contents( $this->target_dir . '/' . $relative_path );

		return false === $contents ? '' : $contents;
	}
}
