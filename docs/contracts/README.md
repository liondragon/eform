# Contract Docs

This folder contains stable implementation-facing contracts that are too detailed for `docs/overview.md` and too broad for a single owner entry.

Use these files only for contracts that cross public surfaces, template authorship, runtime storage, or machine-readable outputs. Keep local owner routing in `docs/Owner_Index.md`, subsystem routing in `docs/Architecture_Router.md`, and operator guidance in `docs/overview.md` or root `README.md`.

- `Public_Contracts.md` — public surfaces, config source precedence, machine-readable responses, error/result stability, and browser asset behavior.
- `Template_Contract.md` — template JSON shape, field envelope, row groups, options, email block, sanitized fragments, and registry contract.
- `Runtime_Storage.md` — private storage layout, token and ledger semantics, cache safety, upload policy, throttling, and GC.

Do not duplicate default values here. Runtime defaults live in code; fixed bounds live in `src/Anchors.php`; behavior is enforced by code and tests.

