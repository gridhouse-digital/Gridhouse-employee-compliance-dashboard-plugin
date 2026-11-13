<?php
/**
 * Bridge to the admin plugin's training-year calculator (admin 2.0.0-rc.2+).
 *
 * When the admin training-year switch is on, the admin plugin is the single source of truth for the
 * employee's due date, training-year window, annual hours threshold and per-course due dates. This class
 * only calls that API, guarded so a missing class or method means "absent" and the dashboard keeps its
 * 1.6.3 behaviour. It never reads the admin option itself and never does training-year date math.
 *
 * Contract (all public static on GHCA_ACD_Training_Year):
 *   enabled(): bool
 *   due( int $user_id, ?int $now = null ): array{due_timestamp:int,due_date_label:string,training_year_label:string}
 *   window( int $user_id, ?int $now = null ): array{start_ts:int,end_ts:int,start_date:string,end_date:string,missing:string,label:string}
 *   requirement( int $user_id ): array{key:string,label:string,hours:int,set:bool,source:string}
 *   course( int $user_id, int $course_id, bool $completed, int $completed_ts, ?int $now = null ): array{expiration_ts:int,expiration_label:string,compliance_state:string,lifespan_days:int,follows_training_year:bool}
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

final class GHCA_ECD_Training_Year {
  const API_CLASS = 'GHCA_ACD_Training_Year';

  /** Every method this plugin needs; if any is missing the API counts as absent. */
  const REQUIRED_METHODS = array( 'enabled', 'due', 'window', 'requirement', 'course' );

  /** True when the admin class exists with every required method. */
  public static function api_present(): bool {
    if ( ! class_exists( self::API_CLASS ) ) {
      return false;
    }
    foreach ( self::REQUIRED_METHODS as $method ) {
      if ( ! method_exists( self::API_CLASS, $method ) ) {
        return false;
      }
    }
    return true;
  }

  /**
   * True only when the API is present and the admin switch is on. Anything else (absent API, switch off,
   * an exception from the admin plugin) means the 1.6.3 behaviour.
   */
  public static function active(): bool {
    if ( ! self::api_present() ) {
      return false;
    }
    try {
      return (bool) call_user_func( array( self::API_CLASS, 'enabled' ) );
    } catch ( \Throwable $e ) {
      self::log( 'enabled', $e );
      return false;
    }
  }

  /** @return array{due_timestamp:int,due_date_label:string,training_year_label:string} */
  public static function due( int $user_id ): array {
    $raw = self::call( 'due', array( $user_id ) );
    return array(
      'due_timestamp'       => max( 0, (int) ( $raw['due_timestamp'] ?? 0 ) ),
      'due_date_label'      => (string) ( $raw['due_date_label'] ?? '' ),
      'training_year_label' => (string) ( $raw['training_year_label'] ?? '' ),
    );
  }

  /** @return array{start_ts:int,end_ts:int,start_date:string,end_date:string,missing:string,label:string} */
  public static function window( int $user_id ): array {
    $raw = self::call( 'window', array( $user_id ) );
    return array(
      'start_ts'   => max( 0, (int) ( $raw['start_ts'] ?? 0 ) ),
      'end_ts'     => max( 0, (int) ( $raw['end_ts'] ?? 0 ) ),
      'start_date' => (string) ( $raw['start_date'] ?? '' ),
      'end_date'   => (string) ( $raw['end_date'] ?? '' ),
      'missing'    => (string) ( $raw['missing'] ?? '' ),
      'label'      => (string) ( $raw['label'] ?? '' ),
    );
  }

  /** @return array{key:string,label:string,hours:int,set:bool,source:string} */
  public static function requirement( int $user_id ): array {
    $raw = self::call( 'requirement', array( $user_id ) );
    return array(
      'key'    => (string) ( $raw['key'] ?? '' ),
      'label'  => (string) ( $raw['label'] ?? '' ),
      'hours'  => max( 0, (int) ( $raw['hours'] ?? 0 ) ),
      'set'    => ! empty( $raw['set'] ),
      'source' => (string) ( $raw['source'] ?? '' ),
    );
  }

  /** @return array{expiration_ts:int,expiration_label:string,compliance_state:string,lifespan_days:int,follows_training_year:bool} */
  public static function course( int $user_id, int $course_id, bool $completed, int $completed_ts ): array {
    $raw   = self::call( 'course', array( $user_id, $course_id, $completed, $completed_ts ) );
    $state = (string) ( $raw['compliance_state'] ?? '' );
    return array(
      'expiration_ts'         => max( 0, (int) ( $raw['expiration_ts'] ?? 0 ) ),
      'expiration_label'      => (string) ( $raw['expiration_label'] ?? '' ),
      'compliance_state'      => '' !== $state ? $state : ( $completed ? 'current' : 'incomplete' ),
      'lifespan_days'         => max( 0, (int) ( $raw['lifespan_days'] ?? 0 ) ),
      'follows_training_year' => ! empty( $raw['follows_training_year'] ),
    );
  }

  /**
   * Whole days from today to the due day in the site time zone: 0 on the due day itself (the due
   * timestamp is that day's last second, so the whole day is still due), negative once it has passed.
   */
  public static function days_until( int $due_timestamp, int $now ): int {
    $tz    = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
    $today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0 );
    $due   = ( new \DateTimeImmutable( '@' . $due_timestamp ) )->setTimezone( $tz )->setTime( 0, 0 );
    $diff  = $today->diff( $due );
    return (int) $diff->days * ( $diff->invert ? -1 : 1 );
  }

  /** @param array<int,mixed> $args @return array<string,mixed> */
  private static function call( string $method, array $args ): array {
    try {
      $raw = call_user_func_array( array( self::API_CLASS, $method ), $args );
    } catch ( \Throwable $e ) {
      self::log( $method, $e );
      return array();
    }
    return is_array( $raw ) ? $raw : array();
  }

  private static function log( string $method, \Throwable $e ): void {
    error_log( sprintf( '[ghca-ecd] Training-year API %s() failed: %s: %s', $method, get_class( $e ), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
  }
}
