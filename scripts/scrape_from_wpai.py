#!/usr/bin/env python3
"""
LADBS Batch Scraper - Reads permit numbers from WordPress DB and scrapes contractor info

This script:
1. Connects to WordPress MySQL database
2. Reads permit numbers from wp_pmxi_posts table
3. Scrapes LADBS for contractor/owner info
4. Saves results to JSON file for import back into WordPress

Usage:
    python scrape_from_wpai.py
    python scrape_from_wpai.py --limit 10
    python scrape_from_wpai.py --output results.json
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path
from datetime import datetime

# MySQL connection settings - adjust as needed
DB_CONFIG = {
    'host': 'localhost',
    'user': 'webdev',
    'password': 'webdev123',
    'database': 'wordpress_dev',
    'charset': 'utf8mb4'
}

# Table prefix
TABLE_PREFIX = 'wp_'


def get_permit_numbers(limit: int = None, skip_enriched: bool = True) -> list:
    """
    Fetch permit numbers from wp_pmxi_posts that haven't been enriched yet.
    
    Args:
        limit: Max number of permits to fetch
        skip_enriched: Skip permits that already have contractor data
        
    Returns:
        List of dicts with post_id and permit_number
    """
    try:
        import mysql.connector
    except ImportError:
        print("mysql-connector-python not installed. Run: pip install mysql-connector-python", file=sys.stderr)
        sys.exit(1)
    
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)
    
    if skip_enriched:
        # Get permits that don't have contractor data yet
        query = f"""
            SELECT 
                p.post_id,
                p.unique_key as permit_number,
                p.import_id
            FROM {TABLE_PREFIX}pmxi_posts p
            LEFT JOIN {TABLE_PREFIX}postmeta m 
                ON p.post_id = m.post_id 
                AND m.meta_key = '_contractor_enriched_at'
            WHERE m.meta_id IS NULL
                AND p.unique_key IS NOT NULL
                AND p.unique_key != ''
        """
    else:
        query = f"""
            SELECT 
                post_id,
                unique_key as permit_number,
                import_id
            FROM {TABLE_PREFIX}pmxi_posts
            WHERE unique_key IS NOT NULL
                AND unique_key != ''
        """
    
    if limit:
        query += f" LIMIT {limit}"
    
    cursor.execute(query)
    results = cursor.fetchall()
    
    cursor.close()
    conn.close()
    
    return results


def save_contractor_data(post_id: int, data: dict) -> bool:
    """
    Save contractor data to WordPress post meta.
    
    Args:
        post_id: WordPress post ID
        data: Scraper result dict
        
    Returns:
        True if saved successfully
    """
    try:
        import mysql.connector
    except ImportError:
        return False
    
    if not data.get('success'):
        return False
    
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    
    meta_values = []
    
    # Owner info
    owner = data.get('owner', {})
    if owner.get('name'):
        meta_values.append((post_id, '_owner_name', owner['name']))
    if owner.get('address'):
        meta_values.append((post_id, '_owner_address', owner['address']))
    if owner.get('phone'):
        meta_values.append((post_id, '_owner_phone', owner['phone']))
    
    # Contractor info
    contractor = data.get('contractor', {})
    if contractor.get('name'):
        meta_values.append((post_id, '_contractor_name', contractor['name']))
    if contractor.get('company'):
        meta_values.append((post_id, '_contractor_company', contractor['company']))
    if contractor.get('license_number'):
        meta_values.append((post_id, '_contractor_license', contractor['license_number']))
    if contractor.get('phone'):
        meta_values.append((post_id, '_contractor_phone', contractor['phone']))
    
    # Applicant info
    applicant = data.get('applicant', {})
    if applicant.get('name'):
        meta_values.append((post_id, '_applicant_name', applicant['name']))
    if applicant.get('phone'):
        meta_values.append((post_id, '_applicant_phone', applicant['phone']))
    
    # Raw data
    if data.get('raw_data'):
        meta_values.append((post_id, '_contractor_raw_data', json.dumps(data['raw_data'])))
    
    # Timestamp
    meta_values.append((post_id, '_contractor_enriched_at', datetime.now().strftime('%Y-%m-%d %H:%M:%S')))
    
    # Insert/update meta values
    for post_id, meta_key, meta_value in meta_values:
        # Delete existing
        cursor.execute(
            f"DELETE FROM {TABLE_PREFIX}postmeta WHERE post_id = %s AND meta_key = %s",
            (post_id, meta_key)
        )
        # Insert new
        cursor.execute(
            f"INSERT INTO {TABLE_PREFIX}postmeta (post_id, meta_key, meta_value) VALUES (%s, %s, %s)",
            (post_id, meta_key, meta_value)
        )
    
    conn.commit()
    cursor.close()
    conn.close()
    
    return True


def main():
    parser = argparse.ArgumentParser(description="Scrape LADBS data for WP All Import permits")
    parser.add_argument("--limit", type=int, default=10, help="Max permits to process (default: 10)")
    parser.add_argument("--delay", type=float, default=2.0, help="Delay between scrapes (default: 2s)")
    parser.add_argument("--output", "-o", help="Save results to JSON file")
    parser.add_argument("--no-save", action="store_true", help="Don't save to WordPress DB")
    parser.add_argument("--all", action="store_true", help="Include already-enriched permits")
    parser.add_argument("--headless", action="store_true", default=True, help="Run browser headless")
    parser.add_argument("--show-browser", action="store_true", help="Show browser window")
    parser.add_argument("--dry-run", action="store_true", help="Just show permits, don't scrape")
    
    args = parser.parse_args()
    
    # Get permits from database
    print(f"Fetching permits from WordPress database...", file=sys.stderr)
    permits = get_permit_numbers(limit=args.limit, skip_enriched=not args.all)
    
    if not permits:
        print("No permits found to process.", file=sys.stderr)
        return
    
    print(f"Found {len(permits)} permits to process:", file=sys.stderr)
    for p in permits:
        print(f"  - {p['permit_number']} (post_id: {p['post_id']})", file=sys.stderr)
    
    if args.dry_run:
        return
    
    # Import scraper
    try:
        from ladbs_scraper import LADBSScraper
    except ImportError:
        # Add script directory to path
        script_dir = Path(__file__).parent
        sys.path.insert(0, str(script_dir))
        from ladbs_scraper import LADBSScraper
    
    # Scrape each permit
    results = []
    headless = not args.show_browser
    
    print(f"\nStarting scrape with {args.delay}s delay between requests...", file=sys.stderr)
    
    with LADBSScraper(headless=headless) as scraper:
        for i, permit in enumerate(permits, 1):
            permit_number = permit['permit_number']
            post_id = permit['post_id']
            
            print(f"\n[{i}/{len(permits)}] Scraping {permit_number} (post {post_id})...", file=sys.stderr)
            
            result = scraper.scrape_permit(permit_number)
            result['post_id'] = post_id
            results.append(result)
            
            if result['success']:
                contractor = result.get('contractor', {})
                owner = result.get('owner', {})
                print(f"  ✓ Contractor: {contractor.get('name', 'N/A')}", file=sys.stderr)
                print(f"  ✓ Owner: {owner.get('name', 'N/A')}", file=sys.stderr)
                
                # Save to WordPress
                if not args.no_save:
                    if save_contractor_data(post_id, result):
                        print(f"  ✓ Saved to WordPress", file=sys.stderr)
                    else:
                        print(f"  ✗ Failed to save to WordPress", file=sys.stderr)
            else:
                print(f"  ✗ Error: {result.get('error', 'Unknown error')}", file=sys.stderr)
            
            # Rate limit
            if i < len(permits):
                time.sleep(args.delay)
    
    # Output results
    if args.output:
        Path(args.output).write_text(json.dumps(results, indent=2))
        print(f"\nResults saved to {args.output}", file=sys.stderr)
    
    # Summary
    successful = sum(1 for r in results if r['success'])
    print(f"\n=== Summary ===", file=sys.stderr)
    print(f"Total: {len(results)}", file=sys.stderr)
    print(f"Successful: {successful}", file=sys.stderr)
    print(f"Failed: {len(results) - successful}", file=sys.stderr)
    
    # Print JSON to stdout
    print(json.dumps(results, indent=2))


if __name__ == "__main__":
    main()
