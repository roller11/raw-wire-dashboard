#!/usr/bin/env python3
"""
LADBS Permit Scraper - Extracts contractor/owner info from LA Building Permits

This scraper uses Playwright to navigate LADBS permit lookup and extract:
- Owner information
- Contractor details (name, license, company)
- Applicant information

Usage:
    python ladbs_scraper.py --permit 23010-20000-01607
    python ladbs_scraper.py --file permits.txt  # One permit per line
    python ladbs_scraper.py --json permits.json  # JSON array of permit numbers
"""

import argparse
import json
import sys
import time
import re
from pathlib import Path
from typing import Optional

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
except ImportError:
    print("Playwright not installed. Run: pip install playwright && playwright install chromium")
    sys.exit(1)


class LADBSScraper:
    """Scrapes contractor/owner info from LADBS permit lookup."""
    
    BASE_URL = "https://www.ladbsservices2.lacity.org/OnlineServices/?service=plr"
    
    def __init__(self, headless: bool = True, timeout: int = 30000):
        self.headless = headless
        self.timeout = timeout
        self.playwright = None
        self.browser = None
        self.context = None
        
    def __enter__(self):
        self.playwright = sync_playwright().start()
        self.browser = self.playwright.chromium.launch(headless=self.headless)
        self.context = self.browser.new_context(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        )
        return self
        
    def __exit__(self, exc_type, exc_val, exc_tb):
        if self.context:
            self.context.close()
        if self.browser:
            self.browser.close()
        if self.playwright:
            self.playwright.stop()
    
    def scrape_permit(self, permit_number: str) -> dict:
        """
        Scrape contractor/owner info for a single permit.
        
        Args:
            permit_number: LA building permit number (e.g., "23010-20000-01607")
            
        Returns:
            dict with permit details including owner, contractor, applicant
        """
        page = self.context.new_page()
        result = {
            "permit_number": permit_number,
            "success": False,
            "error": None,
            "owner": {},
            "contractor": {},
            "applicant": {},
            "raw_data": {}
        }
        
        try:
            # Navigate to permit lookup
            page.goto(self.BASE_URL, wait_until="networkidle", timeout=self.timeout)
            
            # Wait for search form
            page.wait_for_selector("#pcisPermitNumber", timeout=self.timeout)
            
            # Enter permit number
            page.fill("#pcisPermitNumber", permit_number)
            
            # Click search button
            page.click("button[type='submit'], input[type='submit'], .btn-search, #btnSearch")
            
            # Wait for results
            time.sleep(2)  # Give time for AJAX
            page.wait_for_load_state("networkidle", timeout=self.timeout)
            
            # Check if we got results or need to click on a result row
            result_link = page.query_selector(f"a:has-text('{permit_number}')")
            if result_link:
                result_link.click()
                page.wait_for_load_state("networkidle", timeout=self.timeout)
            
            # Extract permit details
            result["raw_data"] = self._extract_all_tables(page)
            
            # Parse specific sections
            result["owner"] = self._extract_owner_info(page, result["raw_data"])
            result["contractor"] = self._extract_contractor_info(page, result["raw_data"])
            result["applicant"] = self._extract_applicant_info(page, result["raw_data"])
            
            result["success"] = True
            
        except PlaywrightTimeout:
            result["error"] = "Timeout waiting for page load"
        except Exception as e:
            result["error"] = str(e)
        finally:
            page.close()
            
        return result
    
    def _extract_all_tables(self, page) -> dict:
        """Extract all table data from the permit details page."""
        data = {}
        
        # Get all table rows with label/value pairs
        rows = page.query_selector_all("tr")
        for row in rows:
            cells = row.query_selector_all("td, th")
            if len(cells) >= 2:
                label = cells[0].inner_text().strip().rstrip(":")
                value = cells[1].inner_text().strip()
                if label and value:
                    data[label] = value
        
        # Also try div-based layouts
        labels = page.query_selector_all(".label, .field-label, dt")
        for label_el in labels:
            label = label_el.inner_text().strip().rstrip(":")
            value_el = label_el.query_selector("+ .value, + .field-value, + dd")
            if value_el:
                data[label] = value_el.inner_text().strip()
        
        return data
    
    def _extract_owner_info(self, page, raw_data: dict) -> dict:
        """Extract owner information."""
        owner = {}
        
        # Common field names for owner info
        owner_fields = [
            "Owner Name", "Owner", "Property Owner", "Owner's Name",
            "Owner Address", "Owner Phone", "Owner Email"
        ]
        
        for field in owner_fields:
            for key, value in raw_data.items():
                if field.lower() in key.lower():
                    clean_key = re.sub(r'^owner[\'s]*\s*', '', key, flags=re.IGNORECASE).strip()
                    owner[clean_key or "name"] = value
                    
        # Try to find owner section by heading
        owner_section = page.query_selector("h3:has-text('Owner'), h4:has-text('Owner'), .owner-section")
        if owner_section:
            parent = owner_section.query_selector("xpath=..")
            if parent:
                text = parent.inner_text()
                # Parse name/address from text block
                lines = [l.strip() for l in text.split("\n") if l.strip()]
                if len(lines) > 1:
                    owner["name"] = lines[1] if lines[1] != "Owner" else lines[2] if len(lines) > 2 else ""
        
        return owner
    
    def _extract_contractor_info(self, page, raw_data: dict) -> dict:
        """Extract contractor information."""
        contractor = {}
        
        # Common field names
        contractor_fields = [
            "Contractor", "Contractor Name", "Licensed Contractor",
            "Contractor License", "License Number", "License #",
            "Contractor Company", "Company Name", "Business Name",
            "Contractor Phone", "Contractor Address"
        ]
        
        for field in contractor_fields:
            for key, value in raw_data.items():
                if field.lower() in key.lower():
                    # Normalize key
                    clean_key = key.lower()
                    if "license" in clean_key:
                        contractor["license_number"] = value
                    elif "company" in clean_key or "business" in clean_key:
                        contractor["company"] = value
                    elif "phone" in clean_key:
                        contractor["phone"] = value
                    elif "address" in clean_key:
                        contractor["address"] = value
                    elif "name" in clean_key or clean_key == "contractor":
                        contractor["name"] = value
        
        # Try to find contractor section
        contractor_section = page.query_selector(
            "h3:has-text('Contractor'), h4:has-text('Contractor'), .contractor-section, "
            "#contractorInfo, [data-section='contractor']"
        )
        if contractor_section:
            parent = contractor_section.query_selector("xpath=..")
            if parent:
                text = parent.inner_text()
                # Look for license number pattern (e.g., "License: 123456")
                lic_match = re.search(r'License[#:\s]*(\d+)', text)
                if lic_match:
                    contractor["license_number"] = lic_match.group(1)
        
        return contractor
    
    def _extract_applicant_info(self, page, raw_data: dict) -> dict:
        """Extract applicant/filer information."""
        applicant = {}
        
        applicant_fields = [
            "Applicant", "Applicant Name", "Filed By", "Application By",
            "Applicant Phone", "Applicant Email", "Applicant Address"
        ]
        
        for field in applicant_fields:
            for key, value in raw_data.items():
                if field.lower() in key.lower():
                    clean_key = re.sub(r'^applicant[\'s]*\s*', '', key, flags=re.IGNORECASE).strip()
                    applicant[clean_key or "name"] = value
        
        return applicant
    
    def scrape_multiple(self, permit_numbers: list, delay: float = 1.0) -> list:
        """
        Scrape multiple permits with delay between requests.
        
        Args:
            permit_numbers: List of permit numbers
            delay: Seconds to wait between requests
            
        Returns:
            List of result dicts
        """
        results = []
        total = len(permit_numbers)
        
        for i, permit in enumerate(permit_numbers, 1):
            print(f"[{i}/{total}] Scraping {permit}...", file=sys.stderr)
            result = self.scrape_permit(permit)
            results.append(result)
            
            if result["success"]:
                print(f"  ✓ Got: {result['contractor'].get('name', 'No contractor')}", file=sys.stderr)
            else:
                print(f"  ✗ Error: {result['error']}", file=sys.stderr)
            
            if i < total:
                time.sleep(delay)
        
        return results


def main():
    parser = argparse.ArgumentParser(description="Scrape contractor info from LADBS")
    parser.add_argument("--permit", help="Single permit number to scrape")
    parser.add_argument("--file", help="File with permit numbers (one per line)")
    parser.add_argument("--input-json", help="JSON file with array of permit numbers")
    parser.add_argument("--json", action="store_true", help="Output single result as JSON object (not array)")
    parser.add_argument("--output", "-o", help="Output file (default: stdout)")
    parser.add_argument("--delay", type=float, default=1.5, help="Delay between requests (default: 1.5s)")
    parser.add_argument("--headless", action="store_true", default=True, help="Run browser in headless mode")
    parser.add_argument("--show-browser", action="store_true", help="Show browser window (for debugging)")
    
    args = parser.parse_args()
    
    # Get permit numbers
    permits = []
    if args.permit:
        permits = [args.permit]
    elif args.file:
        permits = Path(args.file).read_text().strip().split("\n")
    elif args.input_json:
        permits = json.loads(Path(args.input_json).read_text())
    else:
        parser.print_help()
        sys.exit(1)
    
    # Clean permit numbers
    permits = [p.strip() for p in permits if p.strip()]
    
    if not permits:
        print("No permit numbers provided", file=sys.stderr)
        sys.exit(1)
    
    # Scrape
    headless = not args.show_browser
    with LADBSScraper(headless=headless) as scraper:
        if len(permits) == 1:
            result = scraper.scrape_permit(permits[0])
            # Single permit with --json flag: output single object
            if args.json:
                output = json.dumps(result)
            else:
                output = json.dumps([result], indent=2)
        else:
            results = scraper.scrape_multiple(permits, delay=args.delay)
            output = json.dumps(results, indent=2)
    
    # Output
    if args.output:
        Path(args.output).write_text(output)
        print(f"Results saved to {args.output}", file=sys.stderr)
    else:
        print(output)


if __name__ == "__main__":
    main()
