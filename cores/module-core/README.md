Module Core

This directory contains core module-loading functionality.

Files:
- `module-core.php` — lightweight loader + validator stub. Loads `modules/<name>/module.json` based on `rawwire_active_module` option.

Next steps:
- Move `modules/` under plugin root if not present.
- Implement JSON Schema validator at `schemas/module-schema.json` and wire into `validate_module()`.
- Add admin UI for `rawwire_active_module` selection.
