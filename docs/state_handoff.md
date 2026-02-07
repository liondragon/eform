# State Handoff (Live)

Only "run residue" that isn't captured well by `docs/Implementation_Plan.md`.

## Non-Obvious Implementation Notes
- Throttle ownership is centralized in `Security::enforce_throttle()` + `Throttle::check()`; mint/validate entrypoints consume that API and should not manipulate throttle files directly (see `docs/Canonical_Spec.md#sec-throttling`).
- Hidden-mode GET throttling still depends on server fallback: `FormRenderer` calls `Security::mint_hidden_record()` without passing a request object, so IP resolution can fall back to `$_SERVER['REMOTE_ADDR']`.
- `Security::token_validate()` emits `challenge_response_present` alongside `require_challenge`; SubmitHandler uses both so challenge verify runs when a provider token is posted even if the security gate didn’t mark challenge as required.
- Logging is now sink-routed in `Logging::event()`: minimal and JSONL follow `logging.mode`, while Fail2ban emission is independent of `logging.mode` and only writes for `EFORMS_ERR_*` codes (see `docs/Canonical_Spec.md#sec-logging`).
- Descriptor fingerprinting (`desc_sha1`) is request-scoped: `Logging::remember_descriptors()` is called from both `FormRenderer` and `SubmitHandler`; level `logging.level>=2` events reuse the remembered hash.
- JSONL events normalize URI to `path + only eforms_* query params` and only include normalized `ua`/`origin` when `logging.headers=true`; `Referrer` is never logged.
- Fail2ban lines always use resolved raw client IP (plaintext) regardless of `privacy.ip_mode`; this is separate from minimal/JSONL presentation rules.
- Harness gotcha still applies: `eforms/tests/bootstrap.php` stubs `apply_filters()` with a single argument and defines a stub `Logging` class unless `eforms/src/Logging.php` is loaded first.
