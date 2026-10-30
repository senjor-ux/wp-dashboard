<?php
/**
 * Snapshot ingestion and change detection.
 *
 * Agents push snapshots (or the Manager pulls them). Each snapshot is diffed against the
 * stored components: version changes become component events (previous version, new version,
 * detection time). Events are NOT attributed to anyone — a technician attaches a maintenance
 * record to claim and document them.
 */

defined( 'ABSPATH' ) || exit;

class WPCM_Sync {

	const MAX_COMPONENTS = 2000;
	const MAX_BACKUPS    = 100;
	const MAX_CHECKS     = 100;

	public static function ingest( $site_id, array $snapshot, $direction = 'push' ) {
		global $wpdb;
		$site_id = (int) $site_id;
		$now     = WPCM_DB::now();

		$wpdb->insert(
			WPCM_DB::t( 'sync_runs' ),
			array(
				'site_id'    => $site_id,
				'direction'  => $direction,
				'status'     => 'running',
				'started_at' => $now,
			)
		);
		$run_id = (int) $wpdb->insert_id;

		$wpdb->query( 'START TRANSACTION' );

		$is_first = ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPCM_DB::t( 'components' ) . ' WHERE site_id = %d', $site_id ) );

		$changes  = self::ingest_components( $site_id, (array) ( $snapshot['components'] ?? array() ), $run_id, $now, $is_first );
		self::ingest_backups( $site_id, (array) ( $snapshot['backups'] ?? array() ), $now );
		self::ingest_checks( $site_id, (array) ( $snapshot['checks'] ?? array() ), $now );
		self::ingest_site_info( $site_id, (array) ( $snapshot['site'] ?? array() ), $now );

		$wpdb->update(
			WPCM_DB::t( 'agents' ),
			array(
				'last_seen_at'  => $now,
				'agent_version' => substr( sanitize_text_field( $snapshot['agent_version'] ?? '' ), 0, 32 ),
			),
			array( 'site_id' => $site_id )
		);

		$message = $is_first ? 'Baseline snapshot recorded.' : sprintf( '%d change(s) detected.', $changes );
		$wpdb->update(
			WPCM_DB::t( 'sync_runs' ),
			array(
				'status'      => 'success',
				'finished_at' => WPCM_DB::now(),
				'changes'     => $changes,
				'message'     => $message,
			),
			array( 'id' => $run_id )
		);

		$wpdb->query( 'COMMIT' );

		return array(
			'run_id'  => $run_id,
			'changes' => $changes,
			'message' => $message,
		);
	}

	private static function ingest_components( $site_id, array $incoming, $run_id, $now, $is_first ) {
		global $wpdb;
		$ct = WPCM_DB::t( 'components' );
		$ot = WPCM_DB::t( 'update_offers' );
		$et = WPCM_DB::t( 'component_events' );

		$existing = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ct WHERE site_id = %d", $site_id ) ) as $row ) {
			$existing[ $row->type . '|' . $row->slug ] = $row;
		}
		$open_offers = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ot WHERE site_id = %d AND resolved_at IS NULL", $site_id ) ) as $row ) {
			$open_offers[ (int) $row->component_id ] = $row;
		}

		$changes  = 0;
		$seen     = array();
		$incoming = array_slice( $incoming, 0, self::MAX_COMPONENTS );

		foreach ( $incoming as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = sanitize_key( $item['type'] ?? '' );
			if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
				continue;
			}
			$slug    = substr( sanitize_text_field( $item['slug'] ?? '' ), 0, 150 );
			$name    = substr( sanitize_text_field( $item['name'] ?? $slug ), 0, 191 );
			$version = substr( sanitize_text_field( $item['version'] ?? '' ), 0, 64 );
			$active  = ! empty( $item['active'] ) ? 1 : 0;
			$update  = substr( sanitize_text_field( $item['update'] ?? '' ), 0, 64 );
			if ( '' === $slug ) {
				continue;
			}
			$key          = $type . '|' . $slug;
			$seen[ $key ] = true;

			if ( isset( $existing[ $key ] ) ) {
				$row          = $existing[ $key ];
				$component_id = (int) $row->id;
				$event        = null;

				if ( $row->removed_at ) {
					$event = array( 'installed', '', $version );
				} elseif ( $row->version !== $version ) {
					$event = array( version_compare( $version, $row->version, '<' ) ? 'downgraded' : 'updated', $row->version, $version );
				}

				$wpdb->update(
					$ct,
					array(
						'name'         => $name,
						'version'      => $version,
						'active'       => $active,
						'last_seen_at' => $now,
						'removed_at'   => null,
					),
					array( 'id' => $component_id )
				);
			} else {
				$wpdb->insert(
					$ct,
					array(
						'site_id'       => $site_id,
						'type'          => $type,
						'slug'          => $slug,
						'name'          => $name,
						'version'       => $version,
						'active'        => $active,
						'first_seen_at' => $now,
						'last_seen_at'  => $now,
					)
				);
				$component_id = (int) $wpdb->insert_id;
				$event        = $is_first ? null : array( 'installed', '', $version );
			}

			if ( $event ) {
				$wpdb->insert(
					$et,
					array(
						'site_id'      => $site_id,
						'component_id' => $component_id,
						'event_type'   => $event[0],
						'old_version'  => $event[1],
						'new_version'  => $event[2],
						'detected_at'  => $now,
						'sync_run_id'  => $run_id,
					)
				);
				$changes++;
			}

			// Update offers: keep one open offer per component, resolve when no longer offered.
			$offer = $open_offers[ $component_id ] ?? null;
			if ( '' !== $update && version_compare( $update, $version, '>' ) ) {
				if ( $offer && $offer->new_version === $update ) {
					$wpdb->update( $ot, array( 'last_seen_at' => $now, 'current_version' => $version ), array( 'id' => $offer->id ) );
				} else {
					if ( $offer ) {
						$wpdb->update( $ot, array( 'resolved_at' => $now ), array( 'id' => $offer->id ) );
					}
					$wpdb->insert(
						$ot,
						array(
							'site_id'         => $site_id,
							'component_id'    => $component_id,
							'current_version' => $version,
							'new_version'     => $update,
							'first_seen_at'   => $now,
							'last_seen_at'    => $now,
						)
					);
				}
			} elseif ( $offer ) {
				$wpdb->update( $ot, array( 'resolved_at' => $now ), array( 'id' => $offer->id ) );
			}
		}

		// Components that disappeared from the snapshot were removed from the site.
		foreach ( $existing as $key => $row ) {
			if ( isset( $seen[ $key ] ) || $row->removed_at ) {
				continue;
			}
			$wpdb->update( $ct, array( 'removed_at' => $now, 'active' => 0 ), array( 'id' => $row->id ) );
			$wpdb->insert(
				$et,
				array(
					'site_id'      => $site_id,
					'component_id' => (int) $row->id,
					'event_type'   => 'removed',
					'old_version'  => $row->version,
					'new_version'  => '',
					'detected_at'  => $now,
					'sync_run_id'  => $run_id,
				)
			);
			if ( isset( $open_offers[ (int) $row->id ] ) ) {
				$wpdb->update( $ot, array( 'resolved_at' => $now ), array( 'id' => $open_offers[ (int) $row->id ]->id ) );
			}
			$changes++;
		}

		return $changes;
	}

	private static function ingest_backups( $site_id, array $backups, $now ) {
		global $wpdb;
		$bt = WPCM_DB::t( 'backups' );

		foreach ( array_slice( $backups, 0, self::MAX_BACKUPS ) as $b ) {
			if ( ! is_array( $b ) || empty( $b['id'] ) ) {
				continue;
			}
			$provider = substr( sanitize_key( $b['provider'] ?? 'unknown' ), 0, 50 );
			$ext_id   = substr( sanitize_text_field( (string) $b['id'] ), 0, 191 );
			$status   = sanitize_key( $b['status'] ?? 'unknown' );
			if ( ! in_array( $status, array( 'success', 'failed', 'running', 'unknown' ), true ) ) {
				$status = 'unknown';
			}
			$data = array(
				'status'       => $status,
				'started_at'   => WPCM_DB::to_mysql( $b['started_at'] ?? null ),
				'completed_at' => WPCM_DB::to_mysql( $b['completed_at'] ?? null ),
				'size_bytes'   => isset( $b['size'] ) && is_numeric( $b['size'] ) ? (int) $b['size'] : null,
				'storage'      => substr( sanitize_text_field( $b['storage'] ?? '' ), 0, 100 ),
				'triggered_by' => substr( sanitize_text_field( $b['triggered_by'] ?? '' ), 0, 100 ),
			);

			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $bt WHERE site_id = %d AND provider = %s AND external_id = %s", $site_id, $provider, $ext_id ) );
			if ( $id ) {
				$wpdb->update( $bt, $data, array( 'id' => (int) $id ) );
			} else {
				$wpdb->insert(
					$bt,
					array_merge(
						$data,
						array(
							'site_id'       => $site_id,
							'provider'      => $provider,
							'external_id'   => $ext_id,
							'first_seen_at' => $now,
						)
					)
				);
			}
		}
	}

	private static function ingest_checks( $site_id, array $checks, $now ) {
		global $wpdb;
		$kt = WPCM_DB::t( 'site_checks' );

		$open = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $kt WHERE site_id = %d AND status = 'open'", $site_id ) ) as $row ) {
			$open[ $row->check_key ] = $row;
		}

		$failing = array();
		foreach ( array_slice( $checks, 0, self::MAX_CHECKS ) as $c ) {
			if ( ! is_array( $c ) || empty( $c['key'] ) || 'fail' !== ( $c['status'] ?? '' ) ) {
				continue;
			}
			$key      = substr( sanitize_key( $c['key'] ), 0, 100 );
			$severity = in_array( $c['severity'] ?? '', array( 'critical', 'moderate', 'low' ), true ) ? $c['severity'] : 'low';
			$data     = array(
				'severity'     => $severity,
				'title'        => substr( sanitize_text_field( $c['title'] ?? $key ), 0, 255 ),
				'component'    => substr( sanitize_text_field( $c['component'] ?? '' ), 0, 191 ),
				'details'      => sanitize_textarea_field( $c['details'] ?? '' ),
				'last_seen_at' => $now,
			);
			$failing[ $key ] = true;

			if ( isset( $open[ $key ] ) ) {
				$wpdb->update( $kt, $data, array( 'id' => $open[ $key ]->id ) );
			} else {
				$wpdb->insert(
					$kt,
					array_merge(
						$data,
						array(
							'site_id'       => $site_id,
							'check_key'     => $key,
							'status'        => 'open',
							'first_seen_at' => $now,
						)
					)
				);
			}
		}

		foreach ( $open as $key => $row ) {
			if ( ! isset( $failing[ $key ] ) ) {
				$wpdb->update( $kt, array( 'status' => 'resolved', 'resolved_at' => $now ), array( 'id' => $row->id ) );
			}
		}
	}

	private static function ingest_site_info( $site_id, array $info, $now ) {
		global $wpdb;
		$clean = array();
		foreach ( $info as $k => $v ) {
			$k = sanitize_key( $k );
			if ( is_scalar( $v ) || null === $v ) {
				$clean[ $k ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
			}
		}
		$wpdb->update(
			WPCM_DB::t( 'sites' ),
			array(
				'wp_version'   => substr( (string) ( $clean['wp_version'] ?? '' ), 0, 32 ),
				'php_version'  => substr( (string) ( $clean['php_version'] ?? '' ), 0, 32 ),
				'active_theme' => substr( (string) ( $clean['active_theme'] ?? '' ), 0, 191 ),
				'reported_url' => isset( $clean['url'] ) ? esc_url_raw( $clean['url'] ) : '',
				'info'         => wp_json_encode( $clean ),
				'last_sync_at' => $now,
				'last_error'   => null,
				'last_error_at' => null,
			),
			array( 'id' => $site_id )
		);
	}

	public static function pull( $site_id, $refresh = true ) {
		global $wpdb;
		$agent  = WPCM_Sites::get_agent( $site_id );
		$secret = WPCM_Sites::agent_secret( $agent );

		if ( ! $agent || 'paired' !== $agent->status || ! $secret || ! $agent->agent_api ) {
			return new WP_Error( 'wpc_not_paired', 'This site has no paired agent yet.', array( 'status' => 409 ) );
		}

		$route = '/wpcontrol-agent/v1/snapshot';
		$url   = $agent->agent_api . ltrim( $route, '/' );
		if ( $refresh ) {
			$url = add_query_arg( 'refresh', '1', $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 45,
				'headers'            => WPCM_Signature::headers( $secret, $site_id, 'GET', $route ),
				'reject_unsafe_urls' => (bool) WPCM_Settings::get( 'require_https' ),
				'sslverify'          => (bool) WPCM_Settings::get( 'require_https' ),
			)
		);

		$error = null;
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$error = sprintf( 'Agent responded with HTTP %d%s', wp_remote_retrieve_response_code( $response ), isset( $body['message'] ) ? ': ' . $body['message'] : '' );
		} else {
			$snapshot = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $snapshot ) ) {
				$error = 'Agent returned an invalid snapshot.';
			}
		}

		if ( $error ) {
			$now = WPCM_DB::now();
			$wpdb->update( WPCM_DB::t( 'sites' ), array( 'last_error' => $error, 'last_error_at' => $now ), array( 'id' => (int) $site_id ) );
			$wpdb->insert(
				WPCM_DB::t( 'sync_runs' ),
				array(
					'site_id'     => (int) $site_id,
					'direction'   => 'pull',
					'status'      => 'failed',
					'started_at'  => $now,
					'finished_at' => $now,
					'message'     => $error,
				)
			);
			return new WP_Error( 'wpc_pull_failed', $error, array( 'status' => 502 ) );
		}

		return self::ingest( $site_id, $snapshot, 'pull' );
	}

	/** Cron: refresh every paired site that hasn't reported recently. */
	public static function pull_all() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT site_id FROM ' . WPCM_DB::t( 'agents' ) . " WHERE status = 'paired' AND (last_seen_at IS NULL OR last_seen_at < %s)",
				WPCM_DB::ago( HOUR_IN_SECONDS * 2 )
			)
		);
		foreach ( $ids as $id ) {
			self::pull( (int) $id, false );
		}
	}
}
