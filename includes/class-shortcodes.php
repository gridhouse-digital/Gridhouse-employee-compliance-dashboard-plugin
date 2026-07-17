<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Shortcode rendering for the employee dashboard. All markup lives here;
 * data comes from GHCA_ECD_Data_Provider.
 */
final class GHCA_ECD_Shortcodes {

  public static function init(): void {
    add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
    add_action( 'init', array( __CLASS__, 'maybe_seed_announcements' ), 999 );
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
      'employee_certificate_packet'       => 'render_certificate_packet',
    );

    foreach ( $map as $tag => $method ) {
      add_shortcode( $tag, array( __CLASS__, $method ) );
    }
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
    $data   = GHCA_ECD_Data_Provider::get_snapshot();
    $notice = is_array( $data['compliance_notification'] ?? null )
      ? $data['compliance_notification']
      : GHCA_ECD_Data_Provider::get_compliance_notification( get_current_user_id() );
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

    $data    = GHCA_ECD_Data_Provider::get_snapshot();
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
          <div class="ghca-ecd__ring" style="background: conic-gradient(var(--ghca-secondary) <?php echo esc_attr( (string) $percent ); ?>%, var(--ent-ring-track, #e9ecef) 0);" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d%% of required training complete', 'ghca-ecd' ), $percent ) ); ?>">
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
          <?php if ( GHCA_ECD_Cert_Packet::is_available() && GHCA_ECD_Cert_Packet::has_certificates() ) : ?>
            <button type="button" class="ghca-ecd__btn ghca-ecd__btn--ghost ghca-ecd__btn--packet" data-ghca-ecd-packet><?php echo GHCA_ECD_UI_Icons::render( 'award', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Download my certificate packet', 'ghca-ecd' ); ?></button>
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

    $data           = GHCA_ECD_Data_Provider::get_snapshot();
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
            <div class="ghca-ecd__kpi-actions">
              <?php if ( ! empty( $data['certificate_url'] ) ) : ?>
                <a class="ghca-ecd__btn ghca-ecd__btn--secondary ghca-ecd__btn--compact" href="<?php echo esc_url( $data['certificate_url'] ); ?>"><?php esc_html_e( 'Recent Cert', 'ghca-ecd' ); ?></a>
              <?php else : ?>
                <span class="ghca-ecd__pill ghca-ecd__pill--locked"><?php echo GHCA_ECD_UI_Icons::render( 'compliance', 'ghca-ecd__pill-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Locked', 'ghca-ecd' ); ?></span>
              <?php endif; ?>
              <?php if ( GHCA_ECD_Cert_Packet::is_available() && GHCA_ECD_Cert_Packet::has_certificates() ) : ?>
                <button type="button" class="ghca-ecd__btn ghca-ecd__btn--ghost ghca-ecd__btn--compact ghca-ecd__btn--packet" data-ghca-ecd-packet><?php esc_html_e( 'Cert Packet', 'ghca-ecd' ); ?></button>
              <?php endif; ?>
            </div>
          </div>
          <p class="ghca-ecd__kpi-foot"><?php echo ! empty( $data['certificate_url'] ) ? esc_html__( 'Ready to download.', 'ghca-ecd' ) : esc_html__( 'Available after completion.', 'ghca-ecd' ); ?></p>
        </div>

      </div>
    </div>
    <?php
    return (string) ob_get_clean();
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

    $data = GHCA_ECD_Data_Provider::get_snapshot();
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

    $data       = GHCA_ECD_Data_Provider::get_snapshot();
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
                  <?php if ( ! empty( $item['url'] ) ) : ?>
                    <a class="ghca-ecd__announce-action" href="<?php echo esc_url( $item['url'] ); ?>"><?php esc_html_e( 'Take action', 'ghca-ecd' ); ?> &rarr;</a>
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

    // Map the admin plugin's announcement types (ghca_announce_type meta,
    // written by its Add-note CRUD) onto this dashboard's tones/icons.
    $type_map = array(
      'alert'    => array( 'tone' => 'warning', 'icon' => 'alert' ),
      'reminder' => array( 'tone' => 'info', 'icon' => 'bell' ),
      'update'   => array( 'tone' => 'success', 'icon' => 'status' ),
      'system'   => array( 'tone' => 'neutral', 'icon' => 'document' ),
    );

    if ( ! empty( $posts ) ) {
      $items = array();
      foreach ( $posts as $index => $post ) {
        $type   = (string) get_post_meta( $post->ID, 'ghca_announce_type', true );
        $preset = $type_map[ $type ] ?? $presets[ $index % count( $presets ) ];
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

        // The action link the admin attached in the announcements manager.
        // (Never get_permalink(): the ghca-announce CPT is not public, so
        // its permalinks 404 on the frontend.)
        $action_url = (string) get_post_meta( $post->ID, 'ghca_announce_url', true );
        if ( $action_url !== '' ) {
          $item['url'] = $action_url;
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

  public static function can_view_dashboard(): bool {
    return is_user_logged_in();
  }

  /** Standalone packet-download card; renders nothing when the feature is unavailable or no certificates exist. */
  public static function render_certificate_packet( $atts = array() ): string {
    if ( ! self::can_view_dashboard() || ! GHCA_ECD_Cert_Packet::is_available() || ! GHCA_ECD_Cert_Packet::has_certificates() ) {
      return '';
    }

    ob_start();
    ?>
    <div class="ghca-ecd ghca-ecd--packet">
      <div class="ghca-ecd__panel ghca-ecd__panel--packet">
        <div class="ghca-ecd__panel-head">
          <h2><?php esc_html_e( 'Certificate Packet', 'ghca-ecd' ); ?></h2>
        </div>
        <p class="ghca-ecd__panel-sub"><?php esc_html_e( 'Download all of your earned certificates as a single PDF, with a summary cover page.', 'ghca-ecd' ); ?></p>
        <button type="button" class="ghca-ecd__btn ghca-ecd__btn--primary" data-ghca-ecd-packet><?php echo GHCA_ECD_UI_Icons::render( 'award', 'ghca-ecd__btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Download my certificate packet', 'ghca-ecd' ); ?></button>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
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
