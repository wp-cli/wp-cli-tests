<?php

namespace WP_CLI\Tests\Context;

use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use RuntimeException;
use WP_CLI\Process;
use WP_CLI\Utils;

/**
 * Features context.
 */
class FeatureContext implements SnippetAcceptingContext {

	use GivenStepDefinitions;
	use ThenStepDefinitions;
	use WhenStepDefinitions;

	/**
	 * The result of the last command run with `When I run` or `When I try`. Lives until the end of the scenario.
	 */
	protected $result;

	/**
	 * The number of emails sent by the last command run with `When I run` or `When I try`. Lives until the end of the scenario.
	 */
	protected $email_sends;

	/**
	 * The current working directory for scenarios that have a "Given a WP installation" or "Given an empty directory" step. Variable RUN_DIR. Lives until the end of the scenario.
	 */
	private static $run_dir;

	/**
	 * Where WordPress core is downloaded to for caching, and which is copied to RUN_DIR during a "Given a WP installation" step. Lives until manually deleted.
	 */
	private static $cache_dir;

	/**
	 * The directory that holds the install cache, and which is copied to RUN_DIR during a "Given a WP installation" step. Recreated on each suite run.
	 */
	private static $install_cache_dir;

	/**
	 * The directory that holds a copy of the sqlite-database-integration plugin, and which is copied to RUN_DIR during a "Given a WP installation" step. Lives until manually deleted.
	 */
	private static $sqlite_cache_dir;

	/**
	 * The directory that the WP-CLI cache (WP_CLI_CACHE_DIR, normally "$HOME/.wp-cli/cache") is set to on a "Given an empty cache" step.
	 * Variable SUITE_CACHE_DIR. Lives until the end of the scenario (or until another "Given an empty cache" step within the scenario).
	 */
	private static $suite_cache_dir;

	/**
	 * Where the current WP-CLI source repository is copied to for Composer-based tests with a "Given a dependency on current wp-cli" step.
	 * Variable COMPOSER_LOCAL_REPOSITORY. Lives until the end of the suite.
	 */
	private static $composer_local_repository;

	/**
	 * The test database settings. All but `dbname` can be set via environment variables. The database is dropped at the start of each scenario and created on a "Given a WP installation" step.
	 */
	private static $db_settings = [
		'dbname' => 'wp_cli_test',
		'dbuser' => 'wp_cli_test',
		'dbpass' => 'password1',
		'dbhost' => '127.0.0.1',
	];

	/**
	 *  What type of database should WordPress use for the test installations. Default to MySQL
	 */
	private static $db_type = 'mysql';

	/**
	 * Array of background process ids started by the current scenario. Used to terminate them at the end of the scenario.
	 */
	private $running_procs = [];

	/**
	 * Array of variables available as {VARIABLE_NAME}. Some are always set: CORE_CONFIG_SETTINGS, DB_USER, DB_PASSWORD, DB_HOST, SRC_DIR, CACHE_DIR, WP_VERSION-version-latest.
	 * Some are step-dependent: RUN_DIR, SUITE_CACHE_DIR, COMPOSER_LOCAL_REPOSITORY, PHAR_PATH. One is set on use: INVOKE_WP_CLI_WITH_PHP_ARGS-args.
	 * Scenarios can define their own variables using "Given save" steps. Variables are reset for each scenario.
	 */
	public $variables = [];

	/**
	 * The current feature file and scenario line number as '<file>.<line>'. Used in RUN_DIR and SUITE_CACHE_DIR directory names. Set at the start of each scenario.
	 */
	private static $temp_dir_infix;

	/**
	 * Settings and variables for WP_CLI_TEST_LOG_RUN_TIMES run time logging.
	 */
	private static $log_run_times; // Whether to log run times - WP_CLI_TEST_LOG_RUN_TIMES env var. Set on `@BeforeScenario'.
	private static $suite_start_time; // When the suite started, set on `@BeforeScenario'.
	private static $output_to; // Where to output log - stdout|error_log. Set on `@BeforeSuite`.
	private static $num_top_processes; // Number of processes/methods to output by longest run times. Set on `@BeforeSuite`.
	private static $num_top_scenarios; // Number of scenarios to output by longest run times. Set on `@BeforeSuite`.

	private static $scenario_run_times    = []; // Scenario run times (top `self::$num_top_scenarios` only).
	private static $scenario_count        = 0; // Scenario count, incremented on `@AfterScenario`.
	private static $proc_method_run_times = []; // Array of run time info for proc methods, keyed by method name and arg, each a 2-element array containing run time and run count.

	/**
	 * Get the path to the Composer vendor folder.
	 *
	 * @return string Absolute path to the Composer vendor folder.
	 */
	public static function get_vendor_dir() {
		static $vendor_folder = null;

		if ( null !== $vendor_folder ) {
			return $vendor_folder;
		}

		// We try to detect the vendor folder in the most probable locations.
		$vendor_locations = [
			// wp-cli/wp-cli-tests is a dependency of the current working dir.
			getcwd() . '/vendor',
			// wp-cli/wp-cli-tests is the root project.
			dirname( dirname( __DIR__ ) ) . '/vendor',
			// wp-cli/wp-cli-tests is a dependency.
			dirname( dirname( dirname( dirname( __DIR__ ) ) ) ),
		];

		$vendor_folder = '';
		foreach ( $vendor_locations as $location ) {
			if (
				is_dir( $location )
				&& is_readable( $location )
				&& is_file( "{$location}/autoload.php" )
			) {
				$vendor_folder = $location;
				break;
			}
		}

		return $vendor_folder;
	}

	/**
	 * Get the path to the WP-CLI framework folder.
	 *
	 * @return string Absolute path to the WP-CLI framework folder.
	 */
	public static function get_framework_dir() {
		static $framework_folder = null;

		if ( null !== $framework_folder ) {
			return $framework_folder;
		}

		$vendor_folder = self::get_vendor_dir();

		// Now we need to detect the location of wp-cli/wp-cli package.
		$framework_locations = [
			// wp-cli/wp-cli is the root project.
			dirname( $vendor_folder ),
			// wp-cli/wp-cli is a dependency.
			"{$vendor_folder}/wp-cli/wp-cli",
		];

		$framework_folder = '';
		foreach ( $framework_locations as $location ) {
			if (
				is_dir( $location )
				&& is_readable( $location )
				&& is_file( "{$location}/php/utils.php" )
			) {
				$framework_folder = $location;
				break;
			}
		}

		return $framework_folder;
	}


	/**
	 * Get the path to the WP-CLI binary.
	 *
	 * @return string Absolute path to the WP-CLI binary.
	 */
	public static function get_bin_path() {
		static $bin_path = null;

		if ( null !== $bin_path ) {
			return $bin_path;
		}

		$bin_path = getenv( 'WP_CLI_BIN_DIR' );

		if ( ! empty( $bin_path ) ) {
			return $bin_path;
		}

		$bin_paths = [
			self::get_vendor_dir() . '/bin',
			self::get_framework_dir() . '/bin',
		];

		foreach ( $bin_paths as $path ) {
			if ( is_file( "{$path}/wp" ) && is_executable( "{$path}/wp" ) ) {
				$bin_path = $path;
				break;
			}
		}

		return $bin_path;
	}

	/**
	 * Get the environment variables required for launched `wp` processes
	 */
	private static function get_process_env_variables() {
		static $env = null;

		if ( null !== $env ) {
			return $env;
		}

		// Ensure we're using the expected `wp` binary.
		$bin_path = self::get_bin_path();
		wp_cli_behat_env_debug( "WP-CLI binary path: {$bin_path}" );

		if ( ! file_exists( "{$bin_path}/wp" ) ) {
			wp_cli_behat_env_debug( "WARNING: No file named 'wp' found in the provided/detected binary path." );
		}

		if ( ! is_executable( "{$bin_path}/wp" ) ) {
			wp_cli_behat_env_debug( "WARNING: File named 'wp' found in the provided/detected binary path is not executable." );
		}

		$path_separator = Utils\is_windows() ? ';' : ':';
		$env            = [
			'PATH'      => $bin_path . $path_separator . getenv( 'PATH' ),
			'BEHAT_RUN' => 1,
			'HOME'      => sys_get_temp_dir() . '/wp-cli-home',
		];

		$config_path = getenv( 'WP_CLI_CONFIG_PATH' );
		if ( false !== $config_path ) {
			$env['WP_CLI_CONFIG_PATH'] = $config_path;
		}

		$allow_root = getenv( 'WP_CLI_ALLOW_ROOT' );
		if ( false !== $allow_root ) {
			$env['WP_CLI_ALLOW_ROOT'] = $allow_root;
		}

		$term = getenv( 'TERM' );
		if ( false !== $term ) {
			$env['TERM'] = $term;
		}

		$php_args = getenv( 'WP_CLI_PHP_ARGS' );
		if ( false !== $php_args ) {
			$env['WP_CLI_PHP_ARGS'] = $php_args;
		}

		$php_used = getenv( 'WP_CLI_PHP_USED' );
		if ( false !== $php_used ) {
			$env['WP_CLI_PHP_USED'] = $php_used;
		}

		$php = getenv( 'WP_CLI_PHP' );
		if ( false !== $php ) {
			$env['WP_CLI_PHP'] = $php;
		}

		$travis_build_dir = getenv( 'TRAVIS_BUILD_DIR' );
		if ( false !== $travis_build_dir ) {
			$env['TRAVIS_BUILD_DIR'] = $travis_build_dir;
		}

		// Dump environment for debugging purposes, but before adding the GitHub token.
		wp_cli_behat_env_debug( 'Environment:' );
		foreach ( $env as $key => $value ) {
			wp_cli_behat_env_debug( "   [{$key}] => {$value}" );
		}

		$github_token = getenv( 'GITHUB_TOKEN' );
		if ( false !== $github_token ) {
			$env['GITHUB_TOKEN'] = $github_token;
		}

		return $env;
	}

	/**
	 * Get the internal variables to use within tests.
	 *
	 * @return array Associative array of internal variables that will be mapped
	 *               into tests.
	 */
	private static function get_behat_internal_variables() {
		static $variables = null;

		if ( null !== $variables ) {
			return $variables;
		}

		$paths = [
			dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-cli/wp-cli/VERSION',
			dirname( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) ) . '/VERSION',
			dirname( dirname( __DIR__ ) ) . '/vendor/wp-cli/wp-cli/VERSION',
		];

		$framework_root = dirname( dirname( __DIR__ ) );
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				$framework_root = (string) realpath( dirname( $path ) );
				break;
			}
		}

		$variables = [
			'FRAMEWORK_ROOT' => realpath( $framework_root ),
			'SRC_DIR'        => realpath( dirname( dirname( __DIR__ ) ) ),
			'PROJECT_DIR'    => realpath( dirname( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) ) ),
		];

		return $variables;
	}

	/**
	* Download and extract a single copy of the sqlite-database-integration plugin
	* for use in subsequent WordPress copies
	*/
	private static function download_sqlite_plugin( $dir ) {
		$download_url      = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip';
		$download_location = $dir . '/sqlite-database-integration.zip';

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}

		Process::create(
			Utils\esc_cmd(
				'curl -sSfL %1$s > %2$s',
				$download_url,
				$download_location
			)
		)->run_check();

		$zip          = new \ZipArchive();
		$new_zip_file = $download_location;

		if ( $zip->open( $new_zip_file ) === true ) {
			if ( $zip->extractTo( $dir ) ) {
				$zip->close();
				unlink( $new_zip_file );
			} else {
				$error_message = $zip->getStatusString();
				throw new RuntimeException( sprintf( 'Failed to extract files from the zip: %s', $error_message ) );
			}
		} else {
			$error_message = $zip->getStatusString();
			throw new RuntimeException( sprintf( 'Failed to open the zip file: %s', $error_message ) );
		}
	}

	/**
	* Given a WordPress installation with the sqlite-database-integration plugin,
	* configure it to use SQLite as the database by placing the db.php dropin file
	*/
	private static function configure_sqlite( $dir ) {
		$db_copy   = $dir . '/wp-content/plugins/sqlite-database-integration/db.copy';
		$db_dropin = $dir . '/wp-content/db.php';

		/* similar to https://github.com/WordPress/sqlite-database-integration/blob/3306576c9b606bc23bbb26c15383fef08e03ab11/activate.php#L95 */
		$file_contents = str_replace(
			array(
				'\'{SQLITE_IMPLEMENTATION_FOLDER_PATH}\'',
				'{SQLITE_PLUGIN}',
			),
			array(
				'__DIR__ . \'plugins/sqlite-database-integration\'',
				'sqlite-database-integration/load.php',
			),
			file_get_contents( $db_copy )
		);

		file_put_contents( $db_dropin, $file_contents );
	}

	/**
	 * We cache the results of `wp core download` to improve test performance.
	 * Ideally, we'd cache at the HTTP layer for more reliable tests.
	 */
	private static function cache_wp_files() {
		$wp_version             = getenv( 'WP_VERSION' );
		$wp_version_suffix      = ( false !== $wp_version ) ? "-$wp_version" : '';
		self::$cache_dir        = sys_get_temp_dir() . '/wp-cli-test-core-download-cache' . $wp_version_suffix;
		self::$sqlite_cache_dir = sys_get_temp_dir() . '/wp-cli-test-sqlite-integration-cache';

		if ( 'sqlite' === getenv( 'DB_TYPE' ) ) {
			if ( ! is_readable( self::$sqlite_cache_dir . '/sqlite-database-integration/db.copy' ) ) {
				self::download_sqlite_plugin( self::$sqlite_cache_dir );
			}
		}

		if ( is_readable( self::$cache_dir . '/wp-config-sample.php' ) ) {
			return;
		}

		$cmd = Utils\esc_cmd( 'wp core download --force --path=%s', self::$cache_dir );
		if ( $wp_version ) {
			$cmd .= Utils\esc_cmd( ' --version=%s', $wp_version );
		}
		Process::create( $cmd, null, self::get_process_env_variables() )->run_check();
	}

	/**
	 * @BeforeSuite
	 */
	public static function prepare( BeforeSuiteScope $scope ) {
		// Test performance statistics - useful for detecting slow tests.
		self::$log_run_times = getenv( 'WP_CLI_TEST_LOG_RUN_TIMES' );
		if ( false !== self::$log_run_times ) {
			self::log_run_times_before_suite( $scope );
		}

		$result = Process::create( 'wp cli info', null, self::get_process_env_variables() )->run_check();
		echo "{$result->stdout}\n";

		self::cache_wp_files();

		$result = Process::create( Utils\esc_cmd( 'wp core version --debug --path=%s', self::$cache_dir ), null, self::get_process_env_variables() )->run_check();
		echo "[Debug messages]\n";
		echo "{$result->stderr}\n";

		echo "WordPress {$result->stdout}\n";

		// Remove install cache if any (not setting the static var).
		$wp_version        = getenv( 'WP_VERSION' );
		$wp_version_suffix = ( false !== $wp_version ) ? "-$wp_version" : '';
		$install_cache_dir = sys_get_temp_dir() . '/wp-cli-test-core-install-cache' . $wp_version_suffix;
		if ( file_exists( $install_cache_dir ) ) {
			self::remove_dir( $install_cache_dir );
		}

		if ( getenv( 'WP_CLI_TEST_DEBUG_BEHAT_ENV' ) ) {
			exit;
		}
	}

	/**
	 * @AfterSuite
	 */
	public static function afterSuite( AfterSuiteScope $scope ) {
		if ( self::$composer_local_repository ) {
			self::remove_dir( self::$composer_local_repository );
			self::$composer_local_repository = null;
		}

		if ( self::$log_run_times ) {
			self::log_run_times_after_suite( $scope );
		}
	}

	/**
	 * @BeforeScenario
	 */
	public function beforeScenario( BeforeScenarioScope $scope ) {
		if ( self::$log_run_times ) {
			self::log_run_times_before_scenario( $scope );
		}

		$this->variables = array_merge(
			$this->variables,
			self::get_behat_internal_variables()
		);

		// Used in the names of the RUN_DIR and SUITE_CACHE_DIR directories.
		self::$temp_dir_infix = null;
		$file                 = self::get_event_file( $scope, $line );
		if ( isset( $file ) ) {
			self::$temp_dir_infix = basename( $file ) . '.' . $line;
		}
	}

	/**
	 * @AfterScenario
	 */
	public function afterScenario( AfterScenarioScope $scope ) {

		if ( self::$run_dir ) {
			// Remove altered WP install, unless there's an error.
			if ( $scope->getTestResult()->getResultCode() <= 10 ) {
				self::remove_dir( self::$run_dir );
			}
			self::$run_dir = null;
		}

		// Remove WP-CLI package directory if any. Set to `wp package path` by package-command and scaffold-package-command features, and by cli-info.feature.
		if ( isset( $this->variables['PACKAGE_PATH'] ) ) {
			self::remove_dir( $this->variables['PACKAGE_PATH'] );
		}

		// Remove SUITE_CACHE_DIR if any.
		if ( self::$suite_cache_dir ) {
			self::remove_dir( self::$suite_cache_dir );
			self::$suite_cache_dir = null;
		}

		// Remove global config file if any.
		$env = self::get_process_env_variables();
		if ( isset( $env['HOME'] ) && file_exists( "{$env['HOME']}/.wp-cli/config.yml" ) ) {
			unlink( "{$env['HOME']}/.wp-cli/config.yml" );
		}

		// Remove any background processes.
		foreach ( $this->running_procs as $proc ) {
			$status = proc_get_status( $proc );
			self::terminate_proc( $status['pid'] );
		}

		if ( self::$log_run_times ) {
			self::log_run_times_after_scenario( $scope );
		}
	}

	/**
	 * Terminate a process and any of its children.
	 */
	private static function terminate_proc( $master_pid ) {

		$output = `ps -o ppid,pid,command | grep $master_pid`;

		foreach ( explode( PHP_EOL, $output ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\s+(\d+)/', $line, $matches ) ) {
				$parent = $matches[1];
				$child  = $matches[2];

				if ( (int) $parent === (int) $master_pid ) {
					self::terminate_proc( $child );
				}
			}
		}

		if ( ! posix_kill( (int) $master_pid, 9 ) ) {
			$errno = posix_get_last_error();
			// Ignore "No such process" error as that's what we want.
			if ( 3 /*ESRCH*/ !== $errno ) {
				throw new RuntimeException( posix_strerror( $errno ) );
			}
		}
	}

	/**
	 * Create a temporary WP_CLI_CACHE_DIR. Exposed as SUITE_CACHE_DIR in "Given an empty cache" step.
	 */
	public static function create_cache_dir() {
		if ( self::$suite_cache_dir ) {
			self::remove_dir( self::$suite_cache_dir );
		}
		self::$suite_cache_dir = sys_get_temp_dir() . '/' . uniqid( 'wp-cli-test-suite-cache-' . self::$temp_dir_infix . '-', true );
		mkdir( self::$suite_cache_dir );
		return self::$suite_cache_dir;
	}

	/**
	 * Initializes context.
	 * Every scenario gets its own context object.
	 */
	public function __construct() {
		if ( getenv( 'WP_CLI_TEST_DBROOTUSER' ) ) {
			$this->variables['DB_ROOT_USER'] = getenv( 'WP_CLI_TEST_DBROOTUSER' );
		}

		if ( false !== getenv( 'WP_CLI_TEST_DBROOTPASS' ) ) {
			$this->variables['DB_ROOT_PASSWORD'] = getenv( 'WP_CLI_TEST_DBROOTPASS' );
		}

		if ( getenv( 'WP_CLI_TEST_DBNAME' ) ) {
			$this->variables['DB_NAME'] = getenv( 'WP_CLI_TEST_DBNAME' );
		} else {
			$this->variables['DB_NAME'] = 'wp_cli_test';
		}

		if ( getenv( 'WP_CLI_TEST_DBUSER' ) ) {
			$this->variables['DB_USER'] = getenv( 'WP_CLI_TEST_DBUSER' );
		} else {
			$this->variables['DB_USER'] = 'wp_cli_test';
		}

		if ( false !== getenv( 'WP_CLI_TEST_DBPASS' ) ) {
			$this->variables['DB_PASSWORD'] = getenv( 'WP_CLI_TEST_DBPASS' );
		} else {
			$this->variables['DB_PASSWORD'] = 'password1';
		}

		if ( getenv( 'WP_CLI_TEST_DBHOST' ) ) {
			$this->variables['DB_HOST'] = getenv( 'WP_CLI_TEST_DBHOST' );
		} else {
			$this->variables['DB_HOST'] = 'localhost';
		}

		if ( getenv( 'MYSQL_TCP_PORT' ) ) {
			$this->variables['MYSQL_PORT'] = getenv( 'MYSQL_TCP_PORT' );
		}

		if ( getenv( 'MYSQL_HOST' ) ) {
			$this->variables['MYSQL_HOST'] = getenv( 'MYSQL_HOST' );
		}

		if ( 'sqlite' === getenv( 'DB_TYPE' ) ) {
			self::$db_type = 'sqlite';
		}

		self::$db_settings['dbname'] = $this->variables['DB_NAME'];
		self::$db_settings['dbuser'] = $this->variables['DB_USER'];
		self::$db_settings['dbpass'] = $this->variables['DB_PASSWORD'];
		self::$db_settings['dbhost'] = $this->variables['DB_HOST'];

		$this->variables['CORE_CONFIG_SETTINGS'] = Utils\assoc_args_to_str( self::$db_settings );

		$this->drop_db();
		$this->set_cache_dir();
	}

	/**
	 * Replace standard {VARIABLE_NAME} variables and the special {INVOKE_WP_CLI_WITH_PHP_ARGS-args} and {WP_VERSION-version-latest} variables.
	 * Note that standard variable names can only contain uppercase letters, digits and underscores and cannot begin with a digit.
	 */
	public function replace_variables( $str ) {
		if ( false !== strpos( $str, '{INVOKE_WP_CLI_WITH_PHP_ARGS-' ) ) {
			$str = $this->replace_invoke_wp_cli_with_php_args( $str );
		}
		$str = preg_replace_callback( '/\{([A-Z_][A-Z_0-9]*)\}/', [ $this, 'replace_var' ], $str );
		if ( false !== strpos( $str, '{WP_VERSION-' ) ) {
			$str = $this->replace_wp_versions( $str );
		}
		return $str;
	}

	/**
	 * Substitute {INVOKE_WP_CLI_WITH_PHP_ARGS-args} variables.
	 */
	private function replace_invoke_wp_cli_with_php_args( $str ) {
		static $phar_path = null, $shell_path = null;

		if ( null === $phar_path ) {
			$phar_path      = false;
			$phar_begin     = '#!/usr/bin/env php';
			$phar_begin_len = strlen( $phar_begin );
			$bin_dir        = getenv( 'WP_CLI_BIN_DIR' );
			if ( false !== $bin_dir && file_exists( $bin_dir . '/wp' ) && file_get_contents( $bin_dir . '/wp', false, null, 0, $phar_begin_len ) === $phar_begin ) {
				$phar_path = $bin_dir . '/wp';
			} else {
				$src_dir         = dirname( dirname( __DIR__ ) );
				$bin_path        = $src_dir . '/bin/wp';
				$vendor_bin_path = $src_dir . '/vendor/bin/wp';
				if ( file_exists( $bin_path ) && is_executable( $bin_path ) ) {
					$shell_path = $bin_path;
				} elseif ( file_exists( $vendor_bin_path ) && is_executable( $vendor_bin_path ) ) {
					$shell_path = $vendor_bin_path;
				} else {
					$shell_path = 'wp';
				}
			}
		}

		$str = preg_replace_callback(
			'/{INVOKE_WP_CLI_WITH_PHP_ARGS-([^}]*)}/',
			function ( $matches ) use ( $phar_path, $shell_path ) {
				return $phar_path ? "php {$matches[1]} {$phar_path}" : ( 'WP_CLI_PHP_ARGS=' . escapeshellarg( $matches[1] ) . ' ' . $shell_path );
			},
			$str
		);

		return $str;
	}

	/**
	 * Replace variables callback.
	 */
	private function replace_var( $matches ) {
		$cmd = $matches[0];

		foreach ( array_slice( $matches, 1 ) as $key ) {
			$cmd = str_replace( '{' . $key . '}', $this->variables[ $key ], $cmd );
		}

		return $cmd;
	}

	/**
	 * Substitute {WP_VERSION-version-latest} variables.
	 */
	private function replace_wp_versions( $str ) {
		static $wp_versions = null;
		if ( null === $wp_versions ) {
			$wp_versions = [];

			$response = Utils\http_request( 'GET', 'https://api.wordpress.org/core/version-check/1.7/', null, [], [ 'timeout' => 30 ] );
			if ( 200 === $response->status_code ) {
				$body = json_decode( $response->body );
				if ( is_object( $body ) && isset( $body->offers ) && is_array( $body->offers ) ) {
					// Latest version alias.
					$wp_versions['{WP_VERSION-latest}'] = count( $body->offers ) ? $body->offers[0]->version : '';
					foreach ( $body->offers as $offer ) {
						$sub_ver     = preg_replace( '/(^[0-9]+\.[0-9]+)\.[0-9]+$/', '$1', $offer->version );
						$sub_ver_key = "{WP_VERSION-{$sub_ver}-latest}";

						$main_ver     = preg_replace( '/(^[0-9]+)\.[0-9]+$/', '$1', $sub_ver );
						$main_ver_key = "{WP_VERSION-{$main_ver}-latest}";

						if ( ! isset( $wp_versions[ $main_ver_key ] ) ) {
							$wp_versions[ $main_ver_key ] = $offer->version;
						}
						if ( ! isset( $wp_versions[ $sub_ver_key ] ) ) {
							$wp_versions[ $sub_ver_key ] = $offer->version;
						}
					}
				}
			}
		}
		return strtr( $str, $wp_versions );
	}

	/**
	 * Get the file and line number for the current behat scope.
	 */
	private static function get_event_file( $scope, &$line ) {
		if ( method_exists( $scope, 'getScenario' ) ) {
			$scenario_feature = $scope->getScenario();
		} elseif ( method_exists( $scope, 'getFeature' ) ) {
			$scenario_feature = $scope->getFeature();
		} elseif ( method_exists( $scope, 'getOutline' ) ) {
			$scenario_feature = $scope->getOutline();
		} else {
			return null;
		}

		$line = $scenario_feature->getLine();

		if ( ! method_exists( $scenario_feature, 'getFile' ) ) {
			return null;
		}

		return $scenario_feature->getFile();
	}

	/**
	 * Create the RUN_DIR directory, unless already set for this scenario.
	 */
	public function create_run_dir() {
		if ( ! isset( $this->variables['RUN_DIR'] ) ) {
			self::$run_dir              = sys_get_temp_dir() . '/' . uniqid( 'wp-cli-test-run-' . self::$temp_dir_infix . '-', true );
			$this->variables['RUN_DIR'] = self::$run_dir;
			mkdir( $this->variables['RUN_DIR'] );
		}
	}

	public function build_phar( $version = 'same' ) {
		$this->variables['PHAR_PATH'] = $this->variables['RUN_DIR'] . '/' . uniqid( 'wp-cli-build-', true ) . '.phar';

		// Test running against a package installed as a WP-CLI dependency
		// WP-CLI bundle installed as a project dependency
		$make_phar_path = self::get_vendor_dir() . '/wp-cli/wp-cli-bundle/utils/make-phar.php';
		if ( ! file_exists( $make_phar_path ) ) {
			// Running against WP-CLI bundle proper
			$make_phar_path = self::get_vendor_dir() . '/../utils/make-phar.php';
		}

		$this->proc(
			Utils\esc_cmd(
				'php -dphar.readonly=0 %1$s %2$s --version=%3$s && chmod +x %2$s',
				$make_phar_path,
				$this->variables['PHAR_PATH'],
				$version
			)
		)->run_check();
	}

	public function download_phar( $version = 'same' ) {
		if ( 'same' === $version ) {
			$version = WP_CLI_VERSION;
		}

		$download_url = sprintf(
			'https://github.com/wp-cli/wp-cli/releases/download/v%1$s/wp-cli-%1$s.phar',
			$version
		);

		$this->variables['PHAR_PATH'] = $this->variables['RUN_DIR'] . '/'
										. uniqid( 'wp-cli-download-', true )
										. '.phar';

		Process::create(
			Utils\esc_cmd(
				'curl -sSfL %1$s > %2$s && chmod +x %2$s',
				$download_url,
				$this->variables['PHAR_PATH']
			)
		)->run_check();
	}

	/**
	 * CACHE_DIR is a cache for downloaded test data such as images. Lives until manually deleted.
	 */
	private function set_cache_dir() {
		$path = sys_get_temp_dir() . '/wp-cli-test-cache';
		if ( ! file_exists( $path ) ) {
			mkdir( $path );
		}
		$this->variables['CACHE_DIR'] = $path;
	}

	/**
	 * Run a MySQL command with `$db_settings`.
	 *
	 * @param string $sql_cmd Command to run.
	 * @param array $assoc_args Optional. Associative array of options. Default empty.
	 * @param bool $add_database Optional. Whether to add dbname to the $sql_cmd. Default false.
	 */
	private static function run_sql( $sql_cmd, $assoc_args = [], $add_database = false ) {
		$default_assoc_args = [
			'host' => self::$db_settings['dbhost'],
			'user' => self::$db_settings['dbuser'],
			'pass' => self::$db_settings['dbpass'],
		];
		if ( $add_database ) {
			$sql_cmd .= ' ' . escapeshellarg( self::$db_settings['dbname'] );
		}
		$start_time = microtime( true );
		Utils\run_mysql_command( $sql_cmd, array_merge( $assoc_args, $default_assoc_args ) );
		if ( self::$log_run_times ) {
			self::log_proc_method_run_time( 'run_sql ' . $sql_cmd, $start_time );
		}
	}

	public function create_db() {
		if ( 'sqlite' === self::$db_type ) {
			return;
		}

		$dbname = self::$db_settings['dbname'];
		self::run_sql( 'mysql --no-defaults', [ 'execute' => "CREATE DATABASE IF NOT EXISTS $dbname" ] );
	}

	public function drop_db() {
		if ( 'sqlite' === self::$db_type ) {
			return;
		}
		$dbname = self::$db_settings['dbname'];
		self::run_sql( 'mysql --no-defaults', [ 'execute' => "DROP DATABASE IF EXISTS $dbname" ] );
	}

	public function proc( $command, $assoc_args = [], $path = '' ) {
		if ( ! empty( $assoc_args ) ) {
			$command .= Utils\assoc_args_to_str( $assoc_args );
		}

		$env = self::get_process_env_variables();
		if ( isset( $this->variables['SUITE_CACHE_DIR'] ) ) {
			$env['WP_CLI_CACHE_DIR'] = $this->variables['SUITE_CACHE_DIR'];
		}

		if ( isset( $this->variables['RUN_DIR'] ) ) {
			$cwd = "{$this->variables['RUN_DIR']}/{$path}";
		} else {
			$cwd = null;
		}

		return Process::create( $command, $cwd, $env );
	}

	/**
	 * Start a background process. Will automatically be closed when the tests finish.
	 */
	public function background_proc( $cmd ) {
		$descriptors = [
			0 => STDIN,
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$proc = proc_open( $cmd, $descriptors, $pipes, $this->variables['RUN_DIR'], self::get_process_env_variables() );

		sleep( 1 );

		$status = proc_get_status( $proc );

		if ( ! $status['running'] ) {
			$stderr = is_resource( $pipes[2] ) ? ( ': ' . stream_get_contents( $pipes[2] ) ) : '';
			throw new RuntimeException( sprintf( "Failed to start background process '%s'%s.", $cmd, $stderr ) );
		} else {
			$this->running_procs[] = $proc;
		}
	}

	public function move_files( $src, $dest ) {
		rename( $this->variables['RUN_DIR'] . "/$src", $this->variables['RUN_DIR'] . "/$dest" );
	}

	/**
	 * Remove a directory (recursive).
	 */
	public static function remove_dir( $dir ) {
		Process::create( Utils\esc_cmd( 'rm -rf %s', $dir ) )->run_check();
	}

	/**
	 * Copy a directory (recursive). Destination directory must exist.
	 */
	public static function copy_dir( $src_dir, $dest_dir ) {
		Process::create( Utils\esc_cmd( 'cp -r %s/* %s', $src_dir, $dest_dir ) )->run_check();
	}

	public function add_line_to_wp_config( &$wp_config_code, $line ) {
		$token = "/* That's all, stop editing!";

		$wp_config_code = str_replace( $token, "$line\n\n$token", $wp_config_code );
	}

	public function download_wp( $subdir = '' ) {
		$dest_dir = $this->variables['RUN_DIR'] . "/$subdir";

		if ( $subdir ) {
			mkdir( $dest_dir );
		}

		self::copy_dir( self::$cache_dir, $dest_dir );

		if ( ! is_dir( $dest_dir . '/wp-content/mu-plugins' ) ) {
			mkdir( $dest_dir . '/wp-content/mu-plugins' );
		}

		// Disable emailing.
		copy( dirname( dirname( __DIR__ ) ) . '/utils/no-mail.php', $dest_dir . '/wp-content/mu-plugins/no-mail.php' );

		// Add polyfills.
		copy( dirname( dirname( __DIR__ ) ) . '/utils/polyfills.php', $dest_dir . '/wp-content/mu-plugins/polyfills.php' );

		if ( 'sqlite' === self::$db_type ) {
			self::copy_dir( self::$sqlite_cache_dir, $dest_dir . '/wp-content/plugins' );
			self::configure_sqlite( $dest_dir );

		}
	}

	public function create_config( $subdir = '', $extra_php = false ) {
		$params = self::$db_settings;

		// Replaces all characters that are not alphanumeric or an underscore into an underscore.
		$params['dbprefix'] = $subdir ? preg_replace( '#[^a-zA-Z\_0-9]#', '_', $subdir ) : 'wp_';

		$params['skip-salts'] = true;

		if ( false !== $extra_php ) {
			$params['extra-php'] = $extra_php;
		}

		$run_dir           = '' !== $subdir ? ( $this->variables['RUN_DIR'] . "/$subdir" ) : $this->variables['RUN_DIR'];
		$config_cache_path = '';
		if ( self::$install_cache_dir ) {
			$config_cache_path = self::$install_cache_dir . '/config_' . md5( implode( ':', $params ) . ':subdir=' . $subdir );
		}

		if ( $config_cache_path && file_exists( $config_cache_path ) ) {
			copy( $config_cache_path, $run_dir . '/wp-config.php' );
		} else {
			$this->proc( 'wp config create', $params, $subdir )->run_check();
			if ( $config_cache_path && file_exists( $run_dir . '/wp-config.php' ) ) {
				copy( $run_dir . '/wp-config.php', $config_cache_path );
			}
		}
	}

	public function install_wp( $subdir = '' ) {
		$wp_version              = getenv( 'WP_VERSION' );
		$wp_version_suffix       = ( false !== $wp_version ) ? "-$wp_version" : '';
		self::$install_cache_dir = sys_get_temp_dir() . '/wp-cli-test-core-install-cache' . $wp_version_suffix;
		if ( ! file_exists( self::$install_cache_dir ) ) {
			mkdir( self::$install_cache_dir );
		}

		$subdir = $this->replace_variables( $subdir );

		// Disable WP Cron by default to avoid bogus HTTP requests in CLI context.
		$config_extra_php = "if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }\n";

		if ( 'mysql' === self::$db_type ) {
			$this->create_db();
		}
		$this->create_run_dir();
		$this->download_wp( $subdir );
		$this->create_config( $subdir, $config_extra_php );

		$install_args = [
			'url'            => 'https://example.com',
			'title'          => 'WP CLI Site',
			'admin_user'     => 'admin',
			'admin_email'    => 'admin@example.com',
			'admin_password' => 'password1',
			'skip-email'     => true,
		];

		$install_cache_path = '';
		if ( self::$install_cache_dir ) {
			$install_cache_path = self::$install_cache_dir . '/install_' . md5( implode( ':', $install_args ) . ':subdir=' . $subdir );
			$run_dir            = '' !== $subdir ? ( $this->variables['RUN_DIR'] . "/$subdir" ) : $this->variables['RUN_DIR'];
		}

		if ( $install_cache_path && file_exists( $install_cache_path ) ) {
			self::copy_dir( $install_cache_path, $run_dir );

			// This is the sqlite equivalent of restoring a database dump in MySQL
			if ( 'sqlite' === self::$db_type ) {
				copy( "{$install_cache_path}.sqlite", "$run_dir/wp-content/database/.ht.sqlite" );
			} else {
				self::run_sql( 'mysql --no-defaults', [ 'execute' => "source {$install_cache_path}.sql" ], true /*add_database*/ );
			}
		} else {
			$this->proc( 'wp core install', $install_args, $subdir )->run_check();
			if ( $install_cache_path ) {
				mkdir( $install_cache_path );
				self::dir_diff_copy( $run_dir, self::$cache_dir, $install_cache_path );

				if ( 'mysql' === self::$db_type ) {
					$mysqldump_binary          = Utils\force_env_on_nix_systems( 'mysqldump' );
					$support_column_statistics = exec( "{$mysqldump_binary} --help | grep 'column-statistics'" );
					$command                   = "{$mysqldump_binary} --no-defaults --no-tablespaces";
					if ( $support_column_statistics ) {
						$command .= ' --skip-column-statistics';
					}
					self::run_sql( $command, [ 'result-file' => "{$install_cache_path}.sql" ], true /*add_database*/ );
				}

				if ( 'sqlite' === self::$db_type ) {
					// This is the sqlite equivalent of creating a database dump in MySQL
					copy( "$run_dir/wp-content/database/.ht.sqlite", "{$install_cache_path}.sqlite" );
				}
			}
		}
	}

	public function install_wp_with_composer( $vendor_directory = 'vendor' ) {
		$this->create_run_dir();
		$this->create_db();

		$yml_path = $this->variables['RUN_DIR'] . '/wp-cli.yml';
		file_put_contents( $yml_path, 'path: WordPress' );

		$this->composer_command( 'init --name="wp-cli/composer-test" --type="project"' );
		$this->composer_command( 'config vendor-dir ' . $vendor_directory );
		$this->composer_command( 'config extra.wordpress-install-dir WordPress' );

		// Allow for all Composer plugins to run to avoid warnings.
		$this->composer_command( 'config --no-plugins allow-plugins true' );
		$this->composer_command( 'require johnpbloch/wordpress-core-installer johnpbloch/wordpress-core --optimize-autoloader' );

		// Disable WP Cron by default to avoid bogus HTTP requests in CLI context.
		$config_extra_php = "if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }\n";

		$config_extra_php .= "require_once dirname(__DIR__) . '/" . $vendor_directory . "/autoload.php';\n";

		$this->create_config( 'WordPress', $config_extra_php );

		$install_args = [
			'url'            => 'http://localhost:8080',
			'title'          => 'WP CLI Site with both WordPress and wp-cli as Composer dependencies',
			'admin_user'     => 'admin',
			'admin_email'    => 'admin@example.com',
			'admin_password' => 'password1',
			'skip-email'     => true,
		];

		if ( 'sqlite' === self::$db_type ) {
			self::copy_dir( self::$sqlite_cache_dir, $this->variables['RUN_DIR'] . '/WordPress/wp-content/plugins' );
			self::configure_sqlite( $this->variables['RUN_DIR'] . '/WordPress' );
		}

		$this->proc( 'wp core install', $install_args )->run_check();
	}

	public function composer_add_wp_cli_local_repository() {
		if ( ! self::$composer_local_repository ) {
			self::$composer_local_repository = sys_get_temp_dir() . '/' . uniqid( 'wp-cli-composer-local-', true );
			mkdir( self::$composer_local_repository );

			$env = self::get_process_env_variables();
			$src = isset( $env['TRAVIS_BUILD_DIR'] )
				? $env['TRAVIS_BUILD_DIR']
				: realpath( self::get_vendor_dir() . '/../' );

			self::copy_dir( $src, self::$composer_local_repository . '/' );
			self::remove_dir( self::$composer_local_repository . '/.git' );
			self::remove_dir( self::$composer_local_repository . '/vendor' );
		}
		$dest = self::$composer_local_repository . '/';
		$this->composer_command( "config repositories.wp-cli '{\"type\": \"path\", \"url\": \"$dest\", \"options\": {\"symlink\": false, \"versions\": { \"wp-cli/wp-cli\": \"dev-main\"}}}'" );
		$this->variables['COMPOSER_LOCAL_REPOSITORY'] = self::$composer_local_repository;
	}

	public function composer_require_current_wp_cli() {
		$this->composer_add_wp_cli_local_repository();
		// TODO: Specific alias version should be deduced to keep up-to-date.
		$this->composer_command( 'require "wp-cli/wp-cli:dev-main as 2.5.x-dev" --optimize-autoloader' );
	}

	public function start_php_server( $subdir = '' ) {
		$dir = $this->variables['RUN_DIR'] . '/';
		if ( $subdir ) {
			$dir .= trim( $subdir, '/' ) . '/';
		}
		$cmd = Utils\esc_cmd(
			'%s -S %s -t %s -c %s %s',
			Utils\get_php_binary(),
			'localhost:8080',
			$dir,
			get_cfg_var( 'cfg_file_path' ),
			$this->variables['RUN_DIR'] . '/vendor/wp-cli/server-command/router.php'
		);
		$this->background_proc( $cmd );
	}

	private function composer_command( $cmd ) {
		if ( ! isset( $this->variables['COMPOSER_PATH'] ) ) {
			$this->variables['COMPOSER_PATH'] = exec( 'which composer' );
		}
		$this->proc( $this->variables['COMPOSER_PATH'] . ' --no-interaction ' . $cmd )->run_check();
	}

	/**
	 * Initialize run time logging.
	 */
	private static function log_run_times_before_suite( BeforeSuiteScope $scope ) {
		self::$suite_start_time = microtime( true );

		Process::$log_run_times = true;

		$travis = getenv( 'TRAVIS' );

		// Default output settings.
		self::$output_to         = 'stdout';
		self::$num_top_processes = $travis ? 10 : 40;
		self::$num_top_scenarios = $travis ? 10 : 20;

		// Allow setting of above with "WP_CLI_TEST_LOG_RUN_TIMES=<output_to>[,<num_top_processes>][,<num_top_scenarios>]" formatted env var.
		if ( preg_match( '/^(stdout|error_log)?(,[0-9]+)?(,[0-9]+)?$/i', self::$log_run_times, $matches ) ) {
			if ( isset( $matches[1] ) ) {
				self::$output_to = strtolower( $matches[1] );
			}
			if ( isset( $matches[2] ) ) {
				self::$num_top_processes = max( (int) substr( $matches[2], 1 ), 1 );
			}
			if ( isset( $matches[3] ) ) {
				self::$num_top_scenarios = max( (int) substr( $matches[3], 1 ), 1 );
			}
		}
	}

	/**
	 * Record the start time of the scenario into the `$scenario_run_times` array.
	 */
	private static function log_run_times_before_scenario( $scope ) {
		$scenario_key = self::get_scenario_key( $scope );
		if ( $scenario_key ) {
			self::$scenario_run_times[ $scenario_key ] = -microtime( true );
		}
	}

	/**
	 * Save the run time of the scenario into the `$scenario_run_times` array. Only the top `self::$num_top_scenarios` are kept.
	 */
	private static function log_run_times_after_scenario( $scope ) {
		$scenario_key = self::get_scenario_key( $scope );
		if ( $scenario_key ) {
			self::$scenario_run_times[ $scenario_key ] += microtime( true );
			++self::$scenario_count;
			if ( count( self::$scenario_run_times ) > self::$num_top_scenarios ) {
				arsort( self::$scenario_run_times );
				array_pop( self::$scenario_run_times );
			}
		}
	}

	/**
	 * Copy files in updated directory that are not in source directory to copy directory. ("Incremental backup".)
	 * Note: does not deal with changed files (ie does not compare file contents for changes), for speed reasons.
	 *
	 * @param string $upd_dir The directory to search looking for files/directories not in `$src_dir`.
	 * @param string $src_dir The directory to be compared to `$upd_dir`.
	 * @param string $cop_dir Where to copy any files/directories in `$upd_dir` but not in `$src_dir` to.
	 */
	private static function dir_diff_copy( $upd_dir, $src_dir, $cop_dir ) {
		$files = scandir( $upd_dir );
		if ( false === $files ) {
			$error = error_get_last();
			throw new RuntimeException( sprintf( "Failed to open updated directory '%s': %s. " . __FILE__ . ':' . __LINE__, $upd_dir, $error['message'] ) );
		}
		foreach ( array_diff( $files, [ '.', '..' ] ) as $file ) {
			$upd_file = $upd_dir . '/' . $file;
			$src_file = $src_dir . '/' . $file;
			$cop_file = $cop_dir . '/' . $file;
			if ( ! file_exists( $src_file ) ) {
				if ( is_dir( $upd_file ) ) {
					if ( ! file_exists( $cop_file ) && ! mkdir( $cop_file, 0777, true /*recursive*/ ) ) {
						$error = error_get_last();
						throw new RuntimeException( sprintf( "Failed to create copy directory '%s': %s. " . __FILE__ . ':' . __LINE__, $cop_file, $error['message'] ) );
					}
					self::copy_dir( $upd_file, $cop_file );
				} elseif ( ! copy( $upd_file, $cop_file ) ) {
						$error = error_get_last();
						throw new RuntimeException( sprintf( "Failed to copy '%s' to '%s': %s. " . __FILE__ . ':' . __LINE__, $upd_file, $cop_file, $error['message'] ) );
				}
			} elseif ( is_dir( $upd_file ) ) {
				self::dir_diff_copy( $upd_file, $src_file, $cop_file );
			}
		}
	}

	/**
	 * Get the scenario key used for `$scenario_run_times` array.
	 * Format "<grandparent-dir> <feature-file>:<line-number>", eg "core-command core-update.feature:221".
	 */
	private static function get_scenario_key( $scope ) {
		$scenario_key = '';
		$file         = self::get_event_file( $scope, $line );
		if ( isset( $file ) ) {
			$scenario_grandparent = Utils\basename( dirname( dirname( $file ) ) );
			$scenario_key         = $scenario_grandparent . ' ' . Utils\basename( $file ) . ':' . $line;
		}
		return $scenario_key;
	}

	/**
	 * Print out stats on the run times of processes and scenarios.
	 */
	private static function log_run_times_after_suite( AfterSuiteScope $scope ) {

		$suite = '';
		if ( self::$scenario_run_times ) {
			// Grandparent directory is first part of key.
			$keys  = array_keys( self::$scenario_run_times );
			$suite = substr( $keys[0], 0, strpos( $keys[0], ' ' ) );
		}

		$run_from = Utils\basename( dirname( dirname( __DIR__ ) ) );

		// Format same as Behat, if have minutes.
		$fmt = function ( $time ) {
			$mins = floor( $time / 60 );
			return round( $time, 3 ) . ( $mins ? ( ' (' . $mins . 'm' . round( $time - ( $mins * 60 ), 3 ) . 's)' ) : '' );
		};

		$time = microtime( true ) - self::$suite_start_time;

		$log = PHP_EOL . str_repeat( '(', 80 ) . PHP_EOL;

		// Process and proc method run times.
		$run_times       = array_merge( Process::$run_times, self::$proc_method_run_times );
		$reduce_callback = function ( $carry, $item ) {
			return [ $carry[0] + $item[0], $carry[1] + $item[1] ];
		};

		list( $ptime, $calls ) = array_reduce( $run_times, $reduce_callback, [ 0, 0 ] );

		$overhead = $time - $ptime;
		$pct      = round( ( $overhead / $time ) * 100 );
		$unique   = count( $run_times );

		$log .= sprintf(
			PHP_EOL . "Total process run time %s (tests %s, overhead %.3f %d%%), calls %d (%d unique) for '%s' run from '%s'" . PHP_EOL,
			$fmt( $ptime ),
			$fmt( $time ),
			$overhead,
			$pct,
			$calls,
			$unique,
			$suite,
			$run_from
		);

		$sort_callback = function ( $a, $b ) {
			return $a[0] === $b[0] ? 0 : ( $a[0] < $b[0] ? 1 : -1 ); // Reverse sort.
		};
		uasort( $run_times, $sort_callback );

		$tops = array_slice( $run_times, 0, self::$num_top_processes, true );

		$runtime_callback = function ( $k, $v, $i ) {
			return sprintf( ' %3d. %7.3f %3d %s', $i + 1, round( $v[0], 3 ), $v[1], $k );
		};

		$log .= PHP_EOL . 'Top ' . self::$num_top_processes . " process run times for '$suite'";
		$log .= PHP_EOL . implode(
			PHP_EOL,
			array_map(
				$runtime_callback,
				array_keys( $tops ),
				$tops,
				array_keys( array_keys( $tops ) )
			)
		) . PHP_EOL;

		// Scenario run times.
		arsort( self::$scenario_run_times );

		$tops = array_slice( self::$scenario_run_times, 0, self::$num_top_scenarios, true );

		$scenario_runtime_callback = function ( $k, $v, $i ) {
			return sprintf( ' %3d. %7.3f %s', $i + 1, round( $v, 3 ), substr( $k, strpos( $k, ' ' ) + 1 ) );
		};

		$log .= PHP_EOL . 'Top ' . self::$num_top_scenarios . ' (of ' . self::$scenario_count . ") scenario run times for '$suite'";

		$log .= PHP_EOL . implode(
			PHP_EOL,
			array_map(
				$scenario_runtime_callback,
				array_keys( $tops ),
				$tops,
				array_keys( array_keys( $tops ) )
			)
		) . PHP_EOL;

		$log .= PHP_EOL . str_repeat( ')', 80 );

		if ( 'error_log' === self::$output_to ) {
			error_log( $log );
		} else {
			echo PHP_EOL . $log;
		}
	}

	/**
	 * Log the run time of a proc method (one that doesn't use Process but does (use a function that does) a `proc_open()`).
	 */
	private static function log_proc_method_run_time( $key, $start_time ) {
		$run_time = microtime( true ) - $start_time;
		if ( ! isset( self::$proc_method_run_times[ $key ] ) ) {
			self::$proc_method_run_times[ $key ] = [ 0, 0 ];
		}
		self::$proc_method_run_times[ $key ][0] += $run_time;
		++self::$proc_method_run_times[ $key ][1];
	}
}

// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
function wp_cli_behat_env_debug( $message ) {
	if ( ! getenv( 'WP_CLI_TEST_DEBUG_BEHAT_ENV' ) ) {
		return;
	}

	echo "{$message}\n";
}

/**
 * Load required support files as needed before heading into the Behat context.
 */
function wpcli_bootstrap_behat_feature_context() {
	$vendor_folder = FeatureContext::get_vendor_dir();
	wp_cli_behat_env_debug( "Vendor folder location: {$vendor_folder}" );

	// Didn't manage to detect a valid vendor folder.
	if ( empty( $vendor_folder ) ) {
		return;
	}

	// We assume the vendor folder is located in the project root folder.
	$project_folder = dirname( $vendor_folder );

	$framework_folder = FeatureContext::get_framework_dir();
	wp_cli_behat_env_debug( "Framework folder location: {$framework_folder}" );

	// Didn't manage to detect a valid framework folder.
	if ( empty( $vendor_folder ) ) {
		return;
	}

	// Load helper functionality that is needed for the tests.
	require_once "{$framework_folder}/php/utils.php";
	require_once "{$framework_folder}/php/WP_CLI/Process.php";
	require_once "{$framework_folder}/php/WP_CLI/ProcessRun.php";

	// Manually load Composer file includes by generating a config with require:
	// statements for each file.
	$project_composer = "{$project_folder}/composer.json";
	if ( ! file_exists( $project_composer ) ) {
		return;
	}

	$composer = json_decode( file_get_contents( $project_composer ) );
	if ( empty( $composer->autoload->files ) ) {
		return;
	}

	$contents = "require:\n";
	foreach ( $composer->autoload->files as $file ) {
		$contents .= "  - {$project_folder}/{$file}\n";
	}

	$temp_folder = sys_get_temp_dir() . '/wp-cli-package-test';
	if (
		! is_dir( $temp_folder )
		&& ! mkdir( $temp_folder )
		&& ! is_dir( $temp_folder )
	) {
		return;
	}

	$project_config = "{$temp_folder}/config.yml";
	file_put_contents( $project_config, $contents );
	putenv( 'WP_CLI_CONFIG_PATH=' . $project_config );

	wp_cli_behat_env_debug( "Project config file location: {$project_config}" );
	wp_cli_behat_env_debug( "Project config:\n{$contents}" );
}

wpcli_bootstrap_behat_feature_context();
