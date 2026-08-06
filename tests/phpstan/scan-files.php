<?php

namespace SebastianBergmann\CodeCoverage\Driver {
 class Xdebug {
 }
}

namespace {
	class Requests_Exception extends WpOrg\Requests\Exception {
	}

	class Requests_Response extends WpOrg\Requests\Response {
	}
}

namespace WpOrg\Requests {
	class Exception extends \Exception {
    /**
     * Type of exception
     *
     * @var string
     */
    protected $type;

    /**
     * Data associated with the exception
     *
     * @var mixed
     */
    protected $data;
    
		/**
		 * Like {@see \Exception::getCode()}, but a string code.
		 *
		 * @return string
		 */
		public function getType() {
      return $this->type;
		}

		/**
		 * Gives any relevant data
		 *
		 * @return mixed
		 */
		public function getData() {
      return $this->data;
		}
	}

	class Response {
		/**
		 * Response body
		 *
		 * @var string
		 */
		public $body = '';

		/**
		 * Raw HTTP data from the transport
		 *
		 * @var string
		 */
		public $raw = '';

		/**
		 * Headers, as an associative array
		 *
		 * @var \WpOrg\Requests\Response\Headers Array-like object representing headers
		 */
		public $headers;

		/**
		 * Status code, false if non-blocking
		 *
		 * @var integer|boolean
		 */
		public $status_code = false;

		/**
		 * Protocol version, false if non-blocking
		 *
		 * @var float|boolean
		 */
		public $protocol_version = false;

		/**
		 * Whether the request succeeded or not
		 *
		 * @var boolean
		 */
		public $success = false;

		/**
		 * Number of redirects the request used
		 *
		 * @var integer
		 */
		public $redirects = 0;

		/**
		 * URL requested
		 *
		 * @var string
		 */
		public $url = '';

		/**
		 * Previous requests (from redirects)
		 *
		 * @var array<\WpOrg\Requests\Response> Array of \WpOrg\Requests\Response objects
		 */
		public $history = [];

		/**
		 * Cookies from the request
		 *
		 * @var array<string, mixed> Array-like object representing a cookie jar
		 */
		public $cookies = [];
	}
}