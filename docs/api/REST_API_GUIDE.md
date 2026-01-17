# RawWire Dashboard REST API Guide

## Base URL
`https://raw-wire.com/home/wp-json/rawwire/v1`

## Authentication
All endpoints require WordPress authentication with `manage_options` capability.

## Endpoints

### 1. Get Findings
**GET** `/findings`

Retrieve stored findings data from cache.

**Response:**
```json
{
  "success": true,
  "count": 150,
  "data": [...]  
}
```

### 2. Fetch New Data
**POST** `/findings/fetch`

Trigger data fetch from GitHub repository.

**Response:**
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "count": 150,
  "timestamp": "2025-12-27 16:00:00"
}
```

### 3. Clear Cache
**DELETE** `/findings/cache`

Clear the findings cache.

**Response:**
```json
{
  "success": true,
  "message": "Cache cleared successfully"
}
```

### 4. System Status
**GET** `/status`

Get current system status and statistics.

**Response:**
```json
{
  "success": true,
  "status": {
    "last_sync": "2025-12-27 15:30:00",
    "total_items": 150,
    "cache_status": "populated",
    "timestamp": "2025-12-27 16:00:00"
  }
}
```

## Usage Examples

### Using cURL:

```bash
# Get findings
curl -X GET https://raw-wire.com/home/wp-json/rawwire/v1/findings \
  --user username:application_password

# Fetch new data
curl -X POST https://raw-wire.com/home/wp-json/rawwire/v1/findings/fetch \
  --user username:application_password

# Get status
curl -X GET https://raw-wire.com/home/wp-json/rawwire/v1/status \
  --user username:application_password

# Clear cache
curl -X DELETE https://raw-wire.com/home/wp-json/rawwire/v1/findings/cache \
  --user username:application_password
```

### Using JavaScript (Fetch API):

```javascript
// With WordPress authentication nonce
fetch('https://raw-wire.com/home/wp-json/rawwire/v1/findings', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

## Benefits of REST API

- **Modern Architecture**: RESTful design following industry standards
- **External Access**: Can be accessed from external applications and services  
- **Better Performance**: Built-in WordPress REST API caching
- **Standard HTTP Methods**: GET, POST, DELETE for semantic operations
- **JSON Responses**: Easy to parse and integrate
- **Authentication Options**: Supports application passwords and nonces
- **Documentation**: Self-documenting with WordPress REST API schema

## Integration with Existing AJAX

The REST API complements the existing WordPress AJAX handlers. You can use:
- AJAX for traditional WordPress admin panel interactions
- REST API for modern external integrations and programmatic access
