<?php
/**
 * Plugin Name: Gridhouse Employee Compliance Dashboard
 * Description: Employee compliance home dashboard shortcodes for LearnDash + Elementor.
 * Version: 1.6.4
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
  const OPTION_DUE_DATE     = 'ghca_compliance_due_date';
  const OPTION_CACHE_TTL    = 'ghca_ecd_cache_ttl';
  const OPTION_START_GROUPS = 'ghca_ecd_start_group_ids';
  const VERSION             = '1.6.4';
  const CYCLE_DAYS       = 365;
  const NOTICE_DAYS      = 90;

  public static function init(): void {
    add_action( 'init', static function (): void {
      load_plugin_textdomain( 'ghca-ecd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    } );

    GHCA_ECD_Settings::init();
    GHCA_ECD_Data_Provider::init();
    GHCA_ECD_Shortcodes::init();
    GHCA_ECD_Cert_Packet::init();
    GHCA_ECD_Profile_Photo::init();

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
    $training_year = GHCA_ECD_Training_Year::active();
    if ( ( ! $training_year && ! class_exists( 'GHCA_Course_Lifespans' ) ) || ! $course || empty( $course->ID ) ) {
      return $class;
    }

    $completed    = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $course->ID ) : false;
    $completed_ts = $completed && function_exists( 'learndash_user_get_course_completed_date' ) ? (int) learndash_user_get_course_completed_date( $user_id, $course->ID ) : 0;

    // Training-year switch on: the admin calculator owns per-course expiry (decorate() zeros annual ODP courses).
    $decorated = $training_year
      ? GHCA_ECD_Training_Year::course( (int) $user_id, (int) $course->ID, $completed, $completed_ts )
      : GHCA_Course_Lifespans::decorate( $course->ID, $completed, $completed_ts );
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

    // The traffic-light dots live on LearnDash profile pages, which are not
    // dashboard pages — they get a small self-contained stylesheet instead
    // (deliberately without the ghca-ecd-page body class or its width overrides).
    if ( self::page_uses_traffic_light( $post ) ) {
      wp_enqueue_style(
        'ghca-employee-traffic-light',
        plugin_dir_url( __FILE__ ) . 'assets/traffic-light.css',
        array(),
        self::VERSION
      );
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

    // Opt-in redesign: the scoped design-system stylesheet and its behaviour
    // script. With the redesign on, [employee_compliance_dashboard] and every
    // legacy section shortcode render the new dashboard (once per page);
    // dashboard.css stays loaded only for the login gate, the standalone
    // [employee_certificate_packet] card and the packet progress modal.
    // Versioned by file time as well as the plugin version, so a rebuilt file is never served from a stale
    // browser, plugin or CDN cache under an unchanged ?ver=.
    if ( GHCA_ECD_Redesign::is_enabled() ) {
      wp_enqueue_style(
        'ghca-employee-redesign',
        plugin_dir_url( __FILE__ ) . 'assets/ecd-redesign.css',
        array( 'ghca-employee-dashboard' ),
        self::asset_version( 'assets/ecd-redesign.css' )
      );
      wp_enqueue_script(
        'ghca-employee-redesign',
        plugin_dir_url( __FILE__ ) . 'assets/ecd-redesign.js',
        array(),
        self::asset_version( 'assets/ecd-redesign.js' ),
        true
      );
    }

    if ( GHCA_ECD_Cert_Packet::is_available() && is_user_logged_in() ) {
      wp_enqueue_script(
        'ghca-employee-dashboard',
        plugin_dir_url( __FILE__ ) . 'assets/dashboard.js',
        array(),
        self::asset_version( 'assets/dashboard.js' ),
        true
      );
      wp_localize_script(
        'ghca-employee-dashboard',
        'ghcaEcd',
        array(
          'ajaxUrl' => admin_url( 'admin-ajax.php' ),
          'nonce'   => wp_create_nonce( GHCA_ECD_Cert_Packet::NONCE_ACTION ),
          'i18n'    => array(
            'preparing' => __( 'Preparing your packet…', 'ghca-ecd' ),
            'fetching'  => __( 'Fetching certificate %1$s of %2$s…', 'ghca-ecd' ),
            'merging'   => __( 'Merging documents…', 'ghca-ecd' ),
            'done'      => __( 'Done! Starting download…', 'ghca-ecd' ),
            'error'     => __( 'Packet generation failed. No packet was created. Please try again.', 'ghca-ecd' ),
          ),
        )
      );
    }
  }

  private static function asset_version( string $relative ): string {
    $mtime = @filemtime( plugin_dir_path( __FILE__ ) . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    return self::VERSION . ( $mtime ? '-' . $mtime : '' );
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

  private static function page_uses_traffic_light( WP_Post $post ): bool {
    if ( has_shortcode( $post->post_content, 'ld_profile' ) || has_block( 'learndash/ld-profile', $post ) ) {
      return true;
    }

    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
    return is_string( $elementor_data ) && strpos( $elementor_data, 'ld_profile' ) !== false;
  }

  public static function page_uses_dashboard( WP_Post $post ): bool {
    $tags = array(
      'employee_certificate_packet',
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
      'employee_certificates',
    );

    foreach ( $tags as $tag ) {
      if ( has_shortcode( $post->post_content, $tag ) ) {
        return true;
      }
    }

    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
    return is_string( $elementor_data )
      && ( strpos( $elementor_data, 'employee_compliance' ) !== false
        || strpos( $elementor_data, 'employee_certificate_packet' ) !== false );
  }
}

require_once __DIR__ . '/includes/class-training-year.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-data-provider.php';
require_once __DIR__ . '/includes/class-shortcodes.php';
require_once __DIR__ . '/includes/class-redesign.php';
require_once __DIR__ . '/includes/class-profile-photo.php';
require_once __DIR__ . '/includes/class-cert-packet.php';

GHCA_Employee_Compliance_Dashboard::init();
