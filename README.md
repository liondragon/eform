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

Requires PHP 8.0+ and Composer. Install dependencies and run the tiny PHPUnit suite:

- `composer install`
- `vendor/bin/phpunit -c phpunit.xml.dist --testdox`
- Optional stricter run: `vendor/bin/phpunit -c phpunit.xml.dist --fail-on-warning --testdox`

