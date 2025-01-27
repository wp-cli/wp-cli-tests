<?php

namespace WP_CLI\Tests\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use RuntimeException;
use WP_CLI\Process;
use WP_CLI\Utils;

trait GivenStepDefinitions {

	/**
	 * @Given an empty directory
	 */
	public function given_an_empty_directory() {
		$this->create_run_dir();
	}

	/**
	 * @Given /^an? (empty|non-existent) ([^\s]+) directory$/
	 */
	public function given_a_specific_directory( $empty_or_nonexistent, $dir ) {
		$dir = $this->replace_variables( $dir );
		if ( ! Utils\is_path_absolute( $dir ) ) {
			$dir = $this->variables['RUN_DIR'] . "/$dir";
		}

		// Mac OS X can prefix the `/var` folder to turn it into `/private/var`.
		$dir = preg_replace( '|^/private/var/|', '/var/', $dir );

		$temp_dir = sys_get_temp_dir();

		// Also check for temp dir prefixed with `/private` for Mac OS X.
		if ( 0 !== strpos( $dir, $temp_dir ) && 0 !== strpos( $dir, "/private{$temp_dir}" ) ) {
			throw new RuntimeException(
				sprintf(
					"Attempted to delete directory '%s' that is not in the temp directory '%s'. " . __FILE__ . ':' . __LINE__,
					$dir,
					$temp_dir
				)
			);
		}

		$this->remove_dir( $dir );
		if ( 'empty' === $empty_or_nonexistent ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}
	}

	/**
	 * @Given an empty cache
	 */
	public function given_an_empty_cache() {
		$this->variables['SUITE_CACHE_DIR'] = FeatureContext::create_cache_dir();
	}

	/**
	 * @Given /^an? ([^\s]+) (file|cache file):$/
	 */
	public function given_a_specific_file( $path, $type, PyStringNode $content ) {
		$path      = $this->replace_variables( (string) $path );
		$content   = $this->replace_variables( (string) $content ) . "\n";
		$full_path = 'cache file' === $type
			? $this->variables['SUITE_CACHE_DIR'] . "/$path"
			: $this->variables['RUN_DIR'] . "/$path";
		$dir       = dirname( $full_path );
		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}
		file_put_contents( $full_path, $content );
	}

	/**
	 * @Given /^"([^"]+)" replaced with "([^"]+)" in the ([^\s]+) file$/
	 */
	public function given_string_replaced_with_string_in_a_specific_file( $search, $replace, $path ) {
		$full_path = $this->variables['RUN_DIR'] . "/$path";
		$contents  = file_get_contents( $full_path );
		$contents  = str_replace( $search, $replace, $contents );
		file_put_contents( $full_path, $contents );
	}

	/**
	 * @Given /^an? HTTP request to (.*?) with this response:$/
	 */
	public function given_a_request_to_a_url_respond_with_file( $url_or_pattern, PyStringNode $content ) {
		if ( ! isset( $this->variables['RUN_DIR'] ) ) {
			$this->create_run_dir();
		}

		$config_file = $this->variables['RUN_DIR'] . '/wp-cli.yml';
		$mock_file   = $this->variables['RUN_DIR'] . '/mock-requests.php';
		$dir         = dirname( $config_file );

		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}

		$config_file_contents = <<<FILE
require:
    - mock-requests.php
FILE;

		file_put_contents(
			$config_file,
			$config_file_contents
		);

		$pattern = '/' . preg_quote( $url_or_pattern, '/' ) . '/';

		$this->mocked_requests[ $pattern ] = (string) $content;

		$mocked_requests = var_export( $this->mocked_requests, true /* return */ );

		$mock_file_contents = <<<FILE
<?php
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Transport;
use WpOrg\Requests\Transport\Curl;
use WpOrg\Requests\Requests;

class WP_CLI_Tests_Mock_Requests_Transport implements Transport {
	public function request( \$url, \$headers = array(), \$data = array(), \$options = array() ) {
		\$mocked_requests = $mocked_requests;

		foreach ( \$mocked_requests as \$pattern => \$response ) {
			if ( 1 === preg_match( \$pattern, \$url ) ) {
				\$pos = strpos( \$response, "\\n\\n");
				if ( false !== \$pos ) {
					\$response = substr( \$response, 0, \$pos ) . "\\r\\n\\r\\n" . substr( \$response, \$pos + 2 );
				}
				return \$response;
			}
		}

		return (new Curl())->request( \$url, \$headers, \$data, \$options );
	}

	public function request_multiple( \$requests, \$options ) {
		throw new Exception( 'Method not implemented: ' . __METHOD__ );
	}

	public static function test( \$capabilities = array() ) {
		return true;
	}
}

WP_CLI::add_hook(
	'http_request_options',
	static function( \$options ) {
		\$options['transport'] = new WP_CLI_Tests_Mock_Requests_Transport();
		return \$options;
	}
);

WP_CLI::add_wp_hook(
	'pre_http_request',
	static function( \$pre, \$parsed_args, \$url ) {
		\$mocked_requests = $mocked_requests;

		foreach ( \$mocked_requests as \$pattern => \$response ) {
			if ( 1 === preg_match( \$pattern, \$url ) ) {
				\$pos = strpos( \$response, "\n\n");
				if ( false !== \$pos ) {
					\$response = substr( \$response, 0, \$pos ) . "\r\n\r\n" . substr( \$response, \$pos + 2 );
				}
				Requests::parse_multiple(
					\$response,
					array(
						'url'     => \$url,
						'headers' => array(),
						'data'    => array(),
						'options' => array_merge(
							Requests::OPTION_DEFAULTS,
							array(
								'hooks' => new Hooks(),
							)
						),
					)
				);

				return array(
					'headers'  => \$response->headers->getAll(),
					'body'     => \$response->body,
					'response' => array(
						'code'    => \$response->status_code,
						'message' => get_status_header_desc( \$response->status_code ),
					),
					'cookies'  => array(),
					'filename' => '',
				);
			}
		}

		return \$pre;
	},
	10,
	3
);
FILE;

		file_put_contents(
			$mock_file,
			$mock_file_contents
		);
	}

	/**
	 * @Given WP files
	 */
	public function given_wp_files() {
		$this->download_wp();
	}

	/**
	 * @Given wp-config.php
	 */
	public function given_wp_config_php() {
		$this->create_config();
	}

	/**
	 * @Given a database
	 */
	public function given_a_database() {
		$this->create_db();
	}

	/**
	 * @Given a WP install(ation)
	 */
	public function given_a_wp_installation() {
		$this->install_wp();
	}

	/**
	 * @Given a WP install(ation) in :subdir
	 */
	public function given_a_wp_installation_in_a_specific_folder( $subdir ) {
		$this->install_wp( $subdir );
	}

	/**
	 * @Given a WP install(ation) with Composer
	 */
	public function given_a_wp_installation_with_composer() {
		$this->install_wp_with_composer();
	}

	/**
	 * @Given a WP install(ation) with Composer and a custom vendor directory :vendor_directory
	 */
	public function given_a_wp_installation_with_composer_and_a_custom_vendor_folder( $vendor_directory ) {
		$this->install_wp_with_composer( $vendor_directory );
	}

	/**
	 * @Given /^a WP multisite (subdirectory|subdomain)?\s?(install|installation)$/
	 */
	public function given_a_wp_multisite_installation( $type = 'subdirectory' ) {
		$this->install_wp();
		$subdomains = ! empty( $type ) && 'subdomain' === $type ? 1 : 0;
		$this->proc(
			'wp core install-network',
			array(
				'title'      => 'WP CLI Network',
				'subdomains' => $subdomains,
			)
		)->run_check();
	}

	/**
	 * @Given these installed and active plugins:
	 */
	public function given_these_installed_and_active_plugins( $stream ) {
		$plugins = implode( ' ', array_map( 'trim', explode( PHP_EOL, (string) $stream ) ) );
		$plugins = $this->replace_variables( $plugins );

		$this->proc( "wp plugin install $plugins --activate" )->run_check();
	}

	/**
	 * @Given a custom wp-content directory
	 */
	public function given_a_custom_wp_directory() {
		$wp_config_path = $this->variables['RUN_DIR'] . '/wp-config.php';

		$wp_config_code = file_get_contents( $wp_config_path );

		$this->move_files( 'wp-content', 'my-content' );
		$this->add_line_to_wp_config(
			$wp_config_code,
			"define( 'WP_CONTENT_DIR', dirname(__FILE__) . '/my-content' );"
		);

		$this->move_files( 'my-content/plugins', 'my-plugins' );
		$this->add_line_to_wp_config(
			$wp_config_code,
			"define( 'WP_PLUGIN_DIR', __DIR__ . '/my-plugins' );"
		);

		$this->move_files( 'my-content/mu-plugins', 'my-mu-plugins' );
		$this->add_line_to_wp_config(
			$wp_config_code,
			"define( 'WPMU_PLUGIN_DIR', __DIR__ . '/my-mu-plugins' );"
		);

		file_put_contents( $wp_config_path, $wp_config_code );

		if ( 'sqlite' === self::$db_type ) {
			$db_dropin = $this->variables['RUN_DIR'] . '/my-content/db.php';

			/* similar to https://github.com/WordPress/sqlite-database-integration/blob/3306576c9b606bc23bbb26c15383fef08e03ab11/activate.php#L95 */
			$file_contents = str_replace(
				'mu-plugins/',
				'../my-mu-plugins/',
				file_get_contents( $db_dropin )
			);

			file_put_contents( $db_dropin, $file_contents );
		}
	}

	/**
	 * @Given download:
	 */
	public function given_a_download( TableNode $table ) {
		foreach ( $table->getHash() as $row ) {
			$path = $this->replace_variables( $row['path'] );
			if ( file_exists( $path ) ) {
				// Assume it's the same file and skip re-download.
				continue;
			}

			Process::create( Utils\esc_cmd( 'curl -sSL %s > %s', $row['url'], $path ) )->run_check();
		}
	}

	/**
	 * @Given /^save (STDOUT|STDERR) ([\'].+[^\'])?\s?as \{(\w+)\}$/
	 */
	public function given_saved_stdout_stderr( $stream, $output_filter, $key ) {
		$stream = strtolower( $stream );

		if ( $output_filter ) {
			$output_filter = '/' . trim( str_replace( '%s', '(.+[^\b])', $output_filter ), "' " ) . '/';
			if ( false !== preg_match( $output_filter, $this->result->$stream, $matches ) ) {
				$output = array_pop( $matches );
			} else {
				$output = '';
			}
		} else {
			$output = $this->result->$stream;
		}
		$this->variables[ $key ] = trim( $output, "\n" );
	}

	/**
	 * @Given /^a new Phar with (?:the same version|version "([^"]+)")$/
	 */
	public function given_a_new_phar_with_a_specific_version( $version = 'same' ) {
		$this->build_phar( $version );
	}

	/**
	 * @Given /^a downloaded Phar with (?:the same version|version "([^"]+)")$/
	 */
	public function given_a_downloaded_phar_with_a_specific_version( $version = 'same' ) {
		$this->download_phar( $version );
	}

	/**
	 * @Given /^save the (.+) file ([\'].+[^\'])?as \{(\w+)\}$/
	 */
	public function given_saved_a_specific_file( $filepath, $output_filter, $key ) {
		$full_file = file_get_contents( $this->replace_variables( $filepath ) );

		if ( $output_filter ) {
			$output_filter = '/' . trim( str_replace( '%s', '(.+[^\b])', $output_filter ), "' " ) . '/';
			if ( false !== preg_match( $output_filter, $full_file, $matches ) ) {
				$output = array_pop( $matches );
			} else {
				$output = '';
			}
		} else {
			$output = $full_file;
		}
		$this->variables[ $key ] = trim( $output, "\n" );
	}

	/**
	 * @Given a misconfigured WP_CONTENT_DIR constant directory
	 */
	public function given_a_misconfigured_wp_content_dir_constant_directory() {
		$wp_config_path = $this->variables['RUN_DIR'] . '/wp-config.php';

		$wp_config_code = file_get_contents( $wp_config_path );

		$this->add_line_to_wp_config(
			$wp_config_code,
			"define( 'WP_CONTENT_DIR', '' );"
		);

		file_put_contents( $wp_config_path, $wp_config_code );
	}

	/**
	 * @Given a dependency on current wp-cli
	 */
	public function given_a_dependency_on_wp_cli() {
		$this->composer_require_current_wp_cli();
	}

	/**
	 * @Given a PHP built-in web server
	 */
	public function given_a_php_built_in_web_server() {
		$this->start_php_server();
	}

	/**
	 * @Given a PHP built-in web server to serve :subdir
	 */
	public function given_a_php_built_in_web_server_to_serve_a_specific_folder( $subdir ) {
		$this->start_php_server( $subdir );
	}
}
