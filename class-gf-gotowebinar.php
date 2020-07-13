<?php

class GFGoToWebinar extends GFFeedAddOn {

	/**
	 * GoToWebinar Add-On version.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_version = GF_GOTOWEBINAR_VERSION;

	/**
	 * Minimum Gravity Forms version required.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_min_gravityforms_version = '2.0';

	/**
	 * Add-On slug ID.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_slug = 'gravityformsgotowebinar';

	/**
	 * Add-On plugin basename.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_path = GF_GOTOWEBINAR_BASE;

	/**
	 * Add-on plugin filepath.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_full_path = GF_GOTOWEBINAR_FILE;

	/**
	 * Add-On title.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_title = 'Gravity Forms GoToWebinar Add-On';

	/**
	 * Add-On short title.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_short_title = 'GoToWebinar';

	/**
	 * All capabilities possibly required for this Add-On.
	 *
	 * @since  0.1
	 * @var    array $_capabilities
	 */
	protected $_capabilities = [
		'gravityforms_gotowebinar',
		'gravityforms_gotowebinar_uninstall',
	];

	/**
	 * Capability to access the Add-On settings page.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_capabilities_settings_page = 'gravityforms_gotowebinar';

	/**
	 * Capability to access the Add-On form settings page.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_capabilities_form_settings = 'gravityforms_gotowebinar';

	/**
	 * Capability to uninstall the Add-On.
	 *
	 * @since  0.1
	 * @var    string
	 */
	protected $_capabilities_uninstall = 'gravityforms_gotowebinar_uninstall';

	/**
	 * Single instance of this class.
	 *
	 * @since  0.1
	 * @var    GFGoToWebinar $_instance
	 */
	private static $_instance;

	/**
	 * Single instance of the GoToWebinar API library.
	 *
	 * @since  0.1
	 * @var    GF_GoToWebinar_API $api
	 */
	protected $api;

	/**
	 * Get instance of this class.
	 *
	 * @since  0.1
	 *
	 * @return GF_GoToWebinar
	 */
	public static function get_instance() {

		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		return self::$_instance;

	}

	/**
	 * Register delayed payment support and schedule API token renewal (daily).
	 *
	 * @since 0.1
	 */
	public function init() {

		parent::init();

		$this->add_delayed_payment_support([
			'option_label' => esc_html__( 'Create webinar registrant only when payment is received.', 'gravityformsgotowebinar' )
		]);

		if ( ! wp_next_scheduled( 'gform_gotowebinar_refresh_access_token' ) ) {
			wp_schedule_event( time(), 'daily', 'gform_gotowebinar_refresh_access_token' );
		}

		add_action( 'gform_gotowebinar_refresh_access_token', [ $this, 'refresh_access_token' ] );

	}

	/**
	 * Allow feeds if API is configured.
	 *
	 * @since  0.1
	 *
	 * @return bool
	 */
	public function can_create_feed() {

		return $this->api()->is_ready();

	}

	/**
	 * Register the form submission with GoToWebinar.
	 *
	 * @since  0.1
	 *
	 * @param  array $feed  The feed object to be processed.
	 * @param  array $entry The entry object currently being processed.
	 * @param  array $form  The form object currently being processed.
	 *
	 * @return bool|void
	 */
	public function process_feed( $feed, $entry, $form ) {

		try {

			$webinar_id = rgars( $feed, 'meta/webinarId' );

			$webinar_id = GFCommon::replace_variables( $webinar_id, $form, $entry, false, false, false, 'text' );

			if ( ! $webinar_id ) {
				throw new Exception( __( 'Webinar ID required.', 'gravityformsgotowebinar' ) );
			}

			$fields = [];

			$field_map = $this->get_field_map_fields( $feed, 'fieldMap' );

			$field_map = array_filter( $field_map );

			foreach ( $field_map as $name => $field_id ) {
				$fields[ $name ] = $this->get_field_value( $form, $entry, $field_id );
			}

			/**
			 * Filter the registration API payload data.
			 *
			 * @param array $fields
			 * @param array $form
			 */
			$fields = apply_filters( 'gravityformsgotowebinar_registration_fields', $fields, $form );

			$api = $this->api();

			if ( $api->token_expires <= time() ) {
				$this->refresh_access_token();
			}

			$response = $api->post(
				"organizers/$api->organizer_key/webinars/$webinar_id/registrants?resendConfirmation=true",
				$fields,
				[
					'headers' => [
						'Accept' => 'application/vnd.citrix.g2wapi-v1.1+json',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			$this->log_debug( __method__ . '(): ' . json_encode( $response, JSON_PRETTY_PRINT ) );

		} catch ( Exception $e ) {

			$this->add_feed_error( $e->getMessage(), $feed, $entry, $form );

		}

	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @since  0.1
	 *
	 * @return array
	 */
	public function feed_list_columns() {

		return [
			'feedName'  => esc_html__( 'Name', 'gravityformsgotowebinar' ),
			'webinarId' => esc_html__( 'Webinar ID', 'gravityformsgotowebinar' ),
		];

	}

	/**
	 * Get configured webinarId value for feed.
	 *
	 * @since  0.1
	 *
	 * @param  array $feed
	 *
	 * @return array
	 */
	public function get_column_value_webinarid( $feed ) {

		return rgars( $feed, 'meta/webinarId' );

	}

	/**
	 * Feed settings fields.
	 *
	 * @since  0.1
	 *
	 * @return array
	 */
	public function feed_settings_fields() {

		$field_map = [];

		foreach ( $this->get_webinar_fields() as $name => $label ) {
			$field_map[] = [
				'name'  => $name,
				'label' => esc_html( $label ),
			];
		}

		$fields = [
			[
				'name'    => 'feedName',
				'type'    => 'text',
				'label'   => esc_html__( 'Name', 'gravityformsgotowebinar' ),
				'class'   => 'medium',
				'tooltip' => esc_html__( '<h6>Name</h6> Enter a feed name to uniquely identify this setup.', 'gravityformsgotowebinar' ),
			],

			[
				'name'    => 'webinarId',
				'type'    => 'text',
				'label'   => esc_html__( 'Webinar ID', 'gravityformsgotowebinar' ),
				'class'   => 'medium merge-tag-support',
				'tooltip' => esc_html__( '<h6>Webinar ID</h6> The unique ID of the webinar. Enter directly here for a specific webinar or use merge tags to populate the ID dynamically.', 'gravityformsgotowebinar' ),
			],

			[
				'name'      => 'fieldMap',
				'type'      => 'field_map',
				'label'     => esc_html__( 'Map Fields', 'gravityformsgotowebinar' ),
				'field_map' => $field_map,
			],

			[
				'name'           => 'feedCondition',
				'type'           => 'feed_condition',
				'label'          => esc_html__( 'Conditional Logic', 'gravityformsgotowebinar' ),
				'checkbox_label' => esc_html__( 'Enable conditional logic', 'gravityformsgotowebinar' ),
				'tooltip'        => esc_html__( '<h6>Conditional Logic</h6> Restrict when form submissions are registered with GoToWebinar.', 'gravityformsgotowebinar' ),
			],
		];

		return [
			[
				'fields' => $fields,
			]
		];

	}

	/**
	 * Get mappable webinar fields.
	 *
	 * @since  0.1
	 *
	 * @return array
	 */
	public function get_webinar_fields() {

		return [
			'firstName'            => __( 'First Name', 'gravityformsgotowebinar' ),
			'lastName'             => __( 'Last Name', 'gravityformsgotowebinar' ),
			'email'                => __( 'Email', 'gravityformsgotowebinar' ),
			'source'               => __( 'Source', 'gravityformsgotowebinar' ),
			'address'              => __( 'Address', 'gravityformsgotowebinar' ),
			'city'                 => __( 'City', 'gravityformsgotowebinar' ),
			'state'                => __( 'State', 'gravityformsgotowebinar' ),
			'zipCode'              => __( 'ZIP Code', 'gravityformsgotowebinar' ),
			'country'              => __( 'Country', 'gravityformsgotowebinar' ),
			'phone'                => __( 'Phone', 'gravityformsgotowebinar' ),
			'organization'         => __( 'Organization', 'gravityformsgotowebinar' ),
			'jobTitle'             => __( 'Job Title', 'gravityformsgotowebinar' ),
			'questionsAndComments' => __( 'Questions & Comments', 'gravityformsgotowebinar' ),
			'industry'             => __( 'Industry', 'gravityformsgotowebinar' ),
			'numberOfEmployees'    => __( 'Number of Employees', 'gravityformsgotowebinar' ),
			'purchasingTimeFrame'  => __( 'Purchasing Time Frame', 'gravityformsgotowebinar' ),
			'purchasingRole'       => __( 'Purchasing Role', 'gravityformsgotowebinar' ),
		];

	}

	/**
	 * Register GoToWebinar settings.
	 *
	 * @since  0.1
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {

		if ( $this->api()->is_ready() ) {

			$description = sprintf(
				__( 'You are connected to client <strong>%s</strong>.', 'gravityformsgotowebinar' ),
				esc_html( $this->api()->client_id )
			);

			$fields = [
				[
					'name' => 'client_id',
					'type' => 'hidden',
				],

				[
					'name' => 'client_secret',
					'type' => 'hidden',
				],

				[
					'type'     => 'save',
					'value'    => __( 'Disconnect', 'gravityformsgotowebinar' ),
					'messages' => [
						'success' => sprintf(
							__( 'Successfully disconnected GoToWebinar client.', 'gravityformsgotowebinar' )
						),
					],
				],
			];

		} else {

			$description = __( 'Connect your LogMeIn OAuth client to Gravity Forms. If you don\'t have a client yet you can <a href="https://developer.logmeininc.com/clients" target="_blank">create one here</a>.','gravityformsgotowebinar' );

			$fields = [
				[
					'name'     => 'redirect_uri',
					'type'     => 'text',
					'label'    => __( 'Redirect URI', 'gravityformsgotowebinar' ),
					'class'    => 'large',
					'value'    => $this->api()->redirect_uri,
					'readonly' => true,
					'onclick'  => 'this.select();',
					'tooltip'  => __( '<h6>Redirect URI</h6>Ensure you have added this value to your client.', 'gravityformsgotowebinar' ),
				],

				[
					'name'     => 'client_id',
					'type'     => 'text',
					'required' => true,
					'label'    => __( 'Client ID', 'gravityformsgotowebinar' ),
					'class'    => 'medium code',
				],

				[
					'name'     => 'client_secret',
					'type'     => 'text',
					'required' => true,
					'label'    => __( 'Client Secret', 'gravityformsgotowebinar' ),
					'class'    => 'medium code',
				],

				[
					'name'     => '',
					'type'     => 'save',
					'value'    => __( 'Connect', 'gravityformsgotowebinar' ),
					'messages' => [
						'success' => sprintf(
							__( 'Almost there! Now authorise this client with your LogMeIn account.', 'gravityformsgotowebinar' )
						),
						'error' => __( 'Please ensure you have entered a valid client ID and client secret.', 'gravityformsgotowebinar' ),
					],

					'save_callback' => [ $this, 'pre_authorize_client' ],
				],
			];

		}

		return [[
			'description' => "<p>$description</p>",
			'fields' => $fields,
		]];
	}

	/**
	 * Initiates the client authorization settings stage.
	 *
	 * Callback for save field, runs immediately after client settings have been saved.
	 *
	 * @since 0.1
	 * @see   self::render_settings()
	 */
	public function pre_authorize_client() {

		$this->pre_authorize_client = true;

	}

	/**
	 * @since 0.1
	 * @see   self::pre_authorize_client()
	 */
	public function render_settings( $sections ) {

		if ( ! empty( $this->pre_authorize_client ) ) {

			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				$this->api()->get_authorize_url(),
				__( 'Authorize Client', 'gravityformsgotowebinar' )
			);

		} else {

			parent::render_settings( $sections );

		}

	}

	/**
	 * Create access token after redirect from client authorization.
	 *
	 * @since 0.1
	 * @see   self::render_settings()
	 */
	public function plugin_settings_page() {

		if ( ! $this->is_save_postback() && ! $this->api()->is_ready() && rgget( 'code' ) ) {
			$token = $this->create_access_token( rgget( 'code' ) );

			if ( is_wp_error( $token ) ) {
				GFCommon::add_error_message( sprintf( __( 'Failed to connect; %s', 'gravityformsgotowebinar' ), $token->get_error_message() ) );
			}
		}

		parent::plugin_settings_page();

	}

	/**
	 * Get plugin settings instance of GoToWebinar API.
	 *
	 * @since  0.1
	 *
	 * @return GF_GoToWebinar_API
	 */
	public function api() {

		if ( ! isset( $this->api ) ) {
			if ( ! class_exists( 'GF_GoToWebinar_API' ) ) {
				require_once __dir__ . '/includes/class-gf-gotowebinar-api.php';
			}

			$this->api = new GF_GoToWebinar_API;
		}

		$settings = $this->get_plugin_settings() ?: [];

		$settings['redirect_uri'] = $this->get_plugin_settings_url();

		$this->api->init( $settings );

		return $this->api;

	}

	/**
	 * Create new access token using authorization code.
	 *
	 * @since  0.1
	 *
	 * @param  string $code
	 *
	 * @return bool
	 */
	public function create_access_token( $code ) {

		$token = $this->api()->create_token( $code );

		if ( ! is_wp_error( $token ) ) {
			$this->log_debug( __method__ . '(): Created new access token.' );

			$this->save_api();
		} else {
			$this->log_error( __method__ . '(): Failed to create new access token; ' . $token->get_error_message() );
		}

		return $token;

	}

	/**
	 * Refresh existing access token.
	 *
	 * @since  0.1
	 *
	 * @return object|WP_Error
	 */
	public function refresh_access_token() {

		$token = $this->api()->refresh_token();

		if ( ! is_wp_error( $token ) ) {
			$this->log_debug( __method__ . '(): Refreshed access token.' );

			$this->save_api();
		} else {
			$this->log_error( __method__ . '(): Failed to refresh access token; ' . $token->get_error_message() );
		}

		return $token;

	}

	/**
	 * Save API state.
	 *
	 * @since  0.1
	 */
	private function save_api() {

		$this->update_plugin_settings([
			'client_id'     => $this->api->client_id,
			'client_secret' => $this->api->client_secret,
			'token_expires' => $this->api->token_expires,
			'access_token'  => $this->api->access_token,
			'refresh_token' => $this->api->refresh_token,
			'organizer_key' => $this->api->organizer_key,
		]);

	}

}
