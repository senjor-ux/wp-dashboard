<?php

defined( 'ABSPATH' ) || exit;

class WPCA_Collector {

	/**
	 * @param bool $refresh Ask WordPress.org for fresh update data first (rate-limited).
	 */
	public static function snapshot( $refresh = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		if ( $refresh && ! get_transient( 'wpca_refreshed' ) ) {
			set_transient( 'wpca_refreshed', 1, 5 * MINUTE_IN_SECONDS );
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();
		}

		$components = self::components();

		return array(
			'agent_version' => WPCA_VERSION,
			'generated_at'  => gmdate( 'c' ),
			'site'          => self::site_info(),
			'components'    => $components,
			'backups'       => self::backups(),
			'checks'        => self::checks( $components ),
		);
	}

	public static function site_info() {
		global $wpdb;
		$theme = wp_get_theme();
		return array(
			'url'              => home_url(),
			'name'             => get_bloginfo( 'name' ),
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'db_version'       => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version(),
			'server'           => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'multisite'        => is_multisite(),
			'locale'           => get_locale(),
			'timezone'         => wp_timezone_string(),
			'active_theme'     => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'https'            => 0 === strpos( home_url(), 'https://' ),
			'backup_providers' => implode( ', ', self::backup_providers() ),
		);
	}

	public static function components() {
		$items = array();

		$core_update = '';
		$updates     = get_core_updates();
		if ( is_array( $updates ) ) {
			foreach ( $updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response && ! empty( $u->current ) ) {
					$core_update = $u->current;
					break;
				}
			}
		}
		$items[] = array(
			'type'    => 'core',
			'slug'    => 'wordpress',
			'name'    => 'WordPress',
			'version' => get_bloginfo( 'version' ),
			'active'  => true,
			'update'  => $core_update,
		);

		$plugin_updates = get_site_transient( 'update_plugins' );
		foreach ( get_plugins() as $file => $plugin ) {
			$update = '';
			if ( isset( $plugin_updates->response[ $file ]->new_version ) ) {
				$update = $plugin_updates->response[ $file ]->new_version;
			}
			$items[] = array(
				'type'    => 'plugin',
				'slug'    => $file,
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'active'  => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
				'update'  => $update,
			);
		}

		$theme_updates = get_site_transient( 'update_themes' );
		$stylesheet    = get_stylesheet();
		$template      = get_template();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$update = '';
			if ( isset( $theme_updates->response[ $slug ]['new_version'] ) ) {
				$update = $theme_updates->response[ $slug ]['new_version'];
			}
			$items[] = array(
				'type'    => 'theme',
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $stylesheet || $slug === $template,
				'update'  => $update,
			);
		}

		return $items;
	}

	public static function backup_providers() {
		$providers = array();
		if ( class_exists( 'UpdraftPlus' ) || false !== get_option( 'updraft_backup_history', false ) ) {
			$providers[] = 'UpdraftPlus';
		}
		if ( class_exists( 'BackWPup' ) ) {
			$providers[] = 'BackWPup (history not supported)';
		}
		return apply_filters( 'wpcontrol_agent_backup_providers', $providers );
	}

	/**
	 * Backup history. Each item: id, provider, status (success|failed|running|unknown),
	 * started_at, completed_at (unix or ISO), size (bytes), storage, triggered_by.
	 *
	 * Other backup plugins can contribute entries via the `wpcontrol_agent_backups` filter.
	 */
	public static function backups() {
		$items = self::updraftplus_backups();
		$items = apply_filters( 'wpcontrol_agent_backups', $items );
		return array_slice( is_array( $items ) ? $items : array(), 0, 50 );
	}

	private static function updraftplus_backups() {
		$items   = array();
		$history = get_option( 'updraft_backup_history', array() );
		if ( is_array( $history ) ) {
			krsort( $history );
			foreach ( array_slice( $history, 0, 30, true ) as $timestamp => $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$size = 0;
				foreach ( $b as $key => $value ) {
					if ( '-size' === substr( (string) $key, -5 ) && is_numeric( $value ) ) {
						$size += (int) $value;
					}
				}
				$services = isset( $b['service'] ) ? (array) $b['service'] : array();
				$services = array_filter( $services, function ( $s ) {
					return $s && 'none' !== $s;
				} );
				$items[] = array(
					'id'           => isset( $b['nonce'] ) ? $b['nonce'] : (string) $timestamp,
					'provider'     => 'updraftplus',
					'status'       => 'success',
					'started_at'   => (int) $timestamp,
					'completed_at' => (int) $timestamp,
					'size'         => $size ? $size : null,
					'storage'      => $services ? implode( ', ', array_map( 'ucfirst', $services ) ) : 'Local',
					'triggered_by' => ! empty( $b['autobackup'] ) ? 'Automatic (before update)' : ( ! empty( $b['label'] ) ? $b['label'] : 'UpdraftPlus' ),
				);
			}
		}

		// The last attempt, if it failed, is not in the history.
		$last = get_option( 'updraft_last_backup', array() );
		if ( is_array( $last ) && isset( $last['success'] ) && ! $last['success'] && ! empty( $last['backup_time'] ) ) {
			$items[] = array(
				'id'           => 'failed-' . (int) $last['backup_time'],
				'provider'     => 'updraftplus',
				'status'       => 'failed',
				'started_at'   => (int) $last['backup_time'],
				'completed_at' => null,
				'size'         => null,
				'storage'      => '',
				'triggered_by' => 'UpdraftPlus',
			);
		}

		return $items;
	}

	private static function check( $key, $failing, $severity, $title, $component = '', $details = '' ) {
		return array(
			'key'       => $key,
			'status'    => $failing ? 'fail' : 'pass',
			'severity'  => $severity,
			'title'     => $title,
			'component' => $component,
			'details'   => $details,
		);
	}

	public static function checks( array $components ) {
		global $wpdb;
		$checks = array();

		$core = $components[0];
		$checks[] = self::check(
			'core_outdated',
			! empty( $core['update'] ),
			'moderate',
			'WordPress core is out of date',
			'WordPress',
			$core['update'] ? sprintf( 'Installed %s, available %s.', $core['version'], $core['update'] ) : ''
		);

		$php_critical = version_compare( PHP_VERSION, '8.1', '<' );
		$checks[]     = self::check(
			'php_eol',
			version_compare( PHP_VERSION, '8.2', '<' ),
			$php_critical ? 'critical' : 'moderate',
			'PHP version no longer receives security fixes',
			'PHP ' . PHP_VERSION,
			'Upgrade to a supported PHP version (8.2 or newer).'
		);

		$checks[] = self::check( 'no_https', 0 !== strpos( home_url(), 'https://' ), 'critical', 'Site is not served over HTTPS', 'Site URL', home_url() );

		$checks[] = self::check(
			'debug_display',
			defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'moderate',
			'Debug output is displayed to visitors',
			'wp-config.php',
			'Set WP_DEBUG_DISPLAY to false on production sites.'
		);

		$checks[] = self::check(
			'file_edit',
			! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			'low',
			'Plugin/theme file editor is enabled',
			'wp-config.php',
			'Define DISALLOW_FILE_EDIT as true.'
		);

		$checks[] = self::check( 'admin_username', (bool) username_exists( 'admin' ), 'low', 'A user named "admin" exists', 'Users', 'Common target for brute-force attacks.' );

		$checks[] = self::check( 'default_prefix', 'wp_' === $wpdb->prefix, 'low', 'Default database table prefix (wp_)', 'Database' );

		$checks[] = self::check( 'xmlrpc', (bool) apply_filters( 'xmlrpc_enabled', true ), 'low', 'XML-RPC is enabled', 'xmlrpc.php', 'Disable it if no app or service needs it.' );

		$checks[] = self::check( 'search_hidden', '0' === (string) get_option( 'blog_public' ), 'low', 'Search engines are discouraged from indexing', 'Settings → Reading' );

		$inactive = array();
		foreach ( $components as $c ) {
			if ( 'plugin' === $c['type'] && ! $c['active'] ) {
				$inactive[] = $c['name'];
			}
		}
		$checks[] = self::check(
			'inactive_plugins',
			(bool) $inactive,
			'low',
			sprintf( '%d inactive plugin(s) installed', count( $inactive ) ),
			'Plugins',
			$inactive ? 'Remove unused plugins: ' . implode( ', ', array_slice( $inactive, 0, 10 ) ) . ( count( $inactive ) > 10 ? '…' : '' ) : ''
		);

		$outdated = array();
		foreach ( $components as $c ) {
			if ( 'core' !== $c['type'] && ! empty( $c['update'] ) && $c['active'] ) {
				$outdated[] = $c['name'];
			}
		}
		$checks[] = self::check(
			'outdated_active',
			count( $outdated ) >= 5,
			'moderate',
			sprintf( '%d active plugins/themes are outdated', count( $outdated ) ),
			'Plugins & themes',
			implode( ', ', array_slice( $outdated, 0, 10 ) )
		);

		return apply_filters( 'wpcontrol_agent_checks', $checks );
	}
}
