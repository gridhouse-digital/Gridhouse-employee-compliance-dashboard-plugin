<?php
/**
 * Plugin Name: Gridhouse Employee Compliance Dashboard
 * Description: Employee compliance home dashboard shortcodes for LearnDash + Elementor.
 * Version: 1.2.0
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

final class GHCA_Employee_Compliance_Dashboard {
  const OPTION_DUE_DATE  = 'ghca_compliance_due_date';
  const OPTION_CACHE_TTL = 'ghca_ecd_cache_ttl';
  const VERSION          = '1.2.0';
  const CYCLE_DAYS       = 365;
  const NOTICE_DAYS      = 90;

  /** @var array<string,mixed>|null */
  private static $snapshot = null;

  /** @var array<int,array<int,array<string,mixed>>> Per-request memo, keyed by user ID. */
  private static $required_courses = array();

  /** @var array<int,array<string,mixed>> Per-request memo, keyed by user ID. */
  private static $notification = array();

  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
    add_action( 'init', array( __CLASS__, 'maybe_seed_announcements' ), 999 );
    add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    add_filter( 'body_class', array( __CLASS__, 'add_body_class' ) );
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
    add_filter( 'learndash-course-row-class', array( __CLASS__, 'inject_traffic_light_class' ), 10, 3 );

    // Cache busting: keep the persistent snapshot honest when LearnDash data
    // or the options it derives from change.
    add_action( 'learndash_course_completed', array( __CLASS__, 'on_course_completed' ) );
    add_action( 'learndash_update_course_access', array( __CLASS__, 'on_course_access_changed' ), 10, 4 );
    add_action( 'ld_added_group_access', array( __CLASS__, 'on_group_access_changed' ), 10, 2 );
    add_action( 'ld_removed_group_access', array( __CLASS__, 'on_group_access_changed' ), 10, 2 );
    add_action( 'update_option_' . self::OPTION_DUE_DATE, array( __CLASS__, 'bust_all_caches' ) );
    add_action( 'update_option_' . self::OPTION_CACHE_TTL, array( __CLASS__, 'bust_all_caches' ) );

    if ( class_exists( 'GHCA_Course_Lifespans' ) ) {
      add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_LIFESPANS, array( __CLASS__, 'bust_all_caches' ) );
      add_action( 'update_option_' . GHCA_Course_Lifespans::OPTION_WARNING_DAYS, array( __CLASS__, 'bust_all_caches' ) );
    }
    if ( class_exists( 'GHCA_Compliance_Program' ) ) {
      add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS, array( __CLASS__, 'bust_all_caches' ) );
      add_action( 'update_option_' . GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS, array( __CLASS__, 'bust_all_caches' ) );
    }
  }

  /** @param array<string,mixed> $data LearnDash completion payload; $data['user'] is a WP_User. */
  public static function on_course_completed( $data ): void {
    if ( is_array( $data ) && ! empty( $data['user'] ) && $data['user'] instanceof WP_User ) {
      self::bust_cache( (int) $data['user']->ID );
    }
  }

  /** @param array<int,mixed> $access_list */
  public static function on_course_access_changed( $user_id, $course_id = 0, $access_list = array(), $remove = false ): void {
    self::bust_cache( (int) $user_id );
  }

  public static function on_group_access_changed( $user_id, $group_id = 0 ): void {
    self::bust_cache( (int) $user_id );
  }

  /**
   * Pure key builder so the format is unit-testable without WordPress.
   * The Ymd stamp (site timezone) makes the snapshot self-invalidate at local
   * midnight, so day-based countdowns and traffic lights recompute with no DB write.
   */
  public static function build_cache_key( string $version, int $user_id, string $ymd ): string {
    return 'ghca_ecd_snap_' . $version . '_' . $user_id . '_' . $ymd;
  }

  private static function get_cache_key( int $user_id ): string {
    return self::build_cache_key( self::VERSION, $user_id, (string) wp_date( 'Ymd' ) );
  }

  public static function get_cache_ttl(): int {
    $ttl = (int) get_option( self::OPTION_CACHE_TTL, 600 );
    return max( 0, min( 3600, $ttl ) );
  }

  /** Delete one user's snapshot for today and reset the request-scoped memos. */
  public static function bust_cache( int $user_id ): void {
    if ( $user_id <= 0 ) {
      return;
    }
    delete_transient( self::get_cache_key( $user_id ) );
    unset( self::$required_courses[ $user_id ], self::$notification[ $user_id ] );
    if ( null !== self::$snapshot && (int) ( self::$snapshot['user_id'] ?? 0 ) === $user_id ) {
      self::$snapshot = null;
    }
  }

  /** Delete every stored snapshot (option-level changes affect all users). */
  public static function bust_all_caches(): void {
    global $wpdb;
    // Delete through the transient API (not raw SQL) so the in-request options
    // cache is cleared too — otherwise a bust fired mid-request would still
    // serve the stale snapshot for the rest of that request.
    $names = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_ghca_ecd_snap_' ) . '%'
      )
    );
    foreach ( (array) $names as $name ) {
      delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
    }
    self::$snapshot         = null;
    self::$required_courses = array();
    self::$notification     = array();
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

  public static function register_shortcodes(): void {
    $map = array(
      'employee_compliance_login_gate'    => 'render_login_gate',
      'employee_compliance_notification'  => 'render_compliance_notification',
      'employee_compliance_welcome'       => 'render_welcome',
      'employee_compliance_status_cards'  => 'render_status_cards',
      'employee_next_required_course'     => 'render_next_course',
      'employee_required_actions'         => 'render_required_actions',
      'employee_compliance_announcements' => 'render_announcements',
      'employee_compliance_quick_links'   => 'render_quick_links',
      'employee_compliance_support'       => 'render_support',
      'employee_compliance_trio'          => 'render_trio',
      'employee_compliance_dashboard'     => 'render_full_dashboard',
    );

    foreach ( $map as $tag => $method ) {
      add_shortcode( $tag, array( __CLASS__, $method ) );
    }
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

  public static function register_settings(): void {
    register_setting(
      'ghca_employee_dashboard',
      self::OPTION_DUE_DATE,
      array(
        'type'              => 'string',
        'sanitize_callback' => array( __CLASS__, 'sanitize_due_date' ),
        'default'           => '2026-07-31',
      )
    );

    register_setting(
      'ghca_employee_dashboard',
      self::OPTION_CACHE_TTL,
      array(
        'type'              => 'integer',
        'sanitize_callback' => array( __CLASS__, 'sanitize_cache_ttl' ),
        'default'           => 600,
      )
    );
  }

  /** @param mixed $value */
  public static function sanitize_cache_ttl( $value ): int {
    return max( 0, min( 3600, (int) $value ) );
  }

  public static function sanitize_due_date( string $value ): string {
    $value = trim( $value );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
      return $value;
    }
    return '2026-07-31';
  }

  public static function register_settings_page(): void {
    add_options_page(
      'Employee Compliance Dashboard',
      'Employee Dashboard',
      'manage_options',
      'ghca-employee-dashboard',
      array( __CLASS__, 'render_settings_page' )
    );
  }

  public static function render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $due = get_option( self::OPTION_DUE_DATE, '2026-07-31' );
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Employee Compliance Dashboard', 'ghca-ecd' ); ?></h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_employee_dashboard' ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_compliance_due_date"><?php esc_html_e( 'Compliance due date', 'ghca-ecd' ); ?></label></th>
            <td><input type="date" id="ghca_compliance_due_date" name="<?php echo esc_attr( self::OPTION_DUE_DATE ); ?>" value="<?php echo esc_attr( $due ); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_ecd_cache_ttl"><?php esc_html_e( 'Dashboard cache lifetime (seconds)', 'ghca-ecd' ); ?></label></th>
            <td>
              <input type="number" id="ghca_ecd_cache_ttl" name="<?php echo esc_attr( self::OPTION_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) self::get_cache_ttl() ); ?>" min="0" max="3600" step="1" class="small-text" />
              <p class="description"><?php esc_html_e( 'How long each employee\'s dashboard snapshot is cached (0 disables caching, max 3600). The cache also refreshes automatically at midnight and whenever training data changes.', 'ghca-ecd' ); ?></p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  public static function render_full_dashboard( $atts = array() ): string {
    return self::render_login_gate( $atts )
      . self::render_compliance_notification( $atts )
      . self::render_welcome( $atts )
      . self::render_status_cards( $atts )
      . self::render_trio( $atts )
      . self::render_quick_links( $atts )
      . self::render_support( $atts );
  }

  public static function render_login_gate( $atts = array() ): string {
    if ( is_user_logged_in() ) {
      return '';
    }

    $login_url = wp_login_url( get_permalink() ?: home_url( '/employee-dashboard/' ) );
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--gate">
      <div class="ghca-ecd__card ghca-ecd__card--center">
        <h2><?php esc_html_e( 'Employee Dashboard', 'ghca-ecd' ); ?></h2>
        <p><?php esc_html_e( 'Please log in to view your compliance training dashboard.', 'ghca-ecd' ); ?></p>
        <a class="ghca-ecd__btn ghca-ecd__btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'ghca-ecd' ); ?></a>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_compliance_notification( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    // Read the notification off the snapshot so a transient-cached page load
    // never re-runs the LearnDash course loop just for this banner.
    $data   = self::get_snapshot();
    $notice = is_array( $data['compliance_notification'] ?? null )
      ? $data['compliance_notification']
      : self::get_compliance_notification( get_current_user_id() );
    if ( empty( $notice['show'] ) ) {
      return '';
    }
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--notification">
      <div class="ghca-ecd__notice ghca-ecd__notice--<?php echo esc_attr( $notice['level'] ); ?>" role="status">
        <div class="ghca-ecd__notice-copy">
          <span class="ghca-ecd__notice-label"><?php echo esc_html( $notice['title'] ?? __( 'Compliance Notification', 'ghca-ecd' ) ); ?></span>
          <p class="ghca-ecd__notice-message"><?php echo esc_html( $notice['message'] ); ?></p>
          <?php if ( ! empty( $notice['meta_label'] ) ) : ?>
            <p class="ghca-ecd__notice-meta"><?php echo esc_html( $notice['meta_label'] ); ?></p>
          <?php endif; ?>
        </div>
        <?php if ( ! empty( $notice['show_cta'] ) && ! empty( $data['continue_url'] ) ) : ?>
          <a class="ghca-ecd__btn ghca-ecd__btn--notice" href="<?php echo esc_url( $data['continue_url'] ); ?>">
            <?php echo esc_html( $notice['cta_label'] ?? __( 'Continue Training', 'ghca-ecd' ) ); ?>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_welcome( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data    = self::get_snapshot();
    $first   = $data['first_name'] ?: $data['display_name'];
    $total   = (int) $data['total_courses'];
    $done    = (int) $data['completed_count'];
    $percent = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;
    $avatar  = get_avatar( (int) $data['user_id'], 120, '', $first, array( 'class' => 'ghca-ecd__avatar-img' ) );

    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--welcome">
      <div class="ghca-ecd__welcome">
        <div class="ghca-ecd__welcome-id">
          <div class="ghca-ecd__avatar">
            <?php
            if ( $avatar ) {
              echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
              echo GHCA_ECD_UI_Icons::render( 'user', 'ghca-ecd__avatar-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
          </div>
          <div class="ghca-ecd__welcome-text">
            <h1 class="ghca-ecd__welcome-title"><?php echo esc_html( sprintf( __( 'Welcome, %s', 'ghca-ecd' ), $first ) ); ?></h1>
            <p class="ghca-ecd__welcome-subtitle"><?php esc_html_e( 'to your Employee Compliance Dashboard', 'ghca-ecd' ); ?></p>
          </div>
        </div>
        <div class="ghca-ecd__welcome-aside">
          <div class="ghca-ecd__ring" style="background: conic-gradient(var(--ghca-secondary) <?php echo esc_attr( (string) $percent ); ?>%, #e9ecef 0);" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d%% of required training complete', 'ghca-ecd' ), $percent ) ); ?>">
            <div class="ghca-ecd__ring-inner">
              <span class="ghca-ecd__ring-value"><?php echo esc_html( (string) $percent ); ?><span class="ghca-ecd__ring-pct">%</span></span>
              <span class="ghca-ecd__ring-label"><?php esc_html_e( 'Complete', 'ghca-ecd' ); ?></span>
            </div>
          </div>
          <?php if ( $data['all_complete'] && ! empty( $data['certificate_url'] ) ) : ?>
            <a class="ghca-ecd__btn ghca-ecd__btn--ghost" href="<?php echo esc_url( $data['certificate_url'] ); ?>"><?php esc_html_e( 'View Certificate', 'ghca-ecd' ); ?> <?php echo GHCA_ECD_UI_Icons::render( 'chevron_right', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
          <?php elseif ( $data['all_complete'] ) : ?>
            <span class="ghca-ecd__badge ghca-ecd__badge--success"><?php esc_html_e( 'All training complete', 'ghca-ecd' ); ?></span>
          <?php else : ?>
            <a class="ghca-ecd__btn ghca-ecd__btn--ghost" href="<?php echo esc_url( $data['continue_url'] ); ?>"><?php esc_html_e( 'Continue Training', 'ghca-ecd' ); ?> <?php echo GHCA_ECD_UI_Icons::render( 'chevron_right', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_status_cards( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data           = self::get_snapshot();
    $is_new_hire    = ! empty( $data['new_hire']['active'] ) && empty( $data['new_hire']['complete'] );
    $status_badge   = self::get_status_badge_class( (string) $data['training_status'] );
    $due_is_warning = $is_new_hire || ! empty( $data['new_hire_flagged'] );
    $total          = (int) $data['total_courses'];
    $done           = (int) $data['completed_count'];
    $percent        = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;
    $days_left      = self::get_due_days_remaining( $data, $is_new_hire );
    $status_state   = (string) $data['training_status'];
    if ( 'expired' === $status_state || 'new_hire_overdue' === $status_state ) {
      $status_tone = 'danger';
      $status_icon = 'alert';
    } elseif ( 'expiring_soon' === $status_state ) {
      $status_tone = 'amber';
      $status_icon = 'clock';
    } elseif ( $is_new_hire || ! empty( $data['new_hire_flagged'] ) ) {
      $status_tone = 'amber';
      $status_icon = 'flag';
    } else {
      $status_tone = 'blue';
      $status_icon = 'compliance';
    }

    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--cards">
      <div class="ghca-ecd__kpi-grid">

        <div class="ghca-ecd__kpi<?php echo ! empty( $data['new_hire_flagged'] ) ? ' ghca-ecd__kpi--alert' : ''; ?>">
          <div class="ghca-ecd__kpi-top">
            <span class="ghca-ecd__tile ghca-ecd__tile--<?php echo esc_attr( $status_tone ); ?>"><?php echo GHCA_ECD_UI_Icons::render( $status_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="ghca-ecd__kpi-tag"><?php esc_html_e( 'Status', 'ghca-ecd' ); ?></span>
          </div>
          <div class="ghca-ecd__kpi-body">
            <span class="ghca-ecd__kpi-label"><?php echo $is_new_hire ? esc_html__( 'New Hire Status', 'ghca-ecd' ) : esc_html__( 'Training Status', 'ghca-ecd' ); ?></span>
            <span class="ghca-ecd__pill ghca-ecd__pill--dot <?php echo esc_attr( $status_badge ); ?>"><?php echo esc_html( $data['training_status_label'] ); ?></span>
          </div>
          <p class="ghca-ecd__kpi-foot"><?php echo esc_html( self::get_status_foot( (string) $data['training_status'], $is_new_hire ) ); ?></p>
        </div>

        <div class="ghca-ecd__kpi">
          <div class="ghca-ecd__kpi-top">
            <span class="ghca-ecd__tile ghca-ecd__tile--blue"><?php echo GHCA_ECD_UI_Icons::render( 'courses' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="ghca-ecd__kpi-tag"><?php esc_html_e( 'Progress', 'ghca-ecd' ); ?></span>
          </div>
          <div class="ghca-ecd__kpi-body">
            <span class="ghca-ecd__kpi-label"><?php esc_html_e( 'Required Courses', 'ghca-ecd' ); ?></span>
            <?php if ( $total > 0 ) : ?>
              <span class="ghca-ecd__kpi-value"><?php echo esc_html( (string) $done ); ?> <span class="ghca-ecd__kpi-unit"><?php echo esc_html( sprintf( __( 'of %d completed', 'ghca-ecd' ), $total ) ); ?></span></span>
            <?php else : ?>
              <span class="ghca-ecd__kpi-value ghca-ecd__kpi-value--muted"><?php esc_html_e( 'None assigned', 'ghca-ecd' ); ?></span>
            <?php endif; ?>
          </div>
          <?php if ( $total > 0 ) : ?>
            <div class="ghca-ecd__kpi-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>">
              <span class="ghca-ecd__kpi-bar-fill" style="width: <?php echo esc_attr( (string) max( 2, $percent ) ); ?>%;"></span>
            </div>
          <?php else : ?>
            <p class="ghca-ecd__kpi-foot"><?php esc_html_e( 'Courses appear here once assigned.', 'ghca-ecd' ); ?></p>
          <?php endif; ?>
        </div>

        <div class="ghca-ecd__kpi">
          <div class="ghca-ecd__kpi-top">
            <span class="ghca-ecd__tile ghca-ecd__tile--green"><?php echo GHCA_ECD_UI_Icons::render( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="ghca-ecd__kpi-tag"><?php esc_html_e( 'Enrolled', 'ghca-ecd' ); ?></span>
          </div>
          <div class="ghca-ecd__kpi-body">
            <span class="ghca-ecd__kpi-label"><?php esc_html_e( 'Start Date', 'ghca-ecd' ); ?></span>
            <span class="ghca-ecd__kpi-value ghca-ecd__kpi-value--date"><?php echo esc_html( $data['start_date_label'] ); ?></span>
          </div>
          <p class="ghca-ecd__kpi-foot"><?php echo $is_new_hire ? esc_html__( 'Onboarding window opened.', 'ghca-ecd' ) : esc_html__( 'Your annual cycle start date.', 'ghca-ecd' ); ?></p>
        </div>

        <div class="ghca-ecd__kpi<?php echo $due_is_warning ? ' ghca-ecd__kpi--due' : ''; ?>">
          <div class="ghca-ecd__kpi-top">
            <span class="ghca-ecd__tile ghca-ecd__tile--amber"><?php echo GHCA_ECD_UI_Icons::render( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <?php if ( null !== $days_left && $days_left >= 0 ) : ?>
              <span class="ghca-ecd__kpi-tag ghca-ecd__kpi-tag--due"><?php echo esc_html( sprintf( _n( '%d day left', '%d days left', $days_left, 'ghca-ecd' ), $days_left ) ); ?></span>
            <?php else : ?>
              <span class="ghca-ecd__kpi-tag"><?php esc_html_e( 'Due', 'ghca-ecd' ); ?></span>
            <?php endif; ?>
          </div>
          <div class="ghca-ecd__kpi-body">
            <span class="ghca-ecd__kpi-label"><?php echo $is_new_hire ? esc_html__( 'New Hire Due Date', 'ghca-ecd' ) : esc_html__( 'Compliance Due Date', 'ghca-ecd' ); ?></span>
            <span class="ghca-ecd__kpi-value ghca-ecd__kpi-value--date<?php echo $due_is_warning ? ' ghca-ecd__kpi-value--warning' : ''; ?>"><?php echo esc_html( $data['compliance_due_date_label'] ); ?></span>
          </div>
          <p class="ghca-ecd__kpi-foot<?php echo $due_is_warning ? ' ghca-ecd__kpi-foot--warning' : ''; ?>"><?php esc_html_e( 'Complete all courses before this date.', 'ghca-ecd' ); ?></p>
        </div>

        <div class="ghca-ecd__kpi">
          <div class="ghca-ecd__kpi-top">
            <span class="ghca-ecd__tile ghca-ecd__tile--neutral"><?php echo GHCA_ECD_UI_Icons::render( 'award' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <span class="ghca-ecd__kpi-tag"><?php esc_html_e( 'Reward', 'ghca-ecd' ); ?></span>
          </div>
          <div class="ghca-ecd__kpi-body">
            <span class="ghca-ecd__kpi-label"><?php esc_html_e( 'Certificate', 'ghca-ecd' ); ?></span>
            <?php if ( ! empty( $data['certificate_url'] ) ) : ?>
              <a class="ghca-ecd__btn ghca-ecd__btn--secondary ghca-ecd__btn--compact" href="<?php echo esc_url( $data['certificate_url'] ); ?>"><?php esc_html_e( 'View Certificate', 'ghca-ecd' ); ?></a>
            <?php else : ?>
              <span class="ghca-ecd__pill ghca-ecd__pill--locked"><?php echo GHCA_ECD_UI_Icons::render( 'compliance', 'ghca-ecd__pill-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Locked', 'ghca-ecd' ); ?></span>
            <?php endif; ?>
          </div>
          <p class="ghca-ecd__kpi-foot"><?php echo ! empty( $data['certificate_url'] ) ? esc_html__( 'Ready to download.', 'ghca-ecd' ) : esc_html__( 'Available after completion.', 'ghca-ecd' ); ?></p>
        </div>

      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  private static function get_status_foot( string $status, bool $is_new_hire ): string {
    switch ( $status ) {
      case 'completed':
        return __( 'All required training complete.', 'ghca-ecd' );
      case 'in_progress':
        return __( "Keep going — you're on track.", 'ghca-ecd' );
      case 'expired':
        return __( 'Action needed — a required course has expired.', 'ghca-ecd' );
      case 'expiring_soon':
        return __( 'Renew soon — a required course is expiring.', 'ghca-ecd' );
      case 'new_hire_overdue':
        return __( 'Action needed — training overdue.', 'ghca-ecd' );
      default:
        return $is_new_hire
          ? __( 'Begin your onboarding to get compliant.', 'ghca-ecd' )
          : __( 'Start your annual training.', 'ghca-ecd' );
    }
  }

  /** @param array<string,mixed> $data */
  private static function get_due_days_remaining( array $data, bool $is_new_hire ): ?int {
    if ( $is_new_hire && isset( $data['new_hire']['days_remaining'] ) && is_numeric( $data['new_hire']['days_remaining'] ) ) {
      return (int) $data['new_hire']['days_remaining'];
    }

    if ( isset( $data['compliance_notification']['days_remaining'] ) && is_numeric( $data['compliance_notification']['days_remaining'] ) ) {
      return (int) $data['compliance_notification']['days_remaining'];
    }

    return null;
  }

  public static function render_trio( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    return '<div class="ghca-ecd ghca-ecd--trio"><div class="ghca-ecd__trio">'
      . self::render_next_course( $atts )
      . self::render_required_actions( $atts )
      . self::render_announcements( $atts )
      . '</div></div>';
  }

  public static function render_next_course( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data = self::get_snapshot();
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--next">
      <?php if ( empty( $data['courses'] ) ) : ?>
        <div class="ghca-ecd__panel ghca-ecd__panel--featured">
          <h2><?php esc_html_e( 'Next Required Course', 'ghca-ecd' ); ?></h2>
          <p><?php esc_html_e( 'No required courses are assigned to your account yet. Please contact your administrator if you believe this is an error.', 'ghca-ecd' ); ?></p>
        </div>
      <?php elseif ( $data['all_complete'] ) : ?>
        <div class="ghca-ecd__panel ghca-ecd__panel--featured">
          <h2><?php esc_html_e( 'Next Required Course', 'ghca-ecd' ); ?></h2>
          <p class="ghca-ecd__success"><?php esc_html_e( "You're all caught up. No required training is pending right now.", 'ghca-ecd' ); ?></p>
        </div>
      <?php else : ?>
        <div class="ghca-ecd__hero">
          <span class="ghca-ecd__hero-glow" aria-hidden="true"></span>
          <div class="ghca-ecd__hero-top">
            <span class="ghca-ecd__hero-badge"><span class="ghca-ecd__hero-badge-dot"></span><?php esc_html_e( 'Next Up', 'ghca-ecd' ); ?></span>
            <span class="ghca-ecd__hero-meta"><?php echo GHCA_ECD_UI_Icons::render( 'clock', 'ghca-ecd__hero-meta-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $data['next_course']['module_label'] ); ?></span>
          </div>
          <p class="ghca-ecd__hero-eyebrow"><?php esc_html_e( 'Next Required Course', 'ghca-ecd' ); ?></p>
          <h3 class="ghca-ecd__hero-title"><?php echo esc_html( $data['next_course']['title'] ); ?></h3>
          <?php if ( ! empty( $data['next_course']['description'] ) ) : ?>
            <p class="ghca-ecd__hero-desc"><?php echo esc_html( $data['next_course']['description'] ); ?></p>
          <?php endif; ?>
          <div class="ghca-ecd__hero-foot">
            <div class="ghca-ecd__hero-progress-meta">
              <span><?php echo esc_html( sprintf( __( '%s complete', 'ghca-ecd' ), $data['next_course']['progress_label'] ) ); ?></span>
              <span><?php echo esc_html( $data['next_course']['module_label'] ); ?></span>
            </div>
            <div
              class="ghca-ecd__hero-bar"
              role="progressbar"
              aria-valuemin="0"
              aria-valuemax="100"
              aria-valuenow="<?php echo esc_attr( (string) $data['next_course']['progress'] ); ?>"
              aria-label="<?php echo esc_attr( sprintf( __( '%s complete', 'ghca-ecd' ), $data['next_course']['progress_label'] ) ); ?>"
            >
              <span class="ghca-ecd__hero-bar-fill" style="width: <?php echo esc_attr( (string) max( 2, min( 100, (int) $data['next_course']['progress'] ) ) ); ?>%;"></span>
            </div>
            <a class="ghca-ecd__btn ghca-ecd__btn--hero" href="<?php echo esc_url( $data['next_course']['url'] ); ?>"><?php echo esc_html( $data['next_course']['button_label'] ); ?> <?php echo GHCA_ECD_UI_Icons::render( 'arrow_right', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_required_actions( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $data       = self::get_snapshot();
    $items      = self::build_action_items( $data );
    $total      = count( $items );
    $done_count = 0;
    foreach ( $items as $item ) {
      if ( ! empty( $item['done'] ) ) {
        ++$done_count;
      }
    }
    $is_new_hire = ! empty( $data['new_hire']['active'] ) && empty( $data['new_hire']['complete'] );
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--checklist">
      <div class="ghca-ecd__panel ghca-ecd__panel--actions">
        <div class="ghca-ecd__panel-head">
          <h2><?php esc_html_e( 'Required Actions', 'ghca-ecd' ); ?></h2>
          <span class="ghca-ecd__count-pill"><?php echo esc_html( sprintf( __( '%1$d/%2$d done', 'ghca-ecd' ), $done_count, $total ) ); ?></span>
        </div>
        <p class="ghca-ecd__panel-sub"><?php echo $is_new_hire ? esc_html__( 'Your onboarding compliance checklist.', 'ghca-ecd' ) : esc_html__( 'Your annual compliance checklist.', 'ghca-ecd' ); ?></p>
        <ul class="ghca-ecd__checklist">
          <?php foreach ( $items as $item ) : ?>
            <li class="ghca-ecd__checklist-item <?php echo $item['done'] ? 'is-done' : 'is-pending'; ?>">
              <span class="ghca-ecd__checkmark" aria-hidden="true"></span>
              <span><?php echo esc_html( $item['label'] ); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_announcements( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $items = self::get_announcement_items();

    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--announcements">
      <div class="ghca-ecd__panel ghca-ecd__panel--announce">
        <div class="ghca-ecd__panel-head">
          <h2><?php esc_html_e( 'Announcements', 'ghca-ecd' ); ?></h2>
        </div>
        <p class="ghca-ecd__panel-sub"><?php esc_html_e( 'Reminders to keep you on track.', 'ghca-ecd' ); ?></p>
        <?php if ( empty( $items ) ) : ?>
          <p><?php esc_html_e( 'No announcements at this time.', 'ghca-ecd' ); ?></p>
        <?php else : ?>
          <div class="ghca-ecd__announce-list">
            <?php foreach ( $items as $item ) : ?>
              <?php $tone = isset( $item['tone'] ) ? (string) $item['tone'] : 'neutral'; ?>
              <div class="ghca-ecd__announce ghca-ecd__announce--<?php echo esc_attr( $tone ); ?>">
                <span class="ghca-ecd__tile ghca-ecd__tile--<?php echo esc_attr( $tone ); ?>"><?php echo GHCA_ECD_UI_Icons::render( isset( $item['icon'] ) ? (string) $item['icon'] : 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <div class="ghca-ecd__announce-copy">
                  <span class="ghca-ecd__announce-title">
                    <?php if ( ! empty( $item['url'] ) ) : ?>
                      <a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
                    <?php else : ?>
                      <?php echo esc_html( $item['title'] ); ?>
                    <?php endif; ?>
                  </span>
                  <?php if ( ! empty( $item['meta'] ) ) : ?>
                    <span class="ghca-ecd__announce-meta"><?php echo esc_html( $item['meta'] ); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** @return array<int,array{title:string,meta?:string,tone:string,icon:string,url?:string}> */
  private static function get_announcement_items(): array {
    $presets = array(
      array( 'tone' => 'warning', 'icon' => 'alert' ),
      array( 'tone' => 'success', 'icon' => 'status' ),
      array( 'tone' => 'info', 'icon' => 'bell' ),
      array( 'tone' => 'neutral', 'icon' => 'document' ),
    );

    $posts = get_posts(
      array(
        'post_type'              => 'ghca-announce',
        'post_status'            => 'publish',
        'posts_per_page'         => 5,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );

    if ( ! empty( $posts ) ) {
      $items = array();
      foreach ( $posts as $index => $post ) {
        $preset = $presets[ $index % count( $presets ) ];
        $title  = trim( (string) get_the_title( $post ) );
        $body   = trim( wp_strip_all_tags( (string) $post->post_content ) );

        if ( $title === '' && $body !== '' ) {
          $title = wp_trim_words( $body, 10, '…' );
          $body  = '';
        }

        if ( $title === '' ) {
          continue;
        }

        $item = array(
          'title' => $title,
          'meta'  => $body !== '' ? wp_trim_words( $body, 16, '…' ) : '',
          'tone'  => $preset['tone'],
          'icon'  => $preset['icon'],
        );

        if ( $body !== '' ) {
          $item['url'] = get_permalink( $post );
        }

        $items[] = $item;
      }

      if ( ! empty( $items ) ) {
        return apply_filters( 'ghca_employee_announcements_items', $items );
      }
    }

    return apply_filters(
      'ghca_employee_announcements_items',
      array(
        array(
          'title' => __( 'Complete training before the deadline', 'ghca-ecd' ),
          'meta'  => __( 'Finish your required courses on time.', 'ghca-ecd' ),
          'tone'  => 'warning',
          'icon'  => 'alert',
        ),
        array(
          'title' => __( 'Annual compliance training is now open', 'ghca-ecd' ),
          'meta'  => __( 'Enroll today to stay certified.', 'ghca-ecd' ),
          'tone'  => 'success',
          'icon'  => 'status',
        ),
        array(
          'title' => __( "Can't access a course?", 'ghca-ecd' ),
          'meta'  => __( "Contact support and we'll help right away.", 'ghca-ecd' ),
          'tone'  => 'info',
          'icon'  => 'bell',
        ),
        array(
          'title' => __( 'Keep your contact details current', 'ghca-ecd' ),
          'meta'  => __( 'So you never miss a training notice.', 'ghca-ecd' ),
          'tone'  => 'neutral',
          'icon'  => 'document',
        ),
      )
    );
  }

  public static function maybe_seed_announcements(): void {
    if ( get_option( 'ghca_announcements_seeded' ) || ! post_type_exists( 'ghca-announce' ) ) {
      return;
    }

    $defaults = array(
      array( 'Annual compliance training is now open.', 'annual-compliance-training-is-now-open' ),
      array( 'Complete training before the deadline.', 'complete-training-before-the-deadline' ),
      array( 'Contact support if you cannot access a course.', 'contact-support-if-you-cannot-access-a-course' ),
    );

    foreach ( $defaults as $row ) {
      if ( get_page_by_path( $row[1], OBJECT, 'ghca-announce' ) ) {
        continue;
      }

      wp_insert_post(
        array(
          'post_type'    => 'ghca-announce',
          'post_status'  => 'publish',
          'post_title'   => $row[0],
          'post_name'    => $row[1],
          'post_content' => '',
        )
      );
    }

    update_option( 'ghca_announcements_seeded', 1, false );
  }

  public static function render_quick_links( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $links = self::get_quick_links();
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--links">
      <div class="ghca-ecd__links-head">
        <h2><?php esc_html_e( 'Quick Links', 'ghca-ecd' ); ?></h2>
        <span class="ghca-ecd__links-caption"><?php esc_html_e( 'Shortcuts to your resources', 'ghca-ecd' ); ?></span>
      </div>
      <div class="ghca-ecd__ql-grid">
        <?php foreach ( $links as $link ) : ?>
          <?php $tone = isset( $link['tone'] ) ? (string) $link['tone'] : 'blue'; ?>
          <a class="ghca-ecd__ql-card" href="<?php echo esc_url( $link['url'] ); ?>">
            <?php if ( ! empty( $link['icon'] ) ) : ?>
              <span class="ghca-ecd__tile ghca-ecd__tile--<?php echo esc_attr( $tone ); ?> ghca-ecd__tile--lg"><?php echo GHCA_ECD_UI_Icons::render( (string) $link['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
            <?php endif; ?>
            <span class="ghca-ecd__ql-text">
              <strong class="ghca-ecd__ql-title"><?php echo esc_html( $link['label'] ); ?></strong>
              <?php if ( ! empty( $link['subtitle'] ) ) : ?>
                <span class="ghca-ecd__ql-sub"><?php echo esc_html( $link['subtitle'] ); ?></span>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_support( $atts = array() ): string {
    if ( ! self::can_view_dashboard() ) {
      return '';
    }

    $email = class_exists( 'GHCA_Dashboard_Branding', false )
      ? GHCA_Dashboard_Branding::get_support_email()
      : apply_filters( 'ghca_employee_support_email', 'info@lafiahealthcare.org' );
    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--support">
      <div class="ghca-ecd__support">
        <div class="ghca-ecd__support-main">
          <span class="ghca-ecd__tile ghca-ecd__tile--blue ghca-ecd__tile--xl"><?php echo GHCA_ECD_UI_Icons::render( 'lifebuoy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
          <div class="ghca-ecd__support-text">
            <h2 class="ghca-ecd__support-title"><?php esc_html_e( 'Need help accessing your training?', 'ghca-ecd' ); ?></h2>
            <p class="ghca-ecd__support-sub">
              <?php esc_html_e( 'Our team responds quickly — reach us at', 'ghca-ecd' ); ?>
              <a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
            </p>
          </div>
        </div>
        <a class="ghca-ecd__btn ghca-ecd__btn--support" href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo GHCA_ECD_UI_Icons::render( 'mail', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Contact Support', 'ghca-ecd' ); ?></a>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  private static function can_view_dashboard(): bool {
    return is_user_logged_in();
  }

  /** @return array<string,mixed> */
  private static function get_snapshot(): array {
    if ( null !== self::$snapshot ) {
      return self::$snapshot;
    }

    $user_id = get_current_user_id();
    $ttl     = self::get_cache_ttl();

    if ( $ttl > 0 ) {
      $cached = get_transient( self::get_cache_key( $user_id ) );
      if ( is_array( $cached ) && (int) ( $cached['user_id'] ?? 0 ) === $user_id ) {
        self::$snapshot = $cached;
        return self::$snapshot;
      }
    }

    $user            = get_userdata( $user_id );
    $new_hire        = self::get_new_hire_status( $user_id );
    $courses         = ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) )
      ? self::map_new_hire_courses( $new_hire )
      : self::get_required_courses( $user_id );
    $completed_count = 0;
    $started_count   = 0;
    $in_progress     = null;
    $not_started     = null;
    $certificate_url = '';

    foreach ( $courses as $course ) {
      if ( $course['completed'] ) {
        ++$completed_count;
        $cert = self::get_certificate_url( $user_id, (int) $course['id'] );
        if ( $cert ) {
          $certificate_url = $cert;
        }
      } elseif ( $course['status'] === 'in_progress' ) {
        ++$started_count;
        if ( null === $in_progress ) {
          $in_progress = $course;
        }
      } elseif ( null === $not_started ) {
        $not_started = $course;
      }
    }

    $total        = count( $courses );
    $all_complete = $total > 0 && $completed_count === $total;
    $next_course  = $in_progress ?: $not_started;
    $continue_url = $next_course['url'] ?? home_url( '/my-courses/' );
    $module_index = 1;

    if ( $next_course ) {
      foreach ( $courses as $index => $course ) {
        if ( (int) $course['id'] === (int) $next_course['id'] ) {
          $module_index = $index + 1;
          break;
        }
      }
    }

    if ( ! empty( $new_hire['overdue'] ) ) {
      $training_status = 'new_hire_overdue';
    } elseif ( class_exists( 'GHCA_Course_Lifespans' ) ) {
      $course_states = array_column( $courses, 'compliance_state' );
      if ( in_array( 'expired', $course_states, true ) ) {
        $training_status = 'expired';
      } elseif ( in_array( 'expiring_soon', $course_states, true ) ) {
        $training_status = 'expiring_soon';
      } elseif ( ! $all_complete ) {
        $training_status = ( $started_count > 0 || $completed_count > 0 ) ? 'in_progress' : 'not_started';
      } else {
        $training_status = 'compliant';
      }
    } else {
      if ( $all_complete ) {
        $training_status = 'compliant';
      } elseif ( $started_count > 0 || $completed_count > 0 ) {
        $training_status = 'in_progress';
      } else {
        $training_status = 'not_started';
      }
    }

    $cycle   = ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) )
      ? array(
        'start_date_label' => ! empty( $new_hire['enrollment_ts'] ) ? wp_date( 'F j, Y', (int) $new_hire['enrollment_ts'] ) : __( 'Not available', 'ghca-ecd' ),
        'due_date_label'   => (string) ( $new_hire['deadline_label'] ?? '' ),
      )
      : self::get_compliance_cycle( $user_id );
    $due_raw = ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) && ! empty( $new_hire['deadline_ts'] ) )
      ? wp_date( 'Y-m-d', (int) $new_hire['deadline_ts'] )
      : ( $cycle['due_date'] ?? (string) apply_filters(
        'ghca_compliance_due_date',
        get_option( self::OPTION_DUE_DATE, '2026-07-31' ),
        $user_id
      ) );

    self::$snapshot = array(
      'user_id'                   => $user_id,
      'first_name'                => get_user_meta( $user_id, 'first_name', true ),
      'display_name'              => $user ? $user->display_name : '',
      'courses'                   => $courses,
      'total_courses'             => $total,
      'completed_count'           => $completed_count,
      'all_complete'              => $all_complete,
      'training_status'           => $training_status,
      'training_status_label'     => self::status_label( $training_status ),
      'courses_completed_label'   => $total ? sprintf( '%1$d of %2$d completed', $completed_count, $total ) : __( 'None assigned', 'ghca-ecd' ),
      'start_date_label'          => $cycle['start_date_label'],
      'compliance_due_date_label' => $cycle['due_date_label'] ?: self::format_due_date( $due_raw ),
      'due_date_label'            => $cycle['due_date_label'] ?: self::format_due_date( $due_raw ),
      'certificate_url'           => $certificate_url,
      'certificate_status_label'  => $all_complete ? __( 'Check certificates page', 'ghca-ecd' ) : __( 'Available after completion', 'ghca-ecd' ),
      'next_course'               => $next_course ? array(
        'title'          => $next_course['title'],
        'description'    => $next_course['description'] ?? '',
        'progress'       => (int) ( $next_course['progress'] ?? 0 ),
        'progress_label' => $next_course['progress_label'],
        'module_index'   => $module_index,
        'module_total'   => $total,
        'module_label'   => sprintf(
          /* translators: 1: current module number, 2: total modules */
          __( 'Module %1$d of %2$d', 'ghca-ecd' ),
          $module_index,
          $total
        ),
        'url'            => $next_course['url'],
        'button_label'   => $next_course['status'] === 'in_progress' ? __( 'Resume Course', 'ghca-ecd' ) : __( 'Start Course', 'ghca-ecd' ),
      ) : null,
      'continue_url'              => $continue_url,
      'compliance_notification'   => self::get_compliance_notification( $user_id, $new_hire ),
      'annual_training_complete'  => self::is_annual_training_complete( $user_id, self::get_required_courses( $user_id ) ),
      'new_hire'                  => $new_hire,
      'new_hire_flagged'          => ! empty( $new_hire['flagged'] ),
    );

    if ( $ttl > 0 ) {
      set_transient( self::get_cache_key( $user_id ), self::$snapshot, $ttl );
    }

    return self::$snapshot;
  }

  /** @return array<int,array<string,mixed>> */
  private static function get_required_courses( int $user_id ): array {
    if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
      return array();
    }

    if ( isset( self::$required_courses[ $user_id ] ) ) {
      return self::$required_courses[ $user_id ];
    }

    $course_ids = array_map( 'intval', (array) learndash_user_get_enrolled_courses( $user_id ) );
    $course_ids = array_values( array_unique( array_filter( $course_ids ) ) );
    $items      = array();

    foreach ( $course_ids as $course_id ) {
      $title     = self::normalize_course_title(
        html_entity_decode( (string) get_the_title( $course_id ), ENT_QUOTES, 'UTF-8' )
      );
      $status    = function_exists( 'learndash_course_status' ) ? (string) learndash_course_status( $course_id, $user_id, true ) : 'not_started';
      $progress  = function_exists( 'learndash_course_progress' ) ? (array) learndash_course_progress(
        array(
          'user_id'   => $user_id,
          'course_id' => $course_id,
          'array'     => true,
        )
      ) : array();
      $percent   = isset( $progress['percentage'] ) ? (int) $progress['percentage'] : 0;
      $completed = function_exists( 'learndash_course_completed' ) ? (bool) learndash_course_completed( $user_id, $course_id ) : false;
      $completed_ts = $completed && function_exists( 'learndash_user_get_course_completed_date' ) ? (int) learndash_user_get_course_completed_date( $user_id, $course_id ) : 0;
      
      $compliance_state = $completed ? 'completed' : $status;
      $lifespan_days = 0;
      $expiration_ts = 0;
      $expiration_label = '';

      if ( class_exists( 'GHCA_Course_Lifespans' ) ) {
        $decorated = GHCA_Course_Lifespans::decorate( $course_id, $completed, $completed_ts );
        $compliance_state = $decorated['compliance_state'];
        $lifespan_days = $decorated['lifespan_days'];
        $expiration_ts = $decorated['expiration_ts'];
        $expiration_label = $decorated['expiration_label'];
      }

      $items[] = array(
        'id'             => $course_id,
        'title'          => $title,
        'description'    => self::get_course_description( $course_id ),
        'status'         => $status,
        'completed'      => $completed,
        'compliance_state' => $compliance_state,
        'lifespan_days'  => $lifespan_days,
        'expiration_ts'  => $expiration_ts,
        'expiration_label' => $expiration_label,
        'progress'       => $percent,
        'progress_label' => $percent . '%',
        'url'            => get_permalink( $course_id ) ?: home_url( '/my-courses/' ),
        'sort_order'     => self::course_sort_order( $title ),
      );
    }

    usort(
      $items,
      static function ( array $a, array $b ): int {
        return $a['sort_order'] <=> $b['sort_order'];
      }
    );

    self::$required_courses[ $user_id ] = $items;

    return $items;
  }

  private static function normalize_course_title( string $title ): string {
    return mb_strtoupper( trim( $title ), 'UTF-8' );
  }

  private static function get_course_description( int $course_id ): string {
    $post = get_post( $course_id );
    if ( ! $post ) {
      return '';
    }

    $raw = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
    $raw = wp_strip_all_tags( strip_shortcodes( (string) $raw ) );
    $raw = trim( preg_replace( '/\s+/', ' ', $raw ) );

    if ( $raw === '' ) {
      return __( 'Complete this required compliance module to stay up to date with your training.', 'ghca-ecd' );
    }

    return wp_trim_words( $raw, 28, '…' );
  }

  private static function course_sort_order( string $title ): int {
    if ( preg_match( '/\bMODULE\s*(\d+)/i', $title, $matches ) ) {
      return (int) $matches[1];
    }
    if ( preg_match( '/ORIENTATION/i', $title ) ) {
      return 0;
    }
    return 999;
  }

  private static function status_label( string $status ): string {
    $map = array(
      'not_started'      => __( 'Not Started', 'ghca-ecd' ),
      'in_progress'      => __( 'In Progress', 'ghca-ecd' ),
      'completed'        => __( 'Completed', 'ghca-ecd' ),
      'compliant'        => __( 'Compliant', 'ghca-ecd' ),
      'expiring_soon'    => __( 'Expiring Soon', 'ghca-ecd' ),
      'expired'          => __( 'Expired', 'ghca-ecd' ),
      'overdue'          => __( 'Overdue', 'ghca-ecd' ),
      'new_hire_overdue' => __( 'New Hire Overdue', 'ghca-ecd' ),
    );

    return $map[ $status ] ?? __( 'Unknown', 'ghca-ecd' );
  }

  private static function get_status_badge_class( string $status ): string {
    $map = array(
      'completed'        => 'ghca-ecd__pill--success',
      'compliant'        => 'ghca-ecd__pill--success',
      'in_progress'      => 'ghca-ecd__pill--info',
      'expiring_soon'    => 'ghca-ecd__pill--warning',
      'expired'          => 'ghca-ecd__pill--danger',
      'overdue'          => 'ghca-ecd__pill--danger',
      'new_hire_overdue' => 'ghca-ecd__pill--danger',
      'not_started'      => 'ghca-ecd__pill--neutral',
    );

    return $map[ $status ] ?? 'ghca-ecd__pill--neutral';
  }

  /** @return array<string,mixed> */
  private static function get_new_hire_status( int $user_id ): array {
    if ( class_exists( 'GHCA_Compliance_Program' ) ) {
      return GHCA_Compliance_Program::get_user_status( $user_id );
    }

    return array(
      'active'    => false,
      'complete'  => false,
      'overdue'   => false,
      'at_risk'   => false,
      'flagged'   => false,
    );
  }

  /** @param array<string,mixed> $new_hire @return array<int,array<string,mixed>> */
  private static function map_new_hire_courses( array $new_hire ): array {
    $items = array();

    foreach ( (array) ( $new_hire['courses'] ?? array() ) as $course ) {
      $items[] = array(
        'id'             => (int) ( $course['id'] ?? 0 ),
        'title'          => (string) ( $course['title'] ?? '' ),
        'description'    => self::get_course_description( (int) ( $course['id'] ?? 0 ) ),
        'status'         => (string) ( $course['status'] ?? 'not_started' ),
        'completed'      => ! empty( $course['completed'] ),
        'progress'       => (int) ( $course['progress'] ?? 0 ),
        'progress_label' => (string) ( $course['progress_label'] ?? '0%' ),
        'url'            => (string) ( $course['url'] ?? home_url( '/my-courses/' ) ),
        'sort_order'     => self::course_sort_order( (string) ( $course['title'] ?? '' ) ),
      );
    }

    usort(
      $items,
      static function ( array $a, array $b ): int {
        return $a['sort_order'] <=> $b['sort_order'];
      }
    );

    return $items;
  }

  private static function format_due_date( string $raw ): string {
    $timestamp = strtotime( $raw );
    if ( ! $timestamp ) {
      return $raw;
    }
    return wp_date( 'F j, Y', $timestamp );
  }

  private static function get_certificate_url( int $user_id, int $course_id ): string {
    if ( function_exists( 'learndash_get_course_certificate_link' ) ) {
      $link = learndash_get_course_certificate_link( $course_id, $user_id );
      if ( is_string( $link ) && $link !== '' ) {
        return $link;
      }
    }

    $uo = get_user_meta( $user_id, '_uo-course-cert-' . $course_id, true );
    if ( is_array( $uo ) && ! empty( $uo ) ) {
      $first = reset( $uo );
      if ( is_string( $first ) && $first !== '' ) {
        return $first;
      }
    }

    return '';
  }

  private static function resolve_due_date( int $user_id ): string {
    $cycle = self::get_compliance_cycle( $user_id );
    if ( ! empty( $cycle['due_date'] ) ) {
      return (string) $cycle['due_date'];
    }

    return (string) apply_filters(
      'ghca_compliance_due_date',
      get_option( self::OPTION_DUE_DATE, '2026-07-31' ),
      $user_id
    );
  }

  /** @return array<string,mixed> */
  private static function get_compliance_cycle( int $user_id ): array {
    $empty = array(
      'start_timestamp'  => 0,
      'start_date'       => '',
      'start_date_label' => __( 'Not available', 'ghca-ecd' ),
      'due_timestamp'    => 0,
      'due_date'         => '',
      'due_date_label'   => '',
      'days_on_platform' => 0,
      'days_remaining'   => null,
      'cycle_day'        => 0,
      'cycle_number'     => 1,
    );

    $start = self::get_platform_start_timestamp( $user_id );
    if ( ! $start ) {
      return $empty;
    }

    $now              = time();
    $days_on_platform = (int) floor( max( 0, $now - $start ) / DAY_IN_SECONDS );
    $cycle_day        = $days_on_platform % self::CYCLE_DAYS;
    $cycle_day        = 0 === $cycle_day && $days_on_platform > 0 ? self::CYCLE_DAYS : $cycle_day;
    $days_remaining   = self::CYCLE_DAYS - $cycle_day;
    $cycle_number     = (int) floor( $days_on_platform / self::CYCLE_DAYS ) + 1;
    $due_timestamp    = $start + ( $cycle_number * self::CYCLE_DAYS * DAY_IN_SECONDS );

    return array(
      'start_timestamp'  => $start,
      'start_date'       => wp_date( 'Y-m-d', $start ),
      'start_date_label' => wp_date( 'F j, Y', $start ),
      'due_timestamp'    => $due_timestamp,
      'due_date'         => wp_date( 'Y-m-d', $due_timestamp ),
      'due_date_label'   => wp_date( 'F j, Y', $due_timestamp ),
      'days_on_platform' => $days_on_platform,
      'days_remaining'   => $days_remaining,
      'cycle_day'        => $cycle_day,
      'cycle_number'     => $cycle_number,
    );
  }

  /** @param array<string,mixed> $new_hire @return array<string,mixed> */
  private static function get_compliance_notification( int $user_id, array $new_hire = array() ): array {
    if ( isset( self::$notification[ $user_id ] ) ) {
      return self::$notification[ $user_id ];
    }

    self::$notification[ $user_id ] = self::compute_compliance_notification( $user_id, $new_hire );

    return self::$notification[ $user_id ];
  }

  /** @param array<string,mixed> $new_hire @return array<string,mixed> */
  private static function compute_compliance_notification( int $user_id, array $new_hire = array() ): array {
    if ( empty( $new_hire ) ) {
      $new_hire = self::get_new_hire_status( $user_id );
    }

    if ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) ) {
      if ( ! empty( $new_hire['overdue'] ) ) {
        return array(
          'show'        => true,
          'level'       => 'danger',
          'title'       => __( 'New Hire Compliance Alert', 'ghca-ecd' ),
          'message'     => __( 'Your new hire training is overdue. Complete all required courses in your new hire group immediately.', 'ghca-ecd' ),
          'meta_label'  => sprintf(
            __( 'New hire due date was %1$s (%2$d-day window).', 'ghca-ecd' ),
            (string) $new_hire['deadline_label'],
            (int) ( $new_hire['deadline_days'] ?? 30 )
          ),
          'show_cta'    => true,
          'cta_label'   => __( 'Complete New Hire Training', 'ghca-ecd' ),
        );
      }

      if ( ! empty( $new_hire['at_risk'] ) ) {
        return array(
          'show'        => true,
          'level'       => 'warning',
          'title'       => __( 'New Hire Deadline Approaching', 'ghca-ecd' ),
          'message'     => __( 'You have new hire training due soon. Finish all required courses before your 30-day deadline.', 'ghca-ecd' ),
          'meta_label'  => sprintf(
            __( 'New hire due date: %1$s (%2$d days remaining).', 'ghca-ecd' ),
            (string) $new_hire['deadline_label'],
            (int) ( $new_hire['days_remaining'] ?? 0 )
          ),
          'show_cta'    => true,
          'cta_label'   => __( 'Continue New Hire Training', 'ghca-ecd' ),
        );
      }
    }

    $empty = array(
      'show'             => false,
      'level'            => 'info',
      'message'          => '',
      'due_date'         => '',
      'due_date_label'   => '',
      'countdown_label'  => '',
      'days_on_platform' => 0,
      'days_remaining'   => null,
      'annual_complete'  => false,
    );

    $start = self::get_platform_start_timestamp( $user_id );
    if ( ! $start ) {
      return $empty;
    }

    $cycle            = self::get_compliance_cycle( $user_id );
    $days_on_platform = (int) $cycle['days_on_platform'];
    $days_remaining   = (int) $cycle['days_remaining'];
    $due_timestamp    = (int) $cycle['due_timestamp'];
    $due_date_label   = (string) $cycle['due_date_label'];
    $courses          = self::get_required_courses( $user_id );
    $annual_complete  = self::is_annual_training_complete( $user_id, $courses );
    $in_notice_window = $days_remaining <= self::NOTICE_DAYS;

    if ( ! $in_notice_window || ( $annual_complete && $days_remaining > 0 ) ) {
      return $empty;
    }

    if ( $annual_complete && 0 === $days_remaining ) {
      return array(
        'show'             => true,
        'level'            => 'success',
        'message'          => __( 'Your annual compliance training is complete for this cycle.', 'ghca-ecd' ),
        'due_date'         => wp_date( 'Y-m-d', $due_timestamp ),
        'due_date_label'   => $due_date_label,
        'countdown_label'  => __( 'Completed', 'ghca-ecd' ),
        'days_on_platform' => $days_on_platform,
        'days_remaining'   => $days_remaining,
        'annual_complete'  => true,
      );
    }

    if ( 0 === $days_remaining ) {
      $level           = 'danger';
      $countdown_label = __( 'Due today', 'ghca-ecd' );
      $message         = __( 'Your annual compliance training is due today. Please complete it as soon as possible.', 'ghca-ecd' );
    } elseif ( $days_remaining <= 30 ) {
      $level           = 'danger';
      $countdown_label = sprintf( _n( '%d day remaining', '%d days remaining', $days_remaining, 'ghca-ecd' ), $days_remaining );
      $message         = sprintf(
        __( 'Your annual compliance training is due in %1$d days on %2$s.', 'ghca-ecd' ),
        $days_remaining,
        $due_date_label
      );
    } elseif ( $days_remaining <= 60 ) {
      $level           = 'warning';
      $countdown_label = sprintf( _n( '%d day remaining', '%d days remaining', $days_remaining, 'ghca-ecd' ), $days_remaining );
      $message         = sprintf(
        __( 'Annual compliance training opens soon. Complete your required annual training by %1$s (%2$d days remaining).', 'ghca-ecd' ),
        $due_date_label,
        $days_remaining
      );
    } else {
      $level           = 'info';
      $countdown_label = sprintf( _n( '%d day remaining', '%d days remaining', $days_remaining, 'ghca-ecd' ), $days_remaining );
      $message         = sprintf(
        __( 'You are approaching your annual compliance anniversary. Complete annual training by %1$s (%2$d days remaining).', 'ghca-ecd' ),
        $due_date_label,
        $days_remaining
      );
    }

    return array(
      'show'             => true,
      'level'            => $level,
      'message'          => $message,
      'due_date'         => wp_date( 'Y-m-d', $due_timestamp ),
      'due_date_label'   => $due_date_label,
      'countdown_label'  => $countdown_label,
      'days_on_platform' => $days_on_platform,
      'days_remaining'   => $days_remaining,
      'annual_complete'  => $annual_complete,
    );
  }

  private static function get_platform_start_timestamp( int $user_id ): int {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
      return 0;
    }

    $candidates = array( strtotime( $user->user_registered ) );

    foreach ( array( 54, 1428 ) as $group_id ) {
      foreach ( array(
        'group_' . $group_id . '_access_from',
        'learndash_group_' . $group_id . '_enrolled_at',
      ) as $meta_key ) {
        $value = get_user_meta( $user_id, $meta_key, true );
        if ( is_numeric( $value ) && (int) $value > 0 ) {
          $candidates[] = (int) $value;
        }
      }
    }

    $candidates = array_values( array_filter( $candidates ) );
    if ( empty( $candidates ) ) {
      return 0;
    }

    return (int) min( $candidates );
  }

  /** @param array<int,array<string,mixed>> $courses */
  private static function is_annual_training_complete( int $user_id, array $courses = array() ): bool {
    if ( empty( $courses ) ) {
      $courses = self::get_required_courses( $user_id );
    }

    foreach ( $courses as $course ) {
      if ( ! self::is_annual_compliance_course( (string) $course['title'] ) ) {
        continue;
      }

      if ( ! empty( $course['completed'] ) ) {
        return true;
      }
    }

    return false;
  }

  private static function is_annual_compliance_course( string $title ): bool {
    return (bool) preg_match( '/annual\s+employee\s+review|annual\s+compliance/i', $title );
  }

  /** @return array<int,array{label:string,done:bool}> */
  private static function build_action_items( array $data ): array {
    if ( ! empty( $data['new_hire']['active'] ) && empty( $data['new_hire']['complete'] ) ) {
      return array(
        array(
          'label' => sprintf(
            __( 'Complete all new hire courses within %d days', 'ghca-ecd' ),
            (int) ( $data['new_hire']['deadline_days'] ?? 30 )
          ),
          'done'  => ! empty( $data['all_complete'] ),
        ),
        array(
          'label' => __( 'Pass required new hire quizzes', 'ghca-ecd' ),
          'done'  => ! empty( $data['all_complete'] ),
        ),
        array(
          'label' => __( 'Review company policies', 'ghca-ecd' ),
          'done'  => false,
        ),
        array(
          'label' => __( 'Download certificate if needed', 'ghca-ecd' ),
          'done'  => ! empty( $data['certificate_url'] ),
        ),
        array(
          'label' => __( 'Contact admin if something looks wrong', 'ghca-ecd' ),
          'done'  => false,
        ),
      );
    }

    return array(
      array(
        'label' => __( 'Complete annual compliance training', 'ghca-ecd' ),
        'done'  => ! empty( $data['annual_training_complete'] ) || ! empty( $data['all_complete'] ),
      ),
      array(
        'label' => __( 'Pass required quizzes', 'ghca-ecd' ),
        'done'  => ! empty( $data['all_complete'] ),
      ),
      array(
        'label' => __( 'Review company policies', 'ghca-ecd' ),
        'done'  => false,
      ),
      array(
        'label' => __( 'Download certificate if needed', 'ghca-ecd' ),
        'done'  => ! empty( $data['certificate_url'] ),
      ),
      array(
        'label' => __( 'Contact admin if something looks wrong', 'ghca-ecd' ),
        'done'  => false,
      ),
    );
  }

  /** @return array<int,array{label:string,url:string,icon?:string,subtitle?:string,tone?:string}> */
  private static function get_quick_links(): array {
    $my_courses = get_page_by_path( 'my-courses' );
    $cert       = get_page_by_path( 'cert-download' );
    $transcript = get_page_by_path( 'transcript' );

    $links = array(
      array(
        'label'    => __( 'My Courses', 'ghca-ecd' ),
        'subtitle' => __( 'View assigned training', 'ghca-ecd' ),
        'icon'     => 'courses',
        'tone'     => 'blue',
        'url'      => $my_courses ? get_permalink( $my_courses ) : home_url( '/my-courses/' ),
      ),
      array(
        'label'    => __( 'Certificates', 'ghca-ecd' ),
        'subtitle' => __( 'Download records', 'ghca-ecd' ),
        'icon'     => 'award',
        'tone'     => 'green',
        'url'      => $cert ? get_permalink( $cert ) : ( $transcript ? get_permalink( $transcript ) : home_url( '/cert-download/' ) ),
      ),
      array(
        'label'    => __( 'Employee Handbook', 'ghca-ecd' ),
        'subtitle' => __( 'Company guidelines', 'ghca-ecd' ),
        'icon'     => 'handbook',
        'tone'     => 'purple',
        'url'      => apply_filters( 'ghca_employee_handbook_url', home_url( '/employee-handbook/' ) ),
      ),
      array(
        'label'    => __( 'Policies', 'ghca-ecd' ),
        'subtitle' => __( 'Standard procedures', 'ghca-ecd' ),
        'icon'     => 'policies',
        'tone'     => 'neutral',
        'url'      => apply_filters( 'ghca_employee_policies_url', home_url( '/privacy-policy/' ) ),
      ),
      array(
        'label'    => __( 'Contact Support', 'ghca-ecd' ),
        'subtitle' => __( 'Get technical help', 'ghca-ecd' ),
        'icon'     => 'support',
        'tone'     => 'amber',
        'url'      => 'mailto:' . ( class_exists( 'GHCA_Dashboard_Branding', false ) ? GHCA_Dashboard_Branding::get_support_email() : 'info@lafiahealthcare.org' ),
      ),
    );

    return apply_filters( 'ghca_employee_quick_links', $links );
  }
}

GHCA_Employee_Compliance_Dashboard::init();
