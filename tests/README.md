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

The uninstall-drain proof requires a disposable, installed WordPress with
`WP_ENVIRONMENT_TYPE` set to `local` or `development`. To guard against an
accidental production purge, it also requires an explicit sentinel at the
WordPress root:

```sh
touch /path/to/disposable-wordpress/.eforms-uninstall-drain-disposable
EFORMS_WP_PATH=/path/to/disposable-wordpress php tests/wp-runtime/uninstall-drain.php
```

Do not point this proof at a real local development site just because the
eForms plugin is symlinked there. Runtime sites such as a paired
`flooringartists.home` install are appropriate for browser/provider smoke, but
this proof installs and deletes fixture plugins and must use a disposable
WordPress target.

The harness installs only disposable fixture plugins and exercises the real
wp-admin AJAX deletion handler, both JavaScript-queue and server-fallback bulk
orders, the plugins REST endpoint, and WP-CLI with normal deletion and
`--skip-delete`. It proves that incomplete drains and provider failures return
an error with retry instructions, preserve the plugin and persisted barrier,
and resume on a ready retry. It does not need R2 credentials or use customer
objects.

The implemented Worker/R2 lifecycle has offline restore and uninstall proofs.
The restore drill covers a retained finalized artifact, an open intent, a
delete-pending tombstone, conservative capacity, and interrupted remote-purge
resumption:

```sh
php tests/integration/test_remote_restore_drill.php
php tests/integration/test_remote_uninstall_drain.php
php tests/unit/test_r2_lifecycle_verifier.php
```

The genuine-provider and actual lifecycle-rule commands are documented in
`worker/README.md`; they require a disposable Cloudflare integration deployment
and operator-held credentials respectively. The genuine lane covers upload,
inspection, fixed-recipe private preview, exact download, and deletion for every
enabled image format.
The controlled rotation wrapper runs
`tests/wp-runtime/rotation-probe.php` through `wp eval-file` on the paired
disposable WordPress deployment after every key-role transition; the probe is
read-only and emits only a non-sensitive site/Worker/environment pair
fingerprint, environment/key IDs, and readiness. The Worker harness recomputes
the fingerprint independently from its configured origins and rejects a probe
from a different paired deployment.

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

The staged suite is self-contained and covers retry-safe batch creation, three-transfer/four-pipeline scheduling, Uploading and Processing states, stable item retry/removal, serialized Clear all cleanup, timer-driven expiry rendering, final-submit blocking and label restoration, hidden credential transport, authenticated validation-rerender status restoration without authoritative-artifact rereads, terminal/finalizing/expired behavior, responsive three/two/one-column geometry, accessibility, teardown, and multi-form isolation. The review-gallery suite proves one-at-a-time preview admission plus explicit retry and download fallback. The older live mint checks remain environment-dependent and skip when their configured URLs are absent.
