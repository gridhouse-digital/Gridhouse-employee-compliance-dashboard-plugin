# Changelog

All notable changes to the Gridhouse Employee Compliance Dashboard are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0]

### Changed

- **Architecture split (no behavior change).** The single-file god class was
  decomposed following the admin plugin's Phase-6 recipe:
  - `includes/class-data-provider.php` — `GHCA_ECD_Data_Provider`: snapshot,
    required courses, compliance cycle, notification, new-hire mapping,
    certificate URL lookup, and all caching/bust logic.
  - `includes/class-shortcodes.php` — `GHCA_ECD_Shortcodes`: all 11 shortcode
    renderers, action items, quick links, announcements.
  - `includes/class-settings.php` — `GHCA_ECD_Settings`: settings page,
    sanitizers, option accessors, option-save bust hooks.
  - The main plugin file is now a slim bootstrap (constants, asset enqueue,
    page detection, traffic-light filter) plus `bust_cache`/`bust_all_caches`
    delegating wrappers kept for the admin plugin's cross-plugin calls.
- Verified byte-identical shortcode output before vs after the split
  (sha1-matched golden render of all 11 shortcodes for a real user).
- New smoke test: `php tests/test-split-smoke.php`.

## [1.2.0]

### Performance

- **Per-request memoization.** `get_required_courses()` and
  `get_compliance_notification()` are now memoized per user for the duration of
  the request. Previously the full per-course LearnDash query loop ran 3–4×
  per page load (snapshot build, annual-training check, and the notification
  banner each recomputed it independently).
- **Persistent snapshot cache.** The assembled dashboard snapshot is stored in
  a transient keyed `ghca_ecd_snap_{version}_{user}_{Ymd}` (mirrors the admin
  dashboard's aggregate cache). The date stamp self-invalidates at site-timezone
  midnight so day-based countdowns and traffic lights never go stale overnight.
  Measured locally: 46 queries on a cold render → 0 on a warm render, with
  byte-identical HTML.
- New setting **Dashboard cache lifetime** (`ghca_ecd_cache_ttl`, default 600 s,
  clamp 0–3600, 0 disables caching).
- The notification banner shortcode now reads from the snapshot instead of
  recomputing, so cached page loads issue no LearnDash queries at all.

### Added

- **Cache busting** on `learndash_course_completed`,
  `learndash_update_course_access`, `ld_added_group_access` /
  `ld_removed_group_access`, and on saves of the due-date, cache-TTL,
  course-lifespan, warning-days, and new-hire options.
- Cross-plugin bust: the admin dashboard's Edit Records and Save Employee AJAX
  handlers (which intentionally skip LearnDash completion hooks to avoid
  notification emails) now bust the affected employee's snapshot directly.
- Plain-PHP test harness under `tests/` (`php tests/test-cache-key.php`),
  following the admin plugin's pattern.

## [1.1.0]

### Added — Phase 2: Rolling Expirations & Traffic Light

- **Traffic light on the LearnDash course table.** Hooked `learndash-course-row-class`
  to append a `ghca-traffic-light--{state}` class to each course row. CSS draws a
  colored dot on `.ld-course-title::before`: 🟢 green (`current`/`completed`/`compliant`),
  🟡 amber (`expiring_soon`), 🔴 red (`expired`/`new_hire_overdue`).
- **Rolling-expiration awareness in the KPI status card.** When the Admin Compliance
  Dashboard's `GHCA_Course_Lifespans` class is present, each required course is
  decorated with `compliance_state`, `expiration_ts`, and `expiration_label` via
  `GHCA_Course_Lifespans::decorate()`. The dashboard rolls these up (worst-wins) so
  the status card can show **Expired** or **Expiring Soon**.
- New `expired` / `expiring_soon` labels in `status_label()` and badge colors in
  `get_status_badge_class()`.
- Status-card footer copy for the `expired` and `expiring_soon` states.
- Status-card tile now changes tone and icon to match the state — red alert for
  expired/overdue, amber clock for expiring soon.
- `.ghca-ecd__pill--warning` and `.ghca-ecd__tile--danger` styles.

### Fixed

- **"In Progress" detection regression.** `get_required_courses()` was overwriting
  the LearnDash `status` field with `compliance_state` (which collapses to
  `incomplete` for all unfinished courses). This broke started-vs-not-started
  detection, the "next course" preference, and the "Resume Course" button label.
  The LearnDash status is now preserved; compliance state lives in its own
  `compliance_state` key.
- The **Expiring Soon** KPI pill rendered unstyled because `.ghca-ecd__pill--warning`
  was referenced in PHP but never defined in CSS.

### Notes

- Rolling-expiration behavior degrades gracefully: with `GHCA_Course_Lifespans`
  inactive, the dashboard falls back to plain complete / in-progress / not-started
  status and the traffic-light filter is a no-op.

## [1.0.0]

### Added

- Initial release: employee compliance home dashboard shortcodes for LearnDash +
  Elementor (welcome, KPI status cards, next required course, required actions,
  announcements, quick links, support), new-hire onboarding windows, and annual
  compliance cycle countdown.
