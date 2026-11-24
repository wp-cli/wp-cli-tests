<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

/**
 * Tests for the `WP_CLI_CS_Feature_Files` ruleset.
 *
 * A block embedded in a feature file is checked as if it were a PHP file of its
 * own, but it comes from a file that indents with spaces, and it is padded with
 * one empty line per preceding line of the feature file so that the line numbers
 * reported against it match. Keeping both intact is what this ruleset is for, so
 * it is checked the way it is used: by running the fixer over a block and
 * looking at what comes back.
 */
class TestFeatureFilesRuleset extends TestCase {

	/**
	 * Sniffs that decide the indentation and the trailing whitespace of a block.
	 *
	 * The run is narrowed down to these so that a fixture does not have to
	 * satisfy the whole standard to be readable.
	 *
	 * @var string
	 */
	const SNIFFS = 'Generic.WhiteSpace.DisallowTabIndent,Generic.WhiteSpace.DisallowSpaceIndent,Generic.WhiteSpace.ScopeIndent,WordPress.Arrays.ArrayIndentation,Squiz.WhiteSpace.SuperfluousWhitespace';

	/**
	 * @var string
	 */
	private $temp_file;

	protected function set_up(): void {
		parent::set_up();

		$this->temp_file = Utils\get_temp_dir() . uniqid( 'wp-cli-test-feature-ruleset-', true ) . '.php';
	}

	protected function tear_down(): void {
		if ( file_exists( $this->temp_file ) ) {
			unlink( $this->temp_file );
		}

		parent::tear_down();
	}

	/**
	 * Runs PHPCBF over a block and returns what it made of it.
	 *
	 * @param string $block Contents of the block, as extraction would write them.
	 * @return string Contents of the block after fixing.
	 */
	private function fix( $block ): string {
		file_put_contents( $this->temp_file, $block );

		$phpcbf = dirname( dirname( __DIR__ ) ) . '/vendor/squizlabs/php_codesniffer/bin/phpcbf';

		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( $phpcbf )
			. ' ' . escapeshellarg( '--standard=WP_CLI_CS_Feature_Files' )
			. ' ' . escapeshellarg( '--sniffs=' . self::SNIFFS )
			. ' ' . escapeshellarg( $this->temp_file )
			. ' 2>&1';

		$output    = array();
		$exit_code = 0;

		exec( $command, $output, $exit_code );

		// PHPCBF reports 1 when it fixed something and 0 when there was nothing
		// to fix. Anything above that is the run itself having gone wrong.
		$this->assertLessThanOrEqual( 1, $exit_code, implode( "\n", $output ) );

		return (string) file_get_contents( $this->temp_file );
	}

	public function test_a_block_is_fixed_to_space_indentation(): void {
		$this->assertSame(
			"\n\n\n<?php\nif ( true ) {\n    \$foo = 'bar';\n}\n",
			$this->fix( "\n\n\n<?php\nif ( true ) {\n\t\$foo = 'bar';\n}\n" )
		);
	}

	public function test_a_block_that_is_already_indented_with_spaces_is_left_alone(): void {
		$block = "\n\n\n<?php\nif ( true ) {\n    \$foo = 'bar';\n}\n";

		$this->assertSame( $block, $this->fix( $block ) );
	}

	public function test_an_array_is_fixed_to_space_indentation(): void {
		// Array indentation is a sniff of its own, with a tab setting of its own.
		$this->assertSame(
			"\n\n\n<?php\n\$foo = array(\n    'bar' => 1,\n);\n",
			$this->fix( "\n\n\n<?php\n\$foo = array(\n\t'bar' => 1,\n);\n" )
		);
	}

	public function test_trailing_whitespace_is_removed(): void {
		// The fixer leaves whitespace behind wherever it breaks a line, so the
		// sniff that cleans that up has to be part of the run.
		$this->assertSame(
			"\n\n\n<?php\nfoo(\n    'bar'\n);\n",
			$this->fix( "\n\n\n<?php\nfoo(\n    'bar' \n); \n" )
		);
	}

	public function test_the_padding_in_front_of_a_block_is_kept(): void {
		// Taking the padding off would shift every line number reported against
		// the feature file the block came from.
		$block = "\n\n\n\n\n<?php\n\$foo = 'bar';\n";

		$this->assertSame( $block, $this->fix( $block ) );
	}
}
