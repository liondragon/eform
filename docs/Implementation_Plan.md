# Implementation Plan

- [x] EFORMS-FIX-001 Fix spam-fail threshold, upload rendering, and mint endpoint routing
  - Type: standard + formal-spec
  - Owner: `SubmitHandler` for POST spam decisions; `FieldRenderers_Upload` for upload controls; `FormRenderer`/`forms.js` for JS mint endpoint configuration.
  - Done When: threshold spam short-circuits before validation/email/uploads; `file`/`files` fields render usable controls; JS-minted forms use the WordPress-provided mint endpoint with `/eforms/mint` only as fallback.
  - Verified via: `find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php`; `php eforms/tests/wp-runtime/run.php`; `php eforms/tests/tools/assert-template-slugs.php`; PHP/JS syntax checks for touched files.
  - Reasoning: high
