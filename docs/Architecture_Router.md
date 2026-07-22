# Architecture Router

This is the primary routing map for the eForms WordPress plugin implementation.

This repo uses this router, `docs/Owner_Index.md`, `docs/overview.md`, `docs/contracts/*`, affected owner docs/READMEs, code, and tests as the active implementation contracts.

## Project Doctrine

- Prefer one canonical owner for reusable contracts.
- Keep durable operator-facing behavior in `docs/overview.md`; keep owner lookup here and in `docs/Owner_Index.md`; keep executable detail in code and tests.
- Keep stable public/template/storage contracts in `docs/contracts/*`.
- Extend existing owners before adding shared layers; add a shared layer only when it removes live duplication.
- Do not add parallel compatibility paths, duplicate config/schema owners, or local bypasses for a documented owner.
- If an `agent_docs` guide references canonical spec or implementation-plan workflows, translate that requirement to this repo's active carriers instead of recreating those files.

## Main Subsystems

- Rendering: `src/Rendering/` loads templates, builds context, and renders forms. `EformsMarkup` owns shared HTML escaping and attribute serialization; `EformsAssets` owns plugin asset registration and enqueueing.
- Submission: `src/Submission/` handles public POST routing, virtual result-page GET routing, pipeline orchestration, ledger reservation, and result redirects.
- Managed uploads: `UploadBatchStore` owns authoritative-artifact intents, committed items, tombstones, immutable storage/deployment identity, staged/finalized aggregate state, capability-gated finalized-submission availability updates and deletion, remote purge traversal, and the external capacity facade. Store-private `ManagedCapacityStore` owns capacity-record persistence plus storage-owned artifact and transient-allocation reservation arithmetic; `LocalArtifactStore` owns write-once local artifact bytes and durable deletion fences. `UploadPolicy` owns accepted-media policy and delegates box- and association-bounded, decoder-free HEIC/HEIF container inspection to `HeifInspector`; `worker/src/heif.js` enforces that same policy at the edge. `WorkerProtocol` and `worker/src/protocol.js` own the environment-bound PHP/edge signing contract; `WorkerClient` owns bounded signed WordPress-to-Worker operations and rejects an aggregate fingerprint that does not match the configured Worker origin/environment before network access. The Worker owns conditional R2 writes, exact-version validation records, inspect/delete/review delivery, and signed immutable results but no aggregate state. `LocalPreviewProvider` owns optional local preview-cache production, producer locks, the bounded global semaphore, and bounded reclamation of safely aged preview-deletion fences. The facade retains aggregate traversal and object-budget-before-aggregate lock ordering. `UploadBatchEndpoint` owns batch HTTP requests; `ReviewController` owns signed gallery and local file/preview reads plus operator availability/deletion gates and short-lived Worker review grants.
- Operator review snapshots: `SubmissionReviewSnapshot` owns managed-photo lead-review sidecar shape, validation, summary projection, and descriptor-aware display rows; `UploadBatchStore` owns sidecar persistence under finalized aggregate locks. `SubmissionsAdmin` owns the narrow retained photo submissions Tools page and consumes storage facts instead of reading aggregate files directly.
- Security: `src/Security/` owns tokens, origin policy, challenge verification, throttling, and mint endpoint behavior.
- Spam policy: `src/Spam/ContentFilter.php` owns local blocked-term parsing, normalization, field selection, and matching; it does not own request behavior soft signals or threshold math.
- Validation and registries: `src/Validation/` owns template validation, field descriptors, normalizers, validators, and handler registries.
- Email and result pages: `src/Email/` owns outbound email assembly; `src/Submission/Success.php` owns result-page URL/query handling.
- Declined review: `src/DeclinedReviewLog.php` owns declined-submission content capture and file-backed reads; `src/Admin/DeclinedReviewAdmin.php` owns the Tools admin viewer.
- Admin settings: `src/Admin/SettingsAdmin.php` owns the Settings -> eForms page; `src/Admin/AdminSettingsStore.php` owns `eforms_admin_config` option I/O and option-name reuse; `Config` owns admin override validation, merge precedence, final snapshots, and effective-config provenance.
- Diagnostics: `src/Diagnostics/SpamSmokeDiagnostic.php` owns the spam smoke checks and result shape; `src/Diagnostics/RuntimeHealthDiagnostic.php` owns runtime health checks and result shape. CLI and admin surfaces are adapters only.
- Runtime safety: `src/WordPressRuntime.php` owns fail-closed wrappers for required WordPress APIs used by load-bearing runtime paths.
- Entropy: `src/Security/Entropy.php` owns secure random bytes and identifier generation for security-sensitive runtime identifiers.
- Browser assets: `assets/forms.js` owns client enhancement, JS minting, submit blocking, error focus behavior, the staged-photo same-document submission consumer, and the single staged-upload queue across local multipart and Worker transports. Plugin CSS is split by runtime surface into core form, managed upload, review gallery, and admin settings bundles.

## Contract Docs

- `docs/contracts/Public_Contracts.md`: public surfaces, stable machine-readable outputs, config source precedence, error/result stability, and browser asset contract.
- `docs/contracts/Template_Contract.md`: template file shape, field envelope, row groups, options, email block, sanitized fragments, and registry contract.
- `docs/contracts/Runtime_Storage.md`: private storage layout, token and ledger semantics, cache safety, upload policy, throttling, and GC.

## Dependency Direction

- Public entrypoints call rendering, submission, and security owners.
- Managed-upload endpoints, submission finalization, review reads, and GC call `UploadBatchStore`; no caller writes manifests or addresses capacity/artifact persistence directly. Artifact transfer and inspection do not hold object-budget or aggregate locks; deletion serializes absence with writes under the object lock and retains its fence through normal aggregate GC. Normal runtime never removes a fence; any whole-root removal is permitted only under the exclusive lifecycle purge barrier.
- WordPress signs Worker upload/review/health capabilities and verifies Worker receipts/results through `WorkerProtocol`; the Worker never calls aggregate mutation methods, and browser-carried tokens never become authority outside their signed scope.
- Rendering and submission consume validation/registry descriptors.
- Browser assets consume settings emitted by rendering; callers request canonical bundles through `EformsAssets` and do not parse templates or specs.
- Security and submission may emit metadata for email/logging; email/logging do not drive security decisions.
- Admin settings may write sparse config overrides only through the admin settings store. Runtime consumers read the frozen `Config` snapshot, not the raw WordPress option.

## Runtime Centers

- Server GET render: `FormRenderer`.
- Public POST and result GET: `PublicRequestController` routes POSTs to `SubmitHandler`, selects the existing HTML/redirect response or the negotiated private enhanced JSON response, and routes GET result args to fixed internal page templates. `FormProtocol` owns enhanced wire names; `Success` owns all accepted and email-failure result locations.
- Staged upload API: `UploadBatchEndpoint` handles batch create/status, deployment-bound transport authorization, local item upload, Worker receipt completion, and item deletion. Customer selection previews remain browser-local; submitted-artifact preview and download belong to `ReviewController` after finalization.
- Production artifact ingress, review, and lifecycle: `worker/src/index.js` handles exact-origin preflight, signed write-once R2 upload, exact-version media inspection, receipts, private fixed-recipe preview/download delivery, signed non-customer binding health, and exact-version inspect/delete operations. WordPress and `forms.js` drive signed ingress; `ReviewController` mints review grants only after validating gallery authority and manifest membership; `GcRunner` and uninstall adapt `UploadBatchStore` cleanup through `WorkerClient`.
- Managed review: `ReviewController` validates signed gallery/member bearers, renders galleries, and admits capability/nonce-gated availability updates and whole-submission deletion through `UploadBatchStore`. Member links remain WordPress-owned while the finalized manifest is available; each Worker-owned member request mints one fresh short-lived exact-version action and redirects, while local members stream artifacts/previews directly. `LocalPreviewProvider` and the Worker produce optional representations without manifest mutation. Persisted aggregate ownership, not the currently configured composition, selects review and cleanup storage.
- Retained submissions admin: `SubmissionsAdmin` registers a `manage_options` Tools page for currently retained photo-backed finalized submissions; traversal, sidecar identity checks, and deletion/availability mutation stay with `UploadBatchStore` and `ReviewController`.
- JS token mint: `MintEndpoint` plus `forms.js`.
- Template preflight: `TemplateValidator` plus registries.
- Admin configuration: `SettingsAdmin` for page orchestration, admin settings store for option persistence, and `Config` for merge/provenance.
- Spam smoke: `SpamSmokeDiagnostic` for checks and result shape; `SpamSmokeCommand` and Settings -> eForms only adapt presentation/invocation.
- Runtime health: `RuntimeHealthDiagnostic` for checks and result shape; `RuntimeHealthCommand` and Settings -> eForms only adapt presentation/invocation.

## Admin Settings Anti-Drift Gates

- Settings field matrix: one admin settings-field owner defines labels, groups, controls, and form-to-override mapping. It derives allowed config paths, types, ranges, enums, secret flags, nullable flags, and editability from `Config`.
- Provenance and source label decisions: `Config` owns effective-config provenance, externally-controlled status, and secret masking. Admin renderers display those facts; they do not recompute them.
- Seam guard: implementation must prove no raw full-config editor, no raw `eforms_admin_config` reads/writes outside the option owner/config bootstrap/uninstall cleanup, and no duplicate settings metadata or form-to-override mapper in per-group render branches.
