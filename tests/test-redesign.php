<?php
// Run: php tests/test-redesign.php
// Pure logic behind the redesigned screens and the announcement audience rule.

// Minimal WordPress stand-ins for the functions these paths call.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); } }
if ( ! function_exists( 'get_user_meta' ) ) { function get_user_meta( ...$a ) { return ''; } }
// Course 11 and 12 award certificates; course 13 does not.
if ( ! function_exists( 'learndash_get_setting' ) ) {
  function learndash_get_setting( $id, $key ) { return 'certificate' === $key && in_array( (int) $id, array( 11, 12, 14 ), true ) ? 99 : 0; }
}
// Only completed course 11 has a certificate link.
if ( ! function_exists( 'learndash_get_course_certificate_link' ) ) {
  function learndash_get_course_certificate_link( $course_id, $user_id ) { return 11 === (int) $course_id ? 'https://example.test/cert/11' : ''; }
}

require_once __DIR__ . '/bootstrap.php';

$failures = 0;

function check( string $label, bool $ok ): void {
  global $failures;
  if ( $ok ) {
    echo "PASS  {$label}\n";
  } else {
    ++$failures;
    echo "FAIL  {$label}\n";
  }
}

// --- Announcement audience (admin plugin's ghca_announce_audience) -----------
$vis = array( 'GHCA_ECD_Shortcodes', 'announcement_visible_to_employee' );
check( 'no stored audience (older announcements) => visible', $vis( '', 0, array() ) );
check( '"all" => visible', $vis( 'all', 0, array() ) );
check( '"admins" => never visible to employees', ! $vis( 'admins', 0, array( 5 ) ) );
check( '"group" in one of my groups => visible', $vis( 'group', 5, array( 3, 5 ) ) );
check( '"group" not in my groups => hidden', ! $vis( 'group', 7, array( 3, 5 ) ) );
check( '"group" with no group id => hidden', ! $vis( 'group', 0, array( 0 ) ) );
check( '"group" when I have no groups => hidden', ! $vis( 'group', 5, array() ) );
check( 'group ids given as strings still match', $vis( 'group', 5, array( '5' ) ) );
check( 'unknown audience value => hidden', ! $vis( 'staff', 0, array() ) );

// --- Theme resolution -----------------------------------------------------
check( 'saved theme wins over the admin theme', 'purple' === GHCA_ECD_Redesign::resolve_theme( 'purple', 'akansoh' ) );
check( 'empty saved theme follows the admin dashboard', 'akansoh' === GHCA_ECD_Redesign::resolve_theme( '', 'akansoh' ) );
check( 'unknown values fall back to gridhouse', 'gridhouse' === GHCA_ECD_Redesign::resolve_theme( 'neon', 'neon' ) );
check( 'no admin plugin => gridhouse', 'gridhouse' === GHCA_ECD_Redesign::resolve_theme( '', '' ) );

// --- Initials and title case -------------------------------------------------
check( 'initials: first and last word', 'JE' === GHCA_ECD_Redesign::initials( 'Jordan Ellis' ) );
check( 'initials: single name', 'J' === GHCA_ECD_Redesign::initials( 'jordan' ) );
check( 'initials: middle names skipped', 'AO' === GHCA_ECD_Redesign::initials( 'Ada  Mary  Obi' ) );
check( 'initials: empty', '' === GHCA_ECD_Redesign::initials( '   ' ) );
check( 'title case for upper-cased course titles', 'Prevention of Abuse and Neglect' === GHCA_ECD_Redesign::title_case( 'PREVENTION OF ABUSE AND NEGLECT' ) );
check( 'title case keeps first small word capitalised', 'A New Hire Orientation' === GHCA_ECD_Redesign::title_case( 'A NEW HIRE ORIENTATION' ) );
check( 'title case capitalises after hyphens', 'Person-Centered Practices & Community Integration' === GHCA_ECD_Redesign::title_case( 'PERSON-CENTERED PRACTICES & COMMUNITY INTEGRATION' ) );
check( 'mixed-case titles are left alone', 'Fatal Five: iPad edition' === GHCA_ECD_Redesign::title_case( 'Fatal Five: iPad edition' ) );

// --- Role badge -----------------------------------------------------------------
$names = array( 'caregiver' => 'Caregiver', 'ghca_custom_dsp' => 'Direct Support Professional (DSP)', 'subscriber' => 'Subscriber' );
check( 'role badge: display name of the job role', 'Caregiver' === GHCA_ECD_Redesign::role_label_from( array( 'caregiver' ), $names ) );
check( 'role badge: custom agency role', 'Direct Support Professional (DSP)' === GHCA_ECD_Redesign::role_label_from( array( 'ghca_custom_dsp' ), $names ) );
check( 'role badge: generic roles are skipped for the job role', 'Caregiver' === GHCA_ECD_Redesign::role_label_from( array( 'subscriber', 'caregiver' ), $names ) );
check( 'role badge: only a generic role => no badge', '' === GHCA_ECD_Redesign::role_label_from( array( 'subscriber' ), $names ) );
check( 'role badge: no roles => no badge', '' === GHCA_ECD_Redesign::role_label_from( array(), $names ) );

// --- Status chip ---------------------------------------------------------------
$chip = array( 'GHCA_ECD_Redesign', 'status_chip' );
check( 'new hire overdue => overdue chip', array( 'overdue', 'Overdue' ) === $chip( array( 'training_status' => 'new_hire_overdue' ) ) );
check( 'expired => overdue chip labelled Expired', array( 'overdue', 'Expired' ) === $chip( array( 'training_status' => 'expired' ) ) );
check( 'expiring soon => duesoon chip', 'duesoon' === $chip( array( 'training_status' => 'expiring_soon' ) )[0] );
check( 'compliant => completed chip', array( 'completed', 'Compliant' ) === $chip( array( 'training_status' => 'compliant' ) ) );
check( 'new hire at risk => Due soon', array( 'duesoon', 'Due soon' ) === $chip( array( 'training_status' => 'in_progress', 'new_hire' => array( 'at_risk' => true ) ) ) );
check( 'in progress => progress chip', 'progress' === $chip( array( 'training_status' => 'in_progress' ) )[0] );
check( 'anything else => not started', 'notstarted' === $chip( array( 'training_status' => 'not_started' ) )[0] );

// --- Certificates: earned vs still to earn -----------------------------------
$courses = array(
  array( 'id' => 11, 'title' => 'NEW HIRE ORIENTATION', 'completed' => true, 'completed_ts' => 1793232000, 'compliance_state' => 'current', 'expiration_label' => '', 'progress' => 100, 'url' => 'https://example.test/c/11' ),
  array( 'id' => 12, 'title' => 'FATAL FIVE', 'completed' => false, 'progress' => 40, 'url' => 'https://example.test/c/12' ),
  array( 'id' => 13, 'title' => 'NO CERT COURSE', 'completed' => false, 'progress' => 0, 'url' => 'https://example.test/c/13' ),
  array( 'id' => 14, 'title' => 'DONE BUT NO LINK', 'completed' => true, 'completed_ts' => 1793232000, 'progress' => 100, 'url' => 'https://example.test/c/14' ),
);
$model = GHCA_ECD_Redesign::certificates_model( $courses, 7 );
check( 'one earned certificate (completed with a link)', 1 === count( $model['earned'] ) );
check( 'earned title is title-cased', 'New Hire Orientation' === ( $model['earned'][0]['title'] ?? '' ) );
check( 'earned links to the certificate', 'https://example.test/cert/11' === ( $model['earned'][0]['url'] ?? '' ) );
check( 'earned without expiry says no expiry', false !== strpos( (string) ( $model['earned'][0]['meta'] ?? '' ), 'no expiry' ) );
check( 'earned chip is Earned', array( 'completed', 'Earned' ) === ( $model['earned'][0]['chip'] ?? null ) );
check( 'one locked certificate (unfinished course that awards one)', 1 === count( $model['locked'] ) );
check( 'locked row keeps course progress', 40 === ( $model['locked'][0]['progress'] ?? null ) );
check( 'course without a certificate is not listed as locked', ! in_array( 'No Cert Course', array_column( $model['locked'], 'title' ), true ) );
check( 'completed course without a link is not listed', ! in_array( 'Done but No Link', array_column( $model['earned'], 'title' ), true ) );

$expiring = GHCA_ECD_Redesign::certificates_model(
  array( array( 'id' => 11, 'title' => 'CPR', 'completed' => true, 'completed_ts' => 1793232000, 'compliance_state' => 'expiring_soon', 'expiration_label' => 'Dec 1, 2026' ) ),
  7
);
check( 'expiring certificate => Expiring soon chip with date', 'duesoon' === $expiring['earned'][0]['chip'][0] && false !== strpos( $expiring['earned'][0]['meta'], 'Dec 1, 2026' ) );

if ( $failures > 0 ) {
  echo "\n{$failures} test(s) failed.\n";
  exit( 1 );
}

echo "\nAll tests passed.\n";
