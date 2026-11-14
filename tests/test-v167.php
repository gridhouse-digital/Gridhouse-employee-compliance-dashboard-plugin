<?php
// Run: php tests/test-v167.php
// 1.6.7: "Next Required Course" follows LearnDash order and prerequisites.
//  - Courses sort by menu_order (> 0 first, ascending), then courses with no menu_order by the old title rule
//    (ORIENTATION, MODULE n, the rest), ties by lower course ID. An ORIENTATION course with menu_order 0 goes first.
//  - The next course skips courses whose LearnDash prerequisites are unmet (locked). If every incomplete course is
//    locked, the first incomplete one is shown, marked locked.
//  - Both the regular path (get_required_courses) and the new-hire path (map_new_hire_courses) behave the same.
//
// Each scenario runs the real get_snapshot() plus the redesign "Next up" card and the legacy Next Required Course
// shortcode in its own PHP process. Legacy-site parity runs the same scenario against the 1.6.6 plugin files
// (commit 8bb049b), read out of git into a temporary folder. The admin plugin is never loaded: the new-hire list
// comes from a GHCA_Compliance_Program stub shaped like admin get_user_courses() rows.

const GHCA_T_BASE_COMMIT = '8bb049bd78f891feb9abca8be78cb8bba386a274';

if ( isset( $argv[1] ) && 'scenario' === $argv[1] ) {
  run_scenario( (string) ( $argv[2] ?? '' ), (string) ( $argv[3] ?? '' ), (string) ( $argv[4] ?? '' ) );
  exit( 0 );
}

$failures = 0;
function check( string $label, bool $ok ): void {
  global $failures;
  echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
  if ( ! $ok ) { ++$failures; }
}
function scenario( string $name, string $path, string $plugin_dir ): array {
  $out  = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg( __FILE__ ) . ' scenario ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $path ) . ' ' . escapeshellarg( $plugin_dir ) . ' 2>&1' );
  $line = '';
  foreach ( preg_split( '/\R/', $out ) as $l ) {
    if ( 0 === strpos( $l, '@@JSON ' ) ) { $line = substr( $l, 7 ); }
  }
  if ( $plugin_dir === dirname( __DIR__ ) && preg_match( '/(Deprecated|Warning|Notice|Fatal error):/', $out ) ) {
    $GLOBALS['ghca_t_php_issues'][] = "{$path}:{$name}: " . trim( (string) strtok( $out, "\n" ) );
  }
  $json = json_decode( $line, true );
  if ( ! is_array( $json ) ) {
    echo "  scenario {$path}:{$name} output:\n" . $out . "\n";
  }
  return is_array( $json ) ? $json : array();
}

// 1.6.6 plugin files, straight from git.
$repo = dirname( __DIR__ );
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-ecd-166-' . getmypid();
$git  = 'git -C ' . escapeshellarg( $repo ) . ' ';
foreach ( preg_split( '/\R/', trim( (string) shell_exec( $git . 'ls-tree -r --name-only ' . GHCA_T_BASE_COMMIT . ' -- gridhouse-employee-compliance-dashboard.php includes' ) ) ) as $path ) {
  if ( '' === $path ) { continue; }
  $dest = $base . '/' . $path;
  if ( ! is_dir( dirname( $dest ) ) ) { mkdir( dirname( $dest ), 0777, true ); }
  $code = str_replace( "\r\n", "\n", (string) shell_exec( $git . 'show ' . escapeshellarg( GHCA_T_BASE_COMMIT . ':' . $path ) ) );
  $here = $repo . '/' . $path;
  if ( is_readable( $here ) && false !== strpos( (string) file_get_contents( $here ), "\r\n" ) ) {
    $code = str_replace( "\n", "\r\n", $code );
  }
  file_put_contents( $dest, $code );
}
check( '1.6.6 baseline files read from git (' . substr( GHCA_T_BASE_COMMIT, 0, 7 ) . ')', is_readable( $base . '/includes/class-data-provider.php' ) && false === strpos( (string) file_get_contents( $base . '/includes/class-data-provider.php' ), 'sort_courses' ) );

foreach ( array( 'regular', 'newhire' ) as $p ) {
  $L = "[{$p}]";

  // --- Sorting -------------------------------------------------------------------------------------------------------
  $j = scenario( 'sort', $p, $repo );
  check( "{$L} menu_order ascending, tie at 6 -> lower ID first, menu_order 0 after numbered, ORIENTATION (menu_order 0) first", array( 40, 5, 10, 20, 30, 31 ) === ( $j['order'] ?? null ) );
  $j = scenario( 'sort-zeros', $p, $repo );
  check( "{$L} menu_order 0 courses: ORIENTATION, then MODULE n, then the rest (lower ID first)", array( 9, 3, 2, 4, 8 ) === ( $j['order'] ?? null ) );

  // Legacy site: all menu_order 0, "Module n" titles. Same order as the 1.6.6 code.
  $old = scenario( 'legacy', $p, $base );
  $new = scenario( 'legacy', $p, $repo );
  check( "{$L} legacy site (menu_order unset, Module n titles): order identical to 1.6.6", array() !== ( $old['order'] ?? array() ) && $old['order'] === ( $new['order'] ?? null ) && array( 50, 53, 51, 52 ) === $new['order'] );
  check( "{$L} legacy site: next course and module label identical to 1.6.6", ( $old['next'] ?? 'x' ) === ( $new['next'] ?? 'y' ) && 'Module 2 of 4' === ( $new['next']['module_label'] ?? '' ) );
  check( "{$L} legacy site: legacy Next Required Course card identical to 1.6.6", '' !== ( $old['legacy_html'] ?? '' ) && $old['legacy_html'] === ( $new['legacy_html'] ?? null ) );

  // --- Prerequisites -------------------------------------------------------------------------------------------------
  $j = scenario( 'prereq', $p, $repo );
  check( "{$L} unmet prerequisite skipped: next is the first unlocked not-started course", 62 === ( $j['next']['id'] ?? 0 ) && false === ( $j['next']['locked'] ?? null ) && 'Module 3 of 4' === ( $j['next']['module_label'] ?? '' ) );
  check( "{$L} locked flag on the course rows", true === ( $j['locked'][61] ?? null ) && false === ( $j['locked'][62] ?? null ) && false === ( $j['locked'][60] ?? null ) );
  $j = scenario( 'inprog-locked', $p, $repo );
  check( "{$L} in-progress but locked is skipped; a later unlocked in-progress course wins over not-started", 73 === ( $j['next']['id'] ?? 0 ) && 'Resume Course' === ( $j['next']['button_label'] ?? '' ) );
  $j = scenario( 'inprog-locked-only', $p, $repo );
  check( "{$L} in-progress locked, no other in-progress: first unlocked not-started course", 72 === ( $j['next']['id'] ?? 0 ) && 'Start Course' === ( $j['next']['button_label'] ?? '' ) );
  $j = scenario( 'all-locked', $p, $repo );
  check( "{$L} every incomplete course locked: first incomplete in sort order, locked=true (card never blank)", 81 === ( $j['next']['id'] ?? 0 ) && true === ( $j['next']['locked'] ?? null ) && 'Module 2 of 3' === ( $j['next']['module_label'] ?? '' ) );
  check( "{$L} every incomplete course locked: redesign Next up card shows it with the Locked chip", false !== strpos( $j['next_html'] ?? '', 'gh-st--locked' ) && false !== strpos( $j['next_html'] ?? '', 'Course 2 of 3' ) && false !== strpos( $j['legacy_html'] ?? '', 'Module 2 of 3' ) );

  // --- Adesuwa (user 101, LPN, group 2864 on Akanso) ------------------------------------------------------------------
  foreach ( array( 'adesuwa' => '', 'adesuwa-2905-inprogress' => ' (2905 in progress)' ) as $name => $suffix ) {
    $j = scenario( $name, $p, $repo );
    check( "{$L} Adesuwa{$suffix}: Akanso order by menu_order (1757 before 2905 at 6, unset last by ID)", array( 135, 1469, 1468, 1471, 1597, 1757, 2905, 2606, 2886, 2934 ) === ( $j['order'] ?? null ) );
    check( "{$L} Adesuwa{$suffix}: next course is 1469 EMPLOYEE POLICIES & FILLABLE FORMS, Module 2 of 10", 1469 === ( $j['next']['id'] ?? 0 ) && 'EMPLOYEE POLICIES & FILLABLE FORMS' === ( $j['next']['title'] ?? '' ) && 'Module 2 of 10' === ( $j['next']['module_label'] ?? '' ) && false === ( $j['next']['locked'] ?? null ) );
    check( "{$L} Adesuwa{$suffix}: 2905 FATAL FIVE is locked and never next", 2905 !== ( $j['next']['id'] ?? 0 ) && true === ( $j['locked'][2905] ?? null ) && false === strpos( ( $j['next_html'] ?? '' ) . ( $j['legacy_html'] ?? '' ), 'FATAL FIVE' ) && false === stripos( $j['next_html'] ?? '', 'Fatal Five' ) );
    check( "{$L} Adesuwa{$suffix}: both cards show 1469", false !== stripos( $j['next_html'] ?? '', 'Employee Policies' ) && false !== strpos( $j['legacy_html'] ?? '', 'EMPLOYEE POLICIES &amp; FILLABLE FORMS' ) && false !== strpos( $j['next_html'] ?? '', 'Course 2 of 10' ) );
  }
  // The bug, reproduced on the 1.6.6 code, so the scenario is the live one.
  $old = scenario( 'adesuwa', $p, $base );
  check( "{$L} baseline sanity: 1.6.6 picks 2905 FATAL FIVE for Adesuwa", 2905 === ( $old['next']['id'] ?? 0 ) );
}

check( 'cache: snapshot key carries the plugin version (1.6.7 orphans every 1.6.6 snapshot)', 1 === preg_match( "/self::build_cache_key\\(\\s*\\\$version,/", (string) file_get_contents( $repo . '/includes/class-data-provider.php' ) ) && 1 === preg_match( "/\\\$version\\s*=\\s*GHCA_Employee_Compliance_Dashboard::VERSION/", (string) file_get_contents( $repo . '/includes/class-data-provider.php' ) ) );

$issues = $GLOBALS['ghca_t_php_issues'] ?? array();
check( 'no PHP notices, warnings or deprecations in any 1.6.7 scenario' . ( $issues ? ' — ' . implode( ' | ', $issues ) : '' ), array() === $issues );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
rmdir( $base );

echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASSED' ) . "\n";
exit( $failures ? 1 : 0 );

// =====================================================================================================================
// Scenario runner (child process)
// =====================================================================================================================

/**
 * Course sets: id => [title, menu_order, state (done|prog|new), prerequisite course IDs].
 * The list order is the order the courses arrive in (the admin new-hire list is newest course ID first).
 *
 * @return array<int,array{0:string,1:int,2:string,3:array<int,int>}>
 */
function t_courses( string $name ): array {
  switch ( $name ) {
    case 'sort':
      return array(
        31 => array( 'ZETA SAFETY', 0, 'new', array() ),
        30 => array( 'ALPHA SAFETY', 0, 'new', array() ),
        20 => array( 'FATAL FIVE', 6, 'new', array() ),
        10 => array( 'ABUSE PREVENTION', 6, 'new', array() ),
        5  => array( 'EMPLOYEE POLICIES', 2, 'new', array() ),
        40 => array( 'ORIENTATION', 0, 'new', array() ),
      );
    case 'sort-zeros':
      return array(
        8 => array( 'CPR', 0, 'new', array() ),
        4 => array( 'HIPAA', 0, 'new', array() ),
        2 => array( 'MODULE 2: RIGHTS', 0, 'new', array() ),
        3 => array( 'MODULE 1: SAFETY', 0, 'new', array() ),
        9 => array( 'NEW HIRE ORIENTATION', 0, 'new', array() ),
      );
    case 'legacy':
      return array(
        52 => array( 'MODULE 3: INCIDENTS', 0, 'new', array() ),
        51 => array( 'MODULE 2: RIGHTS', 0, 'new', array() ),
        50 => array( 'ORIENTATION', 0, 'done', array() ),
        53 => array( 'MODULE 1: SAFETY', 0, 'new', array() ),
      );
    case 'prereq':
      return array(
        60 => array( 'ORIENTATION', 1, 'done', array() ),
        61 => array( 'LOCKED COURSE', 2, 'new', array( 99 ) ),
        62 => array( 'OPEN COURSE', 3, 'new', array( 60 ) ),
        63 => array( 'LATER COURSE', 4, 'new', array() ),
      );
    case 'inprog-locked':
      return array(
        70 => array( 'ORIENTATION', 1, 'done', array() ),
        71 => array( 'STARTED BUT LOCKED', 2, 'prog', array( 99 ) ),
        72 => array( 'OPEN NOT STARTED', 3, 'new', array() ),
        73 => array( 'OPEN IN PROGRESS', 4, 'prog', array( 70 ) ),
      );
    case 'inprog-locked-only':
      return array(
        70 => array( 'ORIENTATION', 1, 'done', array() ),
        71 => array( 'STARTED BUT LOCKED', 2, 'prog', array( 99 ) ),
        72 => array( 'OPEN NOT STARTED', 3, 'new', array() ),
      );
    case 'all-locked':
      return array(
        80 => array( 'ORIENTATION', 1, 'done', array() ),
        81 => array( 'LOCKED ONE', 2, 'new', array( 99 ) ),
        82 => array( 'LOCKED TWO', 3, 'prog', array( 81 ) ),
      );
    case 'adesuwa':
    case 'adesuwa-2905-inprogress':
      // Akanso menu_order values as found live; titles other than 135, 1469, 1597 and 2905 are stand-ins.
      // Prerequisites: 1469 needs 135 (met); 2905 needs 1597 (not done). The others are illustrative.
      return array(
        2934 => array( 'HIPAA PRIVACY', 0, 'done', array() ),
        2905 => array( 'FATAL FIVE', 6, 'adesuwa' === $name ? 'new' : 'prog', array( 1597 ) ),
        2886 => array( 'CPR & FIRST AID', 0, 'new', array() ),
        2606 => array( 'FIRE SAFETY', 0, 'new', array() ),
        1757 => array( 'MEDICATION ADMINISTRATION', 6, 'new', array( 1597 ) ),
        1597 => array( 'ABUSE PREVENTION', 5, 'new', array( 1469 ) ),
        1471 => array( 'INDIVIDUAL RIGHTS', 4, 'new', array( 1469 ) ),
        1469 => array( 'EMPLOYEE POLICIES & FILLABLE FORMS', 2, 'new', array( 135 ) ),
        1468 => array( 'INCIDENT MANAGEMENT', 3, 'new', array( 1469 ) ),
        135  => array( 'ORIENTATION', 1, 'done', array() ),
      );
  }
  return array();
}

function run_scenario( string $name, string $path, string $plugin_dir ): void {
  $GLOBALS['t'] = array( 'courses' => t_courses( $name ), 'new_hire' => 'newhire' === $path );

  t_define_wp_stubs();
  if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
  if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', __DIR__ ); }
  if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
  require $plugin_dir . '/gridhouse-employee-compliance-dashboard.php';

  $snapshot = GHCA_ECD_Data_Provider::get_snapshot();
  $next_m   = new ReflectionMethod( 'GHCA_ECD_Redesign', 'next_up_html' );
  $locked   = array();
  foreach ( $snapshot['courses'] as $course ) {
    $locked[ (int) $course['id'] ] = ! empty( $course['locked'] );
  }
  $next = $snapshot['next_course'];
  if ( is_array( $next ) ) {
    $next = array_intersect_key( $next, array_flip( array( 'id', 'title', 'module_index', 'module_total', 'module_label', 'button_label', 'url', 'locked' ) ) );
    if ( ! array_key_exists( 'locked', $next ) ) { $next['locked'] = null; } // 1.6.6 has no locked flag on next_course.
  }
  echo '@@JSON ' . json_encode( array(
    'order'       => array_map( 'intval', array_column( $snapshot['courses'], 'id' ) ),
    'locked'      => $locked,
    'next'        => 'legacy' === $name && is_array( $next ) ? array_diff_key( $next, array( 'locked' => 1 ) ) : $next,
    'next_html'   => (string) $next_m->invoke( null, $snapshot ),
    'legacy_html' => (string) GHCA_ECD_Shortcodes::render_next_course(),
  ) ) . "\n";
}

function t_state( int $id ): string {
  return $GLOBALS['t']['courses'][ $id ][2] ?? 'new';
}

function t_define_wp_stubs(): void {
  if ( $GLOBALS['t']['new_hire'] ) {
    class GHCA_Compliance_Program {
      const OPTION_NEW_HIRE_GROUPS = 'x_groups';
      const OPTION_NEW_HIRE_DAYS = 'x_days';
      public static function get_user_status( int $user_id ): array {
        $rows = array();
        foreach ( $GLOBALS['t']['courses'] as $id => $c ) {
          $done   = 'done' === $c[2];
          $rows[] = array(
            'id' => $id, 'title' => $c[0], 'status' => $done ? 'completed' : ( 'prog' === $c[2] ? 'in_progress' : 'not_started' ),
            'completed' => $done, 'progress' => $done ? 100 : ( 'prog' === $c[2] ? 40 : 0 ), 'progress_label' => ( $done ? 100 : ( 'prog' === $c[2] ? 40 : 0 ) ) . '%',
            'url' => 'https://example.test/course/' . $id . '/',
          );
        }
        return array( 'active' => true, 'complete' => false, 'overdue' => false, 'at_risk' => false, 'flagged' => false, 'deadline_label' => 'December 1, 2030', 'deadline_ts' => 1922745600, 'deadline_days' => 30, 'courses' => $rows );
      }
    }
  }

  function add_action( ...$a ) {}
  function add_filter( ...$a ) {}
  function add_shortcode( ...$a ) {}
  function __( $s, $d = null ) { return $s; }
  function _e( $s, $d = null ) { echo $s; }
  function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
  function _x( $s, $c, $d = null ) { return $s; }
  function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
  function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
  function esc_url( $s ) { return (string) $s; }
  function esc_html__( $s, $d = null ) { return esc_html( $s ); }
  function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
  function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
  function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
  function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }
  function wp_timezone() { return new DateTimeZone( 'UTC' ); }
  function home_url( $p = '' ) { return 'https://example.test' . $p; }
  function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
  function site_url( $p = '' ) { return 'https://example.test' . $p; }
  function get_permalink( $id = 0 ) { return 'https://example.test/course/' . (int) $id . '/'; }
  function add_query_arg( ...$a ) { return 'https://example.test/?q'; }
  function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
  function wp_create_nonce( $a = '' ) { return 'nonce'; }
  function wp_nonce_field( ...$a ) { return ''; }
  function get_current_user_id() { return 101; }
  function is_user_logged_in() { return true; }
  function current_user_can( ...$a ) { return false; }
  function is_admin() { return false; }
  function get_userdata( $id ) {
    return (object) array( 'ID' => 101, 'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 40 * 86400 ), 'display_name' => 'Test Employee', 'user_login' => 'test', 'user_email' => 'test@example.test', 'roles' => array( 'subscriber' ), 'first_name' => 'Test', 'last_name' => 'Employee' );
  }
  function get_user_meta( $id, $key = '', $single = false ) { return array( 'first_name' => 'Test', 'last_name' => 'Employee' )[ $key ] ?? ''; }
  function get_option( $k, $default = false ) {
    $map = array( 'ghca_compliance_due_date' => '2030-01-01', 'ghca_ecd_cache_ttl' => 0, 'ghca_ecd_ui_redesign' => false );
    return array_key_exists( $k, $map ) ? $map[ $k ] : $default;
  }
  function apply_filters( $tag, $value, ...$a ) { return $value; }
  function do_action( ...$a ) {}
  function get_transient( $k ) { return false; }
  function set_transient( ...$a ) { return true; }
  function delete_transient( $k ) { return true; }
  function get_the_title( $id ) { return $GLOBALS['t']['courses'][ (int) $id ][0] ?? 'COURSE'; }
  function get_post( $id = null ) { return null; }
  function get_posts( ...$a ) { return array(); }
  function get_post_meta( ...$a ) { return ''; }
  function get_post_field( $field, $id = 0 ) {
    return 'menu_order' === $field ? (string) ( $GLOBALS['t']['courses'][ (int) $id ][1] ?? 0 ) : '';
  }
  function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
  function strip_shortcodes( $s ) { return (string) $s; }
  function wp_trim_words( $s, $n = 55 ) { return (string) $s; }
  function shortcode_atts( $d, $a ) { return array_merge( (array) $d, (array) $a ); }
  function wp_login_url( $r = '' ) { return 'https://example.test/login'; }
  function post_type_exists( $t ) { return false; }
  function get_page_by_path( ...$a ) { return ''; }
  function learndash_user_get_enrolled_courses( $u ) { return array_keys( $GLOBALS['t']['courses'] ); }
  function learndash_course_status( $c, $u, $raw = false ) { return array( 'done' => 'completed', 'prog' => 'in_progress', 'new' => 'not_started' )[ t_state( (int) $c ) ]; }
  function learndash_course_progress( $a ) { return array( 'percentage' => array( 'done' => 100, 'prog' => 40, 'new' => 0 )[ t_state( (int) $a['course_id'] ) ] ); }
  function learndash_course_completed( $u, $c ) { return 'done' === t_state( (int) $c ); }
  function learndash_user_get_course_completed_date( $u, $c ) { return time() - 10 * 86400; }
  function learndash_get_setting( ...$a ) { return 0; }
  function learndash_get_course_certificate_link( $c, $u ) { return ''; }
  function learndash_get_course_prerequisite_enabled( $c ) { return array() !== ( $GLOBALS['t']['courses'][ (int) $c ][3] ?? array() ); }
  function learndash_is_course_prerequities_completed( $c, $u = 0 ) {
    foreach ( $GLOBALS['t']['courses'][ (int) $c ][3] ?? array() as $pre ) {
      if ( 'done' !== t_state( (int) $pre ) ) { return false; }
    }
    return true;
  }
}
