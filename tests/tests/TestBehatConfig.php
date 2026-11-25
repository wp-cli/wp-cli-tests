<?php

namespace WP_CLI\Tests\Tests;

use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

class TestBehatConfig extends TestCase {

	/**
	 * @var string
	 */
	private $config_file = '';

	protected function set_up(): void {
		parent::set_up();

		$this->config_file = Utils\get_temp_dir() . uniqid( 'wp-cli-test-behat-config-', true ) . '.yml';

		file_put_contents(
			$this->config_file,
			"default:\n  suites:\n    default:\n      contexts: [ FeatureContext ]\n      paths: [ features/a, features/b ]\n  formatters:\n    pretty: true\n"
		);
	}

	protected function tear_down(): void {
		if ( file_exists( $this->config_file ) ) {
			unlink( $this->config_file );
		}

		parent::tear_down();
	}

	/**
	 * Run the converter the way run-behat-tests does, from the root of this package.
	 *
	 * @param string|null $params Value of BEHAT_PARAMS, if any.
	 * @return array<mixed>
	 */
	private function convert( $params = null ): array {
		$root    = dirname( dirname( __DIR__ ) );
		$command = 'cd ' . escapeshellarg( $root ) . ' && '
			. ( null === $params ? 'unset BEHAT_PARAMS; ' : 'BEHAT_PARAMS=' . escapeshellarg( $params ) . ' ' )
			. escapeshellarg( PHP_BINARY ) . ' utils/behat-config.php ' . escapeshellarg( $this->config_file );

		$output = (string) shell_exec( $command );

		if ( '' === $output ) {
			$this->markTestSkipped( 'The installed Behat reads behat.yml by itself.' );
		}

		$config = json_decode( $output, true );
		$this->assertIsArray( $config );

		return $config;
	}

	public function test_default_profile_is_passed_on(): void {
		$this->assertSame(
			[
				'suites'     => [
					'default' => [
						'contexts' => [ 'FeatureContext' ],
						'paths'    => [ 'features/a', 'features/b' ],
					],
				],
				'formatters' => [ 'pretty' => true ],
			],
			$this->convert()
		);
	}

	public function test_existing_params_are_merged_over_the_profile(): void {
		$config = $this->convert( '{"formatters":{"progress":true}}' );

		$this->assertSame(
			[
				'pretty'   => true,
				'progress' => true,
			],
			$config['formatters']
		);
		$this->assertSame( [ 'FeatureContext' ], $config['suites']['default']['settings']['contexts'] );
	}

	public function test_lists_are_replaced_as_a_whole(): void {
		$config = $this->convert( '{"suites":{"default":{"paths":["features/smoke"]}}}' );
		$this->assertSame( [ 'features/smoke' ], $config['suites']['default']['settings']['paths'] );

		$config = $this->convert( '{"suites":{"default":{"paths":[]}}}' );
		$this->assertSame( [], $config['suites']['default']['settings']['paths'] );
	}

	public function test_both_forms_of_suite_settings_are_reconciled(): void {
		$config = $this->convert( '{"suites":{"default":{"settings":{"paths":["features/smoke"]}}}}' );

		$this->assertSame(
			[
				'settings' => [
					'contexts' => [ 'FeatureContext' ],
					'paths'    => [ 'features/smoke' ],
				],
			],
			$config['suites']['default']
		);
	}
}
