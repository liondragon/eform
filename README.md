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

## Generic Fields for Templates

`FieldRegistry` includes three generic field definitions that allow templates to
capture extra information without writing PHP:

* `text_generic` – freeform text optionally validated by a `pattern` regex.
* `number_generic` – numeric input supporting `min` and `max` limits.
* `radio_generic` – radio buttons validated against a list of `choices`.

Each generic field requires a `post_key` parameter when registered. Radio fields
also require a `choices` array of allowed values.

Example usage inside a template:

```php
<?php
// Register the field so submitted data is sanitized and validated.
$eform_registry->register_field( $eform_current_template, 'text_generic', [
    'post_key' => 'company_input',
    'required' => true,
    'pattern'  => '[A-Za-z\\s]+'
] );
?>
<label for="company_input">Company</label>
<input type="text" name="company_input" id="company_input">
<?php eform_field_error( $eform_form, 'text_generic' ); ?>
```

Swap `text_generic` for `number_generic` or `radio_generic` to collect numeric or
choice values. This approach keeps templates accessible to non-coders while
ensuring submitted data is handled securely.

## Theme-based Template Configuration

The plugin ships with its default field configuration in `templates/default.json`.
Themes may override these settings by placing JSON or PHP files within
`{theme}/eform/`. For example, `wp-content/themes/my-theme/eform/default.json`
can adjust placeholders or other field attributes for the `default` template.
Configuration files are parsed using core PHP functions. If a theme file is not
found, the plugin will look for a matching configuration within its own
`templates/` directory, providing a plugin-level fallback.
