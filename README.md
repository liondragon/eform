# Electronic Forms

Lightweight PHP form handler for WordPress.

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

### Logging

Logging modes: `off`, `minimal`, `jsonl`. See `Config` for options.

### Uploads

Uploads are stored in `wp-content/uploads/eforms-private` with strict perms.

## Tests

Run the tiny PHPUnit suite (requires Composer dev dependencies for JSON Schema validation):

```bash
composer install
phpunit
```
