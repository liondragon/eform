# Template Contract

This file captures template authoring and runtime contracts that are too exact for `docs/overview.md`. Runtime enforcement lives in `TemplateValidator`, `TemplateContext`, registries, renderers, and tests.

## Template Files

- Form templates live in `templates/forms/{form_id}.json`.
- Template filenames are lowercase slug files matching `^[a-z0-9-]+\.json$`; nested paths and slug transforms are not supported.
- Runtime loads templates lazily and never modifies template files in place.
- Malformed or incomplete templates produce a deterministic form configuration error instead of a white screen.

## Envelope

Required minimal shape:

```json
{
  "id": "contact",
  "version": "1.0",
  "title": "Contact Form",
  "fields": [],
  "email": {
    "to": "admin@example.com",
    "subject": "New message",
    "email_template": "default",
    "include_fields": []
  },
  "submit_button_text": "Send Message"
}
```

- `result_pages.success` and `result_pages.email_failure` may provide `title` and `message`.
- `rules[]` is a bounded cross-field rule list owned by the validator.
- `version` should be explicit. If omitted, runtime falls back to `filemtime()` for cache/version consumers.

## Fields

Field entries may declare:

- `key`, `type`, `label`, `placeholder`, `required`, `class`
- text-like hints: `size`, `autocomplete`, `max_length`, `min`, `max`, `step`, `pattern`
- choice metadata: `options`
- upload metadata: `accept`, `max_file_bytes`, `max_files`, `email_attach`
- sanitized fragments: `before_html`, `after_html`

Field keys must match `^[a-z0-9_-]{1,64}$`. Square brackets are forbidden. Reserved runtime names are forbidden: `form_id`, `instance_id`, `submission_id`, `eforms_token`, `eforms_hp`, `eforms_mode`, `timestamp`, `js_ok`, `ip`, and `submitted_at`.

Renderer-generated names use `{form_id}[{field_key}]`, appending `[]` only for multivalue descriptors. Renderer-generated IDs use `{form_id}-{field_key}` with the shared ID cap helper when needed.

## Row Groups

- Row groups are pseudo-fields: `{ "type": "row_group", "mode": "start"|"end", "tag": "div"|"section", "class": "..." }`.
- Row groups do not declare `key`, do not carry submission data, and do not count toward field limits.
- Row groups may nest, but every start must be balanced by an end.
- `FormRenderer` must not auto-close dangling row groups; unbalanced groups fail preflight with `EFORMS_ERR_ROW_GROUP_UNBALANCED`.

## Options

- Choice fields use objects shaped as `{ "key": "...", "label": "...", "disabled": false }`.
- Stored submitted values are option keys, not labels.
- Author-supplied option order is preserved.
- Disabled options must not be accepted as submitted values.

## Email Block

- `email.to` is a string email address or list of string email addresses. Scalars normalize to a single-element list.
- Addresses validate as single addresses through WordPress `is_email()`; display names and comma-separated lists are not accepted.
- `email.subject` and `email.email_template` are required strings.
- `email.email_template` references `templates/email/{name}.txt.php` or `{name}.html.php`.
- `email.include_fields` may reference template field keys plus `ip`, `submitted_at`, `form_id`, `instance_id`, and `submission_id`.
- `email.display_format_tel` supports `xxx-xxx-xxxx`, `(xxx) xxx-xxxx`, and `xxx.xxx.xxxx`.

## HTML Fragments And Classes

- `before_html` and `after_html` are sanitized with `wp_kses_post`.
- Inline styles are forbidden.
- Fragments must not cross row-group boundaries.
- Template classes are preserved after token-level sanitization; class tokens should remain simple slug-like values.

## Registries

- Field types, validators, normalizers/coercers, and renderers are static internal registries.
- Consumers resolve handlers through registry `resolve()` helpers.
- Unknown handlers throw deterministic `RuntimeException` payloads containing `type`, `id`, `registry`, and `owner_path`.
- Do not add public filters for registry mutation.

