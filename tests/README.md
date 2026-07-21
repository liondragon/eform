# eForms test execution strategy

This project uses a hybrid strategy:

- Default lane (canonical): pure-PHP harness tests under `tests/unit/`, `tests/integration/`, and `tests/smoke/`.
- WordPress-runtime lane: targeted smoke checks for public WP surfaces using a faithful runtime fixture.
- Static guard lane: narrow commands for plan-level seam and identity invariants.

The canonical command from repository root is:

```sh
find tests/unit tests/integration tests/smoke -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
```

This command is intentionally deterministic (`sort -z`) and fails fast on any test failure.

The WordPress-runtime hidden-mode smoke command is:

```sh
php tests/wp-runtime/run.php
```

This boots a faithful WordPress fixture, renders `[eform id="contact" cacheable="false"]` through the shortcode, submits hidden-mode POST data through the `template_redirect` public controller, verifies validation rerender, verifies success PRG, and verifies the follow-up virtual success and email-failure result pages.

The staged-preview REST adapter also has a real WordPress-server regression:

```sh
wp --path=/path/to/wordpress eval-file tests/wp-runtime/rest-preview.php
```

It builds an isolated temporary aggregate and verifies that `WP_REST_Server` emits the exact JPEG bytes instead of JSON-encoding them.

The shipped-template slug guard is:

```sh
php tests/tools/assert-template-slugs.php
```

Use this before identity-sensitive lifecycle work. It fails if any `templates/forms/{form_id}.json` file declares an `id` that differs from `{form_id}`.

Optional browser checks (dev-only, separate lane) live under `tests/e2e/`.
They validate JS-minted, mixed-mode, and managed staged-upload browser behavior and are run via Playwright:

```sh
npm ci --prefix tests/e2e
npm test --prefix tests/e2e
```

The staged suite is self-contained and covers retry-safe batch creation, three-concurrent-item queueing, Uploading versus Processing, stable item retry/removal, final-submit blocking and label restoration, hidden credential transport, authenticated validation-rerender preview restoration, terminal/finalizing/expired behavior, responsive three/two/one-column geometry, accessibility, teardown, and multi-form isolation. The older live mint checks remain environment-dependent and skip when their configured URLs are absent.
