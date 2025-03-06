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

$root_folder = realpath( dirname( __DIR__ ) );

if ( ! class_exists( 'SebastianBergmann\CodeCoverage\Filter' ) ) {
	require "{$root_folder}/vendor/autoload.php";
}

$filter = new Filter();
$filter->includeDirectory( "{$root_folder}/includes" );
$filter->includeFiles( array( "{$root_folder}/plugin.php" ) );

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
