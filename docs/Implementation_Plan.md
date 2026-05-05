# Implementation Plan

- [x] EFORMS-FIX-001 Fix spam-fail threshold, upload rendering, and mint endpoint routing
  - Type: standard + formal-spec
  - Owner: `SubmitHandler` for POST spam decisions; `FieldRenderers_Upload` for upload controls; `FormRenderer`/`forms.js` for JS mint endpoint configuration.
  - Done When: threshold spam short-circuits before validation/email/uploads; `file`/`files` fields render usable controls; JS-minted forms use the WordPress-provided mint endpoint with `/eforms/mint` only as fallback.
  - Verified via: `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`; `php eforms/tests/wp-runtime/run.php`; `php eforms/tests/tools/assert-template-slugs.php`; PHP/JS syntax checks for touched files.
  - Reasoning: high

- [x] EFORMS-FIX-002 Strict clear-win protocol and registry cleanup
  - Type: seam-refactor + shared-ui-runtime + formal-spec
  - Owner: `FieldTypeRegistry` for real field types; `FormProtocol` for shared form control and JS mint protocol names; `TemplateValidator` for `row_group` pseudo-fields.
  - Done When: template validation derives real field-type support from `FieldTypeRegistry`; form control/reserved/mint names route through `FormProtocol`; lean architecture owner maps exist without changing `docs/Canonical_Spec.md`.
  - Verified via: `php eforms/tests/unit/test_registry_resolution.php`; `php eforms/tests/unit/test_shipped_templates_preflight.php`; `php eforms/tests/unit/test_template_schema_validation.php`; `php eforms/tests/unit/test_protocol_seam_guards.php`; `php eforms/tests/integration/test_form_protocol_contract.php`; `node --check eforms/assets/forms.js`; `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`; `php eforms/tests/wp-runtime/run.php`; `php eforms/tests/tools/assert-template-slugs.php`.
  - Reasoning: high

- [x] EFORMS-FIX-003 Friendly email-failure recovery and admin notice
  - Type: standard + formal-spec
  - Owner: `SubmitHandler` for failed-send recovery and admin notification; `PublicRequestController` for customer-facing result-page output; `Emailer` for PHPMailer failure metadata.
  - Done When: `EFORMS_ERR_EMAIL_SEND` routes to a WordPress theme/page result with customer-friendly copy, no submitted-content copy summary, no retry token, and one metadata-only `wp_mail()` notice to the WordPress admin email.
  - Verified via: `php eforms/tests/integration/test_email_failure_rerender.php`; `php eforms/tests/wp-runtime/run.php`; `php eforms/tests/unit/test_error_codes_append_only.php`.
  - Reasoning: high

- [x] EFORMS-FIX-004 Virtual result pages and safe SMTP debug output
  - Type: result-flow-refactor + cross-owner-hardening + formal-spec
  - Owner: `Success` for result-page query URLs; `PublicRequestController` for result-page GET template swaps and local validation rerenders; `templates/pages/*` for fixed plugin-owned pages; theme `The_Artist_Theme::configure_phpmailer()` for SMTP debug routing.
  - Done When: successful POSTs and email-send failures return 303 to fixed `eforms_result`/`eforms_form` virtual result pages with no POST redirect body; validation/security failures still rerender locally; email-failure pages show only friendly copy; PHPMailer debug output is logged instead of echoed in browser responses.
  - Verified via: `php eforms/tests/integration/test_success_inline_flow.php`; `php eforms/tests/integration/test_success_redirect_flow.php`; `php eforms/tests/integration/test_email_failure_rerender.php`; `php eforms/tests/wp-runtime/run.php`; `node --check eforms/assets/forms.js`; `php -l /var/www/fa/wp-content/themes/the-artist/inc/class-the-artist-theme.php`; fake PHPMailer smoke.
  - Reasoning: high
