Sample Module

This module demonstrates how to provide admin panels and an example MPC client.

Install:
- Copy the `sample` folder into `modules/` (already in repository).
- Activate by setting the active module via WP-CLI:

```bash
wp option update rawwire_active_module 'sample'
```

Panel actions available (via module dispatcher or REST):
- `panel_settings` -> returns HTML fragment for settings
- `panel_approvals` -> returns a small approvals list
- `panel_control` -> accepts `control_action` and `value`
- `ai_chat`, `get_workflow_config`, `execute_workflow` -> sample behaviors

MPC client example:
- The sample includes `RawWire_Sample_MPC_Client` which uses `rawwire_mpc_endpoint` option.
- Configure via:

```bash
wp option update rawwire_mpc_endpoint 'ws://mpc.example.com:8080'
```

Note: This client is illustrative. For production use a robust WebSocket client (Ratchet, Wrench, or a CLI service).
