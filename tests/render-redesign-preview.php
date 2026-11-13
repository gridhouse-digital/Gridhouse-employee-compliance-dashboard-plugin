<?php
// Visual preview of the redesigned employee screens with canned data — not WordPress, no database, no email.
// Serve the plugin folder:  php -S 127.0.0.1:8765 -t .
// Open:  /tests/render-redesign-preview.php?screen=home|certificates|legacy
//   &theme=akansoh|gridhouse|purple   &state=newhire|annual|empty
//   &ann=0|1|3            announcements visible to this user
//   &bul=0|1|7|unavail|cached   ODP bulletin feed
//   &block=none|all       packet blocking reasons (admin calculator stand-in)
//   &pol=none|notstarted|progress|done   company policies course
//   &admin=0|1            viewer is an admin looking at the employee page
//   &photo=0|1            employee has an uploaded BuddyBoss photo
//   &pop=1|0              BuddyBoss REST API present (photo pop-up) or not (link to the change-photo screen)
//   &upl=1|0              BuddyBoss "members can upload profile photos" setting
//   &rest=ok|denied|fail|noroute   how the stand-in REST endpoint answers the pop-up
if ( 'cli' !== PHP_SAPI && ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) {
  http_response_code( 403 );
  exit;
}

/*
 * Stand-in for BuddyBoss's member avatar endpoint (/buddyboss/v1/members/{id}/avatar). It stores nothing: it
 * checks what the pop-up sends the way BuddyBoss would (REST nonce, a `file` upload, an image at least the full
 * avatar size) and answers in BuddyBoss's shapes. `received` reports what arrived, for the tests.
 */
if ( isset( $_GET['avatar_rest'] ) ) {
  header( 'Content-Type: application/json; charset=utf-8' );
  $mode   = (string) $_GET['avatar_rest'];
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $fail   = static function ( int $status, string $code, string $message ): void {
    http_response_code( $status );
    echo json_encode( array( 'code' => $code, 'message' => $message, 'data' => array( 'status' => $status ) ) );
    exit;
  };
  $person = static function ( string $fill ): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode( "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='" . $fill . "'/><circle cx='32' cy='25' r='12' fill='#fff'/><path d='M10 64c3-15 13-22 22-22s19 7 22 22z' fill='#fff'/></svg>" );
  };
  if ( 'noroute' === $mode ) {
    $fail( 404, 'rest_no_route', 'No route was found matching the URL and request method.' );
  }
  if ( 'denied' === $mode || 'nonce123' !== ( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) ) {
    $fail( 403, 'rest_cookie_invalid_nonce', 'Cookie check failed' );
  }
  if ( 'POST' === $method ) {
    if ( 'fail' === $mode ) {
      $fail( 500, 'bp_rest_attachments_member_avatar_upload_error', 'Upload failed! Error was: The uploaded file exceeds the maximum upload size.' );
    }
    $file = $_FILES['file'] ?? null;
    if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
      $fail( 500, 'bp_rest_attachments_member_avatar_no_image_file', 'Sorry, you need an image file to upload.' );
    }
    $info = getimagesize( $file['tmp_name'] );
    if ( ! $info || $info[0] < 300 || $info[1] < 300 ) {
      $fail( 400, 'bp_rest_attachments_member_avatar_error', 'You have selected an image that is smaller than recommended. For best results, upload a picture larger than 300 x 300 pixels.' );
    }
    $url = 'data:' . $info['mime'] . ';base64,' . base64_encode( (string) file_get_contents( $file['tmp_name'] ) );
    echo json_encode( array( 'full' => $url, 'thumb' => $url, 'received' => array( 'width' => $info[0], 'height' => $info[1], 'mime' => $info['mime'], 'bytes' => (int) $file['size'], 'name' => $file['name'], 'action' => $_POST['action'] ?? '' ) ) );
    exit;
  }
  if ( 'DELETE' === $method ) {
    echo json_encode( array( 'deleted' => true, 'previous' => array( 'full' => '', 'thumb' => '' ) ) );
    exit;
  }
  echo json_encode( array( 'full' => $person( '#9aa3af' ), 'thumb' => $person( '#9aa3af' ) ) );
  exit;
}

// A small landscape sample certificate for the viewer (served at ?pdf=1).
if ( isset( $_GET['pdf'] ) ) {
  $stream = "BT /F1 28 Tf 200 400 Td (Certificate of Completion) Tj ET BT /F1 18 Tf 300 340 Td (Adebola Oyinlola) Tj ET BT /F1 14 Tf 290 300 Td (A New Hire Orientation) Tj ET";
  $objs   = array(
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  );
  $pdf = "%PDF-1.4\n";
  $off = array();
  foreach ( $objs as $i => $o ) {
    $off[] = strlen( $pdf );
    $pdf  .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
  }
  $xref = strlen( $pdf );
  $pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
  foreach ( $off as $o ) { $pdf .= sprintf( "%010d 00000 n \n", $o ); }
  $pdf .= "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
  header( 'Content-Type: application/pdf' );
  echo $pdf;
  exit;
}

$pick   = static function ( string $k, array $allowed, string $default ): string { return in_array( $_GET[ $k ] ?? '', $allowed, true ) ? (string) $_GET[ $k ] : $default; };
$screen = $pick( 'screen', array( 'home', 'certificates', 'legacy' ), 'home' );
$theme  = $pick( 'theme', array( 'akansoh', 'gridhouse', 'purple' ), 'akansoh' );
$state  = $pick( 'state', array( 'newhire', 'annual', 'empty' ), 'newhire' );
$ann    = (int) $pick( 'ann', array( '0', '1', '3' ), '3' );
$bul    = $pick( 'bul', array( '0', '1', '7', 'unavail', 'cached' ), '7' );
$block  = $pick( 'block', array( 'none', 'all' ), 'all' );
$pol    = $pick( 'pol', array( 'none', 'notstarted', 'progress', 'done' ), 'progress' );
$admin  = '1' === ( $_GET['admin'] ?? '0' );
$photo  = '1' === ( $_GET['photo'] ?? '0' );
$pop    = '0' !== ( $_GET['pop'] ?? '1' );
$upl    = '0' !== ( $_GET['upl'] ?? '1' );
$rest   = $pick( 'rest', array( 'ok', 'denied', 'fail', 'noroute' ), 'ok' );
$NOW    = 1794700800; // Nov 20, 2026 (fixed so the preview is reproducible)

// ---- WordPress stand-ins --------------------------------------------------------------------------------------
function __( $s, $d = null ) { return $s; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function wp_date( $f, $ts = null ) { global $NOW; return gmdate( $f, null === $ts ? $NOW : (int) $ts ); }
function home_url( $p = '' ) { return '/tests/site' . $p; }
function admin_url( $p = '' ) { return '/tests/wp-admin/' . $p; }
function get_permalink( $p = null ) { return '/tests/render-redesign-preview.php'; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_create_nonce( $a ) { return 'nonce123'; }
function wp_nonce_field( $a, $n, $r = false, $e = false ) { return '<input type="hidden" name="' . $n . '" value="nonce123">'; }
function get_page_by_path( $p ) { return null; }
function get_current_user_id() { return 7; }
function is_user_logged_in() { return true; }
function current_user_can( $cap ) { global $admin; return $admin; }
function get_user_meta( ...$a ) { return ''; }
function get_option( $k, $d = false ) {
  global $theme;
  if ( 'ghca_ecd_ui_theme' === $k ) { return $theme; }
  if ( 'ghca_ecd_ui_redesign' === $k ) { return 1; }
  if ( 'ghca_acd_audit_mapping' === $k ) { return array( 6 => array( 'odp_category' => 'individual_rights', 'credit_hours' => 2 ) ); }
  return $d;
}
function apply_filters( $tag, $value, ...$a ) { return $value; }
function get_userdata( $id ) {
  global $admin;
  return (object) array( 'ID' => $id, 'user_login' => 'manueltech', 'display_name' => 'manueltech', 'roles' => $admin ? array( 'administrator', 'bbp_keymaster' ) : array( 'bbp_keymaster', 'subscriber', 'ghca_custom_dsp' ) );
}
function wp_roles() { return (object) array( 'roles' => array( 'administrator' => array( 'name' => 'Administrator' ), 'bbp_keymaster' => array( 'name' => 'Keymaster' ), 'subscriber' => array( 'name' => 'Subscriber' ), 'ghca_custom_dsp' => array( 'name' => 'Direct Support Professional (DSP)' ) ) ); }
function learndash_get_setting( $id, $k ) { return 99; }
function learndash_user_get_enrolled_courses( $user_id ) { return array(); } // Presence only: the canned list is set on the memo below.
function learndash_get_course_certificate_link( $course_id, $user_id ) { return in_array( (int) $course_id, array( 1, 2 ), true ) ? '/tests/render-redesign-preview.php?pdf=' . $course_id : ''; }
function get_posts( $args ) {
  global $ann;
  $all = array(
    (object) array( 'ID' => 1, 'post_title' => 'New course for DSPs', 'post_content' => 'Trauma-informed care is now part of the annual cycle.', 'post_date' => '2026-10-27' ),
    (object) array( 'ID' => 2, 'post_title' => 'Annual audit in September', 'post_content' => 'Please finish annual training before the audit window.', 'post_date' => '2026-10-12' ),
    (object) array( 'ID' => 3, 'post_title' => 'Holiday office hours', 'post_content' => 'The office closes early on Thursday.', 'post_date' => '2026-10-02' ),
  );
  return array_slice( $all, 0, $ann );
}
function get_post_meta( $id, $k, $single = false ) {
  $meta = array( 1 => array( 'ghca_announce_type' => 'alert', 'ghca_announce_url' => '/tests/site/my-courses/' ), 2 => array( 'ghca_announce_type' => 'reminder' ), 3 => array( 'ghca_announce_type' => 'update' ) );
  return $meta[ $id ][ $k ] ?? '';
}
function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : 'Company Policies'; }
function get_the_date( $f, $p ) { return gmdate( $f, strtotime( $p->post_date ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_trim_words( $s, $n = 55, $more = '…' ) { $w = preg_split( '/\s+/', trim( $s ) ); return count( $w ) > $n ? implode( ' ', array_slice( $w, 0, $n ) ) . $more : $s; }

// BuddyBoss / BuddyPress stand-ins.
function bp_get_user_has_avatar( $id ) { global $photo; return $photo; }
function bp_core_fetch_avatar( $args ) { return 'data:image/svg+xml;utf8,' . rawurlencode( "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='#c9a27a'/><circle cx='32' cy='25' r='12' fill='#6b4a2f'/><path d='M10 64c3-15 13-22 22-22s19 7 22 22z' fill='#6b4a2f'/></svg>" ); }
function bp_members_get_user_url( $id ) { return '/tests/site/members/adebola/'; }
function bp_core_delete_existing_avatar( $args ) { return true; }
function bp_disable_avatar_uploads() { global $upl; return ! $upl; }
function bp_core_avatar_full_width() { return 300; }
function bp_core_avatar_full_height() { return 300; }
function bp_core_avatar_original_max_width() { return 450; }
function bp_rest_namespace() { return 'buddyboss'; }
function bp_rest_version() { return 'v1'; }
function rest_url( $path = '' ) { global $rest; return '/tests/render-redesign-preview.php?avatar_rest=' . $rest . '&path=' . rawurlencode( $path ); }
if ( $pop ) {
  final class BP_REST_Attachments_Member_Avatar_Endpoint {} // Presence only: the real class registers the route.
}

/** Stand-in for the admin plugin's packet calculator (read-only in the real plugin). */
final class GHCA_Audit_Calculator {
  public static function calculate_employee_audit_data( $employee, $tracker, $mappings, $period = array() ) {
    global $block;
    if ( 'none' === $block ) {
      return array( 'total_annual_hrs' => 26, 'category_statuses' => array(), 'new_hire_program' => array( 'active' => true, 'complete' => true ), 'completion_date' => '11/01/2026' );
    }
    return array(
      'total_annual_hrs'  => 14.5,
      'category_statuses' => array( 'individual_rights' => 'Missing training', 'person_centered' => 'Needs review' ),
      'new_hire_program'  => array( 'active' => 'orientation' === $tracker, 'complete' => false, 'outstanding' => array( array( 'course_id' => 2, 'title' => 'FATAL FIVE' ) ) ),
      'completion_date'   => '',
    );
  }
}

/**
 * Stand-in for the admin plugin's GHCA_ODP_Bulletins::dashboard_fragment(): same ticker, drawer and payload
 * structure as GHCA_ODP_UI (admin PR #19). Records the context it was asked for.
 */
final class GHCA_ODP_Bulletins {
  public static $context = '';
  public static function snapshot( $c ) { return array( 'bulletins' => array() ); }
  public static function item_url( $id ) { return '/tests/site/odp-bulletins/' . $id . '/'; }
  public static function library_url() { return '/tests/site/odp-bulletins/'; }
  public static function dashboard_fragment( $context ) {
    global $bul;
    self::$context = $context;
    $i      = static function ( $n, $s = '' ) { return '<span class="gh-i' . ( $s ? ' gh-i--' . $s : '' ) . ' i-' . $n . '" aria-hidden="true"></span>'; };
    $titles = array( 'Annual training hours reminder for the current cycle', 'Incident reporting guidance update', 'Provider qualification documentation checklist', 'Visit verification reminder for in-home services', 'Webinar recording now available', 'Medication administration refresher dates', 'Updated fire safety drill requirements' );
    $n      = 'unavail' === $bul ? 0 : ( 'cached' === $bul ? 3 : (int) $bul );
    $items  = array();
    for ( $k = 0; $k < $n; $k++ ) {
      $items[] = array( 'id' => 'b' . ( $k + 1 ), 'headline' => 'Sample bulletin · ' . $titles[ $k ], 'date_short' => gmdate( 'M j', 1794268800 - $k * 5 * 86400 ), 'date_long' => gmdate( 'l, F j, Y', 1794268800 - $k * 5 * 86400 ), 'published_at' => gmdate( 'c', 1794268800 - $k * 5 * 86400 ), 'paragraphs' => array( 'Plain-language summary of the bulletin for employees.', 'Check your course list for anything still outstanding.' ), 'source_url' => 0 === $k ? 'https://www.dhs.pa.gov/' : null, 'source_host' => 0 === $k ? 'www.dhs.pa.gov' : '', 'url' => '/tests/site/odp-bulletins/b' . ( $k + 1 ) . '/' );
    }
    $t = '<section class="gh-ticker" data-gh-ticker aria-label="ODP Bulletin"><span class="gh-ticker-lbl">' . $i( 'megaphone' ) . 'ODP Bulletin</span>';
    if ( $items ) {
      $t .= '<div class="gh-ticker-vp"><ul class="gh-ticker-track" style="list-style:none;margin:0">';
      foreach ( $items as $b ) { $t .= '<li class="gh-ticker-item"><time>' . $b['date_short'] . '</time><a href="' . $b['url'] . '" data-gh-open-bulletin="' . $b['id'] . '">' . $b['headline'] . '</a></li>'; }
      $t .= '</ul></div>';
    } else {
      $t .= '<div class="gh-ticker-msg" role="status">' . ( 'unavail' === $bul ? 'Bulletins are unavailable right now. Try again later.' : 'No current bulletins.' ) . '</div>';
    }
    $t .= '<div class="gh-ticker-ctl">';
    if ( 'cached' === $bul ) { $t .= '<span class="gh-ticker-state" role="status">' . $i( 'clock', 'sm' ) . 'Saved copy<span class="gh-sr"> Showing a saved copy; the feed could not be reached.</span></span>'; }
    if ( count( $items ) > 1 ) { $t .= '<button type="button" class="gh-btn gh-btn--ghost gh-btn--icon gh-btn--sm" data-gh-ticker-pause aria-pressed="false" aria-label="Pause bulletin ticker" data-label-pause="Pause bulletin ticker" data-label-play="Play bulletin ticker">' . $i( 'pause' ) . '</button>'; }
    $t .= '<a href="/tests/site/odp-bulletins/">All bulletins</a></div></section>';
    $d  = '<div class="gh-odp-layer" id="gh-odp-drawer" hidden><div class="gh-scrim" data-gh-drawer-close aria-hidden="true"></div><aside class="gh-drawer gh-bul" role="dialog" aria-modal="true" aria-labelledby="gh-odp-drawer-title" tabindex="-1">';
    $d .= '<div class="gh-bul-hd"><span class="gh-bul-chip">' . $i( 'megaphone' ) . 'ODP Bulletin</span><span class="gh-bul-pos" data-gh-drawer-pos></span><button type="button" class="gh-bul-x" data-gh-drawer-close data-gh-drawer-x aria-label="Close bulletin">' . $i( 'x' ) . '</button></div>';
    $d .= '<div class="gh-bul-bd"><span class="gh-bul-date">' . $i( 'calendar' ) . '<time data-gh-drawer-meta></time></span><h2 class="gh-bul-h" id="gh-odp-drawer-title" data-gh-drawer-title></h2><div class="gh-bul-sum" data-gh-drawer-body></div>';
    $d .= '<div class="gh-bul-src" data-gh-drawer-source-wrap hidden><span class="gh-bul-eyebrow">Source</span><div class="gh-bul-srcrow"><span class="gh-bul-srcico">' . $i( 'file' ) . '</span><span class="gh-bul-srcmeta"><strong>Official source</strong><span data-gh-drawer-source-host></span></span><a class="gh-btn gh-btn--secondary gh-btn--sm" data-gh-drawer-source href="#" target="_blank" rel="noopener noreferrer">Read source' . $i( 'external' ) . '</a></div></div>';
    $d .= '<p class="gh-bul-note">' . $i( 'info' ) . 'This is a plain-language summary from Gridhouse, not an official ODP publication.</p></div>';
    $d .= '<div class="gh-bul-ft"><a class="gh-btn gh-btn--secondary gh-btn--sm gh-bul-open" data-gh-drawer-full href="/tests/site/odp-bulletins/">' . $i( 'article' ) . 'Open full page</a><a class="gh-bul-all" href="/tests/site/odp-bulletins/">All bulletins' . $i( 'arrow-right' ) . '</a><div class="gh-bul-step"><button type="button" data-gh-drawer-step="-1" aria-label="Newer bulletin">' . $i( 'chev-left' ) . '</button><button type="button" data-gh-drawer-step="1" aria-label="Older bulletin">' . $i( 'chev-right' ) . '</button></div></div></aside></div>';
    $payload = array( 'bulletins' => array_column( $items, null, 'id' ), 'order' => array_column( $items, 'id' ) );
    return array( 'ticker' => $t, 'drawer' => $d, 'payload' => '<script type="application/json" id="gh-odp-data">' . json_encode( $payload ) . '</script>' );
  }
}

require_once __DIR__ . '/bootstrap.php';

// ---- Canned employee data --------------------------------------------------------------------------------------
$titles = array( 'A NEW HIRE ORIENTATION', 'FATAL FIVE', 'DOCUMENTATION ESSENTIALS FOR HEALTHCARE AND IDD SERVICES', 'RECOGNIZING AND REPORTING INCIDENTS', 'PREVENTION, DETECTION & REPORTING OF ABUSE', 'INDIVIDUAL RIGHTS', 'PERSON-CENTERED PRACTICES & COMMUNITY INTEGRATION', 'TRAUMA-INFORMED CARE' );
$courses = array();
foreach ( $titles as $i => $t ) {
  $done = 'empty' !== $state && ( 0 === $i || ( 'annual' === $state && $i < 4 ) );
  // Course 2: overdue (own date passed). Course 3: due soon (own date). Course 6: locked by prerequisites. Others: cycle date.
  $own  = array( 1 => $NOW - 3 * 86400, 2 => $NOW + 12 * 86400 )[ $i ] ?? 0;
  $courses[] = array(
    'id' => $i + 1, 'title' => $t, 'completed' => $done, 'completed_ts' => $done ? $NOW - ( 20 + $i ) * 86400 : 0,
    'status' => $done ? 'completed' : ( 1 === $i ? 'in_progress' : 'not_started' ),
    'compliance_state' => $done ? ( 3 === $i ? 'expired' : 'current' ) : 'not_started', 'expiration_label' => $done && 3 === $i ? 'Nov 1, 2026' : '',
    'expiration_ts' => $done ? 0 : $own, 'locked' => ! $done && 5 === $i,
    'progress' => $done ? 100 : ( 1 === $i ? 15 : 0 ), 'progress_label' => $done ? '100%' : ( 1 === $i ? '15%' : '0%' ),
    'description' => 'Five health conditions that often lead to preventable harm for people with intellectual and developmental disabilities — the early warning signs, and when to escalate.',
    'url' => '/tests/site/courses/' . ( $i + 1 ),
  );
}
$done_count = count( array_filter( array_column( $courses, 'completed' ) ) );
$next       = null;
foreach ( $courses as $i => $c ) {
  if ( ! $c['completed'] ) { $next = $c + array( 'module_index' => $i + 1 ); break; }
}
$newhire  = 'newhire' === $state;
$policies = array( 'course_id' => 'none' === $pol ? 0 : 50, 'source' => 'setting', 'url' => '/tests/site/courses/policies', 'completed' => 'done' === $pol, 'progress' => array( 'none' => 0, 'notstarted' => 0, 'progress' => 40, 'done' => 100 )[ $pol ], 'title' => 'Company Policies' );
$snapshot = array(
  'user_id' => 7, 'first_name' => 'Adebola', 'last_name' => 'Oyinlola', 'display_name' => 'manueltech', 'courses' => 'empty' === $state ? array() : $courses,
  'total_courses' => 'empty' === $state ? 0 : count( $courses ), 'completed_count' => 'empty' === $state ? 0 : $done_count, 'all_complete' => false,
  'training_status' => $newhire ? 'in_progress' : 'expiring_soon', 'training_status_label' => 'In Progress',
  'start_date_label' => $newhire ? 'October 31, 2026' : 'January 9, 2025', 'compliance_due_date_label' => $newhire ? 'November 30, 2026' : 'January 9, 2027',
  'due_ts' => $newhire ? $NOW + 10 * 86400 : $NOW + 50 * 86400,
  'certificate_url' => '/tests/render-redesign-preview.php?pdf=1', 'packet_certificate_count' => 'empty' === $state ? 0 : ( $newhire ? 1 : 2 ),
  'policies' => $policies,
  'next_course' => ( 'empty' === $state || ! $next ) ? null : array(
    'id' => $next['id'], 'title' => $next['title'], 'description' => $next['description'], 'progress' => $next['progress'], 'progress_label' => $next['progress_label'],
    'module_index' => $next['module_index'], 'module_total' => count( $courses ), 'url' => $next['url'],
  ),
  'continue_url' => '/tests/site/courses/2',
  'compliance_notification' => $newhire ? array( 'show' => false ) : array( 'show' => true, 'level' => 'warning', 'message' => 'Complete your required annual training by January 9, 2027 (50 days remaining).', 'days_remaining' => 50 ),
  'annual_training_complete' => false,
  'new_hire' => $newhire ? array( 'active' => true, 'complete' => false, 'at_risk' => true, 'days_remaining' => 10, 'deadline_ts' => $NOW + 10 * 86400, 'enrollment_ts' => $NOW - 20 * 86400, 'deadline_days' => 30 ) : array(),
  'new_hire_flagged' => false,
);
// ?ty=on|default|none: what the snapshot carries when the admin training-year switch is on (1.6.4).
$ty = (string) ( $_GET['ty'] ?? '' );
if ( in_array( $ty, array( 'on', 'default', 'none' ), true ) ) {
  $snapshot['training_year'] = array(
    'window_label'        => 'Jan 10, 2026 – Jan 9, 2027',
    'window_missing'      => '',
    'hours'               => 'none' === $ty ? 0 : ( 'default' === $ty ? 24 : 12 ),
    'requirement_label'   => 'Direct Support Professional',
    'requirement_set'     => 'default' !== $ty,
    'requirement_source'  => 'default' === $ty ? 'default' : 'role_map',
    'requirement_not_set' => 'default' === $ty,
  );
}

$set = static function ( string $prop, $value ): void {
  $r = new ReflectionProperty( 'GHCA_ECD_Data_Provider', $prop );
  $r->setAccessible( true );
  $r->setValue( null, $value );
};
$set( 'snapshot', $snapshot );
$set( 'required_courses', array( 7 => $snapshot['courses'] ) );

if ( 'certificates' === $screen ) {
  $html = GHCA_ECD_Redesign::render_certificates();
} elseif ( 'legacy' === $screen ) {
  // A page built from several legacy section shortcodes: the new dashboard must appear once, plus one admin note.
  $html = GHCA_ECD_Shortcodes::render_welcome() . GHCA_ECD_Shortcodes::render_status_cards() . GHCA_ECD_Shortcodes::render_trio() . GHCA_ECD_Shortcodes::render_full_dashboard() . GHCA_ECD_Shortcodes::render_quick_links();
} else {
  $html = GHCA_ECD_Shortcodes::render_full_dashboard();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Employee redesign preview · <?php echo esc_html( $screen . ' · ' . $state ); ?></title>
<link rel="stylesheet" href="../assets/ecd-redesign.css">
<style>
  /* Stand-in theme: a narrow content column, a sticky site header, and the hostile rules themes ship. */
  body { margin: 0; font: 16px/1.6 Georgia, serif; color: #333; background: #fff; }
  .site-header { background: #222; color: #fff; padding: 18px 24px; font: 600 15px/1 Arial, sans-serif; position: sticky; top: 0; z-index: 9999; }
  .entry-content { max-width: 760px; margin: 0 auto; padding: 0 20px; transform: translateZ(0); position: relative; z-index: 1; }
  .entry-content a { color: #0645ad !important; text-decoration: underline !important; }
  .entry-content a:hover { background: yellow !important; }
  .entry-content h1, .entry-content h2 { font-family: Impact, sans-serif !important; text-transform: uppercase !important; letter-spacing: .1em !important; }
  .entry-content ul { list-style: square; margin: 1em 2em; }
</style>
</head>
<body data-odp-context="<?php echo esc_attr( GHCA_ODP_Bulletins::$context ); ?>">
<header class="site-header" style="display:flex;align-items:center;justify-content:space-between">Site header (WordPress theme)<img class="avatar user-7-avatar avatar-32 photo" width="32" height="32" alt="" style="border-radius:50%;background:#555" src="<?php echo esc_attr( $photo ? bp_core_fetch_avatar( array() ) : 'data:image/svg+xml;utf8,' . rawurlencode( "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' fill='#9aa3af'/></svg>" ) ); ?>"></header>
<div class="entry-content"><?php echo $html; // phpcs:ignore ?></div>
<script src="../assets/ecd-redesign.js"></script>
<?php if ( in_array( $_GET['open'] ?? '', array( 'pick', 'crop', 'remove', 'error' ), true ) ) : ?>
<script>
// Screenshots: open the photo pop-up in a given state (a generated sample photo for crop / error).
// After DOMContentLoaded, so the dashboard script has set up the pop-up (a click before that follows the link).
document.addEventListener('DOMContentLoaded', function () {
  var mode = <?php echo json_encode( (string) $_GET['open'] ); ?>;
  document.querySelector('[data-ghca-ph-open]').click();
  var layer = document.querySelector('[data-ghca-ph]');
  if (mode === 'remove') { layer.querySelector('[data-ghca-ph-remove]').click(); return; }
  if (mode === 'pick') return;
  var c = document.createElement('canvas'); c.width = 1200; c.height = 900;
  var x = c.getContext('2d'), g = x.createLinearGradient(0, 0, 0, 900);
  g.addColorStop(0, '#9cc3d5'); g.addColorStop(1, '#e8d8c3'); x.fillStyle = g; x.fillRect(0, 0, 1200, 900);
  x.fillStyle = '#6b4a2f'; x.beginPath(); x.arc(600, 360, 150, 0, 7); x.fill();
  x.beginPath(); x.ellipse(600, 900, 330, 300, 0, 0, 7); x.fill();
  c.toBlob(function (b) {
    var input = layer.querySelector('[data-ghca-ph-file]'), dt = new DataTransfer();
    dt.items.add(new File([b], 'me.png', { type: 'image/png' }));
    input.files = dt.files; input.dispatchEvent(new Event('change', { bubbles: true }));
    if (mode === 'error') setTimeout(function () { layer.querySelector('[data-ghca-ph-save]').click(); }, 300);
  }, 'image/png');
});
</script>
<?php endif; ?>
</body>
</html>
