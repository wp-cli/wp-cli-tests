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


$project_dir = (string) getenv( 'TEST_RUN_DIR' );

if ( ! class_exists( 'SebastianBergmann\CodeCoverage\Filter' ) ) {
	if ( ! file_exists( $project_dir . '/vendor/autoload.php' ) ) {
		die( 'Could not load dependencies for generating code coverage' );
	}
	require "{$project_dir}/vendor/autoload.php";
}

$filtered_items = new CallbackFilterIterator(
	new DirectoryIterator( $project_dir ),
	function ( $file ) {
		// Allow directories named "php" or "src"
		if ( $file->isDir() && in_array( $file->getFilename(), [ 'php', 'src' ], true ) ) {
			return true;
		}

		// Allow top-level files ending in "-command.php"
		if ( $file->isFile() && false !== strpos( $file->getFilename(), '-command.php' ) ) {
			return true;
		}

		return false;
	}
);

$files = [];

foreach ( $filtered_items as $item ) {
	if ( $item->isDir() ) {
		foreach (
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $item->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS )
			) as $file
		) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file->getPathname();
			}
		}
	} else {
		$files[] = $item->getPathname();
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
