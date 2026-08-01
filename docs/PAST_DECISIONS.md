# PAST DECISIONS

Architectural decisions made during Electronic Forms spec development.

## Design Principles

- **Origin-only CSRF**: Use Origin header (not Referer) as CSRF boundary.
- **Hidden tokens for idempotency**: Tokens prevent duplicate submits; CSRF defense is Origin's job.
- **No nonces**: Complexity/expiry issues; incompatible with caching.
- **No double-submit cookies**: Requires JS; not needed here.
- **wp_kses_post() for HTML fields**: Leverage WP's maintained allow-list; snapshot test catches behavior shifts.

## Architecture

- **No PSR-4**: WordPress-style includes keep bootstrap explicit.
- **Static config**: `Config::bootstrap()` provides immutable per-request snapshot.
- **File-backed state (no DB writes)**: Tokens/ledger/throttle live under `wp_upload_dir()`; avoids schema/migrations and keeps runtime dependencies minimal.
- **Mode-authoritative tokens**: No cross-mode fallback; POST cannot change modes.
- **No FormManager**: Split between FormRenderer and SubmitHandler.

## Major Simplifications

### Removed: Cookie-mode, NCID, Slots, `/eforms/prime`

**Was**: 700+ lines covering cookie lifecycle matrices, slot union logic, NCID rerender contracts for edge cases (cookie-disabled users, cached pages, 12 form instances).

**Now**: Single `/eforms/mint` endpoint returns unique tokens per form instance. Requires JS for cacheable pages. Reduces spec ~280 lines.

### Removed: Success Verification System

**Was**: Ticket files, success cookies, `/eforms/success-verify` endpoint, GC—all to prevent users from visiting success URLs directly.

**Now**: Query parameter only. Success messages are idempotent (like GitHub, Stripe, Gmail).

## Design Choices

### Adopted: Authoritative-artifact pre-implementation safety profile

Before implementing the authoritative-artifact upload path, eForms fixed one
bounded media/object envelope, short-lived scoped Worker credentials around a
longer bounded transfer window, 30-day finalized retention, a staged two-key
rotation procedure, one shared resumable uninstall-drain record, generic
provider-neutral outage behavior, and a local preview concurrency ceiling.
Exact runtime values now live in `src/Anchors.php`; active contracts own their
behavioral meaning.

The rejected alternatives were a file limit too close to the provider's hard
inspection ceiling, hour-long bearer grants, an active-plus-previous-only key
deployment that creates a cross-runtime mismatch window, automatic local
fallback during R2 outage, and unbounded or broadly configurable local image
conversion. P0-T2 chose the WordPress uninstall entrypoint from real deletion
evidence without reopening the drain state or purge ownership.

### Adopted: Normal two-attempt WordPress uninstall drain

A disposable WordPress 7.0.1 proof exercised wp-admin single-plugin AJAX
deletion, both multi-plugin queue orders, REST deletion, and WP-CLI with normal
deletion and `--skip-delete`. Incomplete and simulated provider-failed drains
returned HTTP 503 or a nonzero CLI status, retained the plugin files and retry
instructions, and resumed the same persisted barrier on a later ready attempt.
Normal ready attempts removed the plugin; `--skip-delete` intentionally retained
its files after running uninstall.

wp-admin bulk deletion is sequential rather than transactional. Its AJAX queue
continues to other plugins after eForms blocks; its server fallback stops at
eForms, so only plugins earlier in the list are deleted. Because every supported
entrypoint preserved the eForms safety invariant, eForms will use ordinary
WordPress deletion as the two-attempt adapter over the single purge owner. A
separate pre-uninstall admin/CLI state machine was rejected as unnecessary.

### Adopted: One authoritative artifact with optional review previews

Managed upload acceptance means that exactly one bounded, inspected artifact is
durably committed for an item. Browser preparation may choose that artifact
before authorization, but it never creates a second retained source. Review
previews are replaceable presentation caches: their readiness, generation, and
failure do not participate in upload, manifest, finalization, or email success.

This superseded the never-production normalized-master design. Its manifest-v2
reader, required `master.jpg`/`preview.jpg` generation, derivative capacity,
processing readiness gate, customer artifact-preview route, and compatibility
tests were removed without a migration layer; Git history is the recovery
mechanism. The accepted trade is that submitted HEIC/HEIF or metadata-bearing
artifacts may require operator download when an optional preview is unavailable.

Each installation binds one validated artifact/review composition. Runtime
provider pickers and automatic local fallback were rejected because they would
require per-item backend identity, parallel cleanup/accounting paths, and
ambiguous outage behavior. A backend change requires a retention drain or an
explicit migration. Optional browser JPEG preparation remains capability-gated,
defaults off, and does not change stored manifest semantics.

### Adopted: Batch-scoped artifact keys with canonical extensions

Managed artifacts use one storage namespace per upload batch:
`artifacts/{h2(batch_id)}/{batch_id}/{ordinal}-{intent_id}.{ext}`. The extension
is derived from validated MIME instead of the client filename. The sanitized
original filename remains manifest metadata for operator display and local
authorized-download naming. R2 stores the object at that exact key; local storage maps the same
key to a versioned immutable file. Submission finalization associates the batch
namespace with the submission manifest without copying or renaming bytes.

This replaced content-only opaque keys in greenfield mode. Schema 6 manifests,
schema 6 capacity records, and Worker protocol 2 reject the old object-key shape
instead of carrying a migration reader. Original client filenames in provider
keys were rejected because collision, path-safety, Unicode-normalization, and
later rename semantics would make lifecycle cleanup less deterministic without
improving operator presentation.

### Adopted: One stateless opaque review URL token

Managed gallery links use `/review/{token}`. Version 4 packs the submission UUID
and a 128-bit action-bound HMAC tag into one canonical unpadded base64url token;
gallery tokens are 44 characters. File and preview routes use the same codec and
add their manifest-owned upload ID inside the authenticated token. The token
therefore locates and authorizes its target without a database, token index,
directory scan, visible submission ID, or separate signature parameter.

The split `/?eforms_review={submission_id}&signature={signature}` route and its
member query parameters were removed rather than retained as aliases. A short
random identifier like a public image host would require a durable reverse
index and would provide weaker authorization if shortened for aesthetics. A
route-only rewrite would preserve the old credential model. The self-contained
codec keeps the existing salt-rotation invalidation and manifest availability
authority while producing the cleanest customer-facing URL.

An owner review retained `UploadBatchStore` as the public aggregate facade even
though it exceeds the original line-count signal. Its remaining size is active
aggregate traversal, intent/tombstone/finalization coordination, remote purge,
and object-budget-before-aggregate lock ordering. The proven physical concerns
already live in `ManagedCapacityStore`, `LocalArtifactStore`,
`LocalPreviewProvider`, and `WorkerClient`; extracting a generic manifest
repository solely to meet a line target would split the sole-writer authority
without removing state or behavior.

The final reviewed Phase 5/6 upload/media PHP focus slice was 13,303 lines versus the
8,117-line pre-feature signal. The newly approved Worker/R2 composition explains the
greater-than-3,000-line growth, but did not waive the owner review. The review
retained each owner for a distinct reason:

| Owner family | Decision |
| --- | --- |
| `UploadPolicy` / `HeifInspector` | Keep the small policy facade and its bounded, decoder-free HEIF parser; merging them would mix format parsing with public policy. |
| `LocalArtifactStore` / `LocalPreviewProvider` | Keep physical local-object operations separate from optional, replaceable preview cache/concurrency work. |
| `ManagedCapacityStore` | Keep capacity arithmetic and persistence private behind `UploadBatchStore`; no other caller may create a second accounting seam. |
| `WorkerProtocol` / `WorkerClient` | Keep canonical capability envelopes separate from bounded network transport; neither owns aggregate state. |
| `UploadBatchEndpoint` / `ReviewController` | Keep the customer upload HTTP adapter separate from authenticated operator review delivery. |
| `UploadBatchStore` | Keep the sole manifest writer and lock-order coordinator intact; its remaining breadth is aggregate behavior, not another physical concern. |
| `PrivateDir` / `UploadStore` / `UploadValue` | Keep established filesystem, legacy non-managed upload, and submitted-value owners; the feature did not duplicate them. |
| `GcRunner` / `RuntimeHealthDiagnostic` / `uninstall.php` | Keep orchestration, read-only health reporting, and the WordPress deletion adapter distinct; all delegate managed state changes to the aggregate owner. |

No further extraction removed a dependency or state machine. The rejected
alternative was a generic repository/service layer that would add indirection
while weakening sole-writer, exact-version, or lock-order invariants. This is an
explicit acceptance of the focus-slice growth, not a precedent to bypass future
LOC gates.

### Kept: Soft Signal Scoring

Weighted accumulation of spam signals (honeypot/timing/origin/challenge) with threshold. Provides tuning flexibility and visibility into "almost spam" patterns. Hard gates lose this insight.

### Kept: Synchronous Email (v1.0)

Async email deferred to v1.1+. On failure, user sees error immediately and can retry. No new subsystems.

### Applied: Delegated Email Transport (wp_mail)

Email delivery uses `wp_mail()`. SMTP transport details (retries/backoff, DKIM signing, provider debug transcripts) are delegated to the site's mail plugin/MTA rather than implemented inside this plugin.

### Applied: Manual GC Scheduling

The plugin MUST NOT schedule WP-Cron. Operators run `wp eforms gc` via system cron (or an equivalent external trigger). This keeps runtime request paths predictable and avoids surprise background scheduling on shared hosting.

### Superseded: Email-Failure Retry Marker

The earlier retry-marker rerender path used `eforms_email_retry=1` to skip `min_fill_time` after a server-side email failure. That path is superseded by virtual email-failure result pages: the original ledger reservation remains committed, submitted values are not preserved, no retry token is minted, and the customer sees fixed friendly copy.

### Applied: Throttle + Privacy Clarifications (s2.diff, s22.diff)

- Fixed spec contradiction about minting helpers and throttle checks
- Added `[THROTTLE_SOFT_THRESHOLD]` anchor, form-ID fanout guard, `/eforms/mint` HTTP response codes
- IP keying decoupled from `privacy.ip_mode` (rate limiting uses resolved IP regardless)
- Email failure UX normative (virtual result page with no submitted-value copy)

**Deferred**: Making throttle mandatory, deleting `cooldown_seconds`/`hard_multiplier` config keys.

### Applied: Unknown-Keys Drop-in Policy (Phase 0)

When a drop-in config file contains unknown keys, the entire drop-in override is rejected (fail-closed). The spec says "unknown keys MUST be rejected" but doesn't specify whether to reject just the unknown keys or the whole override. We chose fail-closed:

- **Rationale**: A drop-in with typos or stale keys likely has other problems. Silently ignoring unknown keys while applying known keys could produce surprising partial configurations.
- **Warning**: A single `EFORMS_CONFIG_DROPIN_INVALID` warning is emitted with `{path: '_root', reason: 'unknown_keys', keys: [...]}` listing all unknown paths.
- **Alternative considered**: "Ignore unknown, keep known" — rejected because it risks silent misconfiguration.
