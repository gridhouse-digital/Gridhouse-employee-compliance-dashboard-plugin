<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Opt-in redesign of the employee home and certificates screens, following the
 * "Gridhouse Compliance — Phase 2 Dashboards" canvas (Emp-Home, Emp-Certificates
 * and their phone boards). Off until `ghca_ecd_ui_redesign` is saved; the
 * existing shortcodes render the previous screens until then.
 *
 * Markup uses the shared gh-* design system (assets/ecd-redesign.css), scoped
 * under one `.ghca-ecd-ui` wrapper. The site header, menu and phone navigation
 * come from the WordPress theme: the boards' app bar and tab bar are not
 * rendered. Data comes from GHCA_ECD_Data_Provider, unchanged.
 */
final class GHCA_ECD_Redesign {

  const OPTION_ENABLED = 'ghca_ecd_ui_redesign';
  const OPTION_THEME   = 'ghca_ecd_ui_theme';

  /** @return array<int,string> */
  public static function themes(): array {
    return array( 'gridhouse', 'akansoh', 'purple' );
  }

  public static function is_enabled(): bool {
    $on = (bool) get_option( self::OPTION_ENABLED, false );
    return (bool) apply_filters( 'ghca_ecd_ui_redesign', $on );
  }

  /**
   * Pure theme resolution: an explicit saved theme wins; otherwise the admin
   * dashboard's theme (so both dashboards match); otherwise Gridhouse.
   */
  public static function resolve_theme( string $saved, string $admin_theme ): string {
    if ( in_array( $saved, self::themes(), true ) ) {
      return $saved;
    }
    if ( in_array( $admin_theme, self::themes(), true ) ) {
      return $admin_theme;
    }
    return 'gridhouse';
  }

  public static function theme_slug(): string {
    $admin = ( class_exists( 'GHCA_ACD_Redesign' ) && method_exists( 'GHCA_ACD_Redesign', 'theme_slug' ) )
      ? (string) GHCA_ACD_Redesign::theme_slug()
      : '';
    $theme = self::resolve_theme( (string) get_option( self::OPTION_THEME, '' ), $admin );
    $theme = (string) apply_filters( 'ghca_ecd_ui_theme', $theme );
    return in_array( $theme, self::themes(), true ) ? $theme : 'gridhouse';
  }

  /**
   * Agency colours from the admin plugin's Branding settings, derived by the
   * admin redesign's own helper so both dashboards use identical values.
   * Empty when that helper is unavailable: the theme colours apply as drawn.
   */
  private static function brand_style(): string {
    if ( class_exists( 'GHCA_ACD_Redesign' ) && method_exists( 'GHCA_ACD_Redesign', 'brand_style' ) ) {
      return (string) GHCA_ACD_Redesign::brand_style();
    }
    return '';
  }

  /* ---------------------------------------------------------------------
   * Page shell
   * ------------------------------------------------------------------- */

  /** @var string Overlay markup (drawer, viewer) collected while rendering; placed inside the themed wrapper. */
  private static $overlays = '';

  private static function shell( string $screen, string $inner ): string {
    $bleed = (bool) apply_filters( 'ghca_ecd_ui_full_bleed', true );
    $style = self::brand_style();
    $over  = self::$overlays;
    self::$overlays = '';

    return '<div class="ghca-ecd-ui' . ( $bleed ? ' ghca-ecd-ui--bleed' : '' ) . '" data-ghca-ecd-ui="' . esc_attr( $screen ) . '">'
      . '<div class="gh-app gh-ecd" data-theme="' . esc_attr( self::theme_slug() ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>'
      . '<main class="gh-container gh-ecd-main">' . $inner . '</main>'
      . $over
      . '</div></div>';
  }

  private static function icon( string $name, string $size = '' ): string {
    return '<span class="gh-i' . ( '' !== $size ? ' gh-i--' . esc_attr( $size ) : '' ) . ' i-' . esc_attr( $name ) . '" aria-hidden="true"></span>';
  }

  /* ---------------------------------------------------------------------
   * Home (Emp-Home, M-Emp-Home)
   * ------------------------------------------------------------------- */

  public static function render_home(): string {
    $data = GHCA_ECD_Data_Provider::get_snapshot();

    $html  = self::hello_html( $data );
    $html .= self::alert_html( $data );
    $html .= '<section class="gh-ehome3" aria-label="' . esc_attr__( 'Your training', 'ghca-ecd' ) . '">'
      . self::next_up_html( $data )
      . self::actions_html( $data )
      . self::announcements_html()
      . '</section>';
    $html .= self::ticker_html();
    $html .= self::kpis_html( $data );
    $html .= self::blockers_html( $data ); // Below the status strip, above Quick links.
    $html .= self::quick_links_html( $data );
    $html .= self::support_html();

    return self::shell( 'home', $html );
  }

  /** Two-letter initials for the avatar, from the display name. */
  public static function initials( string $name ): string {
    $words = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
    if ( ! $words ) {
      return '';
    }
    $first = mb_substr( $words[0], 0, 1, 'UTF-8' );
    $last  = count( $words ) > 1 ? mb_substr( (string) end( $words ), 0, 1, 'UTF-8' ) : '';
    return mb_strtoupper( $first . $last, 'UTF-8' );
  }

  /**
   * The employee's role as the admin dashboard shows it: the display name of their WordPress role
   * ("Caregiver", "Nurse", or an agency role added in the admin plugin). Generic site roles that say
   * nothing about the job are skipped.
   *
   * @param array<int,string>    $roles The user's role slugs, primary first.
   * @param array<string,string> $names Role slug => display name.
   */
  public static function role_label_from( array $roles, array $names, array $job_roles = array() ): string {
    $generic = array( 'subscriber', 'customer', 'administrator', 'editor', 'author', 'contributor', 'group_leader', 'shop_manager' );
    $usable  = array();
    foreach ( $roles as $slug ) {
      $slug = (string) $slug;
      // bbPress forum roles (bbp_keymaster, bbp_participant, ...) and generic site roles say nothing about the job.
      if ( '' === $slug || 0 === strpos( $slug, 'bbp_' ) || in_array( $slug, $generic, true ) || ! isset( $names[ $slug ] ) ) {
        continue;
      }
      $usable[] = $slug;
    }
    // A known GHCA job role (admin plugin roles, caregiver, nurse, ...) wins over any other role.
    foreach ( $usable as $slug ) {
      if ( in_array( $slug, $job_roles, true ) ) {
        return (string) $names[ $slug ];
      }
    }
    return $usable ? (string) $names[ $usable[0] ] : '';
  }

  /** @return array<int,string> Role slugs the compliance system treats as job roles. */
  private static function job_role_slugs(): array {
    $slugs = (array) apply_filters( 'ghca_acd_employee_roles', array( 'caregiver', 'nurse' ) );
    if ( class_exists( 'GHCA_ACD_Roles' ) ) {
      foreach ( array( 'builtin_employee_roles', 'custom_roles' ) as $method ) {
        if ( method_exists( 'GHCA_ACD_Roles', $method ) ) {
          $slugs = array_merge( $slugs, array_keys( (array) GHCA_ACD_Roles::$method() ) );
        }
      }
    }
    return array_values( array_unique( array_map( 'strval', $slugs ) ) );
  }

  private static function role_label( int $user_id ): string {
    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->roles ) ) {
      return '';
    }
    $names = array();
    if ( function_exists( 'wp_roles' ) ) {
      foreach ( wp_roles()->roles as $slug => $role ) {
        $names[ $slug ] = function_exists( 'translate_user_role' ) ? translate_user_role( (string) $role['name'] ) : (string) $role['name'];
      }
    }
    return self::role_label_from( array_values( (array) $user->roles ), $names, self::job_role_slugs() );
  }

  /** @param array<string,mixed> $data */
  private static function is_new_hire( array $data ): bool {
    return ! empty( $data['new_hire']['active'] ) && empty( $data['new_hire']['complete'] );
  }

  /** @param array<string,mixed> $data */
  private static function percent( array $data ): int {
    $total = (int) $data['total_courses'];
    return $total > 0 ? (int) round( ( (int) $data['completed_count'] / $total ) * 100 ) : 0;
  }

  /** @param array<string,mixed> $data */
  private static function hello_html( array $data ): string {
    $first   = (string) ( $data['first_name'] ?: $data['display_name'] );
    $total   = (int) $data['total_courses'];
    $done    = (int) $data['completed_count'];
    $percent = self::percent( $data );
    $role    = (string) apply_filters( 'ghca_employee_role_label', self::role_label( (int) $data['user_id'] ), (int) $data['user_id'] );
    $program = self::is_new_hire( $data ) ? __( 'New-hire onboarding', 'ghca-ecd' ) : __( 'Annual training', 'ghca-ecd' );

    $photo_cfg = self::photo_config( (int) $data['user_id'] );
    if ( $photo_cfg ) {
      $photo_cfg['initials'] = self::avatar_initials( $data );
      self::$overlays       .= self::photo_dialog_html( $photo_cfg );
    }

    $h  = '<section class="gh-ecd-hello">';
    $h .= self::avatar_html( $data, $photo_cfg );
    $h .= '<div class="gh-ecd-hello-t"><h1 class="gh-h1">' . esc_html( sprintf( /* translators: %s: first name */ __( 'Welcome back, %s', 'ghca-ecd' ), $first ) ) . '</h1>';
    $h .= '<div class="gh-meta">';
    if ( '' !== $role ) {
      $h .= '<span class="gh-badge gh-badge--brand">' . esc_html( $role ) . '</span>';
    }
    // With the photo pop-up, "Remove photo" lives in the pop-up; the form here is the fallback without it.
    $h .= '<span>' . esc_html( $program ) . '</span><span class="gh-ecd-today">' . esc_html( wp_date( 'l, F j' ) ) . '</span>' . ( $photo_cfg ? '' : self::remove_photo_html( (int) $data['user_id'] ) ) . '</div>';
    $h .= $photo_cfg ? '<span class="gh-sr" role="status" aria-live="polite" data-ghca-ph-live></span>' : '';
    $h .= '</div>';
    $h .= '<div class="gh-ecd-hello-p">';
    $h .= '<div class="gh-ring gh-ecd-ring" style="--p: ' . esc_attr( (string) $percent ) . ';" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: percent */ __( '%d%% of required training complete', 'ghca-ecd' ), $percent ) ) . '"><span class="gh-ring-v">' . esc_html( $percent . '%' ) . '</span></div>';
    $h .= '<div><strong>' . esc_html(
      $total > 0
        ? sprintf( /* translators: 1: completed, 2: total */ _n( '%1$d of %2$d course', '%1$d of %2$d courses', $total, 'ghca-ecd' ), $done, $total )
        : __( 'No courses assigned', 'ghca-ecd' )
    ) . '</strong><div class="gh-small gh-muted">' . esc_html__( 'Overall progress', 'ghca-ecd' ) . '</div></div>';
    $h .= '</div></section>';

    return $h;
  }

  /**
   * The deadline banner: the existing compliance notification when it shows,
   * otherwise a summary of what is left in the new-hire window.
   *
   * @param array<string,mixed> $data
   */
  private static function alert_html( array $data ): string {
    $notice = is_array( $data['compliance_notification'] ?? null ) ? $data['compliance_notification'] : array();
    $level  = '';
    $title  = '';
    $body   = '';

    if ( ! empty( $notice['show'] ) ) {
      $level = (string) ( $notice['level'] ?? 'info' );
      $title = (string) ( $notice['title'] ?? $notice['countdown_label'] ?? '' );
      $body  = (string) ( $notice['message'] ?? '' );
      if ( '' === $title ) {
        $title = $body;
        $body  = (string) ( $notice['meta_label'] ?? '' );
      } elseif ( ! empty( $notice['meta_label'] ) ) {
        $body .= ' ' . (string) $notice['meta_label'];
      }
    } elseif ( self::is_new_hire( $data ) && empty( $data['all_complete'] ) ) {
      $left  = max( 0, (int) $data['total_courses'] - (int) $data['completed_count'] );
      $days  = isset( $data['new_hire']['days_remaining'] ) && is_numeric( $data['new_hire']['days_remaining'] ) ? (int) $data['new_hire']['days_remaining'] : null;
      $level = 'warning';
      // Board format: "Friday, November 13" when the deadline timestamp is known.
      $due   = ! empty( $data['new_hire']['deadline_ts'] ) ? wp_date( 'l, F j', (int) $data['new_hire']['deadline_ts'] ) : (string) $data['compliance_due_date_label'];
      $title = sprintf(
        /* translators: 1: courses left, 2: due date */
        _n( '%1$d course is due by %2$s', '%1$d courses are due by %2$s', $left, 'ghca-ecd' ),
        $left,
        $due
      );
      if ( null !== $days ) {
        $title .= ' — ' . sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days, 'ghca-ecd' ), $days );
      }
      $body = __( 'A little training each day keeps you on track.', 'ghca-ecd' );
    }

    if ( '' === $title ) {
      return '';
    }

    $map  = array( 'danger' => array( 'danger', 'alert' ), 'warning' => array( 'warning', 'clock' ), 'success' => array( 'success', 'check-circle' ), 'info' => array( 'info', 'info' ) );
    $tone = $map[ $level ] ?? $map['info'];

    $h  = '<div class="gh-alert gh-alert--' . esc_attr( $tone[0] ) . ' gh-ecd-alert" role="status">' . self::icon( $tone[1], 'lg' );
    $h .= '<div class="gh-alert-body"><span class="gh-alert-t">' . esc_html( $title ) . '</span>';
    if ( '' !== $body ) {
      $h .= '<p>' . esc_html( $body ) . '</p>';
    }
    $h .= '</div>';
    if ( empty( $data['all_complete'] ) && (int) $data['total_courses'] > 0 ) {
      $h .= '<a class="gh-btn gh-btn--secondary gh-btn--sm" href="' . esc_url( self::courses_url() ) . '">' . esc_html__( 'See courses', 'ghca-ecd' ) . '</a>';
    }
    return $h . '</div>';
  }

  /** @param array<string,mixed> $data */
  private static function next_up_html( array $data ): string {
    $next = is_array( $data['next_course'] ?? null ) ? $data['next_course'] : null;

    $h = '<article class="gh-ecd-next">';
    if ( empty( $data['courses'] ) || null === $next ) {
      $done = ! empty( $data['courses'] );
      $h   .= '<div class="gh-ecd-next-top"><span class="gh-badge gh-ecd-next-badge">' . esc_html( $done ? __( 'All done', 'ghca-ecd' ) : __( 'Next up', 'ghca-ecd' ) ) . '</span></div>';
      $h   .= '<h2 class="gh-display gh-ecd-next-t">' . esc_html( $done ? __( "You're all caught up", 'ghca-ecd' ) : __( 'No courses yet', 'ghca-ecd' ) ) . '</h2>';
      $h   .= '<p class="gh-p gh-ecd-next-d">' . esc_html(
        $done
          ? __( 'No required training is pending right now.', 'ghca-ecd' )
          : __( 'No required courses are assigned to your account yet. Contact your administrator if you think this is wrong.', 'ghca-ecd' )
      ) . '</p>';
      return $h . '</article>';
    }

    $progress = max( 0, min( 100, (int) $next['progress'] ) );
    $status   = $progress > 0 ? sprintf( /* translators: %s: percent */ __( '%s complete', 'ghca-ecd' ), (string) $next['progress_label'] ) : __( 'Not started', 'ghca-ecd' );

    $course = self::find_course( $data, (int) ( $next['id'] ?? 0 ) );
    $due    = self::course_due( array( 'progress' => $progress ) + $course, $data );
    $h .= '<div class="gh-ecd-next-top"><span class="gh-ecd-next-tags"><span class="gh-badge gh-ecd-next-badge">' . esc_html__( 'Next up', 'ghca-ecd' ) . '</span>';
    if ( in_array( $due['chip'][0], array( 'overdue', 'duesoon', 'locked' ), true ) ) {
      $h .= '<span class="gh-st gh-st--' . esc_attr( $due['chip'][0] ) . '">' . esc_html( $due['chip'][1] ) . '</span>';
    }
    $h .= '</span><span class="gh-ecd-next-n">' . esc_html( sprintf( /* translators: 1: position, 2: total */ __( 'Course %1$d of %2$d', 'ghca-ecd' ), (int) $next['module_index'], (int) $next['module_total'] ) ) . '</span></div>';
    $h .= '<div><div class="gh-ecd-next-eye">' . esc_html( $due['label'] ) . '</div>';
    $h .= '<h2 class="gh-display gh-ecd-next-t">' . esc_html( self::title_case( (string) $next['title'] ) ) . '</h2></div>';
    if ( '' !== (string) $next['description'] ) {
      $h .= '<p class="gh-p gh-ecd-next-d">' . esc_html( (string) $next['description'] ) . '</p>';
    }
    $h .= '<div class="gh-ecd-next-ft">';
    $h .= '<div class="gh-ecd-next-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $progress ) . '" aria-label="' . esc_attr( $status ) . '"><span style="width: ' . esc_attr( (string) max( 2, $progress ) ) . '%;"></span></div>';
    $h .= '<div class="gh-ecd-next-row"><span class="gh-ecd-next-s">' . esc_html( $status ) . '</span>';
    $h .= '<a class="gh-btn gh-btn--accent gh-btn--lg" href="' . esc_url( (string) $next['url'] ) . '">' . esc_html( $progress > 0 ? __( 'Resume course', 'ghca-ecd' ) : __( 'Start course', 'ghca-ecd' ) ) . self::icon( 'arrow-right' ) . '</a>';
    return $h . '</div></div></article>';
  }

  /**
   * Course titles are stored upper-cased for sorting; the boards show them in
   * title case. Short connector words stay lower case.
   */
  public static function title_case( string $title ): string {
    if ( $title !== mb_strtoupper( $title, 'UTF-8' ) ) {
      return $title;
    }
    $small    = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with' );
    $acronyms = array( 'IDD', 'ODP', 'ISP', 'CPR', 'DSP', 'LPN', 'RN', 'CNA', 'HIPAA', 'OSHA', 'PA', 'DHS', 'OLTL', 'AED', 'HIV', 'TB', 'ID', 'ADL', 'ADLS', 'PPE', 'BLS' );
    if ( function_exists( 'apply_filters' ) ) {
      $acronyms = (array) apply_filters( 'ghca_ecd_title_acronyms', $acronyms );
    }
    $words    = explode( ' ', mb_strtolower( $title, 'UTF-8' ) );
    foreach ( $words as $i => $word ) {
      if ( '' === $word || ( $i > 0 && in_array( $word, $small, true ) ) ) {
        continue;
      }
      // Known acronyms stay in capitals ("IDD Services", "CPR"), also inside punctuation such as "(ODP)".
      $bare = trim( $word, '()[],.:;!?"\'' );
      if ( '' !== $bare && in_array( mb_strtoupper( $bare, 'UTF-8' ), $acronyms, true ) ) {
        $words[ $i ] = str_replace( $bare, mb_strtoupper( $bare, 'UTF-8' ), $word );
        continue;
      }
      // Each hyphenated part is capitalised: "Person-Centered", "Trauma-Informed".
      $parts = explode( '-', $word );
      foreach ( $parts as $j => $part ) {
        $parts[ $j ] = mb_strtoupper( mb_substr( $part, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $part, 1, null, 'UTF-8' );
      }
      $words[ $i ] = implode( '-', $parts );
    }
    return implode( ' ', $words );
  }

  /** @param array<string,mixed> $data */
  private static function actions_html( array $data ): string {
    $items = GHCA_ECD_Shortcodes::build_action_items( $data );
    $done  = count( array_filter( array_column( $items, 'done' ) ) );
    $due      = (string) $data['compliance_due_date_label'];
    $policies = is_array( $data['policies'] ?? null ) ? $data['policies'] : array();
    $urls     = array(
      'courses'     => self::courses_url(),
      'quizzes'     => self::courses_url(),
      'policies'    => (string) ( $policies['url'] ?? '' ),
      'certificate' => self::certificates_url(),
    );

    $h  = '<div class="gh-card gh-ecd-col">';
    $h .= '<div class="gh-card-hd"><div><h2 class="gh-h4">' . esc_html__( 'Required actions', 'ghca-ecd' ) . '</h2><span class="gh-small gh-muted">' . esc_html( self::is_new_hire( $data ) ? __( 'Your onboarding checklist', 'ghca-ecd' ) : __( 'Your annual checklist', 'ghca-ecd' ) ) . '</span></div>';
    $h .= '<span class="gh-badge gh-badge--neutral">' . esc_html( sprintf( /* translators: 1: done, 2: total */ __( '%1$d of %2$d done', 'ghca-ecd' ), $done, count( $items ) ) ) . '</span></div>';
    $h .= '<ul class="gh-ecd-acts">';
    foreach ( $items as $item ) {
      $key = (string) ( $item['key'] ?? '' );
      if ( ! empty( $item['done'] ) ) {
        $icon = 'check-circle';
        $tone = 'done';
        $sub  = __( 'Done', 'ghca-ecd' );
      } elseif ( in_array( $key, array( 'courses', 'quizzes' ), true ) ) {
        $icon = 'clock';
        $tone = 'due';
        $sub  = sprintf( /* translators: %s: due date */ __( 'Due %s', 'ghca-ecd' ), $due );
      } elseif ( 'policies' === $key ) {
        $started = (int) ( $policies['progress'] ?? 0 ) > 0;
        $icon    = $started ? 'progress' : 'circle';
        $tone    = $started ? 'info' : 'todo';
        $sub     = $started
          ? sprintf( /* translators: %d: percent */ __( 'Policies course %d%% done', 'ghca-ecd' ), (int) $policies['progress'] )
          : __( 'Complete the policies course', 'ghca-ecd' );
      } else {
        $icon = 'circle';
        $tone = 'todo';
        $sub  = __( 'For your own records', 'ghca-ecd' );
      }
      $url = $urls[ $key ] ?? '';
      $in  = self::icon( $icon, 'lg' ) . '<span class="gh-ecd-act-t"><span class="gh-ecd-act-l">' . esc_html( (string) $item['label'] ) . '</span><span class="gh-small gh-muted">' . esc_html( $sub ) . '</span></span>';
      if ( '' !== $url ) {
        $h .= '<li><a class="gh-ecd-act is-' . esc_attr( $tone ) . '" href="' . esc_url( $url ) . '">' . $in . self::icon( 'chev-right', 'sm' ) . '</a>';
      } else {
        $h .= '<li><span class="gh-ecd-act is-' . esc_attr( $tone ) . '">' . $in . '</span>';
      }
      // The policy page is reading material only; viewing it never ticks the item.
      $page = 'policies' === $key && empty( $item['done'] ) ? self::link_url( 'policies' ) : '';
      if ( '' !== $page ) {
        $h .= '<a class="gh-ecd-act-sub" href="' . esc_url( $page ) . '">' . esc_html__( 'Read the policy', 'ghca-ecd' ) . '</a>';
      }
      $h .= '</li>';
    }
    return $h . '</ul></div>';
  }

  private static function announcements_html(): string {
    $items = GHCA_ECD_Shortcodes::get_announcement_items( false );
    $icons = array( 'alert' => 'alert', 'reminder' => 'bell', 'update' => 'megaphone', 'system' => 'info' );

    $h  = '<div class="gh-card gh-ecd-col">';
    $h .= '<div class="gh-card-hd"><div><h2 class="gh-h4">' . esc_html__( 'Announcements', 'ghca-ecd' ) . '</h2><span class="gh-small gh-muted">' . esc_html__( 'From your agency', 'ghca-ecd' ) . '</span></div></div>';
    if ( ! $items ) {
      // Same card, header and padding as when it has items; the body fills the row height like its neighbours.
      $h .= '<div class="gh-ecd-anns gh-ecd-anns--empty"><div class="gh-ecd-empty-state">' . self::icon( 'megaphone', 'xl' ) . '<p>' . esc_html__( 'No current announcements', 'ghca-ecd' ) . '</p></div></div>';
      return $h . '</div>';
    }
    $h .= '<div class="gh-ecd-anns">';
    foreach ( array_slice( $items, 0, 3 ) as $item ) {
      $type = isset( $icons[ $item['type'] ?? '' ] ) ? (string) $item['type'] : 'update';
      $h   .= '<div class="gh-ann"><span class="gh-ann-ico gh-ann-ico--' . esc_attr( $type ) . '">' . self::icon( $icons[ $type ] ) . '</span><div class="gh-ecd-ann-b">';
      $h   .= '<span class="gh-ann-t">' . esc_html( (string) $item['title'] ) . '</span>';
      if ( ! empty( $item['meta'] ) ) {
        $h .= '<p class="gh-ann-m">' . esc_html( (string) $item['meta'] ) . '</p>';
      }
      $meta = '';
      if ( ! empty( $item['url'] ) ) {
        $meta .= '<a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html__( 'Take action', 'ghca-ecd' ) . ' →</a>';
      }
      if ( ! empty( $item['date'] ) ) {
        $meta .= '<span>' . esc_html( (string) $item['date'] ) . '</span>';
      }
      if ( '' !== $meta ) {
        $h .= '<div class="gh-ann-meta">' . $meta . '</div>';
      }
      $h .= '</div></div>';
    }
    return $h . '</div></div>';
  }

  /**
   * ODP bulletin ticker, read from the admin plugin's in-process API. The
   * admin plugin owns the /odp-bulletins/ routes; each headline links there.
   * Nothing renders when that API is not installed.
   */
  private static function ticker_html(): string {
    if ( ! class_exists( 'GHCA_ODP_Bulletins' ) || ! method_exists( 'GHCA_ODP_Bulletins', 'snapshot' ) || ! method_exists( 'GHCA_ODP_Bulletins', 'item_url' ) ) {
      return '';
    }

    // The employee dashboard always shows the employee audience (employee or both), for every role: an admin
    // looking at this page sees what employees see. Ticker, drawer and its count all come from this one set.
    $context = 'employee';

    // Preferred: the admin plugin's own fragment — ticker, ODP-Drawer side drawer and its audience-filtered
    // payload — so wording, disclaimer and states match the admin dashboard exactly.
    if ( method_exists( 'GHCA_ODP_Bulletins', 'dashboard_fragment' ) ) {
      try {
        $fragment = GHCA_ODP_Bulletins::dashboard_fragment( $context );
      } catch ( Throwable $e ) {
        $fragment = array();
      }
      if ( ! empty( $fragment['ticker'] ) ) {
        self::$overlays .= (string) ( $fragment['drawer'] ?? '' ) . (string) ( $fragment['payload'] ?? '' );
        return (string) $fragment['ticker'];
      }
    }

    try {
      $view    = GHCA_ODP_Bulletins::snapshot( $context );
      $library = method_exists( 'GHCA_ODP_Bulletins', 'library_url' ) ? GHCA_ODP_Bulletins::library_url() : home_url( '/odp-bulletins/' );
    } catch ( Throwable $e ) {
      return '';
    }

    $items = array();
    foreach ( (array) ( $view['bulletins'] ?? array() ) as $b ) {
      if ( ! is_array( $b ) || empty( $b['id'] ) || empty( $b['headline'] ) ) {
        continue;
      }
      $ts      = strtotime( (string) ( $b['published_at'] ?? '' ) );
      $items[] = array(
        'url'  => GHCA_ODP_Bulletins::item_url( (string) $b['id'] ),
        'text' => (string) $b['headline'],
        'iso'  => (string) ( $b['published_at'] ?? '' ),
        'date' => $ts ? wp_date( 'M j', $ts ) : '',
      );
    }

    $h  = '<section class="gh-ticker" data-ghca-ecd-ticker aria-label="' . esc_attr__( 'ODP Bulletin', 'ghca-ecd' ) . '">';
    $h .= '<span class="gh-ticker-lbl">' . self::icon( 'megaphone' ) . esc_html__( 'ODP Bulletin', 'ghca-ecd' ) . '</span>';
    if ( $items ) {
      $h .= '<div class="gh-ticker-vp"><ul class="gh-ticker-track">';
      // The second pass is a visual copy so the loop is seamless; it is hidden from assistive tech and the tab order.
      foreach ( array( false, true ) as $copy ) {
        foreach ( $items as $item ) {
          $h .= '<li class="gh-ticker-item"' . ( $copy ? ' aria-hidden="true"' : '' ) . '><time datetime="' . esc_attr( $item['iso'] ) . '">' . esc_html( $item['date'] ) . '</time>';
          $h .= '<a href="' . esc_url( $item['url'] ) . '"' . ( $copy ? ' tabindex="-1"' : '' ) . '>' . esc_html( $item['text'] ) . '</a></li>';
        }
      }
      $h .= '</ul></div>';
    } else {
      $state = (string) ( $view['state'] ?? '' );
      $h    .= '<div class="gh-ecd-ticker-msg" role="status">' . esc_html( 'empty' === $state || 'fresh' === $state ? __( 'No current bulletins.', 'ghca-ecd' ) : __( 'Bulletins are unavailable right now.', 'ghca-ecd' ) ) . '</div>';
    }
    $h .= '<div class="gh-ticker-ctl">';
    if ( count( $items ) > 1 ) {
      $h .= '<button class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" type="button" data-ghca-ecd-ticker-pause aria-pressed="false" aria-label="' . esc_attr__( 'Pause bulletin ticker', 'ghca-ecd' ) . '" data-label-pause="' . esc_attr__( 'Pause bulletin ticker', 'ghca-ecd' ) . '" data-label-play="' . esc_attr__( 'Play bulletin ticker', 'ghca-ecd' ) . '">' . self::icon( 'pause' ) . '</button>';
    }
    $h .= '<a href="' . esc_url( $library ) . '">' . esc_html__( 'All bulletins', 'ghca-ecd' ) . '</a>';
    return $h . '</div></section>';
  }

  /** @param array<string,mixed> $data */
  private static function kpis_html( array $data ): string {
    $total     = (int) $data['total_courses'];
    $done      = (int) $data['completed_count'];
    $left      = max( 0, $total - $done );
    $new_hire  = self::is_new_hire( $data );
    $days_left = null;
    if ( $new_hire && isset( $data['new_hire']['days_remaining'] ) && is_numeric( $data['new_hire']['days_remaining'] ) ) {
      $days_left = (int) $data['new_hire']['days_remaining'];
    } elseif ( isset( $data['compliance_notification']['days_remaining'] ) && is_numeric( $data['compliance_notification']['days_remaining'] ) ) {
      $days_left = (int) $data['compliance_notification']['days_remaining'];
    }
    $status = self::status_chip( $data );

    $h  = '<section class="gh-grid gh-grid--kpi gh-ecd-kpis" aria-label="' . esc_attr__( 'My status', 'ghca-ecd' ) . '">';

    $h .= '<div class="gh-stat"><span class="gh-stat-top">' . esc_html__( 'Training status', 'ghca-ecd' ) . '</span>';
    $h .= '<span class="gh-st gh-st--lg gh-st--' . esc_attr( $status[0] ) . ' gh-ecd-chip">' . esc_html( $status[1] ) . '</span>';
    $h .= '<span class="gh-stat-sub">' . esc_html( $total > 0 && $left > 0 ? sprintf( /* translators: %d: courses */ _n( '%d course left', '%d courses left', $left, 'ghca-ecd' ), $left ) : ( $total > 0 ? __( 'All required training done', 'ghca-ecd' ) : __( 'Nothing assigned yet', 'ghca-ecd' ) ) ) . '</span></div>';

    $h .= '<div class="gh-stat"><span class="gh-stat-top">' . esc_html__( 'Courses', 'ghca-ecd' ) . '</span>';
    $h .= '<span class="gh-stat-val">' . esc_html( (string) $done ) . ' <span class="gh-ecd-of">/ ' . esc_html( (string) $total ) . '</span></span>';
    $h .= '<div class="gh-bar gh-bar--sm" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) self::percent( $data ) ) . '" aria-label="' . esc_attr__( 'Courses complete', 'ghca-ecd' ) . '"><span style="width: ' . esc_attr( (string) self::percent( $data ) ) . '%;"></span></div></div>';

    $start_sub = __( 'Your annual cycle start', 'ghca-ecd' );
    if ( $new_hire && ! empty( $data['new_hire']['enrollment_ts'] ) ) {
      $day       = (int) floor( max( 0, time() - (int) $data['new_hire']['enrollment_ts'] ) / DAY_IN_SECONDS ) + 1;
      $window    = (int) ( $data['new_hire']['deadline_days'] ?? 30 );
      $start_sub = sprintf( /* translators: 1: day number, 2: window length */ __( 'Day %1$d of your first %2$d', 'ghca-ecd' ), min( $day, $window ), $window );
    }
    $h .= '<div class="gh-stat"><span class="gh-stat-top">' . esc_html__( 'Start date', 'ghca-ecd' ) . '</span><span class="gh-stat-val gh-ecd-date">' . esc_html( (string) $data['start_date_label'] ) . '</span><span class="gh-stat-sub">' . esc_html( $start_sub ) . '</span></div>';

    $due_sub  = '';
    $due_warn = false;
    if ( null !== $days_left && empty( $data['all_complete'] ) ) {
      $due_warn = $days_left <= 30;
      $due_sub  = $days_left < 0
        ? __( 'Past due', 'ghca-ecd' )
        : ( 0 === $days_left ? __( 'Due today', 'ghca-ecd' ) : sprintf( /* translators: %d: days */ _n( '%d day left', '%d days left', $days_left, 'ghca-ecd' ), $days_left ) );
    } elseif ( ! empty( $data['all_complete'] ) ) {
      $due_sub = __( 'Complete for this cycle', 'ghca-ecd' );
    }
    $h .= '<div class="gh-stat"><span class="gh-stat-top">' . esc_html__( 'Due date', 'ghca-ecd' ) . '</span><span class="gh-stat-val gh-ecd-date">' . esc_html( (string) $data['compliance_due_date_label'] ?: '—' ) . '</span>';
    if ( '' !== $due_sub ) {
      $h .= '<span class="gh-stat-sub' . ( $due_warn ? ' gh-ecd-warn' : '' ) . '">' . esc_html( $due_sub ) . '</span>';
    }
    $h .= '</div>';

    $certs = (int) ( $data['packet_certificate_count'] ?? 0 );
    $h    .= '<div class="gh-stat"><span class="gh-stat-top">' . esc_html__( 'Certificates', 'ghca-ecd' ) . '</span><span class="gh-stat-val">' . esc_html( (string) $certs ) . '</span>';
    $h    .= '<a class="gh-small gh-ecd-statlink" href="' . esc_url( self::certificates_url() ) . '">' . esc_html( $certs > 0 ? __( 'Download', 'ghca-ecd' ) : __( 'View', 'ghca-ecd' ) ) . ' →</a></div>';

    return $h . '</section>';
  }

  /**
   * Status chip variant and label for the training status.
   *
   * @param array<string,mixed> $data
   * @return array{0:string,1:string}
   */
  public static function status_chip( array $data ): array {
    $status = (string) ( $data['training_status'] ?? '' );
    if ( 'new_hire_overdue' === $status ) {
      return array( 'overdue', __( 'Overdue', 'ghca-ecd' ) );
    }
    if ( 'expired' === $status ) {
      return array( 'overdue', __( 'Expired', 'ghca-ecd' ) );
    }
    if ( 'expiring_soon' === $status ) {
      return array( 'duesoon', __( 'Expiring soon', 'ghca-ecd' ) );
    }
    if ( in_array( $status, array( 'compliant', 'completed' ), true ) ) {
      return array( 'completed', __( 'Compliant', 'ghca-ecd' ) );
    }
    if ( ! empty( $data['new_hire']['at_risk'] ) ) {
      return array( 'duesoon', __( 'Due soon', 'ghca-ecd' ) );
    }
    if ( 'in_progress' === $status ) {
      return array( 'progress', __( 'In progress', 'ghca-ecd' ) );
    }
    return array( 'notstarted', __( 'Not started', 'ghca-ecd' ) );
  }

  /** @param array<string,mixed> $data */
  private static function quick_links_html( array $data ): string {
    $icons = array( 'courses' => 'grad', 'award' => 'award', 'handbook' => 'book', 'policies' => 'file', 'support' => 'lifebuoy' );
    $certs = (int) ( $data['packet_certificate_count'] ?? 0 );
    $subs  = array(
      'courses'  => sprintf( /* translators: %d: courses */ _n( '%d assigned', '%d assigned', (int) $data['total_courses'], 'ghca-ecd' ), (int) $data['total_courses'] ),
      'award'    => sprintf( /* translators: %d: certificates */ _n( '%d earned', '%d earned', $certs, 'ghca-ecd' ), $certs ),
      'handbook' => __( 'Company guide', 'ghca-ecd' ),
      'policies' => __( 'Procedures', 'ghca-ecd' ),
      'support'  => __( 'Get help', 'ghca-ecd' ),
    );
    $titles = array( 'courses' => __( 'My courses', 'ghca-ecd' ), 'award' => __( 'Certificates', 'ghca-ecd' ), 'handbook' => __( 'Handbook', 'ghca-ecd' ), 'policies' => __( 'Policies', 'ghca-ecd' ), 'support' => __( 'Support', 'ghca-ecd' ) );

    $h = '<section class="gh-card"><div class="gh-card-hd gh-card-hd--plain"><h2 class="gh-h4">' . esc_html__( 'Quick links', 'ghca-ecd' ) . '</h2></div><div class="gh-card-bd gh-qlinks gh-ecd-qlinks">';
    foreach ( GHCA_ECD_Shortcodes::get_quick_links() as $link ) {
      $key   = (string) ( $link['icon'] ?? '' );
      $url   = 'award' === $key ? self::certificates_url() : (string) $link['url'];
      $title = $titles[ $key ] ?? (string) $link['label'];
      $sub   = $subs[ $key ] ?? (string) ( $link['subtitle'] ?? '' );
      $h    .= '<a class="gh-qlink" href="' . esc_url( $url ) . '">' . self::icon( $icons[ $key ] ?? 'arrow-right' ) . '<span><strong>' . esc_html( $title ) . '</strong>' . ( '' !== $sub ? '<span>' . esc_html( $sub ) . '</span>' : '' ) . '</span></a>';
    }
    return $h . '</div></section>';
  }

  private static function support_html(): string {
    $h  = '<section class="gh-card gh-card--sunken gh-ecd-help">';
    $h .= '<span class="gh-stat-ico">' . self::icon( 'lifebuoy', 'lg' ) . '</span>';
    $h .= '<div><h2 class="gh-h4">' . esc_html__( 'Trouble opening a course?', 'ghca-ecd' ) . '</h2><p class="gh-p gh-secondary">' . esc_html( sprintf( /* translators: %s: support email */ __( 'Email %s and we will help you get back on track.', 'ghca-ecd' ), self::support_email() ) ) . '</p></div>';
    $h .= '<a class="gh-btn gh-btn--primary gh-ecd-agency" href="' . esc_url( 'mailto:' . self::support_email() ) . '">' . self::icon( 'mail' ) . esc_html__( 'Contact support', 'ghca-ecd' ) . '</a>';
    return $h . '</section>';
  }

  /* ---------------------------------------------------------------------
   * Certificates (Emp-Certificates, M-Emp-Certs)
   * ------------------------------------------------------------------- */

  public static function render_certificates(): string {
    $user_id = get_current_user_id();
    $data    = GHCA_ECD_Data_Provider::get_snapshot();
    $model   = self::certificates_model( GHCA_ECD_Data_Provider::get_required_courses( $user_id ), $user_id, (int) ( $data['due_ts'] ?? 0 ) );
    $earned  = $model['earned'];
    $locked  = $model['locked'];
    $packet  = GHCA_ECD_Cert_Packet::is_available() && count( $earned ) > 0;

    $summary = sprintf( /* translators: %d: certificates earned */ _n( '%d earned', '%d earned', count( $earned ), 'ghca-ecd' ), count( $earned ) );
    if ( $locked ) {
      $summary .= ' · ' . sprintf( /* translators: %d: certificates still to earn */ _n( '%d more unlocks as you finish courses', '%d more unlock as you finish courses', count( $locked ), 'ghca-ecd' ), count( $locked ) );
    }

    $h  = '<div class="gh-toolbar"><div><h1 class="gh-h1">' . esc_html__( 'My certificates', 'ghca-ecd' ) . '</h1><p class="gh-p gh-secondary gh-ecd-sub">' . esc_html( $summary ) . '</p></div>';
    $h .= '<div class="gh-ecd-tools">';
    if ( $packet ) {
      $h .= '<button class="gh-btn gh-btn--secondary" type="button" data-ghca-ecd-packet>' . self::icon( 'download' ) . esc_html__( 'Download all as one PDF', 'ghca-ecd' ) . '</button>';
    }
    $h .= '</div></div>';

    $h .= '<section class="gh-ecd-earned" aria-label="' . esc_attr__( 'Earned certificates', 'ghca-ecd' ) . '">';
    if ( ! $earned ) {
      $h .= '<div class="gh-card gh-ecd-none"><span class="gh-stat-ico">' . self::icon( 'award', 'lg' ) . '</span><div><h2 class="gh-h4">' . esc_html__( 'No certificates yet', 'ghca-ecd' ) . '</h2><p class="gh-p gh-secondary">' . esc_html__( 'Finish a course and its certificate appears here, ready to download.', 'ghca-ecd' ) . '</p></div>';
      if ( ! empty( $data['continue_url'] ) && $locked ) {
        $h .= '<a class="gh-btn gh-btn--primary" href="' . esc_url( (string) $data['continue_url'] ) . '">' . esc_html__( 'Continue training', 'ghca-ecd' ) . self::icon( 'arrow-right' ) . '</a>';
      }
      $h .= '</div>';
    }
    foreach ( $earned as $cert ) {
      $h .= '<article class="gh-card gh-ecd-cert">';
      $h .= '<div class="gh-ecd-cert-pv" aria-hidden="true">' . self::icon( 'award', 'xl' ) . '<span class="gh-ecd-cert-pv-t">' . esc_html__( 'Certificate of completion', 'ghca-ecd' ) . '</span><span class="gh-ghost-bar"></span></div>';
      $h .= '<div class="gh-ecd-cert-b"><span class="gh-st gh-st--' . esc_attr( $cert['chip'][0] ) . '">' . esc_html( $cert['chip'][1] ) . '</span>';
      $h .= '<h2 class="gh-h3">' . esc_html( $cert['title'] ) . '</h2><span class="gh-secondary">' . esc_html( $cert['meta'] ) . '</span>';
      $h .= '<div class="gh-ecd-cert-a"><a class="gh-btn gh-btn--primary" href="' . esc_url( $cert['url'] ) . '" download>' . self::icon( 'download' ) . esc_html__( 'Download PDF', 'ghca-ecd' ) . '<span class="gh-sr"> ' . esc_html( $cert['title'] ) . '</span></a>';
      $h .= '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-ecd-cv-open data-cv-url="' . esc_url( $cert['url'] ) . '" data-cv-title="' . esc_attr( $cert['title'] ) . '" data-cv-completed="' . esc_attr( $cert['issued_label'] ) . '" data-cv-expires="' . esc_attr( $cert['expires_label'] ) . '" data-cv-chip="' . esc_attr( $cert['chip'][0] ) . '" data-cv-chip-label="' . esc_attr( $cert['chip'][1] ) . '" data-cv-file="' . esc_attr( self::file_name( $cert['title'] ) ) . '">' . self::icon( 'eye' ) . esc_html__( 'View', 'ghca-ecd' ) . '<span class="gh-sr"> ' . esc_html( $cert['title'] ) . '</span></button></div>';
      $h .= '</div></article>';
    }
    $h .= '</section>';

    if ( $locked ) {
      $h  .= '<section class="gh-card"><div class="gh-card-hd"><h2 class="gh-h4">' . esc_html__( 'Still to earn', 'ghca-ecd' ) . ' <span class="gh-ecd-count">· ' . esc_html( (string) count( $locked ) ) . '</span></h2><span class="gh-small gh-muted">' . esc_html__( 'Finish the course to unlock its certificate', 'ghca-ecd' ) . '</span></div><ul class="gh-ecd-locked">';
      foreach ( $locked as $row ) {
        $sub = $row['due_label'] . ' · ' . sprintf( /* translators: %d: percent */ __( '%d%% done', 'ghca-ecd' ), $row['progress'] );
        $h  .= '<li><a class="gh-ecd-lock is-' . esc_attr( $row['chip'][0] ) . '" href="' . esc_url( $row['url'] ) . '"><span class="gh-stat-ico">' . self::icon( 'locked' === $row['chip'][0] ? 'lock' : ( 'overdue' === $row['chip'][0] ? 'alert' : 'award' ) ) . '</span>';
        $h  .= '<span class="gh-ecd-lock-t"><strong>' . esc_html( $row['title'] ) . '</strong><span class="gh-small gh-muted">' . esc_html( $sub ) . '</span></span>';
        $h  .= '<span class="gh-st gh-st--' . esc_attr( $row['chip'][0] ) . '">' . esc_html( $row['chip'][1] ) . '</span><span class="gh-ecd-lock-go">' . esc_html__( 'Go to course', 'ghca-ecd' ) . '</span></a></li>';
      }
      $h .= '</ul></section>';
    }

    if ( $earned ) {
      self::$overlays .= self::viewer_html();
    }

    return self::shell( 'certificates', $h );
  }

  /** A readable PDF file name from a course title: "fatal-five-certificate.pdf". */
  public static function file_name( string $title ): string {
    $slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
    return ( '' !== $slug ? substr( $slug, 0, 60 ) . '-' : '' ) . 'certificate.pdf';
  }

  /**
   * Certificate viewer (Modal-CertViewer, Modal-CertViewer-States), ported from the admin plugin's viewer with
   * employee wording. The PDF is fetched from the employee's own LearnDash certificate link into memory and shown
   * from a same-page blob in the browser's PDF viewer (assets/ecd-redesign.js).
   */
  private static function viewer_html(): string {
    $btn = static function ( string $attr, string $icon, string $label ): string {
      return '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" ' . $attr . ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . self::icon( $icon ) . '</button>';
    };
    $msg = static function ( string $state, string $icon, string $tone, string $title, string $text, string $action ): string {
      return '<div class="gh-cv-msg" hidden data-ghca-cv-pane="' . esc_attr( $state ) . '" role="' . ( 'blocked' === $state ? 'status' : 'alert' ) . '"><span class="gh-cv-msg-ico' . ( '' !== $tone ? ' gh-cv-msg-ico--' . esc_attr( $tone ) : '' ) . '" aria-hidden="true">' . self::icon( $icon, 'lg' ) . '</span><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p>' . $action . '</div>';
    };

    $h  = '<div class="gh-mod-layer gh-cv-layer" id="ghca-ecd-cert-viewer" hidden aria-hidden="true" data-ghca-cv><div class="gh-scrim" data-ghca-cv-close></div>';
    $h .= '<div class="gh-modal gh-cv" role="dialog" aria-modal="true" aria-labelledby="ghca-ecd-cv-t" aria-describedby="ghca-ecd-cv-sub" data-ghca-cv-dialog>';
    $h .= '<div class="gh-modal-hd"><button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-cv-x-phone" data-ghca-cv-close aria-label="' . esc_attr__( 'Close certificate', 'ghca-ecd' ) . '">' . self::icon( 'x' ) . '</button><span class="gh-stat-ico gh-cv-ico" aria-hidden="true">' . self::icon( 'award' ) . '</span>';
    $h .= '<div class="gh-cv-hd-t"><h2 class="gh-h3" id="ghca-ecd-cv-t" data-ghca-cv-heading>' . esc_html__( 'Certificate', 'ghca-ecd' ) . '</h2><p class="gh-p gh-secondary" id="ghca-ecd-cv-sub" data-ghca-cv-sub></p></div>';
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-cv-x" data-ghca-cv-close aria-label="' . esc_attr__( 'Close certificate', 'ghca-ecd' ) . '">' . self::icon( 'x' ) . '</button></div>';
    $h .= '<div class="gh-modal-bd"><div class="gh-cv-grid"><div class="gh-cv-view">';
    $h .= '<div class="gh-cv-bar" role="toolbar" aria-label="' . esc_attr__( 'Certificate preview controls', 'ghca-ecd' ) . '" data-ghca-cv-bar>';
    $h .= $btn( 'data-ghca-cv-page="prev"', 'chev-left', __( 'Previous page', 'ghca-ecd' ) ) . '<span class="gh-num" data-ghca-cv-pages aria-live="polite">1 / 1</span>' . $btn( 'data-ghca-cv-page="next"', 'chev-right', __( 'Next page', 'ghca-ecd' ) );
    $h .= '<span class="gh-sep" aria-hidden="true"></span>' . $btn( 'data-ghca-cv-zoom="out"', 'zoom-out', __( 'Zoom out', 'ghca-ecd' ) ) . '<span class="gh-cv-zoom" role="status" data-ghca-cv-zoomval>100%</span>' . $btn( 'data-ghca-cv-zoom="in"', 'plus', __( 'Zoom in', 'ghca-ecd' ) ) . $btn( 'data-ghca-cv-zoom="fit"', 'fit', __( 'Fit to width', 'ghca-ecd' ) );
    $h .= '<span class="gh-cv-grow" aria-hidden="true"></span>' . $btn( 'data-ghca-cv-print', 'printer', __( 'Print certificate', 'ghca-ecd' ) );
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--sm" data-ghca-cv-newtab>' . self::icon( 'external', 'sm' ) . esc_html__( 'Open in new tab', 'ghca-ecd' ) . '<span class="gh-sr"> ' . esc_html__( '(opens the PDF in a new tab)', 'ghca-ecd' ) . '</span></button></div>';
    $h .= '<div class="gh-cv-stage" data-ghca-cv-stage>';
    $h .= '<div class="gh-cv-page gh-cv-loading" aria-busy="true" data-ghca-cv-pane="loading"><span class="gh-bul-skel gh-ecd-skel-1"></span><span class="gh-bul-skel gh-ecd-skel-2"></span><span class="gh-bul-skel gh-ecd-skel-3"></span><span class="gh-bul-skel gh-ecd-skel-4"></span><span class="gh-sr" role="status">' . esc_html__( 'Loading certificate…', 'ghca-ecd' ) . '</span></div>';
    $h .= '<iframe class="gh-cv-frame" title="' . esc_attr__( 'Certificate PDF', 'ghca-ecd' ) . '" hidden data-ghca-cv-pane="ready" data-ghca-cv-frame></iframe>';
    $h .= $msg( 'blocked', 'file', '', __( 'This PDF can’t be shown here', 'ghca-ecd' ), __( 'Your browser does not show PDFs inside the page. The certificate is fine — download it to open it on your device.', 'ghca-ecd' ), '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-cv-download>' . self::icon( 'download' ) . esc_html__( 'Download PDF', 'ghca-ecd' ) . '</button>' );
    $h .= $msg( 'unavailable', 'file-x', 'danger', __( 'This certificate isn’t available', 'ghca-ecd' ), __( 'The certificate file couldn’t be found. If you finished this course recently, try again later or contact support.', 'ghca-ecd' ), '' );
    $h .= $msg( 'denied', 'lock', 'danger', __( 'You can’t open this certificate', 'ghca-ecd' ), __( 'Your session may have expired. Reload the page, sign in again and try again.', 'ghca-ecd' ), '' );
    $h .= $msg( 'error', 'alert', 'danger', __( 'The certificate couldn’t be loaded', 'ghca-ecd' ), __( 'The connection failed or the server answered with something other than a PDF. Nothing was downloaded.', 'ghca-ecd' ), '<button type="button" class="gh-btn gh-btn--secondary" data-ghca-cv-retry>' . self::icon( 'refresh' ) . esc_html__( 'Try again', 'ghca-ecd' ) . '</button>' );
    $h .= '</div></div>';
    $h .= '<aside class="gh-cv-side" aria-label="' . esc_attr__( 'Certificate details', 'ghca-ecd' ) . '"><div class="gh-cv-side-hd"><h3>' . esc_html__( 'Certificate details', 'ghca-ecd' ) . '</h3><span class="gh-st" data-ghca-cv-chip></span></div>';
    $h .= '<dl class="gh-cv-dl" data-ghca-cv-dl></dl>';
    $h .= '<p class="gh-small gh-muted gh-cv-note">' . self::icon( 'info', 'sm' ) . '<span>' . esc_html__( 'Download saves the original PDF exactly as it was issued.', 'ghca-ecd' ) . '</span></p></aside>';
    $h .= '</div></div>';
    $h .= '<div class="gh-modal-ft gh-cv-ft"><p class="gh-small gh-cv-ft-msg" role="status" aria-live="polite" data-ghca-cv-msg></p><button type="button" class="gh-btn gh-btn--secondary gh-cv-close-btn" data-ghca-cv-close>' . esc_html__( 'Close', 'ghca-ecd' ) . '</button>';
    $h .= '<button type="button" class="gh-btn gh-btn--secondary gh-cv-newtab-ft" data-ghca-cv-newtab>' . self::icon( 'external' ) . esc_html__( 'Open in new tab', 'ghca-ecd' ) . '</button>';
    $h .= '<button type="button" class="gh-btn gh-btn--primary" data-ghca-cv-download>' . self::icon( 'download' ) . esc_html__( 'Download PDF', 'ghca-ecd' ) . '</button></div>';
    return $h . '</div></div>';
  }

  /**
   * Splits the enrolled courses into earned certificates and certificates
   * still to earn. Completed courses without a certificate link are left out
   * of both; so are unfinished courses that award no certificate.
   *
   * @param array<int,array<string,mixed>> $courses
   * @return array{earned:array<int,array<string,mixed>>,locked:array<int,array<string,mixed>>}
   */
  public static function certificates_model( array $courses, int $user_id, int $fallback_due_ts = 0, int $now = 0 ): array {
    $earned = array();
    $locked = array();

    foreach ( $courses as $course ) {
      $id    = (int) ( $course['id'] ?? 0 );
      $title = self::title_case( (string) ( $course['title'] ?? '' ) );
      if ( ! empty( $course['completed'] ) ) {
        $url = GHCA_ECD_Data_Provider::get_certificate_url( $user_id, $id );
        if ( '' === $url ) {
          continue;
        }
        $state  = (string) ( $course['compliance_state'] ?? 'completed' );
        $issued = (int) ( $course['completed_ts'] ?? 0 );
        $expiry = (string) ( $course['expiration_label'] ?? '' );
        $meta   = $issued > 0 ? sprintf( /* translators: %s: date */ __( 'Issued %s', 'ghca-ecd' ), wp_date( 'M j, Y', $issued ) ) : __( 'Issued', 'ghca-ecd' );
        if ( 'expired' === $state ) {
          $chip = array( 'overdue', __( 'Expired', 'ghca-ecd' ) );
          $meta .= ' · ' . ( '' !== $expiry ? sprintf( /* translators: %s: date */ __( 'expired %s', 'ghca-ecd' ), $expiry ) : __( 'expired', 'ghca-ecd' ) );
        } elseif ( 'expiring_soon' === $state ) {
          $chip = array( 'duesoon', __( 'Expiring soon', 'ghca-ecd' ) );
          $meta .= ' · ' . sprintf( /* translators: %s: date */ __( 'expires %s', 'ghca-ecd' ), $expiry );
        } else {
          $chip = array( 'completed', __( 'Earned', 'ghca-ecd' ) );
          $meta .= ' · ' . ( '' !== $expiry ? sprintf( /* translators: %s: date */ __( 'expires %s', 'ghca-ecd' ), $expiry ) : __( 'no expiry', 'ghca-ecd' ) );
        }
        $earned[] = array(
          'title'         => $title,
          'url'           => $url,
          'meta'          => $meta,
          'chip'          => $chip,
          'issued'        => $issued,
          'issued_label'  => $issued > 0 ? wp_date( 'M j, Y', $issued ) : '',
          'expires_label' => '' !== $expiry ? $expiry : __( 'No expiry', 'ghca-ecd' ),
        );
        continue;
      }

      if ( ! GHCA_ECD_Data_Provider::course_has_certificate( $id ) ) {
        continue;
      }
      $due      = self::course_due( $course, array( 'due_ts' => $fallback_due_ts ), $now );
      $locked[] = array(
        'title'     => $title,
        'url'       => (string) ( $course['url'] ?? self::courses_url() ),
        'progress'  => max( 0, min( 100, (int) ( $course['progress'] ?? 0 ) ) ),
        'chip'      => $due['chip'],
        'due_label' => $due['label'],
        'due_ts'    => $due['ts'],
      );
    }

    usort(
      $earned,
      static function ( array $a, array $b ): int {
        return $b['issued'] <=> $a['issued'];
      }
    );

    return array( 'earned' => $earned, 'locked' => $locked );
  }

  /* ---------------------------------------------------------------------
   * Per-course due dates and status
   * ------------------------------------------------------------------- */

  /** @param array<string,mixed> $data @return array<string,mixed> The snapshot course with this id, or an empty array. */
  private static function find_course( array $data, int $id ): array {
    foreach ( (array) ( $data['courses'] ?? array() ) as $course ) {
      if ( (int) ( $course['id'] ?? 0 ) === $id ) {
        return (array) $course;
      }
    }
    return array();
  }

  /**
   * Status chip for an unfinished course. Overdue when its due date has passed, Locked only when LearnDash
   * prerequisites lock it, Due soon within 30 days, otherwise In progress / Not started.
   *
   * @return array{0:string,1:string}
   */
  public static function course_chip( bool $locked, int $due_ts, int $progress, int $now ): array {
    if ( $due_ts > 0 && $due_ts < $now ) {
      return array( 'overdue', __( 'Overdue', 'ghca-ecd' ) );
    }
    if ( $locked ) {
      return array( 'locked', __( 'Locked', 'ghca-ecd' ) );
    }
    if ( $due_ts > 0 && $due_ts - $now <= 30 * DAY_IN_SECONDS ) {
      return array( 'duesoon', __( 'Due soon', 'ghca-ecd' ) );
    }
    return $progress > 0 ? array( 'progress', __( 'In progress', 'ghca-ecd' ) ) : array( 'notstarted', __( 'Not started', 'ghca-ecd' ) );
  }

  /**
   * A course's own due date (its expiry from course lifespans) and chip, falling back to the cycle or
   * new-hire due date only when the course has none.
   *
   * @param array<string,mixed> $course
   * @param array<string,mixed> $data   Needs 'due_ts' (the fallback).
   * @return array{ts:int,label:string,chip:array{0:string,1:string}}
   */
  public static function course_due( array $course, array $data, int $now = 0 ): array {
    $now = $now > 0 ? $now : time();
    $own = (int) ( $course['expiration_ts'] ?? 0 );
    $ts  = $own > 0 ? $own : (int) ( $data['due_ts'] ?? 0 );
    $chip = self::course_chip( ! empty( $course['locked'] ), $ts, (int) ( $course['progress'] ?? 0 ), $now );
    if ( $ts <= 0 ) {
      $label = __( 'No due date', 'ghca-ecd' );
    } elseif ( $ts < $now ) {
      $label = sprintf( /* translators: %s: date */ __( 'Was due %s', 'ghca-ecd' ), wp_date( 'M j, Y', $ts ) );
    } else {
      $label = sprintf( /* translators: %s: date */ __( 'Due %s', 'ghca-ecd' ), wp_date( 'M j, Y', $ts ) );
    }
    return array( 'ts' => $ts, 'label' => $label, 'chip' => $chip );
  }

  /* ---------------------------------------------------------------------
   * What's blocking my packet
   * ------------------------------------------------------------------- */

  /** @return array<string,string> ODP topic key => plain-language name. */
  public static function topic_labels(): array {
    return array(
      'person_centered'     => __( 'Person-centered practices', 'ghca-ecd' ),
      'abuse_prevention'    => __( 'Preventing, detecting and reporting abuse', 'ghca-ecd' ),
      'individual_rights'   => __( 'Individual rights', 'ghca-ecd' ),
      'reporting_incidents' => __( 'Recognizing and reporting incidents', 'ghca-ecd' ),
      'behavior_supports'   => __( 'Positive behavior supports', 'ghca-ecd' ),
      'individual_plan'     => __( 'Implementing individual plans (ISP)', 'ghca-ecd' ),
      'job_related'         => __( 'Job-related training', 'ghca-ecd' ),
    );
  }

  /**
   * Plain-language blocking reasons for this employee's packet, built from the admin plugin's packet calculation
   * (GHCA_Audit_Calculator::calculate_employee_audit_data, read-only) plus this plugin's own certificate data.
   * Pure: every input is passed in, nothing is read or written here.
   *
   * @param array<string,mixed>            $audit    Calculator output ([] when unavailable or excluded).
   * @param string                         $tracker  'annual' or 'orientation'.
   * @param array<int,array<string,mixed>> $courses  This plugin's enrolled-course rows.
   * @param array<int,array<string,mixed>> $mappings Admin audit mapping: course id => config (odp_category, coverage).
   * @param array<int,string>              $cert_urls Course id => certificate link ('' when none).
   * @return array<int,array{key:string,tone:string,text:string,sub:string,url:string,link:string}>
   */
  public static function blocking_reasons( array $audit, string $tracker, array $courses, array $mappings, array $cert_urls ): array {
    $by_id = array();
    foreach ( $courses as $c ) {
      $by_id[ (int) ( $c['id'] ?? 0 ) ] = $c;
    }
    // A course that fixes a topic: an unfinished enrolled course mapped to it, else any mapped enrolled course.
    $course_for = static function ( string $topic ) use ( $mappings, $by_id ): array {
      $fallback = array();
      foreach ( $mappings as $cid => $config ) {
        $covers = (array) ( $config['coverage'] ?? array() );
        if ( ( $config['odp_category'] ?? '' ) !== $topic && ! in_array( $topic, $covers, true ) ) {
          continue;
        }
        if ( isset( $by_id[ (int) $cid ] ) ) {
          if ( empty( $by_id[ (int) $cid ]['completed'] ) ) {
            return $by_id[ (int) $cid ];
          }
          $fallback = $fallback ? $fallback : $by_id[ (int) $cid ];
        }
      }
      return $fallback;
    };
    $first_open = array();
    foreach ( $courses as $c ) {
      if ( empty( $c['completed'] ) ) {
        $first_open = $c;
        break;
      }
    }
    $title = static function ( array $c ): string {
      return self::title_case( (string) ( $c['title'] ?? '' ) );
    };

    $reasons = array();
    if ( $audit ) {
      // Orientation (new hire) not complete.
      $program = (array) ( $audit['new_hire_program'] ?? array() );
      if ( 'orientation' === $tracker && ! empty( $program['active'] ) && empty( $program['complete'] ) ) {
        $open = (array) ( $program['outstanding'] ?? array() );
        $next = $open ? ( $by_id[ (int) ( $open[0]['course_id'] ?? 0 ) ] ?? array( 'title' => (string) ( $open[0]['title'] ?? '' ), 'url' => '' ) ) : $first_open;
        $reasons[] = array(
          'key'  => 'orientation',
          'tone' => 'warning',
          'text' => __( 'Orientation is not complete', 'ghca-ecd' ),
          'sub'  => sprintf( /* translators: %d: courses */ _n( '%d orientation course left', '%d orientation courses left', max( 1, count( $open ) ), 'ghca-ecd' ), max( 1, count( $open ) ) ),
          'url'  => (string) ( $next['url'] ?? '' ),
          'link' => $next ? sprintf( /* translators: %s: course */ __( 'Continue %s', 'ghca-ecd' ), $title( $next ) ) : '',
        );
      } elseif ( 'orientation' === $tracker && empty( $audit['completion_date'] ) && empty( $program['active'] ) ) {
        $reasons[] = array( 'key' => 'orientation', 'tone' => 'warning', 'text' => __( 'Orientation is not complete', 'ghca-ecd' ), 'sub' => __( 'Finish your orientation courses', 'ghca-ecd' ), 'url' => (string) ( $first_open['url'] ?? '' ), 'link' => $first_open ? sprintf( /* translators: %s: course */ __( 'Continue %s', 'ghca-ecd' ), $title( $first_open ) ) : '' );
      }

      // ODP 6100 annual hours short.
      if ( 'annual' === $tracker && isset( $audit['total_annual_hrs'] ) && (float) $audit['total_annual_hrs'] < 24 ) {
        $have      = round( (float) $audit['total_annual_hrs'], 1 );
        $reasons[] = array(
          'key'  => 'hours',
          'tone' => 'warning',
          'text' => sprintf( /* translators: 1: hours done, 2: hours required */ __( 'ODP 6100 annual training: %1$s of %2$s hours', 'ghca-ecd' ), rtrim( rtrim( number_format( $have, 1, '.', '' ), '0' ), '.' ), '24' ),
          'sub'  => __( 'Hours count once the course is complete', 'ghca-ecd' ),
          'url'  => (string) ( $first_open['url'] ?? '' ),
          'link' => $first_open ? sprintf( /* translators: %s: course */ __( 'Continue %s', 'ghca-ecd' ), $title( $first_open ) ) : '',
        );
      }

      // Required topics with no training on record. ("Needs review" is the agency's step, not the employee's.)
      foreach ( self::topic_labels() as $topic => $label ) {
        if ( ( $audit['category_statuses'][ $topic ] ?? '' ) !== 'Missing training' ) {
          continue;
        }
        $course    = $course_for( $topic );
        $reasons[] = array(
          'key'  => 'topic:' . $topic,
          'tone' => 'danger',
          'text' => sprintf( /* translators: %s: training topic */ __( 'Missing required training: %s', 'ghca-ecd' ), $label ),
          'sub'  => $course ? __( 'Completing this course covers it', 'ghca-ecd' ) : __( 'Ask your agency which course covers this topic', 'ghca-ecd' ),
          'url'  => (string) ( $course['url'] ?? '' ),
          'link' => $course ? sprintf( /* translators: %s: course */ __( 'Go to %s', 'ghca-ecd' ), $title( $course ) ) : '',
        );
      }
    }

    // This plugin's own certificate facts: expired, or completed with no certificate issued yet.
    foreach ( $courses as $c ) {
      $cid = (int) ( $c['id'] ?? 0 );
      if ( 'expired' === ( $c['compliance_state'] ?? '' ) ) {
        $reasons[] = array(
          'key'  => 'expired:' . $cid,
          'tone' => 'danger',
          'text' => sprintf( /* translators: %s: course */ __( 'Expired certificate: %s', 'ghca-ecd' ), $title( $c ) ),
          'sub'  => '' !== (string) ( $c['expiration_label'] ?? '' ) ? sprintf( /* translators: %s: date */ __( 'Expired %s. Retake the course to renew it.', 'ghca-ecd' ), (string) $c['expiration_label'] ) : __( 'Retake the course to renew it.', 'ghca-ecd' ),
          'url'  => (string) ( $c['url'] ?? '' ),
          'link' => __( 'Retake the course', 'ghca-ecd' ),
        );
      } elseif ( ! empty( $c['completed'] ) && ! empty( $c['has_certificate'] ) && '' === (string) ( $cert_urls[ $cid ] ?? '' ) ) {
        $reasons[] = array(
          'key'  => 'nocert:' . $cid,
          'tone' => 'warning',
          'text' => sprintf( /* translators: %s: course */ __( 'Missing required certificate: %s', 'ghca-ecd' ), $title( $c ) ),
          'sub'  => __( 'The course is complete but no certificate was issued. Open the course to finish any remaining step.', 'ghca-ecd' ),
          'url'  => (string) ( $c['url'] ?? '' ),
          'link' => __( 'Open the course', 'ghca-ecd' ),
        );
      }
    }

    return $reasons;
  }

  /**
   * Reads the admin plugin's packet calculation for the current user. Read-only: the calculator only reads
   * LearnDash, Jotform and agency records. Returns [] when the admin plugin (or its calculator) is unavailable,
   * or when the account is excluded from audits.
   *
   * @return array{audit:array<string,mixed>,tracker:string,mappings:array<int,array<string,mixed>>}
   */
  private static function packet_audit( array $data ): array {
    $tracker = self::is_new_hire( $data ) ? 'orientation' : 'annual';
    $out     = array( 'audit' => array(), 'tracker' => $tracker, 'mappings' => array() );
    if ( ! class_exists( 'GHCA_Audit_Calculator' ) || ! method_exists( 'GHCA_Audit_Calculator', 'calculate_employee_audit_data' ) ) {
      return $out;
    }
    $mappings = get_option( 'ghca_acd_audit_mapping', array() );
    $mappings = is_array( $mappings ) ? $mappings : array();
    foreach ( $mappings as $cid => $config ) {
      if ( is_array( $config ) && class_exists( 'GHCA_Audit_Mapping' ) && method_exists( 'GHCA_Audit_Mapping', 'coverage_categories' ) ) {
        $mappings[ $cid ]['coverage'] = (array) GHCA_Audit_Mapping::coverage_categories( $config );
      }
    }
    $out['mappings'] = $mappings;
    $user = get_userdata( (int) $data['user_id'] );
    try {
      $out['audit'] = (array) GHCA_Audit_Calculator::calculate_employee_audit_data(
        array( 'user_id' => (int) $data['user_id'], 'name' => $user ? (string) $user->display_name : '', 'email' => '', 'group' => '' ),
        $tracker,
        $mappings
      );
    } catch ( Throwable $e ) {
      $out['audit'] = array();
    }
    return $out;
  }

  /** @param array<string,mixed> $data */
  private static function blockers_html( array $data ): string {
    $packet    = self::packet_audit( $data );
    $courses   = array();
    $cert_urls = array();
    foreach ( (array) ( $data['courses'] ?? array() ) as $c ) {
      $cid = (int) ( $c['id'] ?? 0 );
      $c['has_certificate'] = GHCA_ECD_Data_Provider::course_has_certificate( $cid );
      $courses[]            = $c;
      $cert_urls[ $cid ]    = ! empty( $c['completed'] ) ? GHCA_ECD_Data_Provider::get_certificate_url( (int) $data['user_id'], $cid ) : '';
    }
    $reasons = self::blocking_reasons( $packet['audit'], $packet['tracker'], $courses, $packet['mappings'], $cert_urls );

    $h  = '<section class="gh-card gh-ecd-block" aria-labelledby="gh-ecd-block-t">';
    $h .= '<div class="gh-card-hd"><div><h2 class="gh-h4" id="gh-ecd-block-t">' . esc_html__( "What's blocking my packet", 'ghca-ecd' ) . '</h2><span class="gh-small gh-muted">' . esc_html( 'orientation' === $packet['tracker'] ? __( 'Your orientation packet', 'ghca-ecd' ) : __( 'Your annual training packet', 'ghca-ecd' ) ) . '</span></div>';
    if ( $reasons ) {
      $h .= '<span class="gh-badge gh-badge--neutral">' . esc_html( sprintf( /* translators: %d: items */ _n( '%d item', '%d items', count( $reasons ), 'ghca-ecd' ), count( $reasons ) ) ) . '</span>';
    }
    $h .= '</div>';
    if ( ! $reasons ) {
      $h .= '<div class="gh-ecd-allset">' . self::icon( 'check-circle', 'lg' ) . '<div><strong>' . esc_html__( "You're all set", 'ghca-ecd' ) . '</strong><p class="gh-small gh-muted">' . esc_html__( 'Nothing is blocking your packet right now.', 'ghca-ecd' ) . '</p></div></div>';
      return $h . '</section>';
    }
    $h .= '<ul class="gh-ecd-blocks">';
    foreach ( $reasons as $r ) {
      $h .= '<li class="gh-ecd-blk is-' . esc_attr( $r['tone'] ) . '">' . self::icon( 'danger' === $r['tone'] ? 'alert' : 'clock', 'lg' );
      $h .= '<div class="gh-ecd-blk-t"><span class="gh-ecd-blk-l">' . esc_html( $r['text'] ) . '</span><span class="gh-small gh-muted">' . esc_html( $r['sub'] ) . '</span></div>';
      if ( '' !== $r['url'] && '' !== $r['link'] ) {
        $h .= '<a class="gh-btn gh-btn--secondary gh-btn--sm gh-ecd-blk-go" href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['link'] ) . self::icon( 'arrow-right', 'sm' ) . '</a>';
      }
      $h .= '</li>';
    }
    return $h . '</ul></section>';
  }

  /* ---------------------------------------------------------------------
   * Profile photo (BuddyBoss / BuddyPress avatar)
   * ------------------------------------------------------------------- */

  /**
   * Initials from the person's real name (first + last), never the username. Empty when no real name is known.
   */
  public static function name_initials( string $first, string $last, string $display, string $login ): string {
    $first = trim( $first );
    $last  = trim( $last );
    if ( '' !== $first || '' !== $last ) {
      return self::initials( trim( $first . ' ' . $last ) );
    }
    $display = trim( $display );
    if ( '' === $display || 0 === strcasecmp( $display, trim( $login ) ) ) {
      return '';
    }
    return self::initials( $display );
  }

  public static function has_uploaded_photo( int $user_id ): bool {
    return function_exists( 'bp_get_user_has_avatar' ) && (bool) bp_get_user_has_avatar( $user_id );
  }

  /** The BuddyBoss change-avatar screen for this user, or '' without BuddyPress. */
  public static function change_photo_url( int $user_id ): string {
    if ( function_exists( 'bp_members_get_user_url' ) ) {
      $base = (string) bp_members_get_user_url( $user_id );
    } elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
      $base = (string) bp_core_get_user_domain( $user_id );
    } else {
      return '';
    }
    if ( '' === $base ) {
      return '';
    }
    $url = trailingslashit( $base ) . 'profile/change-avatar/';
    return add_query_arg(
      array(
        'ghca_ecd_return' => wp_create_nonce( 'ghca_ecd_avatar_return' ),
        'ghca_ecd_back'   => rawurlencode( (string) get_permalink() ),
      ),
      $url
    );
  }

  /** @param array<string,mixed> $data */
  private static function avatar_initials( array $data ): string {
    $user = get_userdata( (int) $data['user_id'] );
    return self::name_initials( (string) ( $data['first_name'] ?? '' ), (string) ( $data['last_name'] ?? '' ), (string) ( $data['display_name'] ?? '' ), $user ? (string) $user->user_login : '' );
  }

  /** The uploaded photo's URL, or '' when the employee has none (initials show instead). */
  private static function photo_url( int $user_id ): string {
    if ( ! self::has_uploaded_photo( $user_id ) || ! function_exists( 'bp_core_fetch_avatar' ) ) {
      return '';
    }
    return (string) bp_core_fetch_avatar( array( 'item_id' => $user_id, 'object' => 'user', 'type' => 'full', 'html' => false, 'no_grav' => true ) );
  }

  /**
   * @param array<string,mixed> $data
   * @param array<string,mixed> $photo_cfg From photo_config(); empty when the pop-up is not available.
   */
  private static function avatar_html( array $data, array $photo_cfg = array() ): string {
    $uid   = (int) $data['user_id'];
    $init  = self::avatar_initials( $data );
    $photo = self::photo_url( $uid );
    $inner = '' !== $photo
      ? '<img src="' . esc_url( $photo ) . '" alt="" width="64" height="64" loading="lazy" decoding="async">'
      : ( '' !== $init ? esc_html( $init ) : self::icon( 'user', 'lg' ) );
    $class = 'gh-avatar gh-avatar--xl gh-avatar--brand gh-ecd-avatar' . ( '' !== $photo ? ' has-photo' : '' );
    $cam   = '<span class="gh-ecd-avatar-cam" aria-hidden="true"><span class="gh-i i-camera"></span></span>';
    $label = esc_attr__( 'Change profile photo', 'ghca-ecd' );
    $url   = self::uploads_disabled() ? '' : self::change_photo_url( $uid );

    if ( $photo_cfg ) {
      // The pop-up opens on click; without JavaScript the link still goes to BuddyBoss's change-photo screen.
      $attrs = ' aria-haspopup="dialog" aria-controls="ghca-ecd-photo" aria-label="' . $label . '" title="' . $label . '" data-ghca-ph-open';
      return '' !== $url
        ? '<a class="' . $class . '" href="' . esc_url( $url ) . '"' . $attrs . '>' . $inner . $cam . '</a>'
        : '<button type="button" class="' . $class . '"' . $attrs . '>' . $inner . $cam . '</button>';
    }
    if ( '' === $url ) {
      return '<span class="' . $class . '" aria-hidden="true">' . $inner . '</span>';
    }
    return '<a class="' . $class . '" href="' . esc_url( $url ) . '" aria-label="' . $label . '" title="' . $label . '">' . $inner . $cam . '</a>';
  }

  /** BuddyBoss setting "Allow members to upload profile photos" switched off. */
  private static function uploads_disabled(): bool {
    return function_exists( 'bp_disable_avatar_uploads' ) && (bool) bp_disable_avatar_uploads();
  }

  /**
   * Pixel size of the square photo the pop-up sends. BuddyBoss needs at least its "full" avatar size and shrinks
   * anything wider than its original-photo limit, so the photo is sent at twice the full size, capped at that
   * limit: sharp on high-density screens and never re-compressed by BuddyBoss.
   */
  public static function photo_export_size( int $full_w, int $full_h, int $original_max ): int {
    $side = max( 1, $full_w, $full_h );
    return max( $side, min( $side * 2, $original_max > 0 ? $original_max : $side * 2 ) );
  }

  /**
   * Settings for the profile photo pop-up, or an empty array when it can't work here (no BuddyBoss / BuddyPress
   * REST API, uploads switched off in BuddyBoss, or the filter says no). Without it the avatar links to
   * BuddyBoss's change-photo screen as before.
   *
   * The pop-up saves through BuddyBoss's public REST API (POST and DELETE /buddyboss/v1/members/{id}/avatar),
   * which checks the login, the REST nonce and that the user may edit this avatar, then stores the photo with
   * BuddyBoss's own upload and crop code and fires `xprofile_avatar_uploaded`.
   *
   * @return array<string,mixed>
   */
  public static function photo_config( int $user_id ): array {
    $ready = $user_id > 0
      && $user_id === (int) get_current_user_id()
      && function_exists( 'rest_url' )
      && function_exists( 'bp_core_avatar_full_width' )
      && class_exists( 'BP_REST_Attachments_Member_Avatar_Endpoint' )
      && ! self::uploads_disabled();
    if ( ! (bool) apply_filters( 'ghca_ecd_photo_popup', $ready, $user_id ) || ! $ready ) {
      return array();
    }
    $ns   = function_exists( 'bp_rest_namespace' ) ? (string) bp_rest_namespace() : 'buddyboss';
    $ver  = function_exists( 'bp_rest_version' ) ? (string) bp_rest_version() : 'v1';
    $full = (int) bp_core_avatar_full_width();
    $fh   = function_exists( 'bp_core_avatar_full_height' ) ? (int) bp_core_avatar_full_height() : $full;
    $max  = function_exists( 'bp_core_avatar_original_max_width' ) ? (int) bp_core_avatar_original_max_width() : 0;
    $side = max( 1, $full, $fh );

    return array(
      'user_id'   => $user_id,
      'rest'      => (string) rest_url( sprintf( '%s/%s/members/%d/avatar', $ns, $ver, $user_id ) ),
      'nonce'     => (string) wp_create_nonce( 'wp_rest' ),
      'min'       => $side,
      'size'      => self::photo_export_size( $full, $fh, $max ),
      'has_photo' => self::has_uploaded_photo( $user_id ),
      'photo'     => self::photo_url( $user_id ),
      'fallback'  => self::change_photo_url( $user_id ),
    );
  }

  /**
   * Profile photo pop-up: choose a photo, crop it to a square, save. Same overlay pattern as the certificate
   * viewer (assets/ecd-redesign.js moves it to the end of <body> and handles focus and Escape).
   *
   * @param array<string,mixed> $cfg From photo_config().
   */
  public static function photo_dialog_html( array $cfg ): string {
    $min      = (int) $cfg['min'];
    $fallback = (string) $cfg['fallback'];
    $preview  = '' !== (string) $cfg['photo']
      ? '<img src="' . esc_url( (string) $cfg['photo'] ) . '" alt="">'
      : ( '' !== (string) ( $cfg['initials'] ?? '' ) ? esc_html( (string) $cfg['initials'] ) : self::icon( 'user', 'lg' ) );

    $h  = '<div class="gh-mod-layer gh-ph-layer" id="ghca-ecd-photo" hidden aria-hidden="true" data-ghca-ph';
    $h .= ' data-rest="' . esc_url( (string) $cfg['rest'] ) . '" data-nonce="' . esc_attr( (string) $cfg['nonce'] ) . '" data-min="' . esc_attr( (string) $min ) . '" data-size="' . esc_attr( (string) (int) $cfg['size'] ) . '" data-user="' . esc_attr( (string) (int) $cfg['user_id'] ) . '" data-has-photo="' . ( ! empty( $cfg['has_photo'] ) ? '1' : '0' ) . '" data-initials="' . esc_attr( (string) ( $cfg['initials'] ?? '' ) ) . '">';
    $h .= '<div class="gh-scrim" data-ghca-ph-close></div>';
    $h .= '<div class="gh-modal gh-ph" role="dialog" aria-modal="true" aria-labelledby="ghca-ecd-ph-t" aria-describedby="ghca-ecd-ph-sub" data-ghca-ph-dialog>';
    $h .= '<div class="gh-modal-hd"><span class="gh-stat-ico gh-ph-ico" aria-hidden="true">' . self::icon( 'camera' ) . '</span>';
    $h .= '<div class="gh-ph-hd-t"><h2 class="gh-h3" id="ghca-ecd-ph-t">' . esc_html__( 'Profile photo', 'ghca-ecd' ) . '</h2><p class="gh-p gh-secondary" id="ghca-ecd-ph-sub">' . esc_html__( 'Shown on your dashboard and across the site. Never added to certificates.', 'ghca-ecd' ) . '</p></div>';
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-ph-x" data-ghca-ph-close aria-label="' . esc_attr__( 'Close', 'ghca-ecd' ) . '">' . self::icon( 'x' ) . '</button></div>';

    $h .= '<div class="gh-modal-bd">';
    // Pick: the current photo and a drop zone.
    $h .= '<div class="gh-ph-pick" data-ghca-ph-pane="pick">';
    $h .= '<span class="gh-avatar gh-avatar--brand gh-ph-current' . ( '' !== (string) $cfg['photo'] ? ' has-photo' : '' ) . '" data-ghca-ph-current>' . $preview . '</span>';
    $h .= '<div class="gh-drop gh-ph-drop" data-ghca-ph-drop><span class="gh-ph-drop-ico" aria-hidden="true">' . self::icon( 'upload', 'lg' ) . '</span><div class="gh-ph-drop-t">';
    $h .= '<strong>' . esc_html__( 'Choose a photo', 'ghca-ecd' ) . '</strong><span class="gh-small gh-muted gh-ph-drag">' . esc_html__( 'or drag one here', 'ghca-ecd' ) . '</span>';
    /* translators: %1$d, %2$d: minimum width and height in pixels */
    $h .= '<span class="gh-small gh-muted" id="ghca-ecd-ph-rules">' . esc_html( sprintf( __( 'JPG, PNG or WebP, at least %1$d × %2$d pixels.', 'ghca-ecd' ), $min, $min ) ) . '</span></div>';
    $h .= '<label class="gh-btn gh-btn--secondary gh-ph-choose">' . self::icon( 'upload' ) . '<span>' . esc_html__( 'Choose photo', 'ghca-ecd' ) . '</span><input type="file" class="gh-ph-file" accept="image/jpeg,image/png,image/webp" aria-describedby="ghca-ecd-ph-rules" data-ghca-ph-file></label>';
    $h .= '</div></div>';
    // Crop: a square stage with a round guide, zoom controls.
    $h .= '<div class="gh-ph-crop" hidden data-ghca-ph-pane="crop">';
    $h .= '<div class="gh-ph-stage" tabindex="0" role="application" aria-roledescription="' . esc_attr__( 'photo crop area', 'ghca-ecd' ) . '" aria-label="' . esc_attr__( 'Photo position. Drag, or use the arrow keys, to move the photo. Plus and minus zoom.', 'ghca-ecd' ) . '" data-ghca-ph-stage><img alt="" draggable="false" data-ghca-ph-img><span class="gh-ph-guide" aria-hidden="true"></span></div>';
    $h .= '<div class="gh-ph-zoom"><button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" data-ghca-ph-zoom="-1" aria-label="' . esc_attr__( 'Zoom out', 'ghca-ecd' ) . '">' . self::icon( 'zoom-out' ) . '</button>';
    $h .= '<input type="range" class="gh-ph-range" min="100" max="400" step="1" value="100" aria-label="' . esc_attr__( 'Zoom', 'ghca-ecd' ) . '" data-ghca-ph-range>';
    $h .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" data-ghca-ph-zoom="1" aria-label="' . esc_attr__( 'Zoom in', 'ghca-ecd' ) . '">' . self::icon( 'zoom-in' ) . '</button></div>';
    $h .= '<p class="gh-small gh-muted gh-ph-hint">' . esc_html__( 'Drag the photo to position it inside the circle.', 'ghca-ecd' ) . '</p>';
    $h .= '</div>';
    // Messages (errors, the remove question).
    $h .= '<div class="gh-ph-msg" role="alert" hidden data-ghca-ph-msg><span class="gh-ph-msg-t" data-ghca-ph-msg-t></span>';
    if ( '' !== $fallback ) {
      $h .= ' <a class="gh-ph-alt" href="' . esc_url( $fallback ) . '" hidden data-ghca-ph-alt>' . esc_html__( 'Use your profile page instead', 'ghca-ecd' ) . '</a>';
    }
    $h .= '</div>';
    $h .= '<p class="gh-ph-ask" hidden data-ghca-ph-pane="remove">' . self::icon( 'info' ) . '<span>' . esc_html__( 'Remove your photo? Your initials will show instead.', 'ghca-ecd' ) . '</span></p>';
    $h .= '</div>';

    // Footer: one row of buttons per pane.
    $h .= '<div class="gh-modal-ft gh-ph-ft">';
    $h .= '<div class="gh-ph-row" data-ghca-ph-for="pick"><button type="button" class="gh-btn gh-btn--danger-quiet gh-ph-rm"' . ( empty( $cfg['has_photo'] ) ? ' hidden' : '' ) . ' data-ghca-ph-remove>' . self::icon( 'trash' ) . esc_html__( 'Remove photo', 'ghca-ecd' ) . '</button><span class="gh-ph-grow"></span><button type="button" class="gh-btn gh-btn--secondary" data-ghca-ph-close>' . esc_html__( 'Cancel', 'ghca-ecd' ) . '</button></div>';
    $h .= '<div class="gh-ph-row" hidden data-ghca-ph-for="crop"><button type="button" class="gh-btn gh-btn--ghost" data-ghca-ph-again>' . self::icon( 'refresh' ) . esc_html__( 'Choose another', 'ghca-ecd' ) . '</button><span class="gh-ph-grow"></span><button type="button" class="gh-btn gh-btn--secondary" data-ghca-ph-close>' . esc_html__( 'Cancel', 'ghca-ecd' ) . '</button><button type="button" class="gh-btn gh-btn--primary" data-ghca-ph-save>' . self::icon( 'check' ) . '<span data-ghca-ph-save-t>' . esc_html__( 'Save photo', 'ghca-ecd' ) . '</span></button></div>';
    $h .= '<div class="gh-ph-row" hidden data-ghca-ph-for="remove"><span class="gh-ph-grow"></span><button type="button" class="gh-btn gh-btn--secondary" data-ghca-ph-keep>' . esc_html__( 'Keep photo', 'ghca-ecd' ) . '</button><button type="button" class="gh-btn gh-btn--danger" data-ghca-ph-confirm>' . self::icon( 'trash' ) . '<span data-ghca-ph-confirm-t>' . esc_html__( 'Remove', 'ghca-ecd' ) . '</span></button></div>';
    $h .= '</div></div></div>';

    return $h;
  }

  /** "Remove photo": a small own-account form, shown only when an uploaded photo exists. */
  private static function remove_photo_html( int $user_id ): string {
    if ( ! self::has_uploaded_photo( $user_id ) || ! function_exists( 'bp_core_delete_existing_avatar' ) ) {
      return '';
    }
    return '<form class="gh-ecd-rmphoto" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ghca_ecd_remove_photo">'
      . wp_nonce_field( 'ghca_ecd_remove_photo', '_ghca_ecd_nonce', false, false )
      . '<input type="hidden" name="ghca_ecd_back" value="' . esc_attr( (string) get_permalink() ) . '">'
      . '<button type="submit" class="gh-ecd-linkbtn">' . esc_html__( 'Remove photo', 'ghca-ecd' ) . '</button></form>';
  }

  /** Admin-only note for pages that still use a legacy section shortcode. */
  public static function legacy_notice_html(): string {
    return '<div class="ghca-ecd-ui ghca-ecd-ui--note"><div class="gh-app" data-theme="' . esc_attr( self::theme_slug() ) . '"><div class="gh-container"><p class="gh-alert gh-alert--info gh-ecd-legacy" role="note">' . self::icon( 'info' ) . '<span>' . esc_html__( 'This page uses a legacy shortcode. Replace it with [employee_compliance_dashboard].', 'ghca-ecd' ) . ' <span class="gh-small">' . esc_html__( '(Only admins see this note.)', 'ghca-ecd' ) . '</span></span></p></div></div></div>';
  }

  /* ---------------------------------------------------------------------
   * Links
   * ------------------------------------------------------------------- */

  public static function courses_url(): string {
    $page = get_page_by_path( 'my-courses' );
    return $page ? (string) get_permalink( $page ) : home_url( '/my-courses/' );
  }

  public static function certificates_url(): string {
    $page = get_page_by_path( 'cert-download' );
    $url  = $page ? (string) get_permalink( $page ) : home_url( '/cert-download/' );
    return (string) apply_filters( 'ghca_employee_certificates_url', $url );
  }

  private static function link_url( string $icon ): string {
    foreach ( GHCA_ECD_Shortcodes::get_quick_links() as $link ) {
      if ( ( $link['icon'] ?? '' ) === $icon ) {
        return (string) $link['url'];
      }
    }
    return '';
  }

  public static function support_email(): string {
    return class_exists( 'GHCA_Dashboard_Branding', false )
      ? (string) GHCA_Dashboard_Branding::get_support_email()
      : (string) apply_filters( 'ghca_employee_support_email', 'info@lafiahealthcare.org' );
  }
}
