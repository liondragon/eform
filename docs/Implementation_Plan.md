# Declined Submission Review Log + WP Admin Dashboard

## Summary
Build a default-off declined-submission monitoring mode that stores submitted content for spam-review outcomes in a separate append-only JSONL file family, then renders it in a presentable WordPress admin table. This is a Full Plan per `Implementation_Plan_Guide.md`: it adds persistence, config, GC/uninstall behavior, and a user-facing admin surface.

Scope: add monitoring for declined submissions only; do not add form-builder/settings admin UI, DB persistence, moderation state, resend/approve, or per-record deletion.

Source of Truth: AI conversation plus the spec update required in EFORMS-FEAT-010. Current spec conflict to resolve first: `docs/Canonical_Spec.md` says “No admin UI”; this feature must narrow that invariant to “no form-builder/settings admin UI; declined-review viewer is the only admin surface.”

Host Contracts:
- WordPress admin primitives: `admin_menu`, `manage_options`, admin table/list markup, request sanitization, and escaping helpers.
- Existing file-backed runtime model under `${uploads.dir}/eforms-private/`; no DB schema or WP-Cron.
- Existing logging remains metadata-first; declined review content must not enter `Logging::event()`.

Verification Baseline:
- Automated harness: existing PHP unit/integration/smoke tests and `php eforms/tests/wp-runtime/run.php`.
- Manual residue: visual review of the WP admin screen in a real WordPress admin is recommended, but automated HTML/capability/filter tests are required before implementation is complete.

Design Preflight:
- User job: operator needs to inspect recently declined form submissions and rejection reasons so they can detect false positives during rollout.
- Decision: WP Admin list-table screen under Tools, with filters and detail view.
- Reuse contract: WordPress admin primitives (`admin_menu`, capability checks, nonce/query sanitization, admin table/list styling, escaping helpers); existing `PrivateDir`, `FileSink`, `Config`, `Entropy`, `SubmitHandler`, and GC patterns.
- Do not build: raw text log dump, custom app shell, per-record deletion, approve/resend workflow, DB table, upload-file retention.

## Public Interfaces
- Add config domain:
  - `declined_review.enable` bool, default `false`.
  - `declined_review.retention_days` int|null, default `null`; materialized at config bootstrap to `logging.retention_days`, then clamped with existing retention anchors.
- Add protected storage:
  - `${uploads.dir}/eforms-private/declined/declined-YYYYMMDD.jsonl`
  - rotated/pruned with `FileSink`-style mechanics.
- Add admin screen:
  - Tools → “eForms Declined”
  - Requires `manage_options`.
  - Filters: date range, form ID, decision/reason.
  - Defaults and limits: most recent `[DECLINED_REVIEW_ADMIN_DEFAULT_DAYS]`; maximum date window `[DECLINED_REVIEW_ADMIN_MAX_DAYS]`; maximum scanned records `[DECLINED_REVIEW_SCAN_MAX_RECORDS]`; table page size `[DECLINED_REVIEW_PAGE_SIZE]`.
  - Table columns: time, form, decision, reasons, IP, field preview, request ID.
  - Detail view shows submitted declared fields and upload metadata only.
- Declined review records include: `review_id`, timestamp, form ID, submission ID, request ID, decision code, `decision_phase`, `value_stage`, soft reasons/count/threshold, honeypot/challenge metadata, privacy-processed IP, filtered URI, declared submitted fields, upload metadata.
- `review_id` is generated with `Entropy` and is unique per write; duplicate records are allowed, and the admin table groups/sorts by submission ID + decision code rather than treating `review_id` as an idempotency key.
- Never store protocol/security fields: token, instance ID, timestamp, mode, honeypot value, Turnstile/provider tokens.
- Capture stages:
  - Honeypot: metadata only in v1 (`value_stage=metadata_only`) because it fires before validation/coercion on raw attacker-controlled input.
  - Threshold spam: declared field content captured as bounded raw values (`value_stage=raw_declared`).
  - Challenge failure: content captured only when a provider response was submitted and verification failed/timed out after validation/coercion (`value_stage=canonical` when canonical values are available); do not capture the first missing-challenge rerender.
- Payload caps: store at most `[DECLINED_REVIEW_MAX_FIELDS]` declared fields, `[DECLINED_REVIEW_FIELD_MAX_BYTES]` per scalar field, `[DECLINED_REVIEW_RECORD_FIELDS_MAX_BYTES]` total field payload per record, arrays flattened only to bounded scalar leaves, multiline text preserved after control-character stripping, binary-looking values replaced with `[binary omitted]`, and upload capture limited to field key, original safe name, size, MIME/type metadata when available, and upload error code.
- Privacy consent: `declined_review.enable=true` is the explicit content-capture consent; `logging.pii` does not control this feature. IP display still uses `privacy.ip_mode`.

## Implementation Tasks
- [ ] EFORMS-FEAT-010 Spec and plan contract for declined review
  - Type: standard + formal-spec
  - Artifacts: `docs/Canonical_Spec.md`, `docs/overview.md`, `docs/Implementation_Plan.md`, `eforms/src/Anchors.php`
  - Interfaces: `declined_review.*`, declined JSONL record shape, admin dashboard behavior, new `[DECLINED_REVIEW_*]` Anchors
  - Owner: spec/docs; runtime ownership follows tasks below
  - Depends On: none
  - Boundary Decision: update canonical docs before code; do not implement against the current “No admin UI” invariant.
  - Done When: spec defines default-off behavior, narrows “No admin UI” to exclude only form-builder/settings surfaces, defines capture scope/stages/caps/storage/admin viewer/GC/uninstall behavior, and excludes DB/quarantine/approve workflow.
  - Verified via: docs grep for `declined_review`, `Declined Review`, narrowed admin-UI invariant, and no conflicting “submitted content never logged” wording for the new review surface.
  - Reasoning: high

- [ ] EFORMS-FEAT-011 Declined review file writer and reader
  - Type: shared-ui-runtime + formal-spec
  - Artifacts: new declined-review runtime owner under `eforms/src/`, config updates, focused tests
  - Interfaces: writer API accepts form context, request, decision metadata, raw form post/files; reader API returns filtered/paginated records for admin.
  - Owner: introduce `DeclinedReviewLog`; reuse `PrivateDir`, `FileSink`, `Config`, `Entropy`, privacy IP helpers.
  - Boundary Decision: introduce new local owner; do not extend `Logging` because submitted content must stay out of operational logs; do not use DB because v1 is append-only monitoring.
  - Reuse Target: `PrivateDir`, `FileSink`, `Config::value()`, `Config::bool()`, `Entropy`, `ClientIp`.
  - No-Fallback Rule: do not write to operational JSONL logs or a database if declined-review storage is unavailable; fail the review write silently after emitting metadata-only operational warning when logging is enabled.
  - Complexity Budget: one writer/reader owner, one file family, no indexes, no per-record mutation.
  - Depends On: EFORMS-FEAT-010
  - Done When: enabled mode writes one JSONL record per captured declined event with the defined caps/stages; disabled mode writes nothing; reader enforces date-window/scan/page limits and reports when scan limits are hit.
  - Verified via: unit/integration tests for disabled/enabled writes, config materialization/clamps, field capping, protocol-field exclusion, upload metadata-only capture, rotation/pruning, malformed record tolerance, scan limits, and duplicate record tolerance.
  - Reasoning: high

- [ ] EFORMS-FEAT-012 Submission capture hooks for spam-review outcomes
  - Type: standard + formal-spec
  - Artifacts: `SubmitHandler`, `Honeypot`/challenge-spam paths as needed, integration tests
  - Interfaces: capture token-valid honeypot metadata only, soft-threshold spam fail raw declared content, and provider-response challenge failure/timeout after validation/coercion; exclude missing-challenge rerenders, token, throttle, validation, storage, ledger, and email failures.
  - Owner: `SubmitHandler` owns orchestration; `DeclinedReviewLog` owns persistence.
  - Depends On: EFORMS-FEAT-011
  - Done When: spam-review declines still short-circuit normal delivery, cleanup, ledger burn, and public response semantics exactly as before; first missing-challenge POST does not create a review record; provider failure does.
  - Verified via: integration tests for honeypot metadata-only, threshold spam raw-declared content, challenge provider failure canonical content, missing-challenge no-capture, and negative tests for throttle/token/validation.
  - Reasoning: high

- [ ] EFORMS-FEAT-013 WP Admin declined dashboard
  - Type: ui-ownership + formal-spec
  - Artifacts: admin controller/table renderer, bootstrap admin hook, WP-runtime/test stubs as needed
  - Interfaces: Tools page with date-range/form/reason filters, list table, safe detail view, empty/error states.
  - Owner: new admin owner; bootstrap only registers hooks.
  - Boundary Decision: introduce a small admin owner; do not place admin rendering in `SubmitHandler` or `Logging`, and do not introduce a custom frontend framework.
  - Reuse Target: WordPress admin hooks, `manage_options`, core admin classes/markup where available, escaping and sanitization helpers.
  - No-Fallback Rule: if capability check fails, render nothing and return a standard permission failure; never expose raw paths or JSONL filenames through query parameters.
  - Depends On: EFORMS-FEAT-011
  - Done When: dashboard is a polished WP-admin table, not a text dump; filters are sanitized; detail links use stable `review_id` plus date/form filters, not file paths; all submitted values are escaped; missing files/empty ranges/scan-limit hit show normal WP admin notices; unauthorized users cannot access it.
  - Verified via: PHP tests for hook registration, `manage_options` guard on list and detail routes, filter parsing, no raw path exposure, table/detail HTML escaping, empty state, scan-limit notice, and malformed record handling.
  - Reasoning: high

- [ ] EFORMS-FEAT-014 GC, uninstall, and verification closure
  - Type: standard + formal-spec
  - Artifacts: GC runner, uninstall purge behavior, owner docs if needed
  - Interfaces: `wp eforms gc` prunes declined review files older than `declined_review.retention_days`; `install.uninstall.purge_logs=true` removes declined review logs.
  - Owner: `GcRunner` for scheduled cleanup; uninstall owns uninstall cleanup.
  - Boundary Decision: treat declined review files as log-like runtime artifacts for purge, but keep their runtime owner separate from operational logging.
  - Contract Carriers to Re-evaluate: `docs/Architecture_Router.md`, `docs/Owner_Index.md`, GC tests, uninstall tests, config tests, and WP-runtime admin hook stubs.
  - Guard Strategy: grep for declined-review writes outside `DeclinedReviewLog` and submitted-content writes through `Logging::event()`.
  - Depends On: EFORMS-FEAT-011
  - Done When: retention works through existing GC flow; uninstall treats declined review files as log-like artifacts; owner/router docs are updated if new admin/runtime owners are introduced.
  - Verified via: GC dry-run/apply tests, uninstall purge tests, owner/router grep checks, syntax checks, full PHP test suite, WP runtime smoke.
  - Reasoning: high

## Test Plan
- Targeted:
  - config defaults/schema/clamps for `declined_review.*`
  - declined writer/reader JSONL tests
  - spam threshold raw-declared, honeypot metadata-only, provider challenge-failure canonical, and missing-challenge no-capture tests
  - negative tests for disabled mode, token failure, throttle, validation errors, protocol-field leakage, and submitted-content leakage through `Logging::event()`
  - admin table/detail rendering, capability, scan-limit, no-path-exposure, and escaping tests
  - GC/uninstall tests
- Final verification:
  - `find eforms/src eforms/templates eforms/tests -name '*.php' -print0 | xargs -0 -n1 php -l`
  - `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`
  - `php eforms/tests/wp-runtime/run.php`
  - `php eforms/tests/tools/assert-template-slugs.php`
  - `git diff --check`

## Known Debt & Open Questions
- Daily email summary remains out of scope; add a later plan only after the dashboard proves useful.
- DB-backed moderation remains intentionally rejected for v1; revisit only if operators need search, long retention, or approve/resend workflow.
- Real WordPress admin visual polish still needs human review because the current automated harness can verify markup and permissions but not final admin styling.

## Assumptions
- Storage model is separate JSONL, not existing operational logs and not DB.
- Capture scope is spam-review only: token-valid honeypot metadata, soft-threshold spam fail content, and provider-response challenge failure content.
- Uploads are metadata-only in v1; rejected file bytes are not retained.
- No individual delete, approve, resend, moderation status, or daily email digest in this plan.
- Privacy is intentionally weaker only inside default-off declined review mode; normal operational logging remains metadata-first and `logging.pii` does not authorize declined content capture.
