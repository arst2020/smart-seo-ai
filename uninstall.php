<?php
// Runs when the plugin is deleted from the Plugins screen.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ssai_o = get_option( 'ssai_settings' );
if ( is_array( $ssai_o ) && ! empty( $ssai_o['indexnow_key'] ) && preg_match( '/^[a-f0-9]{32}$/', $ssai_o['indexnow_key'] ) ) {
	@unlink( ABSPATH . $ssai_o['indexnow_key'] . '.txt' ); // phpcs:ignore
}
delete_option( 'ssai_settings' );
delete_option( 'ssai_gsc' );
delete_option( 'ssai_ai_last' );
delete_option( 'ssai_google_updates' );
wp_clear_scheduled_hook( 'ssai_fetch_google_updates' );
wp_clear_scheduled_hook( 'ssai_indexnow_send' );
foreach ( array( 'ssai_observatory', 'ssai_indexnow_log', 'ssai_indexnow_queue', 'ssai_gsc_import_site', 'ssai_authority', 'ssai_backlinks', 'ssai_backlink_opr', 'ssai_site_audit', 'ssai_aeo_audit', 'ssai_llms_txt', 'ssai_redirect_map', 'ssai_migrate_report', 'ssai_migrate_compare', 'ssai_sitemap_ver', 'ssai_flush_rules', 'ssai_ahrefs', 'ssai_schema_diag' ) as $opt ) {
	delete_option( $opt );
}

// Remove only this plugin's own data. Yoast / Rank Math fields stay untouched.
foreach ( array( '_ssai_last_ai', '_ssai_score', '_ssai_history', '_ssai_psi', '_ssai_gsc_import' ) as $key ) {
	delete_post_meta_by_key( $key );
}
// _ssai_meta_description and _ssai_focus_keyword are kept on purpose, so no SEO work is lost
// if the plugin is reinstalled. Delete them manually if you no longer need them.

// The 404 log is temporary data and is removed. Redirects ({prefix}ssai_redirects), the SEO settings
// (ssai_seo) and per-page SEO fields are kept on purpose: deleting them would break old links and lose
// SEO work. Drop the table / option manually if you are sure.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ssai_404" ); // phpcs:ignore WordPress.DB
delete_option( 'ssai_db_ver' );
