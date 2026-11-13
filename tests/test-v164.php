<?php
// Run: php tests/test-v164.php
// 1.6.4: with the admin training-year switch on, the dashboard takes its due date, training-year window, annual
// hours threshold and per-course expiry from GHCA_ACD_Training_Year (admin 2.0.0-rc.2), and does no date math of its own.
// With the switch off, or the API absent or partial, the output must be identical to 1.6.3.
//
// The admin plugin is never loaded: GHCA_ACD_Training_Year is a stub with canned returns. Each scenario runs the real
// get_snapshot(), the redesign Due date stat, the legacy status cards, the certificates model and the profile traffic
// light in its own PHP process (stub classes can't be redefined). The 1.6.3 parity runs execute the same scenario
// against the plugin files of commit 555512a, read out of git into a temporary folder.

const GHCA_T_BASE_COMMIT = '555512ae7e5b7f9b64bc66afc4d53bee2965c72b';
// One clock for every scenario process, so 1.6.3 and 1.6.4 runs see the same timestamps.
define( 'GHCA_T_NOW', time() );

if ( isset( $argv[1] ) && 'scenario' === $argv[1] ) {
  run_scenario( (string) ( $argv[2] ?? '' ), (string) ( $argv[3] ?? '' ), (int) ( $argv[4] ?? time() ) );
  exit( 0 );
}

$failures = 0;
function check( string $label, bool $ok ): void {
  global $failures;
  echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
  if ( ! $ok ) { ++$failures; }
}
function scenario( string $name, string $plugin_dir ): array {
  $out  = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 -d display_errors=1 ' . escapeshellarg( __FILE__ ) . ' scenario ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $plugin_dir ) . ' ' . GHCA_T_NOW . ' 2>&1' );
  $line = '';
  foreach ( preg_split( '/\R/', $out ) as $l ) {
    if ( 0 === strpos( $l, '@@JSON ' ) ) { $line = substr( $l, 7 ); }
  }
  // Only the 1.6.4 code counts here; the 1.6.3 copy is just the reference.
  if ( $plugin_dir === dirname( __DIR__ ) && preg_match( '/(Deprecated|Warning|Notice|Fatal error):/', $out ) ) {
    $GLOBALS['ghca_t_php_issues'][] = $name . ': ' . trim( (string) strtok( (string) strstr( $out, ':', true ) . strstr( $out, ':' ), "\n" ) );
  }
  $json = json_decode( $line, true );
  if ( ! is_array( $json ) ) {
    echo "  scenario {$name} output:\n" . $out . "\n";
  }
  return is_array( $json ) ? $json : array();
}
/** What the employee sees and the data behind it, without the test's own bookkeeping. */
function rendered_only( array $j ): array {
  unset( $j['calls'], $j['canned'], $j['old_due_label'] );
  return $j;
}
/** The scenario output without the API call log (which 1.6.3 never makes). */
function rendered( array $j ): array {
  unset( $j['calls'] );
  return $j;
}

// 1.6.3 plugin files, straight from git, so "identical to 1.6.3" is compared against the real 1.6.3 code.
$repo = dirname( __DIR__ );
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ghca-ecd-163-' . getmypid();
$git  = 'git -C ' . escapeshellarg( $repo ) . ' ';
foreach ( preg_split( '/\R/', trim( (string) shell_exec( $git . 'ls-tree -r --name-only ' . GHCA_T_BASE_COMMIT . ' -- gridhouse-employee-compliance-dashboard.php includes' ) ) ) as $path ) {
  if ( '' === $path ) { continue; }
  $dest = $base . '/' . $path;
  if ( ! is_dir( dirname( $dest ) ) ) { mkdir( dirname( $dest ), 0777, true ); }
  $code = str_replace( "\r\n", "\n", (string) shell_exec( $git . 'show ' . escapeshellarg( GHCA_T_BASE_COMMIT . ':' . $path ) ) );
  // Same line endings as this checkout's copy: template blocks emit the source file's endings, and .gitattributes
  // checks PHP out as CRLF while `git show` gives the stored LF.
  $here = $repo . '/' . $path;
  if ( is_readable( $here ) && false !== strpos( (string) file_get_contents( $here ), "\r\n" ) ) {
    $code = str_replace( "\n", "\r\n", $code );
  }
  file_put_contents( $dest, $code );
}
check( '1.6.3 baseline files read from git (' . substr( GHCA_T_BASE_COMMIT, 0, 7 ) . ')', is_readable( $base . '/includes/class-data-provider.php' ) && false === strpos( (string) file_get_contents( $base . '/gridhouse-employee-compliance-dashboard.php' ), 'class-training-year' ) );

// --- Switch off, API absent, API partial: identical to 1.6.3 -----------------------------------------------------------
foreach ( array(
  'off'                 => 'switch off (enabled() false)',
  'none'                => 'admin plugin absent (no GHCA_ACD_Training_Year)',
  'partial-enabled'     => 'partial API: enabled() missing',
  'partial-due'         => 'partial API: due() missing',
  'partial-window'      => 'partial API: window() missing',
  'partial-requirement' => 'partial API: requirement() missing',
  'partial-course'      => 'partial API: course() missing',
  'off-newhire'         => 'switch off, new hire with no deadline (option fallback)',
) as $name => $label ) {
  $old = scenario( $name, $base );
  $new = scenario( $name, $repo );
  check( "{$label}: output identical to 1.6.3", array() !== $old && rendered( $old ) === rendered( $new ) );
  if ( 'off' === $name || 'off-newhire' === $name ) {
    check( "{$label}: only enabled() asked, no training-year data used", array( 'enabled' ) === array_values( array_unique( $new['calls']['api'] ?? array( 'x' ) ) ) );
  } else {
    check( "{$label}: the training-year API is never called", array() === ( $new['calls']['api'] ?? array( 'x' ) ) );
  }
  check( "{$label}: decorate() still used for per-course expiry", ( $new['calls']['decorate'] ?? 0 ) > 0 );
  check( "{$label}: no window / threshold labels", false === strpos( $new['kpis'] ?? '', 'data-ghca-ty' ) && false === strpos( $new['legacy'] ?? '', 'data-ghca-ty' ) && ! isset( $new['snapshot']['training_year'] ) );
}
$old = scenario( 'off', $base );
check( 'baseline sanity: 1.6.3 shows the 365-day due date and a notice', '' !== ( $old['snapshot']['compliance_due_date_label'] ?? '' ) && ! empty( $old['snapshot']['compliance_notification']['show'] ) );
$old = scenario( 'off-newhire', $base );
check( 'baseline sanity: 1.6.3 new hire without a deadline falls back to the option date', 'January 1, 2030' === ( $old['snapshot']['compliance_due_date_label'] ?? '' ) );

// --- Switch on: parity with the admin API ------------------------------------------------------------------------------
$j    = scenario( 'on', $repo );
$c    = $j['canned'] ?? array();
$snap = $j['snapshot'] ?? array();
check( 'on: due date label exactly as due() returned it', $c['due_date_label'] === ( $snap['compliance_due_date_label'] ?? null ) && $c['due_date_label'] === ( $snap['due_date_label'] ?? null ) );
check( 'on: cycle due_timestamp / due_date from due()', $c['due_timestamp'] === ( $j['cycle']['due_timestamp'] ?? null ) && gmdate( 'Y-m-d', $c['due_timestamp'] ) === ( $j['cycle']['due_date'] ?? null ) && $c['due_timestamp'] === ( $snap['due_ts'] ?? null ) );
check( 'on: cycle start from window() start_ts', $c['start_ts'] === ( $j['cycle']['start_timestamp'] ?? null ) && gmdate( 'F j, Y', $c['start_ts'] ) === ( $snap['start_date_label'] ?? null ) );
check( 'on: days_remaining counted to the due day (45), not a 365-day cycle', 45 === ( $j['cycle']['days_remaining'] ?? null ) );
check( 'on: cycle keeps days_on_platform / cycle_day / cycle_number keys', array_key_exists( 'days_on_platform', $j['cycle'] ?? array() ) && array_key_exists( 'cycle_day', $j['cycle'] ?? array() ) && array_key_exists( 'cycle_number', $j['cycle'] ?? array() ) );
check( 'on: the 1.6.3 365-day date is not shown anywhere', false === strpos( json_encode( rendered_only( $j ) ), $j['old_due_label'] ?? '###' ) );
check( 'on: the option due date is not shown anywhere', false === strpos( json_encode( $j ), 'January 1, 2030' ) && false === strpos( json_encode( $j ), '2030-01-01' ) );
check( 'on: notice counts down to the training-year due date', 45 === ( $snap['compliance_notification']['days_remaining'] ?? null ) && $c['due_date_label'] === ( $snap['compliance_notification']['due_date_label'] ?? null ) && false !== strpos( (string) ( $snap['compliance_notification']['message'] ?? '' ), $c['due_date_label'] ) );
check( 'redesign Due date stat: due date', false !== strpos( $j['kpis'], '>' . $c['due_date_label'] . '<' ) );
check( 'redesign Due date stat: training-year window', false !== strpos( $j['kpis'], 'data-ghca-ty="window">Training year: ' . $c['window_label'] . '<' ) );
check( 'redesign Due date stat: threshold hours (12)', false !== strpos( $j['kpis'], 'data-ghca-ty="hours">12 hours required this training year<' ) );
check( 'redesign Due date stat: no "Role requirement not set" when the role is mapped', false === strpos( $j['kpis'], 'Role requirement not set' ) );
check( 'legacy KPI: due date, window and threshold', false !== strpos( $j['legacy'], $c['due_date_label'] ) && false !== strpos( $j['legacy'], 'Training year: ' . $c['window_label'] ) && false !== strpos( $j['legacy'], '12 hours required this training year' ) );
$courses = array_column( $snap['courses'] ?? array(), null, 'id' );
$ok      = 3 === count( $courses );
foreach ( $c['courses'] as $id => $want ) {
  foreach ( array( 'compliance_state', 'lifespan_days', 'expiration_ts', 'expiration_label', 'follows_training_year' ) as $k ) {
    $ok = $ok && ( $courses[ $id ][ $k ] ?? null ) === $want[ $k ];
  }
}
check( 'on: per-course state / lifespan / expiry / follows_training_year exactly as course() returned', $ok );
check( 'on: decorate() never called', 0 === ( $j['calls']['decorate'] ?? -1 ) );
check( 'on: course() called with (user, course, completed, completed_ts)', array( array( 7, 101, true, $c['completed_ts'] ), array( 7, 102, true, $c['completed_ts'] ), array( 7, 103, false, 0 ) ) === array_slice( $j['calls']['course_args'] ?? array(), 0, 3 ) );
check( 'on: expired course drives the status and the certificate expiry label', 'expired' === ( $snap['training_status'] ?? '' ) && 'overdue' === ( $j['certs']['CPR']['chip'] ?? '' ) && $c['courses'][102]['expiration_label'] === ( $j['certs']['CPR']['expires'] ?? '' ) && false !== strpos( $j['certs']['CPR']['meta'] ?? '', 'expired ' . $c['courses'][102]['expiration_label'] ) );
check( 'on: per-course due badge uses course() expiry (Due soon chip for course 103)', $c['courses'][103]['expiration_ts'] === ( $j['course_due'][103]['ts'] ?? 0 ) && 'duesoon' === ( $j['course_due'][103]['chip'] ?? '' ) );
check( 'on: profile traffic light uses course()', false !== strpos( $j['traffic'][102] ?? '', 'ghca-traffic-light--expired' ) && false !== strpos( $j['traffic'][101] ?? '', 'ghca-traffic-light--current' ) );
check( 'on: required_hours() not needed (requirement() used)', ! in_array( 'required_hours', $j['calls']['api'] ?? array(), true ) );

$j = scenario( 'on-default', $repo );
check( 'role unmapped (source default): "Role requirement not set" next to 24 hours', false !== strpos( $j['kpis'], 'data-ghca-ty="hours">24 hours required this training year<' ) && false !== strpos( $j['kpis'], 'data-ghca-ty="flag">Role requirement not set<' ) && false !== strpos( $j['legacy'], 'Role requirement not set' ) );
check( 'role unmapped: never the old "Not set — 24 h assumed" wording', false === stripos( $j['kpis'] . $j['legacy'], '24 h assumed' ) );

$j = scenario( 'on-notrequired', $repo );
check( 'role not required (0 hours): "Annual hours not required", no flag', false !== strpos( $j['kpis'], 'data-ghca-ty="hours">Annual hours not required<' ) && false === strpos( $j['kpis'], 'Role requirement not set' ) );

$j    = scenario( 'on-missing', $repo );
$snap = $j['snapshot'] ?? array();
check( 'hire date missing: label as returned, no due date, no notice', 'Hire date missing' === ( $snap['compliance_due_date_label'] ?? '' ) && 0 === ( $j['cycle']['due_timestamp'] ?? -1 ) && '' === ( $j['cycle']['due_date'] ?? 'x' ) && array_key_exists( 'days_remaining', $j['cycle'] ?? array() ) && null === $j['cycle']['days_remaining'] && empty( $snap['compliance_notification']['show'] ) );
check( 'hire date missing: no option / 365-day fallback', false === strpos( json_encode( $j ), 'January 1, 2030' ) && false === strpos( json_encode( rendered_only( $j ) ), $j['old_due_label'] ?? '###' ) && 0 === ( $snap['due_ts'] ?? -1 ) );
check( 'hire date missing: Due date stat shows the label, window line left out', false !== strpos( $j['kpis'], '>Hire date missing<' ) && false === strpos( $j['kpis'], 'data-ghca-ty="window"' ) );

$j = scenario( 'on-notconfigured', $repo );
check( 'training year not configured: label as returned', 'Training year not configured' === ( $j['snapshot']['compliance_due_date_label'] ?? '' ) && false !== strpos( $j['kpis'], '>Training year not configured<' ) );

$j = scenario( 'on-today', $repo );
check( 'due day itself: 0 days left, "Due today" (still due, not overdue)', 0 === ( $j['cycle']['days_remaining'] ?? null ) && 'Due today' === ( $j['snapshot']['compliance_notification']['countdown_label'] ?? '' ) );

$j = scenario( 'on-overdue', $repo );
check( 'due day passed: negative days and an Overdue notice (no 365-day roll-over)', -3 === ( $j['cycle']['days_remaining'] ?? null ) && 'Overdue' === ( $j['snapshot']['compliance_notification']['countdown_label'] ?? '' ) && 'danger' === ( $j['snapshot']['compliance_notification']['level'] ?? '' ) );

$j = scenario( 'on-newhire', $repo );
check( 'switch on, new hire with no deadline: due date from due(), not the option', ( $j['canned']['due_date_label'] ?? '?' ) === ( $j['snapshot']['compliance_due_date_label'] ?? '' ) && false === strpos( json_encode( $j ), 'January 1, 2030' ) );

$j = scenario( 'on-throws', $repo );
check( 'admin API throws: dashboard still renders, no fatal', isset( $j['kpis'] ) && false !== strpos( $j['kpis'], 'Due date' ) );

$issues = $GLOBALS['ghca_t_php_issues'] ?? array();
check( 'no PHP notices, warnings or deprecations in any 1.6.4 scenario' . ( $issues ? ' — ' . implode( ' | ', $issues ) : '' ), array() === $issues );

// Clean up the 1.6.3 copy.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
rmdir( $base );

echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASSED' ) . "\n";
exit( $failures ? 1 : 0 );

// =====================================================================================================================
// Scenario runner (child process)
// =====================================================================================================================

function run_scenario( string $name, string $plugin_dir, int $clock ): void {
  $now = $clock;
  $day = 86400;
  $eod = static function ( int $ts ): int { return (int) gmmktime( 23, 59, 59, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) ); };

  $completed_ts = $now - 20 * $day;
  $due_ts       = $eod( $now + 45 * $day );
  $start_ts     = $due_ts + 1 - 365 * $day;
  $canned = array(
    'due_timestamp'  => $due_ts,
    'due_date_label' => gmdate( 'F j, Y', $due_ts ),
    'start_ts'       => $start_ts,
    'window_label'   => gmdate( 'M j, Y', $start_ts ) . ' – ' . gmdate( 'M j, Y', $due_ts ),
    'completed_ts'   => $completed_ts,
    'hours'          => 12,
    'source'         => 'role_map',
    'courses'        => array(
      101 => array( 'expiration_ts' => $due_ts, 'expiration_label' => gmdate( 'M j, Y', $due_ts ), 'compliance_state' => 'current', 'lifespan_days' => 0, 'follows_training_year' => true ),
      102 => array( 'expiration_ts' => $now - 2 * $day, 'expiration_label' => gmdate( 'M j, Y', $now - 2 * $day ), 'compliance_state' => 'expired', 'lifespan_days' => 730, 'follows_training_year' => false ),
      103 => array( 'expiration_ts' => $eod( $now + 10 * $day ), 'expiration_label' => gmdate( 'M j, Y', $eod( $now + 10 * $day ) ), 'compliance_state' => 'incomplete', 'lifespan_days' => 0, 'follows_training_year' => true ),
    ),
  );
  if ( 'on-default' === $name ) { $canned['hours'] = 24; $canned['source'] = 'default'; }
  if ( 'on-notrequired' === $name ) { $canned['hours'] = 0; }
  if ( 'on-missing' === $name || 'on-notconfigured' === $name ) {
    $canned['due_timestamp']  = 0;
    $canned['due_date_label'] = 'on-missing' === $name ? 'Hire date missing' : 'Training year not configured';
    $canned['start_ts']       = 0;
    $canned['window_label']   = '';
  }
  if ( 'on-today' === $name ) { $canned['due_timestamp'] = $eod( $now ); $canned['due_date_label'] = gmdate( 'F j, Y', $now ); }
  if ( 'on-overdue' === $name ) { $canned['due_timestamp'] = $eod( $now - 3 * $day ); $canned['due_date_label'] = gmdate( 'F j, Y', $now - 3 * $day ); }

  $GLOBALS['t'] = array(
    'canned'     => $canned,
    'enabled'    => 0 === strpos( $name, 'on' ) || 0 === strpos( $name, 'partial' ),
    'throws'     => 'on-throws' === $name,
    'registered' => gmdate( 'Y-m-d H:i:s', $now - 300 * $day ), // 365-day cycle: 65 days left => an info notice in 1.6.3
    'new_hire'   => in_array( $name, array( 'off-newhire', 'on-newhire' ), true ),
    'calls'      => array( 'api' => array(), 'decorate' => 0, 'course_args' => array() ),
  );

  t_define_wp_stubs();
  t_define_admin_stubs();
  if ( 'none' !== $name ) {
    $variant = 0 === strpos( $name, 'partial-' ) ? substr( $name, 8 ) : 'full';
    class_alias( 'T_TY_' . str_replace( '-', '_', $variant ), 'GHCA_ACD_Training_Year' );
  }

  if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
  if ( ! defined( 'WP_PLUGIN_DIR' ) ) { define( 'WP_PLUGIN_DIR', __DIR__ ); }
  if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
  require $plugin_dir . '/gridhouse-employee-compliance-dashboard.php';

  $cycle_m = new ReflectionMethod( 'GHCA_ECD_Data_Provider', 'get_compliance_cycle' );
  $kpis_m = new ReflectionMethod( 'GHCA_ECD_Redesign', 'kpis_html' );

  $snapshot = GHCA_ECD_Data_Provider::get_snapshot();
  $cycle    = $cycle_m->invoke( null, 7 );
  $kpis     = (string) $kpis_m->invoke( null, $snapshot );
  $legacy   = (string) GHCA_ECD_Shortcodes::render_status_cards();

  $course_due = array();
  $traffic    = array();
  foreach ( $snapshot['courses'] as $course ) {
    $d = GHCA_ECD_Redesign::course_due( $course, $snapshot, $now );
    $course_due[ $course['id'] ] = array( 'ts' => $d['ts'], 'label' => $d['label'], 'chip' => $d['chip'][0] );
    $traffic[ $course['id'] ]    = GHCA_Employee_Compliance_Dashboard::inject_traffic_light_class( 'row', (object) array( 'ID' => $course['id'] ), 7 );
  }
  $certs = array();
  foreach ( GHCA_ECD_Redesign::certificates_model( $snapshot['courses'], 7, (int) $snapshot['due_ts'], $now )['earned'] ?? array() as $row ) {
    $certs[ (string) $row['title'] ] = array( 'chip' => (string) $row['chip'][0], 'expires' => (string) $row['expires_label'], 'meta' => (string) $row['meta'] );
  }

  $keep = array( 'start_date_label', 'compliance_due_date_label', 'due_date_label', 'due_ts', 'training_status', 'training_status_label', 'compliance_notification', 'courses' );
  $out  = array(
    'snapshot'      => array_intersect_key( $snapshot, array_flip( $keep ) ),
    'cycle'         => $cycle,
    'kpis'          => $kpis,
    'legacy'        => $legacy,
    'course_due'    => $course_due,
    'traffic'       => $traffic,
    'certs'         => $certs,
    'calls'         => $GLOBALS['t']['calls'],
    'canned'        => $canned,
    // The 365-day date 1.6.3 would show: platform start + 365 days.
    'old_due_label' => gmdate( 'F j, Y', $now - 300 * $day + 365 * $day ),
  );
  if ( isset( $snapshot['training_year'] ) ) {
    $out['snapshot']['training_year'] = $snapshot['training_year'];
  }
  echo '@@JSON ' . json_encode( $out ) . "\n";
}

function t_api( string $method, array $args ) {
  $GLOBALS['t']['calls']['api'][] = $method;
  $c = $GLOBALS['t']['canned'];
  if ( $GLOBALS['t']['throws'] && 'enabled' !== $method ) {
    throw new RuntimeException( 'admin calculator exploded' );
  }
  switch ( $method ) {
    case 'enabled':
      return $GLOBALS['t']['enabled'];
    case 'due':
      return array( 'due_timestamp' => $c['due_timestamp'], 'due_date_label' => $c['due_date_label'], 'training_year_label' => 'Training year' );
    case 'window':
      return array(
        'start_ts'   => $c['start_ts'],
        'end_ts'     => $c['due_timestamp'] ? $c['due_timestamp'] + 1 : 0,
        'start_date' => $c['start_ts'] ? gmdate( 'm/d/Y', $c['start_ts'] ) : '',
        'end_date'   => $c['due_timestamp'] ? gmdate( 'm/d/Y', $c['due_timestamp'] ) : '',
        'missing'    => $c['due_timestamp'] ? '' : 'hire',
        'label'      => $c['window_label'],
      );
    case 'requirement':
      return array( 'key' => 'dsp', 'label' => 'Direct Support Professional', 'hours' => $c['hours'], 'set' => 'default' !== $c['source'], 'source' => $c['source'] );
    case 'course':
      $GLOBALS['t']['calls']['course_args'][] = array( $args[0], $args[1], $args[2], $args[3] );
      return $c['courses'][ $args[1] ];
  }
  return array();
}

function t_define_admin_stubs(): void {
  if ( true ) {
    // Full API, and one class per missing method (a partial admin build).
    class T_TY_full {
      public static function enabled(): bool { return t_api( 'enabled', array() ); }
      public static function due( int $u, ?int $n = null ): array { return t_api( 'due', array( $u ) ); }
      public static function window( int $u, ?int $n = null ): array { return t_api( 'window', array( $u ) ); }
      public static function requirement( int $u ): array { return t_api( 'requirement', array( $u ) ); }
      public static function required_hours( int $u ): int { t_api( 'required_hours', array( $u ) ); return 0; }
      public static function course( int $u, int $c, bool $d, int $t, ?int $n = null ): array { return t_api( 'course', array( $u, $c, $d, $t ) ); }
    }
    class T_TY_enabled {
      public static function due( int $u, ?int $n = null ): array { return t_api( 'due', array( $u ) ); }
      public static function window( int $u, ?int $n = null ): array { return t_api( 'window', array( $u ) ); }
      public static function requirement( int $u ): array { return t_api( 'requirement', array( $u ) ); }
      public static function course( int $u, int $c, bool $d, int $t, ?int $n = null ): array { return t_api( 'course', array( $u, $c, $d, $t ) ); }
    }
    class T_TY_due {
      public static function enabled(): bool { return t_api( 'enabled', array() ); }
      public static function window( int $u, ?int $n = null ): array { return t_api( 'window', array( $u ) ); }
      public static function requirement( int $u ): array { return t_api( 'requirement', array( $u ) ); }
      public static function course( int $u, int $c, bool $d, int $t, ?int $n = null ): array { return t_api( 'course', array( $u, $c, $d, $t ) ); }
    }
    class T_TY_window {
      public static function enabled(): bool { return t_api( 'enabled', array() ); }
      public static function due( int $u, ?int $n = null ): array { return t_api( 'due', array( $u ) ); }
      public static function requirement( int $u ): array { return t_api( 'requirement', array( $u ) ); }
      public static function course( int $u, int $c, bool $d, int $t, ?int $n = null ): array { return t_api( 'course', array( $u, $c, $d, $t ) ); }
    }
    class T_TY_requirement {
      public static function enabled(): bool { return t_api( 'enabled', array() ); }
      public static function due( int $u, ?int $n = null ): array { return t_api( 'due', array( $u ) ); }
      public static function window( int $u, ?int $n = null ): array { return t_api( 'window', array( $u ) ); }
      public static function required_hours( int $u ): int { return t_api( 'required_hours', array( $u ) ); }
      public static function course( int $u, int $c, bool $d, int $t, ?int $n = null ): array { return t_api( 'course', array( $u, $c, $d, $t ) ); }
    }
    class T_TY_course {
      public static function enabled(): bool { return t_api( 'enabled', array() ); }
      public static function due( int $u, ?int $n = null ): array { return t_api( 'due', array( $u ) ); }
      public static function window( int $u, ?int $n = null ): array { return t_api( 'window', array( $u ) ); }
      public static function requirement( int $u ): array { return t_api( 'requirement', array( $u ) ); }
    }

    // The admin per-course lifespan helper (the 1.6.3 path).
    class GHCA_Course_Lifespans {
      const OPTION_LIFESPANS = 'ghca_course_lifespans';
      const OPTION_WARNING_DAYS = 'ghca_course_warning_days';
      public static function decorate( int $course_id, bool $completed, int $completed_ts ): array {
        ++$GLOBALS['t']['calls']['decorate'];
        $now   = time();
        $state = array( 101 => 'current', 102 => 'expiring_soon', 103 => 'incomplete' )[ $course_id ] ?? 'incomplete';
        $exp   = $completed ? $completed_ts + 365 * 86400 : 0;
        return array( 'compliance_state' => $state, 'lifespan_days' => 365, 'expiration_ts' => $exp, 'expiration_label' => $exp ? gmdate( 'M j, Y', $exp ) : '' );
      }
    }
  }
  if ( $GLOBALS['t']['new_hire'] ) {
    class GHCA_Compliance_Program {
      const OPTION_NEW_HIRE_GROUPS = 'x_groups';
      const OPTION_NEW_HIRE_DAYS = 'x_days';
      public static function get_user_status( int $user_id ): array {
        return array( 'active' => true, 'complete' => false, 'overdue' => false, 'at_risk' => false, 'flagged' => false, 'deadline_label' => '', 'deadline_days' => 30 );
      }
    }
  }
}

function t_define_wp_stubs(): void {
  if ( true ) {
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
    function get_current_user_id() { return 7; }
    function is_user_logged_in() { return true; }
    function current_user_can( ...$a ) { return false; }
    function is_admin() { return false; }
    function get_userdata( $id ) {
      return (object) array( 'ID' => 7, 'user_registered' => $GLOBALS['t']['registered'], 'display_name' => 'Ada Lovelace', 'user_login' => 'ada', 'user_email' => 'ada@example.test', 'roles' => array( 'subscriber' ), 'first_name' => 'Ada', 'last_name' => 'Lovelace' );
    }
    function get_user_meta( $id, $key = '', $single = false ) { return array( 'first_name' => 'Ada', 'last_name' => 'Lovelace' )[ $key ] ?? ''; }
    function get_option( $k, $default = false ) {
      $map = array( 'ghca_compliance_due_date' => '2030-01-01', 'ghca_ecd_cache_ttl' => 0, 'ghca_ecd_ui_redesign' => false );
      return array_key_exists( $k, $map ) ? $map[ $k ] : $default;
    }
    function apply_filters( $tag, $value, ...$a ) { return $value; }
    function do_action( ...$a ) {}
    function get_transient( $k ) { return false; }
    function set_transient( ...$a ) { return true; }
    function delete_transient( $k ) { return true; }
    function get_the_title( $id ) { return array( 101 => 'FATAL FIVE', 102 => 'CPR', 103 => 'INDIVIDUAL RIGHTS' )[ (int) $id ] ?? 'COURSE'; }
    function get_post( $id = null ) { return null; }
    function get_posts( ...$a ) { return array(); }
    function get_post_meta( ...$a ) { return ''; }
    function get_post_field( ...$a ) { return ''; }
    function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
    function wp_trim_words( $s, $n = 55 ) { return (string) $s; }
    function shortcode_atts( $d, $a ) { return array_merge( (array) $d, (array) $a ); }
    function wp_login_url( $r = '' ) { return 'https://example.test/login'; }
    function post_type_exists( $t ) { return false; }
    function get_page_by_path( ...$a ) { return ''; }
    function learndash_user_get_enrolled_courses( $u ) { return array( 101, 102, 103 ); }
    function learndash_course_status( $c, $u, $raw = false ) { return 103 === (int) $c ? 'not_started' : 'completed'; }
    function learndash_course_progress( $a ) { return array( 'percentage' => 103 === (int) $a['course_id'] ? 0 : 100 ); }
    function learndash_course_completed( $u, $c ) { return 103 !== (int) $c; }
    function learndash_user_get_course_completed_date( $u, $c ) { return $GLOBALS['t']['canned']['completed_ts']; }
    function learndash_get_setting( ...$a ) { return 0; }
    function learndash_get_course_certificate_link( $c, $u ) { return 103 === (int) $c ? '' : 'https://example.test/cert/' . (int) $c; }
    function learndash_is_course_prerequities_completed( ...$a ) { return true; }
  }
}
