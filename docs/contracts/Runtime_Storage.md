# Runtime Storage

This file captures runtime storage, token, upload, and maintenance contracts. Operator setup guidance belongs in `README.md` and `docs/overview.md`; exact implementation lives in code and tests.

## Private Storage Root

All runtime artifacts written under `wp_upload_dir()` live under:

```text
${uploads.dir}/eforms-private/
```

Runtime must create `index.html`, `.htaccess`, and `web.config` deny/protection files for private directories it owns. Directory permissions are `0700`; file permissions are `0600`. Permission or hardening failures fail closed on paths that need private storage.

## Layout

| Artifact | Path |
| --- | --- |
| Token records | `tokens/{h2}/{sha256(token)}.json` |
| Ledger guard, markers, and shard locks | `ledger.lock`, `ledger/{form_id}/{h2}/{submission_id}.used`, and `ledger/{form_id}/{h2}/.lock` |
| Uploads | Ordinary: `uploads/{Ymd}/{submission_id}-{field_ordinal}-{item_ordinal}-{sha16}.{ext}`; staged recovery: `uploads/{h2}/{submission_id}/{submission_id}-{field_ordinal}-{item_ordinal}-{sha16}.{ext}` |
| Open/finalizing managed batches | `staged/{h2}/{batch_id}/` |
| Finalized managed submissions | `submissions/{h2}/{submission_id}/` |
| Managed capacity state | `managed-capacity.json` and `managed-capacity.lock` |
| Upload lifecycle state | `upload-lifecycle.lock` and `managed-purged` |
| Throttle state | `throttle/{h2}/{ip_hash}.tally` and `.cooldown` |
| JSONL logs | `logs/` |
| Fail2ban file | `f2b/` unless configured otherwise |
| Declined-review files | `declined/` |
| GC lock/progress | `gc.lock` |

`{h2}` is the first two hex characters of `sha256(id)` and is derived through the shared helper.

## Filesystem Assumptions

- Token writes use write-to-temp plus rename in the same directory.
- Ledger reservation uses atomic exclusive-create while holding the stable shared `ledger.lock` guard and its shard lock. Any mutation permitted only for an unused token holds both locks from the final marker check through the mutation.
- Throttling and JSONL logging require reliable `flock()` semantics.
- Multi-webhead/container deployments must share persistent storage that preserves those filesystem semantics. Ephemeral per-container storage is unsupported.

## Token And Ledger Contract

- Hidden-mode GET render mints and persists a hidden token record before emitting hidden security fields.
- JS-minted mode obtains token records only through `POST /eforms/mint`.
- Token records include `mode`, `form_id`, `instance_id`, `issued_at`, and `expires`.
- Posted mode hints are informational; validated mode comes from the persisted token record.
- Token-record validation is read-only and does not mutate records, headers, or ledger state. Managed batch creation separately enters the ledger-owned unused-token guard before touching aggregate state.
- The ledger marker is reserved immediately before side effects. Batch creation and marker reservation share ledger serialization, so creation either completes before reservation or observes the consumed token and performs no managed-state mutation. `EEXIST` is duplicate submission; other filesystem failures map to ledger IO failure.
- Once a ledger marker exists, only `wp eforms gc` may delete it.
- Email-send failure after ledger reservation does not reopen the token.
- Managed finalization freezes an `open` aggregate as `finalizing(submission_id)` before ledger reservation only when the lock-held resolved item set exactly matches the snapshot already used for submission validation. A concurrent upload or deletion rejects the stale claim while leaving the batch open for a clean retry. Before `accept_until`, validation or challenge failure reopens an unused recovered claim. After `accept_until`, exact recovery keeps that claim `finalizing` and may re-emit its credentials for a corrected retry through `delete_after`. The matching submission may recover an established claim only before `email_attempted_at`; a foreign claim or any replay after the marker remains a duplicate failure.

## Cache Safety

Responses that embed hidden security metadata, respond to `eforms_*` result query args, or return PRG result pages must send private no-store cache headers. If headers cannot be set for a hidden-token response, runtime must not mint or emit hidden tokens in a cache-unsafe response.

## Upload Policy

- Synchronous upload token mappings:
  - `image` -> `image/jpeg`, `image/png`, `image/gif`, `image/webp`
  - `pdf` -> `application/pdf`
- Default exclusions include SVG and TIFF. Synchronous uploads exclude HEIC and HEIF. A staged field accepts only `image`, which maps to JPEG, PNG, WebP, HEIC, and HEIF while rejecting GIF.
- Validation requires agreement between accept token, extension, and `finfo` MIME result.
- `fileinfo` is required for upload attempts; if unavailable, upload attempts fail deterministically.
- Display filename starts from the client name but strips paths, control characters, CR/LF, unsafe dot/space runs, and excessive length.
- An ordinary synchronous stored filename remains private and collision-resistant at `{Ymd}/{submission_id}-{field_ordinal}-{item_ordinal}-{sha16}.{ext}`. A synchronous file accompanying a staged submission uses `{h2}/{submission_id}/{submission_id}-{field_ordinal}-{item_ordinal}-{sha16}.{ext}` so recovery ordinals and lookup are stable and bounded to that exact submission directory. Recovery ordinals come from the upload descriptor position and original request item position, so omitting an earlier optional field or item does not change a later file's identity. Validation and writes for every staged synchronous commit or recovery run through one exact-reuse-or-collision path under the finalized aggregate's per-submission lock.
- Runtime must not overwrite existing upload paths.
- Synchronous uploads are deleted after successful send unless retention applies. Non-staged failures and transport failures after a durable staged email-attempt marker follow synchronous retention policy. A staged local-preparation failure before that marker preserves the exact stored paths for the permitted recovery; a successful corrected commit removes preserved owner files omitted by the retry. When ordinary retention is zero, GC still reclaims abandoned staged-recovery files after `MANAGED_FINALIZED_TTL_SECONDS`.

### Managed staged images

- A staged field accepts JPEG, PNG, WebP, HEIC, and HEIF through the sole `image` token and rejects GIF. Accept token, extension, and `finfo` MIME must agree before decode; synchronous upload support does not change.
- Source dimensions are bounded by `STAGED_IMAGE_MAX_PIXELS` and `STAGED_IMAGE_MAX_EDGE`. Staged readiness also requires an effective PHP memory limit of at least `STAGED_IMAGE_MIN_MEMORY_BYTES` (or unlimited), an execution limit of at least `STAGED_IMAGE_MIN_EXECUTION_SECONDS` (or unlimited), and Imagick able to decode every staged source format and encode JPEG. Imagick is the only staged backend; partial-format fallback does not exist.
- After recording the sanitized source display name, byte count, MIME, dimensions, and SHA-256 digest, Imagick decodes the source once and selects only the primary image. It rejects unsupported multi-image input, applies orientation, converts the pixels to sRGB, flattens transparency onto `#ffffff`, and strips metadata and nonessential profiles. The review master and preview are independently resized and encoded from that same normalized in-memory pixel set, so the preview is never derived from the master.
- Review-master attempts derive from `STAGED_MASTER_MAX_EDGE`, `STAGED_MASTER_EDGE_STEP`, `STAGED_MASTER_JPEG_QUALITY_INITIAL`, `STAGED_MASTER_JPEG_QUALITY_STEP`, and `STAGED_MASTER_MAX_ATTEMPTS`; the encoded result must satisfy `STAGED_MASTER_MAX_BYTES`. Preview attempts use the corresponding `STAGED_PREVIEW_*` Anchors. Each attempt is encoded before materialization. If either derivative cannot satisfy its fixed ceiling, the item is rejected without committed residue.
- Both derivatives are written as `image/jpeg` to `master.jpg` and `preview.jpg` inside a new temporary item directory. Only after both files and their facts validate does `UploadBatchStore` atomically rename that directory to the committed item path while holding the aggregate lock. The uploaded source is never a committed member and is deleted on both success and failure before the response completes.
- Managed manifest version `2` is the sole accepted schema; no earlier-version reader or alias exists. Each item stores exactly `upload_id`, `ordinal`, `source_display_name`, `source_bytes`, `source_mime`, `source_width`, `source_height`, `source_sha256`, `master_relpath`, `master_bytes`, `master_width`, `master_height`, `master_sha256`, `preview_relpath`, `preview_bytes`, `preview_width`, `preview_height`, `preview_sha256`, `managed_bytes`, and `created_at`. Aggregate byte totals are `source_bytes` for ingress-policy enforcement and `managed_bytes` for durable master-plus-preview storage; master/preview breakdowns derive from the exact item records rather than duplicate aggregate fields. Tombstones refer to `master_relpath` and `preview_relpath`. Browser and email values never contain stored paths.

## Managed Aggregate Contract

- Each staged aggregate uses a stable adjacent `<batch_id>.lock`; each finalized aggregate contains a stable `.lock` alongside `manifest.json` and `files/`. Manifest and capacity-record writes use write-to-temp plus same-directory rename while their owning lock is held. Controllers, submission, email, review templates, and GC never write these files directly.
- `UploadBatchStore` derives `batch_id` from the exact public-contract fixture algorithm. One exact form/token/field/policy binding therefore has one locatable active path without an index or scan. The manifest stores only the batch-secret SHA-256 digest and the exact binding needed for verification.
- Aggregate state is `open -> finalizing(submission_id) -> finalized`. Upload and item deletion are valid only in `open`. Delete records an item tombstone before removing committed files so a late upload cannot resurrect the logical ID. Before accepting a new logical upload, the store reserves bounded tombstone capacity for every active item; once that lifetime bound is full, further distinct IDs are rejected. An absent delete remains idempotent at the bound, while deletion of an active item may replace a fully released tombstone without reopening upload admission. Finalizing and finalized aggregates are immutable through batch endpoints.
- Finalization writes the durable finalizing claim, holds the adjacent staged lock across the atomic same-filesystem directory rename to `submissions/{h2}/{submission_id}/`, then releases that source lock and acquires the destination's internal `.lock` before completing the finalized manifest. Rename does not change managed-capacity usage. If execution stops during that lock handoff, exact same-submission pre-email recovery acquires the destination lock and completes its `finalizing` manifest. After rename, no batch route looks up the submission aggregate.
- `UploadBatchStore` strictly validates the complete manifest schema, nested types, disjoint active-item/tombstone identities, item membership paths, accounting totals, policy fingerprint, state-specific fields, and aggregate identity before any caller consumes it. A staged manifest's `batch_id` must match its batch directory, and a submission manifest's claimed submission ID must match its submission directory. Structurally or semantically malformed or misplaced JSON fails closed as storage corruption.
- `accept_until` is the validated token record expiry. An abandoned open batch starts with `delete_after = accept_until + MANAGED_STAGED_DELETE_GRACE_SECONDS`; upload and submission end at `accept_until`, while open status/preview/delete may continue only until `delete_after`.
- Successful finalization sets `gallery_expires_at = finalized_at + MANAGED_FINALIZED_TTL_SECONDS` and replaces `delete_after` with `gallery_expires_at`. Only manifest-driven GC or explicit uninstall purge may delete a finalized aggregate.
- The global managed total includes durable master and preview bytes plus active derivative reservations; source bytes never enter that total. Managed capacity accounting requires 64-bit PHP integers; unsupported 32-bit runtimes fail readiness and capacity mutation before capacity-state I/O. Under `managed-capacity.lock`, reserve the two derivative ceilings before decode only when the resulting total does not exceed `MANAGED_UPLOAD_MAX_BYTES`. Observed free space, after subtracting every outstanding derivative reservation, the new derivative reservation, and temporary source bytes when the source occupies the managed filesystem, must remain at least `MANAGED_UPLOAD_MIN_FREE_BYTES`. Observation or lock/account failures fail closed. Each active upload attempt adopts the logical item's reservation with a fresh opaque attempt ID; only that current attempt may settle the reservation, so a late failure cannot release capacity already adopted by a waiting retry. Settlement subtracts the reservation's persisted byte count rather than a caller-owned duplicate. A successful item commit settles its reservation to the exact master-plus-preview bytes; if persistence of that settlement fails after the manifest commit, the request fails and an exact same-item retry repairs the committed reservation under capacity-before-aggregate locking before returning success. A normal upload, deletion, claim, or finalization mutation accepts a missing or zeroed capacity record only while the managed trees contain no derivative bytes; contradictory durable files fail closed rather than being treated as a fresh ledger. Diagnostics, explicit reconciliation, and GC may still inspect that state so they can report, rebuild, or remove it. Item deletion persists a matching capacity-release reservation and tombstone release intent before unlinking files; a retry can therefore distinguish a pending capacity write from an applied release and settle the exact bytes once. Accounting may conservatively overcount after a crash, but releases only after confirmed deletion; synchronous uploads remain outside this total. Health and explicit reconciliation inspect reservation-owned aggregates under the capacity-before-aggregate lock order, and zero-delta finalization rename shares the capacity lock so full scans are stable. Explicit `wp eforms gc --reconcile-capacity` repair recomputes managed derivative bytes, replaces already-committed active reservations with their exact file bytes, retains attribution for a reservation whose item files materialized before its manifest entry, and drops only unresolved stale reservations; it never counts physical files and their retained reservation contribution twice. Aggregate GC removes the retained reservation with its orphan files so ordinary cleanup releases the contribution exactly once. This full scan is not part of ordinary batch-bounded GC, and dry-run never mutates accounting.
- Finalized manifests persist `email_attempted_at` after local message preparation succeeds and immediately before transport invocation. This creates an accepted loss window if the process stops before `wp_mail()`; it prevents duplicate mail and is never cleared. Before this marker, the finalized aggregate lock serializes initial and recovered synchronous-file commits, and local preparation failures preserve the exact files. If a concurrent recovery loses the marker gate, it must not delete paths shared with the winning transport attempt; normal post-transport retention remains the winner's responsibility.

## Throttle Contract

- Throttle is optional and per resolved client IP.
- It uses a fixed 60-second byte-counter window plus optional cooldown sentinel.
- On lock acquisition failure, throttle enforcement is skipped for that request and a warning is logged when logging is enabled.
- Hard-fail response semantics:
  - hidden GET render: HTTP 200 inline per-form error with no-store cache headers
  - POST submit: HTTP 429 with `Retry-After`
  - `/eforms/mint`: HTTP 429 with `Retry-After`
  - staged create/upload: HTTP 429 with `Retry-After`, before decode or managed-state mutation
- Staged create/upload fail closed while the throttle is disabled. They reuse this throttle's existing window, cooldown, lock-failure behavior, and operator settings; no second limiter or configuration surface exists.

## GC Contract

- The plugin does not schedule WP-Cron for GC.
- Operators run `wp eforms gc` through system cron or an equivalent external trigger.
- GC uses a single-run lock to avoid overlapping work.
- Applying GC persists the next-family position and bounded cursors for every budgeted artifact family in `gc.lock` so repeated small runs advance regardless of scheduler cadence instead of rescanning an early fresh prefix. Managed aggregates resume within their shards; recursive file families resume after the last visited relative path; declined-review cleanup resumes after the last visited entry. Directory discovery streams lexicographically bounded in-memory pages instead of materializing whole directories; filesystem enumeration cost still follows the number of entries in the directory being visited. Dry-run reads but does not advance progress; losing progress only restarts discovery from the beginning.
- GC targets expired token records, eligible ledger markers and orphan ledger shard locks, stale throttle files, retained synchronous uploads plus abandoned staged-recovery files, expired managed aggregates, old logs, Fail2ban rotated siblings, and old declined-review files.
- Managed cleanup reads manifest `delete_after` and deletes one aggregate as a unit. Discovery and deletion never follow symlinked shards, aggregates, locks, manifests, or partial `files/` directories. A manifest-less staged directory is collected only when its identifier/shard, empty `files/` directory, and aggregate entries match the creation protocol and its observed age exceeds `TOKEN_TTL_MAX + MANAGED_STAGED_DELETE_GRACE_SECONDS`; exact owner-created initial-manifest temp files remain recognizable and recoverable, while corrupt manifests and unrecognized residue still fail closed. Dry-run and apply summaries report staged and finalized aggregates separately; pre-expiry finalized aggregates are never deleted.
- Ledger markers are eligible only after `TOKEN_TTL_MAX + LEDGER_GC_GRACE_SECONDS` from marker mtime. A ledger shard `.lock` is eligible on the same age gate only when its shard has no `.used` marker and GC can take the stable `ledger.lock` guard exclusively and then the shard lock without waiting. The root guard is never garbage-collected.
- Dry-run reports candidate counts and bytes without deleting.

## Uninstall Purge Contract

- The existing `install.uninstall.purge_uploads` decision owns all private upload lifecycle data; staged uploads do not add a second purge flag.
- When upload purge is disabled, staged/finalized aggregates and managed-capacity artifacts remain untouched.
- Every token, ledger, throttle, synchronous-upload, managed-upload, and upload-GC mutation holds a shared `upload-lifecycle.lock` lease before taking its owner-specific locks. When upload purge is enabled, uninstall takes that lifecycle lock exclusively without waiting, then acquires `managed-capacity.lock`, pre-acquires every staged/finalized aggregate lock, and writes the `managed-purged` barrier. With both global locks still held, it releases aggregate handles before deleting their roots so Windows can remove the lock files. A successful purge removes `managed-capacity.json` only after both aggregate roots are absent, then retains the lifecycle lock, capacity lock, stable ledger guard, and barrier so queued writers fail closed; activation removes only the barrier under the exclusive lifecycle lock.
- A lock-acquisition or deletion failure leaves recoverable residue and never broadens cleanup outside `${uploads.dir}/eforms-private/`.
