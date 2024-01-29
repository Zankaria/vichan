<?php // Verify captchas server side.

require_once('inc/driver/http-driver.php');


class RemoteCaptchaQuery {
	private $http;
	private $secret;
	private $endpoint;


	/**
	 * Creates a new CaptchaRemoteQueries instance using the google recaptcha service.
	 *
	 * @param HttpDriver $http The http client.
	 * @param string $secret Server side secret.
	 * @return CaptchaRemoteQueries A new captcha query instance.
	 */
	public static function with_recaptcha($http, $secret) {
		return new self($http, $secret, 'https://www.google.com/recaptcha/api/siteverify');
	}

	/**
	 * Creates a new CaptchaRemoteQueries instance using the hcaptcha service.
	 *
	 * @param HttpDriver $http The http client.
	 * @param string $secret Server side secret.
	 * @return CaptchaRemoteQueries A new captcha query instance.
	 */
	public static function with_hcaptcha($http, $secret) {
		return new self($http, $secret, 'https://hcaptcha.com/siteverify');
	}

	private function __construct($http, $secret, $endpoint) {
		$this->http = $http;
		$this->secret = $secret;
		$this->endpoint = $endpoint;
	}

	/**
	 * Checks if the user at the remote ip passed the captcha.
	 *
	 * @param string $response User provided response.
	 * @param string $remote_ip User ip.
	 * @return bool Returns true if the user passed the captcha.
	 * @throws Exception Throws on internal error.
	 */
	public function verify($response, $remote_ip) {
		$data = array(
			'secret' => $this->secret,
			'response' => $response,
			'remoteip' => $remote_ip
		);

		$ret = $this->http->send_post($this->endpoint, $data);
		$resp = json_decode($ret, true, 5, JSON_THROW_ON_ERROR);

		return !!$resp['success'];
	}
}

class NativeCaptchaQuery {
	private $http;
	private $domain;
	private $provider_check;


	/**
	 * @param HttpDriver $http The http client.
	 * @param string $domain The server's domain.
	 * @param string $provider_check Path to the endpoint.
	 */
	function __construct($http, $domain, $provider_check) {
		$this->http = $http;
		$this->domain = $domain;
		$this->provider_check = $provider_check;
	}

	/**
	 * Checks if the user at the remote ip passed the native vichan captcha.
	 *
	 * @param string $extra Extra http parameters.
	 * @param string $user_text Remote user's text input.
	 * @param string $user_cookie Remote user cookie.
	 * @return bool Returns true if the user passed the check.
	 * @throws Exception Throws on internal errors.
	 */
	public function with_native($extra, $user_text, $user_cookie) {
		$data = array(
			'mode' => 'check',
			'text' => $user_text,
			'extra' => $extra,
			'cookie' => $user_cookie
		);

		$ret = $this->http->send_get($this->domain . '/' . $this->provider_check, $data);
		return $ret === '1';
	}
}
