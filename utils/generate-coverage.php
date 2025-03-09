<?php

/**
 * This script is added via `--require` to the WP-CLI commands executed by the Behat test runner.
 * It starts coverage collection right away and registers a shutdown hook to complete it
 * after the respective WP-CLI command has finished.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Clover;

// The wp-cli-tests directory.
$package_folder = realpath( dirname( __DIR__ ) );

// If installed as a dependency in `<somedir>/vendor/wp-cli/wp-cli-tests, this is <somedir>.
$root_folder = realpath( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) );

if ( file_exists( $package_folder . '/vendor/autoload.php' ) ) {
	$root_folder = $package_folder;
}

if ( ! class_exists( 'SebastianBergmann\CodeCoverage\Filter' ) ) {
	require "{$root_folder}/vendor/autoload.php";
}

$filter = new Filter();
// In wp-cli/wp-cli, all source code is in the "php" folder.
$filter->includeDirectory( "{$root_folder}/php" );

// In commands, all source code is in the "src" folder.
$filter->includeDirectory( "{$root_folder}/src" );
// There is also a "*-command.php" file.
$filter->includeDirectory( $root_folder, '-command.php' );

$coverage = new CodeCoverage(
	( new Selector() )->forLineCoverage( $filter ),
	$filter
);

/*
 * The names of the current feature and scenario are passed on from the Behat test runner
 * to this script through environment variables `BEHAT_FEATURE_TITLE` & `BEHAT_SCENARIO_TITLE`.
 */
$feature  = getenv( 'BEHAT_FEATURE_TITLE' );
$scenario = getenv( 'BEHAT_SCENARIO_TITLE' );
$name     = "{$feature} - {$scenario}";

$coverage->start( $name );

register_shutdown_function(
	static function () use ( $coverage, $feature, $scenario, $name ) {
		$coverage->stop();

		$project_dir = (string) getenv( 'BEHAT_PROJECT_DIR' );

		$feature_suffix  = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $feature ) );
		$scenario_suffix = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $scenario ) );
		$db_type         = strtolower( getenv( 'WP_CLI_TEST_DBTYPE' ) );
		$destination     = "$project_dir/build/logs/$feature_suffix-$scenario_suffix-$db_type.xml";

		$dir = dirname( $destination );
		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}

		( new Clover() )->process( $coverage, $destination, $name );
	}
);
