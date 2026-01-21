# State Handoff (Live)

Only "run residue" that isn't captured well by `docs/Implementation_Plan.md`.

## Non-Obvious Implementation Notes
- Descriptor handlers: `TemplateValidator` validates handler IDs (`validator_id`/`normalizer_id`/`renderer_id`), but `TemplateContext` resolves them to callables and stores them as `handlers.v` / `handlers.n` / `handlers.r`.
- Test harness stubs: `eforms/tests/bootstrap.php` defines WP shims (`is_email()`, `apply_filters()`), but `apply_filters()` only forwards the first value argument; it also defines a stub `Logging` class that will shadow the real one unless `eforms/src/Logging.php` is required first.
- Private uploads + health: `PrivateDir::ensure($uploads_dir)` creates `${uploads.dir}/eforms-private/` + deny files; `StorageHealth::check()` is memoized per request and probes mkdir/rename/`fopen('xb')` semantics (use `StorageHealth::reset_for_tests()` to clear memoization).
- Upload render/validate are intentionally stubbed and may throw if invoked (preflight can still resolve handlers).
- `TemplateContext` memoizes contexts in a static cache keyed by `form_id::version`; in unit tests, repeated builds can return cached results unless you vary `id`/`version`.
- `Security::token_validate()` enforces POST `eforms_token` + `instance_id` regex guards before disk access; `eforms_mode` is optional and only a consistency check.
- Origin policy checks use `Origin` + server host/scheme to classify same/cross/unknown/missing; in tests set `$_SERVER['HTTP_HOST']` + `$_SERVER['HTTPS']`/`SERVER_PORT` or provide a request header map.
- `FormRenderer` keeps per-request static state (duplicate `form_id` detection, cache-header warning once); test helpers `reset_for_tests()` and `set_headers_sent_override()` exist, and it rewrites field `name` to `{form_id}[{field_key}]` (appends `[]` for multivalue).
