<?php

namespace WP_CLI\Tests\Tests;

class TestPhpStanFeatureFiles extends FeatureFilesTestCase {

	protected function get_script_name(): string {
		return 'phpstan-feature-files.php';
	}

	/**
	 * `php.ini` is loaded as usual here, as the script needs ext-tokenizer.
	 */
	protected function get_php_flags(): array {
		return array();
	}

	/**
	 * Returns the manifest that extraction wrote to the target directory.
	 *
	 * @return array<string, mixed> Decoded manifest.
	 */
	private function get_manifest(): array {
		$contents = file_get_contents( $this->target_dir . '/manifest.json' );

		$manifest = false === $contents ? null : json_decode( $contents, true );

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Writes a file holding the JSON output of a PHPStan run.
	 *
	 * @param string                                                    $name     Name of the file to write.
	 * @param array<string, array<int, array<string, bool|int|string>>> $messages Messages per extracted file, relative to the target directory.
	 * @return string Full path to the created file.
	 */
	private function create_phpstan_results( $name, array $messages ): string {
		$files = array();
		$total = 0;

		foreach ( $messages as $relative_path => $file_messages ) {
			$files[ $this->target_dir . '/' . $relative_path ] = array(
				'errors'   => count( $file_messages ),
				'messages' => $file_messages,
			);

			$total += count( $file_messages );
		}

		$path = $this->temp_dir . '/' . $name;

		file_put_contents(
			$path,
			(string) json_encode(
				array(
					'totals' => array(
						'errors'      => 0,
						'file_errors' => $total,
					),
					'files'  => (object) $files,
					'errors' => array(),
				)
			)
		);

		return $path;
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
		$this->assertSame( array( 'batch0/example.feature_L5_E8.php' ), $this->get_extracted_files() );

		// The opening tag goes on the first line, and the block is padded with one
		// empty line per preceding line of the feature file, so that reported line
		// numbers keep matching. The tag the block brought along is dropped.
		$this->assertSame(
			"<?php\n\n\n\n\n\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'batch0/example.feature_L5_E8.php' )
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
		$this->assertSame( array( 'batch0/example.feature_L5_E7.php' ), $this->get_extracted_files() );
		$this->assertSame(
			"<?php\n\n\n\n\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'batch0/example.feature_L5_E7.php' )
		);
	}

	public function test_extracts_multiple_blocks_from_one_feature_file(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
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
				'batch0/example.feature_L4_E7.php',
				'batch0/example.feature_L9_E12.php',
			),
			$this->get_extracted_files()
		);
		$this->assertSame(
			"<?php\n\n\n\n\n\n\n\n\n\n\$baz = 'qux';\n",
			$this->get_extracted_contents( 'batch0/example.feature_L9_E12.php' )
		);
	}

	public function test_extracts_from_nested_directories(): void {
		$this->create_feature_file(
			'sub/nested.feature',
			"Feature: Nested\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'batch0/sub/nested.feature_L4_E7.php' ), $this->get_extracted_files() );
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
			"<?php\n\n\n\n\n\nif ( true ) {\n\t\$foo = 'bar';\n}\n",
			$this->get_extracted_contents( 'batch0/example.feature_L4_E10.php' )
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

	/**
	 * Anything in front of the opening tag counts as inline HTML, which makes a
	 * `declare()` or `namespace` statement a fatal error. PHPStan stops analysing
	 * altogether when a single file fails to parse, so the padding goes after the
	 * opening tag rather than in front of it.
	 */
	public function test_extraction_keeps_declare_and_namespace_statements_valid(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A strict block\n"
			. "    Given a strict.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      declare( strict_types = 1 );\n"
			. "      \"\"\"\n"
			. "  Scenario: A namespaced block\n"
			. "    Given a namespaced.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      namespace Example;\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			array(
				'batch0/example.feature_L10_E13.php',
				'batch0/example.feature_L4_E7.php',
			),
			$this->get_extracted_files()
		);
		$this->assertSame( array(), $this->get_manifest()['skipped'] );

		foreach ( $this->get_extracted_files() as $extracted ) {
			$output    = array();
			$exit_code = 0;
			exec(
				escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $this->target_dir . '/' . $extracted ) . ' 2>&1',
				$output,
				$exit_code
			);

			$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
		}
	}

	public function test_extraction_skips_blocks_that_are_not_standalone_php(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A block holding a Behat placeholder\n"
			. "    Given a placeholder.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      var_export( get_the_title( {POST_ID} ) );\n"
			. "      \"\"\"\n"
			. "  Scenario: A usable block\n"
			. "    Given a fine.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array( 'batch0/example.feature_L10_E13.php' ), $this->get_extracted_files() );

		$skipped = $this->get_manifest()['skipped'];

		$this->assertCount( 1, $skipped );
		$this->assertSame( 4, $skipped[0]['line'] );
		$this->assertStringContainsString( 'features/example.feature', str_replace( '\\', '/', $skipped[0]['file'] ) );
		$this->assertStringContainsString( 'syntax error', $skipped[0]['reason'] );
	}

	public function test_extraction_keeps_blocks_declaring_the_same_symbol_apart(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: First definition\n"
			. "    Given a first.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      class Widget {}\n"
			. "      \"\"\"\n"
			. "  Scenario: Second definition\n"
			. "    Given a second.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      class Widget {}\n"
			. "      \"\"\"\n"
			. "  Scenario: Third definition\n"
			. "    Given a third.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      function widget() {}\n"
			. "      class Widget {}\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			array(
				'batch0/example.feature_L4_E7.php',
				'batch1/example.feature_L10_E13.php',
				'batch2/example.feature_L16_E20.php',
			),
			$this->get_extracted_files()
		);
	}

	public function test_extraction_keeps_blocks_without_shared_symbols_together(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A class with methods\n"
			. "    Given a first.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      class First {\n"
			. "      	public function run() {}\n"
			. "      }\n"
			. "      \"\"\"\n"
			. "  Scenario: Another class with a method of the same name\n"
			. "    Given a second.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      class Second {\n"
			. "      	public function run() {}\n"
			. "      }\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );

		// Methods are not global declarations, so both blocks fit into one batch.
		$this->assertSame(
			array(
				'batch0/example.feature_L12_E17.php',
				'batch0/example.feature_L4_E9.php',
			),
			$this->get_extracted_files()
		);
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
		mkdir( $this->target_dir . '/batch0', 0777, true );
		file_put_contents( $this->target_dir . '/batch0/stale.feature_L1_E2.php', '<?php' );

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertFileExists( $this->target_dir . '/keep-me.txt' );
		$this->assertSame( 'important', file_get_contents( $this->target_dir . '/keep-me.txt' ) );
		$this->assertFileDoesNotExist( $this->target_dir . '/batch0/stale.feature_L1_E2.php' );
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

	public function test_extraction_preserves_indentation_that_mixes_tabs_and_spaces(): void {
		// The shared indentation is taken off a block as a prefix rather than as
		// a number of characters, so a line indented with a tab where the rest of
		// the block uses spaces keeps its tab instead of trading it for a space.
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "\tfoo();\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"<?php\n\n\n\n\n\tfoo();\n",
			$this->get_extracted_contents( 'batch0/example.feature_L4_E7.php' )
		);
	}

	public function test_extraction_refuses_to_use_a_root_directory_as_target(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', '/' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Refusing to use', $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_refuses_a_target_that_only_resolves_to_a_root(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', str_repeat( '../', 64 ) ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Refusing to use', $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_refuses_to_use_the_current_directory_as_target(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', '.' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertDirectoryExists( $this->features_dir );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_reports_unterminated_docstring(): void {
		$this->create_feature_file(
			'unterminated.feature',
			"Feature: Unterminated\n"
			. "  Scenario: Unterminated docstring\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Unterminated docstring', $result['output'] );
	}

	public function test_extraction_reports_missing_source_directory(): void {
		$result = $this->run_script( array( 'extract', 'does-not-exist', 'extracted' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'does not exist', $result['output'] );
	}

	public function test_missing_arguments_are_reported(): void {
		$result = $this->run_script( array( 'extract', 'features' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Usage:', $result['output'] );
	}

	public function test_report_maps_errors_back_onto_the_feature_file(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      echo \$undefined;\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->create_phpstan_results(
			'batch0.json',
			array(
				'batch0/example.feature_L4_E7.php' => array(
					array(
						'message'    => 'Variable $undefined might not be defined.',
						'line'       => 6,
						'ignorable'  => true,
						'identifier' => 'variable.undefined',
					),
				),
			)
		);

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'features/example.feature', str_replace( '\\', '/', $result['output'] ) );
		$this->assertStringContainsString( 'Variable $undefined might not be defined.', $result['output'] );
		$this->assertStringContainsString( 'variable.undefined', $result['output'] );
		$this->assertMatchesRegularExpression( '/^\s+6\s+Variable/m', $result['output'] );
		$this->assertStringContainsString( 'Found 1 error(s)', $result['output'] );
	}

	/**
	 * The path PHPStan reports is not necessarily spelled the way the extraction
	 * wrote it: macOS resolves `/var` to `/private/var`, Windows has a short and
	 * a long form of a directory name.
	 */
	public function test_report_maps_errors_from_a_differently_spelled_path(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      echo \$undefined;\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		$resolved = realpath( $this->target_dir );

		$this->assertNotFalse( $resolved );

		// Report the file through a spelling that differs from the one the
		// extraction was given, the way the platforms above do it. The `/./`
		// segment reproduces that on every platform.
		file_put_contents(
			$this->temp_dir . '/batch0.json',
			(string) json_encode(
				array(
					'totals' => array(
						'errors'      => 0,
						'file_errors' => 1,
					),
					'files'  => array(
						str_replace( '\\', '/', $resolved ) . '/./batch0/example.feature_L4_E7.php' => array(
							'errors'   => 1,
							'messages' => array(
								array(
									'message'    => 'Variable $undefined might not be defined.',
									'line'       => 6,
									'ignorable'  => true,
									'identifier' => 'variable.undefined',
								),
							),
						),
					),
					'errors' => array(),
				)
			)
		);

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringNotContainsString( 'Unexpected file', $result['output'] );
		$this->assertStringContainsString( 'features/example.feature', str_replace( '\\', '/', $result['output'] ) );
	}

	public function test_report_tolerates_results_without_messages(): void {
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

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		file_put_contents(
			$this->temp_dir . '/batch0.json',
			(string) json_encode(
				array(
					'files' => array(
						$this->target_dir . '/batch0/example.feature_L4_E7.php' => array( 'errors' => 1 ),
					),
				)
			)
		);

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertStringNotContainsString( 'Warning', $result['output'] );
	}

	public function test_report_without_results_lists_skipped_blocks(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A block holding a Behat placeholder\n"
			. "    Given a placeholder.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      var_export( get_the_title( {POST_ID} ) );\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		// A package whose feature files hold no analysable block produces no
		// PHPStan results at all, which must not be reported as a failure.
		$result = $this->run_script( array( 'report', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertStringContainsString( 'Skipped 1 PHP block(s)', $result['output'] );
		$this->assertStringContainsString( 'No errors', $result['output'] );
	}

	public function test_report_succeeds_without_errors(): void {
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

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$this->create_phpstan_results( 'batch0.json', array() );

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertStringContainsString( 'No errors', $result['output'] );
	}

	public function test_report_mentions_skipped_blocks(): void {
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A block holding a Behat placeholder\n"
			. "    Given a placeholder.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      var_export( get_the_title( {POST_ID} ) );\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$this->create_phpstan_results( 'batch0.json', array() );

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertStringContainsString( 'Skipped 1 PHP block(s)', $result['output'] );
		$this->assertStringContainsString( 'example.feature:4', str_replace( '\\', '/', $result['output'] ) );
	}

	public function test_report_fails_on_unreadable_results(): void {
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

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		file_put_contents( $this->temp_dir . '/batch0.json', 'not json' );

		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Could not read the PHPStan results', $result['output'] );
	}

	public function test_report_fails_without_a_manifest(): void {
		$result = $this->run_script( array( 'report', 'extracted', 'batch0.json' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'Could not read the manifest', $result['output'] );
	}
}
