<?php
/**
 * Hand the default profile of the project's behat.yml to a Behat that no longer reads YAML.
 *
 * Behat 4 only reads its configuration from a PHP file, but still merges the JSON it finds in the
 * BEHAT_PARAMS environment variable, in every version. Printing the profile in that form lets a
 * project keep a single behat.yml for all the Behat versions it is tested with.
 *
 * What BEHAT_PARAMS already holds is merged over the profile, as Behat 3 does with behat.yml.
 *
 * Prints nothing when Behat reads behat.yml by itself.
 *
 * Usage: php behat-config.php [<path to behat.yml>]
 */

use Composer\InstalledVersions;
use Symfony\Component\Yaml\Yaml;

/**
 * Move the settings a suite lists next to its reserved keys into its "settings", as Behat does.
 *
 * Behat accepts both forms, and lets the first one win. Merging two profiles that each use another
 * form would otherwise keep both, and the one that is meant to be overridden could be that winner.
 *
 * @param array<mixed> $profile
 * @return array<mixed>
 */
function wp_cli_tests_normalize_suites( array $profile ) {
	if ( ! isset( $profile['suites'] ) || ! is_array( $profile['suites'] ) ) {
		return $profile;
	}

	foreach ( $profile['suites'] as $name => $suite ) {
		if ( ! is_array( $suite ) ) {
			continue;
		}

		$settings = isset( $suite['settings'] ) && is_array( $suite['settings'] ) ? $suite['settings'] : [];

		foreach ( $suite as $key => $value ) {
			if ( ! in_array( $key, [ 'enabled', 'type', 'settings' ], true ) ) {
				$settings[ $key ] = $value;
				unset( $suite[ $key ] );
			}
		}

		if ( $settings ) {
			$suite['settings'] = $settings;
		}

		$profile['suites'][ $name ] = $suite;
	}

	return $profile;
}

/**
 * Merge overrides into a profile: maps key by key, anything else, lists included, as a whole.
 *
 * @param array<mixed> $base
 * @param array<mixed> $overrides
 * @return array<mixed>
 */
function wp_cli_tests_merge_params( array $base, array $overrides ) {
	foreach ( $overrides as $key => $value ) {
		$is_map = static function ( $candidate ) {
			return is_array( $candidate ) && $candidate && array_keys( $candidate ) !== range( 0, count( $candidate ) - 1 );
		};

		if ( isset( $base[ $key ] ) && $is_map( $base[ $key ] ) && $is_map( $value ) ) {
			$base[ $key ] = wp_cli_tests_merge_params( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}

	return $base;
}

$wp_cli_tests_autoloader = getcwd() . '/vendor/autoload.php';

if ( ! file_exists( $wp_cli_tests_autoloader ) ) {
	exit( 0 );
}

require $wp_cli_tests_autoloader;

// Without Composer's runtime API, the installation predates Behat 4, which requires it.
if ( ! class_exists( InstalledVersions::class ) || ! InstalledVersions::isInstalled( 'behat/behat' ) ) {
	exit( 0 );
}

$wp_cli_tests_behat_version = (string) InstalledVersions::getVersion( 'behat/behat' );

if ( version_compare( $wp_cli_tests_behat_version, '4.0.0-alpha1', '<' ) ) {
	exit( 0 );
}

$wp_cli_tests_config_file = isset( $argv[1] ) ? $argv[1] : getcwd() . '/behat.yml';

try {
	$wp_cli_tests_config = Yaml::parseFile( $wp_cli_tests_config_file );
} catch ( Exception $exception ) {
	fwrite( STDERR, "Error: Could not read {$wp_cli_tests_config_file}: {$exception->getMessage()}\n" );
	exit( 1 );
}

if ( ! is_array( $wp_cli_tests_config ) || ! isset( $wp_cli_tests_config['default'] ) || ! is_array( $wp_cli_tests_config['default'] ) ) {
	fwrite( STDERR, "Error: {$wp_cli_tests_config_file} has no \"default\" profile to run with Behat {$wp_cli_tests_behat_version}.\n" );
	exit( 1 );
}

if ( count( $wp_cli_tests_config ) > 1 ) {
	$wp_cli_tests_ignored = implode( ', ', array_diff( array_keys( $wp_cli_tests_config ), [ 'default' ] ) );
	fwrite( STDERR, "Warning: Behat {$wp_cli_tests_behat_version} does not read YAML, so only the \"default\" profile of {$wp_cli_tests_config_file} is used, not: {$wp_cli_tests_ignored}. Add a behat.php for those.\n" );
}

$wp_cli_tests_params = $wp_cli_tests_config['default'];
$wp_cli_tests_env    = getenv( 'BEHAT_PARAMS' );

if ( is_string( $wp_cli_tests_env ) && '' !== $wp_cli_tests_env ) {
	$wp_cli_tests_overrides = json_decode( $wp_cli_tests_env, true );

	if ( ! is_array( $wp_cli_tests_overrides ) ) {
		fwrite( STDERR, "Error: BEHAT_PARAMS should contain valid JSON, but it is set to `{$wp_cli_tests_env}`.\n" );
		exit( 1 );
	}

	$wp_cli_tests_params = wp_cli_tests_merge_params(
		wp_cli_tests_normalize_suites( $wp_cli_tests_params ),
		wp_cli_tests_normalize_suites( $wp_cli_tests_overrides )
	);
}

echo json_encode( $wp_cli_tests_params, JSON_UNESCAPED_SLASHES );
