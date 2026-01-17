Module installation and SSH loading

This document explains how to load a module from a Git repo (SSH/HTTPS) into the `modules/` directory, enable it, and connect the chatbot to an MPC endpoint.

1) Clone module into plugins `modules/` directory (example):

```bash
cd /path/to/wp/wp-content/plugins/raw-wire-dashboard/modules
# clone via SSH
git clone git@github.com:your-org/your-module.git your-module
# or HTTPS
git clone https://github.com/your-org/your-module.git your-module
```

2) Ensure the module exposes `module.php` and `module.json` at the module root. The module should call `RawWire_Module_Core::register_module('your-module-slug', $instance);` during load.

3) Activate the module in WordPress (set as active module):

```bash
# Using WP-CLI from your site's root
wp option update rawwire_active_module 'your-module-slug'
# flush
wp cache flush
```

4) Verify REST endpoints are available:

```
curl -H "Authorization: Bearer <token>" \
  https://example.com/wp-json/rawwire/v1/modules

# Get panels for a module
curl https://example.com/wp-json/rawwire/v1/modules/your-module-slug/panels

# Panel dispatch
curl https://example.com/wp-json/rawwire/v1/panels/{panel_id}
```

5) Chatbot / MPC hookup (high level)

- The plugin expects a configurable MPC endpoint. Set `rawwire_mpc_endpoint` option to the MPC server URL.
- Example: `wp option update rawwire_mpc_endpoint 'wss://mpc.example.com/ws'`
- Modules can read that option and implement an outbound WebSocket client that connects to the MPC server.

6) Local development notes

- When iterating on a module, use `wp option update rawwire_active_module 'your-module-slug'` and reload the admin page.
- Use `WP-CLI` and `git` over SSH for remote development.

Security notes

- Only install modules from trusted sources.
- Modules run PHP code inside the plugin and must be vetted.

