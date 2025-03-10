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
$project_dir = (string) getenv( 'BEHAT_PROJECT_DIR' );

// If we're not in a Behat environment.
if ( ! $project_dir ) {
	$project_dir = realpath( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) );
}

if ( ! file_exists( $project_dir . '/vendor/autoload.php' ) ) {
	$project_dir = $package_folder;
}

if ( ! class_exists( 'SebastianBergmann\CodeCoverage\Filter' ) ) {
	if ( ! file_exists( $project_dir . '/vendor/autoload.php' ) ) {
		die( 'Could not load dependencies for generating code coverage' );
	}

	require "{$project_dir}/vendor/autoload.php";
}

$files = [];

$dir_to_search = null;

// In wp-cli/wp-cli, all source code is in the "php" folder.
// In commands, all source code is in the "src" folder.
if ( is_dir( "{$project_dir}/php" ) ) {
	$dir_to_search = "{$project_dir}/php";
} elseif ( is_dir( "{$project_dir}/src" ) ) {
	$dir_to_search = "{$project_dir}/src";
}

if ( $dir_to_search ) {
	foreach (
		new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir_to_search, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS )
		)
		as $file
	) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

// There is also a "*-command.php" file.
foreach (
	new IteratorIterator(
		new DirectoryIterator( $project_dir )
	) as $file ) {
	if ( $file->isFile() && false !== strpos( $file->getFilename(), '-command.php' ) ) {
		$files[] = $file->getPathname();
		break;
	}
}

$filter = new Filter();

$filter->includeFiles( $files );

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
	static function () use ( $coverage, $feature, $scenario, $name, $project_dir ) {
		$coverage->stop();

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
