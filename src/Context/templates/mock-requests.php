<?php
/**
 * HTTP request mocking supporting both Requests v1 and v2.
 *
 * This file is copied verbatim into the test run directory by
 * `WP_CLI\Tests\Context\GivenStepDefinitions::given_a_request_to_a_url_respond_with_file()`.
 * The mocked responses themselves are generated next to it as `mock-requests-data.php`.
 */

trait WP_CLI_Tests_Mock_Requests_Trait {
	public function request( $url, $headers = array(), $data = array(), $options = array() ) {
		$mocked_requests = require __DIR__ . '/mock-requests-data.php';

		foreach ( $mocked_requests as $pattern => $response ) {
			$pattern = '/' . preg_quote( $pattern, '/' ) . '/';
			if ( 1 === preg_match( $pattern, $url ) ) {
				$pos = strpos( $response, "\n\n" );
				if ( false !== $pos ) {
					$response = substr( $response, 0, $pos ) . "\r\n\r\n" . substr( $response, $pos + 2 );
				}
				if ( ! empty( $options['filename'] ) ) {
					$body     = '';
					$body_pos = strpos( $response, "\r\n\r\n" );
					if ( false !== $body_pos ) {
						$body = substr( $response, $body_pos + 4 );
					}
					file_put_contents( $options['filename'], $body );
				}
				return $response;
			}
		}

		if ( class_exists( '\WpOrg\Requests\Transport\Curl' ) ) {
			return ( new \WpOrg\Requests\Transport\Curl() )->request( $url, $headers, $data, $options );
		}

		return ( new \Requests_Transport_cURL() )->request( $url, $headers, $data, $options );
	}

	public function request_multiple( $requests, $options ) {
		throw new Exception( 'Method not implemented: ' . __METHOD__ );
	}

	public static function test( $capabilities = array() ) {
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
	static function ( $options ) {
		$options['transport'] = new WP_CLI_Tests_Mock_Requests_Transport();
		return $options;
	}
);

WP_CLI::add_wp_hook(
	'pre_http_request',
	static function ( $pre, $parsed_args, $url ) {
		$mocked_requests = require __DIR__ . '/mock-requests-data.php';

		foreach ( $mocked_requests as $pattern => $response ) {
			$pattern = '/' . preg_quote( $pattern, '/' ) . '/';
			if ( 1 === preg_match( $pattern, $url ) ) {
				$pos = strpos( $response, "\n\n" );
				if ( false !== $pos ) {
					$response = substr( $response, 0, $pos ) . "\r\n\r\n" . substr( $response, $pos + 2 );
				}

				if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
					WpOrg\Requests\Requests::parse_multiple(
						$response,
						array(
							'url'     => $url,
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
						$response,
						array(
							'url'     => $url,
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

				if ( ! empty( $parsed_args['filename'] ) ) {
					file_put_contents( $parsed_args['filename'], $response->body );
				}

				return array(
					'headers'  => $response->headers->getAll(),
					'body'     => $response->body,
					'response' => array(
						'code'    => $response->status_code,
						'message' => get_status_header_desc( $response->status_code ),
					),
					'cookies'  => array(),
					'filename' => '',
				);
			}
		}

		return $pre;
	},
	10,
	3
);
