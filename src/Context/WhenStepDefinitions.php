<?php

namespace WP_CLI\Tests\Context;

use WP_CLI\Process;
use Exception;

trait WhenStepDefinitions {

	public function wpcli_tests_invoke_proc( $proc, $mode ) {
		$map    = array(
			'run' => 'run_check_stderr',
			'try' => 'run',
		);
		$method = $map[ $mode ];

		return $proc->$method();
	}

	public function wpcli_tests_capture_email_sends( $stdout ) {
		$stdout = preg_replace( '#WP-CLI test suite: Sent email to.+\n?#', '', $stdout, -1, $email_sends );

		return array( $stdout, $email_sends );
	}

	/**
	 * Launch a given command in the background.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP install
	 *   And I launch in the background `wp server --host=localhost --port=8181`
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @When /^I launch in the background `([^`]+)`$/
	 */
	public function when_i_launch_in_the_background( $cmd ) {
		$this->background_proc( $cmd );
	}

	/**
	 * Run or try a given command.
	 *
	 * `run` expects an exit code 0, whereas `try` allows for non-zero exit codes.
	 *
	 * So if using `run` and the command errors, the step will fail.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp core version`
	 *   Then STDOUT should contain:
	 *     """
	 *     6.8
	 *     """
	 *
	 * Scenario: My other scenario
	 *   When I try `wp i18n make-pot foo bar/baz.pot`
	 *   Then STDERR should contain:
	 *     """
	 *     Error: Not a valid source directory.
	 *     """
	 *   And the return code should be 1
	 * ```
	 *
	 * @access public
	 *
	 * @When /^I (run|try) `([^`]+)`$/
	 */
	public function when_i_run( $mode, $cmd ) {
		$cmd          = $this->replace_variables( $cmd );
		$this->result = $this->wpcli_tests_invoke_proc( $this->proc( $cmd ), $mode );
		list( $this->result->stdout, $this->email_sends ) = $this->wpcli_tests_capture_email_sends( $this->result->stdout );
	}

	/**
	 * Run or try a given command in a subdirectory.
	 *
	 * `run` expects an exit code 0, whereas `try` allows for non-zero exit codes.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp core is-installed`
	 *   Then STDOUT should be empty
	 *
	 *   When I run `wp core is-installed` from 'foo/wp-content'
	 *   Then STDOUT should be empty
	 * ```
	 *
	 * @access public
	 *
	 * @When /^I (run|try) `([^`]+)` from '([^\s]+)'$/
	 */
	public function when_i_run_from_a_subfolder( $mode, $cmd, $subdir ) {
		$cmd          = $this->replace_variables( $cmd );
		$this->result = $this->wpcli_tests_invoke_proc( $this->proc( $cmd, array(), $subdir ), $mode );
		list( $this->result->stdout, $this->email_sends ) = $this->wpcli_tests_capture_email_sends( $this->result->stdout );
	}

	/**
	 * Run or try the previous command again.
	 *
	 * `run` expects an exit code 0, whereas `try` allows for non-zero exit codes.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp site option update admin_user_id 1`
	 *   Then STDOUT should contain:
	 *     """
	 *     Success: Updated 'admin_user_id' site option.
	 *     """
	 *
	 *   When I run the previous command again
	 *   Then STDOUT should contain:
	 *     """
	 *     Success: Value passed for 'admin_user_id' site option is unchanged.
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @When /^I (run|try) the previous command again$/
	 */
	public function when_i_run_the_previous_command_again( $mode ) {
		if ( ! isset( $this->result ) ) {
			throw new Exception( 'No previous command.' );
		}

		$proc         = Process::create( $this->result->command, $this->result->cwd, $this->result->env );
		$this->result = $this->wpcli_tests_invoke_proc( $proc, $mode );
		list( $this->result->stdout, $this->email_sends ) = $this->wpcli_tests_capture_email_sends( $this->result->stdout );
	}
}
