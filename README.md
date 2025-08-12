# Enhanced iContact Form

This plugin includes logging for form activity. Log entries are written to
`WP_CONTENT_DIR/uploads/logs/forms.log`, which is outside the plugin directory
and typically not directly accessible via the web. The plugin will create this
location if it does not exist and enforce restrictive permissions (`0640`) on
the log file. When the log file exceeds 5MB it is rotated with a timestamped
suffix and a new log is started. Administrators may adjust the limit by defining
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
for troubleshooting. By default only the `name` and `zip` fields are stored. To
adjust this list you may either:

1. Update the `eform_log_safe_fields` option in WordPress to contain an array of
   allowed field keys.
2. Use the `eform_log_safe_fields` filter to programmatically modify the array of
   field keys prior to logging.

Both approaches will replace the default values, ensuring that only explicitly
approved fields are recorded.

## Successful Submission Logging

By default a successful form submission writes a "Form submission sent" entry to
the log. The entry records only the safe fields configured via the
`eform_log_safe_fields` option or filter and includes the template name.
Administrators may disable this logging by setting `DEBUG_LEVEL` to `0` or
filtering the behavior:

```
add_filter('eform_log_successful_submission', '__return_false');
```

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
