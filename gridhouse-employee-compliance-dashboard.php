<?php
/**
 * Plugin Name: Gridhouse Employee Compliance Dashboard
 * Description: Employee compliance home dashboard shortcodes for LearnDash + Elementor.
 * Version: 1.3.0
 * Author: Gridhouse Digital
 * Author URI: https://gridhouse.digital
 * Text Domain: ghca-ecd
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'GHCA_Dashboard_Branding', false ) ) {
  $brand_file = WP_PLUGIN_DIR . '/gridhouse-admin-compliance-dashboard/includes/class-branding.php';
  if ( is_readable( $brand_file ) ) {
    require_once $brand_file;
  }
}

require_once __DIR__ . '/includes/class-ui-icons.php';

/**
 * Bootstrap: constants, asset enqueue, and page detection. The data engine
 * lives in GHCA_ECD_Data_Provider, markup in GHCA_ECD_Shortcodes, and the
 * settings page in GHCA_ECD_Settings.
 */
final class GHCA_Employee_Compliance_Dashboard {
  const OPTION_DUE_DATE  = 'ghca_compliance_due_date';
  const OPTION_CACHE_TTL = 'ghca_ecd_cache_ttl';
  const VERSION          = '1.3.0';
  const CYCLE_DAYS       = 365;
  const NOTICE_DAYS      = 90;

  public static function init(): void {
    GHCA_ECD_Settings::init();
    GHCA_ECD_Data_Provider::init();
    GHCA_ECD_Shortcodes::init();

    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );
    add_filter( 'learndash-course-row-class', array( __CLASS__, 'inject_traffic_light_class' ), 10, 3 );
  }

  /**
   * Kept as delegating wrappers: the admin plugin's AJAX handlers call these
   * cross-plugin (guarded by class_exists/method_exists).
   */
  public static function bust_cache( int $user_id ): void {
    GHCA_ECD_Data_Provider::bust_cache( $user_id );
  }

  public static function bust_all_caches(): void {
    GHCA_ECD_Data_Provider::bust_all_caches();
  }

  /**
   * Inject traffic light class to LearnDash course row based on expiration.
   *
   * @param string   $class   Existing classes.
   * @param \WP_Post $course  The course object.
   * @param int      $user_id The user ID.
   */
  public static function inject_traffic_light_class( string $class, $course, int $user_id ): string {
    if ( ! class_exists( 'GHCA_Course_Lifespans' ) || ! $course || empty( $course->ID ) ) {
      return $class;
    }

    $completed    = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $course->ID ) : false;
    $completed_ts = $completed && function_exists( 'learndash_user_get_course_completed_date' ) ? (int) learndash_user_get_course_completed_date( $user_id, $course->ID ) : 0;

    $decorated = GHCA_Course_Lifespans::decorate( $course->ID, $completed, $completed_ts );
    $state     = $decorated['compliance_state']; // current, expiring_soon, expired, incomplete

    return $class . ' ghca-traffic-light ghca-traffic-light--' . esc_attr( $state );
  }

  public static function enqueue_assets(): void {
    if ( ! is_singular() ) {
      return;
    }

    $post = get_post();
    if ( ! $post ) {
      return;
    }

    if ( ! self::page_uses_dashboard( $post ) ) {
      return;
    }

    wp_enqueue_style(
      'ghca-employee-dashboard-font',
      'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
      array(),
      null
    );

    wp_enqueue_style(
      'ghca-employee-dashboard',
      plugin_dir_url( __FILE__ ) . 'assets/dashboard.css',
      array( 'ghca-employee-dashboard-font' ),
      self::VERSION
    );

    if ( class_exists( 'GHCA_Dashboard_Branding', false ) ) {
      wp_add_inline_style( 'ghca-employee-dashboard', GHCA_Dashboard_Branding::get_inline_css() );
    }
  }

  /** @param array<int,string> $classes */
  public static function add_body_class( array $classes ): array {
    if ( ! is_singular() ) {
      return $classes;
    }

    $post = get_post();
    if ( $post && self::page_uses_dashboard( $post ) ) {
      $classes[] = 'ghca-ecd-page';
    }

    return $classes;
  }

  private static function page_uses_dashboard( WP_Post $post ): bool {
    $tags = array(
      'employee_compliance_login_gate',
      'employee_compliance_notification',
      'employee_compliance_welcome',
      'employee_compliance_status_cards',
      'employee_next_required_course',
      'employee_required_actions',
      'employee_compliance_announcements',
      'employee_compliance_quick_links',
      'employee_compliance_support',
      'employee_compliance_trio',
      'employee_compliance_dashboard',
    );

    foreach ( $tags as $tag ) {
      if ( has_shortcode( $post->post_content, $tag ) ) {
        return true;
      }
    }

    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
    return is_string( $elementor_data ) && strpos( $elementor_data, 'employee_compliance' ) !== false;
  }
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-data-provider.php';
require_once __DIR__ . '/includes/class-shortcodes.php';

GHCA_Employee_Compliance_Dashboard::init();
