<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Profile photo for the redesigned welcome card. The photo is the employee's BuddyBoss / BuddyPress avatar, so
 * the same photo appears in the header, the profile and member lists. Since 1.6.1 the welcome card's pop-up
 * (GHCA_ECD_Redesign::photo_dialog_html, assets/ecd-redesign.js) saves through BuddyBoss's REST API. This class
 * covers the fallback path through BuddyBoss's change-avatar screen (no REST API, or no JavaScript) and keeps no
 * avatar store of its own. It only:
 *
 * - removes the employee's own photo through BuddyPress's API ("Remove photo"),
 * - shows a "Back to your dashboard" link on the change-avatar screen when the employee came from the dashboard,
 * - limits uploads started from the dashboard to JPG, PNG and WebP (BuddyBoss checks the file and its size).
 *
 * Photos are never added to certificates or audit packets.
 */
final class GHCA_ECD_Profile_Photo {

  const FLOW_TRANSIENT = 'ghca_ecd_photo_flow_'; // + user id; holds the dashboard URL to return to.
  const FLOW_TTL       = 1800;

  public static function init(): void {
    add_action( 'admin_post_ghca_ecd_remove_photo', array( __CLASS__, 'handle_remove' ) );
    add_action( 'template_redirect', array( __CLASS__, 'remember_return' ), 1 );
    add_action( 'bp_before_profile_avatar_upload_content', array( __CLASS__, 'return_notice' ) );
    add_filter( 'bp_core_get_allowed_avatar_types', array( __CLASS__, 'allowed_types' ) );
    add_filter( 'bp_core_get_allowed_avatar_mimes', array( __CLASS__, 'allowed_mimes' ) );
  }

  /** Own account only: nonce + the current user; the request never names a user. */
  public static function handle_remove(): void {
    $nonce = isset( $_POST['_ghca_ecd_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ghca_ecd_nonce'] ) ) : '';
    if ( ! is_user_logged_in() || ! wp_verify_nonce( $nonce, 'ghca_ecd_remove_photo' ) ) {
      wp_die( esc_html__( 'Your session expired. Reload the page and try again.', 'ghca-ecd' ), '', array( 'response' => 403 ) );
    }
    if ( function_exists( 'bp_core_delete_existing_avatar' ) ) {
      bp_core_delete_existing_avatar( array( 'item_id' => get_current_user_id(), 'object' => 'user' ) );
    }
    $back = isset( $_POST['ghca_ecd_back'] ) ? esc_url_raw( wp_unslash( $_POST['ghca_ecd_back'] ) ) : '';
    wp_safe_redirect( wp_validate_redirect( $back, home_url( '/' ) ) );
    exit;
  }

  /** Arriving on the change-avatar screen from the dashboard: remember where to send the employee back. */
  public static function remember_return(): void {
    if ( ! is_user_logged_in() || empty( $_GET['ghca_ecd_return'] ) ) {
      return;
    }
    $nonce = sanitize_text_field( wp_unslash( $_GET['ghca_ecd_return'] ) );
    if ( ! wp_verify_nonce( $nonce, 'ghca_ecd_avatar_return' ) ) {
      return;
    }
    $back = isset( $_GET['ghca_ecd_back'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['ghca_ecd_back'] ) ) ) : '';
    set_transient( self::FLOW_TRANSIENT . get_current_user_id(), wp_validate_redirect( $back, home_url( '/' ) ), self::FLOW_TTL );
  }

  private static function return_url(): string {
    return is_user_logged_in() ? (string) get_transient( self::FLOW_TRANSIENT . get_current_user_id() ) : '';
  }

  public static function return_notice(): void {
    $back = self::return_url();
    if ( '' === $back || ( function_exists( 'bp_is_my_profile' ) && ! bp_is_my_profile() ) ) {
      return;
    }
    echo '<p class="bp-feedback info"><span class="bp-icon" aria-hidden="true"></span><span class="bp-help-text">'
      . esc_html__( 'JPG, PNG or WebP. When your photo is saved,', 'ghca-ecd' ) . ' <a href="' . esc_url( $back ) . '">' . esc_html__( 'go back to your dashboard', 'ghca-ecd' ) . '</a>.</span></p>';
  }

  /** @param mixed $types */
  public static function allowed_types( $types ) {
    return '' !== self::return_url() ? array( 'jpeg', 'png', 'webp' ) : $types;
  }

  /** @param mixed $mimes */
  public static function allowed_mimes( $mimes ) {
    return '' !== self::return_url()
      ? array( 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' )
      : $mimes;
  }
}
