<?php

namespace WP_CLI\Tests\Tests;

use ReflectionMethod;
use WP_CLI\Tests\Context\FeatureContext;
use WP_CLI\Tests\TestCase;

class TestXdebugEnv extends TestCase {

	const VARIABLES = [ 'WP_CLI_TEST_XDEBUG', 'WP_CLI_TEST_COVERAGE', 'XDEBUG_MODE', 'XDEBUG_SESSION', 'XDEBUG_CONFIG' ];

	/**
	 * @var array<string, string|false>
	 */
	private $original_env = [];

	protected function set_up(): void {
		parent::set_up();

		foreach ( self::VARIABLES as $name ) {
			$this->original_env[ $name ] = getenv( $name );
			putenv( $name );
		}
	}

	protected function tear_down(): void {
		foreach ( $this->original_env as $name => $value ) {
			putenv( false === $value ? $name : "{$name}={$value}" );
		}

		parent::tear_down();
	}

	/**
	 * @return array<string, string>
	 */
	private function get_xdebug_env_variables(): array {
		$method = new ReflectionMethod( FeatureContext::class, 'get_xdebug_env_variables' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		/** @var array<string, string> */
		return $method->invoke( null );
	}

	public function test_nothing_is_set_without_the_variable(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=false' );

		$this->assertSame( [], $this->get_xdebug_env_variables() );
	}

	public function test_defaults(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=1' );
		putenv( 'XDEBUG_MODE=' );

		$this->assertSame(
			[
				'XDEBUG_MODE'    => 'debug',
				'XDEBUG_SESSION' => '1',
				'XDEBUG_CONFIG'  => 'log_level=0',
			],
			$this->get_xdebug_env_variables()
		);
	}

	public function test_debug_mode_is_added_to_the_ini_setting(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );

		$modes = explode( ',', $this->get_xdebug_env_variables()['XDEBUG_MODE'] );

		$this->assertContains( 'debug', $modes );
		$this->assertNotContains( 'off', $modes );
	}

	public function test_debug_mode_is_added_to_the_requested_modes(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );
		putenv( 'XDEBUG_MODE=develop, off,trace' );

		$this->assertSame( 'develop,trace,debug', $this->get_xdebug_env_variables()['XDEBUG_MODE'] );
	}

	public function test_coverage_mode_is_kept_when_collecting_code_coverage(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );
		putenv( 'WP_CLI_TEST_COVERAGE=true' );
		putenv( 'XDEBUG_MODE=debug' );

		$this->assertSame( 'debug,coverage', $this->get_xdebug_env_variables()['XDEBUG_MODE'] );
	}

	public function test_session_is_not_overridden(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );
		putenv( 'XDEBUG_SESSION=PHPSTORM' );

		$this->assertArrayNotHasKey( 'XDEBUG_SESSION', $this->get_xdebug_env_variables() );
	}

	public function test_log_level_is_added_to_the_config(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );
		putenv( 'XDEBUG_CONFIG=client_host=192.168.1.2' );

		$this->assertSame( 'client_host=192.168.1.2 log_level=0', $this->get_xdebug_env_variables()['XDEBUG_CONFIG'] );
	}

	public function test_config_with_a_log_level_is_not_overridden(): void {
		putenv( 'WP_CLI_TEST_XDEBUG=true' );
		putenv( 'XDEBUG_CONFIG=log_level=7' );

		$this->assertArrayNotHasKey( 'XDEBUG_CONFIG', $this->get_xdebug_env_variables() );
	}
}
