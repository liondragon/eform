# Managed Photo Uploads — Remaining Work Plan

> Mutable execution plan only. It lists unfinished work and is not behavior authority. Current behavior remains owned by `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/overview.md`, `docs/contracts/*`, code, and tests.

**Status:** Release blocked. The staged-upload foundation exists, but production activation waits on normalized review-master storage, the browser finalizing fix, complete verification, target-host readiness, theme migration, and human review.

**Goal:** Activate Virtual Quote with one plugin-owned staged-photo workflow that stores privacy-normalized JPEG review masters and previews, supports ordinary phone photo formats, preserves the existing retry/finalization/security model, and removes the page-specific theme uploader.

**Retirement rule:** Delete this plan after every task is complete and its durable behavior is carried by active contracts, code, tests, and operator documentation. Do not convert completed task cards into history.

**Rebase rule:** If an active carrier changes a cited contract, add a blocking rebase task before continuing.

## Authority and Preserved Invariants

The redesign changes the durable image artifacts, not the established trust or lifecycle model. Preserve all of the following:

- `UploadBatchStore` remains the public owner of managed aggregate state. Endpoints, submission, review, GC, diagnostics, and uninstall do not write manifests or capacity records directly.
- Batch identity, browser-secret authentication, exact form/token/field/policy binding, stable upload IDs, tombstones, and `open -> finalizing(submission_id) -> finalized` remain unchanged unless an active-contract review identifies a necessary versioned change.
- Endpoint credentials remain header-only; final form credentials remain protocol-owned hidden inputs and are stripped before customer-value handling.
- Finalization remains an atomic same-filesystem aggregate rename. Finalized aggregates remain immutable and undiscoverable through former batch routes.
- Email remains at-most-once with the durable pre-transport marker. Staged files remain excluded from attachments.
- Signed company review links remain private, time-limited bearer credentials. Customer gallery links are out of scope.
- Existing synchronous upload behavior remains unchanged.
- Cleanup remains externally scheduled through `wp eforms gc`; no WP-Cron, worker, database table, object storage, browser compression, cross-submission deduplication, refresh recovery, resend surface, or compatibility shim is added.
- Fixed processing and storage bounds live only in `src/Anchors.php`. No new user-facing configuration is introduced.

## Accepted Direction

### Options considered

1. **Normalize before production activation — selected.** Convert every accepted source into a high-resolution JPEG review master and a smaller JPEG preview, then discard the source. This removes source metadata, provides predictable review files, reduces retained storage, and avoids migrating live v1 galleries.
2. **Activate current original-plus-preview storage, then migrate.** This is faster initially but creates immediate manifest, route, capacity, gallery-copy, and retention compatibility work. Reject while no production dependency requires v1.
3. **Retain untouched originals permanently.** This is appropriate only for archival or legal evidence. It conflicts with the current product job—estimate-supporting photos—and retains unnecessary metadata and storage. Reject unless the product purpose changes.

**Devil's advocate:** The selected option depends on representative-photo quality testing and HEIC-capable Imagick on every participating host. If either requirement cannot be met, keep staged production activation off and rebase this plan; do not silently restore originals or add dual storage.

### Target artifact model

Each accepted item follows one deterministic path:

```text
temporary source
    -> validate source identity, size, dimensions, and resource bounds
    -> decode the primary image once
    -> normalize orientation and convert pixels to defined sRGB
    -> flatten transparency onto white and remove source metadata/profiles
    -> encode bounded review-master.jpg and preview.jpg
    -> validate and atomically commit both derivatives
    -> discard the temporary source
```

Use one vocabulary everywhere:

- **source:** temporary customer upload; never durable after terminal success or failure
- **review master:** durable high-resolution normalized JPEG used for detailed review/download
- **preview:** durable smaller normalized JPEG used by restored cards and gallery grids

The review master must never be described or exposed as an original.

## Feedback Triage

| Issue | Category | Decision |
| --- | --- | --- |
| Server-observed `finalizing` freezes only one rendered card and leaves other mutation controls visibly enabled | Contract bug | Fix before any activation and add a multi-item browser regression test |
| Durable originals should become normalized review masters before production | New feature / explicit contract change | Accepted by the user; implement before theme migration and activation |
| `UploadBatchStore` physically combines aggregate state with capacity persistence/reconciliation | Hardening | Extract one internal capacity collaborator only while changing storage semantics; retain `UploadBatchStore` as the public facade |
| Browser, real WordPress REST, image-backend, and target-host gates are incomplete | Hardening / release verification | Make every applicable gate mandatory before human sign-off |
| Generated test output and unrelated worktree changes can pollute the feature commit | Polish / release hygiene | Remove generated artifacts now and enforce intentional commit scope at release |

The accepted work changes the managed manifest schema, signed file variant, and staged `image` format contract. It adds no new error code, configuration key, endpoint, persistence backend, or public service owner. It must not weaken authentication, lifecycle, capacity, path-safety, redaction, or expiry rules, and it must not expand attacker-controlled mutation.

## Dependency Order

Execute tasks in numeric order. A task may start only when every dependency is complete.

## Phase 1 — Close Product Inputs and Contracts

### [ ] T1 Confirm the no-compatibility migration boundary and select exact derivative Anchors

- `Category:` Contract decision
- `Owner:` Product owner plus upload-policy implementer
- `Artifacts:` production inventory evidence; representative non-sensitive photo corpus; `src/Anchors.php` candidates
- `Depends On:` none
- `Work:`
  - Confirm submitted photos are estimate-supporting material, not archival or legal evidence.
  - Inventory production managed aggregates and confirm no finalized v1 staged galleries require retention. If any exist, stop and add an explicit migration/retention task before T2.
  - Confirm every production webhead can provide PHP `fileinfo`, Imagick, ImageMagick HEIC/HEIF decode, JPEG encode, and the existing memory/execution limits.
  - Test representative JPEG, PNG, WebP, HEIC, and HEIF photos, including orientation, transparency, wide-gamut color, large dimensions, detailed damage, and difficult compression cases.
  - Select exact fixed review-master and preview edge ladders, JPEG quality ladders, byte ceilings, and attempt counts. Record final values only in `src/Anchors.php` during T2.
- `Done When:` The product purpose, empty-v1 inventory, host capability, corpus results, and exact deterministic Anchor values are explicitly approved. No approximate number remains an implementation choice.
- `No-Fallback:` Do not introduce per-template derivative settings, preserve sources as overflow fallback, or accept a host that cannot process every staged `image` source.
- `Verified Via:` production aggregate inventory; target-host capability probes; reproducible corpus measurement summary; human approval

### [ ] T2 Replace v1 original semantics in active contracts before implementation

- `Category:` Contract change
- `Owner:` active carrier set and `src/Anchors.php`
- `Artifacts:` `docs/Architecture_Router.md`, `docs/Owner_Index.md`, `docs/overview.md`, `docs/contracts/Public_Contracts.md`, `docs/contracts/Template_Contract.md`, `docs/contracts/Runtime_Storage.md`, `README.md`, `src/Anchors.php`, affected contract fixtures
- `Depends On:` T1
- `Work:`
  - Define staged `image` as JPEG, PNG, WebP, HEIC, and HEIF without a separate HEIC template token; keep GIF rejected and synchronous policy unchanged.
  - Define the one-decode normalization order, review-master/preview artifacts, source-fact manifest fields, atomic two-derivative commit, and source deletion.
  - Bump the managed manifest version and replace managed `original*` fields with unambiguous `source_*` facts and `master_*` artifact fields.
  - Replace signed variant `original` with `master`; specify `.jpg` response filenames and “High-resolution” gallery language.
  - Define capacity as durable master plus preview bytes and active derivative reservations. Free-space safety must also account for temporary source bytes when they occupy the managed filesystem.
  - Add the T1 Anchor values and update readiness requirements. Do not duplicate their numbers in prose.
  - Keep `UploadBatchStore` as the canonical external owner while permitting one private capacity collaborator.
- `Done When:` Every active carrier describes one compatible target model, all former managed-original statements are removed, and contract fixtures encode the target manifest/route vocabulary.
- `No-Fallback:` No v1/v2 dual reader, `original` alias, optional normalization, source retention, HEIC opt-in token, or new configuration surface.
- `Verified Via:` stale-vocabulary scans; contract/fixture tests; template preflight; source analysis; `git diff --check`

## Phase 2 — Implement the Target Model

### [ ] T3 Generate review master and preview from one normalized decode

- `Category:` Image pipeline migration
- `Owner:` `src/Uploads/UploadPolicy.php`
- `Artifacts:` `src/Uploads/UploadPolicy.php`, `src/Anchors.php`, real image fixtures, `tests/integration/test_staged_image_policy.php`, affected policy/readiness tests
- `Depends On:` T2
- `Work:`
  - Validate extension, `fileinfo` MIME, source bytes, dimensions, pixel bounds, memory, and execution limits before expensive work.
  - Decode only the primary image; reject unsupported multi-image inputs.
  - Apply orientation, convert pixels to the defined sRGB output, flatten transparency onto `#ffffff`, then remove EXIF, GPS, XMP, IPTC, comments, and source profiles.
  - Produce review master and preview from the same normalized decoded image. Do not derive the preview by decoding the encoded master.
  - Apply the exact bounded Anchor ladders. Delete an oversized candidate before the next attempt; reject the whole item if either derivative cannot fit.
  - Return bounded source facts/digest and derivative facts/digests without exposing private paths.
- `Done When:` Every successful source produces two validated JPEG derivatives and every terminal path removes the source and partial outputs.
- `No-Fallback:` No second decode, untouched-source retention, conditional normalization, unbounded retry, source-as-master, or backend-specific behavior that changes the contract.
- `Verified Via:` real JPEG/PNG/WebP/HEIC/HEIF fixtures; primary-image HEIC; orientation; wide-gamut-to-sRGB; white alpha flattening; metadata/GPS absence; exact attempt sequences; residue tests; synchronous regression suite

### [ ] T4 Migrate manifests, atomic commit, capacity accounting, GC, and recovery

- `Category:` Persistence migration
- `Owner:` `UploadBatchStore` public facade with one internal managed-capacity collaborator
- `Artifacts:` `src/Uploads/UploadBatchStore.php`, new `src/Uploads/ManagedCapacityStore.php`, `src/Gc/GcRunner.php`, `src/Cli/GcCommand.php`, diagnostics/uninstall consumers, store/GC/recovery/uninstall tests
- `Depends On:` T3
- `Work:`
  - Implement the target manifest version with bounded source facts, `master_*`, `preview_*`, and aggregate managed-byte totals; retain no source path.
  - Reserve worst-case derivative capacity before encoding, atomically place both validated derivatives, update the manifest only after placement, and release reservations exactly once across failure/retry/crash paths.
  - Preserve stable upload ID/ordinal idempotency using source digest and exact binding.
  - Count only durable master/preview bytes after commit; keep ingress `max_file_bytes` and `max_total_bytes` over source bodies.
  - Extract capacity record locking, reservation settlement, health, and reconciliation into one private collaborator. `UploadBatchStore` retains public orchestration and lock-order authority; no caller addresses the collaborator directly.
  - Rename managed GC reporting from original bytes to master bytes and update reconciliation, tombstones, purge, health, and crash recovery.
- `Done When:` The target manifest is strictly validated, item commits are all-or-nothing, capacity never undercounts, source files do not survive, and existing finalization/GC/uninstall state transitions remain intact.
- `No-Fallback:` No compatibility reader unless T1 found real galleries, no public capacity API expansion, no split state owner, no direct manifest/capacity writes outside the facade/collaborator boundary.
- `Verified Via:` crash-point filesystem tests; upload/delete races; retry idempotency; corrupt manifest/capacity failure; reserve/settle/reconcile/GC/uninstall tests; owner seam scan; full PHP suite

### [ ] T5 Replace original routes, gallery language, and downstream consumers

- `Category:` API and presentation migration
- `Owner:` `ReviewController`, `Emailer`, and existing templates
- `Artifacts:` `src/Uploads/ReviewController.php`, `src/Email/Emailer.php`, `templates/pages/review-gallery.php`, email templates, protocol/contracts tests, WordPress runtime adapters
- `Depends On:` T4
- `Work:`
  - Accept only signed `preview|master` file variants and stream exact manifest-owned JPEG members.
  - Rename URLs, response filenames, context keys, templates, accessibility labels, and actions to “High-resolution”; remove every managed “Original” claim.
  - Keep gallery previews lazy-loaded and keep the company email to one signed gallery link with no staged attachments.
  - Preserve generic private denials, expiry, no-store/noindex/nosniff/referrer headers, constant-time signature checks, and path non-disclosure.
- `Done When:` No managed route, manifest, email, template, or gallery exposes `original` semantics; synchronous original-file vocabulary remains untouched where legitimate.
- `No-Fallback:` No alias route, redirect, dual signature vocabulary, customer gallery delivery, raw source stream, or direct filesystem access.
- `Verified Via:` signed preview/master integration tests; modified/expired/foreign/traversal denial tests; exact JPEG/header/filename assertions; email attachment regression; real WordPress REST/runtime test

### [ ] T6 Make server-observed finalizing freeze the complete uploader

- `Category:` Contract bug
- `Owner:` `assets/forms.js`
- `Artifacts:` `assets/forms.js`, `tests/e2e/specs/staged_upload.spec.js`
- `Depends On:` T2
- `Work:` Route a reconciled `finalizing` response through the shared full-runtime freeze path. Disable and clear the picker, disable Choose/Clear all, mark the mount frozen, and rerender every item so no visible Retry/Remove action remains inert.
- `Done When:` A finalizing response affecting one item freezes every card and field-level mutation control while submission-state messaging remains deterministic.
- `No-Fallback:` No second frozen state, item-only patch, clickable inert control, or claim that the browser observed `finalized`.
- `Verified Via:` multi-item Playwright race covering visible controls, picker, Choose, Clear all, handlers, mount state, and terminal language

## Phase 3 — Verify and Activate

### [ ] T7 Run complete plugin verification on capable environments

- `Category:` Release verification
- `Owner:` repository test owners
- `Depends On:` T3, T4, T5, T6
- `Work:`
  - Install locked browser dependencies with `npm ci --prefix tests/e2e`; do not change the lockfile.
  - Run the canonical PHP unit/integration/smoke suite, PHP lint, WordPress runtime smoke, real WordPress REST preview/master adapter, template slug guard, JavaScript syntax check, and all Playwright tests.
  - Run the image suite in an environment with GD where relevant and Imagick HEIC/HEIF support. Required release branches must pass rather than skip.
  - Run stale scans for managed `original` fields/routes/copy, direct manifest/capacity writes, credential/path leakage, duplicate protocol names, and obsolete HEIC opt-in behavior.
- `Done When:` Every required branch is green, every skip is proven unrelated to the release path, and failures map to a task rather than an undocumented exception.
- `Verified Via:` saved command output and clean test summaries; no generated test-results artifact is included in the commit

### [ ] T8 Provision the target host and activate Virtual Quote

- `Category:` Cross-repository production migration
- `Owner:` eForms plus `/home/zhenya/projects/the-artist`
- `Depends On:` T7
- `Artifacts:` target operations; `templates/forms/virtual-estimate.json`; theme uploader JavaScript/CSS/enqueue owner; theme E2E and active owner docs
- `Work:`
  - Provision protected private storage, managed capacity/free-space, PHP and web-server request limits, required memory/execution limits, enabled/tuned existing throttle, HEIC-capable Imagick, and externally scheduled GC.
  - Require target `wp eforms doctor` to pass every staged readiness check before changing the production template.
  - Activate staged `image` on Virtual Quote with its approved source count/byte limits and no attachments.
  - Delete the theme-owned uploader script, status markup, enqueue path, uploader-state CSS, and stale E2E assertions. Retain only surrounding layout and approved plugin CSS variables.
  - Verify upload, retry/removal, validation rerender, finalizing freeze, successful submit, company gallery, responsive layout, and expired-form behavior on the live route.
- `Done When:` The plugin is the only uploader owner, ordinary iPhone photos are accepted, the theme seam has zero matches, and the live workflow passes on desktop and mobile.
- `No-Fallback:` No parallel old/new path, selector alias, hidden old node, theme behavior adapter, or activation while doctor is red.
- `Verified Via:` target doctor; plugin Playwright; focused theme E2E; live responsive smoke; negative theme seam scan

### [ ] T9 Close carriers, commit scope, rollback handoff, and human release review

- `Category:` Release gate
- `Owner:` human reviewer
- `Depends On:` T8
- `Work:`
  - Recheck active carriers against implemented behavior and remove stale original/HEIC-opt-in/theme-owner wording.
  - Confirm both worktrees contain only intended changes. Exclude generated `test-results/`; handle the `agent_docs` submodule pointer separately unless it is deliberately part of another change.
  - Split commits into coherent contract, image/storage, browser, theme migration, and operational units where dependencies allow; do not hide untracked production/test files.
  - Document deployment order as plugin first, then theme. During rollback, disable new staged batches but retain review, capacity, and GC owners until existing aggregates expire.
  - Obtain human review of image privacy/quality, manifest migration, capacity/crash recovery, credential redaction, finalization/email ordering, signed review access, target readiness, and the full test evidence.
- `Done When:` Human sign-off is explicit, deployment/rollback owners are named, both worktrees are intentional, all gates are green, and this plan can be deleted without losing behavior or open work.
- `Verified Via:` final diff review; `git diff --check`; clean status inventory; deployment checklist; explicit human approval

## Release Commands and Seam Guards

```sh
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
php tests/wp-runtime/run.php
php tests/tools/assert-template-slugs.php
npm ci --prefix tests/e2e
npm test --prefix tests/e2e
node --check assets/forms.js
git diff --check

# Managed persistence writes remain behind the canonical facade/internal capacity boundary.
rg -n "manifest\.json|managed-capacity|capacity\.lock" src \
  --glob '!Uploads/UploadBatchStore.php' \
  --glob '!Uploads/ManagedCapacityStore.php'

# No managed v1 artifact or route vocabulary remains.
rg -n "original_relpath|original_bytes|candidate_original_bytes|deleted_original_bytes|preview\|original|eforms_review_variant=original" \
  src tests templates docs README.md

# Credentials and private implementation details do not reach presentation/email.
rg -n "batch_secret|batch_secret_digest|eforms_upload_batches|X-EForms-Batch-Secret|eforms-private|manifest\.json" \
  src/Email templates/email templates/pages

# No page-specific Virtual Quote uploader owner remains.
rg -n "virtual-estimate-upload-status|virtual-estimate\.js|data-selected-singular|data-selected-plural" \
  templates/forms/virtual-estimate.json /home/zhenya/projects/the-artist
```

Interpret scan results by owner: synchronous upload uses of “original” are legitimate; managed-upload matches require removal or an explicit test-only rationale. Behavior tests remain primary.
