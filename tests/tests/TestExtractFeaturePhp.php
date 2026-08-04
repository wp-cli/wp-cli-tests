<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
class TestExtractFeaturePhp extends TestCase {

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

	protected function set_up(): void {
		parent::set_up();

		$this->temp_dir     = Utils\get_temp_dir() . uniqid( 'wp-cli-test-extract-feature-php-', true );
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
	 * Runs the extract-feature-php.php script from within the temporary directory.
	 *
	 * @param string[] $args Arguments to pass to the script.
	 * @return array{output: string, exit_code: int} Combined output and exit code of the script.
	 */
	private function run_script( array $args ): array {
		$script = dirname( dirname( __DIR__ ) ) . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR . 'extract-feature-php.php';

		// Use the `-n` flag to disable loading of `php.ini` and ensure a clean environment.
		$command = escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$command = 'cd ' . escapeshellarg( $this->temp_dir ) . ' && ' . $command . ' 2>&1';

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
	private function create_feature_file( $relative_path, $contents ): string {
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
	 * @return string[] Sorted list of relative file paths.
	 */
	private function get_extracted_files(): array {
		if ( ! is_dir( $this->target_dir ) ) {
			return array();
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->target_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$files = array();

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
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
	private function get_extracted_contents( $relative_path ): string {
		$contents = file_get_contents( $this->target_dir . '/' . $relative_path );

		return false === $contents ? '' : $contents;
	}

	public function test_extracts_block_with_opening_tag(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'example.feature_L5_E8_HASPHP.php' ), $this->get_extracted_files() );

		// The block is padded with one empty line per preceding line of the
		// feature file, so that reported line numbers keep matching.
		$this->assertSame(
			"\n\n\n\n\n<?php\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'example.feature_L5_E8_HASPHP.php' )
		);
	}

	public function test_extracts_block_without_opening_tag(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'example.feature_L5_E7_NOPHP.php' ), $this->get_extracted_files() );

		// The added opening tag takes the place of the docstring delimiter, so
		// that it is not overwritten by the first line of code.
		$this->assertSame(
			"\n\n\n\n<?php\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'example.feature_L5_E7_NOPHP.php' )
		);
	}

	public function test_extracts_multiple_blocks_from_one_feature_file(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "\n"
			. "  Scenario: Two PHP blocks\n"
			. "    Given a first.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
			. "    And a second.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$baz = 'qux';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			array(
				'example.feature_L10_E13_HASPHP.php',
				'example.feature_L5_E8_HASPHP.php',
			),
			$this->get_extracted_files()
		);
	}

	public function test_extracts_from_nested_directories(): void {
		$this->create_feature_file(
			'sub/nested.feature',
			"Feature: Nested\n"
			. "\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'sub/nested.feature_L5_E8_HASPHP.php' ), $this->get_extracted_files() );
	}

	public function test_extraction_preserves_relative_indentation_and_empty_lines(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "\n"
			. "      if ( true ) {\n"
			. "      \t\$foo = 'bar';\n"
			. "      }\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"\n\n\n\n<?php\n\nif ( true ) {\n\t\$foo = 'bar';\n}\n",
			$this->get_extracted_contents( 'example.feature_L4_E10_HASPHP.php' )
		);
	}

	public function test_extraction_skips_docstrings_that_are_not_php_files(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: An expectation about a file\n"
			. "    Then the wp-config.php file should contain:\n"
			. "      \"\"\"\n"
			. "      if ( defined( 'X' ) === false ) { define( 'X', true ); }\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array(), $this->get_extracted_files() );
	}

	public function test_extraction_keeps_empty_lines_before_the_opening_tag(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"\n\n\n\n\n<?php\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'example.feature_L4_E8_HASPHP.php' )
		);

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_keeps_unrelated_files_in_target_directory(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		mkdir( $this->target_dir );
		file_put_contents( $this->target_dir . '/keep-me.txt', 'important' );
		file_put_contents( $this->target_dir . '/stale.feature_L1_E2_HASPHP.php', '<?php' );

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertFileExists( $this->target_dir . '/keep-me.txt' );
		$this->assertSame( 'important', file_get_contents( $this->target_dir . '/keep-me.txt' ) );
		$this->assertFileDoesNotExist( $this->target_dir . '/stale.feature_L1_E2_HASPHP.php' );
	}

	public function test_extraction_refuses_to_use_the_source_directory_as_target(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', 'features' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertFileExists( $feature_file );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_refuses_to_use_the_current_directory_as_target(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', '.' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertDirectoryExists( $this->features_dir );
	}

	public function test_directories_are_not_mistaken_for_an_action(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'example.feature_L4_E7_HASPHP.php' ), $this->get_extracted_files() );
	}

	public function test_missing_arguments_are_reported(): void {
		$result = $this->run_script( array( 'extract' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Usage:', $result['output'] );
	}

	public function test_update_syncs_fixes_back_into_the_feature_file(): void {
		$feature_file = $this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo='bar';\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		$extracted = $this->target_dir . '/example.feature_L4_E7_HASPHP.php';
		file_put_contents( $extracted, "\n\n\n\n<?php\n\$foo = 'bar';\n\$baz = 'qux';\n" );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \$baz = 'qux';\n"
			. "      \"\"\"\n",
			file_get_contents( $feature_file )
		);
	}

	public function test_update_does_not_add_the_generated_opening_tag(): void {
		$feature_file = $this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      \$foo='bar';\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		$extracted = $this->target_dir . '/example.feature_L4_E6_NOPHP.php';
		file_put_contents( $extracted, "\n\n\n<?php\n\$foo = 'bar';\n" );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n",
			file_get_contents( $feature_file )
		);
	}

	public function test_update_without_changes_leaves_the_feature_file_untouched(): void {
		// Includes a block starting and ending with an empty line, nested
		// directories, and code that is indented relative to the block.
		$contents = "Feature: Example\n"
			. "\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "\n"
			. "      <?php\n"
			. "      if ( true ) {\n"
			. "      \t\$foo = 'bar';\n"
			. "      }\n"
			. "\n"
			. "      \"\"\"\n"
			. "    And a second.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$baz = 'qux';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'sub/example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_update_reports_unexpected_content_without_changing_the_feature_file(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		// Shift the whole block, so the padding no longer lines up.
		$extracted = $this->target_dir . '/example.feature_L4_E7_HASPHP.php';
		file_put_contents( $extracted, "\$shifted = true;\n" . (string) file_get_contents( $extracted ) );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}
}
