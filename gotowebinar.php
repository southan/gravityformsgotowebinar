<?php

/**
 * Plugin Name: Gravity Forms GoToWebinar Add-On
 * Plugin URI:  https://southan.dev
 * Description: Integrates Gravity Forms with GoToWebinar, allowing form submissions to register users for a webinar.
 * Version:     0.1
 * Author:      Alex Southan
 * Author URI:  https://southan.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

define( 'GF_GOTOWEBINAR_VERSION', '0.1' );

define( 'GF_GOTOWEBINAR_FILE', __file__ );

define( 'GF_GOTOWEBINAR_BASE', plugin_basename( GF_GOTOWEBINAR_FILE ) );

// If Gravity Forms is loaded, bootstrap the GoToWebinar Add-On.
add_action( 'gform_loaded', function () {

	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}

	GFForms::include_feed_addon_framework();

	require_once __dir__ . '/class-gf-gotowebinar.php';

	GFAddOn::register( 'GFGoToWebinar' );

}, 5 );
