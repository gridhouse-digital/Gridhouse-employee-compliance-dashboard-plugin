<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Data engine for the employee dashboard: builds the per-user compliance
 * snapshot (courses, cycle, notification, new-hire status) with per-request
 * memoization and a persistent date-stamped transient cache.
 */
final class GHCA_ECD_Data_Provider {

  /** @var array<string,mixed>|null */
  private static $snapshot = null;

  /** @var array<int,array<int,array<string,mixed>>> Per-request memo, keyed by user ID. */
  private static $required_courses = array();

  /** @var array<int,array<string,mixed>> Per-request memo, keyed by user ID. */
  private static $notification = array();

  public static function init(): void {
    // Cache busting: keep the persistent snapshot honest when LearnDash data changes.
    add_action( 'learndash_course_completed', array( __CLASS__, 'on_course_completed' ) );
    add_action( 'learndash_update_course_access', array( __CLASS__, 'on_course_access_changed' ), 10, 4 );
    add_action( 'ld_added_group_access', array( __CLASS__, 'on_group_access_changed' ), 10, 2 );
    add_action( 'ld_removed_group_access', array( __CLASS__, 'on_group_access_changed' ), 10, 2 );
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
    return self::build_cache_key( GHCA_Employee_Compliance_Dashboard::VERSION, $user_id, (string) wp_date( 'Ymd' ) );
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

  /** @return array<string,mixed> */
  public static function get_snapshot(): array {
    if ( null !== self::$snapshot ) {
      return self::$snapshot;
    }

    $user_id = get_current_user_id();
    $ttl     = GHCA_ECD_Settings::get_cache_ttl();

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
        get_option( GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' ),
        $user_id
      ) );

    // Certificates eligible for the self-service packet (always measured
    // against the full enrolled list, not the new-hire subset). Stored in
    // the snapshot so cached page loads can show/hide the packet button
    // without re-running the LearnDash course loop.
    $packet_certs = 0;
    foreach ( self::get_required_courses( $user_id ) as $packet_course ) {
      if ( ! empty( $packet_course['completed'] ) && '' !== self::get_certificate_url( $user_id, (int) $packet_course['id'] ) ) {
        ++$packet_certs;
      }
    }

    // The single cycle / new-hire due date, as a timestamp: the fallback for courses with no due date of their own.
    $due_ts = ( ! empty( $new_hire['active'] ) && empty( $new_hire['complete'] ) && ! empty( $new_hire['deadline_ts'] ) )
      ? (int) $new_hire['deadline_ts']
      : ( ! empty( $cycle['due_timestamp'] ) ? (int) $cycle['due_timestamp'] : (int) strtotime( (string) $due_raw ) );

    self::$snapshot = array(
      'user_id'                   => $user_id,
      'packet_certificate_count'  => $packet_certs,
      'due_ts'                    => $due_ts,
      'policies'                  => self::policies_status( $user_id ),
      'last_name'                 => get_user_meta( $user_id, 'last_name', true ),
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
        'id'             => (int) $next_course['id'],
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
  public static function get_required_courses( int $user_id ): array {
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
        'completed_ts'   => $completed_ts,
        'locked'         => ! $completed && self::prerequisites_lock( $course_id, $user_id ),
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

  public static function get_certificate_url( int $user_id, int $course_id ): string {
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

  /** True only when LearnDash prerequisites are enabled for the course and not yet met by this user. */
  public static function prerequisites_lock( int $course_id, int $user_id ): bool {
    if ( ! function_exists( 'learndash_get_course_prerequisite_enabled' ) || ! learndash_get_course_prerequisite_enabled( $course_id ) ) {
      return false;
    }
    if ( function_exists( 'learndash_is_course_prerequities_completed' ) ) { // LearnDash's own spelling.
      return ! learndash_is_course_prerequities_completed( $course_id, $user_id );
    }
    return false;
  }

  /**
   * The company policies course and this user's progress in it. Read-only.
   * Viewing the separate policy page never counts as completion.
   *
   * @return array{course_id:int,source:string,url:string,completed:bool,progress:int,title:string}
   */
  public static function policies_status( int $user_id ): array {
    $resolved = GHCA_ECD_Settings::resolve_policies_course();
    $id       = (int) $resolved['course_id'];
    $out      = array( 'course_id' => $id, 'source' => (string) $resolved['source'], 'url' => '', 'completed' => false, 'progress' => 0, 'title' => '' );
    if ( $id <= 0 ) {
      return $out;
    }
    $out['url']       = (string) ( get_permalink( $id ) ?: '' );
    $out['title']     = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
    $out['completed'] = function_exists( 'learndash_course_completed' ) && (bool) learndash_course_completed( $user_id, $id );
    if ( function_exists( 'learndash_course_progress' ) ) {
      $progress        = (array) learndash_course_progress( array( 'user_id' => $user_id, 'course_id' => $id, 'array' => true ) );
      $out['progress'] = (int) ( $progress['percentage'] ?? 0 );
    }
    if ( $out['completed'] ) {
      $out['progress'] = 100;
    }
    return $out;
  }

  /**
   * Whether finishing the course awards a certificate. Without LearnDash's
   * settings API the answer is assumed yes, so no course silently disappears.
   */
  public static function course_has_certificate( int $course_id ): bool {
    if ( $course_id <= 0 ) {
      return false;
    }
    if ( ! function_exists( 'learndash_get_setting' ) ) {
      return true;
    }
    return (int) learndash_get_setting( $course_id, 'certificate' ) > 0;
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
    $cycle_day        = $days_on_platform % GHCA_Employee_Compliance_Dashboard::CYCLE_DAYS;
    $cycle_day        = 0 === $cycle_day && $days_on_platform > 0 ? GHCA_Employee_Compliance_Dashboard::CYCLE_DAYS : $cycle_day;
    $days_remaining   = GHCA_Employee_Compliance_Dashboard::CYCLE_DAYS - $cycle_day;
    $cycle_number     = (int) floor( $days_on_platform / GHCA_Employee_Compliance_Dashboard::CYCLE_DAYS ) + 1;
    $due_timestamp    = $start + ( $cycle_number * GHCA_Employee_Compliance_Dashboard::CYCLE_DAYS * DAY_IN_SECONDS );

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
  public static function get_compliance_notification( int $user_id, array $new_hire = array() ): array {
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
    $in_notice_window = $days_remaining <= GHCA_Employee_Compliance_Dashboard::NOTICE_DAYS;

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

    /** @var array<int> $group_ids */
    $group_ids = apply_filters( 'ghca_ecd_start_group_ids', GHCA_ECD_Settings::get_start_group_ids() );

    foreach ( $group_ids as $group_id ) {
      $group_id = (int) $group_id;
      if ( $group_id <= 0 ) {
        continue;
      }
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
}
