# eForms test execution strategy

This project uses a hybrid strategy:

- Default lane (canonical): pure-PHP harness tests under `eforms/tests/unit/`, `eforms/tests/integration/`, and `eforms/tests/smoke/`.
- WordPress-runtime lane: targeted smoke checks for public WP surfaces using a faithful runtime fixture.
- Static guard lane: narrow commands for plan-level seam and identity invariants.

The canonical command from repository root is:

```sh
find eforms/tests/unit eforms/tests/integration eforms/tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
```

This command is intentionally deterministic (`sort -z`) and fails fast on any test failure.

The WordPress-runtime hidden-mode smoke command is:

```sh
php eforms/tests/wp-runtime/run.php
```

This boots a faithful WordPress fixture, renders `[eform id="contact" cacheable="false"]` through the shortcode, submits hidden-mode POST data through the `template_redirect` public controller, verifies validation rerender, verifies success PRG, and verifies the follow-up virtual success and email-failure result pages.

The shipped-template slug guard is:

```sh
php eforms/tests/tools/assert-template-slugs.php
```

Use this before identity-sensitive lifecycle work. It fails if any `templates/forms/{form_id}.json` file declares an `id` that differs from `{form_id}`.

Optional browser checks (dev-only, separate lane) live under `eforms/tests/e2e/`.
They validate JS-minted and mixed-mode browser behavior and are run via Playwright.
