<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Admin {

	const SLUG = 'wp-control';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function menu() {
		self::$hook = add_menu_page(
			__( 'WP Control', 'wp-control-manager' ),
			__( 'WP Control', 'wp-control-manager' ),
			WPCM_CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			3
		);
	}

	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->id === self::$hook ) {
			$classes .= ' wpcontrol-app-page';
		}
		return $classes;
	}

	public static function render() {
		echo '<div id="wpcontrol-root" class="wpc-root"><div class="wpc-boot">' . esc_html__( 'Loading WP Control…', 'wp-control-manager' ) . '</div></div>';
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		$asset_file = WPCM_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Control: the dashboard has not been built. Run "npm install && npm run build" in the plugin folder.', 'wp-control-manager' ) . '</p></div>';
				}
			);
			return;
		}
		$asset = include $asset_file;

		wp_enqueue_script( 'wpcontrol-app', WPCM_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wpcontrol-app', WPCM_URL . 'build/style-index.css', array(), $asset['version'] );

		$user = wp_get_current_user();
		wp_add_inline_script(
			'wpcontrol-app',
			'window.wpControl = ' . wp_json_encode(
				array(
					'version'     => WPCM_VERSION,
					'restPath'    => '/' . WPCM_Rest_Admin::NS,
					'user'        => array( 'id' => $user->ID, 'name' => $user->display_name, 'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ) ),
					'exportUrls'  => array(
						'sites'       => WPCM_Export::url( 'sites' ),
						'maintenance' => WPCM_Export::url( 'maintenance' ),
						'events'      => WPCM_Export::url( 'events' ),
					),
					'timezone'    => wp_timezone_string(),
				)
			) . ';',
			'before'
		);
	}
}
