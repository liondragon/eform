# Enhanced iContact Form

This plugin includes logging for form activity. Log entries are written to
`WP_CONTENT_DIR/uploads/logs/forms.log`, which is outside the plugin directory
and typically not directly accessible via the web. The plugin will create this
location if it does not exist and enforce restrictive permissions (`0640`) on
the log file.
