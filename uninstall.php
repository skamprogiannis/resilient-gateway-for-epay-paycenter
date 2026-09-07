<?php
/**
 * Uninstall handler. Removes plugin options and the transactions table.
 *
 * Order meta is intentionally preserved so merchants still have an audit
 * trail of historical payments if they reinstall.
 *
 * @package EpayPaycenter
 *
 * Also removes FOLLOW_UP verification, scheduling, and lock state.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_epay_paycenter_settings' );
delete_option( 'epay_paycenter_db_version' );

// Reconciliation report read by the admin notice.
delete_option( 'epay_paycenter_reconcile_report' );
delete_option( 'epay_paycenter_follow_up_verification' );
delete_option( 'epay_paycenter_follow_up_active' );

// Active "credentials rejected by the bank" alert (ResultCode 100).
delete_option( 'epay_paycenter_credentials_alert' );

// Cached Cloudflare IPv4 CIDR list used by the admin settings screen.
delete_transient( 'epay_paycenter_cf_ipv4_v1' );

// Notice-dismissal flag and the reconciliation cron event. Deactivation
// already unschedules the event, but uninstall can be reached without a
// prior deactivate (e.g. deleting a plugin that was never activated on
// this site in a multisite switch), so clear it here too.
delete_transient( 'epay_paycenter_reconcile_dismissed' );
wp_clear_scheduled_hook( 'epay_paycenter_reconcile' );
wp_clear_scheduled_hook( 'epay_paycenter_release_stock' );

global $wpdb;

// Identifier is fully controlled by $wpdb->prefix + a static literal. We
// still pass it through %i so $wpdb quotes / escapes the identifier safely.
$epay_paycenter_table = $wpdb->prefix . 'epay_paycenter_tickets';

// Per-order locks are short-lived options; remove any left by an interrupted
// worker when the plugin itself is deleted.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'epay_paycenter_follow_up_lock_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $epay_paycenter_table ) );
