# Changelog

All notable changes to the Gridhouse Employee Compliance Dashboard are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
