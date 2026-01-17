# U.S. Government Public Domain Data Sources

## 📜 Legal Foundation

**17 U.S.C. § 105** - U.S. government works are in the **public domain** (no copyright).

This includes:
- ✅ Federal legislation, regulations, court opinions
- ✅ Agency publications (FDA, EPA, DEA, etc.)
- ✅ Congressional records
- ✅ Federal court filings and opinions
- ⚠️ State/local government varies by state

## 🏛️ Primary Legal & Regulatory Sources

### Federal Register & Regulations
| Source | URL | Content Type | Scraping Difficulty |
|--------|-----|--------------|-------------------|
| **Federal Register** | https://www.federalregister.gov | Daily federal agency rules, proposed rules, notices | ⭐ Easy (HTML) |
| **eCFR** | https://www.ecfr.gov | Code of Federal Regulations (current) | ⭐ Easy (HTML) |
| **Regulations.gov** | https://www.regulations.gov | Public comments, regulatory documents | ⭐⭐ Medium (some JS) |

### Congressional Resources
| Source | URL | Content | Difficulty |
|--------|-----|---------|------------|
| **Congress.gov** | https://www.congress.gov | Bills, laws, voting records | ⭐⭐ Medium |
| **Congressional Record** | https://www.congress.gov/congressional-record | Daily proceedings | ⭐ Easy |
| **Congressional Budget Office** | https://www.cbo.gov | Budget analysis, reports | ⭐ Easy |

### Court Systems
| Source | URL | Content | Difficulty |
|--------|-----|---------|------------|
| **Supreme Court** | https://www.supremecourt.gov/opinions | Opinions and orders | ⭐ Easy (PDF) |
| **Court of Appeals** | Various circuits | Circuit opinions | ⭐⭐ Medium |
| **CourtListener** | https://www.courtlistener.com | Aggregated federal/state opinions | ⭐ Easy |
| **PACER** | https://pacer.uscourts.gov | Federal court filings | ⭐⭐⭐ Hard (requires account) |

### Agency-Specific (Relevant for Cannabis/Vaping)

#### FDA (Food & Drug Administration)
- **URL**: https://www.fda.gov
- **Key Pages**:
  - Tobacco products: `/tobacco-products`
  - Warning letters: `/inspections-compliance-enforcement-and-criminal-investigations/warning-letters`
  - Press releases: `/news-events/press-announcements`
- **Format**: HTML, PDFs
- **Scraping**: ⭐ Easy

#### DEA (Drug Enforcement Administration)
- **URL**: https://www.dea.gov
- **Key Pages**:
  - Federal Register notices: `/federal-register-notices`
  - Press releases: `/press-releases`
  - Scheduling actions: `/drug-scheduling`
- **Format**: HTML
- **Scraping**: ⭐ Easy

#### TTB (Alcohol and Tobacco Tax and Trade Bureau)
- **URL**: https://www.ttb.gov
- **Key Pages**:
  - Regulations: `/regulations`
  - Industry circulars: `/industry-circulars`
- **Format**: HTML, PDFs
- **Scraping**: ⭐ Easy

#### State Cannabis Regulators
| State | Agency | URL |
|-------|--------|-----|
| California | DCC | https://cannabis.ca.gov |
| Colorado | MED | https://sbg.colorado.gov/med |
| Oregon | OLCC | https://www.oregon.gov/olcc/marijuana |
| Washington | LCB | https://lcb.wa.gov/marijuana |
| Michigan | CRA | https://www.michigan.gov/cra |

**Note**: State works may have different copyright rules.

## 🎯 Best Sources for Raw-Wire

### Top Priority (Easy + Relevant)
1. **Federal Register** - Daily regulatory updates
2. **FDA Tobacco Products** - Vaping regulations
3. **DEA Federal Register** - Cannabis scheduling
4. **State cannabis regulator sites** - State-specific rules

### Data Available
- Proposed rules and regulations
- Final rules
- Public notices
- Enforcement actions
- Warning letters
- Court opinions
- Legislative text

## 📊 Example Selectors

### Federal Register
```php
'selectors' => array(
    'title' => 'h1.title',
    'agency' => '.agencies',
    'document_number' => '.doc-number',
    'publication_date' => '.publication-date time',
    'abstract' => '.executive-summary',
    'full_text' => '.body-column',
)
```

### FDA Warning Letters
```php
'selectors' => array(
    'title' => 'h1',
    'issued_date' => '.fda-date',
    'company' => '.addressed-to',
    'subject' => '.subject',
    'letter_text' => '.letter-content',
)
```

### Congress.gov Bills
```php
'selectors' => array(
    'bill_number' => '.bill-number',
    'title' => 'h1.legis-title',
    'sponsor' => '.sponsor-name',
    'status' => '.bill-status',
    'summary' => '.bill-summary',
)
```

## 🔧 Implementation Example

```php
// Initialize scraper
$scraper = new RawWire_Adapter_Scraper_Native(array());

// Scrape Federal Register
$result = $scraper->scrape(
    'https://www.federalregister.gov/documents/2024/01/15/...',
    array(
        'selectors' => array(
            'title' => 'h1',
            'agency' => '.agencies',
            'date' => 'time',
            'content' => '.body-column p',
        ),
    )
);

// Store with metadata
global $wpdb;
$wpdb->insert($wpdb->prefix . 'rawwire_content', array(
    'title' => $result['data']['title'],
    'content' => implode("\n", $result['data']['content']),
    'source' => 'federal_register',
    'status' => 'pending',
));
```

## 📋 RSS/Atom Feeds (Even Easier!)

Many government sites offer RSS feeds:
- Federal Register: `https://www.federalregister.gov/documents.rss`
- FDA: `https://www.fda.gov/about-fda/contact-fda/stay-informed/rss-feeds`
- Congress.gov: Has RSS for bills, amendments, etc.

**RSS is ideal** - structured data, easy to parse, no HTML scraping needed!

## ⚖️ Legal Considerations

### ✅ You CAN:
- Scrape and republish federal government content
- Use it commercially
- Modify and remix it
- No attribution required (but recommended)

### ⚠️ Be Aware:
- **State government**: Copyright varies by state
- **Third-party content**: If govt site embeds non-govt content
- **Terms of Service**: Respect rate limits, robots.txt
- **PACER**: Requires account, has per-page fees

## 🚀 Quick Start Workflow

1. **Test Federal Register** (easiest, most relevant)
2. **Add FDA monitoring** (vaping/tobacco rules)
3. **Set up DEA alerts** (cannabis scheduling)
4. **State-by-state tracking** (major cannabis states)

All of these work perfectly with the Native PHP scraper! 🎯
