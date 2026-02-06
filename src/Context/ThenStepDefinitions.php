<?php

namespace WP_CLI\Tests\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Exception;
use Requests;
use RuntimeException;

trait ThenStepDefinitions {

	use Support;

	/**
	 * Expect a specific exit code of the previous command.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   When I try `wp plugin install`
	 *   Then the return code should be 1
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^the return code should( not)? be (\d+)$/
	 *
	 * @param bool $not
	 * @param numeric-string $return_code
	 */
	public function then_the_return_code_should_be( $not, $return_code ): void {
		if (
			( ! $not && (int) $return_code !== $this->result->return_code )
			|| ( $not && (int) $return_code === $this->result->return_code )
		) {
			throw new RuntimeException( $this->result );
		}
	}

	/**
	 * Check the contents of STDOUT or STDERR.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   When I run `wp core is-installed`
	 *   Then STDOUT should be empty
	 *
	 * Scenario: My other scenario
	 *   Given a WP install
	 *   When I run `wp plugin install akismet`
	 *   Then STDOUT should contain:
	 *     """
	 *     Plugin installed successfully.
	 *     """
	 *   And STDERR should be empty
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should( strictly)? (be|contain|not contain):$/
	 *
	 * @param string $stream
	 * @param bool $strictly
	 * @param string $action
	 * @param PyStringNode $expected
	 */
	public function then_stdout_stderr_should_contain( $stream, $strictly, $action, PyStringNode $expected ): void {

		$stream = strtolower( $stream );

		$expected = $this->replace_variables( (string) $expected );

		$this->check_string( $this->result->$stream, $expected, $action, $this->result, (bool) $strictly );
	}

	/**
	 * Expect STDOUT or STDERR to be a numeric value.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   When I run `wp db size --size_format=b`
	 *   Then STDOUT should be a number
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should be a number$/
	 *
	 * @param string $stream
	 */
	public function then_stdout_stderr_should_be_a_number( $stream ): void {

		$stream = strtolower( $stream );

		$this->assert_numeric( trim( $this->result->$stream, "\n" ) );
	}

	/**
	 * Expect STDOUT or STDERR to not be a numeric value.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   When I run `wp post list --format=json`
	 *   Then STDOUT should not be a number
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should not be a number$/
	 *
	 * @param string $stream
	 */
	public function then_stdout_stderr_should_not_be_a_number( $stream ): void {

		$stream = strtolower( $stream );

		$this->assert_not_numeric( trim( $this->result->$stream, "\n" ) );
	}

	/**
	 * Expect STDOUT to be a table containing the given rows.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   When I run `wp config list --fields=name,type`
	 *   Then STDOUT should be a table containing rows:
	 *     | name    | type     |
	 *     | DB_NAME | constant |
	 *     | DB_USER | constant |
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should be a table containing rows:$/
	 */
	public function then_stdout_should_be_a_table_containing_rows( TableNode $expected ): void {
		$output      = $this->result->stdout;
		$actual_rows = explode( "\n", rtrim( $output, "\n" ) );

		$expected_rows = array();
		foreach ( $expected->getRows() as $row ) {
			$expected_rows[] = $this->replace_variables( implode( "\t", $row ) );
		}

		$this->compare_tables( $expected_rows, $actual_rows, $output );
	}

	/**
	 * Expect STDOUT to end with a table containing the given rows.
	 *
	 * Useful when the table is preceded by some other output.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   When I run `wp search-replace foo bar --report-changed-only`
	 *   Then STDOUT should contain:
	 *     """
	 *     Success: Made 3 replacements.
	 *     """
	 *   And STDOUT should end with a table containing rows:
	 *     | Table       | Column       | Replacements | Type |
	 *     | wp_options  | option_value | 1            | PHP  |
	 *     | wp_postmeta | meta_value   | 1            | SQL  |
	 *     | wp_posts    | post_title   | 1            | SQL  |
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should end with a table containing rows:$/
	 */
	public function then_stdout_should_end_with_a_table_containing_rows( TableNode $expected ): void {
		$output      = $this->result->stdout;
		$actual_rows = explode( "\n", rtrim( $output, "\n" ) );

		$expected_rows = array();
		foreach ( $expected->getRows() as $row ) {
			$expected_rows[] = $this->replace_variables( implode( "\t", $row ) );
		}

		$start = array_search( $expected_rows[0], $actual_rows, true );

		if ( false === $start ) {
			throw new Exception( $this->result );
		}

		$this->compare_tables( $expected_rows, array_slice( $actual_rows, $start ), $output );
	}

	/**
	 * Expect valid JSON output in STDOUT.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp post meta get 1 meta-key --format=json`
	 *   Then STDOUT should be JSON containing:
	 *     """
	 *     {
	 *     "foo": "baz"
	 *     }
	 *   """
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should be JSON containing:$/
	 */
	public function then_stdout_should_be_json_containing( PyStringNode $expected ): void {
		$output   = $this->result->stdout;
		$expected = $this->replace_variables( (string) $expected );

		if ( ! $this->check_that_json_string_contains_json_string( $output, $expected ) ) {
			$message = (string) $this->result;
			// Pretty print JSON for better diff readability.
			$expected_decoded = json_decode( $expected );
			$actual_decoded   = json_decode( $output );
			if ( null !== $expected_decoded && null !== $actual_decoded ) {
				$expected_json = json_encode( $expected_decoded, JSON_PRETTY_PRINT );
				$actual_json   = json_encode( $actual_decoded, JSON_PRETTY_PRINT );
				if ( false !== $expected_json && false !== $actual_json ) {
					$diff = $this->generate_diff( $expected_json, $actual_json );
					if ( ! empty( $diff ) ) {
						$message .= "\n\n" . $diff;
					}
				}
			}
			throw new Exception( $message );
		}
	}

	/**
	 * Expect valid JSON array output in STDOUT.
	 *
	 * Errors when some items are missing from the expected array.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp plugin list --field=name --format=json`
	 *   Then STDOUT should be a JSON array containing:
	 *     """
	 *     ["akismet", "hello-dolly"]
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should be a JSON array containing:$/
	 */
	public function then_stdout_should_be_a_json_array_containing( PyStringNode $expected ): void {
		$output   = $this->result->stdout;
		$expected = $this->replace_variables( (string) $expected );

		$actual_values   = json_decode( $output );
		$expected_values = json_decode( $expected );

		$missing = array_diff( $expected_values, $actual_values );
		if ( ! empty( $missing ) ) {
			$message = (string) $this->result;
			// Pretty print JSON arrays for better diff readability.
			if ( null !== $expected_values && null !== $actual_values ) {
				$expected_json = json_encode( $expected_values, JSON_PRETTY_PRINT );
				$actual_json   = json_encode( $actual_values, JSON_PRETTY_PRINT );
				if ( false !== $expected_json && false !== $actual_json ) {
					$diff = $this->generate_diff( $expected_json, $actual_json );
					if ( ! empty( $diff ) ) {
						$message .= "\n\n" . $diff;
					}
				}
			}
			throw new Exception( $message );
		}
	}

	/**
	 * Expect STDOUT to be CSV containing certain values.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp term list post_tag --fields=name,slug --format=csv`
	 *   Then STDOUT should be CSV containing:
	 *     | name      | slug |
	 *     | Test term | test |
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should be CSV containing:$/
	 */
	public function then_stdout_should_be_csv_containing( TableNode $expected ): void {
		$output = $this->result->stdout;

		$expected_rows = $expected->getRows();
		foreach ( $expected_rows as &$row ) {
			foreach ( $row as &$value ) {
				$value = $this->replace_variables( $value );
			}
		}

		if ( ! $this->check_that_csv_string_contains_values( $output, $expected_rows ) ) {
			$message = (string) $this->result;
			// Convert expected rows to CSV format for diff.
			$expected_csv = '';
			foreach ( $expected_rows as $row ) {
				$expected_csv .= implode( ',', array_map( 'trim', $row ) ) . "\n";
			}
			$diff = $this->generate_diff( trim( $expected_csv ), trim( $output ) );
			if ( ! empty( $diff ) ) {
				$message .= "\n\n" . $diff;
			}
			throw new Exception( $message );
		}
	}

	/**
	 * Expect STDOUT to be YAML containing certain content.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp cli alias list`
	 *   Then STDOUT should be YAML containing:
	 *     """
	 *     @all: Run command against every registered alias.
	 *     @foo:
	 *       path: {TEST_DIR}/foo
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^STDOUT should be YAML containing:$/
	 */
	public function then_stdout_should_be_yaml_containing( PyStringNode $expected ): void {
		$output   = $this->result->stdout;
		$expected = $this->replace_variables( (string) $expected );

		if ( ! $this->check_that_yaml_string_contains_yaml_string( $output, $expected ) ) {
			$message = (string) $this->result;
			$diff    = $this->generate_diff( $expected, $output );
			if ( ! empty( $diff ) ) {
				$message .= "\n\n" . $diff;
			}
			throw new Exception( $message );
		}
	}

	/**
	 * Expect STDOUT or STDERR to be empty.
	 *
	 * ```
	 * Scenario: My other scenario
	 *   Given a WP install
	 *   When I run `wp plugin install akismet`
	 *   Then STDERR should be empty
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 *
	 * @param string $stream
	 */
	public function then_stdout_stderr_should_be_empty( $stream ): void {

		$stream = strtolower( $stream );

		if ( ! empty( $this->result->$stream ) ) {
			throw new Exception( $this->result );
		}
	}

	/**
	 * Expect STDOUT or STDERR not to be empty.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp user create examplejane jane@example.com`
	 *   Then STDOUT should not be empty
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should not be empty$/
	 *
	 * @param string $stream
	 */
	public function then_stdout_stderr_should_not_be_empty( $stream ): void {

		$stream = strtolower( $stream );

		if ( '' === rtrim( $this->result->$stream, "\n" ) ) {
			throw new Exception( $this->result );
		}
	}

	/**
	 * Expect STDOUT or STDERR to be a version string comparing to the given version.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP install
	 *   When I run `wp core version
	 *   Then STDOUT should be a version string >= 6.8
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should be a version string (<|<=|>|>=|==|=|!=|<>) ([+\w.{}-]+)$/
	 *
	 * @param string $stream
	 * @param string $operator
	 * @param string $goal_ver
	 */
	public function then_stdout_stderr_should_be_a_specific_version_string( $stream, $operator, $goal_ver ): void {
		$goal_ver = $this->replace_variables( $goal_ver );
		$stream   = strtolower( $stream );
		if ( false === version_compare( trim( $this->result->$stream, "\n" ), $goal_ver, $operator ) ) {
			throw new Exception( $this->result );
		}
	}

	/**
	 * Expect a certain file or directory to (not) exist or (not) contain certain contents.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp core download`
	 *   Then the wp-settings.php file should exist
	 *   And the wp-content directory should exist
	 *   And the {RUN_DIR} directory should contain:
	 *     """
	 *     index.php
	 *     license.txt
	 *     """
	 *   And the wp-config.php file should contain:
	 *     """
	 *     That's all, stop editing! Happy publishing.
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^the (.+) (file|directory) should( strictly)? (exist|not exist|be:|contain:|not contain:)$/
	 *
	 * @param string $path     File/directory path.
	 * @param string $type     Type, either 'file' or 'directory'.
	 * @param string $strictly Whether it's a strict check.
	 * @param string $action   Expected status.
	 * @param string $expected Expected content.
	 */
	public function then_a_specific_file_folder_should_exist( $path, $type, $strictly, $action, $expected = null ): void {
		$path = $this->replace_variables( $path );

		$is_absolute = preg_match( '#^[a-zA-Z]:\\\\#', $path ) || ( strlen( $path ) > 0 && ( '/' === $path[0] || '\\' === $path[0] ) );

		// If it's a relative path, make it relative to the current test dir.
		if ( ! $is_absolute ) {
			$path = $this->variables['RUN_DIR'] . DIRECTORY_SEPARATOR . $path;
		}

		$exists = static function ( $path ) use ( $type ) {
			// Clear the stat cache for the path first to avoid
			// potentially inaccurate results when files change outside of PHP.
			// See https://www.php.net/manual/en/function.clearstatcache.php
			clearstatcache( false, $path );

			if ( 'directory' === $type ) {
				return is_dir( $path );
			}

			return file_exists( $path );
		};

		switch ( $action ) {
			case 'exist':
				if ( ! $exists( $path ) ) {
					throw new Exception( "$path doesn't exist." );
				}
				break;
			case 'not exist':
				if ( $exists( $path ) ) {
					throw new Exception( "$path exists." );
				}
				break;
			default:
				if ( ! $exists( $path ) ) {
					throw new Exception( "$path doesn't exist." );
				}
				$action   = substr( $action, 0, -1 );
				$expected = $this->replace_variables( (string) $expected );
				$contents = '';
				if ( 'file' === $type ) {
					$contents = file_get_contents( $path );
				} elseif ( 'directory' === $type ) {
					$files = glob( rtrim( $path, '/' ) . '/*' );
					foreach ( $files as &$file ) {
						$file = str_replace( $path . '/', '', $file );
					}
					$contents = implode( PHP_EOL, $files );
				}
				$this->check_string( $contents, $expected, $action, false, (bool) $strictly );
		}
	}

	/**
	 * Match file contents against a regex.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp scaffold plugin hello-world`
	 *   Then the contents of the wp-content/plugins/hello-world/languages/hello-world.pot file should match /X-Generator:\s/
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^the contents of the (.+) file should( not)? match (((\/.+\/)|(#.+#))([a-z]+)?)$/
	 *
	 * @param string $path
	 * @param bool $not
	 * @param string $expected
	 */
	public function then_the_contents_of_a_specific_file_should_match( $path, $not, $expected ): void {
		$path     = $this->replace_variables( $path );
		$expected = $this->replace_variables( $expected );

		// If it's a relative path, make it relative to the current test dir.
		if ( '/' !== $path[0] ) {
			$path = $this->variables['RUN_DIR'] . "/$path";
		}
		$contents = file_get_contents( $path );
		if ( $not ) {
			$this->assert_not_regex( $expected, $contents );
		} else {
			$this->assert_regex( $expected, $contents );
		}
	}

	/**
	 * Match STDOUT or STDERR against a regex.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp dist-archive wp-content/plugins/hello-world`
	 *   Then STDOUT should match /^Success: Created hello-world.0.1.0.zip \(Size: \d+(?:\.\d*)? [a-zA-Z]{1,3}\)$/
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^(STDOUT|STDERR) should( not)? match (((\/.+\/)|(#.+#))([a-z]+)?)$/
	 *
	 * @param string $stream
	 * @param bool $not
	 * @param string $expected
	 */
	public function then_stdout_stderr_should_match_a_string( $stream, $not, $expected ): void {
		$expected = $this->replace_variables( $expected );

		$stream = strtolower( $stream );
		if ( $not ) {
			$this->assert_not_regex( $expected, $this->result->$stream );
		} else {
			$this->assert_regex( $expected, $this->result->$stream );
		}
	}

	/**
	 * Expect an email to be sent (or not).
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp user reset-password 1`
	 *   Then an email should be sent
	 * ```
	 *
	 * @access public
	 *
	 * @Then /^an email should (be sent|not be sent)$/
	 *
	 * @param string $expected Expected status, either 'be sent' or 'not be sent'.
	 */
	public function then_an_email_should_be_sent( $expected ): void {
		if ( 'be sent' === $expected ) {
			$this->assert_not_equals( 0, $this->email_sends );
		} elseif ( 'not be sent' === $expected ) {
			$this->assert_equals( 0, $this->email_sends );
		} else {
			throw new Exception( 'Invalid expectation' );
		}
	}

	/**
	 * Expect the HTTP status code for visiting `http://localhost:8080`.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation with Composer
	 *   And a PHP built-in web server to serve 'WordPress'
	 *   Then the HTTP status code should be 200
	 * ```
	 *
	 * @access public
	 *
	 * @Then the HTTP status code should be :code
	 *
	 * @param int $return_code Expected HTTP status code.
	 */
	public function then_the_http_status_code_should_be( $return_code ): void {
		// @phpstan-ignore staticMethod.deprecatedClass
		$response = Requests::request( 'http://localhost:8080' );
		$this->assert_equals( $return_code, $response->status_code );
	}
}
