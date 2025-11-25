<?php

namespace WP_CLI\Tests\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use RuntimeException;
use WP_CLI\Process;
use WP_CLI\Utils;

trait GivenStepDefinitions {

	/**
	 * Creates an empty directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given an empty directory
	 */
	public function given_an_empty_directory(): void {
		$this->create_run_dir();
	}

	/**
	 * Creates or deletes a specific directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty foo-plugin directory
	 *   And a non-existent bar-plugin directory
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^an? (empty|non-existent) ([^\s]+) directory$/
	 *
	 * @param string $empty_or_nonexistent
	 * @param string $dir
	 */
	public function given_a_specific_directory( $empty_or_nonexistent, $dir ): void {
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

		self::remove_dir( $dir );
		if ( 'empty' === $empty_or_nonexistent ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}
	}

	/**
	 * Clears the WP-CLI cache directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty cache
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given an empty cache
	 */
	public function given_an_empty_cache(): void {
		$this->variables['SUITE_CACHE_DIR'] = FeatureContext::create_cache_dir();
	}

	/**
	 * Creates a file with the given contents.
	 *
	 * The file can be created either in the current working directory
	 * or in the cache directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a wp-cli.yml file:
	 *     """
	 *     @foo:
	 *       path: foo
	 *       user: admin
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^an? ([^\s]+) (file|cache file):$/
	 *
	 * @param string $path
	 * @param string $type
	 * @param PyStringNode $content
	 */
	public function given_a_specific_file( $path, $type, PyStringNode $content ): void {
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
	 * Search and replace a string in a file using regex.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given "Foo" replaced with "Bar" in the readme.html file
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^"([^"]+)" replaced with "([^"]+)" in the ([^\s]+) file$/
	 *
	 * @param string $search
	 * @param string $replace
	 * @param string $path
	 */
	public function given_string_replaced_with_string_in_a_specific_file( $search, $replace, $path ): void {
		$full_path = $this->variables['RUN_DIR'] . "/$path";
		$contents  = file_get_contents( $full_path );
		$contents  = str_replace( $search, $replace, $contents );
		file_put_contents( $full_path, $contents );
	}

	/**
	 * Mock HTTP requests to a given URL.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given that HTTP requests to https://api.github.com/repos/wp-cli/wp-cli/releases?per_page=100 will respond with:
	 *     """
	 *     HTTP/1.1 200
	 *     Content-Type: application/json
	 *
	 *     { "foo": "bar" }
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^that HTTP requests to (.*?) will respond with:$/
	 *
	 * @param string $url_or_pattern
	 * @param PyStringNode $content
	 */
	public function given_a_request_to_a_url_respond_with_file( $url_or_pattern, PyStringNode $content ): void {
		if ( ! isset( $this->variables['RUN_DIR'] ) ) {
			$this->create_run_dir();
		}

		$config_file = $this->variables['RUN_DIR'] . '/wp-cli.yml';
		$mock_file   = $this->variables['RUN_DIR'] . '/mock-requests.php';
		$dir         = dirname( $config_file );

		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0777, true /*recursive*/ );
		}

		$config_file_contents = <<<'FILE'
require:
    - mock-requests.php
FILE;

		file_put_contents(
			$config_file,
			$config_file_contents
		);

		$this->mocked_requests[ $url_or_pattern ] = (string) $content;

		$mocked_requests = var_export( $this->mocked_requests, true /* return */ );

		$mock_file_contents = <<<FILE
<?php
/**
 * HTTP request mocking supporting both Requests v1 and v2.
 */

trait WP_CLI_Tests_Mock_Requests_Trait {
	public function request( \$url, \$headers = array(), \$data = array(), \$options = array() ) {
		\$mocked_requests = $mocked_requests;

		foreach ( \$mocked_requests as \$pattern => \$response ) {
			\$pattern = '/' . preg_quote( \$pattern, '/' ) . '/';
			if ( 1 === preg_match( \$pattern, \$url ) ) {
				\$pos = strpos( \$response, "\\n\\n");
				if ( false !== \$pos ) {
					\$response = substr( \$response, 0, \$pos ) . "\\r\\n\\r\\n" . substr( \$response, \$pos + 2 );
				}
				return \$response;
			}
		}

		if ( class_exists( '\WpOrg\Requests\Transport\Curl' ) ) {
			return ( new \WpOrg\Requests\Transport\Curl() )->request( \$url, \$headers, \$data, \$options );
		}

		return ( new \Requests_Transport_cURL() )->request( \$url, \$headers, \$data, \$options );
	}

	public function request_multiple( \$requests, \$options ) {
		throw new Exception( 'Method not implemented: ' . __METHOD__ );
	}

	public static function test( \$capabilities = array() ) {
		return true;
	}
}

if ( interface_exists( '\WpOrg\Requests\Transport' ) ) {
	class WP_CLI_Tests_Mock_Requests_Transport implements \WpOrg\Requests\Transport {
		use WP_CLI_Tests_Mock_Requests_Trait;
	}
} else {
	class WP_CLI_Tests_Mock_Requests_Transport implements \Requests_Transport {
		use WP_CLI_Tests_Mock_Requests_Trait;
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
			\$pattern = '/' . preg_quote( \$pattern, '/' ) . '/';
			if ( 1 === preg_match( \$pattern, \$url ) ) {
				\$pos = strpos( \$response, "\n\n");
				if ( false !== \$pos ) {
					\$response = substr( \$response, 0, \$pos ) . "\r\n\r\n" . substr( \$response, \$pos + 2 );
				}

				if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
					WpOrg\Requests\Requests::parse_multiple(
						\$response,
						array(
							'url'     => \$url,
							'headers' => array(),
							'data'    => array(),
							'options' => array_merge(
								WpOrg\Requests\Requests::OPTION_DEFAULTS,
								array(
									'hooks' => new WpOrg\Requests\Hooks(),
								)
							),
						)
					);
				} else {
					\Requests::parse_multiple(
						\$response,
						array(
							'url'     => \$url,
							'headers' => array(),
							'data'    => array(),
							'options' => array(
								'blocking'         => true,
								'filename'         => false,
								'follow_redirects' => true,
								'redirected'       => 0,
								'redirects'        => 10,
								'hooks'            => new Requests_Hooks(),
							),
						)
					);
				}

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
	 * Download WordPress files without installing.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   And WP files
	 * ```
	 *
	 * @access public
	 *
	 * @Given WP files
	 */
	public function given_wp_files(): void {
		$this->download_wp();
	}

	/**
	 * Create a wp-config.php file using `wp config create`.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   And WP files
	 *   And wp-config.php
	 * ```
	 *
	 * @access public
	 *
	 * @Given wp-config.php
	 */
	public function given_wp_config_php(): void {
		$this->create_config();
	}

	/**
	 * Creates an empty database.
	 *
	 * Has no effect when tests run with SQLite.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a database
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given a database
	 */
	public function given_a_database(): void {
		$this->create_db();
	}

	/**
	 * Installs WordPress.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   ...
	 *
	 * Scenario: My other scenario
	 *   Given a WP install
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given a WP install(ation)
	 */
	public function given_a_wp_installation(): void {
		$this->install_wp();
	}

	/**
	 * Installs WordPress in a given directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation in 'foo'
	 *   ...
	 *
	 * Scenario: My other scenario
	 *   Given a WP install in 'bar'
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given a WP install(ation) in :subdir
	 *
	 * @param string $subdir
	 */
	public function given_a_wp_installation_in_a_specific_folder( $subdir ): void {
		$this->install_wp( $subdir );
	}

	/**
	 * Installs WordPress with Composer.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation with Composer
	 *   ...
	 *
	 * Scenario: My other scenario
	 *   Given a WP install with Composer
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given a WP install(ation) with Composer
	 */
	public function given_a_wp_installation_with_composer(): void {
		$this->install_wp_with_composer();
	}

	/**
	 * Installs WordPress with Composer and a custom vendor directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation with Composer and a custom vendor directory 'vendor-custom'
	 *   ...
	 *
	 * Scenario: My other scenario
	 *   Given a WP install with Composer with Composer and a custom vendor directory 'vendor-custom'
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given a WP install(ation) with Composer and a custom vendor directory :vendor_directory
	 *
	 * @param string $vendor_directory
	 */
	public function given_a_wp_installation_with_composer_and_a_custom_vendor_folder( $vendor_directory ): void {
		$this->install_wp_with_composer( $vendor_directory );
	}

	/**
	 * Installs WordPress Multisite.
	 *
	 * Supports either subdirectory or subdomain installation.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP multisite subdomain installation
	 *   ...
	 *
	 * Scenario: My other scenario
	 *   Given a WP subdirectory install
	 *   ...
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^a WP multisite (subdirectory|subdomain)?\s?(install|installation)$/
	 *
	 * @param string $type Multisite installation type.
	 */
	public function given_a_wp_multisite_installation( $type = 'subdirectory' ): void {
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
	 * Installs and activates one or more plugins.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   And these installed and active plugins:
	 *     """
	 *     akismet
	 *     wordpress-importer
	 *     """
	 * ```
	 *
	 * @access public
	 *
	 * @Given these installed and active plugins:
	 *
	 * @param string $stream
	 */
	public function given_these_installed_and_active_plugins( $stream ): void {
		$plugins = implode( ' ', array_map( 'trim', explode( PHP_EOL, (string) $stream ) ) );
		$plugins = $this->replace_variables( $plugins );

		$this->proc( "wp plugin install $plugins --activate" )->run_check();
	}

	/**
	 * Configure a custom `wp-content` directory.
	 *
	 * Defines the `WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, and `WPMU_PLUGIN_DIR` constants.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP install
	 *   And a custom wp-content directory
	 * ```
	 *
	 * @access public
	 *
	 * @Given a custom wp-content directory
	 */
	public function given_a_custom_wp_directory(): void {
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
	 * Download multiple files into the given destinations.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given download:
	 *     | path                | url                                   |
	 *     | {CACHE_DIR}/foo.jpg | https://example.com/foo.jpg           |
	 *     | {CACHE_DIR}/bar.png | https://example.com/another-image.png |
	 * ```
	 *
	 * @access public
	 *
	 * @Given download:
	 */
	public function given_a_download( TableNode $table ): void {
		foreach ( $table->getHash() as $row ) {
			$path = $this->replace_variables( $row['path'] );
			if ( file_exists( $path ) ) {
				// Assume it's the same file and skip re-download.
				continue;
			}

			$response = Utils\http_request( 'GET', $row['url'], null, [], [ 'filename' => $path ] );
			if ( 200 !== $response->status_code ) {
				throw new RuntimeException( "Could not download file (HTTP code {$response->status_code})" );
			}
		}
	}

	/**
	 * Store STDOUT or STDERR contents in a variable.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   When I run `wp package path`
	 *   Then save STDOUT as {PACKAGE_PATH}
	 *
	 * Scenario: My other scenario
	 *   When I run `wp core download`
	 *   Then save STDOUT 'Downloading WordPress ([\d\.]+)' as {VERSION}
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^save (STDOUT|STDERR) ([\'].+[^\'])?\s?as \{(\w+)\}$/
	 *
	 * @param string $stream
	 * @param string $output_filter
	 * @param string $key
	 */
	public function given_saved_stdout_stderr( $stream, $output_filter, $key ): void {
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
	 * Build a new WP-CLI Phar file with a given version.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   And a new Phar with version "2.11.0"
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^a new Phar with (?:the same version|version "([^"]+)")$/
	 *
	 * @param string $version
	 */
	public function given_a_new_phar_with_a_specific_version( $version = 'same' ): void {
		$this->build_phar( $version );
	}

	/**
	 * Download a specific WP-CLI Phar version from GitHub.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given an empty directory
	 *   And a downloaded Phar with version "2.11.0"
	 *
	 * Scenario: My other scenario
	 *   Given an empty directory
	 *   And a downloaded Phar with the same version
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^a downloaded Phar with (?:the same version|version "([^"]+)")$/
	 *
	 * @param string $version
	 */
	public function given_a_downloaded_phar_with_a_specific_version( $version = 'same' ): void {
		$this->download_phar( $version );
	}

	/**
	 * Stores the contents of the given file in a variable.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation with Composer
	 *   And save the {RUN_DIR}/composer.json file as {COMPOSER_JSON}
	 * ```
	 *
	 * @access public
	 *
	 * @Given /^save the (.+) file ([\'].+[^\'])?as \{(\w+)\}$/
	 *
	 * @param string $filepath
	 * @param string $output_filter
	 * @param string $key
	 */
	public function given_saved_a_specific_file( $filepath, $output_filter, $key ): void {
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
	 * Modify wp-config.php to set `WP_CONTENT_DIR` to an empty string.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP install
	 *   And a misconfigured WP_CONTENT_DIR constant directory
	 *  ```
	 *
	 * @access public
	 *
	 * @Given a misconfigured WP_CONTENT_DIR constant directory
	 */
	public function given_a_misconfigured_wp_content_dir_constant_directory(): void {
		$wp_config_path = $this->variables['RUN_DIR'] . '/wp-config.php';

		$wp_config_code = file_get_contents( $wp_config_path );

		$this->add_line_to_wp_config(
			$wp_config_code,
			"define( 'WP_CONTENT_DIR', '' );"
		);

		file_put_contents( $wp_config_path, $wp_config_code );
	}

	/**
	 * Add `wp-cli/wp-cli` as a Composer dependency.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation with Composer
	 *   And a dependency on current wp-cli
	 * ```
	 *
	 * @access public
	 *
	 * @Given a dependency on current wp-cli
	 */
	public function given_a_dependency_on_wp_cli(): void {
		$this->composer_require_current_wp_cli();
	}

	/**
	 * Start a PHP built-in web server in the current directory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   And a PHP built-in web server
	 * ```
	 *
	 * @access public
	 *
	 * @Given a PHP built-in web server
	 */
	public function given_a_php_built_in_web_server(): void {
		$this->start_php_server();
	}

	/**
	 * Start a PHP built-in web server in the given subdirectory.
	 *
	 * ```
	 * Scenario: My example scenario
	 *   Given a WP installation
	 *   And a PHP built-in web server to serve 'WordPress'
	 * ```
	 *
	 * @access public
	 *
	 * @Given a PHP built-in web server to serve :subdir
	 *
	 * @param string $subdir
	 */
	public function given_a_php_built_in_web_server_to_serve_a_specific_folder( $subdir ): void {
		$this->start_php_server( $subdir );
	}
}
