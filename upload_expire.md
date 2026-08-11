# Upload Availability Plan

Status: Retired / non-authoritative. The implementation tasks are complete; durable behavior authority now lives in code, tests, and the active carriers listed below.

Scope: Add operator-controlled availability for finalized staged photo submissions. Operators can keep a submission available for a fixed future period or until manually deleted. The same availability fact controls review-gallery access and artifact cleanup.

Source of Truth: AI conversation on 2026-07-26 plus active carriers `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, and current code/tests.

Host Contracts: `UploadBatchStore` owns managed aggregate state, authoritative artifacts, capacity accounting, finalized-submission deletion, and GC eligibility. `ReviewController` owns signed gallery/member URLs, review rendering context, operator review actions, and Worker review grants. `GcRunner` adapts `UploadBatchStore::gc_aggregates()`. `templates/pages/review-gallery.php` owns the rendered review-gallery surface. `Emailer` owns notification email rows and consumes `ReviewController::email_gallery_reference()`. `Anchors` owns fixed retention-option durations.

Not Behavior Authority: yes. This is a retired transient execution plan kept only as historical task context.

Retirement Trigger: All tasks are checked, durable decisions are carried by code/tests/permanent carriers, and no `gallery_expires_at`/URL `expires` contract remains except documented historical discussion in this plan.

Plan Shape: Full Plan. Triggers: persistence contract change, public review URL contract change, operator UI action, multiple subsystem owners, and dependent load-bearing tasks.

## Design Decision

Use one manifest availability field:

```text
delete_after: int|null
```

- `int` means the finalized photo submission remains available until that Unix timestamp, then GC may delete it.
- `null` means no automatic cleanup; the submission remains available until explicit operator deletion or uninstall purge.
- Staged/open/finalizing pre-email aggregates keep numeric `delete_after`; `null` is valid only for finalized submissions.
- There is no separate `gallery_expires_at`, link-expiry field, archive mode, or retention mode enum.

### URL Mechanics Decision

New review URLs should not carry an expiration timestamp.

Use signed bearer URLs with only identity plus signature:

```text
/?eforms_review={submission_id}&signature={signature}
/?eforms_review={submission_id}&eforms_review_upload={upload_id}&signature={signature}
/?eforms_review={submission_id}&eforms_review_preview={upload_id}&signature={signature}
```

The signature proves the URL was minted by WordPress for that action and identity. Runtime availability is checked only by loading the manifest through `UploadBatchStore` and applying `delete_after`. If `delete_after` is extended, the same review link naturally stays valid longer. If `delete_after` is set to `null`, the same link remains valid until manual deletion. If the aggregate is deleted, every gallery/member route fails the same generic unavailable path.

Because removing URL expiry changes the signed payload shape, bump `ReviewController::VERSION` from `2` to `3` and make version 3 identify exactly this payload: `domain, version, action, submission_id, upload_id`.

Do not create a link table, URL registry, reverse lookup, refresh endpoint, rewrite alias, or separate link lifecycle. Worker review grants remain short-lived implementation details minted per authorized member request; they are not stored in the manifest or exposed as gallery state.

### Rejected Alternatives

- Keep `expires` in the URL and update it when retention changes: rejected because it leaves two user-visible lifetimes and makes old email links stale after an extension.
- Add `retention_mode` beside `delete_after`: rejected because `delete_after = null` expresses manual retention without another state machine.
- Add arbitrary day input: rejected for v1 because fixed choices are easier to validate, test, and explain.
- Add an `Archive` button: rejected because the user job is availability/cleanup, not moving records into a separate archive class.

## Owner Discovery / Reduction Pass

Existing Owner Evidence:
- `docs/Architecture_Router.md` names `UploadBatchStore` as the owner of finalized aggregate state, operator deletion, and GC, and `ReviewController` as the owner of signed gallery/member access.
- `docs/Owner_Index.md` names `UploadBatchStore` for managed aggregate/artifact/capacity state and `ReviewController` for managed review access.
- `docs/contracts/Runtime_Storage.md` currently states finalization sets `gallery_expires_at` and `delete_after`; this is the stale contract to replace.
- `docs/contracts/Public_Contracts.md` currently states anonymous review access ends at the earlier of signed expiry and `gallery_expires_at`; this is the stale contract to replace.

Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `agent_docs/guides/Cross_Cutting_Concerns.md`, `agent_docs/guides/ui_surface_preflight.md`.

Reuse Decision: extend existing owners.

Boundary Decision: extend existing owner.
- Keep local is worse because URL validation, manifest availability, cleanup, and operator actions are shared review/upload lifecycle behavior, not template-local presentation.
- Introduce new shared layer is worse because `UploadBatchStore` and `ReviewController` already own the state and access seams; a retention service would be a parallel owner.

Reuse Target:
- Manifest state and mutation: `src/Uploads/UploadBatchStore.php`.
- Review URL signing/routing and operator POST actions: `src/Uploads/ReviewController.php`.
- Review page UI: `templates/pages/review-gallery.php` and existing `.eforms-review-*` CSS/dialog patterns.
- Fixed duration choices: `src/Anchors.php`.

No-Fallback Rule / Kill List:
- Remove production use of `gallery_expires_at`.
- Remove URL `expires` query generation and validation for new review URLs.
- Do not add compatibility readers/writers for both URL-expiry and manifest availability unless the user explicitly requests legacy link support.
- Do not add a DB table, reverse index, refresh endpoint, rewrite alias, config option, arbitrary days field, or separate link lifecycle.

New Artifact Budget:
- No new subsystem or storage root.
- Allowed: private helper(s) inside `UploadBatchStore` for finalized availability checks and availability mutation; private helper(s)/constants inside `ReviewController` for availability actions; Anchor constants for fixed choices.

Contract Carrier Sync:
- Update `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, and `docs/Owner_Index.md`.
- `docs/Architecture_Router.md` should remain unchanged unless implementation discovers a changed runtime center or dependency direction.

Owner_Index Change: update the managed review verification hook from `gallery_expires_at` to the new `delete_after`/signed-URL contract.

## UI Surface Preflight

User job: A logged-in operator reviewing submitted photos needs to decide how long the photo submission remains available so they can control storage without thinking about separate links, artifacts, or cleanup internals.

Primary content:
- Submission ID.
- Photo count.
- Availability state: `Available until <date>` or `Available until manually deleted`.

Secondary actions:
- `Update availability` opens a small dialog with fixed choices.
- `Delete submission` remains the separate destructive action.

Deferred/not shown:
- No artifact-store identity, Worker/local provider, signatures, URL expiry, manifest fields, GC status, storage paths, or link mechanics.
- No arbitrary days input in v1.

Options considered:
- Inline select in the action row: rejected because it makes a rare operator action too visually prominent and crowds the summary/delete row.
- Separate admin settings page: rejected because the decision belongs with the specific photo submission.
- Modal/dialog from review gallery: selected because it reuses the existing delete-dialog pattern, keeps the summary calm, and gives the availability change one explicit completion boundary.

Decision: Add an operator-only `Update availability` action next to `Delete submission`, using the existing review-page dialog/action-button style.

Completion boundary: `Update availability` POST either commits the new `delete_after` under the submission aggregate lock and returns the gallery with updated summary, or leaves the old value unchanged and shows the same generic unavailable/failure behavior used by review actions.

Expired management boundary: after numeric `delete_after` passes but before GC removes the aggregate, anonymous requests still get the generic unavailable state. A logged-in `manage_options` operator using a valid signed gallery URL may render a management-only expired state with no photos, previews, member links, or availability update form. That state shows only the current availability status and the whole-submission delete action.

Permission behavior: anonymous users see no retention controls. Only `manage_options` users with a valid nonce can change availability.

Mobile behavior: summary stacks above action buttons like the current review action row; destructive delete remains visually distinct.

## Invariant Matrix

| Invariant | Positive Proof | Negative Proof |
| --- | --- | --- |
| `delete_after` is the single availability authority for finalized submissions. | Finalized submission remains reviewable before numeric `delete_after`, after extension, and indefinitely when `delete_after === null`. | `rg -n "gallery_expires_at" src templates tests docs` finds no production contract/use after migration except intentional historical mentions in retired plan if kept. |
| Review URLs carry no expiration timestamp. | Email/reference and gallery/member links render without `expires=` and continue to work after extending `delete_after`. | Tests prove forged/modified signatures fail, expired numeric `delete_after` fails for anonymous gallery/member access, and deleted aggregate fails even with a valid signature. |
| Signature version identifies the exact payload shape. | `ReviewController::VERSION` is `3`, and signatures are generated/verified over `domain, version, action, submission_id, upload_id`. | Tests prove v2/expiry-shaped signatures do not validate after the seam refactor. |
| Expired-but-present aggregates remain operator-deletable without restoring gallery access. | `manage_options` + valid signed gallery URL can render an expired management-only state and submit whole-submission deletion before GC runs. | Anonymous expired requests, member routes, and expired management pages expose no photos, previews, member links, availability update, or Worker grants. |
| GC deletes only numeric-expired finalized submissions. | GC deletes a finalized submission whose numeric `delete_after <= now` and releases capacity/artifacts. | GC skips finalized submissions whose `delete_after` is future or `null`. |
| Operator availability changes are capability/nonce gated and aggregate-locked. | `manage_options` + nonce can set 30/90/365 days from now or `null`. | Anonymous users, missing nonce, invalid action, invalid choice, and already-deleted/expired submissions cannot mutate availability. |
| Operator UI presents one object lifecycle. | Gallery summary says `Available until <date>` or `Available until manually deleted`; dialog says it keeps submitted photos and review gallery available. | UI/tests prove no `archive`, `link expires`, `gallery_expires_at`, storage/provider, or URL mechanics language appears. |
| Worker review grants remain bounded implementation details. | Worker member request mints a grant expiring no later than `now + WORKER_REVIEW_GRANT_TTL_SECONDS` and numeric `delete_after` when present. | Manual retention (`delete_after === null`) does not mint indefinite Worker grants or persist provider grants in manifests/gallery HTML. |

## Phase 1 - Contract And Manifest Authority

Goals: Replace the stale dual-lifetime contract with `delete_after: int|null` as the sole finalized availability authority.

- [x] P1.T1 Update active carriers for one availability authority (Source: AI Conversation + active carriers)
  - Type: standard
  - Artifacts: `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `docs/Owner_Index.md`
  - Interfaces: public managed review link contract, finalized manifest contract, operator-facing review-gallery behavior
  - Owner: active contract carriers listed in `Artifacts`
  - Depends On: none
  - Done When: contracts state finalized submissions use `delete_after: int|null`; review links have no `expires` query; `delete_after = null` means available until manually deleted; numeric `delete_after` controls both review access and GC; operator copy says available, not archive/link expiry; Owner Index verification hook names the new seam.
  - Verified via: `rg -n "gallery_expires_at|expires=|Archive|link expiry|link expires" docs` plus focused review of changed carrier hunks.

- [x] P1.T2 Refactor finalized manifest availability in `UploadBatchStore` (Source: P1.T1)
  - Type: migration
  - Artifacts: `src/Uploads/UploadBatchStore.php`, `src/Anchors.php`, `tests/integration/test_gc_staged_uploads.php`, `tests/integration/test_remote_restore_drill.php`, relevant unit/contract tests
  - Interfaces: finalized manifest schema, `UploadBatchStore::finalize()`, `submission()`, `submission_file()`, `submission_preview_source()`, `gc_aggregates()`
  - Owner: `src/Uploads/UploadBatchStore.php`; fixed preset durations in `src/Anchors.php`
  - Depends On: P1.T1
  - Atomic With: P2.T1, because removing production `gallery_expires_at` from `UploadBatchStore` and removing URL-expiry consumers from `ReviewController` is one seam cutover and cannot be verified independently without preserving a forbidden compatibility alias.
  - Mutated State: finalized manifests replace `gallery_expires_at` with `delete_after: int|null`; manifest schema version updates with no dual reader/writer.
  - Preserved State: staged/open/finalizing pre-email manifests keep numeric `delete_after`; items/intents/tombstones/capacity facts remain unchanged.
  - Discard Semantics: no compatibility bridge for old `gallery_expires_at` manifests in greenfield mode; structurally stale manifests fail closed unless user later requests migration support.
  - Done When: finalization sets numeric `delete_after = finalized_at + MANAGED_FINALIZED_TTL_SECONDS`; finalized availability checks use a single helper that accepts `null` and rejects numeric past times for gallery/member access; GC treats `null` as not time-eligible; operator deletion still deletes immediately, including numeric-expired finalized aggregates that still exist before GC; malformed missing/invalid finalized `delete_after` fails closed.
  - Verified via: `php tests/integration/test_gc_staged_uploads.php && php tests/integration/test_remote_restore_drill.php` plus `rg -n "gallery_expires_at" src tests` expected no production use.

## Phase 2 - Review URL And Email Mechanics

Goals: Make review URL validity derive from manifest availability, not from a URL timestamp.

- [x] P2.T1 Refactor review signatures and routing to remove URL expiry (Source: P1.T1)
  - Type: seam-refactor
  - Artifacts: `src/Uploads/ReviewController.php`, `tests/integration/test_review_gallery.php`, `tests/integration/test_remote_restore_drill.php`, `tests/e2e/specs/review_gallery.spec.js`
  - Interfaces: `gallery_url()`, `file_url()`, `preview_url()`, `signature()`, `dispatch_current_request()`, gallery/member query shapes
  - Owner: `src/Uploads/ReviewController.php`
  - Depends On: P1.T2
  - Atomic With: P1.T2, because `ReviewController` is the live consumer of finalized availability summaries and must move from URL expiry to manifest `delete_after` in the same seam cutover.
  - Existing Owner Evidence: `ReviewController` already owns signed gallery/member URL generation, verification, and dispatch.
  - Reuse Target: existing `ReviewController` signer/parser/dispatcher; existing WordPress home query route.
  - No-Fallback Rule: do not keep `expires` query validation or signature input for new URLs; do not add a URL registry, refresh endpoint, rewrite alias, or link table.
  - Replacement: bump `ReviewController::VERSION` to `3`; new signature message `domain, version, action, submission_id, upload_id` replaces old v2 message `domain, version, action, submission_id, upload_id, expires`.
  - Superseded Seams: URL `expires` parsing, URL-expiry precheck, `expires > artifact['gallery_expires_at']` member guard.
  - Removal Proof: `rg -n "query_expiry|expires >|gallery_expires_at|expires," src/Uploads/ReviewController.php tests` shows only deleted/renamed expected residue.
  - Complexity Budget: one controller refactor, no new public endpoint, no new storage.
  - Done When: gallery/member URLs contain IDs and `signature` only; `ReviewController::VERSION` is `3`; valid signed links work while manifest availability allows; modified signature/action/upload ID and v2/expiry-shaped signatures fail; numeric-expired manifests fail for anonymous gallery/member access; deleted manifests fail for all access; member URLs are still WordPress-owned; Worker redirects still mint fresh short-lived grants.
  - Verified via: `php tests/integration/test_review_gallery.php && php tests/integration/test_remote_restore_drill.php && npm test --prefix tests/e2e -- tests/e2e/specs/review_gallery.spec.js`

- [x] P2.T2 Update email gallery reference and templates to availability language (Source: P1.T1)
  - Type: standard
  - Artifacts: `src/Email/Emailer.php`, `templates/email/default.html.php`, `templates/email/default.txt.php`, email integration tests
  - Interfaces: gallery row in notification emails; `ReviewController::email_gallery_reference()` result consumed by `Emailer`
  - Owner: `ReviewController` owns the review reference; `Emailer` owns outbound email rows/templates.
  - Depends On: P2.T1
  - Done When: generated email review links have no `expires` query; email copy says `Available until` with a date for numeric `delete_after` and `Available until manually deleted` for `null`; old `expires_at`/`expires_label` row fields are removed or renamed consistently so no template carries link-expiry vocabulary.
  - Verified via: relevant email integration tests plus `rg -n "expires_at|expires_label|gallery_expires_at|expires=" src/Email templates/email tests` with only intended non-review-token matches.

## Phase 3 - Operator Availability Action And Review UI

Goals: Let operators update `delete_after` from the review gallery without exposing storage/link internals.

- [x] P3.T1 Add aggregate-locked availability mutation (Source: P1.T1)
  - Type: migration
  - Artifacts: `src/Uploads/UploadBatchStore.php`, `src/Uploads/ReviewController.php`, `src/Anchors.php`, integration tests
  - Interfaces: operator POST action on signed gallery route; fixed choices `30 days`, `90 days`, `1 year`, `until manually deleted`
  - Owner: `UploadBatchStore` owns persisted mutation; `ReviewController` owns capability/nonce/action parsing.
  - Depends On: P2.T1
  - Mutated State: finalized manifest `delete_after` changes to a future timestamp or `null`.
  - Preserved State: manifest items/artifact ownership/capacity accounting/signature identity remain unchanged; delete action remains independent and destructive.
  - Discard Semantics: invalid choice, invalid nonce/capability, unavailable/deleted aggregate, or write failure leaves previous `delete_after` unchanged.
  - Done When: `manage_options` + valid nonce can set each fixed choice; chosen numeric value is computed from request time using Anchor-owned durations; `null` skips future GC; invalid/anonymous availability-update requests fail closed with no mutation; operator delete routes directly through `UploadBatchStore::delete_finalized_submission()` and still works for manual-retention and numeric-expired aggregates that still exist before GC.
  - Verified via: `php tests/integration/test_review_gallery.php && php tests/integration/test_gc_staged_uploads.php`

- [x] P3.T2 Render review-gallery availability controls (Source: UI Surface Preflight)
  - Type: ui-ownership
  - Artifacts: `templates/pages/review-gallery.php`, `assets/forms.css`, `assets/review-gallery.js` if needed, `tests/e2e/specs/review_gallery.spec.js`
  - Interfaces: operator-visible review-gallery summary/action row and availability dialog
  - Owner: `templates/pages/review-gallery.php` for markup; existing review-gallery CSS/dialog patterns for styling; `ReviewController` supplies context.
  - Depends On: P3.T1
  - Old Visible Owner: review-gallery action row currently shows submission ID/photo count and optional delete only.
  - New Visible Owner: same action row adds availability summary and operator-only `Update availability` dialog/action while keeping delete separate.
  - Removal Proof: rendered tests show no archive/link/storage/provider wording and no retention controls for anonymous users.
  - Negative Check: `npm test --prefix tests/e2e -- tests/e2e/specs/review_gallery.spec.js` asserts anonymous gallery lacks controls; operator gallery shows current availability and fixed choices; expired anonymous gallery is unavailable; expired operator gallery renders management-only status/delete without photos/member links/update controls; successful update shows the new state.
  - Done When: operator gallery displays `Available until <date>` or `Available until manually deleted`; dialog offers fixed choices only while gallery access is available; completion returns to the gallery with updated state; numeric-expired operator state displays current unavailable/expired status and whole-submission deletion only; mobile layout remains readable; delete dialog still works.
  - Verified via: `npm test --prefix tests/e2e -- tests/e2e/specs/review_gallery.spec.js`

## Phase 4 - Closure And Broad Verification

- [x] P4.T1 Run seam closure and broad gates (Source: prior tasks)
  - Type: standard
  - Artifacts: `docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`, `docs/Owner_Index.md`, `src/Uploads/UploadBatchStore.php`, `src/Uploads/ReviewController.php`, `src/Email/Emailer.php`, `templates/pages/review-gallery.php`, `templates/email/*`, tests
  - Interfaces: finalized photo availability, review URLs, operator update/delete actions, email review links, GC
  - Owner: changed owners listed above
  - Depends On: P1.T1, P1.T2, P2.T1, P2.T2, P3.T1, P3.T2
  - Done When: one source of availability truth remains; old URL-expiry/gallery-expiry path is absent; signature version `3` owns the expiration-free payload; expired-but-present aggregates are operator-deletable without exposing gallery/media access; permanent carriers match implementation; all scoped tests and broad gates pass or any unrelated pre-existing red is recorded.
  - Verified via: `php tests/integration/test_review_gallery.php && php tests/integration/test_gc_staged_uploads.php && php tests/integration/test_remote_restore_drill.php && npm test --prefix tests/e2e && php tests/wp-runtime/run.php && git --no-pager diff --check`

## Verification Baseline

Primary task-proof commands:

```sh
php tests/integration/test_review_gallery.php
php tests/integration/test_gc_staged_uploads.php
php tests/integration/test_remote_restore_drill.php
npm test --prefix tests/e2e -- tests/e2e/specs/review_gallery.spec.js
```

Broad Gate:

```sh
npm test --prefix tests/e2e
php tests/wp-runtime/run.php
git --no-pager diff --check
```

Seam Guard:

```sh
rg -n "gallery_expires_at|query_expiry|expires_label|expires_at|expires=" src templates tests docs
```

Expected after closure: no production `gallery_expires_at`; no review URL `expires=` generation/validation; no email template `expires_*` review fields. Non-review token/mint `expires` matches are allowed only outside the managed review seam.

## Known Debt & Open Questions

None. If legacy support for already-sent `expires` URLs becomes required, add it as a new explicit compatibility task with a removal trigger; do not silently preserve dual URL semantics in this plan.

## Plan Review Checklist

- Execution Gate Matrix: yes; every load-bearing task has Type, Artifacts, Interfaces, Owner, Depends On, Done When, and Verified via.
- Source Scope: yes; behavior comes from the current conversation and active carriers.
- Owner Reduction: yes; existing `UploadBatchStore` and `ReviewController` absorb the behavior.
- UI Surface Preflight: yes; operator job, action placement, completion boundary, and not-shown facts are recorded.
- Invariant Proof Coverage: yes; positive and negative proof paths are listed in the Invariant Matrix and task cards.
- Contract Carrier Sync: yes; active carriers are explicit in P1.T1 and closure verifies old contract absence.
- Greenfield Purity: yes; no compatibility bridge, dual reader, link table, or refresh endpoint is planned.
