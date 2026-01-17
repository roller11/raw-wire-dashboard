# GitHub Scraper - Toolkit Integration

## Overview
The GitHub scraper adapter enables Raw-Wire to fetch data from GitHub repositories using the free GitHub REST API v3. No external services required!

## Features
- ✅ **Free Tier**: 60 requests/hour (unauthenticated), 5000 requests/hour (with token)
- ✅ **Repository Info**: Fetch metadata, stars, language, etc.
- ✅ **Issues & PRs**: Access issues and pull requests with full metadata
- ✅ **Commits**: Retrieve commit history
- ✅ **File Contents**: Download files from repositories
- ✅ **Rate Limiting**: Automatic tracking and reporting
- ✅ **Caching**: Built-in support via WordPress transients

## Files Created
```
cores/toolbox-core/adapters/scrapers/
└── class-scraper-github.php          # GitHub scraper adapter

cores/toolbox-core/
└── registry.json                     # Updated with github_api entry

scripts/
└── test-github-scraper.php           # Test/demo script
```

## Quick Start

### 1. Run the Test Script
```bash
docker exec raw-wire-core-wordpress-1 php \
  /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-github-scraper.php
```

### 2. Use in Your Code
```php
// Initialize the scraper
require_once 'cores/toolbox-core/adapters/scrapers/class-scraper-github.php';

$config = array(
    'token' => 'ghp_your_token_here' // Optional but recommended
);
$scraper = new RawWire_Adapter_Scraper_GitHub($config);

// Fetch repository info
$result = $scraper->get_repository('wordpress', 'wordpress-develop');
if ($result['success']) {
    $repo = $result['data'];
    echo "Stars: " . $repo['stargazers_count'];
}

// Fetch issues
$issues = $scraper->get_issues('owner', 'repo', array(
    'state' => 'open',
    'per_page' => 10,
    'labels' => 'bug',
));

// Fetch commits
$commits = $scraper->get_commits('owner', 'repo', array(
    'per_page' => 20,
    'since' => '2024-01-01T00:00:00Z',
));

// Fetch file contents
$file = $scraper->get_contents('owner', 'repo', 'path/to/file.md');
```

## Configuration

### Optional: Add GitHub Token
For higher rate limits (5000 req/hr), add your GitHub Personal Access Token:

```php
// In wp-config.php
define('RAWWIRE_GITHUB_TOKEN', 'ghp_your_token_here');

// Or via WordPress options
update_option('rawwire_github_token', 'ghp_your_token_here');
```

**Creating a Token:**
1. Go to GitHub Settings → Developer settings → Personal access tokens
2. Click "Generate new token (classic)"
3. Select scopes: `repo` (for private repos) or none (for public repos only)
4. Copy the token (you won't see it again!)

## API Reference

### Main Methods

#### `scrape($url, $options = array())`
General-purpose scraping method. Accepts GitHub URLs or API endpoints.

```php
$result = $scraper->scrape('https://github.com/owner/repo/issues/123');
```

#### `get_repository($owner, $repo)`
Fetch repository information.

```php
$result = $scraper->get_repository('microsoft', 'vscode');
// Returns: name, description, stars, forks, language, etc.
```

#### `get_issues($owner, $repo, $params = array())`
Fetch issues/PRs from a repository.

**Parameters:**
- `state`: 'open', 'closed', or 'all' (default: 'all')
- `labels`: Comma-separated list of labels
- `sort`: 'created', 'updated', or 'comments' (default: 'created')
- `direction`: 'asc' or 'desc' (default: 'desc')
- `since`: ISO 8601 timestamp
- `per_page`: Results per page (max 100)
- `page`: Page number

```php
$result = $scraper->get_issues('facebook', 'react', array(
    'state' => 'open',
    'labels' => 'bug,good first issue',
    'per_page' => 50,
));
```

#### `get_commits($owner, $repo, $params = array())`
Fetch commit history.

**Parameters:**
- `sha`: Branch/tag/commit SHA
- `path`: Only commits affecting this path
- `author`: GitHub username
- `since`/`until`: ISO 8601 timestamps
- `per_page`: Results per page (max 100)

```php
$result = $scraper->get_commits('rails', 'rails', array(
    'since' => '2024-01-01T00:00:00Z',
    'per_page' => 100,
));
```

#### `get_contents($owner, $repo, $path)`
Fetch file or directory contents.

```php
// Get a file
$result = $scraper->get_contents('torvalds', 'linux', 'README');

// Get directory listing
$result = $scraper->get_contents('jquery', 'jquery', 'src');
```

#### `get_rate_limit_status()`
Check remaining API calls.

```php
$status = $scraper->get_rate_limit_status();
// Returns: ['remaining' => 58, 'limit' => 60, 'reset_at' => 1234567890]
```

### Response Format

All methods return an array with this structure:

```php
array(
    'success' => true|false,
    'data' => array(...),      // On success
    'error' => 'message',      // On failure
    'meta' => array(           // Additional metadata
        'type' => 'issues',
        'rate_limit' => [...],
    ),
)
```

## Data Flow

The test demonstrates the complete workflow:

```
GitHub API
    ↓
GitHub Scraper Adapter
    ↓
WordPress Database (wp_rawwire_content)
    ↓
Activity Logs (wp_rawwire_logs)
    ↓
Dashboard Display
```

## Test Results

```
✓ Connection successful (Rate Limit: 58/60)
✓ Repository: WordPress/wordpress-develop
✓ Found 5 issues
✓ Stored issue #3880 in database (ID: 1)
✓ Logged to activity logs
```

## Rate Limits

| Authentication | Limit | Per |
|---------------|-------|-----|
| None | 60 | hour |
| Token | 5,000 | hour |

The scraper automatically tracks and reports rate limit status.

## Use Cases

### 1. Import Issues for Analysis
```php
$issues = $scraper->get_issues('your-org', 'your-repo', array(
    'state' => 'open',
    'labels' => 'needs-review',
));

foreach ($issues['data'] as $issue) {
    // Store in database, analyze sentiment, etc.
}
```

### 2. Monitor Repository Activity
```php
$commits = $scraper->get_commits('your-org', 'your-repo', array(
    'since' => date('c', strtotime('-24 hours')),
));

// Alert on new commits, track contributor activity, etc.
```

### 3. Fetch Documentation
```php
$readme = $scraper->get_contents('vendor', 'package', 'README.md');
if ($readme['success']) {
    $content = base64_decode($readme['data']['content']);
    // Process markdown, extract links, etc.
}
```

### 4. Batch Processing
```php
$repos = array('owner/repo1', 'owner/repo2', 'owner/repo3');
foreach ($repos as $repo) {
    list($owner, $name) = explode('/', $repo);
    $data = $scraper->get_repository($owner, $name);
    // Aggregate stats, compare metrics, etc.
    sleep(1); // Rate limit protection
}
```

## Integration with Existing Code

The GitHub scraper can replace or augment the existing `RawWire_GitHub_Fetcher`:

**Old Way:**
```php
$fetcher = new RawWire_GitHub_Fetcher();
$result = $fetcher->fetch_findings();
```

**New Way (Toolkit):**
```php
$scraper = new RawWire_Adapter_Scraper_GitHub(array(
    'token' => get_option('rawwire_github_token'),
));
$result = $scraper->get_issues('owner', 'repo');
```

## Next Steps

1. **Dashboard Integration**: Add UI to configure GitHub scraper from dashboard
2. **Scheduled Imports**: Set up WP Cron to automatically fetch new issues
3. **Multiple Repos**: Track multiple repositories simultaneously
4. **Webhooks**: Receive real-time updates (requires webhook endpoint)
5. **Advanced Filtering**: Add custom relevance scoring for GitHub content

## Troubleshooting

### Rate Limit Exceeded
```php
$status = $scraper->get_rate_limit_status();
if ($status['remaining'] < 5) {
    $wait = $status['reset_at'] - time();
    echo "Rate limit low. Resets in {$wait} seconds.";
}
```

### 404 Errors
- Check repository exists and is public
- Verify spelling of owner/repo names
- Add authentication token if repo is private

### Timeout Issues
```php
$scraper->scrape($url, array(
    'timeout' => 60, // Increase timeout for slow requests
));
```

## Documentation
- [GitHub REST API v3](https://docs.github.com/en/rest)
- [Creating Personal Access Tokens](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token)
- [Rate Limiting](https://docs.github.com/en/rest/overview/resources-in-the-rest-api#rate-limiting)
