<?php
/**
 * Utility functions used by the Behat steps.
 */

namespace WP_CLI\Tests\Context;

use Behat\Behat\Exception\PendingException;
use Exception;
use Mustangostang\Spyc;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

trait Support {

	/**
	 * @param string $regex
	 * @param string $actual
	 * @throws Exception
	 */
	protected function assert_regex( $regex, $actual ): void {
		if ( ! preg_match( $regex, $actual ) ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * @param string $regex
	 * @param string $actual
	 * @throws Exception
	 */
	protected function assert_not_regex( $regex, $actual ): void {
		if ( preg_match( $regex, $actual ) ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * Loose comparison.
	 *
	 * @param mixed $expected
	 * @param mixed $actual
	 * @throws Exception
	 */
	protected function assert_equals( $expected, $actual ): void {
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Deliberate loose comparison.
		if ( $expected != $actual ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * Loose comparison.
	 *
	 * @param mixed $expected
	 * @param mixed $actual
	 * @throws Exception
	 */
	protected function assert_not_equals( $expected, $actual ): void {
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Deliberate loose comparison.
		if ( $expected == $actual ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * @param mixed $actual
	 * @throws Exception
	 *
	 * @phpstan-assert numeric-string|number $actual
	 */
	protected function assert_numeric( $actual ): void {
		if ( ! is_numeric( $actual ) ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * @param mixed $actual
	 * @throws Exception
	 *
	 * @phpstan-assert !(numeric-string|number) $actual
	 */
	protected function assert_not_numeric( $actual ): void {
		if ( is_numeric( $actual ) ) {
			throw new Exception( 'Actual value: ' . var_export( $actual, true ) );
		}
	}

	/**
	 * @param string       $output
	 * @param string       $expected
	 * @param string       $action
	 * @param string|false $message
	 * @param bool         $strictly
	 * @throws Exception
	 */
	protected function check_string( $output, $expected, $action, $message = false, $strictly = false ): void {
		// Strip ANSI color codes before comparing strings.
		if ( ! $strictly ) {
			$output = preg_replace( '/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $output );
		}

		switch ( $action ) {
			case 'be':
				$r = rtrim( $output, "\n" ) === $expected;
				break;

			case 'contain':
				$r = false !== strpos( $output, $expected );
				break;

			case 'not contain':
				$r = false === strpos( $output, $expected );
				break;

			default:
				throw new \Behat\Behat\Tester\Exception\PendingException();
		}

		if ( ! $r ) {
			if ( false === $message ) {
				$message = $output;
			}

			$diff = $this->generate_diff( $expected, rtrim( $output, "\n" ) );
			if ( ! empty( $diff ) ) {
				$message .= "\n\n" . $diff;
			}

			throw new Exception( $message );
		}
	}

	/**
	 * @param array<int, mixed> $expected_rows
	 * @param array<int, mixed> $actual_rows
	 * @param string $output
	 * @throws Exception
	 */
	protected function compare_tables( $expected_rows, $actual_rows, $output ): void {
		// The first row is the header and must be present.
		if ( $expected_rows[0] !== $actual_rows[0] ) {
			$expected_table = implode( "\n", $expected_rows );
			$actual_table   = implode( "\n", $actual_rows );
			$diff           = $this->generate_diff( $expected_table, $actual_table );
			throw new Exception( $output . "\n\n" . $diff );
		}

		unset( $actual_rows[0] );
		unset( $expected_rows[0] );

		$missing_rows = array_diff( $expected_rows, $actual_rows );
		if ( ! empty( $missing_rows ) ) {
			$expected_table = implode( "\n", $expected_rows );
			$actual_table   = implode( "\n", $actual_rows );
			$diff           = $this->generate_diff( $expected_table, $actual_table );
			throw new Exception( $output . "\n\n" . $diff );
		}
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 * @return bool
	 */
	protected function compare_contents( $expected, $actual ) {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			return false;
		}

		if ( is_object( $expected ) ) {
			foreach ( get_object_vars( $expected ) as $name => $value ) {
				if ( ! $this->compare_contents( $value, $actual->$name ) ) {
					return false;
				}
			}
		} elseif ( is_array( $expected ) ) {
			foreach ( $expected as $key => $value ) {
				if ( ! $this->compare_contents( $value, $actual[ $key ] ) ) {
					return false;
				}
			}
		} else {
			return $expected === $actual;
		}

		return true;
	}

	/**
	 * Compare two strings containing JSON to ensure that $actualJson contains at
	 * least what the JSON string $expectedJson contains.
	 *
	 * @param string $actual_json   the JSON string to be tested
	 * @param string $expected_json the expected JSON string
	 *
	 * @return bool Whether or not $actual_json contains $expected_json.
	 *
	 * Examples:
	 *   expected: {'a':1,'array':[1,3,5]}
	 *
	 *   1 )
	 *   actual: {'a':1,'b':2,'c':3,'array':[1,2,3,4,5]}
	 *   return: true
	 *
	 *   2 )
	 *   actual: {'b':2,'c':3,'array':[1,2,3,4,5]}
	 *   return: false
	 *     element 'a' is missing from the root object
	 *
	 *   3 )
	 *   actual: {'a':0,'b':2,'c':3,'array':[1,2,3,4,5]}
	 *   return: false
	 *     the value of element 'a' is not 1
	 *
	 *   4 )
	 *   actual: {'a':1,'b':2,'c':3,'array':[1,2,4,5]}
	 *   return: false
	 *     the contents of 'array' does not include 3
	 */
	protected function check_that_json_string_contains_json_string( $actual_json, $expected_json ) {
		$actual_value   = json_decode( $actual_json );
		$expected_value = json_decode( $expected_json );

		if ( ! $actual_value ) {
			return false;
		}

		return $this->compare_contents( $expected_value, $actual_value );
	}

	/**
	 * Compare two strings to confirm $actualCSV contains $expectedCSV
	 * Both strings are expected to have headers for their CSVs.
	 * $actualCSV must match all data rows in $expectedCSV
	 *
	 * @param string   $actual_csv   A CSV string
	 * @param array<array<string>> $expected_csv A nested array of values
	 * @return bool   Whether $actual_csv contains $expected_csv
	 */
	protected function check_that_csv_string_contains_values( $actual_csv, $expected_csv ) {
		$actual_csv = array_map(
			static function ( $str ) {
				return str_getcsv( $str, ',', '"', '\\' );
			},
			explode( PHP_EOL, $actual_csv )
		);

		if ( empty( $actual_csv ) ) {
			return false;
		}

		/**
		 * @var array<array<string>> $actual_csv
		 */

		// Each sample must have headers.
		$actual_headers   = array_values( array_shift( $actual_csv ) );
		$expected_headers = array_values( array_shift( $expected_csv ) );

		// Each expected_csv must exist somewhere in actual_csv in the proper column.
		$expected_result = 0;
		foreach ( $expected_csv as $expected_row ) {
			$expected_row = array_combine( $expected_headers, $expected_row );
			foreach ( $actual_csv as $actual_row ) {
				if ( count( $actual_headers ) !== count( $actual_row ) ) {
					continue;
				}

				$actual_row = array_intersect_key(
					array_combine(
						$actual_headers,
						$actual_row
					),
					$expected_row
				);

				if ( $actual_row === $expected_row ) {
					++$expected_result;
				}
			}
		}

		return $expected_result >= count( $expected_csv );
	}

	/**
	 * Compare two strings containing YAML to ensure that $actualYaml contains at
	 * least what the YAML string $expectedYaml contains.
	 *
	 * @param string $actual_yaml   the YAML string to be tested
	 * @param string $expected_yaml the expected YAML string
	 *
	 * @return bool whether or not $actual_yaml contains $expected_json
	 */
	protected function check_that_yaml_string_contains_yaml_string( $actual_yaml, $expected_yaml ) {
		$actual_value   = Spyc::YAMLLoad( $actual_yaml );
		$expected_value = Spyc::YAMLLoad( $expected_yaml );

		if ( ! $actual_value ) {
			return false;
		}

		return $this->compare_contents( $expected_value, $actual_value );
	}

	/**
	 * Generate a unified diff between two strings.
	 *
	 * @param string $expected The expected string.
	 * @param string $actual   The actual string.
	 * @return string The unified diff output.
	 */
	protected function generate_diff( string $expected, string $actual ): string {
		$builder = new UnifiedDiffOutputBuilder(
			"--- Expected\n+++ Actual\n",
			false
		);
		$differ  = new Differ( $builder );
		return $differ->diff( $expected, $actual );
	}
}
