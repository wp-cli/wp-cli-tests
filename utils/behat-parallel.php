<?php
/**
 * Run the Behat scenarios of a project in several parallel workers.
 *
 * Used by `run-behat-tests` when WP_CLI_TEST_PARALLEL is set to a number of workers greater than 1.
 *
 * Every worker but the first gets its own temporary directory, and with it its own home directory,
 * caches and run directories, as well as its own test database. The first worker runs with the
 * environment as it is, and takes all scenarios that rely on resources shared across the system,
 * such as fixed ports or fixed paths in the system's temporary directory.
 *
 * Scenarios are distributed by their expected cost, so that the workers finish at about the same time.
 * The scenarios that failed are recorded, so that `rerun-behat-tests` can run exactly those again.
 *
 * Usage: php behat-parallel.php <workers> <behat arguments> -- <arguments passed to run-behat-tests>
 */

use Behat\Gherkin\Filter\TagFilter;
use Behat\Gherkin\Keywords\CachedArrayKeywords;
use Behat\Gherkin\Lexer;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\ScenarioInterface;
use Behat\Gherkin\Node\TableNode;
use Behat\Gherkin\Parser;

/**
 * Scenarios whose steps match this are run by the first worker, one after the other.
 *
 * They use fixed ports, background processes, the scaffold test database or fixed paths in the
 * system's temporary directory, any of which would clash between workers.
 */
const WP_CLI_TESTS_SHARED_RESOURCES = '#localhost:\d+|127\.0\.0\.1:\d+|built-in web server|launch in the background|\bwp server\b|_scaffold\b|install-wp-tests|/tmp/#';

/**
 * Get the file that holds the scenarios which failed in the last parallel run.
 *
 * @return string
 */
function wp_cli_tests_failures_file() {
	return sys_get_temp_dir() . '/wp-cli-test-parallel-failures.scenarios';
}

/**
 * Get whether the scenarios run in Docker containers, as set through WP_CLI_TEST_DOCKER.
 *
 * @return string "auto" to run them without Docker where it cannot be used, "force" to fail
 *                instead, or an empty string to run them without Docker.
 */
function wp_cli_tests_docker_mode() {
	$value = strtolower( (string) getenv( 'WP_CLI_TEST_DOCKER' ) );

	if ( in_array( $value, [ 'auto', 'force' ], true ) ) {
		return $value;
	}

	return in_array( $value, [ '1', 'yes', 'true', 'on' ], true ) ? 'force' : '';
}

/**
 * Report that Docker cannot be used, and fail if it is required.
 *
 * @param string $reason
 */
function wp_cli_tests_without_docker( $reason ) {
	if ( 'force' === wp_cli_tests_docker_mode() ) {
		fwrite( STDERR, "Error: {$reason} Set WP_CLI_TEST_DOCKER to \"auto\" to run the scenarios without Docker in that case.\n" );
		exit( 1 );
	}

	fwrite( STDERR, "{$reason} Running the scenarios without Docker.\n" );
}

/**
 * Start the run over without Docker and exit with its exit code.
 *
 * The database check and the scenario selection were made for the containers,
 * so run-behat-tests has to make them again for the host.
 *
 * @param array<string>      $user_args
 * @param array<string>|null $rerun_paths Scenarios of a rerun, which are put back for it.
 */
function wp_cli_tests_restart_without_docker( array $user_args, $rerun_paths ) {
	if ( null !== $rerun_paths ) {
		file_put_contents( wp_cli_tests_failures_file(), implode( "\n", $rerun_paths ) . "\n" );
		$user_args[] = '--rerun';
	}

	$wp_version = getenv( 'WP_CLI_TEST_REQUESTED_WP_VERSION' );
	putenv( false === $wp_version || '' === $wp_version ? 'WP_VERSION' : "WP_VERSION={$wp_version}" );
	putenv( 'WP_CLI_TEST_DOCKER=0' );
	passthru( escapeshellarg( dirname( __DIR__ ) . '/bin/run-behat-tests' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $user_args ) ), $exit_code );
	exit( $exit_code );
}

/**
 * Run Behat in the current process' environment and exit with its exit code.
 *
 * @param array<string> $args
 */
function wp_cli_tests_run_behat_and_exit( array $args ) {
	$command = escapeshellarg( PHP_BINARY ) . ' vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
	passthru( $command, $exit_code );
	exit( $exit_code );
}

/**
 * Load the Gherkin parser of the Behat installation.
 *
 * @return Parser
 */
function wp_cli_tests_gherkin_parser() {
	if ( method_exists( CachedArrayKeywords::class, 'withDefaultKeywords' ) ) {
		$keywords = CachedArrayKeywords::withDefaultKeywords();
	} else {
		// Older versions of behat/gherkin ship the keywords file in their root folder.
		$dir = dirname( (string) ( new ReflectionClass( CachedArrayKeywords::class ) )->getFileName() );
		while ( ! file_exists( $dir . '/i18n.php' ) && dirname( $dir ) !== $dir ) {
			$dir = dirname( $dir );
		}
		$keywords = new CachedArrayKeywords( $dir . '/i18n.php' );
	}

	return new Parser( new Lexer( $keywords ) );
}

/**
 * Get the text of a list of steps, including their arguments.
 *
 * @param array<\Behat\Gherkin\Node\StepNode> $steps
 * @return string
 */
function wp_cli_tests_steps_text( array $steps ) {
	$text = '';
	foreach ( $steps as $step ) {
		$text .= $step->getText() . "\n";
		foreach ( $step->getArguments() as $argument ) {
			if ( $argument instanceof PyStringNode ) {
				$text .= $argument->getRaw() . "\n";
			} elseif ( $argument instanceof TableNode ) {
				$text .= $argument->getTableAsString() . "\n";
			}
		}
	}
	return $text;
}

/**
 * Collect the scenarios to run, with their expected cost and whether they need the first worker.
 *
 * @param string $features_folder
 * @param string $tags            Behat tag expression, without the --tags= prefix.
 * @return array<array{path: string, cost: int, shared: bool}>
 */
function wp_cli_tests_collect_scenarios( $features_folder, $tags ) {
	$parser    = wp_cli_tests_gherkin_parser();
	$filter    = '' !== $tags ? new TagFilter( $tags ) : null;
	$scenarios = [];

	// With code coverage, building a Phar of the bundle itself rewrites its Composer autoloader
	// for the duration of the build, which would break the scenarios of the other workers.
	$phar_shared = in_array( (string) getenv( 'WP_CLI_TEST_COVERAGE' ), [ 'true', '1' ], true )
		&& ! file_exists( 'vendor/wp-cli/wp-cli-bundle/utils/make-phar.php' )
		&& file_exists( 'utils/make-phar.php' );

	$files = glob( $features_folder . '/*.feature' );
	sort( $files );

	foreach ( $files as $file ) {
		$feature = $parser->parse( (string) file_get_contents( $file ), (string) realpath( $file ) );

		if ( ! $feature instanceof FeatureNode ) {
			continue;
		}

		$background = $feature->hasBackground() ? wp_cli_tests_steps_text( $feature->getBackground()->getSteps() ) : '';

		foreach ( $feature->getScenarios() as $scenario ) {
			/** @var ScenarioInterface $scenario */
			if ( null !== $filter && ! $filter->isScenarioMatch( $feature, $scenario ) ) {
				continue;
			}

			$text = $background . wp_cli_tests_steps_text( $scenario->getSteps() );

			// Every step costs about the same, except for setting up WordPress, Composer projects and Phars.
			$runs = $scenario instanceof \Behat\Gherkin\Node\OutlineNode ? max( 1, count( $scenario->getExamples() ) ) : 1;
			$cost = $runs * (
				substr_count( $text, "\n" )
				+ 10 * preg_match_all( '/^a WP .*install/m', $text )
				+ 40 * preg_match_all( '/^a WP .*install.* with Composer|^a dependency on current wp-cli|^a (new|downloaded) Phar|composer (require|install|update)/m', $text )
			);

			$scenarios[] = [
				'path'   => $file . ':' . $scenario->getLine(),
				'cost'   => $cost,
				'shared' => (bool) preg_match( WP_CLI_TESTS_SHARED_RESOURCES, $text )
					|| ( $phar_shared && preg_match( '/^a new Phar/m', $text ) ),
			];
		}
	}

	return $scenarios;
}

/**
 * Distribute the scenarios over the workers, the most expensive first.
 *
 * @param array<array{path: string, cost: int, shared: bool}> $scenarios
 * @param int $workers
 * @return array<int, array<string>> Scenario paths per worker.
 */
function wp_cli_tests_distribute( array $scenarios, $workers ) {
	$shards = array_fill( 0, $workers, [] );
	$load   = array_fill( 0, $workers, 0 );

	foreach ( $scenarios as $scenario ) {
		if ( $scenario['shared'] ) {
			$shards[0][] = $scenario['path'];
			$load[0]    += $scenario['cost'];
		}
	}

	$independent = array_values(
		array_filter(
			$scenarios,
			static function ( $scenario ) {
				return ! $scenario['shared'];
			}
		)
	);

	usort(
		$independent,
		static function ( $a, $b ) {
			return $b['cost'] - $a['cost'];
		}
	);

	foreach ( $independent as $scenario ) {
		$worker              = (int) array_search( min( $load ), $load, true );
		$shards[ $worker ][] = $scenario['path'];
		$load[ $worker ]    += $scenario['cost'];
	}

	return $shards;
}

/**
 * Get the share of the scenarios to run, as set through WP_CLI_TEST_SHARD to "<index>/<total>".
 *
 * @return array{int, int}|null One-based index and total number of shards, or null to run all of them.
 */
function wp_cli_tests_shard() {
	$value = trim( (string) getenv( 'WP_CLI_TEST_SHARD' ) );

	if ( '' === $value ) {
		return null;
	}

	if ( ! preg_match( '#^(\d+)/(\d+)$#', $value, $matches ) || (int) $matches[1] < 1 || (int) $matches[1] > (int) $matches[2] ) {
		fwrite( STDERR, "Warning: WP_CLI_TEST_SHARD is not of the form <index>/<total>, like 2/3, running all scenarios.\n" );
		return null;
	}

	return [ (int) $matches[1], (int) $matches[2] ];
}

/**
 * Pick one shard of the scenarios, out of a cost-balanced distribution over all shards.
 *
 * Scenarios that rely on shared resources are spread over the shards as well, as every shard runs
 * on a machine of its own. The distribution only depends on the scenarios, so every shard computes
 * the same one and together they run every scenario exactly once.
 *
 * @param array<array{path: string, cost: int, shared: bool}> $scenarios
 * @param int $index One-based.
 * @param int $total
 * @return array<array{path: string, cost: int, shared: bool}>
 */
function wp_cli_tests_pick_shard( array $scenarios, $index, $total ) {
	$order = array_keys( $scenarios );

	usort(
		$order,
		static function ( $a, $b ) use ( $scenarios ) {
			return ( $scenarios[ $b ]['cost'] - $scenarios[ $a ]['cost'] ) ?: ( $a - $b );
		}
	);

	$load   = array_fill( 0, $total, 0 );
	$picked = [];

	foreach ( $order as $key ) {
		$shard           = (int) array_search( min( $load ), $load, true );
		$load[ $shard ] += $scenarios[ $key ]['cost'];
		if ( $shard === $index - 1 ) {
			$picked[ $key ] = $scenarios[ $key ];
		}
	}

	ksort( $picked );

	return array_values( $picked );
}

/**
 * Get the Docker image of the database server the tests run against on the host, so that the
 * containers test against the same type and version of database server.
 *
 * @return string|null Image name, or null if the server could not be identified.
 */
function wp_cli_tests_docker_db_image() {
	$image = getenv( 'WP_CLI_TEST_DOCKER_DB' );
	if ( is_string( $image ) && '' !== $image ) {
		return $image;
	}

	$host  = getenv( 'WP_CLI_TEST_DBHOST' ) ?: 'localhost';
	$parts = explode( ':', $host );
	$args  = '-h' . escapeshellarg( $parts[0] );
	if ( isset( $parts[1] ) ) {
		$args .= is_numeric( $parts[1] ) ? ' --port=' . escapeshellarg( $parts[1] ) . ' --protocol=tcp' : ' --socket=' . escapeshellarg( $parts[1] );
	}
	$pass  = getenv( 'WP_CLI_TEST_DBROOTPASS' );
	$args .= ' -u' . escapeshellarg( getenv( 'WP_CLI_TEST_DBROOTUSER' ) ?: 'root' ) . ( false !== $pass && '' !== $pass ? ' -p' . escapeshellarg( $pass ) : '' );

	foreach ( [ 'mysql', 'mariadb' ] as $client ) {
		$version = (string) exec( "{$client} {$args} --skip-column-names -e 'SELECT VERSION()' 2>/dev/null" );
		if ( preg_match( '/^(\d+\.\d+)/', $version, $matches ) ) {
			return ( false !== stripos( $version, 'mariadb' ) ? 'mariadb:' : 'mysql:' ) . $matches[1];
		}
	}

	return null;
}

/**
 * Get the extra options a database server image needs to accept the test user's password.
 *
 * @param string $image
 * @return string
 */
function wp_cli_tests_docker_db_options( $image ) {
	// Throwaway databases do not need to wait for their writes to reach the disk.
	$options = '--innodb-flush-log-at-trx-commit=0 --sync-binlog=0';

	if ( preg_match( '/^mysql:(\d+)\.(\d+)/', $image, $matches ) && (int) $matches[1] >= 8 ) {
		$options .= version_compare( "{$matches[1]}.{$matches[2]}", '8.4', '>=' )
			? ' --mysql-native-password=ON --authentication-policy=mysql_native_password'
			: ' --default-authentication-plugin=mysql_native_password';
	}

	return $options;
}

/**
 * Get the PHP version the workers run, which is the host's unless WP_CLI_TEST_DOCKER_PHP sets another one.
 *
 * @return string Major and minor version.
 */
function wp_cli_tests_docker_php_version() {
	$host    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	$version = getenv( 'WP_CLI_TEST_DOCKER_PHP' );

	if ( ! is_string( $version ) || '' === $version ) {
		return $host;
	}

	if ( ! preg_match( '/^\d+\.\d+$/', $version ) ) {
		fwrite( STDERR, "Warning: WP_CLI_TEST_DOCKER_PHP is not a PHP version like 7.4, running PHP {$host} in the containers.\n" );
		return $host;
	}

	return $version;
}

/**
 * Build the image the workers run in.
 *
 * @param string $version PHP version.
 * @return string|null Image name, or null if it could not be built.
 */
function wp_cli_tests_docker_php_image( $version ) {
	$image = "wp-cli-tests-php:{$version}";

	exec( 'docker build -q -t ' . escapeshellarg( $image ) . ' --build-arg ' . escapeshellarg( "PHP_VERSION={$version}" ) . ' ' . escapeshellarg( __DIR__ . '/docker' ) . ' 2>&1', $output, $exit_code );

	if ( 0 !== $exit_code ) {
		fwrite( STDERR, "Warning: Could not build the Docker image for PHP {$version}:\n" . implode( "\n", array_slice( $output, -5 ) ) . "\n" );
		return null;
	}

	return $image;
}

/**
 * Install the project's Composer dependencies for another PHP version than the host's, as the
 * versions installed for the host can require a newer PHP version or not support an older one.
 *
 * The dependencies are resolved the way CI does without a lock file, and refreshed once a day.
 *
 * @param string $php_image Image of the PHP version to install them for.
 * @param string $version   PHP version.
 * @return string|null Vendor directory, or null if the dependencies could not be installed.
 */
function wp_cli_tests_docker_vendor( $php_image, $version ) {
	$cwd  = (string) getcwd();
	$json = (string) file_get_contents( $cwd . '/composer.json' );
	$dir  = sys_get_temp_dir() . "/wp-cli-test-docker-vendor-{$version}-" . substr( md5( $cwd . "\n" . $json ), 0, 12 );

	// Installed dependencies can be linked to local checkouts, as in wp-cli/wp-cli-dev,
	// which a separate installation cannot use.
	$linked = glob( $cwd . '/vendor/*/*', GLOB_ONLYDIR ) ?: [];
	$linked = array_filter( $linked, 'is_link' );
	if ( $linked ) {
		fwrite( STDERR, "Warning: The containers running PHP {$version} use the published versions of the dependencies instead of the local checkouts they are linked to, for example " . substr( reset( $linked ), strlen( $cwd ) + 1 ) . ".\n" );
	}

	$marker = "{$dir}/installed";
	if ( file_exists( $marker ) && filemtime( $marker ) > time() - 86400 ) {
		return "{$dir}/vendor";
	}

	if ( ! is_dir( "{$dir}/vendor" ) ) {
		mkdir( "{$dir}/vendor", 0777, true );
	}
	file_put_contents( "{$dir}/composer.json", $json );

	$cache = getenv( 'COMPOSER_CACHE_DIR' ) ?: ( getenv( 'HOME' ) . '/.cache/composer' );

	fwrite( STDERR, "Installing the Composer dependencies for PHP {$version}...\n" );

	// The project is read-only, and Composer writes its lock file next to the copy of composer.json.
	$command = 'docker run --rm'
		. ' -u ' . escapeshellarg( getmyuid() . ':' . getmygid() )
		. ' -v ' . escapeshellarg( "{$cwd}:{$cwd}:ro" )
		. ' -v ' . escapeshellarg( "{$dir}:/wp-cli-tests-vendor" )
		. ' -v ' . escapeshellarg( "{$dir}/vendor:{$cwd}/vendor" )
		. ( is_dir( $cache ) ? ' -v ' . escapeshellarg( "{$cache}:/tmp/composer-cache" ) . ' -e COMPOSER_CACHE_DIR=/tmp/composer-cache' : '' )
		. ' -e HOME=/tmp -e COMPOSER=/wp-cli-tests-vendor/composer.json'
		. ' -w ' . escapeshellarg( $cwd )
		. ' ' . escapeshellarg( $php_image )
		. ' composer update --no-interaction --no-progress 2>&1';

	exec( $command, $output, $exit_code );

	if ( 0 !== $exit_code ) {
		fwrite( STDERR, "Warning: Could not install the Composer dependencies for PHP {$version}:\n" . implode( "\n", array_slice( $output, -5 ) ) . "\n" );
		return null;
	}

	touch( $marker );

	return "{$dir}/vendor";
}

/**
 * Get the directories a container needs to see at their usual paths.
 *
 * @param string      $base_dir
 * @param string|null $vendor Dependencies installed for the PHP version of the containers, if not the host's.
 * @return array<int|string, string> Paths to mount at the same path, and read-only sources keyed by source path.
 */
function wp_cli_tests_docker_mounts( $base_dir, $vendor = null ) {
	$cwd    = (string) getcwd();
	$mounts = [ $cwd, $base_dir ];

	// Dependencies can be symlinked from outside the project, as in wp-cli/wp-cli-dev,
	// in which case the closest directory holding both has to be visible.
	foreach ( null === $vendor ? [ $cwd . '/vendor/autoload.php' ] : [] as $path ) {
		$real = realpath( $path );
		if ( false === $real || 0 === strpos( $real . '/', $cwd . '/' ) ) {
			continue;
		}
		$common = $cwd;
		while ( 0 !== strpos( $real . '/', rtrim( $common, '/' ) . '/' ) && dirname( $common ) !== $common ) {
			$common = dirname( $common );
		}
		$mounts[] = '/' === $common ? dirname( $real ) : $common;
	}

	$mounts = array_values( array_unique( $mounts ) );

	if ( null !== $vendor ) {
		$mounts[ $vendor ] = $cwd . '/vendor';
	}

	// Download WordPress once for all containers, which only ever copy from it.
	$version = getenv( 'WP_VERSION' );
	if ( $version && ! getenv( 'WP_CLI_TEST_CORE_ZIP' ) ) {
		$cache = sys_get_temp_dir() . '/wp-cli-test-core-download-cache-' . $version;
		if ( ! is_readable( $cache . '/wp-includes/version.php' ) ) {
			exec( 'vendor/bin/wp core download --force --path=' . escapeshellarg( $cache ) . ' --version=' . escapeshellarg( $version ) . ' > /dev/null 2>&1' );
		}
		if ( is_readable( $cache . '/wp-includes/version.php' ) ) {
			$mounts[ $cache ] = '/tmp/wp-cli-test-core-download-cache-' . $version;
		}
	}

	// Give the containers the PHP settings of the host for how errors are reported, as test
	// outcomes depend on them. WP-CLI, for example, only reports deprecations while testing
	// when error_reporting is exactly E_ALL, which a missing php.ini leads to, but not -1.
	$settings = [];
	foreach ( [ 'error_reporting', 'display_errors', 'display_startup_errors', 'log_errors', 'html_errors', 'zend.assertions', 'memory_limit', 'date.timezone' ] as $name ) {
		$value = ini_get( $name );
		// An empty value is a setting that is switched off, which has to be carried over as well.
		if ( false !== $value ) {
			$settings[] = $name . ' = ' . ( is_numeric( $value ) ? $value : '"' . addcslashes( $value, '"\\' ) . '"' );
		}
	}
	$ini = "{$base_dir}/php-host.ini";
	file_put_contents( $ini, implode( "\n", $settings ) . "\n" );
	$mounts[ $ini ] = '/usr/local/etc/php/conf.d/zz-wp-cli-tests-host.ini';

	return $mounts;
}

/**
 * Start the containers of a worker and get the command that runs its scenarios.
 *
 * @param string      $name     Name prefix of the worker's containers.
 * @param string      $php_image
 * @param string|null $db_image Null when running on SQLite.
 * @param array<int|string, string> $mounts
 * @param array<string> $user_args
 * @param string|null $scenario_list File listing the worker's scenarios, or null to run those the arguments select.
 * @return string
 */
function wp_cli_tests_docker_worker_command( $name, $php_image, $db_image, array $mounts, array $user_args, $scenario_list ) {
	$network = '';

	if ( null !== $db_image ) {
		exec(
			'docker run -d --rm --name ' . escapeshellarg( "{$name}-db" )
			. ' --tmpfs /var/lib/mysql'
			. ' -e MYSQL_ROOT_PASSWORD=root -e MYSQL_USER=wp_cli_test -e MYSQL_PASSWORD=password1'
			. ' ' . escapeshellarg( $db_image ) . ' ' . wp_cli_tests_docker_db_options( $db_image ) . ' 2>&1'
		);
		// Share the database container's network, so the server is at 127.0.0.1 and nothing else is.
		$network = ' --network ' . escapeshellarg( "container:{$name}-db" );
	}

	$env = [
		'HOME'                   => '/tmp/home',
		'WP_CLI_TEST_DBHOST'     => '127.0.0.1:3306',
		'WP_CLI_TEST_DBROOTUSER' => 'root',
		'WP_CLI_TEST_DBROOTPASS' => 'root',
	];

	foreach ( getenv() as $key => $value ) {
		if ( preg_match( '/^(WP_VERSION|NO_COLOR|CI|GITHUB_TOKEN|WP_CLI_TEST_(DBTYPE|OBJECT_CACHE|CORE_ZIP|QUIET|LOG_RUN_TIMES|PHP_TUNING|WP_VERSION_CACHE_TTL))$/', $key ) ) {
			$env[ $key ] = $value;
		}
	}

	$command = 'docker run --rm --name ' . escapeshellarg( $name ) . $network
		. ' --tmpfs /tmp:exec,mode=1777'
		. ' -u ' . escapeshellarg( getmyuid() . ':' . getmygid() )
		. ' -w ' . escapeshellarg( (string) getcwd() );

	foreach ( $mounts as $source => $target ) {
		if ( is_int( $source ) ) {
			$command .= ' -v ' . escapeshellarg( "{$target}:{$target}" );
		} else {
			$command .= ' -v ' . escapeshellarg( "{$source}:{$target}:ro" );
		}
	}

	foreach ( $env as $key => $value ) {
		$command .= ' -e ' . escapeshellarg( "{$key}={$value}" );
	}

	// This package's own scripts are not linked into vendor/bin when it is the project under test.
	$bin = is_file( getcwd() . '/vendor/bin/run-behat-tests' ) ? 'vendor/bin' : 'bin';

	$inner = 'mkdir -p "$HOME"'
		// Wait for the database server, which takes a few seconds to start, before preparing it.
		. ( null !== $db_image ? ' && for i in $(seq 1 60); do mysql -h127.0.0.1 -P3306 --protocol=tcp -uroot -proot -e "SELECT 1" > /dev/null 2>&1 && break; sleep 1; done && ' . $bin . '/install-package-tests > /dev/null' : '' )
		. " && {$bin}/run-behat-tests " . implode( ' ', array_map( 'escapeshellarg', array_merge( $user_args, null === $scenario_list ? [] : [ $scenario_list ] ) ) );

	return $command . ' ' . escapeshellarg( $php_image ) . ' sh -c ' . escapeshellarg( $inner );
}

$args = array_slice( $argv, 1 );

$workers = (int) array_shift( $args );

$separator  = array_search( '--', $args, true );
$behat_args = false === $separator ? $args : array_slice( $args, 0, $separator );
$user_args  = false === $separator ? [] : array_slice( $args, $separator + 1 );
$all_args   = array_merge( $behat_args, $user_args );

// Run every worker in its own throwaway containers, see run-behat-tests.
$docker = '' !== wp_cli_tests_docker_mode();
if ( $docker && DIRECTORY_SEPARATOR === '\\' ) {
	wp_cli_tests_without_docker( 'The scenarios cannot run in Docker containers on Windows.' );
	$docker = false;
}
$rerun_paths = null;

// A rerun after a parallel run covers exactly the scenarios that failed in it.
$rerun = array_search( '--rerun', $user_args, true );
if ( false !== $rerun && file_exists( wp_cli_tests_failures_file() ) && $docker ) {
	unset( $user_args[ $rerun ] );
	$user_args   = array_values( $user_args );
	$rerun_paths = array_filter( explode( "\n", (string) file_get_contents( wp_cli_tests_failures_file() ) ) );
	unlink( wp_cli_tests_failures_file() );
} elseif ( false !== $rerun && file_exists( wp_cli_tests_failures_file() ) ) {
	unset( $user_args[ $rerun ] );
	$failures = wp_cli_tests_failures_file();
	$copy     = $failures . '.' . getmypid() . '.scenarios';
	rename( $failures, $copy );
	register_shutdown_function( 'unlink', $copy );
	$command = escapeshellarg( PHP_BINARY ) . ' vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', array_merge( $behat_args, array_values( $user_args ), [ $copy ] ) ) );
	passthru( $command, $exit_code );
	exit( $exit_code );
}

// Paths, name filters and reruns select their own scenarios, so run them as they are.
// Options that take a value as the next argument have to be told apart from paths.
$selects_scenarios = false;
$takes_value       = false;
foreach ( $user_args as $arg ) {
	if ( $takes_value ) {
		$takes_value = false;
		continue;
	}
	if ( in_array( $arg, [ '--format', '-f', '--out', '-o', '--format-settings', '--tags', '--role', '--suite', '-s', '--profile', '-p', '--config', '-c', '--lang' ], true ) ) {
		$takes_value = true;
	} elseif ( '' === $arg || '-' !== $arg[0] || in_array( $arg, [ '--rerun', '--rerun-only' ], true ) || 0 === strpos( $arg, '--name' ) || '-n' === $arg ) {
		$selects_scenarios = true;
	}
}

$shard = $selects_scenarios || null !== $rerun_paths ? null : wp_cli_tests_shard();

if ( ( $workers < 2 && ! $docker && null === $shard ) || DIRECTORY_SEPARATOR === '\\' || ( $selects_scenarios && null === $rerun_paths && ! $docker ) ) {
	wp_cli_tests_run_behat_and_exit( $all_args );
}

$php_image = null;
$db_image  = null;
$vendor    = null;
if ( $docker ) {
	$php_version = wp_cli_tests_docker_php_version();
	$php_image   = wp_cli_tests_docker_php_image( $php_version );
	$db_image    = 'sqlite' === getenv( 'WP_CLI_TEST_DBTYPE' ) ? null : wp_cli_tests_docker_db_image();

	if ( null !== $php_image && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== $php_version ) {
		$vendor = wp_cli_tests_docker_vendor( $php_image, $php_version );
		if ( null === $vendor ) {
			$php_image = null;
		}
	}

	if ( null === $php_image ) {
		wp_cli_tests_without_docker( 'The containers could not be prepared.' );
		wp_cli_tests_restart_without_docker( $user_args, $rerun_paths );
	} elseif ( null === $db_image && 'sqlite' !== getenv( 'WP_CLI_TEST_DBTYPE' ) ) {
		wp_cli_tests_without_docker( 'The type and version of the database server could not be identified, set WP_CLI_TEST_DOCKER_DB to its Docker image.' );
		wp_cli_tests_restart_without_docker( $user_args, $rerun_paths );
	}
}

// Kept between runs, so that the workers' caches stay warm and failed scenarios can be inspected.
$base_dir = sys_get_temp_dir() . '/wp-cli-test-parallel-' . substr( md5( (string) getcwd() ), 0, 8 );

// Scenarios selected through the arguments run in a single container, which selects them itself.
if ( $docker && $selects_scenarios && null === $rerun_paths ) {
	if ( ! is_dir( $base_dir ) ) {
		mkdir( $base_dir, 0777, true );
	}
	$name = 'wp-cli-tests-' . substr( md5( $base_dir ), 0, 8 ) . '-0';
	register_shutdown_function(
		static function () use ( $name ) {
			exec( 'docker rm -f ' . escapeshellarg( $name ) . ' ' . escapeshellarg( "{$name}-db" ) . ' > /dev/null 2>&1' );
		}
	);
	passthru( wp_cli_tests_docker_worker_command( $name, (string) $php_image, $db_image, wp_cli_tests_docker_mounts( $base_dir, $vendor ), $user_args, null ), $exit_code );
	exit( $exit_code );
}

require getcwd() . '/vendor/autoload.php';

$tags = '';
foreach ( $behat_args as $arg ) {
	if ( 0 === strpos( $arg, '--tags=' ) ) {
		$tags = substr( $arg, strlen( '--tags=' ) );
	}
}

// Containers check for PHP extensions and versions themselves, as theirs can differ from the host's.
if ( $docker ) {
	$tags = (string) preg_replace( '/(^|&&)~@(require-extension-[A-Za-z0-9_]+|(require|less-than)-php-[0-9.]+)/', '', $tags );
	$tags = trim( $tags, '&' );
}

$features_folder = getenv( 'BEHAT_FEATURES_FOLDER' ) ?: 'features';

if ( null !== $rerun_paths ) {
	$scenarios = array_map(
		static function ( $path ) {
			return [
				'path'   => $path,
				'cost'   => 1,
				'shared' => false,
			];
		},
		$rerun_paths
	);
	$workers   = 1;
} else {
	$scenarios = wp_cli_tests_collect_scenarios( $features_folder, $tags );
}

if ( null !== $shard ) {
	$all_scenarios = count( $scenarios );
	$scenarios     = wp_cli_tests_pick_shard( $scenarios, $shard[0], $shard[1] );
	printf( "Running shard %d of %d, with %d of %d scenarios.\n", $shard[0], $shard[1], count( $scenarios ), $all_scenarios );
}

$workers = min( max( 1, $workers ), max( 1, count( $scenarios ) ) );

if ( $workers < 2 && ! $docker ) {
	if ( null === $shard ) {
		wp_cli_tests_run_behat_and_exit( $all_args );
	}

	if ( ! is_dir( $base_dir ) ) {
		mkdir( $base_dir, 0777, true );
	}
	$list = "{$base_dir}/shard.scenarios";
	file_put_contents( $list, implode( "\n", array_column( $scenarios, 'path' ) ) . "\n" );
	if ( count( $scenarios ) > 0 ) {
		wp_cli_tests_run_behat_and_exit( array_merge( $all_args, [ $list ] ) );
	}
	exit( 0 );
}

// Containers do not share ports or temporary directories, so any of them can run any scenario.
if ( $docker ) {
	foreach ( $scenarios as $key => $scenario ) {
		$scenarios[ $key ]['shared'] = false;
	}
}

$shards  = wp_cli_tests_distribute( $scenarios, $workers );
$db_name = getenv( 'WP_CLI_TEST_DBNAME' ) ?: 'wp_cli_test';

if ( ! is_dir( $base_dir ) ) {
	mkdir( $base_dir, 0777, true );
}

printf( "Running %d scenarios in %d parallel workers%s.\n", count( $scenarios ), $workers, $docker ? ' in Docker containers' : '' );

$containers = [];
if ( $docker ) {
	$mounts = wp_cli_tests_docker_mounts( $base_dir, $vendor );
	register_shutdown_function(
		static function () use ( &$containers ) {
			foreach ( $containers as $container ) {
				exec( 'docker rm -f ' . escapeshellarg( $container ) . ' > /dev/null 2>&1' );
			}
		}
	);
}

$procs = [];
foreach ( $shards as $index => $paths ) {
	$list = "{$base_dir}/worker-{$index}.scenarios";
	file_put_contents( $list, implode( "\n", $paths ) . "\n" );

	$env = getenv();

	// Every worker merges the code coverage of its own scenarios into a report of its own.
	$env['WP_CLI_TEST_COVERAGE_ID'] = ( null !== $shard ? "shard-{$shard[0]}-" : '' ) . "worker-{$index}";

	if ( $index > 0 ) {
		$env['TMPDIR']             = "{$base_dir}/worker-{$index}";
		$env['WP_CLI_TEST_DBNAME'] = "{$db_name}_{$index}";
		if ( ! is_dir( $env['TMPDIR'] ) ) {
			mkdir( $env['TMPDIR'] );
		}
	}

	$output  = "{$base_dir}/worker-{$index}.log";
	$command = escapeshellarg( PHP_BINARY ) . ' vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', array_merge( $all_args, [ $list ] ) ) );

	if ( $docker ) {
		$name         = 'wp-cli-tests-' . substr( md5( $base_dir ), 0, 8 ) . "-{$index}";
		$containers[] = $name;
		$containers[] = "{$name}-db";
		$env          = getenv();
		$command      = wp_cli_tests_docker_worker_command( $name, (string) $php_image, $db_image, $mounts, array_values( $user_args ), $list );
	}

	$procs[ $index ] = [
		'proc'   => proc_open(
			$command,
			[
				0 => [ 'file', '/dev/null', 'r' ],
				1 => [ 'file', $output, 'w' ],
				2 => [ 'file', $output, 'a' ],
			],
			$pipes,
			null,
			$env
		),
		'output' => $output,
		'start'  => microtime( true ),
	];
}

$exit_code = 0;
$failed    = [];
$pending   = $procs;

while ( $pending ) {
	foreach ( $pending as $index => $worker ) {
		$status = proc_get_status( $worker['proc'] );

		if ( $status['running'] ) {
			continue;
		}

		unset( $pending[ $index ] );
		proc_close( $worker['proc'] );

		$code      = $status['exitcode'];
		$exit_code = max( $exit_code, $code );
		$output    = (string) file_get_contents( $worker['output'] );

		printf( "\n--- Worker %d (%d scenarios, %.1fs, exit code %d)\n\n%s\n", $index, count( $shards[ $index ] ), microtime( true ) - $worker['start'], $code, rtrim( $output ) );

		// Behat numbers the failed scenarios and examples, followed by their path and line.
		// Older versions list them below a "Failed scenarios" heading instead.
		$paths = [];
		if ( preg_match_all( '/^\d+ [^#\n]*#\s*(\S+:\d+)\s*$/m', $output, $matches ) ) {
			$paths = $matches[1];
		} elseif ( preg_match( '/--- Failed scenarios:\s*\n((?:[ \t]+\S+:\d+.*\n?)+)/', $output, $matches ) ) {
			$paths = preg_replace( '/^\s*(\S+:\d+).*$/', '$1', preg_split( '/\n/', trim( $matches[1] ) ) );
		}

		if ( $paths ) {
			$failed = array_merge( $failed, $paths );
		} elseif ( 0 !== $code ) {
			// Without a list of failures, rerun everything this worker ran.
			$failed = array_merge( $failed, $shards[ $index ] );
		}
	}

	if ( $pending ) {
		usleep( 200000 );
	}
}

if ( $failed ) {
	file_put_contents( wp_cli_tests_failures_file(), implode( "\n", array_unique( $failed ) ) . "\n" );
} elseif ( file_exists( wp_cli_tests_failures_file() ) ) {
	unlink( wp_cli_tests_failures_file() );
}

exit( $exit_code );
