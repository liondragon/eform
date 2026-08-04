# Electronic Forms

Lightweight PHP form handler for WordPress.

## Installation

Requirements: PHP 8.1+ and WordPress 5.8+. Managed photo review links also require rewrite-based pretty permalinks—not Plain or an `index.php` PATHINFO structure—so `/review/{token}` reaches the front controller.

1. Place this repository root inside `wp-content/plugins/eforms/` so `eforms.php` is directly inside the plugin directory.
2. (Optional for contributors) Run `composer install` from the repository root to set up the development-only tooling used for local testing; the packaged plugin ships with no runtime Composer dependencies.
3. Activate the plugin from the WordPress admin Plugins screen once the files are in place.

## Documentation

- [Architecture Router](docs/Architecture_Router.md) maps the main owners, runtime lanes, and routing rules.
- [Owner Index](docs/Owner_Index.md) lists reusable ownership seams and forbidden local duplicates.
- This README carries the current operator-facing behavior and product intent.
- [Public Contracts](docs/contracts/Public_Contracts.md), [Template Contract](docs/contracts/Template_Contract.md), and [Runtime Storage](docs/contracts/Runtime_Storage.md) carry stable implementation contracts that are too detailed for the operator narrative.
- [Past Decisions](docs/PAST_DECISIONS.md) records key design trade-offs and simplifications.
- [Documentation Guide](docs/README.md) explains how the documentation set is organized.

## Architecture

- `eforms.php` boots the plugin, sets up rewrite rules, autoloads `src/`, and registers the `[eform]` shortcode.
- `src/Rendering/` loads JSON form templates from `templates/forms/` and renders HTML.
- `src/Submission/SubmitHandler.php` orchestrates security checks, validation, logging, email, and uploads.
- `src/Security/` houses token, origin, challenge, and throttling logic.
- `src/Logging.php` writes structured logs with rotation.
- Configuration lives in `src/Config.php`. Common operational settings can be managed in WordPress at Settings -> eForms; deployment overrides can still be supplied via a drop-in file (`${WP_CONTENT_DIR}/eforms.config.php`, usually `wp-content/eforms.config.php`) and/or the `eforms_config` filter.

## Usage

Add forms via shortcode:

```php
[eform id="contact"]
```

Configure in WordPress:

- Open Settings -> eForms for curated settings with effective values and source labels shown beside each control.
- Admin settings are stored as sparse overrides in `eforms_admin_config`.
- Precedence is code defaults < admin settings < drop-in file < `eforms_config` filter, so drop-in/filter values appear as externally controlled in wp-admin.

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
       'fail2ban' => [
           'target' => 'file',
           'file' => 'f2b/eforms.log',
       ]
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
   logpath = /var/www/html/wp-content/uploads/f2b/eforms.log
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

Uploads are stored in `wp-content/uploads/eforms-private` with deny rules and strict permissions. Finalized managed-photo paths and operator review snapshots use scoped `0750`/`0640` access so trusted server accounts in the web-runtime group can inspect review data. Ordinary attachments, open uploads, manifests, locks, fences, and other control data remain owner-private. These group permissions do not make upload paths web-accessible.

Staged photo fields require 64-bit PHP integers. The sole staged `image` token covers JPEG, PNG, WebP, HEIC, and HEIF; GIF and animated or multi-image containers remain rejected, and ordinary non-staged synchronous upload behavior is unchanged. The staged browser scheduler admits at most three simultaneous body transfers and four total local or Worker item pipelines; after body completion the card shows **Processing** and one later body may start while registration finishes. Synchronous uploads and local staged artifacts require PHP `fileinfo` and bounded image-header inspection; local artifact storage also requires protected writable storage plus PHP and web-server request limits above one item and its multipart overhead. Worker/R2 staged artifacts become **Uploaded** after immutable R2 storage, durable validation-Queue acceptance, and WordPress registration; exact-object media validation then runs asynchronously and gates review access without delaying form submission or email. Worker/R2 requires the explicit Worker endpoint, environment, signing-key deployment constants, one private R2 bucket, one validation Queue, and one DLQ. Imagick is optional and is used only when the local preview provider is enabled; preview availability never determines upload success. When a local preview is unavailable, the gallery can load a browser-native JPEG, PNG, or WebP original on explicit request through the existing same-origin signed route; it never eagerly loads all full-size originals. HEIC and HEIF remain downloadable when no generated preview is available.

The accepted artifact is retained as submitted or as the one browser-prepared
JPEG selected before upload. An unchanged artifact may retain EXIF, GPS, color
profiles, and other source metadata; eForms does not promise metadata removal.
Artifact and preview access remains private and signed, but operators must
reflect that retention in their privacy notice and handling policy.

The Worker/R2 composition sends photo data to Cloudflare R2 and Cloudflare
Images. Before activation, treat Cloudflare as a data processor: confirm the
appropriate vendor agreement, region/transfer posture, retention and incident
process, and disclose the processing where applicable. Do not record customer
filenames, object identities, grants, receipts, source metadata, or raw provider
responses in rollout measurements.

Authoritative staged and finalized artifacts plus active reservations and delete-pending Worker objects share the fixed managed-capacity ceiling. Local reservations also preserve the separate free-disk floor and account for the request-temporary multipart copy; Worker reservations enforce the global object budget without applying the WordPress disk floor. Managed capacity is WordPress application authority, not a transactional measurement of exceptional late provider residue: tombstoned objects remain charged until the Worker confirms the terminal result and artifact are absent, while later non-authoritative residue remains eligible for the bucket lifecycle backstop. Provision additional space for unrelated WordPress content. Enable the existing per-IP throttle before serving a staged form. The default 60-request budget covers batch creation plus both protocol requests for all 24 advertised items; tune `throttle.per_ip.max_per_minute` when retries or shared-IP traffic require more headroom.

### Managed Photo Review

Staged-photo submissions send recipients one private review gallery link instead of attaching images to email. The link opens `/review/{token}` while the finalized submission remains available. Anonymous visitors see only the photo gallery and limited project summary rows; logged-in `manage_options` operators can see the approved lead details and change availability only while the gallery is available. Operators can still delete the whole retained submission before GC removes it.

Worker/R2 galleries show accepted photos after asynchronous validation. Pending photos show **Processing** with a manual refresh hint, and rejected or unavailable photos show **Photo unavailable** without exposing provider reasons. Local galleries stream submitted artifacts through same-origin signed routes; optional previews are best-effort and never determine submission success.

### Maintenance (Required)

Run `wp eforms gc` via system cron to prune expired token records and uploads, including abandoned staged batches, terminal validation results, and expired finalized galleries. Worker cleanup tombstones first, waits through the validation and in-flight capability drain, deletes the exact result and artifact, confirms absence, and only then releases managed capacity. Use `wp eforms gc --dry-run` after deployment to confirm access and cleanup accounting. PHP cannot prove that external cron is scheduled, so monitor that job separately.

If the doctor reports interrupted managed-capacity accounting, investigate the storage failure and then run `wp eforms gc --reconcile-capacity`. This explicit repair performs a full managed-file scan; ordinary scheduled GC remains batch-bounded.

Before changing the Worker validation contract, run `wp eforms gc --begin-validation-retirement=<old-version>`. That durable barrier blocks only new Worker grants for the old validation contract while existing receipts, submissions, review, Queue work, deletion, and GC continue to drain. After ordinary Queue/DLQ drain and GC, run `wp eforms gc --verify-validation-retirement=<old-version>`; a ready result with `references=0` persists bounded readiness, while a blocked result means retained state still names the old contract. After WordPress and Worker both use the new validation contract, run `wp eforms gc --complete-validation-retirement=<old-version>` to require signed Worker health and remove the barrier.

Ledger markers are pruned by `wp eforms gc` after the associated token is expired.

## Spam Protection Smoke Test

Run the focused spam diagnostic from Settings -> eForms or from the WordPress root:

```sh
wp eforms spam-smoke
```

The command uses the shipped `contact` form and local runtime paths to verify:

- valid baseline submission reaches the commit boundary with real email suppressed
- honeypot blocks before commit
- missing JavaScript can trigger spam rejection under a strict temporary threshold
- too-fast submission can trigger spam rejection under a strict temporary threshold
- combined soft signals are reported together under a strict temporary threshold
- throttle returns a retryable throttled result
- oversized mint requests fail
- mint requests without Origin fail

This is an operator wiring check, not a guarantee that all real-world spam will
be blocked. Smoke artifacts may appear in eForms logs/runtime storage and are
cleaned by normal `wp eforms gc`.

## Runtime Health Doctor

Run the active runtime health diagnostic from Settings -> eForms or from the
WordPress root:

```sh
wp eforms doctor
```

The doctor checks observable host/runtime readiness: uploads writability,
private storage protection, runtime subdirectory usability, staged image
inspection, optional preview readiness, PHP request limits, managed-capacity consistency and disk
provisioning, mandatory staged throttling, shipped templates, GC dry-run
readiness, configured Worker/R2/Images/Queue producer and validation-contract readiness, CLI bootstrap, and config source visibility. The Worker source preflight validates the declared consumer/DLQ wiring; operators must confirm the deployed consumer and observable DLQ in the provider control plane because the doctor does not mutate customer or provider state. It reports PASS/WARN/FAIL
rows and does not store diagnostic history. It cannot prove that system cron is
configured; schedule and monitor `wp eforms gc` separately.
