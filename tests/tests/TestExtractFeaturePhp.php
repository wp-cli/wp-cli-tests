<?php

namespace WP_CLI\Tests\Tests;

class TestExtractFeaturePhp extends FeatureFilesTestCase {

	/**
	 * @return string Name of the script.
	 */
	protected function get_script_name(): string {
		return 'extract-feature-php.php';
	}

	/**
	 * The script needs nothing beyond the PHP core, so `php.ini` is left out of
	 * the run to keep the environment it is exercised in predictable.
	 *
	 * @return string[] Flags to pass to the PHP binary.
	 */
	protected function get_php_flags(): array {
		return array( '-n' );
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
		$this->assertSame(
			"\n\n\n\n\n<?php\n\$foo = 'bar';\n",
			$this->get_extracted_contents( 'example.feature_L5_E8_HASPHP.php' )
		);
		$this->assertSame(
			"\n\n\n\n\n\n\n\n\n\n<?php\n\$baz = 'qux';\n",
			$this->get_extracted_contents( 'example.feature_L10_E13_HASPHP.php' )
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

	public function test_extraction_indents_the_block_with_tabs(): void {
		// Feature files indent with spaces, the standard the blocks are checked
		// against indents with tabs. What does not add up to a full tab stop stays
		// a run of spaces, so that alignment survives the conversion.
		$this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      if ( true ) {\n"
			. "          \$foo = 'bar';\n"
			. "        \$baz = 'qux';\n"
			. "      }\n"
			. "      \"\"\"\n"
		);

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"\n\n\n\n<?php\nif ( true ) {\n\t\$foo = 'bar';\n  \$baz = 'qux';\n}\n",
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

		$extracted_files = array();
		$iterator        = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->temp_dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$extracted_files[] = $file->getPathname();
			}
		}
		$this->assertSame( array(), $extracted_files );
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
		$this->assertSame( array(), $this->get_extracted_files() );
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

	public function test_update_drops_trailing_whitespace(): void {
		// The fixer leaves the whitespace it broke a line at behind, and the sniff
		// that would clean that up cannot be part of the run, as it also wants the
		// padding in front of the block gone.
		$feature_file = $this->create_feature_file(
			'example.feature',
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      foo( 'bar' );\n"
			. "      \"\"\"\n"
		);

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		$extracted = $this->target_dir . '/example.feature_L4_E7_HASPHP.php';
		file_put_contents( $extracted, "\n\n\n\n<?php\nfoo(\n\t'bar' \n); \n" );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      foo(\n"
			. "          'bar'\n"
			. "      );\n"
			. "      \"\"\"\n",
			file_get_contents( $feature_file )
		);
	}

	public function test_update_keeps_crlf_line_endings(): void {
		// Trimming a line means taking its line ending off first, so a feature file
		// using CRLF has to get its own back rather than the one PHP writes.
		$contents = "Feature: Example\r\n"
			. "  Scenario: A PHP block\r\n"
			. "    Given a test.php file:\r\n"
			. "      \"\"\"\r\n"
			. "      <?php\r\n"
			. "      if ( true ) {\r\n"
			. "          \$foo = 'bar';\r\n"
			. "      }\r\n"
			. "      \"\"\"\r\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
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
			. "          \$foo = 'bar';\n"
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

	public function test_update_reports_missing_feature_file(): void {
		$feature_file = $this->create_feature_file(
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

		unlink( $feature_file );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'does not exist', $result['output'] );
	}

	public function test_update_skips_block_when_source_coordinates_mismatch(): void {
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );

		// Modify the feature file so the step preceding the docstring no longer creates a PHP file.
		$modified_contents = str_replace( 'Given a test.php file:', 'Given a non-php step:', $contents );
		file_put_contents( $feature_file, $modified_contents );

		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 1, $result['exit_code'] );
		$this->assertStringContainsString( 'is no longer the one that was checked', $result['output'] );
		$this->assertSame( $modified_contents, file_get_contents( $feature_file ) );
	}

	public function test_extraction_skips_php_docstrings_that_do_not_belong_to_a_php_file_step(): void {
		// The block opens with `<?php`, but the step it belongs to states an
		// expectation instead of creating a file. Reformatting it would make it
		// stop matching the file it is checked against.
		$contents = "Feature: Example\n"
			. "  Scenario: An expectation about a file\n"
			. "    Then the wp-config.php file should contain:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      define('FOO',true);\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$result = $this->run_script( array( 'extract', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( array(), $this->get_extracted_files() );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_update_preserves_a_block_indented_below_its_opening_tag(): void {
		// The first line of the block is not the one carrying the least
		// indentation, so the indentation to restore cannot be read off it.
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "    \$foo = 'bar';\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame( $contents, file_get_contents( $feature_file ) );
	}

	public function test_update_converts_tab_indentation_to_spaces(): void {
		// A block is checked as the tab-indented file the standard expects, so a
		// block that arrives with tabs -- written that way, or left behind by a
		// fixer that did not convert them back -- comes back with spaces.
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      if ( true ) {\n"
			. "      \t\$foo = 'bar';\n"
			. "      }\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "      if ( true ) {\n"
			. "          \$foo = 'bar';\n"
			. "      }\n"
			. "      \"\"\"\n",
			file_get_contents( $feature_file )
		);
	}

	public function test_update_converts_indentation_a_tab_at_a_time(): void {
		// The block shares no indentation with its opening tag, so the whole
		// indentation of the line is the fixer's to see and comes back as the
		// number of columns a tab covers rather than as a single space.
		$contents = "Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "\tif ( true ) {}\n"
			. "      \"\"\"\n";

		$feature_file = $this->create_feature_file( 'example.feature', $contents );

		$this->run_script( array( 'extract', 'features', 'extracted' ) );
		$result = $this->run_script( array( 'update', 'features', 'extracted' ) );

		$this->assertSame( 0, $result['exit_code'], $result['output'] );
		$this->assertSame(
			"Feature: Example\n"
			. "  Scenario: A PHP block\n"
			. "    Given a test.php file:\n"
			. "      \"\"\"\n"
			. "      <?php\n"
			. "    if ( true ) {}\n"
			. "      \"\"\"\n",
			file_get_contents( $feature_file )
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
}
