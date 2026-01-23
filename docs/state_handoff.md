# State Handoff (Live)

Only "run residue" that isn't captured well by `docs/Implementation_Plan.md`.

## Non-Obvious Implementation Notes
- Descriptor handlers: `TemplateValidator` validates handler IDs, but `TemplateContext` resolves them to callables and stores them as `handlers.v` / `handlers.n` / `handlers.r`.
- Test harness gotcha: `eforms/tests/bootstrap.php` stubs `apply_filters()` (only forwards the first `$value` arg) and defines a stub `Logging` class unless `eforms/src/Logging.php` is loaded first.
- Storage contract probe: `StorageHealth::check()` is memoized per request; it probes atomic mkdir/rename and `fopen('xb')` exclusive-create semantics (test helper: `StorageHealth::reset_for_tests()`).
- Working tree note: this session introduced several new files (notably under `eforms/src/Email/`), so don’t forget to stage them when committing.
- Security gate: `Security::token_validate()` regex-validates `eforms_token` + `instance_id` before disk access; posted `eforms_mode` is a consistency check only.
- Submit pipeline knobs: `SubmitHandler::handle()` supports `overrides` (notably `template_base_dir` and `commit`) for deterministic integration tests; it also auto-selects `{form_id}[...]` payloads when present.
- Email headers: `Emailer::send()` strips CR/LF + control chars and truncates Subject/From-Name to 255 bytes; From stays on site domain; Reply-To uses `email.reply_to_address` then `email.reply_to_field`.
- Email-failure rerender: hidden-mode remints via `Security::mint_hidden_record()` and includes `eforms_email_retry=1`; JS-minted rerenders set `data-eforms-remint="1"` and leave token/instance/timestamp empty for forms.js to inject; rerender HTML includes a read-only copy `<textarea>`.
- Suspect signaling: `SubmitHandler` emits `X-EForms-Soft-Fails` and `X-EForms-Suspect` only when `soft_fail_count > 0` and headers are not already sent; `Emailer` prefixes the subject with `email.suspect_subject_tag` (default `[Suspect]`) for suspect deliveries only.
- Template slug pattern: `TemplateLoader::SLUG_PATTERN` is `/^[a-z0-9-]+$/` — underscores are NOT allowed in form_id/slug; use hyphens only (e.g., `contact-us` not `contact_us`).
- Success banner state: `FormRenderer` tracks shown success banners in `$success_banner_shown` static; subsequent renders of the same form_id suppress the banner (per spec "show banner only in first instance in source order").
- PRG success flow: `SubmitHandler::handle()` returns `success` (mode, message, redirect_url) and `form_id` in the result array; callers use `SubmitHandler::do_success_redirect($result, $options)` for the actual 303 redirect.
- Success dry_run: `Success::redirect()` accepts `dry_run => true` option for testing; returns `location` without actually sending headers/redirect.
