# Architecture Router

Routing map only. `docs/Canonical_Spec.md` remains the behavior authority.

## Project Doctrine

- Prefer one canonical owner for reusable contracts.
- Keep public behavior in the spec; keep owner lookup here and in `docs/Owner_Index.md`.
- Extend existing owners before adding shared layers; add a shared layer only when it removes live duplication.

## Main Subsystems

- Rendering: `eforms/src/Rendering/` loads templates, builds context, renders forms, and enqueues browser assets.
- Submission: `eforms/src/Submission/` handles public POST routing, virtual result-page GET routing, pipeline orchestration, ledger reservation, and result redirects.
- Security: `eforms/src/Security/` owns tokens, origin policy, challenge verification, throttling, and mint endpoint behavior.
- Validation and registries: `eforms/src/Validation/` owns template validation, field descriptors, normalizers, validators, and handler registries.
- Email and result pages: `eforms/src/Email/` owns outbound email assembly; `eforms/src/Submission/Success.php` owns result-page URL/query handling.
- Browser assets: `eforms/assets/forms.js` owns client enhancement, JS minting, submit blocking, and error focus behavior.

## Dependency Direction

- Public entrypoints call rendering, submission, and security owners.
- Rendering and submission consume validation/registry descriptors.
- Browser assets consume settings emitted by rendering; they do not parse templates or specs.
- Security and submission may emit metadata for email/logging; email/logging do not drive security decisions.

## Runtime Centers

- Server GET render: `FormRenderer`.
- Public POST and result GET: `PublicRequestController` routes POSTs to `SubmitHandler` and GET result args to fixed internal page templates.
- JS token mint: `MintEndpoint` plus `forms.js`.
- Template preflight: `TemplateValidator` plus registries.
