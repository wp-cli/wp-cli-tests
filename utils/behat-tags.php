<?php
/**
 * Generate a list of tags to skip during the test run.
 *
 * Require a minimum version of WordPress:
 *
 *   @require-wp-4.0
 *   Scenario: Core translation CRUD
 *
 * Then use in bash script:
 *
 *   BEHAT_TAGS=$(php behat-tags.php)
 *   vendor/bin/behat --format progress $BEHAT_TAGS
 */

function version_tags(
	$prefix,
	$current,
	$operator = '<',
	$features_folder = 'features'
) {
	if ( ! $current ) {
		return array();
	}

	exec(
		"grep '@{$prefix}-[0-9\.]*' -h -o {$features_folder}/*.feature | uniq",
		$existing_tags
	);

	$skip_tags = array();

	foreach ( $existing_tags as $tag ) {
		$compare = str_replace( "@{$prefix}-", '', $tag );
		if ( version_compare( $current, $compare, $operator ) ) {
			$skip_tags[] = $tag;
		}
	}

	return $skip_tags;
}

function get_db_type_and_version() {
	// Detect which client binary is available.
	$client_binary = null;
	$output        = array();
	exec( 'command -v mysql 2>/dev/null', $output, $mysql_exit_code );
	if ( 0 === $mysql_exit_code ) {
		$client_binary = 'mysql';
	} else {
		$output = array();
		exec( 'command -v mariadb 2>/dev/null', $output, $mariadb_exit_code );
		if ( 0 === $mariadb_exit_code ) {
			$client_binary = 'mariadb';
		}
	}

	if ( null === $client_binary ) {
		// No client binary found, return defaults.
		return array(
			'type'    => 'mysql',
			'version' => '',
		);
	}

	// Build connection parameters from environment variables.
	$host = getenv( 'WP_CLI_TEST_DBHOST' ) ?: 'localhost';
	$user = getenv( 'WP_CLI_TEST_DBROOTUSER' ) ?: 'root';
	$pass = getenv( 'WP_CLI_TEST_DBROOTPASS' );

	// Build the command to get the server version.
	$host_parts = explode( ':', $host );
	$host_arg   = '-h' . escapeshellarg( $host_parts[0] );

	$port_arg = '';
	if ( isset( $host_parts[1] ) ) {
		// Check if it's a port number or socket path.
		if ( is_numeric( $host_parts[1] ) ) {
			$port_arg = ' --port=' . escapeshellarg( $host_parts[1] ) . ' --protocol=tcp';
		} else {
			$port_arg = ' --socket=' . escapeshellarg( $host_parts[1] ) . ' --protocol=socket';
		}
	}

	$pass_arg = false !== $pass && '' !== $pass ? '-p' . escapeshellarg( $pass ) : '';

	$cmd = sprintf(
		'%s %s %s -u%s %s -e "SELECT VERSION()" --skip-column-names 2>/dev/null',
		escapeshellcmd( $client_binary ),
		$host_arg,
		$port_arg,
		escapeshellarg( $user ),
		$pass_arg
	);

	$output      = array();
	$return_code = 0;
	exec( $cmd, $output, $return_code );
	$version_string = isset( $output[0] ) ? $output[0] : '';

	// If the connection failed, fall back to client binary version.
	if ( 0 !== $return_code || empty( $version_string ) ) {
		$client_version_cmd = sprintf( '%s --version 2>/dev/null', escapeshellcmd( $client_binary ) );
		$version_string     = exec( $client_version_cmd );
	}

	// Detect database type from server version string.
	$db_type = 'mysql';
	if ( false !== stripos( $version_string, 'mariadb' ) ) {
		$db_type = 'mariadb';
	}

	preg_match( '@[0-9]+\.[0-9]+\.[0-9]+@', $version_string, $version );
	$db_version = isset( $version[0] ) ? $version[0] : '';

	return array(
		'type'    => $db_type,
		'version' => $db_version,
	);
}

function get_db_version() {
	$db_info = get_db_type_and_version();
	return $db_info['version'];
}

$features_folder = getenv( 'BEHAT_FEATURES_FOLDER' ) ?: 'features';
$wp_version      = getenv( 'WP_VERSION' );
$wp_version_reqs = array();
// Only apply @require-wp tags when WP_VERSION isn't 'latest', 'nightly' or 'trunk'.
// 'latest', 'nightly' and 'trunk' are expected to work with all features.
if ( $wp_version &&
	! in_array( $wp_version, array( 'latest', 'nightly', 'trunk' ), true ) ) {
	$wp_version_reqs = array_merge(
		version_tags( 'require-wp', $wp_version, '<', $features_folder ),
		version_tags( 'less-than-wp', $wp_version, '>=', $features_folder )
	);
} else {
	// But make sure @less-than-wp tags always exist for those special cases. (Note: @less-than-wp-latest etc won't work and shouldn't be used).
	$wp_version_reqs = array_merge(
		$wp_version_reqs,
		version_tags( 'less-than-wp', '9999', '>=', $features_folder )
	);
}

$skip_tags = array_merge(
	$wp_version_reqs,
	version_tags( 'require-php', PHP_VERSION, '<', $features_folder ),
	// Note: this was '>' prior to WP-CLI 1.5.0 but the change is unlikely to
	// cause BC issues as usually compared against major.minor only.
	version_tags( 'less-than-php', PHP_VERSION, '>=', $features_folder )
);

// Skip GitHub API tests if `GITHUB_TOKEN` not available because of rate
// limiting. See https://github.com/wp-cli/wp-cli/issues/1612
if ( ! getenv( 'GITHUB_TOKEN' ) ) {
	$skip_tags[] = '@github-api';
}
# Skip tests known to be broken.
$skip_tags[] = '@broken';

if ( $wp_version && in_array( $wp_version, array( 'nightly', 'trunk' ), true ) ) {
	$skip_tags[] = '@broken-trunk';
}

$db_info    = get_db_type_and_version();
$db_version = $db_info['version'];
// Use detected database type from server, unless WP_CLI_TEST_DBTYPE is 'sqlite'.
$env_db_type = getenv( 'WP_CLI_TEST_DBTYPE' );
$db_type     = 'sqlite' === $env_db_type ? 'sqlite' : $db_info['type'];

switch ( $db_type ) {
	case 'mariadb':
		$skip_tags = array_merge(
			$skip_tags,
			[ '@require-mysql', '@require-sqlite' ],
			version_tags( 'require-mariadb', $db_version, '<', $features_folder ),
			version_tags( 'less-than-mariadb', $db_version, '>=', $features_folder )
		);
		break;
	case 'sqlite':
		$skip_tags[] = '@require-mariadb';
		$skip_tags[] = '@require-mysql';
		$skip_tags[] = '@require-mysql-or-mariadb';
		break;
	case 'mysql':
	default:
		$skip_tags = array_merge(
			$skip_tags,
			[ '@require-mariadb', '@require-sqlite' ],
			version_tags( 'require-mysql', $db_version, '<', $features_folder ),
			version_tags( 'less-than-mysql', $db_version, '>=', $features_folder )
		);
		break;
}

# Require PHP extension, eg 'imagick'.
function extension_tags( $features_folder = 'features' ) {
	$extension_tags = array();
	exec(
		"grep '@require-extension-[A-Za-z_]*' -h -o {$features_folder}/*.feature | uniq",
		$extension_tags
	);

	$skip_tags = array();

	$substr_start = strlen( '@require-extension-' );
	foreach ( $extension_tags as $tag ) {
		$extension = substr( $tag, $substr_start );
		if ( ! extension_loaded( $extension ) ) {
			$skip_tags[] = $tag;
		}
	}

	return $skip_tags;
}

$skip_tags = array_merge( $skip_tags, extension_tags( $features_folder ) );

if ( ! empty( $skip_tags ) ) {
	echo '--tags=~' . implode( '&&~', $skip_tags );
}
