<?php
// Run: php tests/test-v163.php
// 1.6.3: the certificate packet merge works with both admin-plugin signatures of append_certificate():
//   admin 1.7.3-beta.14+ (incl. 1.7.3-rc.8): append_certificate( Fpdi $pdf, string $path, int &$total_pages )
//   older admin builds:                     append_certificate( Fpdi $pdf, string $path )
// and create_job() exceptions in init answer JSON. Each scenario runs the real ajax_merge() / ajax_init() in its
// own PHP process (a stub class can't be redefined, and wp_send_json ends the request); the parent checks the JSON.

if ( isset( $argv[1] ) && 'scenario' === $argv[1] ) {
  run_scenario( (string) ( $argv[2] ?? '' ) );
  exit( 0 );
}

$failures = 0;
function check( string $label, bool $ok ): void {
  global $failures;
  echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
  if ( ! $ok ) { ++$failures; }
}
function scenario( string $name ): array {
  $out  = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' scenario ' . escapeshellarg( $name ) . ' 2>&1' );
  $line = '';
  foreach ( preg_split( '/\R/', $out ) as $l ) {
    if ( 0 === strpos( $l, '@@JSON ' ) ) { $line = substr( $l, 7 ); }
  }
  $json = json_decode( $line, true );
  return array( 'raw' => $out, 'json' => is_array( $json ) ? $json : array() );
}

// --- Regression proof: the 1.6.1 call (two arguments) against the rc.8 signature ---------------------------------
$r = scenario( 'old-call-3arg' );
check( '1.6.1 call style against the 3-argument signature throws ArgumentCountError (the Lafia 500)', 'ArgumentCountError' === ( $r['json']['thrown'] ?? '' ) );

// --- 3-argument, by-reference signature (admin 1.7.3-rc.8) -------------------------------------------------------
$r = scenario( 'merge-3arg' );
$j = $r['json'];
check( '3-arg: no ArgumentCountError / fatal', false === stripos( $r['raw'], 'ArgumentCountError' ) && false === stripos( $r['raw'], 'Fatal' ) );
check( '3-arg: merge succeeds with a download link', ! empty( $j['response']['success'] ) && false !== strpos( (string) ( $j['response']['data']['download_url'] ?? '' ), 'ghca_ecd_pkt_download' ) );
check( '3-arg: reflection detects the page-count parameter', true === ( $j['stats']['takes_count'] ?? null ) );
check( '3-arg: every call received the running count by reference (1 after the cover, then 2, 4)', array( 1, 2, 4 ) === ( $j['stats']['received'] ?? null ) );
check( '3-arg: final page count = cover 1 + certificates 1 + 2 + 1 = 5', 5 === ( $j['response']['data']['pages'] ?? null ) && 5 === ( $j['stats']['document_pages'] ?? null ) );
check( '3-arg: all 3 certificates merged, packet written', 3 === ( $j['response']['data']['total'] ?? null ) && true === ( $j['stats']['written'] ?? null ) );

// --- 2-argument signature (older admin builds) ---------------------------------------------------------------------
$r = scenario( 'merge-2arg' );
$j = $r['json'];
check( '2-arg: no ArgumentCountError / fatal', false === stripos( $r['raw'], 'ArgumentCountError' ) && false === stripos( $r['raw'], 'Fatal' ) );
check( '2-arg: merge succeeds with a download link', ! empty( $j['response']['success'] ) && false !== strpos( (string) ( $j['response']['data']['download_url'] ?? '' ), 'ghca_ecd_pkt_download' ) );
check( '2-arg: reflection sees no page-count parameter', false === ( $j['stats']['takes_count'] ?? null ) );
check( '2-arg: called with exactly two arguments each time', array( 2, 2, 2 ) === ( $j['stats']['arg_counts'] ?? null ) );
check( '2-arg: page count read from the document = 5', 5 === ( $j['response']['data']['pages'] ?? null ) );

// --- A certificate the admin plugin refuses: clean abort, no packet --------------------------------------------------
$r = scenario( 'merge-3arg-reject' );
$j = $r['json'];
check( 'refused certificate: JSON error naming it, job deleted, no packet written', empty( $j['response']['success'] ) && false !== strpos( (string) ( $j['response']['data']['message'] ?? '' ), 'Certificate 2' ) && true === ( $j['stats']['deleted'] ?? null ) && false === ( $j['stats']['written'] ?? null ) );

// --- Any \Error during merge (1.6.2 hardening kept): JSON, not a bare 500 ---------------------------------------------
$r = scenario( 'merge-error' );
$j = $r['json'];
check( '\Error during merge: JSON error with step "merge", job deleted', empty( $j['response']['success'] ) && 'merge' === ( $j['response']['data']['step'] ?? '' ) && true === ( $j['stats']['deleted'] ?? null ) );
check( '\Error during merge: [ghca-ecd] line written to the error log', false !== strpos( $r['raw'], '[ghca-ecd] Certificate packet merge failed' ) );

// --- create_job() exceptions in init ----------------------------------------------------------------------------------
$r = scenario( 'init-logic' );
$j = $r['json'];
check( 'init LogicException: JSON error, "already being built" message, step init', empty( $j['response']['success'] ) && false !== strpos( (string) ( $j['response']['data']['message'] ?? '' ), 'already being built' ) && 'init' === ( $j['response']['data']['step'] ?? '' ) );
check( 'init LogicException: [ghca-ecd] error-log line', false !== strpos( $r['raw'], '[ghca-ecd] Certificate packet init failed' ) && false !== strpos( $r['raw'], 'LogicException' ) );
$r = scenario( 'init-runtime' );
$j = $r['json'];
check( 'init RuntimeException: JSON error, generic message', empty( $j['response']['success'] ) && false !== strpos( (string) ( $j['response']['data']['message'] ?? '' ), 'couldn’t be started' ) );
check( 'init RuntimeException: [ghca-ecd] error-log line', false !== strpos( $r['raw'], 'RuntimeException' ) );
$r = scenario( 'init-ok' );
check( 'init normal: job created, total 3', ! empty( $r['json']['response']['success'] ) && 3 === ( $r['json']['response']['data']['total'] ?? null ) );

echo "\n" . ( $failures ? $failures . ' failure(s).' : 'All tests passed.' ) . "\n";
exit( $failures ? 1 : 0 );

/* ======================================================================================================================
 * One scenario, in its own process.
 * ==================================================================================================================== */
function run_scenario( string $name ): void {
  $GLOBALS['ghca_scenario'] = $name;
  $GLOBALS['ghca_stats']    = array( 'received' => array(), 'arg_counts' => array(), 'written' => false, 'deleted' => false );
  ini_set( 'error_log', '' ); // error_log() goes to stderr, captured by the parent.
  ini_set( 'log_errors', '1' );

  define_wp_stubs();
  // One of two stand-ins for the admin plugin's GHCA_Audit_PDF, declared conditionally (one per process).
  if ( 0 === strpos( $name, 'merge-2arg' ) ) {
    /** Older admin builds: append_certificate( Fpdi $pdf, string $path ). */
    final class GHCA_Audit_PDF {
      use GHCA_Test_PDF_Base;
      public static function append_certificate( $pdf, string $path ): bool {
        $GLOBALS['ghca_stats']['arg_counts'][] = func_num_args();
        return GHCA_Test_PDF_Base_Impl::add( $pdf, $path );
      }
    }
  } else {
    /** Admin 1.7.3-beta.14+ / rc.8: append_certificate( Fpdi $pdf, string $path, int &$total_pages ), no default. */
    final class GHCA_Audit_PDF {
      use GHCA_Test_PDF_Base;
      public static function append_certificate( $pdf, string $path, int &$total_pages ): bool {
        $GLOBALS['ghca_stats']['received'][] = $total_pages;
        if ( 'merge-3arg-reject' === $GLOBALS['ghca_scenario'] && 'cert-1' === $path ) {
          return false;
        }
        if ( 'merge-error' === $GLOBALS['ghca_scenario'] ) {
          throw new TypeError( 'simulated FPDI failure' );
        }
        $before       = $pdf->getPage();
        $ok           = GHCA_Test_PDF_Base_Impl::add( $pdf, $path );
        $total_pages += $pdf->getPage() - $before;
        return $ok;
      }
    }
  }

  require_once __DIR__ . '/bootstrap.php';

  $set = static function ( string $prop, $value ): void {
    $r = new ReflectionProperty( 'GHCA_ECD_Data_Provider', $prop );
    $r->setAccessible( true );
    $r->setValue( null, $value );
  };
  $courses = array(
    array( 'id' => 11, 'title' => 'Fatal Five', 'completed' => true ),
    array( 'id' => 12, 'title' => 'Individual Rights', 'completed' => true ),
    array( 'id' => 13, 'title' => 'Incident Reporting', 'completed' => true ),
  );
  $set( 'snapshot', array( 'training_status_label' => 'In Progress', 'compliance_due_date_label' => 'January 9, 2027' ) );
  $set( 'required_courses', array( 7 => $courses ) );

  if ( 'old-call-3arg' === $name ) {
    $pdf = new GHCA_Test_Pdf();
    try {
      GHCA_Audit_PDF::append_certificate( $pdf, 'cert-0' ); // The 1.6.1 call.
      emit( array( 'thrown' => '' ) );
    } catch ( \Throwable $e ) {
      emit( array( 'thrown' => get_class( $e ) ) );
    }
    return;
  }

  if ( 0 === strpos( $name, 'init-' ) ) {
    GHCA_ECD_Cert_Packet::ajax_init();
    return;
  }

  $_REQUEST['job_id'] = str_repeat( 'ab', 16 );
  GHCA_ECD_Cert_Packet::ajax_merge();
}

function emit( array $payload ): void {
  echo "\n@@JSON " . json_encode( $payload ) . "\n";
}

final class GHCA_Test_Json_Sent extends \Exception {}

function define_wp_stubs(): void {
  if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
  function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
  function esc_html__( $s, $d = null ) { return esc_html( $s ); }
  function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
  function check_ajax_referer( $a, $k ) { return 1; }
  function is_user_logged_in() { return true; }
  function get_current_user_id() { return 7; }
  function sanitize_text_field( $s ) { return trim( (string) $s ); }
  function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
  function wp_unslash( $v ) { return $v; }
  function is_wp_error( $v ) { return false; }
  function get_userdata( $id ) { return (object) array( 'ID' => $id, 'display_name' => 'Ruth', 'user_login' => 'ruth' ); }
  function get_user_meta( $id, $k = '', $single = false ) { return array( 'first_name' => 'Ruth', 'last_name' => 'Mensah' )[ $k ] ?? ''; }
  function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? 1794700800 ); }
  function get_bloginfo( $k ) { return 'Lafia Health'; }
  function get_transient( $k ) { return false; }
  function set_transient( $k, $v, $t = 0 ) { return true; }
  function delete_transient( $k ) { return true; }
  function wp_list_pluck( $list, $field ) { return array_column( $list, $field ); }
  function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
  function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
  function wp_create_nonce( $a ) { return 'n'; }
  function wp_raise_memory_limit( $c = 'admin' ) { return '256M'; }
  function learndash_get_course_certificate_link( $course_id, $user_id ) { return 'https://example.test/cert/' . $course_id; }
  function learndash_user_get_enrolled_courses( $user_id ) { return array(); } // Presence only: courses are set below.
  function wp_send_json_success( $data = null, $status = null ) { ghca_test_send( true, $data ); }
  function wp_send_json_error( $data = null, $status = null ) { ghca_test_send( false, $data ); }
  function ghca_test_send( bool $ok, $data ): void {
    // Like wp_send_json(): the response ends the request.
    $stats                   = $GLOBALS['ghca_stats'];
    $stats['takes_count']    = class_exists( 'GHCA_ECD_Cert_Packet', false ) && class_exists( 'GHCA_Audit_PDF', false ) ? GHCA_ECD_Cert_Packet::append_takes_page_count() : null;
    $stats['document_pages'] = isset( $GLOBALS['ghca_pdf'] ) ? $GLOBALS['ghca_pdf']->getPage() : null;
    emit( array( 'response' => array( 'success' => $ok, 'data' => $data ), 'stats' => $stats ) );
    exit( 0 );
  }
}

/** Stand-in for the admin job store (GHCA_Audit_PDF_Jobs), current public API. */
final class GHCA_Audit_PDF_Jobs {
  public static function is_valid_job_id( string $id ): bool { return (bool) preg_match( '/^[a-f0-9]{32}$/', $id ); }
  public static function cert_path( string $job_id, int $index ): string { return 'cert-' . $index; }
  public static function packet_path( string $job_id ): string { return 'packet.pdf'; }
  public static function gc(): void {}
  public static function delete_job( string $job_id ): bool { $GLOBALS['ghca_stats']['deleted'] = true; return true; }
  public static function get_job( string $job_id, int $owner_id ) {
    return array( 'owner' => 7, 'user_id' => 7, 'tracker' => 'employee', 'urls' => array( 'u0', 'u1', 'u2' ), 'filename' => 'p.pdf' );
  }
  public static function create_job( int $owner_id, int $user_id, string $tracker, array $urls, string $filename, array $course_ids = array(), array $brokerable = array(), array $course_keys = array(), array $evidence_refs = array(), array $report_snapshot = array() ): string {
    if ( 'init-logic' === $GLOBALS['ghca_scenario'] ) { throw new LogicException( 'A packet job is already starting. Please try again.' ); }
    if ( 'init-runtime' === $GLOBALS['ghca_scenario'] ) { throw new RuntimeException( 'Could not create the packet job directory.' ); }
    return str_repeat( 'cd', 16 );
  }
}

/** Minimal TCPDF/FPDI document: counts pages. */
final class GHCA_Test_Pdf {
  private $pages = 0;
  public function AddPage( ...$a ) { ++$this->pages; }
  public function getPage() { return $this->pages; }
  public function SetTitle( $t ) {}
  public function SetFont( ...$a ) {}
  public function Cell( ...$a ) {}
  public function Ln( ...$a ) {}
  public function writeHTML( ...$a ) {}
  public function Output( $path, $dest ) { $GLOBALS['ghca_stats']['written'] = true; }
}

/** Shared parts of both GHCA_Audit_PDF stubs. */
trait GHCA_Test_PDF_Base {
  public static function load_libs() { return true; }
  public static function create_document( array $audit_data ) { return $GLOBALS['ghca_pdf'] = new GHCA_Test_Pdf(); }
}
final class GHCA_Test_PDF_Base_Impl {
  /** Certificate pages: cert-0 has 1 page, cert-1 has 2, cert-2 has 1. */
  public static function add( GHCA_Test_Pdf $pdf, string $path ): bool {
    $pages = array( 'cert-0' => 1, 'cert-1' => 2, 'cert-2' => 1 )[ $path ] ?? 0;
    if ( ! $pages ) { return false; }
    for ( $i = 0; $i < $pages; $i++ ) { $pdf->AddPage(); }
    return true;
  }
}
