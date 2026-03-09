# Deprecated Files - January 25, 2026

These files were archived during the Subsystem Audit cleanup.

## Reason for Deprecation

| File/Directory | Reason | Replacement |
|----------------|--------|-------------|
| `class-scoring-handler.php` | Functionality duplicated in Workflow Orchestrator | `services/class-workflow-orchestrator.php` |
| `class-storage-service.php` | Obsolete single-table design | `services/class-migration-service.php` + direct DB calls |
| `class-sync-service.php` | Duplicates workflow-orchestrator functions | `services/class-workflow-orchestrator.php` |
| `run-migrations.php` | Dead CLI script, never called | `class-migration-service.php::run_pending_migrations()` |
| `control-panels.js` | Never enqueued, dead code | `js/template-system.js` |
| `ai-discovery/` | Incomplete feature, no real AI integration | Delete entirely |
| `mpc-module/` | Mock data only, never production-ready | Delete entirely |

## Safe to Delete

These files can be permanently deleted after 30 days if no issues arise.

**Archive Date**: 2026-01-25  
**Audit Reference**: `docs/SUBSYSTEM_AUDIT.md`
