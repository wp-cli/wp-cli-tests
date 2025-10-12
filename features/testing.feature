Feature: Test that WP-CLI loads.

  Scenario: WP-CLI loads for your tests
    Given a WP install

    When I run `wp eval 'echo "Hello world.";'`
    Then STDOUT should contain:
      """
      Hello world.
      """

  Scenario: WP Cron is disabled by default
    Given a WP install
    And the wp-config.php file should contain:
      """
      if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
      """
    And a test_cron.php file:
      """
      <?php
      $cron_disabled = defined( "DISABLE_WP_CRON" ) ? DISABLE_WP_CRON : false;
      echo 'DISABLE_WP_CRON is: ' . ( $cron_disabled ? 'true' : 'false' );
      """

    When I run `wp eval-file test_cron.php`
    Then STDOUT should be:
      """
      DISABLE_WP_CRON is: true
      """

  @require-sqlite
  Scenario: Uses SQLite
    Given a WP install
    When I run `wp eval 'echo DB_ENGINE;'`
    Then STDOUT should contain:
      """
      sqlite
      """

  @require-mysql
  Scenario: Uses MySQL
    Given a WP install
    When I run `wp eval 'var_export( defined("DB_ENGINE") );'`
    Then STDOUT should be:
      """
      false
      """

  @require-sqlite
  Scenario: Custom wp-content directory
    Given a WP install
    And a custom wp-content directory

    When I run `wp eval 'echo DB_ENGINE;'`
    Then STDOUT should contain:
      """
      sqlite
      """

  @require-sqlite
  Scenario: Composer installation
    Given a WP install with Composer

    When I run `wp eval 'echo DB_ENGINE;'`
    Then STDOUT should contain:
      """
      sqlite
      """
