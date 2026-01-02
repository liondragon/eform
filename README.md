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
- Configuration lives in `src/Config.php` and can be overridden via a drop-in file (`${WP_CONTENT_DIR}/eforms.config.php`, usually `wp-content/eforms.config.php`) and/or the `eforms_config` filter.

## Usage

Add forms via shortcode:

```php
[eforms id="contact"]
```

Configure via drop-in file:

- Create `${WP_CONTENT_DIR}/eforms.config.php` (usually `wp-content/eforms.config.php`) returning an array of overrides.
- Copying the example `eforms.config.php.example` from this repo is the recommended starting point.
- Recommended: keep secrets in `wp-config.php` constants and reference them from the config file (so secrets aren’t committed to the plugin directory).

```php
<?php
if (!defined('ABSPATH')) {
    return [];
}

return [
    'security' => [
        'origin_mode' => 'hard',
    ],
];
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

### Rate Limiting

The plugin includes optional file-based throttling (`throttle.enable = true`). This is a lightweight, zero-dependency solution suitable for low-to-moderate traffic.

**Built-in throttle limitations:**

| Limitation | Impact |
|------------|--------|
| File-based | Requires reliable `flock()`; may not work on NFS or some shared hosting |
| Per-IP only | Users behind shared NAT (cafes, corporate, cellular) share a limit |
| Application-layer | Requests still reach PHP before being rejected |
| Single-server | No coordination across multiple web servers |

**For production sites expecting abuse, use infrastructure-level protection:**

#### Fail2ban (Recommended for VPS/Dedicated)

Blocks IPs at the firewall before requests reach PHP. Requires root access.

The plugin provides a dedicated Fail2ban emission channel (independent of `logging.mode`) that outputs a simple, single-line format designed for parsing:

```
eforms[f2b] ts=<unix> code=<EFORMS_ERR_*> ip=<client_ip> form=<form_id>
```

1. Enable Fail2ban emission in your config:
   ```php
   'logging' => [
       'fail2ban' => ['target' => 'file']
   ]
   ```

2. Create filter `/etc/fail2ban/filter.d/eforms.conf`:
   ```ini
   [Definition]
   failregex = ^eforms\[f2b\].*ip=<HOST>.*$
   ignoreregex =
   ```

3. Create jail `/etc/fail2ban/jail.d/eforms.local`:
   ```ini
   [eforms]
   enabled = true
   filter = eforms
   logpath = /var/www/html/wp-content/uploads/eforms-private/f2b/eforms-f2b.log
   maxretry = 5      ; adjust based on your traffic patterns
   findtime = 300    ; 5-minute window
   bantime = 3600    ; 1-hour ban
   ```

4. Restart Fail2ban: `sudo systemctl restart fail2ban`

**Fail2ban advantages:** Blocks at firewall (iptables/nftables), zero PHP overhead for banned IPs.

#### Cloudflare (Recommended for All Sites)

Blocks malicious traffic at the edge before it reaches your server. See [Cloudflare documentation](https://developers.cloudflare.com/waf/rate-limiting-rules/) for rate limiting setup. The plugin supports Cloudflare Turnstile natively (`challenge.provider = 'turnstile'`).

**Recommendation:** Use Cloudflare or similar edge protection as your first line of defense. Add Fail2ban if you have server access. Use the built-in throttle as a fallback for simple deployments.

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
