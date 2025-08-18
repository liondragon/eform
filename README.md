# Enhanced iContact Form

This plugin includes logging for form activity. Log entries are written to a
JSONL file (`forms.jsonl`) in `../eform-logs` relative to `WP_CONTENT_DIR`. This
directory resides outside the web root and the plugin automatically creates
`.htaccess` and `index.html` files to block direct access. Administrators may
override the location by defining `EFORM_LOG_DIR` or filtering
`eform_log_dir`. The log file is created with restrictive permissions (`0640`)
and entries are appended atomically.

When the log file exceeds 5MB it is rotated with a timestamped suffix and a new
log is started. Administrators may adjust the limit by defining
`EFORM_LOG_FILE_MAX_SIZE` (bytes) or using the `eform_log_file_max_size` filter.
Rotated log files older than 30 days are automatically deleted; customize this
window by defining `EFORM_LOG_RETENTION_DAYS` or filtering
`eform_log_retention_days`.

Each entry is stored as JSON and includes a `timestamp` field along with details
about the request. When available the request URI and template name are
recorded. An example entry looks like this:

```
{
    "timestamp": "2024-01-01T12:00:00+00:00",
    "ip": "203.0.113.5",
    "source": "Enhanced iContact Form",
    "message": "Submitted form",
    "user_agent": "Mozilla/5.0",
    "referrer": "No referrer",
    "request_uri": "/contact",
    "template": "default"
}
```

## Logged Form Fields

When `DEBUG_LEVEL` is set to `2`, selected form fields may be included in the log
for troubleshooting. By default only the `name` and `zip` fields are stored.
Use the `eform_log_safe_fields` filter to programmatically modify the array of
field keys prior to logging.

## Successful Submission Logging

By default a successful form submission writes a "Form submission sent" entry to
the log. The entry records only the safe fields configured via the
`eform_log_safe_fields` filter and includes the template name.
Administrators may disable this logging by setting `DEBUG_LEVEL` to `0` or
filtering the behavior:

```
add_filter('eform_log_successful_submission', '__return_false');
```

## Security Options

Forms include several security checks to deter automated submissions.
Administrators may tailor these checks globally by defining constants or per
template via JSON configuration.

* `EFORMS_MIN_FILL_TIME` &mdash; Minimum seconds between page render and
  submission. Defaults to 5 seconds.
* `EFORMS_MAX_FILL_TIME` &mdash; Maximum seconds between page render and
  submission. Defaults to 24 hours.
* `EFORMS_NONCE_LIFETIME` &mdash; Lifetime in seconds for form nonces.
  Defaults to 24 hours.
* `EFORMS_MAX_POST_BYTES` &mdash; Maximum allowed POST body size. Requests
  exceeding this limit are rejected.
* `EFORMS_REFERRER_POLICY` &mdash; Referrer enforcement mode: `off`, `soft`,
  `soft_path`, or `hard`.
* `EFORMS_SOFT_FAIL_THRESHOLD` &mdash; Number of soft-fail points before the
  request is flagged.
* `EFORM_JS_CHECK` or template option `js_check` &mdash; Accepts `hard` (default)
  or `soft` to control whether the JavaScript verification field is required.
  In `soft` mode the form proceeds even if the `enhanced_js_check` field is
  missing.

## Email Customization

The `From` address defaults to `noreply` at the site's domain derived from `home_url()`. Override the user or domain by defining `EFORMS_FROM_USER` and `EFORMS_FROM_DOMAIN`.

The IP address is included in outbound messages only when the template's `email.include_fields` contains `ip` and `EFORMS_IP_MODE` permits. Set `EFORMS_IP_MODE` to `masked` (default), `hash`, `full`, or `none`. When using `hash`, optionally define `EFORMS_IP_SALT` to influence the hash value.

Define `EFORMS_STAGING_REDIRECT` to add an `X-Staging-Redirect` header for staging environments. When a submission is marked suspect and `EFORMS_SUSPECT_TAG` is defined, an `X-Tag` header is appended with that tag.

## Running Tests

Install dependencies and execute the test suite:

```bash
composer install
vendor/bin/phpunit
```

## Template Configuration

The plugin ships with its default field configuration in `templates/default.json`.
Additional templates can be defined by adding JSON files to the plugin's
`templates/` directory. Each file describes the fields and settings for a form
template.

### Multi-value Fields

Fields that accept multiple values, such as checkbox groups, should define their
type as `checkbox` and include a `choices` array in the template configuration.
Submitted data for these fields must be an array of selected choice values:

```json
{
    "interests_input": {
        "type": "checkbox",
        "choices": ["news", "offers"],
        "required": true
    }
}
```

When processing the form, each selected value is sanitized and validated against
the configured choices. If a field of this type is marked as required, at least
one selection must be present.

### Template Configuration Caching

Template configurations are cached both in-memory and via WordPress' object
cache. The cache key includes a version token derived from the template file's
modification time. When a template file changes the token differs, causing the
cache to refresh. Call `eform_purge_template_config_cache()` after modifying
template files to remove any stale entries.
