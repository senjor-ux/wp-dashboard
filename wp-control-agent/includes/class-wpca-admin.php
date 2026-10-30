<?php

defined( 'ABSPATH' ) || exit;

class WPCA_Admin {

	const SLUG = 'wp-control-agent';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_wpca_pair', array( __CLASS__, 'handle_pair' ) );
		add_action( 'admin_post_wpca_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_wpca_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPCA_DIR . 'wp-control-agent.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'WP Control', 'wp-control-agent' ),
			__( 'WP Control', 'wp-control-agent' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			80
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Status', 'wp-control-agent' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'wpca-admin', WPCA_URL . 'assets/admin.css', array(), WPCA_VERSION );
		}
	}

	private static function url( $tab = 'status', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-control-agent' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $tab, $type, $message ) {
		set_transient( 'wpca_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	public static function handle_pair() {
		self::guard( 'wpca_pair' );
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$result = WPCA_Connection::pair( $token );
		if ( is_wp_error( $result ) ) {
			$tab = WPCA_Connection::is_paired() ? 'status' : 'connection';
			self::back( $tab, 'error', $result->get_error_message() );
		}
		self::back( 'status', 'success', __( 'Connected to WP Control Manager and sent the first report.', 'wp-control-agent' ) );
	}

	public static function handle_sync() {
		self::guard( 'wpca_sync' );
		$result = WPCA_Connection::push();
		if ( is_wp_error( $result ) ) {
			self::back( 'status', 'error', $result->get_error_message() );
		}
		self::back( 'status', 'success', __( 'Report sent to the Manager.', 'wp-control-agent' ) );
	}

	public static function handle_disconnect() {
		self::guard( 'wpca_disconnect' );
		WPCA_Connection::disconnect();
		self::back( 'connection', 'success', __( 'Disconnected. This site no longer reports to the Manager.', 'wp-control-agent' ) );
	}

	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'status';
		$paired = WPCA_Connection::is_paired();
		$tabs   = array(
			'status'     => __( 'Status', 'wp-control-agent' ),
			'updates'    => __( 'Updates', 'wp-control-agent' ),
			'backups'    => __( 'Backups', 'wp-control-agent' ),
			'security'   => __( 'Security', 'wp-control-agent' ),
			'connection' => __( 'Connection', 'wp-control-agent' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'status';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$components = WPCA_Collector::components();
		$updates    = array_filter(
			$components,
			function ( $c ) {
				return ! empty( $c['update'] );
			}
		);
		?>
		<div class="wrap wpca">
			<h1><?php esc_html_e( 'WP Control', 'wp-control-agent' ); ?></h1>
			<?php self::notice(); ?>

			<nav class="wpca-tabs">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="<?php echo $id === $tab ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<?php if ( 'updates' === $id && $updates ) : ?>
							<span class="wpca-count"><?php echo (int) count( $updates ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'updates':
					self::render_updates( $updates );
					break;
				case 'backups':
					self::render_backups();
					break;
				case 'security':
					self::render_security( $components );
					break;
				case 'connection':
					self::render_connection( $paired );
					break;
				default:
					self::render_status( $paired, $components, $updates );
			}
			?>
		</div>
		<?php
	}

	private static function notice() {
		$key    = 'wpca_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $notice['type'] ? 'error' : 'success', esc_html( $notice['message'] ) );
		}
	}

	private static function ago( $ts ) {
		/* translators: %s: human time difference */
		return $ts ? sprintf( __( '%s ago', 'wp-control-agent' ), human_time_diff( $ts ) ) : '—';
	}

	private static function render_status( $paired, $components, $updates ) {
		$conn = WPCA_Connection::get();
		$last = get_option( WPCA_Connection::LAST_SYNC, array() );

		$active_plugins = count(
			array_filter(
				$components,
				function ( $c ) {
					return 'plugin' === $c['type'] && $c['active'];
				}
			)
		);
		$n_plugins = count(
			array_filter(
				$updates,
				function ( $c ) {
					return 'plugin' === $c['type'];
				}
			)
		);
		$n_themes  = count(
			array_filter(
				$updates,
				function ( $c ) {
					return 'theme' === $c['type'];
				}
			)
		);
		$theme = wp_get_theme();
		?>
		<?php if ( $paired ) : ?>
			<div class="wpca-card wpca-connected <?php echo ! empty( $last ) && empty( $last['ok'] ) ? 'is-error' : ''; ?>">
				<span class="dashicons <?php echo ! empty( $last ) && empty( $last['ok'] ) ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>"></span>
				<div>
					<strong>
						<?php
						/* translators: %s: manager host */
						printf( esc_html__( 'Connected to central dashboard (%s)', 'wp-control-agent' ), esc_html( $conn['manager_host'] ) );
						?>
					</strong>
					<p>
						<?php
						if ( ! empty( $last['time'] ) ) {
							/* translators: 1: date, 2: relative time */
							printf( esc_html__( 'Last sync: %1$s (%2$s)', 'wp-control-agent' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last['time'] ) ), esc_html( self::ago( $last['time'] ) ) );
							if ( empty( $last['ok'] ) ) {
								echo '<br><span class="wpca-error-text">' . esc_html( $last['message'] ) . '</span>';
							}
						} else {
							esc_html_e( 'No report sent yet.', 'wp-control-agent' );
						}
						?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wpca_sync' ); ?>
					<input type="hidden" name="action" value="wpca_sync">
					<button class="button button-primary"><?php esc_html_e( 'Sync now', 'wp-control-agent' ); ?></button>
				</form>
			</div>
		<?php else : ?>
			<div class="wpca-card wpca-connected is-pending">
				<span class="dashicons dashicons-admin-links"></span>
				<div>
					<strong><?php esc_html_e( 'Not connected', 'wp-control-agent' ); ?></strong>
					<p><?php esc_html_e( 'Paste the pairing token from your WP Control Manager to start reporting.', 'wp-control-agent' ); ?></p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( self::url( 'connection' ) ); ?>"><?php esc_html_e( 'Connect', 'wp-control-agent' ); ?></a>
			</div>
		<?php endif; ?>

		<div class="wpca-card">
			<h2><?php esc_html_e( 'Site information', 'wp-control-agent' ); ?></h2>
			<table class="wpca-info">
				<tr><th><?php esc_html_e( 'Site URL', 'wp-control-agent' ); ?></th><td><a href="<?php echo esc_url( home_url() ); ?>"><?php echo esc_html( home_url() ); ?></a></td></tr>
				<tr><th><?php esc_html_e( 'PHP version', 'wp-control-agent' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
				<tr><th><?php esc_html_e( 'WordPress version', 'wp-control-agent' ); ?></th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Active theme', 'wp-control-agent' ); ?></th><td><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Active plugins', 'wp-control-agent' ); ?></th><td><?php echo (int) $active_plugins; ?></td></tr>
				<tr>
					<th><?php esc_html_e( 'Pending updates', 'wp-control-agent' ); ?></th>
					<td>
						<?php if ( $updates ) : ?>
							<span class="wpca-warn"><?php echo (int) count( $updates ); ?></span>
							<?php
							/* translators: 1: plugin count, 2: theme count */
							printf( esc_html__( '(%1$d plugin, %2$d theme)', 'wp-control-agent' ), (int) $n_plugins, (int) $n_themes );
							?>
						<?php else : ?>
							<?php esc_html_e( 'None', 'wp-control-agent' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<p class="wpca-footnote">
			<span class="dashicons dashicons-info-outline"></span>
			<?php esc_html_e( 'This site is managed via WP Control. Data is sent securely (HTTPS, signed requests) to your central dashboard. The agent is read-only: it never changes this site.', 'wp-control-agent' ); ?>
		</p>
		<?php
	}

	private static function render_updates( $updates ) {
		?>
		<div class="wpca-card">
			<h2><?php esc_html_e( 'Available updates', 'wp-control-agent' ); ?></h2>
			<?php if ( ! $updates ) : ?>
				<p><?php esc_html_e( 'Everything is up to date.', 'wp-control-agent' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Name', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Type', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Current', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'New', 'wp-control-agent' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $updates as $c ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $c['name'] ); ?></strong></td>
								<td><?php echo esc_html( ucfirst( $c['type'] ) ); ?></td>
								<td><?php echo esc_html( $c['version'] ); ?></td>
								<td class="wpca-warn"><?php echo esc_html( $c['update'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Go to WordPress Updates', 'wp-control-agent' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_backups() {
		$backups   = WPCA_Collector::backups();
		$providers = WPCA_Collector::backup_providers();
		?>
		<div class="wpca-card">
			<h2><?php esc_html_e( 'Backups', 'wp-control-agent' ); ?></h2>
			<p>
				<?php esc_html_e( 'Detected backup plugins:', 'wp-control-agent' ); ?>
				<strong><?php echo $providers ? esc_html( implode( ', ', $providers ) ) : esc_html__( 'none', 'wp-control-agent' ); ?></strong>
			</p>
			<?php if ( ! $backups ) : ?>
				<p><?php esc_html_e( 'No backup history found. WP Control reads UpdraftPlus history; other plugins can report via the wpcontrol_agent_backups filter.', 'wp-control-agent' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Date', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Status', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Size', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Storage', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Triggered by', 'wp-control-agent' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $backups as $b ) : ?>
							<?php $ts = is_numeric( $b['started_at'] ) ? (int) $b['started_at'] : strtotime( (string) $b['started_at'] ); ?>
							<tr>
								<td><?php echo esc_html( $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—' ); ?></td>
								<td><span class="wpca-pill is-<?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( ucfirst( $b['status'] ) ); ?></span></td>
								<td><?php echo $b['size'] ? esc_html( size_format( $b['size'] ) ) : '—'; ?></td>
								<td><?php echo esc_html( $b['storage'] ); ?></td>
								<td><?php echo esc_html( $b['triggered_by'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_security( $components ) {
		$failing = array_filter(
			WPCA_Collector::checks( $components ),
			function ( $c ) {
				return 'fail' === $c['status'];
			}
		);
		$order = array( 'critical' => 0, 'moderate' => 1, 'low' => 2 );
		usort(
			$failing,
			function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] - $order[ $b['severity'] ];
			}
		);
		?>
		<div class="wpca-card">
			<h2><?php esc_html_e( 'Security checks', 'wp-control-agent' ); ?></h2>
			<?php if ( ! $failing ) : ?>
				<p><span class="dashicons dashicons-yes-alt wpca-ok"></span> <?php esc_html_e( 'No issues found.', 'wp-control-agent' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Issue', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Severity', 'wp-control-agent' ); ?></th><th><?php esc_html_e( 'Component', 'wp-control-agent' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $failing as $c ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $c['title'] ); ?></strong><?php echo $c['details'] ? '<br><span class="description">' . esc_html( $c['details'] ) . '</span>' : ''; ?></td>
								<td><span class="wpca-pill is-<?php echo esc_attr( $c['severity'] ); ?>"><?php echo esc_html( ucfirst( $c['severity'] ) ); ?></span></td>
								<td><?php echo esc_html( $c['component'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_connection( $paired ) {
		$conn = WPCA_Connection::get();
		?>
		<div class="wpca-card">
			<?php if ( $paired ) : ?>
				<h2><?php esc_html_e( 'Connection', 'wp-control-agent' ); ?></h2>
				<table class="wpca-info">
					<tr><th><?php esc_html_e( 'Manager', 'wp-control-agent' ); ?></th><td><?php echo esc_html( $conn['manager_host'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Registered as', 'wp-control-agent' ); ?></th><td><?php echo esc_html( $conn['site_name'] ? $conn['site_name'] : '#' . $conn['site_id'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Paired', 'wp-control-agent' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $conn['paired_at'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Agent version', 'wp-control-agent' ); ?></th><td><?php echo esc_html( WPCA_VERSION ); ?></td></tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Stop reporting to the Manager?', 'wp-control-agent' ) ); ?>');">
					<?php wp_nonce_field( 'wpca_disconnect' ); ?>
					<input type="hidden" name="action" value="wpca_disconnect">
					<p><button class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'wp-control-agent' ); ?></button></p>
				</form>
				<hr>
				<h2><?php esc_html_e( 'Re-pair with a new token', 'wp-control-agent' ); ?></h2>
			<?php else : ?>
				<h2><?php esc_html_e( 'Connect to WP Control Manager', 'wp-control-agent' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'In your WP Control Manager, add this website (or open it and click "Get pairing token").', 'wp-control-agent' ); ?></li>
					<li><?php esc_html_e( 'Copy the pairing token and paste it below.', 'wp-control-agent' ); ?></li>
				</ol>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpca_pair' ); ?>
				<input type="hidden" name="action" value="wpca_pair">
				<p><textarea name="token" class="large-text code" rows="4" required placeholder="wpc1.…" autocomplete="off"></textarea></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Connect', 'wp-control-agent' ); ?></button></p>
			</form>
			<?php if ( ! is_ssl() && ! WPCA_Connection::allow_insecure() ) : ?>
				<p class="description"><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'This site is not using HTTPS. The Manager will not be able to request on-demand reports until HTTPS is enabled.', 'wp-control-agent' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
