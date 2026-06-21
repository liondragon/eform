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

- Rendering: `src/Rendering/` loads templates, builds context, renders forms, and enqueues browser assets.
- Submission: `src/Submission/` handles public POST routing, virtual result-page GET routing, pipeline orchestration, ledger reservation, and result redirects.
- Security: `src/Security/` owns tokens, origin policy, challenge verification, throttling, and mint endpoint behavior.
- Validation and registries: `src/Validation/` owns template validation, field descriptors, normalizers, validators, and handler registries.
- Email and result pages: `src/Email/` owns outbound email assembly; `src/Submission/Success.php` owns result-page URL/query handling.
- Declined review: `src/DeclinedReviewLog.php` owns declined-submission content capture and file-backed reads; `src/Admin/DeclinedReviewAdmin.php` owns the Tools admin viewer.
- Admin settings: `src/Admin/SettingsAdmin.php` owns the Settings -> eForms page; `src/Admin/AdminSettingsStore.php` owns `eforms_admin_config` option I/O and option-name reuse; `Config` owns admin override validation, merge precedence, final snapshots, and effective-config provenance.
- Diagnostics: `src/Diagnostics/SpamSmokeDiagnostic.php` owns the spam smoke checks and result shape; `src/Diagnostics/RuntimeHealthDiagnostic.php` owns runtime health checks and result shape. CLI and admin surfaces are adapters only.
- Runtime safety: `src/WordPressRuntime.php` owns fail-closed wrappers for required WordPress APIs used by load-bearing runtime paths.
- Entropy: `src/Security/Entropy.php` owns secure random bytes and identifier generation for security-sensitive runtime identifiers.
- Browser assets: `assets/forms.js` owns client enhancement, JS minting, submit blocking, and error focus behavior.

## Contract Docs

- `docs/contracts/Public_Contracts.md`: public surfaces, stable machine-readable outputs, config source precedence, error/result stability, and browser asset contract.
- `docs/contracts/Template_Contract.md`: template file shape, field envelope, row groups, options, email block, sanitized fragments, and registry contract.
- `docs/contracts/Runtime_Storage.md`: private storage layout, token and ledger semantics, cache safety, upload policy, throttling, and GC.

## Dependency Direction

- Public entrypoints call rendering, submission, and security owners.
- Rendering and submission consume validation/registry descriptors.
- Browser assets consume settings emitted by rendering; they do not parse templates or specs.
- Security and submission may emit metadata for email/logging; email/logging do not drive security decisions.
- Admin settings may write sparse config overrides only through the admin settings store. Runtime consumers read the frozen `Config` snapshot, not the raw WordPress option.

## Runtime Centers

- Server GET render: `FormRenderer`.
- Public POST and result GET: `PublicRequestController` routes POSTs to `SubmitHandler` and GET result args to fixed internal page templates.
- JS token mint: `MintEndpoint` plus `forms.js`.
- Template preflight: `TemplateValidator` plus registries.
- Admin configuration: `SettingsAdmin` for page orchestration, admin settings store for option persistence, and `Config` for merge/provenance.
- Spam smoke: `SpamSmokeDiagnostic` for checks and result shape; `SpamSmokeCommand` and Settings -> eForms only adapt presentation/invocation.
- Runtime health: `RuntimeHealthDiagnostic` for checks and result shape; `RuntimeHealthCommand` and Settings -> eForms only adapt presentation/invocation.

## Admin Settings Anti-Drift Gates

- Settings field matrix: one admin settings-field owner defines labels, groups, controls, and form-to-override mapping. It derives allowed config paths, types, ranges, enums, secret flags, nullable flags, and editability from `Config`.
- Provenance and source label decisions: `Config` owns effective-config provenance, externally-controlled status, and secret masking. Admin renderers display those facts; they do not recompute them.
- Seam guard: implementation must prove no raw full-config editor, no raw `eforms_admin_config` reads/writes outside the option owner/config bootstrap/uninstall cleanup, and no duplicate settings metadata or form-to-override mapper in per-group render branches.
