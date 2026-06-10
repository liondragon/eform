# Compatibility guards — manual checklist

This is a WordPress admin checklist for verifying the compatibility guards.

## Minimum versions

- [ ] On an unsupported PHP version, activating the plugin shows an admin error notice and the plugin ends up deactivated.
- [ ] On an unsupported WordPress version, activating the plugin shows an admin error notice and the plugin ends up deactivated.

## Shared uploads semantics

The plugin writes runtime state under the WordPress uploads directory. In multi-server/container deployments, `${uploads.dir}` must be a **shared persistent volume** that supports:

- atomic rename (write temp file → rename in same directory)
- atomic exclusive-create (used to deduplicate submissions)

Checklist:

- [ ] With normal uploads permissions, the plugin stays activated and no compatibility notice appears.
- [ ] If the uploads directory is not writable (or the filesystem does not support atomic ops), activation shows an admin error notice and the plugin ends up deactivated.

