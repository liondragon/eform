# Enhanced iContact Form

This plugin includes logging for form activity. Log entries are written to
`WP_CONTENT_DIR/uploads/logs/forms.log`, which is outside the plugin directory
and typically not directly accessible via the web. The plugin will create this
location if it does not exist and enforce restrictive permissions (`0640`) on
the log file. When the log file exceeds 5MB it is rotated with a timestamped
suffix and a new log is started. Administrators may adjust the limit by defining
`EFORM_LOG_FILE_MAX_SIZE` (bytes) or using the `eform_log_file_max_size` filter.

Each entry is stored as JSON and includes a `timestamp` field. An example
entry looks like this:

```
{
    "timestamp": "2024-01-01T12:00:00+00:00",
    "ip": "203.0.113.5",
    "source": "Enhanced iContact Form",
    "message": "Submitted form",
    "user_agent": "Mozilla/5.0",
    "referrer": "No referrer"
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
