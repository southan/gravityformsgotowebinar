<?php

/**
 * Gravity Forms GoToWebinar API wrapper.
 */
class GF_GoToWebinar_API {

	/**
	 * Base GoToWebinar REST API URL.
	 *
	 * @var string
	 */
	protected $base_url = 'https://api.getgo.com/G2W/rest/v2';

	/**
	 * Base GoToWebinar oAuth URL.
	 *
	 * @var string
	 */
	protected $auth_url = 'https://api.getgo.com/oauth/v2';

	/**
	 * Default timeout.
	 *
	 * @var int
	 */
	protected $timeout = 30;

	/**
	 * Initialize GoToWebinar API library.
	 *
	 * @since 0.1
	 *
	 * @param array $config
	 */
	public function __construct( array $config = [] ) {

		foreach ( $config as $name => $value ) {
			$this->$name = $value;
		}

	}

	/**
	 * Set new API config.
	 *
	 * @since 0.1
	 *
	 * @param array $config
	 */
	public function init( array $config ) {

		$props = array_keys( get_object_vars( $this ) );

		$props = array_fill_keys( $props, null );

		$props = array_merge( $props, get_class_vars( __class__ ), $config );

		foreach ( $props as $name => $value ) {
			$this->$name = $value;
		}

	}

	/**
	 * Check if the API instance is *ready* to connect.
	 *
	 * Does *not* check authenticity of credentials.
	 *
	 * @since  0.1
	 *
	 * @return bool
	 */
	public function is_ready() {

		return $this->access_token || $this->client_id && $this->client_secret && $this->refresh_token;

	}

	/**
	 * Endpoint GET request.
	 *
	 * @since  0.1
	 *
	 * @param  string $endpoint
	 * @param  array  $params
	 * @param  array  $request
	 *
	 * @return array|object|WP_Error
	 */
	public function get( $endpoint, $params = [], $request = [] ) {

		return $this->request( "$this->base_url/$endpoint", array_merge( $request, [
			'method' => 'GET',
			'body' => $params,
		]));

	}

	/**
	 * Endpoint POST request.
	 *
	 * @since  0.1
	 *
	 * @param  string $endpoint
	 * @param  array  $params
	 * @param  array  $request
	 *
	 * @return array|object|WP_Error
	 */
	public function post( $endpoint, $params = [], $request = [] ) {

		return $this->request( "$this->base_url/$endpoint", array_merge( $request, [
			'method' => 'POST',
			'body' => json_encode( $params ),
		]));

	}

	/**
	 * Get authorization URL for currently configured OAuth client.
	 *
	 * @since  0.1
	 *
	 * @return string
	 */
	public function get_authorize_url() {

		return "$this->auth_url/authorize?" . http_build_query([
			'client_id'     => $this->client_id,
			'response_type' => 'code',
			'redirect_uri'  => $this->redirect_uri,
		]);

	}

	/**
	 * Create new OAuth token with authorization code.
	 *
	 * @since  0.1
	 *
	 * @param  string $authorization_code Defaults to token refresh.
	 *
	 * @return object|WP_Error
	 */
	public function create_token( $authorization_code ) {

		if ( ! $authorization_code ) {
			return new WP_Error( 'authorization_code', 'Authorisation code cannot be empty.' );
		}

		return $this->token([
			'redirect_uri' => $this->redirect_uri,
			'grant_type' => 'authorization_code',
			'code' => $authorization_code,
		]);

	}

	/**
	 * Refresh OAuth token.
	 *
	 * @since  0.1
	 *
	 * @return object|WP_Error
	 */
	public function refresh_token() {

		if ( ! $this->refresh_token ) {
			return new WP_Error( 'refresh_token', 'Refresh token required.' );
		}

		return $this->token([
			'grant_type' => 'refresh_token',
			'refresh_token' => $this->refresh_token,
		]);

	}

	/**
	 * Get (and set) OAuth token.
	 *
	 * @since  0.1
	 *
	 * @param  array $payload
	 *
	 * @return object|WP_Error
	 */
	public function token( $payload ) {

		if ( ! $this->client_id ) {
			return new WP_Error( 'client_id', 'Client ID required.' );
		}

		if ( ! $this->client_secret ) {
			return new WP_Error( 'client_secret', 'Client secret required.' );
		}

		$time_now = time();

		$token = $this->request( "$this->auth_url/token", [
			'method' => 'POST',
			'body' => $payload,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( "$this->client_id:$this->client_secret" ),
			],
		]);

		if ( ! is_wp_error( $token ) ) {
			$this->token_expires = $token->expires = $time_now + $token->expires_in;
			$this->access_token  = $token->access_token;
			$this->refresh_token = $token->refresh_token;
			$this->organizer_key = $token->organizer_key;
		}

		return $token;

	}

	/**
	 * Make API request.
	 *
	 * @since  0.1
	 *
	 * @param  string $url
	 * @param  array $request
	 *
	 * @return object|WP_Error
	 */
	public function request( $url, array $request = [] ) {

		$default_request = [
			'timeout' => $this->timeout,
		];

		$default_headers = [
			'Accept'        => 'application/json',
			'Authorization' => "Bearer $this->access_token",
		];

		$request['headers'] = array_merge( $default_headers, $request['headers'] ?? [] );

		$request = array_merge( $default_request, $request );

		$response = wp_remote_request( $url, $request );

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );

			$response = json_decode( $body ) ?: new WP_Error( 'invalid_response', $body );

			if ( isset( $response->error ) ) {
				$response = new WP_Error( $response->error, $response->error_description ?? '' );
			}
		}

		return $response;

	}

	/**
	 * Fallback for undefined API properties.
	 *
	 * @since  0.1
	 *
	 * @param  string $name
	 *
	 * @return mixed
	 */
	public function __get( $name ) {

		return $this->$name ?? null;

	}

}
