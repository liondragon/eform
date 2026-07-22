# Authoritative Upload Artifact — Transient Implementation Plan

Status: Active implementation artifact; P0 through P3 and P5 are complete.
P4 and P6 remain pending on genuine external evidence and human approval.

Scope: Replace the staged upload's required normalized-master-and-preview
contract with one authoritative accepted artifact, deployment-bound local or
Worker/R2 transport, optional lazy operator previews, and optional
browser-side JPEG preparation. Preserve the current customer upload-card
experience while removing image processing from upload success.

Source of Truth: Current behavior is governed by
`docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/overview.md`,
`docs/contracts/*`, source code, and tests. Durable design rationale is retained
in `docs/PAST_DECISIONS.md`; the superseded proposal has been retired. Active
carriers continue to govern runtime behavior until a task changes code, tests,
and the affected carrier together.

Host contracts: `docs/Architecture_Router.md`, `docs/Owner_Index.md`,
`docs/overview.md`, `docs/contracts/Public_Contracts.md`,
`docs/contracts/Runtime_Storage.md`, `src/Anchors.php`, code-owner comments,
and repository test READMEs.

Not Behavior Authority: yes. This file sequences work and records acceptance
criteria. It must not be parsed at runtime or used to override an active
contract, code, or test.

Retirement Trigger: Delete this plan after all tasks are complete, the target
behavior is carried entirely by active contracts/code/tests, the design
proposal has been reduced to any durable rationale worth keeping in
`docs/PAST_DECISIONS.md` and removed, and production-readiness evidence has
received human review.

## Outcome and execution policy

The final system has one product lifecycle:

`select -> optionally prepare -> authorize -> upload -> verify -> commit -> finalize`

The committed artifact is authoritative. Preview generation is optional and
never changes submission correctness. Local and Worker/R2 deployments share
the artifact-fact and aggregate contracts, not an artificial byte-transfer
implementation.

Execute the plan in order. Keep each task or tightly coupled task group as a
reviewable commit after its required checks pass; do not accumulate the entire
program into one commit. Generated test output, provider credentials, local
Wrangler state, Playwright output, and disposable-environment evidence are not
committed. Do not update this plan merely to log test runs.

Every task commit must leave the canonical PHP lane green. When an owner and
all of its callers cannot be migrated independently, execute the adjacent
dependent tasks as one cohesive commit rather than adding a compatibility
adapter or committing an intentionally broken intermediate state. P1-T1 and
P1-T2 are the expected example if the manifest facade cannot remain callable
during the endpoint migration.

The plan ends at production-ready and human-approved. It does not authorize a
production deployment, secret change, R2 lifecycle mutation, or destructive
purge by itself.

## Baseline snapshot

Baseline commit: `f3575ad6d6eaae61a8ac801ccd26c057e0afdb19`.

The snapshot was taken with pre-existing changes to `agent_docs`,
`self-imrov.md`, and the then-untracked proposal retired by P5. Those paths were
outside the implementation change set except for the proposal's explicit
adoption and retirement tasks; no task could absorb the other existing changes.

Production-source measurements at the baseline:

- all `src/*.php`: 27,305 lines;
- upload/media PHP focus slice (`src/Uploads/*.php`, `src/Gc/GcRunner.php`,
  `src/Diagnostics/RuntimeHealthDiagnostic.php`, and `uninstall.php`): 8,117
  lines;
- `src/Uploads/UploadBatchStore.php`: 3,004 lines;
- `assets/forms.js`: 1,862 lines.

Baseline verification on 2026-07-22:

- the canonical PHP lane passed;
- `php tests/wp-runtime/run.php` passed;
- `npm test --prefix tests/e2e` passed 14 self-contained tests and skipped the
  four configured live-environment tests.

Reproduce the baseline with:

```sh
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
php tests/wp-runtime/run.php
npm test --prefix tests/e2e
```

Verification Command: The first command above is the canonical repository
lane. Run it before every task commit. Also run the narrower task commands and
each environment lane named by that task.

## Architecture and owner-reduction brief

Docs Consulted: `docs/Architecture_Router.md`, `docs/Owner_Index.md`,
`docs/overview.md`, `docs/contracts/Public_Contracts.md`,
`docs/contracts/Runtime_Storage.md`, `tests/README.md`, `tests/e2e/README.md`,
the now-retired proposal, and the repository planning, cross-cutting-concern,
and UI surface guides.

### Existing owner evidence

- `UploadBatchStore` is the public aggregate and capacity facade. Endpoints,
  submission, review, GC, and uninstall already delegate to it.
- `ManagedCapacityStore` is the store-private capacity record and lock owner.
- `UploadBatchEndpoint` and `FormProtocol` own the staged HTTP and browser/PHP
  protocol.
- `UploadPolicy` owns accepted media and dimension policy.
- `PrivateDir` owns private local roots and the upload lifecycle lease.
- `ReviewController` owns signed bearer-link galleries and scoped artifact
  delivery.
- `RuntimeHealthDiagnostic` owns readiness result shape; CLI/admin remain
  adapters.
- `forms.js` owns the existing cards, queue, progress, cancellation, retry,
  recovery, and submission freeze behavior.

### Reuse decision

Evolve those owners in place. `UploadBatchStore` remains the only manifest
writer and external object-budget facade. `ManagedCapacityStore` evolves into
the physical-object budget owner but remains private. Local multipart and
Worker streaming remain distinct transport implementations. Their only common
write-side seam is one strictly validated artifact-fact result consumed by
`UploadBatchStore`.

New Artifact Budget: The first implementation pass may add at most these
focused production owners
unless an owner review proves a missing physical boundary:

- one bounded local artifact inspector;
- one private local artifact transport/store helper;
- one Worker protocol/signing helper in PHP;
- one bounded WordPress-to-Worker client for server-side read/delete/health
  operations; and
- one local lazy-preview provider.

Do not create a provider registry, service container, generic media framework,
manifest repository, queue abstraction, or one interface that pretends local
multipart and Worker streaming perform the same transfer.

### Boundary decision

This is a Tier 3 boundary change: browser, WordPress, private filesystem,
Cloudflare Worker, R2, Images, operator review, GC, uninstall, and deployment
operations participate in one lifecycle. The boundary is justified because it
removes public artifact bytes and image decoding from the constrained VPS.

The trust and ownership boundaries are:

- WordPress authorizes intents, owns batch state and policy, commits items,
  finalizes submissions, and authorizes retention/deletion/review.
- The Worker authenticates scoped grants, streams bytes, inspects the stored
  object, and reports signed immutable facts. It cannot commit or finalize.
- R2 stores private immutable artifacts and replaceable preview cache objects.
- The browser transports grants and receipts but never supplies authoritative
  media facts.
- Preview providers return a representation or an unavailable result; they do
  not write manifest state.

Review access model:

- The email gallery URL is an account-free, expiring signed bearer grant. It
  requires no login, cookie, or device state; possession or forwarding grants
  the same temporary access until the gallery expires or signing-key rotation
  intentionally invalidates the link.
- `noindex`, `no-store`, and `no-referrer` reduce leakage but are not access
  control and never replace signature or grant validation.
- Local artifacts, R2 objects, and preview cache objects remain private. Every
  gallery, preview, and download request validates its bearer scope before
  delivery; public or static object URLs are forbidden.
- Review signatures and Worker grants bind their action, exact artifact or
  recipe scope, expiry, protocol version, and environment where applicable.
  Modified, foreign, or expired requests fail with the generic unavailable
  response.

### No-fallback and kill list

- No automatic local fallback when Worker/R2 is unavailable.
- No storage-driver field in production manifests and no per-form/wp-admin
  storage or preview switch.
- No normalized-manifest compatibility reader; no production manifests exist
  that require migration.
- No required master/preview derivatives, derivative capacity accounting, or
  derivative readiness gate.
- No background processing state machine, scheduler, queue, Uppy, tus, or
  multipart R2 upload in the initial implementation.
- No permanent provider URL, grant, receipt, secret, or customer metadata in a
  manifest or log.
- No preview state, retry counter, or cache locator in authoritative submission
  state.
- No full-image decode or upload-body transit through S1 in the Worker/R2
  composition.

Seam Guard: After every seam-changing phase, scan for direct manifest writes,
calls to `ManagedCapacityStore` outside `UploadBatchStore`, provider choices in
forms/admin/browser code, normalized derivative fields, preview state in
manifests, and duplicate uploader runtimes. Any match must be an active owner,
test fixture, or explicitly retained local-preview implementation.

### Complexity and LOC budget

LOC Budget:

- `UploadBatchStore.php` must not exceed its 3,004-line baseline after the old
  normalized path is removed. If it does, stop and move only a proven physical
  concern into a focused private collaborator.
- The 8,117-line upload/media PHP focus slice should be smaller after final
  removal. A non-negative result requires an owner-by-owner simplification
  review before activation.
- Count production PHP, browser JavaScript, and Worker source separately from
  tests, docs, fixtures, dependencies, generated output, and build artifacts.
- More than approximately 1,500 net new production lines triggers an explicit
  owner review. Approximately 3,000 or more is a stop-and-redesign gate unless
  a newly approved product requirement explains it.
- Test growth is expected. Do not preserve obsolete code or compress readable
  code to improve the count.

Contract Carrier Sync: Each task updates only the active carrier that owns its
implemented behavior. `Runtime_Storage` carries manifest/object/retention and
purge rules; `Public_Contracts` carries browser/HTTP/review surfaces;
`Architecture_Router` carries dependency direction; `Owner_Index` carries
reusable-owner and forbidden-seam rules; `overview` carries customer/operator
behavior. No task creates a parallel master specification.

Owner Index change: required when the artifact inspector, Worker protocol/
client, or optional preview boundary becomes reusable. Extend the existing
managed upload/review/diagnostics rows where that is sufficient; add a new row
only for a genuinely separate reusable owner with its own extension path,
forbidden seam, and verification hook.

## UI Surface Preflight

User job: A customer needs to select phone photos, see immediate feedback and
reliable progress, and know when each file is safely accepted so they can
submit without waiting for operator-only image processing. An operator needs to
preview when practical and always have an authorized submitted-image download.

Task class: Material lifecycle change with an intentionally preserved
interaction model.

Existing patterns checked:

- `assets/forms.js` and `assets/forms.css`: the existing staged cards already
  provide selection preview, progress, retry, removal, Clear all, responsive
  geometry, accessibility announcements, recovery, and finalization freeze.
- `templates/pages/review-gallery.php`, `ReviewController`, and the existing
  review grid styles: the current gallery already groups each image, name, and
  action and is the correct operator surface.
- The existing form renderer and `FormProtocol`: these remain the only source
  of staged mount and browser/PHP protocol names.

Options considered:

- Preserve the current cards and replace transport/state semantics internally:
  best continuity, smallest UI risk, and one browser state machine.
- Replace the uploader with Uppy or another widget: rejected because current
  file sizes do not require it and it would duplicate a polished working UI.
- Expose separate local and R2 upload experiences: rejected because storage is
  deployment infrastructure, not a customer decision.

Decision: Preserve the current upload-card and review-gallery interaction
models. Rename only the post-transfer state from `Processing` to `Finishing
upload...`; report `Uploaded` only after manifest commit. Keep the operator
fallback as `Preview unavailable — download submitted image`.

Information architecture:

- Customer card primary facts: local thumbnail or placeholder, safe display
  name, progress, and current customer-actionable state.
- Customer card actions: Retry or Remove on the item; Clear all at the owning
  field; submit remains blocked and focus returns to the first unresolved card.
- Operator gallery primary facts: preview when available, safe display name,
  and submitted-image download. Preview failure is local to that card.
- Detail-only diagnostics: provider, grant/receipt validation, intent state,
  object version, cleanup phase, and recipe version belong in bounded
  diagnostics/logs, never customer or routine gallery UI.

Primitive map:

- Surface shell and field container: existing form renderer and staged upload
  mount.
- Cards, progress, announcements, actions, submit freeze: existing `forms.js`
  runtime.
- Layout and responsive behavior: existing `forms.css` selectors.
- Review shell, card, caption, and action group: existing review template and
  CSS.
- Local CSS/classes/selectors: none expected. Any exception needs a rendered
  need, a focused selector, and a removal/ownership note.

Control container census:

- Upload field: picker/Choose, drop region, Clear all, card Retry/Remove.
- Form: existing submit control and shared freeze path.
- Canonical target: the same controls and grouping in every deployment.
- Route-owned input: transport target and scoped protocol data only; routes do
  not render alternate controls.
- Forbidden composition: provider-specific buttons, duplicate queues, disabled
  controls that silently no-op, or a second uploader runtime.
- Negative proof: browser tests must show one mount/queue, the complete freeze
  path across multiple cards/picker/Clear all, and absence of provider UI.

Surface contract:

- `queued`, optional `preparing`, `uploading`, `verifying`, `uploaded`,
  `failed`, `removing`, and `removed` are the only card lifecycle states.
- `verifying` displays `Finishing upload...` and remains unresolved.
- Preparation fallback is silent when the selected source remains valid.
- Worker/R2 outage produces the existing retryable item failure language; it
  never reveals provider internals or switches transport.
- Narrow layouts retain the current one-column card behavior and accessible
  target sizes.
- Removal or finalization freezes every mutation surface consistently.

Not shown: backend/provider choice, object keys, versions, checksums, MIME
inspection details, signing data, cleanup phases, preview cache state, or
preparation provenance.

Delete/do not build: alternate upload widgets, provider settings in the form,
parallel local/remote card state machines, processing-completion UI, preview
queues, and backend-specific gallery layouts.

Rendered verification: extend the existing Playwright suite for multi-card
freeze, `verifying`, transport parity, retry/removal races, preview unavailable,
and desktop/two-column/one-column geometry. The real WordPress live upload case
and representative-device preparation checks remain separate environment
gates.

## Invariant matrix

| Invariant | Established by | Primary proof |
|---|---|---|
| Exactly one durable accepted artifact is authoritative | P1-T1 | manifest fixture and aggregate tests |
| `accepted_at` is assigned once by the WordPress commit clock | P1-T1 | delayed idempotent-completion test |
| Object-budget lock precedes aggregate lock; neither spans network/image work | P1-T1, P2-T2, P2-T3 | lock-order and failure-injection tests |
| Transfer completion is not upload success | P1-T2, P2-T2 | Playwright `verifying` tests |
| Finalization requires the exact committed snapshot and zero intents | P1-T1, P1-T3 | submission/aggregate race tests |
| Tombstoned or late objects cannot resurrect or escape accounting | P1-T1, P2-T3 | removal/replay/GC tests |
| Worker requests and receipts are scoped, canonical, expiring, and environment-bound | P2-T1, P2-T2 | cross-language fixture and negative tests |
| WordPress receives no artifact bytes and performs no image decode in R2 mode | P2-T2, P6-T1 | request/work graph and integration evidence |
| Preview absence never mutates submission state or blocks finalization | P1-T3, P3-T1 | review and submission tests |
| Local preview work is bounded globally and per object | P3-T1 | concurrency/saturation tests |
| Browser preparation chooses one artifact before authorization and is default off | P4-T1 | browser tests and request assertions |
| One active contract remains; normalized paths are absent | P5-T1 | seam scans, source counts, full suite |
| Remote objects remain recoverable/deletable through outage, restore, and uninstall | P2-T3, P6-T1 | genuine-provider drills |

## Phase 0 — Close load-bearing decisions and prove deletion behavior

Phase 0 exists because persistence, signing, retention, and uninstall cannot be
implemented safely while their bounds or deletion adapter remain undecided.
No production runtime path changes in this phase.

### [x] P0-T1 — Resolve fixed bounds, secrets, and outage contracts

Type: Product/architecture decision and contract preparation.

Artifacts: fixed values in `src/Anchors.php`, active contracts, and durable
rationale in `docs/PAST_DECISIONS.md`.

Interfaces: Accepted-artifact bytes/edge/pixels; installation object budget;
intent, grant, receipt, open-batch, finalized-retention, orphan-cleanup, and
lifecycle-margin bounds; staged active/secondary secret rotation with bounded
post-cutover retention; customer retry and operator outage behavior; local
preview hard concurrency ceiling.

Owner: Product owner with `UploadBatchStore`, `UploadPolicy`, deployment, and
runtime-diagnostic owners.

Depends On: none.

Done When:

- Every load-bearing bound, secret, outage, and concurrency decision has one
  chosen value or behavior and rationale in an active carrier or ADR.
- Fixed runtime values have planned `Anchors` names; secrets and provider
  management credentials are explicitly excluded from `Anchors`.
- The accepted-artifact ceiling retains boundary fixtures at 18,874,368 bytes
  accepted and 18,874,369 bytes rejected unless the product decision changes
  that limit explicitly.
- Failure copy remains generic, retryable, and provider-neutral; no automatic
  local fallback is introduced.

Verified via:

```sh
rg -n "TBD|TO DECIDE|OPEN QUESTION" docs/Architecture_Router.md docs/Owner_Index.md docs/contracts docs/PAST_DECISIONS.md
rg -n "MANAGED_|STAGED_|ARTIFACT_|WORKER_|PREVIEW_" src/Anchors.php tests/fixtures
```

The first command must find no unresolved load-bearing placeholder. Runtime
constants are added to `src/Anchors.php` only in the same later task that adds
their callers and tests.

Proof: The unresolved-decision scan and canonical PHP lane passed on
2026-07-22. The decisions were implemented in their owning tasks and their
durable rationale now lives in active carriers and `docs/PAST_DECISIONS.md`.

### [x] P0-T2 — Prove the remote uninstall adapter on disposable WordPress

Type: Tier 3 operational proof; no production purge.

Artifacts: a reusable harness under `tests/wp-runtime/` (preferred name
`uninstall-drain.php`), `tests/README.md`, and the chosen rationale in
`docs/PAST_DECISIONS.md`. Raw logs and disposable WordPress/R2 data remain
uncommitted.

Interfaces: wp-admin single and bulk plugin deletion, REST deletion where
supported, WP-CLI normal and `--skip-delete`, early retry, ready retry, provider
failure, success, and plugin-directory removal.

Owner: `uninstall.php` adapter over the future `UploadBatchStore` purge owner.

Depends On: P0-T1 grant-lifetime and purge-barrier decisions.

Rollback: The proof uses an isolated WordPress install and disposable provider
namespace. It must not point at production credentials or customer objects.

Blast Radius: Disposable plugin files, database records, and test objects only.

Failure Mode: Any false success, lost retry instruction, premature plugin-file
removal, or non-resumable barrier rejects the standard two-attempt adapter.

Done When:

- Every entrypoint named in the proposal's T0 proof result has evidence.
- Termination preserves files and resumable state everywhere, so the normal
  two-attempt deletion adapter is selected without a second purge state
  machine.
- The selected behavior is reflected in the proposal before P1 begins.

Verified via:

```sh
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
php tests/wp-runtime/run.php
```

Proof: The harness passed on a fresh disposable WordPress 7.0.1 installation on
2026-07-22. Blocked wp-admin AJAX/REST requests returned HTTP 503, blocked
WP-CLI requests returned nonzero, plugin files and the persisted barrier
survived, and ready retries completed. Both AJAX queue and server-fallback bulk
orders proved their sequential behavior while eForms remained retryable. The
existing WordPress-runtime smoke harness also passed.

### Phase 0 acceptance

- P0-T1 and P0-T2 are complete.
- The canonical PHP, WordPress runtime, and browser baselines remain green.
- No target runtime code, production provider state, or active contract claims
  have been changed prematurely.

## Phase 1 — First end-to-end slice: local authoritative artifact, no preview processing

This phase must leave one fully usable local composition: authorize, upload,
inspect, commit, recover, remove, finalize, review/download, GC, and uninstall.
It is the correctness foundation for R2, not a throwaway prototype.

### [x] P1-T1 — Replace the aggregate and capacity model

Type: Persistence/API seam replacement with tests and active contract sync.

Artifacts: `src/Anchors.php`, `src/Uploads/UploadBatchStore.php`,
`src/Uploads/ManagedCapacityStore.php`, `src/Uploads/UploadPolicy.php`, up to two
focused new local inspector/storage helpers under `src/Uploads/`,
`tests/fixtures/staged_upload_contract.json`,
`tests/integration/test_upload_batch_store.php`,
`tests/integration/test_staged_image_policy.php`,
`tests/integration/test_gc_staged_uploads.php`,
`docs/contracts/Runtime_Storage.md`, `docs/Architecture_Router.md`, and
`docs/Owner_Index.md`.

Interfaces: Versioned authoritative-artifact manifest; bounded durable intents;
committed items; delete-pending tombstones; strict artifact facts `{object key,
immutable version/integrity identity, exact bytes, detected MIME, width,
height}`; first-commit `accepted_at`; physical-object reservations; fixed
object-budget-then-aggregate lock order.

Owner: `UploadBatchStore` public facade and private `ManagedCapacityStore`.

Depends On: Phase 0.

Reuse Target: Existing batch derivation, aggregate locking, atomic metadata
write, manifest validation, lifecycle lease, and capacity reconciliation.

No-Fallback Rule: No version-2 normalized-manifest reader and no dual manifest
writer. There are no production staged manifests to migrate.

Replacement: Accepted artifact and intent/tombstone schema replaces
source/master/preview derivative membership and managed derivative bytes.

Superseded Seams: Required normalization, required SHA-256 derivative gate,
derivative paths, and derivative capacity reservations.

Complexity Budget: `UploadBatchStore` stays the facade; physical inspection and
local byte persistence may be collaborators. No new repository/service layer.

Done When:

- Local acceptance durably writes one immutable artifact and commits only after
  authoritative bounded inspection.
- Exact reauthorization/completion is idempotent; changed bindings fail
  generically.
- `accepted_at` comes only from the injected/testable WordPress commit clock and
  survives a later completion retry unchanged.
- Finalization rejects unresolved intents and a changed item snapshot.
- Removal writes a tombstone before deleting or releasing physical accounting;
  a late completion cannot resurrect it.
- Crashes may overcount until reconciliation but cannot undercount an object
  that may exist.
- Fixed values exist once in `Anchors`; callers and fixtures reference that
  owner.
- Runtime storage and owner docs describe the implemented schema and lock
  direction without future-tense ambiguity.

Verified via:

```sh
php tests/integration/test_upload_batch_store.php
php tests/integration/test_staged_image_policy.php
php tests/integration/test_gc_staged_uploads.php
php tests/unit/test_staged_upload_contract.php
rg -n "manifest\.json|managed-capacity|master_relpath|preview_relpath|source_sha256" src tests docs/contracts
```

### [x] P1-T2 — Adapt the local HTTP transport and preserve the browser UX

Type: Public protocol and material UI behavior change.

Artifacts: `src/Uploads/UploadBatchEndpoint.php`, `src/FormProtocol.php`,
`src/Rendering/FieldRenderers/Upload.php`, `assets/forms.js`,
`assets/forms.css` only if existing selectors cannot express a required state,
`tests/integration/test_upload_batch_endpoint.php`,
`tests/integration/test_upload_renderer.php`,
`tests/e2e/specs/staged_upload.spec.js`, and
`docs/contracts/Public_Contracts.md`.

Interfaces: Local same-origin multipart authorization/upload/completion;
provider-neutral browser response; `verifying` card state; full runtime freeze;
abort/retry/removal/recovery semantics.

Owner: `UploadBatchEndpoint`, `FormProtocol`, and the existing `forms.js`
staged runtime.

Depends On: P1-T1.

Reuse Target: Existing card DOM, queue, progress, object-URL preview,
announcement, submit-blocking, and complete freeze helpers.

No-Fallback Rule: One browser state machine. Local transport must not introduce
provider-specific cards, controls, or protocol names outside `FormProtocol`.

Done When:

- Transfer reaching 100% enters `verifying` and displays `Finishing upload...`;
  `Uploaded` appears only after manifest commit.
- The current three-transfer queue, retry, cancellation, removal, Clear all,
  recovery, validation rerender, multi-form isolation, and accessibility
  behavior remain intact.
- A server-reported finalizing/frozen state disables or removes mutation
  controls across all cards, picker/Choose, and Clear all through the complete
  freeze path; no visible control silently no-ops.
- Local no-processing acceptance succeeds without Imagick or derivative
  encoder readiness.
- Pre-transfer local authorization supplies the exact declared artifact bytes
  as its durable transient allocation claim; it never passes zero before PHP
  has created the multipart temp file.
- The existing visual hierarchy and responsive geometry do not change.

Verified via:

```sh
php tests/integration/test_upload_batch_endpoint.php
php tests/integration/test_upload_renderer.php
npm test --prefix tests/e2e
rg -n "processing|Processing|verifying|Finishing upload" assets/forms.js tests/e2e docs/contracts/Public_Contracts.md
```

### [x] P1-T3 — Complete local submission, review, GC, diagnostics, and uninstall

Type: Cross-cutting lifecycle completion.

Artifacts: `src/Submission/SubmitHandler.php`,
`src/Uploads/ReviewController.php`, `templates/pages/review-gallery.php`,
`src/Gc/GcRunner.php`, `src/Diagnostics/RuntimeHealthDiagnostic.php`,
`uninstall.php`, relevant integration tests, `tests/wp-runtime/run.php`,
`docs/overview.md`, `docs/contracts/Public_Contracts.md`, and
`docs/contracts/Runtime_Storage.md`.

Interfaces: Exact-item finalization; private submitted-image download;
preview-unavailable gallery state; manifest-driven local cleanup; current local
exclusive lifecycle purge.

Owner: Existing submission, review, GC, diagnostics, and uninstall adapters
through `UploadBatchStore`.

Depends On: P1-T2.

Done When:

- Submission commits the exact accepted-artifact snapshot and email/gallery
  consumers use the new public upload value shape.
- The local no-preview gallery never embeds an incompatible artifact as an
  image; it shows the existing card with an authorized submitted-image
  download.
- Preview absence and review failure never mutate submission state.
- Expired staged/finalized aggregates and delete-pending tombstones are cleaned
  idempotently; the aggregate persists while required deletion is pending.
- Runtime diagnostics report local artifact readiness without requiring image
  processing.
- Local uninstall preserves the existing lifecycle lease/barrier safety.

Verified via:

```sh
php tests/integration/test_staged_submission.php
php tests/integration/test_review_gallery.php
php tests/integration/test_gc_staged_uploads.php
php tests/integration/test_runtime_health_diagnostic.php
php tests/integration/test_uninstall_purge_flags.php
php tests/wp-runtime/run.php
```

### Phase 1 acceptance

- The entire local no-processing composition works end to end.
- All canonical PHP, WordPress runtime, and self-contained browser tests pass.
- Active contracts describe the authoritative artifact, not normalized
  derivatives.
- There is no requirement for Worker credentials, Imagick, or a generated
  preview to accept and finalize a local artifact.

## Phase 2 — Add the production Worker/R2 transport and remote lifecycle

### [x] P2-T1 — Build the canonical Worker protocol and ingress boundary

Type: New external trust boundary with cross-language contract tests.

Artifacts: a lean `worker/` package containing Worker source, tests,
`package.json`, deployment configuration, and a local owner README;
`src/Uploads/WorkerProtocol.php`; one shared language-neutral protocol fixture
under `tests/fixtures/`; PHP protocol tests.

Interfaces: Length-prefixed canonical messages; direction-specific domains;
version/key/environment binding; full-length base64url HMAC-SHA256; upload
grant, receipt, review grant, signed health request/result; exact-origin CORS
preflight; conditional R2 write; bounded `.info()` inspection.

Owner: `WorkerProtocol` for WordPress encoding/verification and the Worker
protocol module for edge verification/signing. The shared fixture owns
cross-language examples, not runtime behavior.

Depends On: Phase 1.

Reuse Target: The proposal's canonical signing pattern and existing eForms
base64url/HMAC conventions. Do not add JWT or sign JSON.

No-Fallback Rule: The Worker cannot call WordPress aggregate mutation methods,
accept cookies, list arbitrary objects, overwrite an object, or mint authority
outside the exact scoped grant.

Replacement: Worker streaming and signed immutable facts replace PHP receipt
of production artifact bytes and PHP image inspection.

Complexity Budget: One Worker entrypoint plus focused protocol/storage/image
modules only when tests demonstrate separate physical concerns. No framework,
queue, Durable Object, or provider abstraction.

Done When:

- PHP and Worker produce and reject the same canonical fixture vectors.
- Unknown versions/domains/environments/key IDs, reordered/missing fields,
  malformed encodings, expired messages, and wrong-object replay fail.
- A valid unauthenticated `OPTIONS` request returns only the exact configured
  CORS values and performs no grant check, body read, mutation, or receipt.
- The actual upload independently validates origin/method/headers/grant,
  requires known exact length, streams to a write-once key, reopens the exact
  version, bounds inspection, and signs a receipt.
- Wrong origin, unknown header, over-limit body, animated input, invalid media,
  or inspection failure cannot produce a committable receipt.

Verified via:

```sh
npm test --prefix worker
php tests/unit/test_worker_protocol.php
git diff --check
```

Proof: The shared PHP/Worker vectors, strict envelope rejection cases, exact
CORS preflight, streamed conditional write/retry, exact-version inspection,
animated/invalid-media cleanup, signed health result, canonical PHP lane, and
diff check passed on 2026-07-22. No WordPress route, browser transport, provider
credential, or production Worker deployment was activated; those remain in
P2-T2 and the configured integration lane.

### [x] P2-T2 — Connect WordPress intents, receipts, and browser XHR

Type: Public distributed completion path.

Artifacts: `src/Anchors.php`, `eforms.config.php.example`,
`src/Uploads/WorkerProtocol.php`, `src/Uploads/UploadBatchEndpoint.php`,
`src/Uploads/UploadBatchStore.php`, `src/FormProtocol.php`,
`assets/forms.js`, endpoint/store/browser tests, and affected active contracts.

Interfaces: Deployment-bound Worker composition; intent authorization;
short-lived upload grant; cookie-free XHR with progress/abort; signed receipt
completion; idempotent response-loss recovery; reservation settlement.

Owner: `UploadBatchEndpoint` delegates state to `UploadBatchStore` and signing
to `WorkerProtocol`; `forms.js` selects the returned transport without creating
a second queue.

Depends On: P2-T1.

Rollback: Before production activation, revert the cohesive task commits. After
R2 artifacts exist, do not switch silently to local storage; fail closed,
repair the configured composition, or execute an explicit retained-object
migration/expiry plan.

Failure Mode: Worker/R2 outage produces a retryable item failure. It cannot
commit, finalize, release uncertain physical accounting, or fall back locally.

Done When:

- Intent and reservation are durable before a grant is returned.
- The browser sends artifact bytes only to the Worker, without WordPress
  cookies; WordPress receives only bounded intent/completion payloads.
- Receipt completion revalidates intent, batch, tombstone, policy, exact object
  facts, count, and byte totals under the canonical lock order.
- Response loss and exact retry return one committed item; changed facts or a
  late receipt after removal fail generically.
- Browser progress, abort, retry, `verifying`, finalization freeze, and local
  transport behavior continue through the same runtime.
- Integration secrets are deployment references, never `wp_salt('auth')`,
  public settings, logs, manifests, or committed fixtures.

Verified via:

```sh
php tests/integration/test_upload_batch_endpoint.php
php tests/integration/test_upload_batch_store.php
npm test --prefix tests/e2e
npm test --prefix worker
rg -n "wp_salt|grant|receipt|object_key|provider" src assets tests | head -n 200
```

Proof: Deployment-constant composition, durable pre-transfer authorization,
scoped grants, bounded signed-receipt completion, conservative remote-delete
tombstones, and the existing browser queue's cookie-free Worker transport all
passed the canonical PHP lane, Worker suite, Playwright suite, targeted secret
scan, syntax checks, and diff check on 2026-07-22. The Playwright lane passed 16
tests with four configured live-environment tests skipped. Remote deletion,
diagnostics, restore, uninstall, and genuine-provider drills were deferred to
P2-T3; review delivery remained deferred to P3-T1.

### [x] P2-T3 — Extend cleanup, diagnostics, restore, and uninstall across R2

Type: Operational lifecycle and destructive-action safety.

Artifacts: one focused `src/Uploads/WorkerClient.php` if the physical outbound
boundary requires it, `src/Uploads/UploadBatchStore.php`,
`src/Gc/GcRunner.php`, `src/Diagnostics/RuntimeHealthDiagnostic.php`,
`src/Cli/GcCommand.php`, `uninstall.php`, Worker delete/health routes and tests,
`worker/README.md`, `tests/wp-runtime/`, and affected runtime-storage/overview
contracts.

Interfaces: Idempotent exact-version delete/absence check; expired-intent
cleanup; finalized GC; signed data-plane health; operator-held lifecycle-rule
verification; control-plane backup/restore; P0-selected uninstall drain.

Owner: `UploadBatchStore` owns traversal and deletion state;
`ManagedCapacityStore` owns physical accounting; `WorkerClient` performs only
bounded signed remote operations; diagnostics/CLI/uninstall adapt those owners.

Depends On: P2-T2 and P0-T2.

Rollback: Remote mutation commands support dry-run/inspection where meaningful.
Delete operations remain manifest-addressed, exact-version, idempotent, and
retryable. Lifecycle-management credentials stay operator-held and are never
available to WordPress or the Worker.

Blast Radius: One manifest-owned object for ordinary cleanup; the plugin's
private namespace for explicit uninstall; the lifecycle rule is a late
whole-namespace backstop only.

Observability: Structured events expose outcome class, latency bucket, retry,
cleanup phase, and budget totals without filenames, object keys, customer
values, grants, receipts, or secrets.

Done When:

- Failed deletion retains the tombstone, locator, version, and charged bytes;
  confirmed absence releases accounting exactly once.
- Expired intents release reservations only after the key is confirmed absent.
- GC never holds object-budget or aggregate locks across Worker/R2 calls.
- The actual R2 lifecycle rule is checked by an operator command and expires
  `artifacts/` strictly after the maximum application lifetime plus approved
  safety margin.
- Runtime health uses a signed non-customer data-plane fixture and cannot read
  lifecycle management configuration.
- The P0-selected uninstall adapter never waits in one request, never reports
  success during the grant-drain window or incomplete purge, and resumes one
  persisted purge state.
- A restore drill can locate the exact version, restore authorized review and
  conservative accounting, and later delete it.

Verified via:

```sh
php tests/integration/test_gc_staged_uploads.php
php tests/integration/test_runtime_health_diagnostic.php
php tests/integration/test_uninstall_purge_flags.php
php tests/integration/test_remote_uninstall_drain.php
php tests/integration/test_remote_restore_drill.php
php tests/unit/test_r2_lifecycle_verifier.php
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
npm test --prefix worker
EFORMS_CF_INTEGRATION=1 npm run test:integration --prefix worker
EFORMS_CF_ACCOUNT_ID=... EFORMS_CF_BUCKET_NAME=... EFORMS_CF_API_TOKEN=... php worker/scripts/verify-r2-lifecycle.php
```

Proof: On 2026-07-22, the disposable `eforms-media-p2t3` Worker and
`eforms-artifacts-p2t3` bucket passed the genuine-provider integration lane for
JPEG, PNG, WebP, HEIC, and the enabled HEIF alias, signed health, exact cleanup,
and the operator lifecycle check. The verified `artifacts/` lifecycle threshold
is at least 39 days. The disposable WordPress uninstall-drain proof also passed
before its database was stopped. A subsequent local rerun passed all 14 Worker
tests and the PHP protocol, client, and lifecycle-verifier unit suites; the
operator-only credentials remained outside the repository and unreadable to the
application user.

### Phase 2 acceptance

- The genuine-provider integration lane proves JPEG, PNG, WebP, and every
  enabled HEIC/HEIF alias with real inspection rather than extension fixtures.
- Remote upload, completion, response loss, removal, cleanup, health, review
  authorization prerequisites, restore, and selected uninstall behavior pass.
- Production writes remain disabled until Phase 6.

## Phase 3 — Optional signed bearer-link review previews

### [x] P3-T1 — Add Cloudflare and bounded local lazy-preview providers

Type: Optional presentation capability; no submission-state change.

Artifacts: Worker review/preview modules and tests,
`src/Uploads/ReviewController.php`, one bounded local preview provider under
`src/Uploads/`, `templates/pages/review-gallery.php`, `assets/forms.css` only
for a proven visual need, review/diagnostic tests, and affected public/runtime
contracts.

Interfaces: Expiring review grant for exact artifact/version/recipe; scoped
artifact download; fixed Cloudflare Images preview;
deterministic cache key; local per-object producer lock and global semaphore;
explicit unavailable/transient result.

Owner: `ReviewController` authorizes WordPress review; Worker and local preview
providers produce optional representations only.

Depends On: Phase 2.

Reuse Target: Existing signed bearer-link gallery, review card/grid/action
pattern, private response headers, and current bounded Imagick conversion logic
where it remains valid for lazy preview.

No-Fallback Rule: Cache misses/failures do not write manifest state, enqueue
work, invalidate a submission, or expose the artifact publicly. Cloudflare
mode does not fall back to local processing.

Superseded Seams: Required preview/master variants and `High-resolution`
normalized-master semantics.

Complexity Budget: One fixed preview recipe per provider. No processing queue,
status table, retry counter, scheduler, or configurable profile matrix.

Done When:

- Every Cloudflare gallery image requests the same bounded preview recipe,
  including JPEG/PNG/WebP; it never embeds the authoritative artifact directly.
- Bearer and grant validation happen before cache lookup or delivery, and
  responses retain private/no-store, no-referrer, no-sniff, expiry, and
  attachment behavior.
- Before expiry and with the signing key unchanged, the same unmodified email
  gallery link works in an independent browser or device context without login
  or cookies. Forwarding grants the same temporary access; modified, foreign,
  or expired links remain generically unavailable.
- Preview failure shows one authorized submitted-image download and leaves the
  manifest/finalization unchanged.
- Local provider default concurrency is 1; values outside the approved range
  reject that optional provider without disabling artifact upload.
- Same-object concurrent requests perform one conversion; different objects
  never exceed the global slot ceiling; saturation returns a bounded transient
  result.
- Local `none` review continues to work without Imagick.

Verified via:

```sh
php tests/integration/test_review_gallery.php
php tests/integration/test_runtime_health_diagnostic.php
npm test --prefix worker
npm test --prefix tests/e2e
EFORMS_CF_INTEGRATION=1 npm run test:integration --prefix worker
```

Implementation proof: On 2026-07-22, the canonical PHP lane, current Worker
suite, WordPress runtime smoke, and Playwright lane passed with the local and
Cloudflare review providers implemented. The local lane exercised real PNG and
HEIC conversion on an Imagick-capable host. After two consecutive clean
P0/P1/P2 review rounds, Worker version
`7c3a4aa0-0b90-4adf-88cd-c0689e04b2e6` was deployed to the disposable
`eforms-media-p2t3` Worker with R2, Images, cache, and the fixed upload-rate
limiter bindings. The genuine-provider lane then passed signed health, exact
cleanup, preview, and exact download for JPEG, PNG, WebP, HEIC, and the enabled
HEIF alias. The operator lifecycle verifier confirmed that `artifacts/` expires
no earlier than 39 days, and a fresh user-owned disposable WordPress/MariaDB
runtime passed the uninstall-drain proof. The previous Worker version
`369559d5-c852-4363-a581-f947c2ac5c70` remains the recorded rollback target.

## Phase 4 — Optional browser JPEG preparation, default off

### [ ] P4-T1 — Add the one-slot opportunistic preparer

Type: Optional browser performance capability and rollout experiment.

Artifacts: `assets/forms.js`, at most one dedicated browser worker asset,
renderer/config emission through existing owners, browser tests, representative
fixtures, and affected public/overview contracts.

Interfaces: Deployment capability `off|opportunistic_jpeg`; fixed code-owned
recipe/version; one preparation slot independent of the three upload slots;
abort/timeout/fallback; artifact choice before intent authorization.

Owner: Existing `forms.js` staged runtime; a Web Worker may own bounded
decode/resize/encode work but not authorization, queue, or cards.

Depends On: Phase 3. R2 activation does not depend on this task.

Reuse Target: Existing file cards, local object-URL previews, queue, abort,
retry, and FormRenderer settings emission.

No-Fallback Rule: Unsupported, failed, timed-out, insufficient-savings, color,
orientation, or memory-risk preparation uses the unchanged source only when it
is already within server policy. It never performs a full-pixel main-thread
fallback or uploads both files.

Replacement: None; this is a capability-gated optimization and defaults off.

Complexity Budget: JPEG input only initially. HEIC/HEIF, PNG, WebP, APNG, and
animated WebP bypass it. No WASM codec bundle, user settings, quality slider,
or processing profile.

Done When:

- Exactly one chosen artifact exists before intent authorization; reservation
  and declared facts match that artifact.
- Only eligible JPEGs show `preparing`; at most one prepares while up to three
  other uploads continue.
- Card removal aborts preparation and object URLs/decoded buffers are released.
- Safe failure falls back silently; an oversized source fails clearly if no
  compliant prepared result exists.
- Worker inspection remains authoritative and manifests contain no client
  preparation claims.
- Invalid deployment values fail validation; `off` exercises no preparation
  path and changes no authorization/transport/manifest behavior.
- Representative Chrome/Android and Safari/iPhone checks satisfy the approved
  recipe's memory, orientation, latency, and savings gates before Flooring
  Artists enables the capability.

Verified via:

```sh
npm test --prefix tests/e2e
php tests/integration/test_upload_renderer.php
rg -n "opportunistic_jpeg|preparing|Worker|createObjectURL|revokeObjectURL" assets src tests docs
```

Implementation status (2026-07-23): the default-off configuration, fixed
Anchor-owned recipe, one-slot Worker path, abort/timeout/fallback behavior,
pre-authorization artifact choice, diagnostics, contracts, and automated PHP
and Chromium coverage are implemented. The canonical PHP lane,
`tests/wp-runtime/run.php`, and the 47-test browser lane pass. P4-T1 remains
unchecked and `media.client_preparation` remains `off` because representative
Chrome/Android and Safari/iPhone hardware measurements have not yet been
recorded; a real desktop Chromium Worker test is not a substitute for that
rollout gate.

## Phase 5 — Converge on one implementation and remove obsolete artifacts

### [x] P5-T1 — Delete normalized paths and complete carrier sync

Type: Seam removal and repository convergence.

Artifacts: all touched upload/media source and tests;
`docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/overview.md`,
`docs/contracts/Public_Contracts.md`, `docs/contracts/Runtime_Storage.md`,
`tests/README.md`, and the staged contract fixture.

Interfaces: One authoritative-artifact manifest and one active transport per
deployment composition.

Owner: Existing active carriers and source owners.

Depends On: P1 through P4 implementation checks. P4 may remain capability-off,
but its code must be either complete or omitted; no half-built path remains.

Reuse Target: Keep still-valid private-root hardening, image detection,
object-URL preview, signed bearer-link review authorization, lifecycle lease,
GC traversal, fixtures, and race tests.

No-Fallback Rule: Do not retain old code “just in case.” Git history is the
recovery mechanism.

Replacement: Authoritative artifact plus optional preview replaces normalized
master/preview acceptance.

Superseded Seams: Manifest v2 reader/writer, `master.jpg`/`preview.jpg`
membership, required normalization/readiness, derivative capacity arithmetic,
old review variants/copy, and tests whose only purpose is compatibility with a
never-deployed manifest.

Closure Candidate Scope: `src/Uploads/`, upload paths in submission/review/GC/
diagnostics/uninstall, `assets/forms.js`, review templates/styles, staged
fixtures/tests, and affected active carriers.

Removal Proof:

- no production reader/writer accepts manifest v2;
- no committed item requires master/preview derivative fields;
- no runtime success gate invokes normalization or derivative readiness;
- no provider/runtime picker or automatic fallback exists;
- no generated output, historical plan, abandoned fixture, or parallel
  proposal remains in the implementation commit.

Leftover Check: Search the full repository for old fields, variants, copy,
constants, and compatibility branches; classify any match as required retained
local-preview implementation or remove it.

Complexity Budget: Apply the source-size gates from this plan after removal,
not before.

Done When:

- All active carriers, code, tests, and fixtures describe one implemented
  contract.
- Obsolete tests are replaced by equivalent invariant/race coverage before
  removal.
- `UploadBatchStore.php` and focused PHP LOC gates pass or the required owner
  review resolves the overage.
- The superseded proposal leaves no unique behavior authority behind. Its
  durable rationale belongs in `docs/PAST_DECISIONS.md`, not a parallel carrier.

Verified via:

```sh
rg -n "master_relpath|preview_relpath|master\.jpg|preview\.jpg|STAGED_MASTER_|required normalization|Processing" src assets templates tests docs
rg -n "storage_driver|media\.storage|media\.preview|Uppy|tus|multipart upload|background processing" src assets templates tests
wc -l src/Uploads/*.php src/Gc/GcRunner.php src/Diagnostics/RuntimeHealthDiagnostic.php uninstall.php
wc -l src/Uploads/UploadBatchStore.php assets/forms.js
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
php tests/wp-runtime/run.php
npm test --prefix tests/e2e
npm test --prefix worker
git diff --check
```

Matches are allowed only when they document an explicitly retained non-runtime
historical rationale or the local optional-preview implementation; each match
must be reviewed rather than accepted by count alone.

Implementation proof (2026-07-23): the never-production normalized derivative
path, its readiness Anchors/tests, the customer artifact-preview REST route and
binary adapter, the superseded proposal, and the historical normalized-upload
plan were removed. Optional Imagick readiness now belongs only to
`LocalPreviewProvider`; authoritative inspection remains independent of it.
Durable rationale and the complete Phase 5 closeout upload/media owner-family
LOC review are recorded in `docs/PAST_DECISIONS.md`. In the final reviewed
Phase 5/6 snapshot, `UploadBatchStore.php` remains above its original 3,004-line
signal at 4,695 lines, but the review found no additional separable
physical concern beyond the existing capacity, artifact, preview, and Worker
collaborators; extracting a generic manifest repository would split its
sole-writer/lock-order authority. `UploadPolicy.php` fell from 991 to 613 lines,
and the Phase 5 closeout change set removed 2,798 lines while adding 233. The
canonical PHP lane, WordPress runtime smoke, Playwright lane (all self-contained
tests passed; external cases environment-gated), Worker lane, seam scans, and
`git diff --check` passed on the final snapshot.

## Phase 6 — Production-readiness evidence and controlled activation

### [ ] P6-T1 — Prove performance, reliability, operations, and privacy posture

Type: Tier 3 operational acceptance; human review required.

Artifacts: reusable integration/load/restore/lifecycle-verifier commands and
their owner README/active contract; no committed secrets, customer files, raw
provider dumps, or generated reports.

Interfaces: Genuine Worker/R2/Images environment; real WordPress adapter;
representative browser/device/network profiles; backup/restore; lifecycle rule;
secret rotation; logging and privacy/vendor disclosures.

Owner: Deployment operator with runtime diagnostics and the owners established
in P2/P3.

Depends On: P5-T1.

Equivalent Work: Upload the same bounded fixture set and item counts through
the baseline local normalized path (from the baseline commit in an isolated
checkout), candidate local no-processing path, and candidate Worker/R2 path.
Measure selection-to-commit, transferred artifact bytes, WordPress request-body
bytes, WordPress request duration/peak memory, retries, and cleanup outcome.

Request/Work Graph: Capture browser -> WordPress intent, browser -> Worker
stream, Worker -> R2/Images, browser -> WordPress completion, WordPress ->
Worker review/delete/health. Prove no candidate request creates a hidden second
artifact upload, image decode on S1, or lock held across network/image work.

Primary Metrics and Acceptance:

- Worker/R2 mode sends zero artifact-body bytes through WordPress and performs
  zero full-image decodes on S1.
- With preparation off, median selection-to-committed time for each fixture on
  a fixed network profile is no worse than 10% above the baseline; if it is,
  inspect intent/completion overhead before activation.
- Transfer completion to manifest commit remains bounded and visible as
  `Finishing upload...`; the operator-approved P6 threshold is met without
  preview work.
- No accepted artifact is lost, duplicated, resurrected, undercounted, or made
  public under injected response loss, retry, cancellation, provider failure,
  GC retry, restore, or secret rotation.

Control and Noise Gate: Use the same browser/device, fixture bytes, concurrency,
network profile, region, and warm/cold classification for at least five runs.
Record median and worst run. Rerun when environmental variance exceeds 20% or
provider throttling contaminates only one candidate.

Regression Gate: Any S1 artifact-body transit/decode, false `Uploaded` state,
manifest/object divergence, public cache leak, irreversible cleanup failure, or
LOC stop threshold blocks activation regardless of latency.

Rollback: Before enabling production writes, preserve a tested code/config
rollback. After the first R2 artifact exists, rollback is fail-closed repair or
an explicit retained-object migration/expiry procedure—not silent local
fallback. Keep lifecycle cleanup and old-key verification available through the
approved rotation window.

Done When:

- Genuine Cloudflare tests cover boundary-size, ordinary phone JPEG/PNG/WebP,
  every enabled HEIC/HEIF type, malformed/animated media, response loss,
  deletion failure, preview failure, and origin/environment mismatch.
- Operator-held lifecycle verification, signed runtime health, backup/restore,
  grant/secret rotation, emergency rotation, and uninstall drain drills pass.
- Logs and metrics prove the required outcomes without customer or credential
  data.
- Privacy/vendor documentation reflects Cloudflare as a data processor and the
  accepted artifact's possible metadata retention.
- A human reviews security, UX, provider configuration, deletion behavior,
  measurements, and the exact production composition.
- Client preparation remains off until its separate representative-device gate
  passes; it is not required for R2 activation.

Verified via:

```sh
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
php tests/wp-runtime/run.php
npm test --prefix tests/e2e
npm test --prefix worker
EFORMS_CF_INTEGRATION=1 EFORMS_CF_REPRESENTATIVE_MEDIA=1 EFORMS_CF_REPRESENTATIVE_DIR=/secure/non-customer-phone-fixtures EFORMS_CF_FAILURE_MATRIX=1 EFORMS_CF_FAULT_COMMAND=/secure/path/set-disposable-worker-fault EFORMS_CF_ROTATION_MATRIX=1 EFORMS_CF_ROTATION_COMMAND=/secure/path/rotate-disposable-worker-keys EFORMS_CF_WORDPRESS_ROTATION_PROBE_COMMAND=/secure/path/probe-disposable-wordpress-rotation EFORMS_CF_SECONDARY_KEY_ID=... EFORMS_CF_SECONDARY_KEY_B64=... EFORMS_CF_EMERGENCY_KEY_ID=... EFORMS_CF_EMERGENCY_KEY_B64=... npm run test:integration --prefix worker
EFORMS_REMOTE_RESTORE_INTEGRATION=1 EFORMS_WORKER_URL=... EFORMS_SITE_ORIGIN=... EFORMS_WORKER_ENVIRONMENT_ID=... EFORMS_WORKER_ACTIVE_KEY_ID=... EFORMS_WORKER_ACTIVE_KEY_B64=... php tests/integration/test_remote_restore_drill.php
EFORMS_PERF_BASELINE_URL=... EFORMS_PERF_LOCAL_URL=... EFORMS_PERF_WORKER_URL=... EFORMS_PERF_BASELINE_BUILD_ID=... EFORMS_PERF_LOCAL_BUILD_ID=... EFORMS_PERF_WORKER_BUILD_ID=... EFORMS_PERF_WP_METRICS_COMMAND=... EFORMS_PERF_NETWORK_PROFILE=... EFORMS_PERF_REGION=... EFORMS_PERF_WARM_COLD=... EFORMS_PERF_MAX_COMPLETION_TAIL_MS=... EFORMS_PERF_LATENCY_MS=... EFORMS_PERF_DOWNLOAD_KBPS=... EFORMS_PERF_UPLOAD_KBPS=... npm run test:performance --prefix tests/e2e
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
php tests/tools/assert-template-slugs.php
git diff --check
```

Implementation status (2026-07-23): the repository-owned harnesses and evidence
schemas are implemented. Deployment-specific privileged collectors and
controllers remain operator-owned P6 evidence rather than repository code.
`README.md` and `docs/overview.md` state the retained-metadata
and Cloudflare data-processor obligations; `worker/README.md` owns the genuine-
provider/lifecycle, representative-media, provider-backed restore, controlled
normal/emergency rotation mechanics, timed normal-retention gate, backup,
rollback, and controlled performance
commands. The performance command requires closed build,
composition, server-work, accounting, and exact-absence facts from a privileged
collector and emits no tracked report. The restore drill now exercises retained
finalized, open-intent, delete-pending, capacity, and interrupted-purge state.
The core Worker integration's discarded-response retry is application-level
evidence only; transport-level response loss still needs controlled external
fault injection. P6-T1 remains unchecked: this workspace has no configured `EFORMS_WORKER_URL`/
integration credentials, Cloudflare lifecycle management credentials,
disposable WordPress sentinel/path, three isolated performance deployments,
representative media/device/network evidence, a disposable rotation controller,
timed normal-rotation retention evidence,
production privacy/vendor approval,
controlled transport-loss evidence, or human sign-off. The genuine-provider
command fails closed at the missing Worker URL, and the lifecycle verifier
reports `operator_configuration_invalid`; neither is accepted as substitute
evidence.

### [ ] P6-T2 — Activate deliberately and retire transient planning artifacts

Type: Explicit operator action and documentation retirement.

Artifacts: deployment configuration/secrets outside the repository; final
active carrier corrections if evidence found drift; deletion of this plan after
completion.

Interfaces: One Flooring Artists production composition: Worker/R2 artifacts,
Cloudflare lazy previews, no automatic local fallback, client preparation off
unless separately accepted.

Owner: Human deployment operator.

Depends On: P6-T1 and explicit production approval.

Done When:

- Revalidate the target before mutation: the documented `s1` webhead and page
  1541 must still be the production owners, the current page copy and managed-
  aggregate state must be recorded, and any dirty/pruned plugin or theme
  checkout must be snapshotted rather than reset or pulled across.
- Move supported deployment-only values out of a checkout-local `src/Config.php`
  into `wp-content/eforms.config.php` or the deployment secret carrier without
  exposing credentials.
- Deploy the plugin and pass web plus WP-CLI doctor/GC readiness before changing
  the staged template, appending its shortcode to the existing page content, or
  deploying the theme. The live page must contain only the plugin-owned uploader,
  not the retired theme node or script.
- Production readiness is green immediately before enabling writes.
- A canary upload, completion, operator preview/download, finalization, GC, and
  diagnostic check pass with non-customer data; live retry/removal, validation
  rerender, finalizing freeze, responsive layout, and expiry behavior also pass.
- The external GC schedule is observed running, both source worktrees contain
  only intentional deployment changes, and rollback retains review, capacity,
  GC, remote deletion, and old-key verification for existing aggregates.
- Monitoring and fail-closed recovery instructions are available to operators.
- Active contracts/code/tests contain every continuing rule; the proposal and
  this completed plan are removed rather than retained as parallel authority.

Verified via: The P6-T1 commands plus human review of the actual provider,
WordPress, browser, and operator workflow. Do not automate production mutation
from this plan.

Implementation status (2026-07-23): not started by automation. Production
mutation, canary evidence, monitoring confirmation, human approval, and final
plan retirement remain explicit operator actions after P6-T1 is green.

## Open evidence still required

These are deliberately phase-gated, not excuses to create generalized runtime
configuration:

| Item | Owner | Must be resolved by | Proof hook |
|---|---|---|---|
| Browser preparation recipe/savings gate | Browser runtime owner | P4-T1 | representative-device measurements |
| Production origin, R2 namespace, lifecycle rule, and credentials | Deployment operator | P6-T1 | operator verifier and genuine-provider tests |
| Transfer-completion-to-commit threshold | Product/deployment operator | P6-T1 before the performance run | `EFORMS_PERF_MAX_COMPLETION_TAIL_MS` gate |
| Privileged performance collector implementation and audit | Deployment operator | P6-T1 before the performance run | fail-closed `EFORMS_PERF_WP_METRICS_COMMAND` contract |
| Timed normal key-retention evidence | Deployment operator | P6-T1 before rotation acceptance | promotion/removal timestamps spanning `WORKER_SECONDARY_KEY_RETENTION_SECONDS` |

If any proof invalidates the target invariant—for example, Cloudflare cannot
authoritatively inspect an enabled format within the accepted bound, or no
supported uninstall entrypoint can preserve recoverable purge state—stop the
affected phase and revise the narrow active carriers before adding a fallback
path.
