<?php

/**
 * RawWire LADBS Contractor Scraper -- Non-AI Direct URL Approach
 *
 * Uses OpenClaw browser to navigate directly to LADBS permit detail pages
 * and extract contractor info via regex. NO AI, NO LLM, NO Venice.
 *
 * Direct URL pattern: PcisPermitDetail?id1=XXXXX&id2=XXXXX&id3=XXXXX
 * Proven approach from ladbs-batch-scrape.php (working since July 2025).
 *
 * Pipeline position: Import -> Enrich -> ** LADBS SCRAPE ** -> Score -> Investigate
 *
 * @package RawWire_Dashboard
 * @since   1.0.35
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_LADBS_Scraper
{

    /** @var string LADBS permit detail base URL */
    const LADBS_DETAIL_URL = 'https://www.ladbsservices2.lacity.org/OnlineServices/PermitReport/PcisPermitDetail';

    /** @var string OpenClaw browser profile name */
    const BROWSER_PROFILE = 'openclaw';

    /** @var int Rate limit between scrapes (seconds) */
    const RATE_LIMIT_SECONDS = 3;

    /** @var int Minimum snapshot length to consider valid (chars) */
    const MIN_SNAPSHOT_LENGTH = 200;

    /** @var int Max retries when snapshot is too short */
    const MAX_SNAPSHOT_RETRIES = 2;

    /** @var int Extra wait between snapshot retries (seconds) */
    const RETRY_WAIT_SECONDS = 4;

    /** @var int Max AI snapshot tokens/lines budget */
    const SNAPSHOT_LIMIT = 1200;

    /** @var string Default openclaw binary path (NVM-installed) */
    const DEFAULT_OPENCLAW_PATH = '/home/ractal1/.nvm/versions/node/v22.22.0/bin/openclaw';

    /** @var string Default Node.js bin directory (NVM) */
    const DEFAULT_NODE_BIN_PATH = '/home/ractal1/.nvm/versions/node/v22.22.0/bin';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Scrape contractor info for a source record and write to DB
     *
     * @param int $source_id Source ID from rawwire_lead_sources
     * @return array {success: bool, contractor_name?: string, contractor_license?: string, error?: string}
     */
    public function scrape_contractor_for_source(int $source_id): array
    {
        global $wpdb;
        $table = rawwire_leads()->table('sources');

        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT id, permit_nbr, contractor_name, contractor_license FROM {$table} WHERE id = %d",
            $source_id
        ), ARRAY_A);

        if (!$source) {
            return ['success' => false, 'error' => 'Source not found'];
        }

        // Skip if contractor already known
        if (!empty($source['contractor_name'])) {
            return [
                'success'            => true,
                'contractor_name'    => $source['contractor_name'],
                'contractor_license' => $source['contractor_license'] ?? '',
                'skipped'            => true,
            ];
        }

        $permit = $source['permit_nbr'] ?? '';
        if (empty($permit)) {
            return ['success' => false, 'error' => 'No permit number'];
        }

        $result = $this->scrape_permit($permit);

        if (!$result['success']) {
            return $result;
        }

        // Update DB if we found contractor data
        if (!empty($result['contractor_name'])) {
            $update = ['contractor_name' => $result['contractor_name']];

            if (!empty($result['contractor_license'])) {
                $update['contractor_license'] = $result['contractor_license'];
            }
            if (!empty($result['contractor_address'])) {
                $update['contractor_address'] = $result['contractor_address'];
            }

            $wpdb->update($table, $update, ['id' => $source_id]);

            rawwire_log('ladbs_scraper', sprintf(
                'Source #%d: Found contractor "%s" (Lic: %s)',
                $source_id,
                $result['contractor_name'],
                $result['contractor_license'] ?? 'N/A'
            ));
        }

        return $result;
    }

    /**
     * Scrape contractor info from LADBS permit detail page
     *
     * Uses DIRECT URL approach -- no form navigation, no AI.
     * URL pattern: PcisPermitDetail?id1=XXXXX&id2=XXXXX&id3=XXXXX
     *
     * @param string $permit_nbr Permit number (format: XXXXX-XXXXX-XXXXX)
     * @return array {success: bool, contractor_name?: string, contractor_license?: string, ...}
     */
    public function scrape_permit(string $permit_nbr): array
    {
        $parts = $this->parse_permit_number($permit_nbr);
        if (!$parts) {
            return ['success' => false, 'error' => 'Invalid permit format: ' . $permit_nbr];
        }

        $url = self::LADBS_DETAIL_URL . '?' . http_build_query([
            'id1' => $parts['id1'],
            'id2' => $parts['id2'],
            'id3' => $parts['id3'],
        ]);

        rawwire_log('ladbs_scraper', sprintf('Navigating to %s', $url), 'debug');

        $base_cmd = $this->get_openclaw_binary() . ' browser --browser-profile ' . escapeshellarg(self::BROWSER_PROFILE);

        // Ensure browser is running
        $status = trim(shell_exec($base_cmd . ' status 2>&1') ?? '');
        if (strpos($status, 'running: true') === false) {
            shell_exec($base_cmd . ' start 2>&1');
            sleep(2);
        }

        // Navigate directly to permit detail page (no forms, no searching)
        shell_exec($base_cmd . ' navigate ' . escapeshellarg($url) . ' 2>&1');
        shell_exec($base_cmd . ' wait --load networkidle --timeout 15000 2>&1');
        sleep(2); // LADBS .NET pages need time to render data after network idle

        // Snapshot with retry — LADBS pages often need extra time to render
        $tmp_file = sys_get_temp_dir() . '/ladbs_snap_' . getmypid() . '.txt';
        $snapshot = '';
        $attempt = 0;

        while ($attempt <= self::MAX_SNAPSHOT_RETRIES) {
            shell_exec($base_cmd . ' snapshot --format ai --limit ' . (int) self::SNAPSHOT_LIMIT . ' > ' . escapeshellarg($tmp_file) . ' 2>&1');

            if (file_exists($tmp_file)) {
                $snapshot = file_get_contents($tmp_file);
                @unlink($tmp_file);
            }

            if (strlen($snapshot) >= self::MIN_SNAPSHOT_LENGTH) {
                break; // Good snapshot, proceed
            }

            $attempt++;
            if ($attempt <= self::MAX_SNAPSHOT_RETRIES) {
                rawwire_log('ladbs_scraper', sprintf(
                    'Permit %s: Snapshot too short (%d chars), retry %d/%d after %ds wait',
                    $permit_nbr,
                    strlen($snapshot),
                    $attempt,
                    self::MAX_SNAPSHOT_RETRIES,
                    self::RETRY_WAIT_SECONDS
                ), 'debug');
                sleep(self::RETRY_WAIT_SECONDS);
            }
        }

        if (empty($snapshot)) {
            return ['success' => false, 'error' => 'Empty snapshot after ' . (self::MAX_SNAPSHOT_RETRIES + 1) . ' attempts'];
        }

        if (strlen($snapshot) < self::MIN_SNAPSHOT_LENGTH) {
            rawwire_log('ladbs_scraper', sprintf(
                'Permit %s: Snapshot still too short after retries (%d chars): "%s"',
                $permit_nbr,
                strlen($snapshot),
                substr($snapshot, 0, 100)
            ), 'warning');
            return ['success' => false, 'error' => sprintf(
                'Snapshot too short (%d chars) after %d retries — page may not have loaded',
                strlen($snapshot),
                self::MAX_SNAPSHOT_RETRIES
            )];
        }

        rawwire_log('ladbs_scraper', sprintf('Snapshot: %d chars for permit %s', strlen($snapshot), $permit_nbr), 'debug');

        $result = $this->parse_contractor($snapshot, $permit_nbr);

        if (!empty($result['success']) && !empty($result['contractor_name'])) {
            $related_parties = $this->parse_related_parties($snapshot);
            if (!empty($related_parties['owner_name'])) {
                $result['owner_name'] = $related_parties['owner_name'];
            }
            if (!empty($related_parties['applicant_name'])) {
                $result['applicant_name'] = $related_parties['applicant_name'];
            }
        }

        return $result;
    }

    /**
     * Get the full path to the openclaw binary, ensuring PATH includes Node.js
     *
     * Apache's www-data user doesn't have NVM paths in PATH. This resolves
     * the full binary path from settings or defaults, and ensures the Node.js
     * bin directory is in PATH for any child processes.
     *
     * Also provisions a minimal openclaw.json with gateway auth token so browser
     * commands can authenticate with the running gateway server.
     *
     * @return string Escaped shell-safe binary path
     */
    private function get_openclaw_binary(): string
    {
        $openclaw_path = self::DEFAULT_OPENCLAW_PATH;
        $node_bin_path = self::DEFAULT_NODE_BIN_PATH;

        // Check if adapter settings override defaults
        $settings = get_option('rawwire_openclaw_settings', []);
        if (!empty($settings['openclaw_path'])) {
            $openclaw_path = $settings['openclaw_path'];
        }
        if (!empty($settings['node_bin_path'])) {
            $node_bin_path = $settings['node_bin_path'];
        }

        // Ensure PATH includes Node.js bin directory (required for child processes)
        $current_path = getenv('PATH') ?: '';
        if (strpos($current_path, $node_bin_path) === false) {
            putenv('PATH=' . $node_bin_path . ':' . $current_path);
        }

        // OpenClaw writes config/identity to ~/.openclaw — Apache's www-data user has
        // HOME=/var/www which is not writable, causing EACCES on mkdir.
        // Redirect HOME to a writable temp directory for the openclaw subprocess.
        $openclaw_home = sys_get_temp_dir() . '/openclaw-home';
        if (!is_dir($openclaw_home)) {
            @mkdir($openclaw_home, 0755, true);
        }
        putenv('HOME=' . $openclaw_home);

        // Provision minimal openclaw.json if missing — browser commands need the
        // gateway auth token to connect to the running gateway server.
        $this->ensure_openclaw_config($openclaw_home);

        return escapeshellarg($openclaw_path);
    }

    /**
     * Ensure a minimal openclaw.json exists with gateway auth + browser config
     *
     * The gateway server runs under the real user's config with an auth token.
     * Browser commands from PHP (www-data) use /tmp/openclaw-home as HOME,
     * which needs a matching config so the client can authenticate.
     *
     * Only writes the config if it doesn't already exist or is missing the
     * gateway token (avoids overwriting configs provisioned by the adapter).
     */
    private function ensure_openclaw_config(string $openclaw_home): void
    {
        $config_dir  = $openclaw_home . '/.openclaw';
        $config_file = $config_dir . '/openclaw.json';

        // If config already exists with required gateway + tool schema, skip.
        // Otherwise, write/refresh a compatible config so browser/tools work.
        if (file_exists($config_file)) {
            $existing = json_decode(file_get_contents($config_file), true);
            $has_token = !empty($existing['gateway']['auth']['token']);
            $profile = $existing['browser']['profiles']['openclaw'] ?? [];
            $has_profile_color = is_array($profile) && !empty($profile['color']) && preg_match('/^[A-Fa-f0-9]{6}$/', (string) $profile['color']);
            $has_profile_cdp = is_array($profile) && (!empty($profile['cdpPort']) || !empty($profile['cdpUrl']));
            $tools_allow = $existing['tools']['allow'] ?? [];
            $has_tools_allow =
                is_array($tools_allow)
                && in_array('web_search', $tools_allow, true)
                && in_array('web_fetch', $tools_allow, true)
                && in_array('browser', $tools_allow, true)
                && in_array('read', $tools_allow, true);

            if ($has_token && $has_profile_color && $has_profile_cdp && $has_tools_allow) {
                return;
            }
        }

        if (!is_dir($config_dir)) {
            @mkdir($config_dir, 0755, true);
        }

        // Compatibility config: gateway auth + browser + tool permissions.
        // Must match the token used by the running gateway server.
        $config = [
            'browser' => [
                'enabled'        => true,
                'executablePath' => '/usr/bin/chromium-browser',
                'headless'       => true,
                'noSandbox'      => true,
                'profiles'       => [
                    'openclaw' => [
                        'color'   => '2563EB',
                        'cdpPort' => 18900,
                    ],
                ],
            ],
            'tools' => [
                'allow' => [
                    'web_search',
                    'web_fetch',
                    'browser',
                    'read',
                ],
            ],
            'gateway' => [
                'port' => 18889,
                'mode' => 'local',
                'bind' => 'loopback',
                'auth' => [
                    'token' => 'rawwire-local-dev-2025',
                ],
            ],
        ];

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($config_file, $json) === false) {
            rawwire_log('ladbs_scraper', 'Failed to write openclaw config to ' . $config_file, 'error');
        }
    }

    /**
     * Batch scrape contractors for all sources missing contractor_name
     *
     * @param int $limit Max sources to process
     * @return array Summary {total, found, failed, skipped}
     */
    public function scrape_batch(int $limit = 10): array
    {
        global $wpdb;
        $table = rawwire_leads()->table('sources');

        $sources = $wpdb->get_results($wpdb->prepare(
            "SELECT id, permit_nbr FROM {$table}
             WHERE (contractor_name IS NULL OR contractor_name = '')
               AND permit_nbr REGEXP '^[0-9]{5}-[0-9]{5}-[0-9]{5}$'
               AND (investigation_status IS NULL OR investigation_status != 'not_in_ladbs')
             ORDER BY imported_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        $summary = ['total' => count($sources), 'found' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sources as $i => $source) {
            $result = $this->scrape_contractor_for_source((int) $source['id']);

            if (!empty($result['contractor_name'])) {
                $summary['found']++;
            } elseif (!empty($result['skipped'])) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }

            // Rate limit between scrapes
            if ($i < count($sources) - 1) {
                sleep(self::RATE_LIMIT_SECONDS);
            }
        }

        rawwire_log('ladbs_scraper', sprintf(
            'Batch scrape complete: %d total, %d found, %d failed, %d skipped',
            $summary['total'],
            $summary['found'],
            $summary['failed'],
            $summary['skipped']
        ));

        return $summary;
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse permit number into 3 parts for LADBS URL
     *
     * @param string $permit Format: XXXXX-XXXXX-XXXXX or XXXXXXXXXX-XXXXX
     * @return array|null {id1, id2, id3} or null
     */
    private function parse_permit_number(string $permit): ?array
    {
        // Standard format: 25016-10000-34634
        if (preg_match('/^(\d{5})-(\d{5})-(\d{5})$/', $permit, $m)) {
            return ['id1' => $m[1], 'id2' => $m[2], 'id3' => $m[3]];
        }
        // Compact format: 2501610000-34634
        if (preg_match('/^(\d{5})(\d{5})-(\d{5})$/', $permit, $m)) {
            return ['id1' => $m[1], 'id2' => $m[2], 'id3' => $m[3]];
        }
        return null;
    }

    /**
     * Extract contractor info from LADBS page snapshot using regex
     *
     * Multiple patterns to handle different snapshot formats (plain text and AI format).
     *
     * @param string $snapshot Page accessibility tree text
     * @param string $permit   Permit number for logging
     * @return array {success, contractor_name?, contractor_license?, contractor_address?}
     */
    private function parse_contractor(string $snapshot, string $permit): array
    {
        $result = ['success' => true, 'permit_nbr' => $permit];

        // Pattern 0 (adapter-proven): cell "Contractor" + cell "NAME; Lic. No.: LICENSE"
        if (preg_match('/cell\s+"Contractor".*?cell\s+"([^;]+);\s*Lic\.\s*No\.:\s*([^"]+)"/is', $snapshot, $m)) {
            $name = trim(rtrim($m[1], ' ,'));
            if (!$this->is_owner_builder($name) && strlen($name) > 2) {
                $result['contractor_name'] = $name;
                $lic_and_more = trim($m[2]);
                if (preg_match('/^([A-Z0-9\-]+)\s*(.*)$/i', $lic_and_more, $lm)) {
                    $result['contractor_license'] = trim($lm[1]);
                    $addr = trim($lm[2] ?? '', " \t\n\r\0\x0B,\"");
                    if ($addr !== '') {
                        $result['contractor_address'] = $addr;
                    }
                }
                return $this->log_and_return($result, $permit, 'cell+license');
            }
        }

        // Pattern 1 (primary): Plain text "Contractor NAME; Lic. No.: LICENSE ADDRESS"
        if (preg_match('/Contractor\s+([^;]+);\s*Lic\.\s*No\.:\s*(\d+[-A-Z]*)\s+(.+?)(?:"|$)/i', $snapshot, $m)) {
            $name = trim($m[1]);
            if (!$this->is_owner_builder($name)) {
                $result['contractor_name']    = $name;
                $result['contractor_license'] = trim($m[2]);
                $addr = trim($m[3], " \t\n\r\0\x0B,");
                $result['contractor_address'] = $addr ?: '';
                return $this->log_and_return($result, $permit, 'plain text');
            }
        }

        // Pattern 2: Cell/row format — extract from the row summary line
        // row "Contractor COMPANY NAME; Lic. No.: LICENSE ADDR" or
        // row "Contractor COMPANY NAME ,"
        if (preg_match('/row\s+"Contractor\s+([^"]+)"/i', $snapshot, $m)) {
            $row_text = trim($m[1]);

            // Sub-pattern 2a: row includes license info
            if (preg_match('/^(.+?);\s*Lic\.\s*No\.:\s*(\d+[-A-Z]*)\s*(.*)/i', $row_text, $rl)) {
                $name = trim(rtrim($rl[1], ' ,'));
                if (!$this->is_owner_builder($name) && strlen($name) > 2) {
                    $result['contractor_name']    = $name;
                    $result['contractor_license'] = trim($rl[2]);
                    $result['contractor_address'] = trim($rl[3] ?? '', " \t\n\r\0\x0B,\"");
                    return $this->log_and_return($result, $permit, 'row+license');
                }
            }

            // Sub-pattern 2b: row without license — just name and possible comma
            $name = trim(rtrim($row_text, ' .,'));
            if (!$this->is_owner_builder($name) && strlen($name) > 2) {
                $result['contractor_name'] = $name;
                return $this->log_and_return($result, $permit, 'row name');
            }
        }

        // Pattern 3: Cell format — cell "Contractor" immediately followed by cell "NAME"
        // Restrict to same row (no /s flag — stops at newline in .*?)
        if (preg_match('/cell\s+"Contractor"[^\n]*\n\s*-\s*cell\s+"([^"]+)"/i', $snapshot, $m)) {
            $name = trim($m[1]);
            if (!$this->is_owner_builder($name) && strlen($name) > 2) {
                $result['contractor_name'] = $name;

                // Check if the next cell has license info
                $pos = strpos($snapshot, $m[0]);
                if ($pos !== false) {
                    $after = substr($snapshot, $pos + strlen($m[0]), 300);
                    if (preg_match('/Lic\.\s*No\.:\s*(\d+[-A-Z]*)(?:\s+(.+?))?"/i', $after, $lm)) {
                        $result['contractor_license'] = trim($lm[1]);
                        if (!empty($lm[2])) {
                            $result['contractor_address'] = trim($lm[2], " \t\n\r\0\x0B,\"");
                        }
                    }
                }

                return $this->log_and_return($result, $permit, 'cell format');
            }
        }

        // Pattern 3b: Same as above but allow wrapped/multi-line rows in AI snapshot
        if (preg_match('/cell\s+"Contractor".*?cell\s+"([^"]+)"/is', $snapshot, $m)) {
            $val = trim($m[1]);
            if (preg_match('/^(.+?);\s*Lic\.\s*No\.:\s*([A-Z0-9\-]+)\s*(.*)$/i', $val, $vm)) {
                $name = trim(rtrim($vm[1], ' ,'));
                if (!$this->is_owner_builder($name) && strlen($name) > 2) {
                    $result['contractor_name'] = $name;
                    $result['contractor_license'] = trim($vm[2]);
                    $addr = trim($vm[3] ?? '', " \t\n\r\0\x0B,\"");
                    if ($addr !== '') {
                        $result['contractor_address'] = $addr;
                    }
                    return $this->log_and_return($result, $permit, 'cell format wrapped');
                }
            }

            $name = trim(rtrim($val, ' .,'));
            if (!$this->is_owner_builder($name) && strlen($name) > 2 && !preg_match('/^Lic\./i', $name)) {
                $result['contractor_name'] = $name;
                return $this->log_and_return($result, $permit, 'cell format broad');
            }
        }

        // Pattern 4: Just contractor name without license (plain text)
        if (preg_match('/Contractor\s+([A-Z][A-Za-z\s&,\.\-]+(?:Inc|LLC|Corp|Construction|Builders?|Co|West|East|North|South))\b/i', $snapshot, $m)) {
            $name = trim(rtrim($m[1], ' .,'));
            if (!$this->is_owner_builder($name)) {
                $result['contractor_name'] = $name;
                return $this->log_and_return($result, $permit, 'name only');
            }
        }

        // Pattern 5: Alternate layout - "Contractor:" label followed by value
        if (preg_match('/Contractor:\s*([^\n;]+?)(?:;|$)/im', $snapshot, $m)) {
            $name = trim($m[1]);
            if (strlen($name) > 3 && !preg_match('/^(N\/A|None|Unknown|Owner)$/i', $name) && !$this->is_owner_builder($name)) {
                $result['contractor_name'] = $name;
                return $this->log_and_return($result, $permit, 'colon format');
            }
        }

        // Check for "not found" / error page
        if (preg_match('/No\s*Permits?\s*Match|No\s*(record|data)\s*found|Not\s*Available/i', $snapshot)) {
            rawwire_log('ladbs_scraper', sprintf('Permit %s: not found in LADBS', $permit), 'info');
            return ['success' => false, 'error' => 'Permit not found in LADBS', 'not_in_ladbs' => true];
        }

        // Check if it's an Owner-Builder permit (no external contractor)
        if (preg_match('/Owner[\-\s]?Builder/i', $snapshot)) {
            // Owner-Builder — but check for engineers on the permit as fallback leads
            $engineer = $this->parse_engineer($snapshot);

            if (!empty($engineer['name'])) {
                rawwire_log('ladbs_scraper', sprintf(
                    'Permit %s: Owner-Builder permit — promoting engineer "%s" (Lic: %s) as lead',
                    $permit,
                    $engineer['name'],
                    $engineer['license'] ?? 'N/A'
                ), 'info');

                $result['contractor_name']    = $engineer['name'];
                $result['contractor_license'] = $engineer['license'] ?? '';
                $result['contractor_address'] = $engineer['address'] ?? '';
                $result['owner_builder']      = true;
                $result['engineer_promoted']  = true;
                return $this->log_and_return($result, $permit, 'owner-builder→engineer');
            }

            rawwire_log('ladbs_scraper', sprintf(
                'Permit %s: Owner-Builder permit — no external contractor or engineer',
                $permit
            ), 'info');
            return [
                'success'         => true,
                'contractor_name' => '',
                'owner_builder'   => true,
                'error'           => 'Owner-Builder permit — no external contractor',
            ];
        }

        rawwire_log('ladbs_scraper', sprintf(
            'Permit %s: Could not parse contractor from %d-char snapshot',
            $permit,
            strlen($snapshot)
        ), 'warning');

        rawwire_log('ladbs_scraper', sprintf(
            'Permit %s: Snapshot excerpt for parse failure: %s',
            $permit,
            substr(preg_replace('/\s+/', ' ', $snapshot), 0, 800)
        ), 'debug');

        return ['success' => false, 'error' => 'Could not parse contractor from snapshot'];
    }

    /**
     * Extract engineer info from LADBS snapshot (fallback for Owner-Builder permits)
     *
     * LADBS pages list engineers in the Contact Information table:
     *   row "Engineer Lastname,, Firstname; Lic. No.: XXXXX ADDRESS"
     *   cell "Engineer" / cell "Name; Lic. No.: XXXXX" / cell "ADDRESS"
     *
     * @param string $snapshot  Text snapshot from OpenClaw browser
     * @return array{name?: string, license?: string, address?: string}
     */
    private function parse_engineer(string $snapshot): array
    {
        // Pattern 1: Row summary — "Engineer NAME; Lic. No.: LICENSE ADDRESS"
        if (preg_match('/row\s+"Engineer\s+([^"]+)"/i', $snapshot, $m)) {
            $row_text = trim($m[1]);

            // Sub-pattern: row includes license info
            if (preg_match('/^(.+?);\s*Lic\.\s*No\.:\s*([A-Z]?\d+[-A-Z]*)\s*(.*)/i', $row_text, $rl)) {
                $name = $this->clean_engineer_name(trim(rtrim($rl[1], ' ,')));
                if (strlen($name) > 2) {
                    return [
                        'name'    => $name,
                        'license' => trim($rl[2]),
                        'address' => trim($rl[3] ?? '', " \t\n\r\0\x0B,\""),
                    ];
                }
            }

            // Sub-pattern: row without license
            $name = $this->clean_engineer_name(trim(rtrim($row_text, ' .,')));
            if (strlen($name) > 2) {
                return ['name' => $name];
            }
        }

        // Pattern 2: Cell format — cell "Engineer" followed by cell "NAME; Lic. No.:"
        if (preg_match('/cell\s+"Engineer"[^\n]*\n\s*-\s*cell\s+"([^"]+)"/i', $snapshot, $m)) {
            $val = trim($m[1]);
            if (preg_match('/^(.+?);\s*Lic\.\s*No\.:\s*([A-Z]?\d+[-A-Z]*)/i', $val, $lm)) {
                $name = $this->clean_engineer_name(trim(rtrim($lm[1], ' ,')));
                if (strlen($name) > 2) {
                    // Try to get address from next cell
                    $address = '';
                    $pos = strpos($snapshot, $m[0]);
                    if ($pos !== false) {
                        $after = substr($snapshot, $pos + strlen($m[0]), 300);
                        if (preg_match('/cell\s+"([^"]+)"/i', $after, $am)) {
                            $addr = trim($am[1]);
                            // Only treat it as address if it looks like one (has numbers + letters)
                            if (preg_match('/\d/', $addr) && preg_match('/[A-Z]/i', $addr)) {
                                $address = $addr;
                            }
                        }
                    }
                    return [
                        'name'    => $name,
                        'license' => trim($lm[2]),
                        'address' => $address,
                    ];
                }
            }

            // No license info
            $name = $this->clean_engineer_name($val);
            if (strlen($name) > 2) {
                return ['name' => $name];
            }
        }

        return [];
    }

    /**
     * Parse owner/applicant parties from LADBS snapshot.
     *
     * @return array{owner_name?: string, applicant_name?: string}
     */
    private function parse_related_parties(string $snapshot): array
    {
        $parties = [];

        $owner = $this->parse_labeled_party($snapshot, 'Owner');
        if ($owner !== '') {
            $parties['owner_name'] = $owner;
        }

        $applicant = $this->parse_labeled_party($snapshot, 'Applicant');
        if ($applicant !== '') {
            $parties['applicant_name'] = $applicant;
        }

        return $parties;
    }

    /**
     * Parse a labeled party value from common LADBS row/cell layouts.
     */
    private function parse_labeled_party(string $snapshot, string $label): string
    {
        $patterns = [
            '/row\s+"' . preg_quote($label, '/') . '\s+([^"]+)"/i',
            '/cell\s+"' . preg_quote($label, '/') . '".*?cell\s+"([^"]+)"/is',
            '/' . preg_quote($label, '/') . '\s*:\s*([^\n;]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $snapshot, $m)) {
                continue;
            }

            $value = trim($m[1] ?? '');
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/;\s*Lic\.\s*No\.:.*$/i', '', $value);
            $value = preg_replace('/\s{2,}.*/', '', (string) $value);
            $value = trim((string) $value, " \t\n\r\0\x0B,.;:\"");

            if ($value === '' || strlen($value) < 3 || $this->is_owner_builder($value)) {
                continue;
            }

            if (preg_match('/^(N\/A|None|Unknown|Not\s+Provided)$/i', $value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    /**
     * Normalize LADBS engineer name format: "Last,, First" → "First Last"
     *
     * @param string $name Raw engineer name from LADBS
     * @return string Cleaned name
     */
    private function clean_engineer_name(string $name): string
    {
        // LADBS format: "Dorta,, Michael Rohrs" → "Michael Rohrs Dorta"
        if (preg_match('/^([^,]+),+\s*(.+)$/i', $name, $m)) {
            $last  = trim($m[1]);
            $first = trim($m[2]);
            if (strlen($first) > 0 && strlen($last) > 0) {
                return $first . ' ' . $last;
            }
        }
        return $name;
    }

    /**
     * Check if name is an Owner-Builder designation (not a real contractor)
     */
    private function is_owner_builder(string $name): bool
    {
        $name = strtolower(trim($name));
        return in_array($name, ['owner-builder', 'owner builder', 'ownerbuilder', 'owner/builder'], true)
            || preg_match('/^owner[\s\-\/]?builder$/i', $name);
    }

    /**
     * Log contractor discovery and return result
     */
    private function log_and_return(array $result, string $permit, string $method): array
    {
        // Sanitize accessibility-tree artifacts from contractor name before returning
        if (!empty($result['contractor_name'])) {
            $result['contractor_name'] = $this->sanitize_snapshot_name($result['contractor_name']);
        }

        rawwire_log('ladbs_scraper', sprintf(
            'Permit %s: Found contractor (%s) "%s"%s',
            $permit,
            $method,
            $result['contractor_name'],
            !empty($result['contractor_license']) ? ' (Lic: ' . $result['contractor_license'] . ')' : ''
        ));
        return $result;
    }

    /**
     * Strip accessibility-tree / snapshot markup from extracted names.
     *
     * OpenClaw browser snapshots use formats like:
     *   cell "Name" [ref=e209]
     *   row "Contractor Name; Lic. No.: 12345"
     * These can leak into extracted names when regex patterns capture too broadly.
     */
    private function sanitize_snapshot_name(string $name): string
    {
        // Remove [ref=XXXX] tags
        $name = preg_replace('/\s*\[ref=[a-z0-9]+\]/i', '', $name);

        // Remove accessibility tree prefixes: cell ", row ", - cell, - row
        $name = preg_replace('/\s*-?\s*(?:cell|row)\s+"/i', '', $name);

        // Remove stray quotes and leading/trailing punctuation
        $name = trim($name, " \t\n\r\0\x0B\"',.-");

        // If after cleanup the name contains newlines, take only the first line
        if (strpos($name, "\n") !== false) {
            $name = trim(strtok($name, "\n"), " \t\"',.-");
        }

        return $name;
    }
}
