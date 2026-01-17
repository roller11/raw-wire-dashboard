# Public Website Scraping Guide

## ✅ You Already Have This!

The **Native PHP Scraper** (`RawWire_Adapter_Scraper_Native`) is built-in and ready to scrape public websites.

## What It Can Scrape

### ✓ Static HTML Sites
- News websites
- Blogs
- Public data portals
- Government websites
- Documentation sites
- RSS feeds (as HTML)
- Any public webpage without heavy JavaScript

### ✗ Cannot Scrape
- JavaScript-heavy SPAs (React, Vue, Angular apps)
- Sites with CAPTCHA protection
- Sites requiring login/authentication
- Sites with aggressive anti-bot measures

## Quick Start

```php
// Initialize the scraper
require_once 'cores/toolbox-core/adapters/scrapers/class-scraper-native.php';
$scraper = new RawWire_Adapter_Scraper_Native(array());

// Scrape a URL with CSS selectors
$result = $scraper->scrape('https://example.com', array(
    'selectors' => array(
        'title' => 'h1',
        'content' => '.article-body p',
        'author' => '.author-name',
        'date' => 'time',
    ),
));

if ($result['success']) {
    $data = $result['data'];
    // Store in database...
}
```

## CSS Selectors Supported

| Selector | Example | Description |
|----------|---------|-------------|
| Tag | `p` | All paragraph tags |
| ID | `#main-content` | Element with id="main-content" |
| Class | `.article` | Elements with class="article" |
| Tag + Class | `div.content` | Div with class="content" |
| Attribute | `[href]` | Elements with href attribute |

## Real Examples

### Example 1: News Article
```php
$news = $scraper->scrape('https://example-news.com/article', array(
    'selectors' => array(
        'headline' => 'h1.article-title',
        'body' => '.article-content p',
        'published' => 'time',
        'author' => '.byline',
    ),
));
```

### Example 2: Batch Scraping
```php
$urls = array(
    'https://site1.com',
    'https://site2.com',
    'https://site3.com',
);

$results = $scraper->scrape_batch($urls, array(
    'delay' => 1000, // 1 second between requests
    'selectors' => array('title' => 'h1'),
));
```

### Example 3: Store in Database
```php
global $wpdb;
$table = $wpdb->prefix . 'rawwire_content';

$wpdb->insert($table, array(
    'title' => $result['data']['headline'],
    'content' => implode("\n", $result['data']['body']),
    'source' => 'web_scrape',
    'status' => 'pending',
));
```

## Rate Limits

- **60 requests per minute** (self-imposed for politeness)
- Automatic rate limit tracking
- Recommended: Add delays between batch requests

## When You Need More

If you encounter:
- ❌ "This site requires JavaScript"
- ❌ CAPTCHA challenges
- ❌ "Access denied" / 403 errors
- ❌ IP blocking

**Upgrade Options:**

1. **ScraperAPI** (Value Tier)
   - Handles JavaScript rendering
   - Proxy rotation
   - CAPTCHA solving
   - ~$49/month for 100k requests

2. **BrightData** (Flagship Tier)
   - Enterprise-grade
   - Residential IPs
   - Browser automation
   - Higher cost but most powerful

## Test Scripts

```bash
# Test public website scraping
docker exec raw-wire-core-wordpress-1 php \
  /var/www/html/wp-content/plugins/raw-wire-dashboard/scripts/test-public-scraper.php
```

## Pro Tips

1. **Respect robots.txt** - Check if site allows scraping
2. **Add delays** - Don't hammer servers (1-2 sec between requests)
3. **Use specific selectors** - More specific = more reliable
4. **Handle failures gracefully** - Sites change, selectors break
5. **Cache results** - Don't re-scrape same content repeatedly

## Target Sites for Raw-Wire

Good sources for cannabis/vaping regulatory data:
- ✓ Federal Register (federalregister.gov)
- ✓ State government portals
- ✓ Industry news sites
- ✓ Public health department sites
- ✓ Research publication databases

Most government sites are static HTML and work perfectly with the native scraper!
