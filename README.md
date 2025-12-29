# Electronic Forms

Lightweight PHP form handler for WordPress.

## Installation

Requirements: PHP 8.0+ and WordPress 5.8+, as detailed in [Canonical Spec → Compatibility and Updates](docs/Canonical_Spec.md#sec-compatibility).

1. Place the plugin directory inside `wp-content/plugins/` so WordPress can discover it.
2. (Optional for contributors) Run `composer install` within the plugin directory to set up the development-only tooling used for local testing; the packaged plugin ships with no runtime Composer dependencies.
3. Activate the plugin from the WordPress admin Plugins screen once the files are in place.

## Documentation

- [Canonical Spec](docs/Canonical_Spec.md) details the end-to-end submission, security, and rendering requirements the plugin must meet.
- [Roadmap](docs/roadmap.md) highlights upcoming milestones, outstanding work, and longer-term ideas.
- [Past Decisions](docs/PAST_DECISIONS.md) records key design trade-offs and simplifications.
- [Documentation Guide](docs/README.md) explains how the documentation set is organized.

## Architecture

- `eforms.php` boots the plugin, sets up rewrite rules, autoloads `src/`, and registers the `[eform]` shortcode.
- `src/Rendering/` loads JSON form templates from `templates/forms/` and renders HTML.
- `src/Submission/SubmitHandler.php` orchestrates security checks, validation, logging, email, and uploads.
- `src/Security/` houses token, origin, challenge, and throttling logic.
- `src/Logging.php` writes structured logs with rotation.
- Configuration lives in `src/Config.php` and can be overridden via the `eforms_config` filter.

## Usage

Add forms via shortcode:

```php
[eforms id="contact"]
```

Configure via filter:

```php
add_filter('eforms_config', function ($config) {
    $config['security']['origin_mode'] = 'hard';
    return $config;
});
```

### Security

* CSRF protection via Origin checks and per-request tokens.
* Token ledger prevents duplicate submissions.
* Email-failure retries set a marker that suppresses the min-fill-time soft signal (see Canonical Spec).

### Logging

Logging modes: `off`, `minimal`, `jsonl`. See `Config` for options.

### Uploads

Uploads are stored in `wp-content/uploads/eforms-private` with strict perms.

### Maintenance (Required)

Run `wp eforms gc` via system cron to prune expired token records and uploads. The plugin also runs best-effort GC on request shutdown, but cron is the primary mechanism.

Ledger markers are pruned by `wp eforms gc` after the associated token is expired.

## Tests

Requires PHP 8.0+ and Composer. Install dependencies and run the tiny PHPUnit suite:

- `composer install`
- `vendor/bin/phpunit -c phpunit.xml.dist --testdox`
- Optional stricter run: `vendor/bin/phpunit -c phpunit.xml.dist --fail-on-warning --testdox`

## WP-CLI scripts

Helper scripts under `bin/wp-cli/` exercise a couple of security scenarios. Run
them from the WordPress root where this plugin is installed:

```sh
# Submit without an Origin header; expects "Security check failed." in response
wp eval-file wp-content/plugins/eform/bin/wp-cli/post-no-origin.php

# Send a payload above the configured limit; expects HTTP 413
wp eval-file wp-content/plugins/eform/bin/wp-cli/post-oversized.php
```

Both scripts exit with a non-zero status when the observed behaviour deviates
from the expected result.
