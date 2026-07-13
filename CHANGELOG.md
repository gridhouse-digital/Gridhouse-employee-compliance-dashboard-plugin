# Changelog

All notable changes to the Gridhouse Employee Compliance Dashboard are documented here.

## [1.4.4]

### Fixed (architecture-review findings)

- **Cached page loads are query-free again.** The packet button's
  `has_certificates()` check re-ran the LearnDash course loop on every render —
  after v1.4.3 moved the button into the status cards (which are on the live
  dashboard page), this partially undid the v1.2.0 caching win. The snapshot
  now stores `packet_certificate_count` and the button reads it.
- **First-ever save of a setting now busts caches.** WordPress fires
  `add_option_{name}` (not `update_option_{name}`) the first time a
  not-yet-persisted option is saved, so the bust hooks never fired on first
  save. Both hooks are now registered per option — here and in the admin
  plugin, which had the identical latent bug.
- **Cancelling a packet build no longer locks the user out.** The
  one-job-per-user lock used to reject new builds for up to 5 minutes after a
  cancelled modal. `ajax_init` now deletes the abandoned job (which also
  terminates any still-running fetch loop) and starts fresh.
- **Elementor pages containing only `[employee_certificate_packet]`** are now
  detected by the asset-enqueue gate (the substring check matched
  `employee_compliance` only).

### Housekeeping

- Removed dead `resolve_due_date()` (unused since the v1.1 cycle logic).
- Added `uninstall.php` (removes plugin-owned options, snapshot caches, and
  packet locks; leaves the shared due-date option and announcement posts).
- Added `.gitattributes` pinning CRLF (golden-output tests depend on stable
  line endings inside ob_start template blocks).
- `load_plugin_textdomain()` is now called so translations can actually load.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2]

### Fixed — traffic-light dots never rendered on LearnDash profile pages

- Two stacked bugs, present since the feature shipped in v1.1.0:
  1. The Profile page uses the `learndash/ld-profile` **block** (not a
     dashboard shortcode), so `page_uses_dashboard()` was false and
     `dashboard.css` — including all traffic-light rules — was never enqueued
     there at all.
  2. Even where the stylesheet did load, the dot colors referenced custom
     properties defined only on the `.ghca-ecd` scope, and LearnDash profile
     markup is not inside that wrapper — the `var()` lookups resolved to
     nothing and the dots rendered transparent.
- Fix: traffic-light rules moved to a new self-contained
  `assets/traffic-light.css` that defines its tokens on `.ghca-traffic-light`
  itself (values mirror the dashboard palette; the amber stays brand-aware via
  `var(--ghca-accent, #f3ab12)`). It is enqueued on any page using the
  `[ld_profile]` shortcode, the `learndash/ld-profile` block, or an Elementor
  layout containing `ld_profile` — deliberately without the `ghca-ecd-page`
  body class, so the dashboard's page-width overrides do not leak onto
  profile pages.
- The previously unstyled `incomplete` state now gets a neutral grey dot
  (`#9aa3ac`) — with the dot base style finally loading, an uncolored ring
  would have looked broken.
- Verified on the live site: Profile page (ID 32) enqueues only
  traffic-light.css; the Elementor dashboard page (ID 2264) enqueues only
  dashboard.css; all four dot states compute to the exact palette colors
  outside any `.ghca-ecd` wrapper.

## [1.4.1]

### Changed — design-token adoption (zero visual change)

- `assets/dashboard.css` now uses the admin plugin's `--ent-*` design-token
  system. Brand tokens read the branding contract vars with fallbacks
  (`--ent-brand: var(--ghca-primary, #176cad)` …); status/surface/layout
  tokens carry the employee palette's existing values unchanged.
- The six branding-contract names (`--ghca-primary`, `--ghca-primary-dark`,
  `--ghca-primary-soft`, `--ghca-secondary`, `--ghca-accent`, `--ghca-ring`)
  stay raw values — `GHCA_Dashboard_Branding`'s inline CSS overrides them at
  runtime; aliasing them would create circular `var()` references.
- All other old `--ghca-*` names remain defined as aliases of their `--ent-*`
  equivalents (PHP inline styles and the traffic-light rules reference them).
- Verified: computed styles of all 583 rendered dashboard elements × 29 CSS
  properties are identical before/after, both with the live branding inline
  CSS applied and with it absent; branding recolor confirmed to flow through
  the new tokens (button gradient + progress ring).

## [1.4.0]

### Added — Self-service certificate packet

- **"Download my certificate packet"**: a logged-in employee can download a
  single merged PDF containing a summary cover page (course / completed on /
  valid until table, compliance status, cycle due date) followed by every
  certificate they have earned. Button appears in the welcome card and via a
  new `[employee_certificate_packet]` shortcode — both render nothing when no
  certificates exist yet.
- Client-driven async build with a progress modal (`assets/dashboard.js`, the
  plugin's first JavaScript): init → fetch one certificate at a time → merge →
  download, adapted from the admin plugin's packet builder.
- **Reuses the admin plugin's PDF machinery** (`GHCA_Audit_PDF_Jobs` job store
  with hardened upload dirs and GC; TCPDF/FPDI loading and page merging from
  `GHCA_Audit_PDF`) as a soft dependency: when the admin plugin is inactive
  the button, endpoints, and script are simply absent — no fatals.
- **Strictly self-scoped security**: own nonce (`ghca_ecd_pkt`); no user id is
  ever read from the request; jobs are created with owner = target = the
  current user and tagged `tracker: employee`; every phase re-validates
  ownership *and* self-scope (an admin-created audit job cannot be replayed
  through these endpoints, and vice versa). One active build per user
  (5-minute lock) prevents request stacking.
- **ABORT policy inherited from the admin packet**: any failed certificate
  fetch or merge deletes the whole job — a packet is never produced with a
  certificate silently missing.
- Test: `php tests/test-cert-packet-guard.php` (job-id validation, manifest
  owner/expiry, self-scope matrix). Live-verified: bad nonce rejected,
  cross-user job replay rejected, double-click rate-limited, abort cleans the
  job and releases the lock.

## [1.3.1]

### Added

- **Cycle start groups setting** (`ghca_ecd_start_group_ids`). The LearnDash
  group IDs used to detect an employee's platform start date were hardcoded
  (`54`, `1428`); they are now a checkbox list on the settings page. The
  historical IDs remain the default until the option is first saved, so
  existing sites see no behavior change. Clearing every checkbox falls back to
  the account registration date only. Also filterable via
  `ghca_ecd_start_group_ids`. Saving the option busts all snapshot caches.
- Test: `php tests/test-start-groups.php` (sanitizer + default-vs-cleared
  resolution).

### Docs

- README: documented all three settings and the test harness; admin plugin
  README version corrected (was stale at 1.1.0 while the plugin is 1.2.0).

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
