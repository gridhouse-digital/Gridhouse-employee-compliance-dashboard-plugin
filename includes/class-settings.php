<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/**
 * Settings page and option accessors for the employee dashboard.
 */
final class GHCA_ECD_Settings {

  public static function init(): void {
    add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
    add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );

    // Option-level changes affect every user's snapshot. Both hooks are
    // needed: WordPress fires add_option_{name} (not update_option_{name})
    // the first time a not-yet-persisted option is saved.
    $bust_options = array(
      GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE,
      GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL,
      GHCA_Employee_Compliance_Dashboard::OPTION_START_GROUPS,
    );
    if ( class_exists( 'GHCA_Course_Lifespans' ) ) {
      $bust_options[] = GHCA_Course_Lifespans::OPTION_LIFESPANS;
      $bust_options[] = GHCA_Course_Lifespans::OPTION_WARNING_DAYS;
    }
    if ( class_exists( 'GHCA_Compliance_Program' ) ) {
      $bust_options[] = GHCA_Compliance_Program::OPTION_NEW_HIRE_GROUPS;
      $bust_options[] = GHCA_Compliance_Program::OPTION_NEW_HIRE_DAYS;
    }
    foreach ( $bust_options as $option_name ) {
      add_action( 'update_option_' . $option_name, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
      add_action( 'add_option_' . $option_name, array( 'GHCA_ECD_Data_Provider', 'bust_all_caches' ) );
    }
  }

  public static function register_settings(): void {
    register_setting(
      'ghca_employee_dashboard',
      GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE,
      array(
        'type'              => 'string',
        'sanitize_callback' => array( __CLASS__, 'sanitize_due_date' ),
        'default'           => '2026-07-31',
      )
    );

    register_setting(
      'ghca_employee_dashboard',
      GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL,
      array(
        'type'              => 'integer',
        'sanitize_callback' => array( __CLASS__, 'sanitize_cache_ttl' ),
        'default'           => 600,
      )
    );

    register_setting(
      'ghca_employee_dashboard',
      GHCA_Employee_Compliance_Dashboard::OPTION_START_GROUPS,
      array(
        'type'              => 'array',
        'sanitize_callback' => array( __CLASS__, 'sanitize_start_group_ids' ),
      )
    );
  }

  /** @var array<int> Historical hardcoded groups, kept as the pre-option default. */
  const DEFAULT_START_GROUP_IDS = array( 54, 1428 );

  /** @param mixed $value @return array<int> */
  public static function sanitize_start_group_ids( $value ): array {
    if ( ! is_array( $value ) ) {
      return array();
    }

    return array_values( array_unique( array_filter( array_map( 'intval', $value ), static function ( int $id ): bool {
      return $id > 0;
    } ) ) );
  }

  /**
   * Pure resolution so the default-vs-cleared distinction is unit-testable:
   * option never saved => historical default; saved (even empty) => as saved.
   *
   * @param mixed $raw The raw get_option() value (false when unset).
   * @return array<int>
   */
  public static function resolve_start_group_ids( $raw ): array {
    if ( ! is_array( $raw ) ) {
      return self::DEFAULT_START_GROUP_IDS;
    }

    return self::sanitize_start_group_ids( $raw );
  }

  /** @return array<int> LearnDash group IDs whose enrollment stamps seed the compliance cycle start. */
  public static function get_start_group_ids(): array {
    return self::resolve_start_group_ids( get_option( GHCA_Employee_Compliance_Dashboard::OPTION_START_GROUPS, false ) );
  }

  /** @return array<int,\WP_Post> */
  private static function get_learndash_groups(): array {
    $posts = get_posts(
      array(
        'post_type'              => 'groups',
        'post_status'            => 'publish',
        'posts_per_page'         => 200,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
      )
    );

    return is_array( $posts ) ? $posts : array();
  }

  public static function sanitize_due_date( string $value ): string {
    $value = trim( $value );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
      return $value;
    }
    return '2026-07-31';
  }

  /** @param mixed $value */
  public static function sanitize_cache_ttl( $value ): int {
    return max( 0, min( 3600, (int) $value ) );
  }

  public static function get_cache_ttl(): int {
    $ttl = (int) get_option( GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL, 600 );
    return max( 0, min( 3600, $ttl ) );
  }

  public static function register_settings_page(): void {
    add_options_page(
      'Employee Compliance Dashboard',
      'Employee Dashboard',
      'manage_options',
      'ghca-employee-dashboard',
      array( __CLASS__, 'render_settings_page' )
    );
  }

  public static function render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
      return;
    }

    $due             = get_option( GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE, '2026-07-31' );
    $start_group_ids = self::get_start_group_ids();
    $groups          = self::get_learndash_groups();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Employee Compliance Dashboard', 'ghca-ecd' ); ?></h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'ghca_employee_dashboard' ); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ghca_compliance_due_date"><?php esc_html_e( 'Compliance due date', 'ghca-ecd' ); ?></label></th>
            <td><input type="date" id="ghca_compliance_due_date" name="<?php echo esc_attr( GHCA_Employee_Compliance_Dashboard::OPTION_DUE_DATE ); ?>" value="<?php echo esc_attr( $due ); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Cycle start groups', 'ghca-ecd' ); ?></th>
            <td>
              <?php if ( empty( $groups ) ) : ?>
                <p><?php esc_html_e( 'No LearnDash groups found.', 'ghca-ecd' ); ?></p>
              <?php else : ?>
                <fieldset>
                  <?php foreach ( $groups as $group ) : ?>
                    <label style="display:block;margin:0 0 8px;">
                      <input
                        type="checkbox"
                        name="<?php echo esc_attr( GHCA_Employee_Compliance_Dashboard::OPTION_START_GROUPS ); ?>[]"
                        value="<?php echo esc_attr( (string) $group->ID ); ?>"
                        <?php checked( in_array( (int) $group->ID, $start_group_ids, true ) ); ?>
                      />
                      <?php echo esc_html( $group->post_title ); ?>
                      <code>#<?php echo esc_html( (string) $group->ID ); ?></code>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endif; ?>
              <p class="description"><?php esc_html_e( 'The earliest enrollment date across these LearnDash groups (or the account registration date, whichever is earliest) starts each employee\'s annual compliance cycle. If none are selected, only the registration date is used.', 'ghca-ecd' ); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="ghca_ecd_cache_ttl"><?php esc_html_e( 'Dashboard cache lifetime (seconds)', 'ghca-ecd' ); ?></label></th>
            <td>
              <input type="number" id="ghca_ecd_cache_ttl" name="<?php echo esc_attr( GHCA_Employee_Compliance_Dashboard::OPTION_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) self::get_cache_ttl() ); ?>" min="0" max="3600" step="1" class="small-text" />
              <p class="description"><?php esc_html_e( 'How long each employee\'s dashboard snapshot is cached (0 disables caching, max 3600). The cache also refreshes automatically at midnight and whenever training data changes.', 'ghca-ecd' ); ?></p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }
}
