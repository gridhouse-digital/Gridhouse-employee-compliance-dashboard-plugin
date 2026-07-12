<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Self-service certificate packet: a logged-in employee downloads a single
 * merged PDF (cover page + every certificate they have earned).
 *
 * The heavy machinery — job store, hardened upload dirs, GC, TCPDF/FPDI
 * loading, page merging — is reused from the admin plugin
 * (GHCA_Audit_PDF_Jobs / GHCA_Audit_PDF). This class only contributes its own
 * endpoints, its own cover page, and a strictly self-scoped security model:
 * no user id is ever read from the request; an employee can only ever build
 * and download their own packet. Everything degrades gracefully (button and
 * endpoints simply absent) when the admin plugin is inactive.
 */
final class GHCA_ECD_Cert_Packet {

  const NONCE_ACTION         = 'ghca_ecd_pkt';
  const ACTIVE_JOB_TRANSIENT = 'ghca_ecd_pkt_active_'; // + user_id
  const ACTIVE_JOB_TTL       = 300; // one packet build at a time, per user

  public static function init(): void {
    if ( ! self::is_available() ) {
      return;
    }

    add_action( 'wp_ajax_ghca_ecd_pkt_init', array( __CLASS__, 'ajax_init' ) );
    add_action( 'wp_ajax_ghca_ecd_pkt_fetch', array( __CLASS__, 'ajax_fetch' ) );
    add_action( 'wp_ajax_ghca_ecd_pkt_merge', array( __CLASS__, 'ajax_merge' ) );
    add_action( 'wp_ajax_ghca_ecd_pkt_download', array( __CLASS__, 'ajax_download' ) );
    add_action( 'wp_footer', array( __CLASS__, 'render_progress_modal' ) );
  }

  /** The feature exists only while the admin plugin's PDF machinery is loadable. */
  public static function is_available(): bool {
    return class_exists( 'GHCA_Audit_PDF_Jobs' )
      && class_exists( 'GHCA_Audit_PDF' )
      && method_exists( 'GHCA_Audit_PDF', 'load_libs' )
      && method_exists( 'GHCA_Audit_PDF', 'create_document' )
      && method_exists( 'GHCA_Audit_PDF', 'append_certificate' );
  }

  /**
   * Pure, unit-testable self-scope check (defense in depth on top of the job
   * store's owner check): the manifest must target the requester themselves
   * and have been created by this feature, not by an admin audit build.
   */
  public static function manifest_is_self_scoped( array $job, int $user_id ): bool {
    return $user_id > 0
      && (int) ( $job['owner'] ?? 0 ) === $user_id
      && (int) ( $job['user_id'] ?? 0 ) === $user_id
      && ( $job['tracker'] ?? '' ) === 'employee';
  }

  /**
   * Common gate: nonce + logged-in, then (when a job id is expected) manifest
   * ownership + self-scope. Sends a JSON error and exits on failure.
   */
  private static function guard_ajax( bool $expects_job ): ?array {
    check_ajax_referer( self::NONCE_ACTION, 'nonce' );

    if ( ! is_user_logged_in() ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-ecd' ) ), 403 );
    }

    if ( ! $expects_job ) {
      return null;
    }

    $job_id = isset( $_REQUEST['job_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['job_id'] ) ) : '';
    $job    = GHCA_Audit_PDF_Jobs::get_job( $job_id, get_current_user_id() );
    if ( is_wp_error( $job ) ) {
      wp_send_json_error( array( 'message' => $job->get_error_message() ), 404 );
    }

    if ( ! self::manifest_is_self_scoped( $job, get_current_user_id() ) ) {
      wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ghca-ecd' ) ), 403 );
    }

    $job['job_id'] = $job_id;
    return $job;
  }

  /** @return array<int,array{course_id:int,title:string,url:string}> Completed courses that actually have a certificate. */
  private static function collect_certificates( int $user_id ): array {
    $certs = array();

    foreach ( GHCA_ECD_Data_Provider::get_required_courses( $user_id ) as $course ) {
      if ( empty( $course['completed'] ) ) {
        continue;
      }
      $url = GHCA_ECD_Data_Provider::get_certificate_url( $user_id, (int) $course['id'] );
      if ( '' === $url ) {
        continue;
      }
      $certs[] = array(
        'course_id' => (int) $course['id'],
        'title'     => (string) $course['title'],
        'url'       => $url,
      );
    }

    return $certs;
  }

  /** Used by the shortcode/welcome button so we never render a dead button. */
  public static function has_certificates(): bool {
    return is_user_logged_in() && count( self::collect_certificates( get_current_user_id() ) ) > 0;
  }

  private static function build_filename( int $user_id ): string {
    $user = get_userdata( $user_id );
    $name = $user ? $user->display_name : (string) $user_id;
    return 'My_Certificates_' . sanitize_title( $name ) . '_' . wp_date( 'Y-m-d' ) . '.pdf';
  }

  /** Phase 1: manifest for the requester's own certificates. */
  public static function ajax_init(): void {
    self::guard_ajax( false );

    $user_id = get_current_user_id();

    // One active build per user: prevents a double-click (or a hammering
    // client) from stacking up remote certificate fetches.
    if ( get_transient( self::ACTIVE_JOB_TRANSIENT . $user_id ) ) {
      wp_send_json_error( array( 'message' => __( 'A packet is already being generated. Please wait a moment and try again.', 'ghca-ecd' ) ), 429 );
    }

    GHCA_Audit_PDF_Jobs::gc();

    $certs = self::collect_certificates( $user_id );
    if ( empty( $certs ) ) {
      wp_send_json_error( array( 'message' => __( 'No certificates are available yet. Complete a course to earn your first certificate.', 'ghca-ecd' ) ) );
    }

    $job_id = GHCA_Audit_PDF_Jobs::create_job(
      $user_id,
      $user_id,
      'employee',
      wp_list_pluck( $certs, 'url' ),
      self::build_filename( $user_id )
    );

    set_transient( self::ACTIVE_JOB_TRANSIENT . $user_id, $job_id, self::ACTIVE_JOB_TTL );

    wp_send_json_success( array(
      'job_id' => $job_id,
      'total'  => count( $certs ),
    ) );
  }

  /** Phase 2: fetch ONE certificate into the job's temp folder. */
  public static function ajax_fetch(): void {
    $job = self::guard_ajax( true );

    $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
    if ( $index < 0 || $index >= count( $job['urls'] ) ) {
      wp_send_json_error( array( 'message' => __( 'Invalid certificate index.', 'ghca-ecd' ) ), 400 );
    }

    // Forward the employee's own cookies so LearnDash serves their certificate.
    $cookies = array();
    foreach ( $_COOKIE as $name => $value ) {
      $cookies[] = new \WP_Http_Cookie( array( 'name' => $name, 'value' => $value ) );
    }

    $response = wp_remote_get( $job['urls'][ $index ], array(
      'timeout'   => 25,
      'cookies'   => $cookies,
      'sslverify' => false,
    ) );

    $pdf_content = ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 )
      ? wp_remote_retrieve_body( $response )
      : '';

    $saved = false;
    if ( strpos( $pdf_content, '%PDF-' ) === 0 ) {
      $saved = (bool) file_put_contents( GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $index ), $pdf_content );
    }

    if ( ! $saved ) {
      // ABORT policy (same as the admin packet): never produce a packet with
      // a certificate silently missing.
      self::abort_job( $job['job_id'] );
      wp_send_json_error( array(
        'message' => sprintf(
          /* translators: %d: 1-based certificate number */
          __( 'Certificate %d could not be retrieved. Packet generation was aborted — no partial packet was created. Please try again.', 'ghca-ecd' ),
          $index + 1
        ),
      ) );
    }

    wp_send_json_success( array( 'index' => $index ) );
  }

  /** Phase 3: cover page + certificates -> final packet. */
  public static function ajax_merge(): void {
    $job = self::guard_ajax( true );

    $libs = GHCA_Audit_PDF::load_libs();
    if ( is_wp_error( $libs ) ) {
      self::abort_job( $job['job_id'] );
      wp_send_json_error( array( 'message' => $libs->get_error_message() ) );
    }

    $user_id = get_current_user_id();
    $user    = get_userdata( $user_id );
    $first   = $user ? get_user_meta( $user_id, 'first_name', true ) : '';
    $last    = $user ? get_user_meta( $user_id, 'last_name', true ) : '';
    if ( '' === trim( $first . $last ) && $user ) {
      $first = $user->display_name;
    }

    $pdf = GHCA_Audit_PDF::create_document( array(
      'first_name' => $first,
      'last_name'  => $last,
    ) );
    $pdf->SetTitle( 'Training Certificate Packet - ' . trim( $first . ' ' . $last ) );
    $pdf->AddPage();
    self::render_cover( $pdf, $user_id, trim( $first . ' ' . $last ) );

    $total = count( $job['urls'] );
    for ( $i = 0; $i < $total; $i++ ) {
      if ( ! GHCA_Audit_PDF::append_certificate( $pdf, GHCA_Audit_PDF_Jobs::cert_path( $job['job_id'], $i ) ) ) {
        // ABORT policy (defense in depth): every fetched cert must merge.
        self::abort_job( $job['job_id'] );
        wp_send_json_error( array(
          'message' => sprintf(
            /* translators: %d: 1-based certificate number */
            __( 'Certificate %d was missing or unreadable at merge time. Packet generation was aborted — no partial packet was created.', 'ghca-ecd' ),
            $i + 1
          ),
        ) );
      }
    }

    $pdf->Output( GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] ), 'F' );

    // The build is done; release the one-job-per-user lock.
    delete_transient( self::ACTIVE_JOB_TRANSIENT . $user_id );

    wp_send_json_success( array(
      'download_url' => add_query_arg(
        array(
          'action' => 'ghca_ecd_pkt_download',
          'job_id' => $job['job_id'],
          'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
        ),
        admin_url( 'admin-ajax.php' )
      ),
      'total'        => $total,
    ) );
  }

  /** Phase 4: stream the finished packet (the packets dir is never web-readable). */
  public static function ajax_download(): void {
    $job = self::guard_ajax( true );

    $path = GHCA_Audit_PDF_Jobs::packet_path( $job['job_id'] );
    if ( ! is_readable( $path ) ) {
      wp_die( esc_html__( 'This packet has expired. Please generate it again.', 'ghca-ecd' ) );
    }

    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $job['filename'] ) . '"' );
    header( 'Content-Length: ' . (string) filesize( $path ) );

    if ( ob_get_length() ) {
      ob_clean();
    }

    readfile( $path );
    exit;
  }

  /** Kill the job AND release the requester's build lock. */
  private static function abort_job( string $job_id ): void {
    GHCA_Audit_PDF_Jobs::delete_job( $job_id );
    delete_transient( self::ACTIVE_JOB_TRANSIENT . get_current_user_id() );
  }

  /** Employee cover page: identity, generated date, course/validity table, cycle footer. */
  private static function render_cover( $pdf, int $user_id, string $display_name ): void {
    $snapshot = GHCA_ECD_Data_Provider::get_snapshot();

    $org_name = class_exists( 'GHCA_Dashboard_Branding', false )
      ? GHCA_Dashboard_Branding::get_org_name()
      : (string) get_bloginfo( 'name' );

    if ( '' !== $org_name ) {
      $pdf->SetFont( 'helvetica', 'B', 18 );
      $pdf->Cell( 0, 10, $org_name, 0, 1, 'C' );
      $pdf->Ln( 2 );
    }

    $pdf->SetFont( 'helvetica', 'B', 14 );
    $pdf->Cell( 0, 10, 'Training Certificate Packet', 0, 1, 'C' );

    $pdf->SetFont( 'helvetica', 'B', 12 );
    $pdf->Cell( 0, 10, 'Employee: ' . $display_name, 0, 1, 'C' );

    $pdf->SetFont( 'helvetica', '', 10 );
    $pdf->Cell( 0, 8, 'Generated: ' . wp_date( 'F j, Y' ), 0, 1, 'C' );
    $pdf->Ln( 4 );

    $rows = '';
    foreach ( GHCA_ECD_Data_Provider::get_required_courses( $user_id ) as $course ) {
      if ( empty( $course['completed'] ) ) {
        continue;
      }
      if ( '' === GHCA_ECD_Data_Provider::get_certificate_url( $user_id, (int) $course['id'] ) ) {
        continue;
      }

      $completed_ts = function_exists( 'learndash_user_get_course_completed_date' )
        ? (int) learndash_user_get_course_completed_date( $user_id, (int) $course['id'] )
        : 0;
      $completed_on = $completed_ts ? wp_date( 'F j, Y', $completed_ts ) : '—';
      $valid_until  = '' !== (string) ( $course['expiration_label'] ?? '' )
        ? (string) $course['expiration_label']
        : 'Does not expire';

      $rows .= '<tr>'
        . '<td width="50%">' . esc_html( (string) $course['title'] ) . '</td>'
        . '<td width="25%">' . esc_html( $completed_on ) . '</td>'
        . '<td width="25%">' . esc_html( $valid_until ) . '</td>'
        . '</tr>';
    }

    $html = '<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
      <tr style="background-color: #f1f1f1;">
        <th width="50%"><strong>Course</strong></th>
        <th width="25%"><strong>Completed on</strong></th>
        <th width="25%"><strong>Valid until</strong></th>
      </tr>' . $rows . '</table><br><br>';

    $html .= '<table cellpadding="4" style="width: 100%; border: 1px solid #ddd;">
      <tr>
        <td width="50%"><strong>Compliance status:</strong></td>
        <td width="50%">' . esc_html( (string) ( $snapshot['training_status_label'] ?? '' ) ) . '</td>
      </tr>
      <tr>
        <td><strong>Current cycle due date:</strong></td>
        <td>' . esc_html( (string) ( $snapshot['compliance_due_date_label'] ?? '' ) ) . '</td>
      </tr>
    </table>';

    $pdf->writeHTML( $html, true, false, true, false, '' );
  }

  /** Progress modal for the packet builder (driven by assets/dashboard.js). */
  public static function render_progress_modal(): void {
    if ( ! is_singular() || ! is_user_logged_in() ) {
      return;
    }
    $post = get_post();
    if ( ! $post || ! GHCA_Employee_Compliance_Dashboard::page_uses_dashboard( $post ) ) {
      return;
    }
    ?>
    <div class="ghca-ecd ghca-ecd__pdf-modal" id="ghca-ecd-pdf-modal" hidden aria-hidden="true">
      <div class="ghca-ecd__pdf-modal-backdrop"></div>
      <div class="ghca-ecd__pdf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ghca-ecd-pdf-modal-title">
        <h2 id="ghca-ecd-pdf-modal-title"><?php esc_html_e( 'Building Your Certificate Packet', 'ghca-ecd' ); ?></h2>
        <p class="ghca-ecd__pdf-modal-status" data-ghca-ecd-pdf-label aria-live="polite"><?php esc_html_e( 'Preparing…', 'ghca-ecd' ); ?></p>
        <div class="ghca-ecd__pdf-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-ghca-ecd-pdf-track>
          <div class="ghca-ecd__pdf-progress-fill" data-ghca-ecd-pdf-bar></div>
        </div>
        <div class="ghca-ecd__pdf-modal-footer">
          <button type="button" class="ghca-ecd__btn ghca-ecd__btn--ghost ghca-ecd__btn--compact" data-ghca-ecd-pdf-cancel><?php esc_html_e( 'Cancel', 'ghca-ecd' ); ?></button>
        </div>
      </div>
    </div>
    <?php
  }
}
