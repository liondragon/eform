# eForms test execution strategy

This project uses a hybrid strategy:

- Default lane (canonical): pure-PHP harness tests under `eforms/tests/unit/` and `eforms/tests/integration/`.
- WordPress-runtime lane: targeted smoke checks for public WP surfaces (tracked separately in plan item #2 for CI wiring).

The canonical command from repository root is:

```sh
find eforms/tests/unit eforms/tests/integration -type f -name 'test_*.php' -print0 | sort -z | xargs -0 -n1 php
```

This command is intentionally deterministic (`sort -z`) and fails fast on any test failure.

Optional browser checks (dev-only, separate lane) live under `eforms/tests/e2e/`.
They validate JS-minted and mixed-mode browser behavior and are run via Playwright.
