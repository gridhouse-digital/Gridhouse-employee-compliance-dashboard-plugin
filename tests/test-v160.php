<?php
// Run: php tests/test-v160.php
// 1.6.0 logic: required actions, per-course due chips, packet blocking reasons, role badge, initials, policies course.

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); } }

require_once __DIR__ . '/bootstrap.php';

$failures = 0;
function check( string $label, bool $ok ): void {
  global $failures;
  echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
  if ( ! $ok ) { ++$failures; }
}
$keys = static function ( array $items ): array { return array_column( $items, 'key' ); };

// --- 6. Required actions: every item completable --------------------------------------------------------------
$base = array( 'all_complete' => false, 'annual_training_complete' => false, 'certificate_url' => '', 'new_hire' => array() );
$a = GHCA_ECD_Shortcodes::build_action_items( $base );
check( 'no "Contact admin" task', ! in_array( 'contact', $keys( $a ), true ) );
check( 'no policies course configured => policies item hidden', ! in_array( 'policies', $keys( $a ), true ) );
$a = GHCA_ECD_Shortcodes::build_action_items( $base + array( 'policies' => array( 'course_id' => 42, 'completed' => false ) ) );
$pol = array_values( array_filter( $a, static function ( $i ) { return 'policies' === $i['key']; } ) );
check( 'policies course set, not started => item shown, not done', 1 === count( $pol ) && false === $pol[0]['done'] );
$a = GHCA_ECD_Shortcodes::build_action_items( $base + array( 'policies' => array( 'course_id' => 42, 'completed' => true ) ) );
$pol = array_values( array_filter( $a, static function ( $i ) { return 'policies' === $i['key']; } ) );
check( 'policies course completed => item done', true === $pol[0]['done'] );
$a = GHCA_ECD_Shortcodes::build_action_items( array( 'all_complete' => true, 'certificate_url' => 'x', 'new_hire' => array( 'active' => true, 'complete' => false, 'deadline_days' => 30 ), 'policies' => array( 'course_id' => 4, 'completed' => true ) ) );
check( 'every item can be done (all done when everything is complete)', count( $a ) === count( array_filter( array_column( $a, 'done' ) ) ) );

// --- 6. Policies course resolution -------------------------------------------------------------------------
check( 'saved policies course wins', array( 'course_id' => 9, 'source' => 'setting' ) === GHCA_ECD_Settings::pick_policies_course( 9, array( 3 => 'Company Policies' ) ) );
check( 'fallback: title containing "Polic"', array( 'course_id' => 3, 'source' => 'title' ) === GHCA_ECD_Settings::pick_policies_course( 0, array( 1 => 'Fatal Five', 3 => 'Company POLICY review' ) ) );
check( 'no match => none', array( 'course_id' => 0, 'source' => '' ) === GHCA_ECD_Settings::pick_policies_course( 0, array( 1 => 'Fatal Five' ) ) );

// --- 7. Per-course due dates and chips ---------------------------------------------------------------------
$now = 1800000000;
$day = 86400;
check( 'past due => Overdue', 'overdue' === GHCA_ECD_Redesign::course_chip( false, $now - $day, 0, $now )[0] );
check( 'overdue beats locked', 'overdue' === GHCA_ECD_Redesign::course_chip( true, $now - $day, 0, $now )[0] );
check( 'prerequisites unmet => Locked', 'locked' === GHCA_ECD_Redesign::course_chip( true, $now + 90 * $day, 0, $now )[0] );
check( 'due within 30 days => Due soon', 'duesoon' === GHCA_ECD_Redesign::course_chip( false, $now + 10 * $day, 0, $now )[0] );
check( 'due later, started => In progress', 'progress' === GHCA_ECD_Redesign::course_chip( false, $now + 90 * $day, 30, $now )[0] );
check( 'due later, not started => Not started (never Locked)', 'notstarted' === GHCA_ECD_Redesign::course_chip( false, $now + 90 * $day, 0, $now )[0] );
$own = GHCA_ECD_Redesign::course_due( array( 'expiration_ts' => $now + 5 * $day ), array( 'due_ts' => $now + 200 * $day ), $now );
check( 'course with its own date uses it', $now + 5 * $day === $own['ts'] && 'duesoon' === $own['chip'][0] );
$fb = GHCA_ECD_Redesign::course_due( array( 'expiration_ts' => 0 ), array( 'due_ts' => $now + 200 * $day ), $now );
check( 'course without its own date falls back to the cycle date', $now + 200 * $day === $fb['ts'] );
$late = GHCA_ECD_Redesign::course_due( array(), array( 'due_ts' => $now - $day ), $now );
check( 'overdue label says "Was due"', 0 === strpos( $late['label'], 'Was due' ) );

// --- 5. Packet blocking reasons ------------------------------------------------------------------------
$courses = array(
  array( 'id' => 1, 'title' => 'NEW HIRE ORIENTATION', 'completed' => true, 'has_certificate' => true, 'url' => 'u1' ),
  array( 'id' => 2, 'title' => 'FATAL FIVE', 'completed' => false, 'has_certificate' => true, 'url' => 'u2' ),
  array( 'id' => 3, 'title' => 'INDIVIDUAL RIGHTS', 'completed' => false, 'has_certificate' => true, 'url' => 'u3' ),
  array( 'id' => 4, 'title' => 'CPR', 'completed' => true, 'has_certificate' => true, 'compliance_state' => 'expired', 'expiration_label' => 'Oct 1, 2026', 'url' => 'u4' ),
);
$mappings = array( 3 => array( 'odp_category' => 'individual_rights', 'coverage' => array( 'individual_rights' ) ) );
$certs    = array( 1 => 'https://x/cert/1', 4 => 'https://x/cert/4' );
$clear    = array( 'total_annual_hrs' => 26, 'category_statuses' => array( 'individual_rights' => 'Completed' ) );
$ok       = array( $courses[0], $courses[1] );
check( 'no blockers => empty list ("You\'re all set")', array() === GHCA_ECD_Redesign::blocking_reasons( $clear, 'annual', $ok, $mappings, $certs ) );
$r = GHCA_ECD_Redesign::blocking_reasons( array( 'total_annual_hrs' => 10.5, 'category_statuses' => array() ), 'annual', $ok, $mappings, $certs );
check( 'hours short => "X of 24 hours" with a course link', 1 === count( $r ) && false !== strpos( $r[0]['text'], '10.5 of 24' ) && 'u2' === $r[0]['url'] );
$r = GHCA_ECD_Redesign::blocking_reasons( array( 'total_annual_hrs' => 30, 'category_statuses' => array( 'individual_rights' => 'Missing training', 'person_centered' => 'Needs review' ) ), 'annual', $courses, $mappings, $certs );
$topic = array_values( array_filter( $r, static function ( $x ) { return 'topic:individual_rights' === $x['key']; } ) );
check( 'missing topic => links to the mapped course', 1 === count( $topic ) && 'u3' === $topic[0]['url'] );
check( '"Needs review" is the agency\'s step, not listed', ! in_array( 'topic:person_centered', array_column( $r, 'key' ), true ) );
check( 'expired certificate listed with retake link', in_array( 'expired:4', array_column( $r, 'key' ), true ) );
$r = GHCA_ECD_Redesign::blocking_reasons( array( 'new_hire_program' => array( 'active' => true, 'complete' => false, 'outstanding' => array( array( 'course_id' => 2, 'title' => 'FATAL FIVE' ) ) ) ), 'orientation', $ok, array(), $certs );
check( 'orientation not complete => continue the outstanding course', 'orientation' === $r[0]['key'] && 'u2' === $r[0]['url'] );
$r = GHCA_ECD_Redesign::blocking_reasons( array(), 'annual', array( array( 'id' => 7, 'title' => 'CPR', 'completed' => true, 'has_certificate' => true, 'url' => 'u7' ) ), array(), array( 7 => '' ) );
check( 'completed course with no certificate => "Missing required certificate"', 'nocert:7' === ( $r[0]['key'] ?? '' ) );
check( 'calculator unavailable => only this plugin\'s own facts', array() === GHCA_ECD_Redesign::blocking_reasons( array(), 'annual', $ok, array(), $certs ) );

// --- Title case keeps acronyms --------------------------------------------------------------------------
check( 'acronyms stay capitalised', 'Documentation Essentials for Healthcare and IDD Services' === GHCA_ECD_Redesign::title_case( 'DOCUMENTATION ESSENTIALS FOR HEALTHCARE AND IDD SERVICES' ) );
check( 'acronym inside brackets', 'Annual Training (ODP)' === GHCA_ECD_Redesign::title_case( 'ANNUAL TRAINING (ODP)' ) );

// --- 9. Role badge and initials --------------------------------------------------------------------------
$names = array( 'bbp_keymaster' => 'Keymaster', 'subscriber' => 'Subscriber', 'ghca_custom_dsp' => 'DSP', 'nurse' => 'LPN', 'editor' => 'Editor' );
check( 'forum role (bbp_keymaster) never shown', '' === GHCA_ECD_Redesign::role_label_from( array( 'bbp_keymaster' ), $names ) );
check( 'job role shown even after a forum role', 'DSP' === GHCA_ECD_Redesign::role_label_from( array( 'bbp_keymaster', 'subscriber', 'ghca_custom_dsp' ), $names ) );
check( 'known GHCA job role wins over other roles', 'LPN' === GHCA_ECD_Redesign::role_label_from( array( 'ghca_custom_dsp', 'nurse' ), $names, array( 'nurse' ) ) );
check( 'no job role => no badge', '' === GHCA_ECD_Redesign::role_label_from( array( 'subscriber', 'editor' ), $names ) );
check( 'initials from first + last name', 'AO' === GHCA_ECD_Redesign::name_initials( 'Adebola', 'Oyinlola', 'manueltech', 'manueltech' ) );
check( 'initials never from the username', '' === GHCA_ECD_Redesign::name_initials( '', '', 'manueltech', 'manueltech' ) );
check( 'initials from a real display name', 'AO' === GHCA_ECD_Redesign::name_initials( '', '', 'Adebola Oyinlola', 'manueltech' ) );

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}
echo "\nAll tests passed.\n";
