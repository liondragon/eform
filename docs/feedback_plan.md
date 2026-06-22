# Review Feedback Implementation Plan

Scope: Add an opt-in admin review workflow for submissions that were delivered after email send, so operators can label spam that got through. When enabled, every successfully delivered submission gets a plugin-owned review ID, bounded private review record, admin-only email review link, append-only review events, and optional manual Fail2ban emission for admin-confirmed spam. The admin workflow uses one `eForms Review` surface for delivered and declined records, with source tabs or filters rather than a separate sent-messages page.

Source of Truth:
- AI conversation in this thread: reject public email action buttons; add an admin-only delivered review workflow; capture all delivered submissions when enabled; carry a plugin-owned review ID in email headers/body for lookup; use one review page for delivered and declined records; keep records private, bounded, and retained for a finite period; emit Fail2ban only for manual spam labels and only when Fail2ban is configured.
- `docs/overview.md` current operator narrative for no database-backed submissions, private runtime storage, settings, logging, Fail2ban, suspect email signals, and declined review.
- `docs/Architecture_Router.md` owner routing for Submission, Email, Admin Settings, Declined Review, Logging, Runtime Storage, and diagnostics.
- `docs/Owner_Index.md` reusable owner map and anti-drift verification hooks.
- `docs/contracts/Public_Contracts.md` public surfaces, config contract, append-only error/code/log surfaces, and suspicious delivered email contract.
- `docs/contracts/Runtime_Storage.md` private storage layout, file permissions, retention, and GC contract.

Host Contracts:
- Runtime config defaults, validation, admin schema, merge precedence, provenance, and secret masking live in `src/Config.php`.
- Fixed bounds live in `src/Anchors.php`; callers must not duplicate new fixed runtime limits.
- Settings -> eForms orchestration lives in `src/Admin/SettingsAdmin.php`; field metadata and form-to-override mapping live in `src/Admin/SettingsFields.php`.
- Public POST orchestration lives in `src/Submission/SubmitHandler.php`; successful delivery capture must not alter validation, ledger, upload, or email-send semantics.
- Email assembly lives in `src/Email/Emailer.php`; review links are notification content, not public actions.
- Security-sensitive review identifiers are generated through `src/Security/Entropy.php`; email `Message-ID` is not the plugin review identifier.
- File append, dated JSONL mechanics, rotation, and pruning live in `src/Logging/FileSink.php`; private directory creation lives in `src/Uploads/PrivateDir.php`.
- Fail2ban line emission lives in `src/Logging/Fail2banLogger.php` and currently accepts only `EFORMS_ERR_*` codes.
- Stable codes live in `src/ErrorCodes.php`; public copy lives in `src/ErrorMessages.php` only when a code is rendered publicly.
- Current declined review is owned by `src/DeclinedReviewLog.php` and `src/Admin/DeclinedReviewAdmin.php`; the unified review admin task may replace the declined-only admin owner, but must not corrupt declined-review storage semantics.
- The final admin surface should be one review workflow; delivered and declined storage owners can remain separate behind the page.

Not Behavior Authority: yes. This plan is a transient execution artifact. Code, tests, and active carriers remain behavior authority.

Retirement Trigger: Retire this plan after all tasks are checked, focused verification passes, and still-valid behavior is distilled into `docs/overview.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `docs/Architecture_Router.md`, and `docs/Owner_Index.md`.

## Non-Goals

- Do not add public one-click spam/not-spam links.
- Do not use or rely on SMTP/RFC `Message-ID` as the plugin review identifier.
- Do not let a review ID directly block, ban, or mark spam; it is lookup/correlation only.
- Do not add DB-backed submission storage, moderation queues, or resend workflows.
- Do not store delivered review records when `delivered_review.enable=false`.
- Do not store forever; every record and event file must be retention-managed.
- Do not store upload contents in review records; store bounded upload metadata only.
- Do not make automatic learning, classifier retraining, or spam-rule mutation part of this plan.
- Do not automatically unban Fail2ban IPs when an admin later marks not spam.
- Do not expose writable `logging.fail2ban.file` in wp-admin in this slice; show read-only status/provenance only.
- Do not add a separate sent-messages page; use the same admin review surface as declined review, with clear source/provenance.

## Verification Baseline

Automated Harness: use focused PHP tests plus static guards. New delivered-review storage and unified review-admin tests are created by `P1.T3` and `P2.T1`; until then, the named command is the target command for execution.

Verification Command:

```bash
php tests/unit/test_config_validation.php \
  && php tests/unit/test_config_admin_api.php \
  && php tests/unit/test_config_clamps.php \
  && php tests/unit/test_error_codes_append_only.php \
  && php tests/unit/test_protocol_seam_guards.php \
  && php tests/integration/test_delivered_review_log.php \
  && php tests/integration/test_review_admin.php \
  && php tests/integration/test_admin_settings_page.php \
  && php tests/integration/test_email_headers_sanitization.php \
  && php tests/integration/test_spam_fail_threshold.php \
  && php tests/integration/test_declined_review_log.php \
  && git diff --check
```

Broad Gate: after focused green, run the canonical PHP sweep from `tests/README.md`:

```bash
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
```

Baseline Failure Snapshot:
- Baseline Commands: before implementation, run existing focused tests that already exist: `php tests/unit/test_config_validation.php && php tests/unit/test_config_admin_api.php && php tests/unit/test_config_clamps.php && php tests/unit/test_error_codes_append_only.php && php tests/unit/test_protocol_seam_guards.php && php tests/integration/test_admin_settings_page.php && php tests/integration/test_email_headers_sanitization.php && php tests/integration/test_spam_fail_threshold.php && php tests/integration/test_declined_review_log.php && git diff --check`.
- Pre-existing Red: none known at planning time; execution must record any red baseline before changing behavior.
- In-Scope Green Target: all focused commands pass, including new delivered-review storage and unified review-admin tests.
- Out-of-Scope Red: any unrelated broad-suite, dirty-worktree, or environment failure must be listed in handoff and not counted as delivered-review proof.

## Owner Discovery / Owner Reduction Pass

Existing Owner Evidence:
- `DeclinedReviewLog` already proves private JSONL review capture, bounded values, upload metadata, retention, date-window reads, and detail lookup.
- `DeclinedReviewAdmin` already proves the closest wp-admin table/detail/maintenance pattern and capability/nonce gates.
- `Config` and `SettingsFields` already own the only admin-writable settings matrix.
- `Emailer` already owns outbound notification metadata, headers, and body assembly; review IDs and links must be outbound metadata, not public action tokens.
- `Logging` and `Fail2banLogger` own operational evidence and Fail2ban emission; review storage must not write submitted content through `Logging::event()`.
- `FileSink`, `PrivateDir`, `UploadValue`, and `ClientIp` are the reusable primitives for JSONL lines, private directories, upload shape, and IP presentation/resolution.

Docs Consulted:
- `agent_docs/Implementation_Plan_Guide.md`
- `agent_docs/ui_surface_preflight.md`
- `agent_docs/Cross_Cutting_Concerns.md`
- `docs/Architecture_Router.md`
- `docs/Owner_Index.md`
- `docs/overview.md`
- `docs/contracts/Public_Contracts.md`
- `docs/contracts/Runtime_Storage.md`

Reuse Decision: introduce a delivered-review sibling storage owner, not a replacement for declined review. Reuse shared primitives and declined-review patterns, while moving the operator workflow to one review admin surface that can show delivered and declined records by source.

Boundary Decision: introduce a new delivered-review storage owner and a unified review admin owner.
- Keep local inside `SubmitHandler` is worse because persistence, bounded scans, review events, and retention would become submission-orchestration code.
- Extend `DeclinedReviewLog` directly is worse because declined records mean runtime rejection evidence, while delivered records mean successful delivery followed by later human labeling.
- Add a separate sent-messages admin page is worse because operators are doing one review job and would have to switch between pages for related evidence.
- Introduce a broad generic moderation storage layer is worse because it would force a larger storage refactor before proving the delivered workflow.
- Add a sibling delivered-review storage owner plus one review admin surface because it preserves storage semantics while avoiding UI fragmentation.

Reuse Target:
- New storage owner: `src/DeliveredReviewLog.php`.
- Unified admin owner: `src/Admin/ReviewAdmin.php`, replacing or absorbing the current declined-only admin surface.
- Existing primitives: `FileSink`, `PrivateDir`, `UploadValue`, `ClientIp`, `Config`, `Fail2banLogger`, and `ErrorCodes`.
- Existing patterns: declined-review admin list/detail/maintenance flow and settings-page field matrix.

No-Fallback Rule / Kill List:
- No DB-backed review state.
- No public action endpoint.
- No GET-triggered mark-spam action.
- No direct action from `review_id`; lookup must open an admin detail/confirmation flow.
- No reuse of `EFORMS_ERR_SPAM` for manual labels.
- No raw submitted content in normal operational logs.
- No raw upload payloads in review records.
- No writable Fail2ban path admin field in this slice.
- No duplicate file append/rotation/pruning mechanics outside `FileSink`.

New Artifact Budget:
- Add `src/DeliveredReviewLog.php`.
- Add or rename to `src/Admin/ReviewAdmin.php` as the single review admin surface.
- Add focused tests `tests/integration/test_delivered_review_log.php` and `tests/integration/test_review_admin.php`.
- Extend existing config, admin settings, email, error-code, protocol guard, and declined-review tests rather than adding parallel harness families.
- Optional shared admin/list helpers are out of scope unless execution proves net deletion and the unified review page adopts the helper in the same patch.

Owner_Index Change: add rows for delivered-review storage and unified review admin surface.

Contract Carrier Sync:
- `docs/overview.md`: add operator-facing delivered review behavior, retention, privacy note, review ID lookup, and unified admin workflow.
- `docs/contracts/Public_Contracts.md`: add the unified Tools surface, config keys, email review-link/review-ID behavior, and `EFORMS_ERR_MANUAL_SPAM` as an append-only stable machine-readable code.
- `docs/contracts/Runtime_Storage.md`: add the delivered-review private storage directory and GC target.
- `docs/Architecture_Router.md`: add delivered review storage and unified review admin boundaries.
- `docs/Owner_Index.md`: add delivered-review storage and unified review admin owner rows and verification hooks.

## UI Surface Preflight

User job: An administrator needs to find a reviewed submission from an email, compare delivered and declined evidence in one place, label delivered spam or not spam, and optionally feed confirmed spam to Fail2ban without exposing action links to anyone who receives the email.

Task class: new/material admin workflow surface.

Existing patterns checked:
- Tools -> eForms Declined: fits list/detail/private-review data density, capability gate, filters, detail view, and maintenance action; should become the base for one review page because declined and delivered review are one operator job with different source/provenance.
- Settings -> eForms: fits config controls and status/provenance rows; does not fit review workflow actions because settings pages should not become moderation queues.
- Email notification body/headers: fits as a pointer to an admin workflow and as a carrier for `X-EForms-Review-Id`; does not fit as an action surface. SMTP `Message-ID` is not stable enough to be the plugin identifier.

Options considered:
- Option A - Public or signed email action links
  - Pros: fastest marking path.
  - Cons: forwarded/quoted emails and mailbox automations can trigger or leak actions; requires public token lifecycle.
  - Reuse: none beyond email body.
  - Best when: never for this plugin.
- Option B - Extend Tools -> eForms Declined into a unified Review page
  - Pros: one review hub.
  - Cons: renames or evolves an existing stable declined surface and needs clear source/provenance labels.
  - Reuse: high; route/title migration risk must be covered by rendered admin tests.
  - Best when: delivered and declined records share list/detail mechanics but keep separate storage semantics.
- Option C - Add Tools -> eForms Delivered as a sibling review screen
  - Pros: preserves declined semantics, reuses WP-admin table/detail patterns, keeps actions admin-only.
  - Cons: adds a second Tools page when both features are enabled and fragments the review job.
  - Reuse: declined admin pattern, WP-admin tables, nonce/capability gates.
  - Best when: route stability matters more than operator workflow simplicity.

Decision: Option B - one `Tools -> eForms Review` surface with source tabs or filters for Delivered and Declined records. Keep storage owners separate; unify the operator workflow.

Reuse contract:
- Reuse the declined-review list/detail/maintenance interaction pattern.
- Use `manage_options` to match existing declined review unless a later carrier introduces a narrower capability.
- Use POST + nonce + confirmation for spam/not-spam actions.
- Use Settings -> eForms only for `delivered_review.*` controls and read-only Fail2ban status.
- Use `X-EForms-Review-Id` and a visible email review ID as lookup/correlation only.

Surface contract:
- Entry point: Tools -> eForms Review, registered when either `declined_review.enable=true` or `delivered_review.enable=true`.
- Visual hierarchy: source tabs or source filter first, review-ID lookup near filters, list second, detail view third, actions near the selected record evidence.
- Density: data-dense WP-admin table with compact field preview.
- Primary actions: Mark as spam, Mark as not spam.
- Secondary actions: clear old delivered-review data, clear old declined-review data, export current filtered labels if phase 3 is implemented.
- Permission behavior: unauthorized render returns no HTML or dies through the WordPress admin gate, matching existing admin patterns.
- Feedback: admin notices after label writes, failed nonce checks, missing records, scan limits, cleanup, and Fail2ban emission status.

Primitive map:
- Page shell: WordPress Tools page.
- Table/detail: declined-review admin pattern.
- Controls: source tabs/filter, review-ID lookup field, WP-admin filters, buttons, nonce fields, confirmation checkbox.
- Runtime/assets: no new frontend runtime.
- Local CSS/classes/selectors: minimal page/table classes only; no separate framework or custom JS.

Not shown:
- Raw upload contents.
- Raw matched terms from content-filter metadata.
- Raw IP in list/detail/export by default; raw IP is retained privately only so manual Fail2ban can work.
- Fail2ban writable file path controls.

Delete / do not build:
- No public report endpoint.
- No email one-click action button.
- No separate sent/delivered page.
- No mailbox/IMAP parser.

Verification:
- Rendered HTML tests prove list/detail/action states, escaping, nonce failure, confirmation, and absence of public action URLs.
- Static/source guards prove no public endpoint or GET action path is introduced.

## Invariant Matrix

| Invariant | Positive Proof | Negative Proof |
| --- | --- | --- |
| Delivered review captures every successful delivered submission only when enabled. | `tests/integration/test_delivered_review_log.php` submits a successful delivery with enabled config and finds one delivered record. | Same test proves disabled config, validation failures, spam short-circuits, and email-send failures create no delivered record. |
| Review records are bounded private JSONL with finite retention. | Storage test proves dated JSONL under the delivered private directory, bounded field summaries, upload metadata only, and GC/prune deletion. | Static guard proves no DB option/table writes and no raw upload content is stored. |
| Review ID is plugin-owned, email-carried, and not an action token. | Email integration test proves successful delivered email includes `X-EForms-Review-Id`, visible review ID, and an admin review URL when enabled. | Test/source guard proves SMTP `Message-ID` is not used for lookup and no `review_id` alone can mark spam, ban, or change labels. |
| Email contains only an admin review link, not an action link. | Email integration test proves successful delivered email includes a Tools-page review URL when enabled. | Test/source guard proves no `mark_spam`, nonce, or action token is present in email URLs and no GET action changes labels. |
| One admin surface handles delivered and declined review without merging provenance. | Admin test proves one Tools page renders source tabs/filter and can show delivered and declined detail states. | Test proves no separate delivered/sent Tools page is registered and delivered labels do not mutate declined records. |
| Manual spam labels are distinct from runtime detector spam. | Admin action test writes a `marked_spam` review event and emits Fail2ban with `EFORMS_ERR_MANUAL_SPAM` when configured. | Test proves runtime spam still logs `EFORMS_ERR_SPAM`, and manual labels never rewrite original delivery records or emit `EFORMS_ERR_SPAM`. |
| Raw IP is retained only for enforcement and is not the default display/export value. | Fail2ban action test proves the private raw IP can feed `Fail2banLogger::emit()`. | Admin/export tests prove list/detail/default export show configured IP presentation, not raw IP, unless a future explicit carrier changes that rule. |
| Mark not spam is a label event, not an unban or detector mutation. | Admin action test writes `marked_not_spam` and current-label derivation changes. | Test proves no Fail2ban line is emitted and no spam config/content-filter data changes. |

## Phase 1 - Contracts, Config, And Storage

Goals:
- Define durable contracts before code relies on them.
- Add delivered-review config, bounds, storage, retention, and capture after successful delivery.
- Keep disabled mode inert.

Non-Goals:
- No admin action screen beyond registration prerequisites.
- No export in this phase.

- [ ] P1.T1 Update active carriers: define delivered review contracts (Source: AI Conversation + active carriers)
  - `Type:` `standard`
  - `Artifacts:` `docs/overview.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - `Interfaces:` `delivered_review.enable`, `delivered_review.retention_days`, Tools -> eForms Review, delivered-review JSONL schema, `X-EForms-Review-Id`, `EFORMS_ERR_MANUAL_SPAM`
  - `Owner:` active carriers listed in `Artifacts`
  - `Depends On:` none
  - `Done When:` carriers define opt-in all-delivered capture, bounded retention, plugin-owned review ID lookup, admin-only review link/action semantics, raw-IP enforcement privacy, manual spam provenance, unified review page source/provenance, and no public action surface.
  - `Verified via:` `rg -n "delivered_review|eForms Review|X-EForms-Review-Id|EFORMS_ERR_MANUAL_SPAM|delivered-review" docs`
  - `Reasoning:` `high`

- [ ] P1.T2 Add config and bounds: expose delivered review as an opt-in admin setting (Source: P1.T1)
  - `Type:` `standard`
  - `Artifacts:` `src/Config.php`, `src/Anchors.php`, `src/Admin/SettingsFields.php`, `tests/unit/test_config_validation.php`, `tests/unit/test_config_admin_api.php`, `tests/unit/test_config_clamps.php`, `tests/integration/test_admin_settings_page.php`
  - `Interfaces:` `delivered_review.enable`, `delivered_review.retention_days`, Settings -> eForms delivered review controls
  - `Owner:` `src/Config.php` for config semantics; `src/Admin/SettingsFields.php` for admin field metadata
  - `Depends On:` P1.T1
  - `Done When:` config defaults off; retention clamps through existing retention anchors or new anchor names in `Anchors`; admin settings save sparse overrides and respect external-control provenance.
  - `Verified via:` `php tests/unit/test_config_validation.php && php tests/unit/test_config_admin_api.php && php tests/unit/test_config_clamps.php && php tests/integration/test_admin_settings_page.php`
  - `Reasoning:` `high`

- [ ] P1.T3 Implement storage owner: create delivered records and review events (Source: P1.T1)
  - `Type:` `standard`
  - `Artifacts:` `src/DeliveredReviewLog.php`, `src/Logging/FileSink.php`, `src/Uploads/PrivateDir.php`, `src/Uploads/UploadValue.php`, `src/Privacy/ClientIp.php`, `tests/integration/test_delivered_review_log.php`, `tests/unit/test_protocol_seam_guards.php`
  - `Interfaces:` delivered-review record schema, plugin-owned review ID schema, review-event schema, query/find/current-label API, retention cleanup API
  - `Owner:` `src/DeliveredReviewLog.php`
  - `Depends On:` P1.T1, P1.T2
  - `Done When:` the owner accepts a caller-provided `review_id`, captures bounded field summaries, upload metadata, request/form/submission IDs, presented IP, private raw enforcement IP, soft/content reasons when available, and append-only label events; invalid JSONL records are skipped without fatal errors; lookup by review ID is exact and read-only.
  - `Verified via:` `php tests/integration/test_delivered_review_log.php && php tests/unit/test_protocol_seam_guards.php`
  - `Reasoning:` `high`

- [ ] P1.T4 Wire review-ID delivery capture: generate before email, record after email success (Source: P1.T1)
  - `Type:` `standard`
  - `Artifacts:` `src/Submission/SubmitHandler.php`, `src/Security/Entropy.php`, `src/DeliveredReviewLog.php`, `tests/integration/test_delivered_review_log.php`, `tests/integration/test_spam_fail_threshold.php`
  - `Interfaces:` successful delivery pipeline, plugin-owned review ID lifecycle, post-commit failure behavior
  - `Owner:` `src/Submission/SubmitHandler.php` orchestrates capture; `src/DeliveredReviewLog.php` owns storage
  - `Depends On:` P1.T3
  - `Done When:` successful delivered submissions generate a plugin review ID before email rendering, pass it to email as metadata, and call `DeliveredReviewLog::capture()` with the same ID only after email success when enabled; write failure logs a warning and does not change public success/failure result; disabled config has no side effect.
  - `Verified via:` `php tests/integration/test_delivered_review_log.php && php tests/integration/test_spam_fail_threshold.php`
  - `Reasoning:` `high`

- [ ] P1.T5 Add GC and maintenance primitives: include delivered review in cleanup (Source: P1.T1)
  - `Type:` `standard`
  - `Artifacts:` `src/Gc/GcRunner.php`, `src/DeliveredReviewLog.php`, `tests/integration/test_delivered_review_log.php`
  - `Interfaces:` `wp eforms gc` delivered-review retention target
  - `Owner:` `src/Gc/GcRunner.php` orchestrates GC; `src/DeliveredReviewLog.php` owns deletion rules
  - `Depends On:` P1.T3
  - `Done When:` GC prunes old delivered-review files using delivered retention without touching declined-review files or non-review private storage.
  - `Verified via:` `php tests/integration/test_delivered_review_log.php`
  - `Reasoning:` `medium`

## Phase 2 - Admin Workflow, Email Link, And Manual Fail2ban

Goals:
- Make review usable only inside wp-admin.
- Add safe email navigation to the review detail.
- Emit manual Fail2ban lines with truthful provenance.

Non-Goals:
- No public action endpoint.
- No automatic unban.
- No export yet.

- [ ] P2.T1 Add unified admin review surface: source tabs, lookup, detail, labels, and cleanup (Source: UI Surface Preflight)
  - `Type:` `ui-ownership`
  - `Artifacts:` `src/Admin/ReviewAdmin.php`, `src/Admin/DeclinedReviewAdmin.php`, `src/bootstrap.php`, `src/DeliveredReviewLog.php`, `src/DeclinedReviewLog.php`, `tests/integration/test_review_admin.php`, `tests/integration/test_declined_review_admin.php`
  - `Interfaces:` Tools -> eForms Review, source tabs/filter, review-ID lookup, admin list/detail/action/cleanup workflow
  - `Owner:` `src/Admin/ReviewAdmin.php`
  - `Depends On:` P1.T3, P1.T5
  - `Old Visible Owner:` Tools -> eForms Declined
  - `New Visible Owner:` Tools -> eForms Review
  - `Removal Proof:` no separate Tools -> eForms Delivered/Sent page is registered; declined review is available through the unified page when `declined_review.enable=true`.
  - `Negative Check:` rendered HTML/source tests prove GET requests cannot mark labels, review-ID lookup alone cannot mutate labels, no second delivered/sent menu page exists, and unauthorized users cannot render the page.
  - `Done When:` enabled delivered or declined config registers one Tools review page; disabled configs register no review page; source tabs/filter separate Delivered and Declined provenance; review-ID lookup opens detail only; list/detail escape stored content; mark spam/not spam actions require capability, nonce, and confirmation; admin notices report action and cleanup outcomes.
  - `Verified via:` `php tests/integration/test_review_admin.php && php tests/integration/test_declined_review_admin.php`
  - `Reasoning:` `high`

- [ ] P2.T2 Add review ID header/body and admin-only review link: lookup/correlation, not action (Source: UI Surface Preflight)
  - `Type:` `standard`
  - `Artifacts:` `src/Email/Emailer.php`, `src/Submission/SubmitHandler.php`, `src/DeliveredReviewLog.php`, `tests/integration/test_email_headers_sanitization.php`, `tests/integration/test_delivered_review_log.php`
  - `Interfaces:` delivered notification email body/content, `X-EForms-Review-Id`, visible review ID, review URL
  - `Owner:` `src/Email/Emailer.php` owns outbound notification content; `SubmitHandler` supplies delivered-review ID after capture
  - `Depends On:` P1.T4, P2.T1
  - `Done When:` delivered emails include `X-EForms-Review-Id`, a visible review ID, and a Tools-page review URL when delivered review is enabled; link contains no action token and no public nonce; the ID is generated before email and recorded only after email success; failed capture omits or leaves the link non-actionable without failing delivery.
  - `Verified via:` `php tests/integration/test_email_headers_sanitization.php && php tests/integration/test_delivered_review_log.php`
  - `Reasoning:` `high`

- [ ] P2.T3 Add manual spam Fail2ban event: emit truthful manual code only when configured (Source: AI Conversation + Public Contracts)
  - `Type:` `standard`
  - `Artifacts:` `src/Admin/ReviewAdmin.php`, `src/DeliveredReviewLog.php`, `src/Logging/Fail2banLogger.php`, `src/ErrorCodes.php`, `tests/unit/test_error_codes_append_only.php`, `tests/integration/test_review_admin.php`
  - `Interfaces:` `EFORMS_ERR_MANUAL_SPAM`, Fail2ban line format, review event schema
  - `Owner:` `src/Admin/ReviewAdmin.php` triggers admin action; `Fail2banLogger` owns emission; `ErrorCodes` owns stable code list
  - `Depends On:` P2.T1
  - `Done When:` mark spam writes a review event and emits one Fail2ban line with `EFORMS_ERR_MANUAL_SPAM` when configured and raw IP is available; not-spam emits no Fail2ban line; manual code is not rendered as a public form error.
  - `Verified via:` `php tests/unit/test_error_codes_append_only.php && php tests/integration/test_review_admin.php`
  - `Reasoning:` `high`

- [ ] P2.T4 Show Fail2ban status: add read-only Logging status, not writable path config (Source: AI Conversation + Settings contracts)
  - `Type:` `ui-ownership`
  - `Artifacts:` `src/Admin/SettingsAdmin.php`, `src/Admin/SettingsFields.php`, `src/Config.php`, `tests/integration/test_admin_settings_page.php`
  - `Interfaces:` Settings -> eForms Logging group read-only Fail2ban status/provenance
  - `Owner:` `src/Admin/SettingsAdmin.php` renders status; `Config` owns effective values/provenance
  - `Depends On:` P1.T1
  - `Old Visible Owner:` Logging group without Fail2ban status
  - `New Visible Owner:` Logging group with read-only Fail2ban configured/unconfigured status and raw-IP privacy note
  - `Removal Proof:` no writable `logging.fail2ban.file` admin control is added.
  - `Negative Check:` admin settings test/source guard proves `logging.fail2ban.file` is absent from saved admin override fields.
  - `Done When:` Logging group shows whether Fail2ban is configured, effective target/path source, retention source, and raw-IP enforcement warning without allowing wp-admin writes to Fail2ban path.
  - `Verified via:` `php tests/integration/test_admin_settings_page.php`
  - `Reasoning:` `medium`

## Phase 3 - Export, Filters, And Closure

Goals:
- Add labeled export for AI/offline analysis without expanding public surface.
- Close active-carrier sync and broad verification.

Non-Goals:
- No automatic model training or rule generation.
- No raw IP export by default.

- [ ] P3.T1 Add filtered export: export review labels and evidence for admin analysis (Source: AI Conversation)
  - `Type:` `ui-ownership`
  - `Artifacts:` `src/Admin/ReviewAdmin.php`, `src/DeliveredReviewLog.php`, `src/DeclinedReviewLog.php`, `tests/integration/test_review_admin.php`
  - `Interfaces:` admin-only export action for current filtered delivered-review records/events and declined-review evidence
  - `Owner:` `src/Admin/ReviewAdmin.php`
  - `Depends On:` P2.T1, P2.T3
  - `Old Visible Owner:` none
  - `New Visible Owner:` export action on Tools -> eForms Review
  - `Removal Proof:` no public export endpoint exists.
  - `Negative Check:` tests prove export requires capability and nonce, and default export omits raw IP.
  - `Done When:` admins can export bounded labeled delivered records and declined evidence for a date/source/filter window; export includes source and review label provenance and excludes raw upload contents and raw IP by default.
  - `Verified via:` `php tests/integration/test_review_admin.php`
  - `Reasoning:` `medium`

- [ ] P3.T2 Sync contract carriers and seam guards: prove no duplicate review/storage seams (Source: active carriers)
  - `Type:` `standard`
  - `Artifacts:` `docs/overview.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `tests/unit/test_protocol_seam_guards.php`
  - `Interfaces:` permanent delivered-review docs, owner index verification hooks
  - `Owner:` active carriers plus protocol guard tests
  - `Depends On:` P1.T1, P1.T3, P2.T1, P2.T3
  - `Done When:` permanent carriers match implemented behavior; owner index names delivered-review storage, unified review admin owners, and forbidden seams; guards cover FileSink reuse, no DB review storage, no public action endpoint, no separate delivered/sent page, and no manual labels using `EFORMS_ERR_SPAM`.
  - `Verified via:` `php tests/unit/test_protocol_seam_guards.php && rg -n "DeliveredReviewLog|ReviewAdmin|X-EForms-Review-Id|EFORMS_ERR_MANUAL_SPAM|delivered_review" docs src tests`
  - `Reasoning:` `high`

- [ ] P3.T3 Run closure verification: focused and broad gates (Source: Verification Baseline)
  - `Type:` `standard`
  - `Artifacts:` tests and changed source/docs from this plan
  - `Interfaces:` all scoped delivered-review behavior
  - `Owner:` plan executor
  - `Depends On:` all prior tasks
  - `Done When:` focused verification command is green; broad gate is green or any red result is classified as task-blocking, out-of-scope pre-existing, or external harness.
  - `Verified via:` Verification Command and Broad Gate listed above
  - `Reasoning:` `high`

## Seam Guard

Run gate: phase checkpoint after P2 and closure gate after P3.

Expected outcomes:
- `rg -n "mark_spam|marked_spam|manual_spam" src tests` shows only unified review admin/log/event paths and tests.
- `rg -n "EFORMS_ERR_MANUAL_SPAM|EFORMS_ERR_SPAM" src tests docs` shows manual code only for delivered-review review events/Fail2ban and runtime spam code only for detector paths.
- `rg -n "Message-ID|X-EForms-Review-Id|review_id" src tests docs` shows plugin review ID usage does not depend on SMTP `Message-ID` and review ID lookup is read-only.
- `rg -n "eforms_admin_config|update_option|get_option|delete_option" src tests uninstall.php` shows no delivered-review record/event persistence through WordPress options.
- `rg -n "DeliveredReviewLog|ReviewAdmin|FileSink::|PrivateDir::|UploadValue::|Fail2banLogger::emit" src tests` shows delivered review delegates shared mechanics to existing owners and uses one review admin owner.

## Known Debt & Open Questions

- [ ] Open Question: delivered-review content mode - should operators later choose metadata-only capture?
  - `Type:` `open-question`
  - `Owner:` `src/Config.php` and delivered-review product carrier
  - `Why Deferred:` v1 needs enough evidence for admin judgment and AI export, and a capture-mode matrix would add another user-facing branch.
  - `Decision Trigger:` operator privacy requirements conflict with storing bounded field summaries for all delivered mail.
  - `Decision Options:` keep bounded field summaries only; add `delivered_review.capture_mode=metadata|summary`; split AI export from review capture.
  - `Default Until Decided:` store bounded field summaries only when `delivered_review.enable=true`.
  - `Verification Hook:` config/admin schema tests if a new mode is later approved.

- [ ] Open Question: narrower review capability - should review use a custom capability instead of `manage_options`?
  - `Type:` `open-question`
  - `Owner:` admin surfaces
  - `Why Deferred:` declined review already uses `manage_options`; changing capability semantics should be deliberate across admin review surfaces.
  - `Decision Trigger:` non-admin staff need access to review delivered submissions without full site settings access.
  - `Decision Options:` keep `manage_options`; add `manage_eforms_reviews`; map capability through a filter.
  - `Default Until Decided:` use `manage_options`.
  - `Verification Hook:` admin render/action tests for allowed and denied users.

- [ ] Debt: shared review table helpers - delivered and declined rows may later share lower-level table/detail helpers.
  - `Type:` `debt`
  - `Owner:` `src/Admin/ReviewAdmin.php`
  - `Why Deferred:` v1 should first converge on one visible review workflow; helper extraction should happen only if it deletes real duplication.
  - `Removal Trigger:` the unified page has repeated delivered/declined rendering code that a narrow helper can remove without hiding source/provenance.
  - `Verification Hook:` rendered tests prove both source tabs still show correct labels, filters, and detail actions.

- [ ] Debt: automatic learning loop - manual labels are exported but do not tune rules automatically.
  - `Type:` `debt`
  - `Owner:` future spam policy owner
  - `Why Deferred:` automatic rule mutation can create false positives and needs a separate safety contract.
  - `Removal Trigger:` user requests a learning/rule-suggestion feature with approval and rollback semantics.
  - `Verification Hook:` future tests prove suggested rules are opt-in, reviewable, and reversible.

## Plan Review Checklist

- Hard-Stop Preflight: yes, triggered gates are represented by task cards, UI preflight, owner reduction, invariant matrix, seam guard, and verification command.
- Scope vs Source of Truth: yes, this plan decomposes the conversation-approved delivered-review workflow and active host contracts.
- Plan shape vs work size: Full Plan is justified by new persistence, admin UI, email output, config, stable code, and logging integration.
- Verification: yes, every load-bearing task has `Verified via:` and every invariant has positive and negative proof.
- Cross-module work: yes, owner discovery chooses a delivered-review storage sibling plus one unified review admin owner and records forbidden local seams.
- Plan maintenance: execute one unchecked task at a time, verify it, then mark it checked. If any cited active carrier changes behavior before execution, add `[ ] Rebase plan to current source` before continuing.
