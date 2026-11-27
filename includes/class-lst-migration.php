<?php
/** Non-destructive migration of WPTR installations to the LST API namespace. */
defined( 'ABSPATH' ) || exit;

/**
 * One-time upgrade from the short lst_ prefix used by earlier builds to leadst_.
 *
 * Runs only when lst_db_version exists and leadst_db_version does not, so fresh
 * installs skip it. Legacy tables are renamed in one atomic statement; if the
 * rename is not permitted the plugin keeps using the legacy tables.
 */
function leadst_migrate_from_lst() {
	global $wpdb;

	if ( false === get_option( 'lst_db_version', false ) || false !== get_option( 'leadst_db_version', false ) ) {
		return;
	}

	$namespace = get_option( 'lst_storage_namespace', 'lst' );
	if ( 'wptr' !== $namespace ) {
		$namespace = 'leadst';
		$pairs     = array();
		foreach ( array( 'strings', 'slugs', 'warm_jobs', 'usage' ) as $suffix ) {
			$old = $wpdb->prefix . 'lst_' . $suffix;
			$new = $wpdb->prefix . 'leadst_' . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema check during upgrade.
			$old_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema check during upgrade.
			$new_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) );
			if ( $old === $old_found && $new !== $new_found ) {
				$pairs[] = '`' . esc_sql( $old ) . '` TO `' . esc_sql( $new ) . '`';
			}
		}
		if ( $pairs ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- One-time atomic rename of this plugin's own tables; identifiers are built from $wpdb->prefix and fixed suffixes.
			$renamed = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
			$wpdb->suppress_errors( $suppress );
			if ( false === $renamed ) {
				$namespace = 'lst'; // Keep using the existing tables.
			}
		}
	}

	// Option => autoload. db_version is copied last as the completion marker.
	$options = array(
		'settings'           => true,
		'cache_version'      => true,
		'cache_version_lang' => true,
		'flush_needed'       => true,
		'slug_queue'         => false,
		'slug_conflicts'     => false,
		'warm_queue'         => false,
		'warm_total'         => false,
		'warm_token'         => false,
		'warm_finished'      => false,
		'pending_lock'       => false,
		'edge_token'         => false,
		'usage_daily'        => false,
		'usage_monthly'      => false,
		'usage_total'        => false,
		'ultimate_usage'     => false,
		'ai_client_usage'    => false,
		'db_version'         => true,
	);
	update_option( 'leadst_storage_namespace', $namespace, false );
	foreach ( $options as $suffix => $autoload ) {
		$value = get_option( 'lst_' . $suffix, false );
		if ( false !== $value ) {
			add_option( 'leadst_' . $suffix, $value, '', $autoload ? 'yes' : 'no' );
		}
	}
	foreach ( array_keys( $options ) as $suffix ) {
		delete_option( 'lst_' . $suffix );
	}
	delete_option( 'lst_storage_namespace' );

	// Scheduled events are re-created under the new hook names.
	foreach ( array( 'lst_queue_tick', 'lst_warm_tick', 'lst_translate_slug_batch', 'lst_rescan_conflicts' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	update_option( 'leadst_flush_needed', 1 );
}

/**
 * One-way, non-destructive WPTR compatibility transition.
 * Existing LST data always wins. WPTR tables stay in place for legacy sites;
 * fresh installations create and use LST tables. No mass-copy or DROP runs
 * during a page view, and the old settings are retained for rollback.
 */
function leadst_adopt_legacy_wptr() {
	if ( false !== get_option( 'leadst_settings', false ) ) { return; }
	$old = get_option( 'wptr_settings', false );
	if ( ! is_array( $old ) ) { return; }
	// Deactivate only the old jobs; LST schedules replacement events.
	foreach ( array( 'wptr_queue_tick', 'wptr_warm_tick', 'wptr_translate_slug_batch', 'wptr_rescan_conflicts' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	add_option( 'leadst_storage_namespace', 'wptr', '', false );
	add_option( 'leadst_settings', $old, '', false );
	foreach ( array( 'warm_queue', 'usage_daily', 'usage_monthly', 'usage_total', 'slug_queue', 'edge_token', 'warm_token' ) as $suffix ) {
		$value = get_option( 'wptr_' . $suffix, false );
		if ( false !== $value ) { add_option( 'leadst_' . $suffix, $value, '', false ); }
	}
	update_option( 'leadst_flush_needed', 1 );
}

