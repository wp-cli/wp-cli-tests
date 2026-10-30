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
 * Run Behat in the current process' environment and exit with its exit code.
 *
 * @param array<string> $args
 */
function wp_cli_tests_run_behat_and_exit( array $args ) {
	$command = 'vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
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
				'shared' => (bool) preg_match( WP_CLI_TESTS_SHARED_RESOURCES, $text ),
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

$args = array_slice( $argv, 1 );

$workers = (int) array_shift( $args );

$separator  = array_search( '--', $args, true );
$behat_args = false === $separator ? $args : array_slice( $args, 0, $separator );
$user_args  = false === $separator ? [] : array_slice( $args, $separator + 1 );
$all_args   = array_merge( $behat_args, $user_args );

// A rerun after a parallel run covers exactly the scenarios that failed in it.
$rerun = array_search( '--rerun', $user_args, true );
if ( false !== $rerun && file_exists( wp_cli_tests_failures_file() ) ) {
	unset( $user_args[ $rerun ] );
	$failures = wp_cli_tests_failures_file();
	$copy     = $failures . '.' . getmypid() . '.scenarios';
	rename( $failures, $copy );
	register_shutdown_function( 'unlink', $copy );
	$command = 'vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', array_merge( $behat_args, array_values( $user_args ), [ $copy ] ) ) );
	passthru( $command, $exit_code );
	exit( $exit_code );
}

// Paths, name filters and reruns select their own scenarios, so run them as they are.
$selects_scenarios = false;
foreach ( $user_args as $arg ) {
	if ( '' === $arg || '-' !== $arg[0] || in_array( $arg, [ '--rerun', '--rerun-only' ], true ) || 0 === strpos( $arg, '--name' ) || '-n' === $arg ) {
		$selects_scenarios = true;
	}
}

if ( $workers < 2 || DIRECTORY_SEPARATOR === '\\' || $selects_scenarios ) {
	wp_cli_tests_run_behat_and_exit( $all_args );
}

require getcwd() . '/vendor/autoload.php';

$tags = '';
foreach ( $behat_args as $arg ) {
	if ( 0 === strpos( $arg, '--tags=' ) ) {
		$tags = substr( $arg, strlen( '--tags=' ) );
	}
}

$features_folder = getenv( 'BEHAT_FEATURES_FOLDER' ) ?: 'features';
$scenarios       = wp_cli_tests_collect_scenarios( $features_folder, $tags );
$workers         = min( $workers, max( 1, count( $scenarios ) ) );

if ( $workers < 2 ) {
	wp_cli_tests_run_behat_and_exit( $all_args );
}

$shards = wp_cli_tests_distribute( $scenarios, $workers );
// Kept between runs, so that the workers' caches stay warm and failed scenarios can be inspected.
$base_dir = sys_get_temp_dir() . '/wp-cli-test-parallel-' . substr( md5( (string) getcwd() ), 0, 8 );
$db_name  = getenv( 'WP_CLI_TEST_DBNAME' ) ?: 'wp_cli_test';

if ( ! is_dir( $base_dir ) ) {
	mkdir( $base_dir, 0777, true );
}

printf( "Running %d scenarios in %d parallel workers.\n", count( $scenarios ), $workers );

$procs = [];
foreach ( $shards as $index => $paths ) {
	$list = "{$base_dir}/worker-{$index}.scenarios";
	file_put_contents( $list, implode( "\n", $paths ) . "\n" );

	$env = getenv();

	if ( $index > 0 ) {
		$env['TMPDIR']             = "{$base_dir}/worker-{$index}";
		$env['WP_CLI_TEST_DBNAME'] = "{$db_name}_{$index}";
		if ( ! is_dir( $env['TMPDIR'] ) ) {
			mkdir( $env['TMPDIR'] );
		}
	}

	$output  = "{$base_dir}/worker-{$index}.log";
	$command = 'vendor/bin/behat ' . implode( ' ', array_map( 'escapeshellarg', array_merge( $all_args, [ $list ] ) ) );

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
