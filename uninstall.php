<?php
/**
 * Cleanup when the plugin is deleted through the WordPress admin.
 *
 * Removes options and transients owned by THIS plugin only. Deliberately
 * left alone:
 * - ghca_compliance_due_date — shared with the admin compliance plugin.
 * - ghca-announce posts — content, and also read by the admin plugin.
 * - LearnDash user/course data — not ours.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
  exit;
}

delete_option( 'ghca_ecd_cache_ttl' );
delete_option( 'ghca_ecd_start_group_ids' );
delete_option( 'ghca_announcements_seeded' );
delete_option( 'ghca_ecd_ui_redesign' );
delete_option( 'ghca_ecd_ui_theme' );
delete_option( 'ghca_ecd_policies_course_id' );

global $wpdb;

// Snapshot caches + per-user packet locks (date/user-suffixed, so LIKE-match).
foreach ( array( 'ghca_ecd_snap_', 'ghca_ecd_pkt_active_' ) as $prefix ) {
  $names = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
      $wpdb->esc_like( '_transient_' . $prefix ) . '%',
      $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
    )
  );
  foreach ( (array) $names as $name ) {
    delete_option( (string) $name );
  }
}
