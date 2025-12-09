<?php
/**
 * Resolve the WP version to use for the tests.
 */

use Composer\Semver\Semver;

const WP_VERSIONS_JSON_FILE = '/wp-cli-tests-wp-versions.json';
const WP_VERSIONS_JSON_URL  = 'https://raw.githubusercontent.com/wp-cli/wp-cli-tests/artifacts/wp-versions.json';

$wp_version_env        = getenv( 'WP_VERSION' );
$wp_versions_file_path = sys_get_temp_dir() . WP_VERSIONS_JSON_FILE;

if ( ! file_exists( $wp_versions_file_path ) ) {
	$ch = curl_init( WP_VERSIONS_JSON_URL );
	$fp = fopen( $wp_versions_file_path, 'w' );

	curl_setopt( $ch, CURLOPT_FILE, $fp );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

	curl_exec( $ch );
	if ( PHP_VERSION_ID < 80000 ) { // curl_close() has no effect as of PHP 8.0.
		// phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated,Generic.PHP.DeprecatedFunctions.Deprecated
		curl_close( $ch );
	}
	fclose( $fp );
}

$wp_versions_json = json_decode( file_get_contents( $wp_versions_file_path ), true );

if ( empty( $wp_version_env ) || 'latest' === $wp_version_env ) {
	$wp_version = array_search( 'latest', $wp_versions_json, true );

	if ( empty( $wp_version ) ) {
		$wp_version = $wp_version_env;
	}

	echo $wp_version;
	exit( 0 );
}

$wp_version    = '';
$constraint    = '';
$wp_versions   = array_keys( $wp_versions_json );
$version_count = count( explode( '.', $wp_version_env ) );

if ( 1 === $version_count ) {
	$constraint = "^$wp_version_env"; // Get the latest minor version.
} elseif ( 2 === $version_count ) {
	$constraint = "~$wp_version_env.0"; // Get the latest patch version.
} elseif ( 3 === $version_count ) {
	$constraint = "=$wp_version_env"; // Get the exact version.
} else {
	$constraint = $wp_version_env;
}

if ( ! class_exists( 'Composer\Semver\Semver' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
}

try {
	$wp_satisfied_versions = Semver::satisfiedBy( $wp_versions, $constraint );
} catch ( Exception $e ) {
	echo $wp_version_env;
	exit( 0 );
}

$wp_version = end( $wp_satisfied_versions );
echo $wp_version ? : $wp_version_env;
