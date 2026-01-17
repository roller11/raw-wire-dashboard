# RawWire Dashboard

**Version 1.0.24** | Professional WordPress plugin for content curation, workflow automation, and AI-powered content management.

## 🚀 Features

### Core Capabilities
- **Template-Driven Dashboard**: Visual template builder with drag-and-drop panel designer
- **Multi-Source Scraping**: Federal Register, Regulations.gov, Congress.gov API integration
- **AI Content Analysis**: Scoring system with configurable weights for relevance, timeliness, impact
- **Full Workflow Pipeline**: Candidates → Approvals → Content → Releases with archive system
- **Centralized Key Manager**: Encrypted API key storage using WordPress salts

### Technical Features
- **Modular Architecture**: Core module system allowing dynamic extensions
- **Standards Compliant**: WordPress Coding Standards (WPCS) adherence
- **Robust REST API**: Full endpoints via `wp-json/rawwire/v1`
- **Secure**: Nonce verification, capability checks, encrypted credentials

## 📂 Project Structure

```text
raw-wire-dashboard/
├── admin/               # Admin UI classes
├── cores/
│   ├── template-engine/ # Template rendering system
│   └── toolbox-core/    # Scraper, AI adapters, Key Manager
├── includes/            # Shared classes and integrations
├── js/                  # Frontend JavaScript
├── css/                 # Stylesheets
├── templates/           # Template JSON definitions
└── raw-wire-dashboard.php
```

## 📖 Documentation

- **[CHANGELOG.md](CHANGELOG.md)**: Version history and release notes
- **[docs/](docs/)**: Architecture, API reference, and developer guides

## 🛠️ Installation

1. Upload the `raw-wire-dashboard` folder to `/wp-content/plugins/`.
2. Activate the plugin via WordPress Admin or WP-CLI:
   ```bash
   wp plugin activate raw-wire-dashboard
   ```
3. Navigate to **Dashboard > RawWire** to view analytics and settings.

## 🧪 Testing

The plugin includes a suite of test scripts in the `scripts/` directory:

```bash
# Run comprehensive logger tests
wp eval-file scripts/test-logger-comprehensive.php

# Seed test data
wp eval-file scripts/seed-test-data.php

# Run CSS sanitizer security tests
php scripts/test-css-sanitizer-standalone.php
```

## 🏗️ Module Development

Modules live in `modules/<slug>/` and must implement `RawWire_Module_Interface`.
See [Module Development Guide](docs/manuals/PLUGIN_QUICKSTART.md) for details.

## ©️ License

Proprietary - Raw Wire DAO LLC.
