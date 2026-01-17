# Raw-Wire Dashboard - Template-Driven WordPress Plugin

## For AI Assistants

**CRITICAL**: Read `.github/copilot-instructions.md` and `CLAUDE.md` before making ANY code changes.

## Architecture Summary

This is a **TEMPLATE-DRIVEN MODULAR SYSTEM** for small businesses:

1. **Templates define ALL behavior** - `templates/*.template.json`
2. **Modules provide fallbacks ONLY** - Empty panels when template missing
3. **Code is static** - Reads templates, doesn't contain business logic
4. **Features are toggleable** - Settings page controls all functionality

## Quick Reference

| Need | Location |
|------|----------|
| Add panel | Template JSON |
| Add action button | Template `actions` array |
| Database query | Template `dataSource` |
| Toggle feature | Template `features` + Settings |

## Database Tables

```
candidates → archives → content → queue
(staging)   (scored)   (approved) (publish)
```

Full documentation: `SYNC_FLOW_MAP.md`

## Key Files

- `templates/news-aggregator.template.json` - Source of truth
- `cores/template-engine/panel-renderer.php` - Executes template config
- `dashboard.js` - Generic handlers driven by data attributes
- `SYNC_FLOW_MAP.md` - Complete data flow documentation

## The Golden Rule

**If you're writing logic in a module, you're doing it wrong.**

Put it in the template instead.
