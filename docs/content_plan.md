# Content Filter Implementation Plan

Scope: Add a small plain-word and phrase content filter for eForms submissions. The filter is optional, bounded, and intended for obvious repeated spam language. It must not become a general regex engine, Akismet integration, or delivered-message feedback loop in this plan.

Source of Truth:
- AI conversation in this thread: approved proposal for a simple content filter with modes `off`, `suspect`, and `reject`; plain terms only; default off; config keys under `spam.content_filter.*`.
- `docs/overview.md` current spam pipeline and operator settings narrative.
- `docs/Architecture_Router.md` owner routing for Submission, Security, Config, Settings, Diagnostics, Email, Logging, and Declined Review.
- `docs/Owner_Index.md` owner registry and anti-drift gates.
- `docs/contracts/Public_Contracts.md` public and machine-readable output contract.

Host Contracts:
- Runtime config defaults and validation live in `src/Config.php`.
- Settings -> eForms rendering lives in `src/Admin/SettingsAdmin.php`; the field matrix and form-to-override mapper live in `src/Admin/SettingsFields.php`.
- Public POST orchestration lives in `src/Submission/SubmitHandler.php`.
- Early behavior/request spam signals live in `src/Security/Security.php`; content filtering must not silently change `spam.soft_fail_threshold` behavior or participate in `soft_reasons`.
- Content filtering lives in `src/Spam/ContentFilter.php` as spam policy, separate from Security behavior-signal detection.
- Email headers and subject tagging live in `src/Email/Emailer.php`.
- Structured runtime logs live in `src/Logging.php`; declined review storage lives in `src/DeclinedReviewLog.php`.
- Spam smoke checks live in `src/Diagnostics/SpamSmokeDiagnostic.php`; admin/CLI surfaces are adapters only.

Not Behavior Authority: yes. This plan is a transient execution artifact. Code, tests, and active carriers are behavior authority.

Retirement Trigger: Retire this plan after all tasks are checked, focused verification passes, and still-valid decisions are distilled into `docs/overview.md`, `docs/contracts/Public_Contracts.md`, and `docs/Owner_Index.md`.

## Non-Goals

- Do not add raw regex editing to the admin UI.
- Do not add Akismet, SpamAssassin, or any third-party scoring provider.
- Do not add automatic Fail2ban emission for content-only suspect matches.
- Do not add delivered-message review or manual spam feedback actions.
- Do not scan raw `$_POST`, protocol fields, honeypot fields, upload payloads, or filenames in this slice.
- Do not store full submitted content or raw blocked terms in normal operational logs.

## Verification Baseline

Automated Harness: use focused PHP tests. New content-filter tests are created by `P1.T2`; until then, the named command is a target command for execution.

Verification Command:

```bash
php tests/unit/test_content_filter.php \
  && php tests/integration/test_content_filter_submission.php \
  && php tests/integration/test_admin_settings_page.php \
  && php tests/integration/test_spam_smoke_command.php \
  && php tests/integration/test_email_headers_sanitization.php \
  && php tests/integration/test_declined_review_log.php \
  && git diff --check
```

Broad Gate: optional after focused green, run the repository's established broader PHP test sweep if available in the current workspace. If broad checks are red, classify failures as task-blocking, out-of-scope pre-existing, or harness/environment.

Baseline Failure Snapshot:
- Baseline Commands: focused existing tests should be run before implementation begins: `php tests/integration/test_admin_settings_page.php && php tests/integration/test_spam_smoke_command.php && php tests/integration/test_email_headers_sanitization.php && git diff --check`.
- Pre-existing Red: none known for the focused baseline at planning time.
- In-Scope Green Target: all focused commands plus new content-filter tests pass.
- Out-of-Scope Red: any unrelated dirty-worktree or broad-suite failures must be listed in execution handoff, not silently folded into this plan.

## Owner Discovery / Owner Reduction Pass

Existing Owner Evidence:
- `Config` owns defaults, enum validation, admin override schema, merge precedence, and effective config provenance.
- `SettingsFields` owns setting labels, groups, controls, help text, submitted-field sentinels, and form-to-override mapping.
- `Security` owns current soft reason vocabulary and threshold math; this plan must not add content matches to `soft_reasons` or `Security::SOFT_REASON_ORDER`.
- `SubmitHandler` owns POST ordering and can run content checks after validation, coercion, and challenge success, before uploads/email side effects.
- `Emailer` owns notification subject tagging and `X-EForms-*` email headers.
- `Logging` and `DeclinedReviewLog` own operator-readable runtime evidence and bounded review records.
- `SpamSmokeDiagnostic` owns smoke-test result shape; CLI/admin must only adapt presentation.

Docs Consulted:
- `agent_docs/Implementation_Plan_Guide.md`
- `agent_docs/ui_surface_preflight.md`
- `docs/Architecture_Router.md`
- `docs/Owner_Index.md`
- `docs/overview.md`
- `docs/contracts/Public_Contracts.md`

Reuse Decision: extend existing owner families. Add a narrow spam-policy owner for term normalization, descriptor/type-driven field selection, and canonical-value matching; keep orchestration in `SubmitHandler`, config in `Config`, UI metadata in `SettingsFields`, headers in `Emailer`, and diagnostics in `SpamSmokeDiagnostic`.

Boundary Decision: add one small spam-policy owner under the existing spam/security area.
- Keep local is worse because local matching inside `SubmitHandler` would duplicate config parsing and make unit proof harder.
- Put under Security is worse because Security already owns behavior/request soft signals and challenge-driving labels; content matching must not look like another soft signal.
- Introduce a broad shared layer is worse because there is only one runtime consumer in v1; a small spam-policy owner is enough.
- Add the narrow spam owner because content matching is spam policy, but its decision mode must remain separate from existing soft-threshold math.

Reuse Target:
- New file budget: `src/Spam/ContentFilter.php` as the single content-term normalization, field-selection, and matching owner.
- Existing orchestrator: `src/Submission/SubmitHandler.php` calls the owner after challenge success and before upload/email side effects.
- Existing settings owner: `src/Admin/SettingsFields.php` adds the controls under Spam Protection.
- Existing evidence owners: `src/Email/Emailer.php`, `src/Logging.php`, `src/DeclinedReviewLog.php`, and `src/Diagnostics/SpamSmokeDiagnostic.php`.

No-Fallback Rule / Kill List:
- No raw regex UI.
- No raw POST scanning.
- No duplicate term parser in SettingsAdmin, SubmitHandler, Logging, Emailer, or diagnostics.
- No threshold reinterpretation of `spam.soft_fail_threshold`.
- No content labels in `soft_reasons` or `Security::SOFT_REASON_ORDER`.
- No automatic Fail2ban write from `suspect` mode.
- No DB-backed moderation state.

New Artifact Budget:
- Add one spam-policy owner: `src/Spam/ContentFilter.php`.
- Add at most two focused tests: `tests/unit/test_content_filter.php` and `tests/integration/test_content_filter_submission.php`.
- Extend existing admin, config, email, logging/review, and diagnostic tests rather than adding parallel harnesses.

Owner_Index Change: add a row for content filter matching when `src/Spam/ContentFilter.php` is added as a reusable owner.

Contract Carrier Sync:
- `docs/overview.md`: describe operator-facing setting and pipeline behavior.
- `docs/contracts/Public_Contracts.md`: define any new machine-readable header/log metadata, such as `X-EForms-Content-Reasons`.
- `docs/Owner_Index.md`: add content-filter owner and verification hook.
- `docs/Architecture_Router.md`: update only if a new runtime center or subsystem boundary is introduced; expected change is none.

## UI Surface Preflight

User job: An administrator needs to add, review, paste, and remove obvious spam words or phrases so repeated spam campaigns can be tagged or rejected without learning regex syntax.

Task class: new/material settings surface, but inside the existing Settings -> eForms page.

Existing patterns checked:
- Settings -> eForms grouped settings panels: fits because content filter is a runtime option with source/provenance and save behavior.
- Spam Protection editable settings plus built-in checks: fits because this is spam-specific, operator-facing, and should stay near threshold/min-fill/rejection-response controls.
- Tools -> eForms Declined: does not fit because this is review/inspection, not configuration.

Options considered:
- Option A: Add content controls inside Spam Protection. Pros: one save surface, provenance retained, no new navigation. Cons: Spam Protection panel grows. Reuse: `SettingsAdmin` and `SettingsFields`. Best when the controls directly affect spam handling.
- Option B: Add a separate Content Filter page. Pros: more room for future rule management. Cons: premature surface split and extra navigation for a two-field feature. Reuse: weaker.
- Option C: Drop-in config only. Pros: lowest UI risk. Cons: non-developers cannot operate it and it hides an operator-facing runtime behavior.
- Option D: Hybrid term editor inside Spam Protection. Pros: compact daily editing, visible existing terms, inline duplicate feedback, and bulk paste support. Cons: requires one focused admin UI component and progressive-enhancement tests.

Decision: Add compact controls inside the existing Spam Protection settings group, with a hybrid term editor for `spam.content_filter.blocked_terms`.

Why: The operator job is adjusting spam behavior, not managing a separate rules database. The existing Settings -> eForms shell already handles provenance, help popouts, and saves. A hybrid editor gives the final UI a better routine workflow without changing the backend newline-list contract.

Rejected:
- Separate page: too much surface for a bounded phrase list.
- Drop-in only: too hidden for the requested operator control.
- Pure pill UI: weak for bulk import and long phrases.
- Raw textarea only: workable fallback, but poorer for routine review and removal once the list grows.

Reuse contract:
- `SettingsAdmin` page shell and save orchestration.
- `SettingsFields` field matrix and mapper.
- Existing help popout pattern.
- Existing Spam Protection panel layout.

Surface contract:
- Entry point: Settings -> eForms -> Spam Protection.
- Visual hierarchy: existing Spam Protection settings first; content filter controls grouped below or within the settings subsection.
- Controls: mode select, hybrid blocked-terms editor.
- Hybrid editor: existing terms render as removable pills; one quick-add input adds a term on Enter or Add; "Paste multiple" opens a small textarea where newline-separated and comma-separated pasted lists become pills.
- Fallback contract: the submitted value remains one normalized term per line in `spam.content_filter.blocked_terms`; with JavaScript unavailable, administrators can still edit the newline textarea directly.
- Help: plain-language warning to start with Suspect; one term per line; no regex.
- Save feedback: existing settings save notice.
- Permission behavior: existing manage-options capability and nonce gates.
- Invalid/blocked state: duplicate, oversized, or malformed term lists return inline feedback where possible and settings errors through the existing save path.

Primitive map:
- Page shell: `SettingsAdmin`.
- Field matrix: `SettingsFields`.
- Form persistence: `AdminSettingsStore` through `Config` validation.
- Local CSS/classes/selectors: one focused admin settings term-editor component; no global UI framework.

Not shown:
- Per-term IDs, per-term table rows, individual enable toggles, match counters, AI suggestions, regex editor, provider scoring, or delivered-message review actions.

Delete / do not build:
- Do not create a second settings page.
- Do not create a custom raw config editor.
- Do not duplicate settings mapper logic outside `SettingsFields`.
- Do not make the JavaScript component the only way to edit terms; the newline-list control remains the canonical submitted shape.

Verification:
- `tests/integration/test_admin_settings_page.php` proves controls, help text, mode options, hybrid editor markup, fallback newline field, duplicate messaging, and old duplicate/raw surfaces absent.

## Invariant Matrix

| Invariant | Positive Proof | Negative Proof |
| --- | --- | --- |
| Plain terms only; no regex UI | `tests/unit/test_content_filter.php` proves literals such as `seo services` and `casino` match predictably | Admin/settings tests prove help says plain terms and no regex control exists |
| Deterministic matching rules | Unit tests prove lowercase normalization, whitespace collapse, edge punctuation trimming, phrase containment, and single-word boundary behavior such as `loan` not matching `Sloan` | Source check proves matching does not depend on locale, current time, randomness, or unordered iteration |
| Matching uses canonical submitted values only | `tests/integration/test_content_filter_submission.php` submits valid coerced scalar text-like fields and gets content match behavior | Integration test includes protocol/honeypot/upload/non-scalar values and proves they do not match |
| Field matching is descriptor/type-driven | Unit or integration tests prove text, textarea, email, tel, and equivalent scalar text descriptors are checked | Source check proves no hardcoded `name`, `email`, `phone`, `subject`, or `message` key allowlist drives matching |
| `suspect` mode sends email and records content evidence | Integration test proves email send occurs and `X-EForms-Content-Reasons` or equivalent metadata appears | Integration test proves `suspect` mode does not call spam short-circuit, burn ledger as spam, join `soft_reasons`, or emit Fail2ban-only behavior |
| `reject` mode reserves/burns ledger and blocks before upload/email side effects | Integration test proves matched content returns current spam rejection response, no email send, no upload move, and one ledger reserve/burn call | Integration test proves non-matching content proceeds through normal ledger/email path |
| Content filter does not change existing soft-threshold semantics | Existing spam-threshold tests remain green; new tests prove content suspect does not become threshold spam when `spam.soft_fail_threshold=1` | Source/grep check proves content labels are not appended to `Security::SOFT_REASON_ORDER` or `soft_reasons` for v1 threshold math |
| Visitor and normal-log privacy is preserved | Tests prove visitor response and normal logs expose reason/hash/field keys only | Tests prove matched raw term text is absent from visitor response, email headers, and normal JSONL logs |
| Bounded config prevents unbounded operator input | Unit/config tests prove max term count, max term length, blank-line cleanup, and oversized admin input handling | Admin save test rejects oversized/malformed term list without partial unsafe persistence |
| Hybrid term editor preserves the backend contract | Admin tests prove pills, remove buttons, quick-add input, Paste multiple affordance, and no-JS newline textarea are rendered for `spam.content_filter.blocked_terms` | Save tests prove submitted values still reach Config as one normalized term per line with no per-term IDs, toggles, stats, or database rows |
| Duplicate handling uses matcher normalization | UI tests or markup/static tests prove duplicate feedback says "Already added"; server tests reject normalized duplicates such as `Casino`, ` casino `, and `CASINO` | Source check proves duplicate checks do not use raw string comparison that disagrees with `ContentFilter` normalization |

## Phases

### Phase 1 - Contracts, Config, And Pure Matcher

Goals:
- Define durable operator-facing behavior before runtime wiring.
- Add one canonical matcher owner with bounded parsing and deterministic matching.

Non-Goals:
- No POST pipeline changes yet.
- No email/log/review metadata yet.

- [ ] P1.T1 Update active carriers for content filter contract
  - `Type:` `standard`
  - `Artifacts:` `docs/overview.md`, `docs/contracts/Public_Contracts.md`, `docs/Owner_Index.md`, maybe `docs/Architecture_Router.md` only if ownership changes beyond the existing Security family
  - `Interfaces:` config keys `spam.content_filter.mode`, `spam.content_filter.blocked_terms`; machine-readable content evidence header/log metadata if added
  - `Owner:` docs carriers for operator behavior and public contracts
  - `Depends On:` none
  - `Done When:` docs define off/suspect/reject behavior, plain-term limits, non-goals, and whether content evidence appears in email headers/logs/review records
  - `Verified via:` `rg -n "content_filter|Content filter|X-EForms-Content" docs`
  - `Reasoning:` `medium`

- [ ] P1.T2 Implement ContentFilter owner and unit proof
  - `Type:` `seam-refactor`
  - `Artifacts:` `src/Spam/ContentFilter.php`, `src/Config.php`, `tests/unit/test_content_filter.php`, `tests/unit/test_config_validation.php` or `tests/unit/test_config_admin_api.php`
  - `Interfaces:` `ContentFilter::evaluate($context, $values, $config)` or equivalent pure owner API returning `matched`, `mode`, `decision` (`none`, `suspect`, `reject`), `reason`, `match_ids`, and `field_keys`
  - `Owner:` `src/Spam/ContentFilter.php`
  - `Depends On:` P1.T1
  - `Done When:` terms parse from one-per-line `spam.content_filter.blocked_terms`, max 100 terms, max 80 chars each, blank lines are ignored, case-insensitive phrase matching works, single words match only at string boundary or non-letter/non-digit boundaries, empty/off config returns no match, and matched terms are exposed as stable hashes or IDs rather than raw text
  - `Verified via:` `php tests/unit/test_content_filter.php && php tests/unit/test_config_validation.php && php tests/unit/test_config_admin_api.php`
  - `Reasoning:` `high`
  - `Existing Owner Evidence:` Security owns behavior/request soft signals; Config owns defaults/admin schema; this new owner owns spam content policy only.
  - `Docs Consulted:` `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - `Reuse Target:` `Config::value()`, `Config::bool()`, existing schema validation, and existing field descriptors from the validated/coerced submission context
  - `No-Fallback Rule:` no duplicate parser in SubmitHandler or SettingsFields
  - `Replacement:` none; new bounded owner for previously absent behavior
  - `Superseded Seams:` none
  - `Removal Proof:` `rg -n "content_filter|ContentFilter|blocked_terms" src tests` shows parsing/matching only in `ContentFilter` plus config/tests/callers
  - `Complexity Budget:` one new runtime class, one unit test file, config/schema edits only

### Phase 2 - Submission Pipeline, Email, Logs, And Review Evidence

Goals:
- Wire the content filter after validation, coercion, and successful challenge verification, before uploads/email side effects.
- Keep `suspect` and `reject` behavior independent from existing soft-threshold math.

Non-Goals:
- No challenge-trigger behavior from content matches in v1.
- No delivered-review action workflow.

- [ ] P2.T1 Wire content filter into SubmitHandler
  - `Type:` `seam-refactor`
  - `Artifacts:` `src/Submission/SubmitHandler.php`, `src/Spam/ContentFilter.php`, `tests/integration/test_content_filter_submission.php`, existing spam-threshold tests
  - `Interfaces:` POST result behavior; `spam.content_filter.mode`; current spam rejection response
  - `Owner:` `src/Submission/SubmitHandler.php` for ordering, `src/Spam/ContentFilter.php` for matching
  - `Depends On:` P1.T2
  - `Done When:` content evaluation runs after coercion and challenge success; `suspect` attaches content-filter metadata then continues through normal ledger/email; `reject` reserves/burns the ledger then blocks before upload moves and email; non-match path remains unchanged
  - `Verified via:` `php tests/integration/test_content_filter_submission.php && php tests/integration/test_spam_fail_threshold.php`
  - `Reasoning:` `high`
  - `Existing Owner Evidence:` SubmitHandler owns POST ordering and spam short-circuit result.
  - `Docs Consulted:` `docs/Architecture_Router.md`, `docs/Owner_Index.md`
  - `Reuse Target:` existing `spam_short_circuit_result()` for reject mode where appropriate; existing ledger reserve/burn, upload cleanup, and spam logging mechanics
  - `No-Fallback Rule:` no second rejection path that skips upload cleanup, ledger burn rules, or current spam response behavior
  - `Replacement:` none; insert a new post-validation decision point
  - `Superseded Seams:` none
  - `Removal Proof:` integration trace proves order includes content filter after coerce and before commit
  - `Complexity Budget:` one new SubmitHandler call site plus small helper methods if needed

- [ ] P2.T2 Add operator evidence to email, logs, and declined review
  - `Type:` `standard`
  - `Artifacts:` `src/Email/Emailer.php`, `src/Logging.php`, `src/DeclinedReviewLog.php`, `src/Submission/SubmitHandler.php`, `tests/integration/test_email_headers_sanitization.php`, `tests/integration/test_declined_review_log.php`, `tests/integration/test_content_filter_submission.php`
  - `Interfaces:` email subject tag/header metadata, JSONL/minimal metadata, declined review record fields
  - `Owner:` `Emailer` for email metadata; `Logging` for event metadata; `DeclinedReviewLog` for bounded review records
  - `Depends On:` P2.T1
  - `Done When:` matched content in suspect/reject mode is visible to administrators through safe reason labels, hashed/stable match IDs, and field keys; normal logs, visitor responses, and email headers do not expose raw submitted content or raw blocked term text
  - `Verified via:` `php tests/integration/test_email_headers_sanitization.php && php tests/integration/test_declined_review_log.php && php tests/integration/test_content_filter_submission.php`
  - `Reasoning:` `high`

### Phase 3 - Admin Settings And Diagnostics

Goals:
- Expose the feature under Spam Protection with the canonical fallback control.
- Add one smoke/diagnostic proof path so operators can see the filter is wired.

Non-Goals:
- No separate Tools page.
- No per-term database rows, IDs, toggles, stats, or separate rules table.

- [ ] P3.T1 Expose Content Filter controls in Spam Protection
  - `Type:` `ui-ownership`
  - `Artifacts:` `src/Admin/SettingsFields.php`, `src/Admin/SettingsAdmin.php` if textarea support is needed, `src/Config.php`, `tests/integration/test_admin_settings_page.php`
  - `Interfaces:` Settings -> eForms; admin override validation; effective source/provenance display
  - `Owner:` `src/Admin/SettingsFields.php` for field metadata and mapper, `src/Admin/SettingsAdmin.php` for render only if existing controls need textarea support
  - `Depends On:` P1.T2
  - `Done When:` mode select and fallback blocked-terms textarea render under Spam Protection, save through existing mapper to `spam.content_filter.*`, show help text, preserve external-control behavior, clean blank lines, reject duplicate and oversized invalid input cleanly, and provide the canonical field enhanced by P4
  - `Verified via:` `php tests/integration/test_admin_settings_page.php`
  - `Reasoning:` `high`
  - `Old Visible Owner:` none; feature absent
  - `New Visible Owner:` existing Settings -> eForms Spam Protection panel
  - `Removal Proof:` no new settings page, raw config editor, or per-group submit branch
  - `Negative Check:` `rg -n "add_options_page|content_filter|blocked_terms" src/Admin tests` with expected matches only in SettingsAdmin/SettingsFields/tests

- [ ] P3.T2 Add content-filter smoke coverage
  - `Type:` `standard`
  - `Artifacts:` `src/Diagnostics/SpamSmokeDiagnostic.php`, `tests/integration/test_spam_smoke_command.php`, `tests/integration/test_admin_settings_page.php`
  - `Interfaces:` spam smoke result rows in CLI/admin
  - `Owner:` `src/Diagnostics/SpamSmokeDiagnostic.php`
  - `Depends On:` P2.T1
  - `Done When:` smoke diagnostic can prove a synthetic canonical submission matches a temporary synthetic phrase config and reports expected suspect/reject behavior without depending on the operator's real blocked terms or sending real email
  - `Verified via:` `php tests/integration/test_spam_smoke_command.php && php tests/integration/test_admin_settings_page.php`
  - `Reasoning:` `medium`

### Phase 4 - Hybrid Blocked-Terms Editor

Goals:
- Provide the final compact admin workflow for routine term review, add, remove, and bulk import.
- Keep `spam.content_filter.blocked_terms` as a boring newline-list value for config, tests, and runtime code.

Non-Goals:
- No separate content-filter page.
- No per-term IDs, toggles, counters, stats, database rows, or async saves.
- No regex or provider scoring UI.

- [ ] P4.T1 Build progressive hybrid term editor
  - `Type:` `ui-ownership`
  - `Artifacts:` `src/Admin/SettingsFields.php`, `src/Admin/SettingsAdmin.php`, optional focused admin settings asset if the component should not stay inline, `tests/integration/test_admin_settings_page.php`
  - `Interfaces:` Settings -> eForms -> Spam Protection; `spam.content_filter.blocked_terms`; existing settings save form
  - `Owner:` `SettingsFields` declares the field/control type and mapper; `SettingsAdmin` renders and enhances the control
  - `Depends On:` P3.T1
  - `Done When:` existing terms render as pills with accessible remove buttons, quick-add supports Enter and Add, Paste multiple opens a textarea that accepts newline-separated and comma-separated lists, normalized duplicates are rejected inline as "Already added.", the canonical submitted textarea contains one normalized term per line, and no-JS fallback editing remains available
  - `Verified via:` `php tests/integration/test_admin_settings_page.php`
  - `Reasoning:` `high`
  - `Old Visible Owner:` fallback textarea from P3.T1
  - `New Visible Owner:` enhanced term editor in the existing Spam Protection settings panel
  - `Removal Proof:` no new page, no per-term persistence, and no runtime contract beyond `spam.content_filter.blocked_terms`
  - `Negative Check:` `rg -n "content_filter|blocked_terms|term-editor|eforms-content" src/Admin assets tests` shows one admin component path and no database/table/action endpoint

### Phase 5 - Contract Sync, Seam Guards, And Closure

Goals:
- Confirm the old forbidden shapes are absent.
- Keep durable docs aligned with actual implementation.

- [ ] P5.T1 Run seam guards and final verification
  - `Type:` `standard`
  - `Artifacts:` code, tests, docs touched by P1-P4
  - `Interfaces:` none beyond verified behavior
  - `Owner:` task-local owners from previous phases
  - `Depends On:` P1.T1, P1.T2, P2.T1, P2.T2, P3.T1, P3.T2, P4.T1
  - `Done When:` focused verification command passes; no raw regex UI, raw POST scanning, duplicate parser, content soft-reason coupling, raw-term normal logging, or unexpected Fail2ban path exists
  - `Verified via:` run the plan-level Verification Command plus seam guard commands below
  - `Reasoning:` `medium`

Seam Guard:

```bash
# Expected: no regex UI or regex config path in admin/runtime content filter.
rg -n "regex|preg_match|content_filter" src/Admin src/Security src/Spam src/Submission tests \
  | rg -v "ContentFilter|test_content_filter|plain|regex UI|preg_quote"

# Expected: content term parsing lives only in ContentFilter plus tests.
rg -n "blocked_terms|spam\\.content_filter\\.blocked_terms" src tests docs

# Expected: content matches do not join behavior soft-signal threshold math.
rg -n "content_blocked_term|content_filter" src/Security src/Submission src/Spam tests

# Expected: no alternate per-term persistence or rules endpoint.
rg -n "content_filter|blocked_terms|term-editor|eforms-content" src/Admin assets tests
```

Contract Carriers to Re-evaluate:
- `docs/overview.md`
- `docs/contracts/Public_Contracts.md`
- `docs/Owner_Index.md`
- `docs/Architecture_Router.md` only if implementation creates a new runtime center, which is not expected
- `tests/integration/test_admin_settings_page.php`
- `tests/integration/test_spam_smoke_command.php`
- `tests/integration/test_email_headers_sanitization.php`
- `tests/integration/test_declined_review_log.php`

## Known Debt & Open Questions

- [ ] Debt: Akismet provider - general content scoring remains out of scope.
  - `Type:` `debt`
  - `Owner:` future provider/integration owner
  - `Why Deferred:` Akismet adds API keys, privacy disclosure, latency, network failure modes, and feedback flows that are larger than the simple local filter.
  - `Trigger:` repeated spam gets through after local content filter and delivered-review feedback loop exist.
  - `Verification Hook:` future provider tests prove spam/ham checks, timeout behavior, and submit-spam/submit-ham feedback.

- [ ] Debt: Advanced regex - raw regex remains unavailable in settings.
  - `Type:` `debt`
  - `Owner:` `src/Spam/ContentFilter.php`
  - `Why Deferred:` raw regex is high-risk for false positives, broken patterns, and performance support burden.
  - `Trigger:` operator has proven plain terms cannot express a needed stable spam campaign rule.
  - `Verification Hook:` future config-file-only tests prove pattern validation, bounded execution, and no admin regex UI.

- [ ] Open Question: Content match as challenge trigger - decide whether content suspect should trigger Turnstile auto mode later.
  - `Type:` `open-question`
  - `Owner:` Security/Challenge owner
  - `Why Deferred:` v1 keeps content mode independent from existing soft-threshold and challenge semantics to avoid changing request behavior unexpectedly.
  - `Decision Trigger:` operators want content matches to challenge instead of merely tagging delivered messages.
  - `Decision Options:` keep independent; trigger challenge on content suspect; reject only on content match.
  - `Default Until Decided:` content suspect does not trigger challenge.
  - `Verification Hook:` future spam-smoke row proves challenge-auto behavior for content matches if enabled.

- [ ] Debt: Delivered-review feedback loop - manual "mark delivered as spam" remains separate.
  - `Type:` `debt`
  - `Owner:` future delivered-review owner
  - `Why Deferred:` this plan only adds local phrase matching, not a moderation workflow or labeled training corpus.
  - `Trigger:` operator wants to confirm false negatives and feed Fail2ban or AI analysis.
  - `Verification Hook:` future review tests prove admin-only action, nonce/capability, audit event, and optional Fail2ban emission.

## Plan Review Checklist

- Execution Gate Matrix: yes for all triggered gates in this plan.
- Full Plan selected because the work spans config, settings UI, submission pipeline, email/log/review metadata, diagnostics, docs, and tests.
- Source of Truth is explicit and this plan does not add Akismet, regex UI, delivered review, or Fail2ban behavior.
- Owner Discovery result is recorded before task cards.
- UI Surface Preflight is recorded for the settings surface.
- Each load-bearing task has `Type`, `Artifacts`, `Interfaces`, `Owner`, `Depends On`, `Done When`, `Verified via`, and strict `Reasoning`.
- Invariant Matrix includes positive and negative proof for load-bearing behavior.
- Automated verification has a named command and a task that creates missing tests.
