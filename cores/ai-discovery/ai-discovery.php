<?php
/**
 * AI Discovery Engine
 * Uses AI to actively search for shocking/unbelievable facts across sources
 *
 * @package RawWire_Dashboard
 * @since 1.0.21
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RawWire AI Discovery Class
 *
 * Actively searches for shocking, unbelievable facts using AI-powered analysis
 */
class RawWire_AI_Discovery {

    /**
     * AI model to use for discovery
     */
    private $ai_model = 'gpt-4';

    /**
     * Search queries for shocking content
     */
    private $shocking_queries = [
        'shocking scientific discoveries',
        'unbelievable historical facts',
        'controversial revelations',
        'hidden truths exposed',
        'mind-blowing statistics',
        'unbelievable conspiracy theories proven true',
        'shocking government secrets revealed',
        'incredible technological breakthroughs',
        'unbelievable natural phenomena',
        'shocking medical discoveries'
    ];

    /**
     * Initialize the AI discovery engine
     */
    public static function init() {
        add_action('rawwire_ai_discovery_cron', array(__CLASS__, 'run_discovery'));
        add_action('wp_ajax_rawwire_run_ai_discovery', array(__CLASS__, 'ajax_run_discovery'));
    }

    /**
     * Run AI-powered discovery across all sources
     */
    public static function run_discovery() {
        $instance = new self();
        $sources = $instance->get_discovery_sources();

        $total_found = 0;
        foreach ($sources as $source) {
            $facts = $instance->discover_from_source($source);
            $total_found += $instance->process_discovered_facts($facts, $source);
        }

        // Log results
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::log("AI Discovery completed: $total_found facts found", 'info', [
                'component' => 'ai-discovery',
                'facts_found' => $total_found
            ]);
        }

        return $total_found;
    }

    /**
     * AJAX handler for manual discovery runs
     */
    public static function ajax_run_discovery() {
        check_ajax_referer('rawwire_ai_discovery', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $count = self::run_discovery();

        wp_send_json_success([
            'message' => "Discovery completed: $count facts found",
            'facts_found' => $count
        ]);
    }

    /**
     * Get sources for AI discovery
     */
    private function get_discovery_sources() {
        // Get sources from active template
        if (!class_exists('RawWire_Template_Engine')) {
            return [];
        }

        $template = RawWire_Template_Engine::get_active_template();
        $sources = $template['sources']['items'] ?? [];

        // Add GitHub as a discovery source
        $sources[] = [
            'id' => 'github_discovery',
            'type' => 'github_api',
            'label' => 'GitHub Trending Repositories',
            'enabled' => true,
            'category' => 'tech'
        ];

        // Add Reddit as a discovery source
        $sources[] = [
            'id' => 'reddit_discovery',
            'type' => 'reddit_api',
            'label' => 'Reddit Controversial',
            'enabled' => true,
            'category' => 'social'
        ];

        return array_filter($sources, function($source) {
            return $source['enabled'] ?? false;
        });
    }

    /**
     * Discover facts from a specific source using AI
     */
    private function discover_from_source($source) {
        switch ($source['type']) {
            case 'rss':
                return $this->discover_from_rss($source);
            case 'api':
                return $this->discover_from_api($source);
            case 'github_api':
                return $this->discover_from_github($source);
            case 'reddit_api':
                return $this->discover_from_reddit($source);
            case 'sec_api':
                return $this->discover_from_sec($source);
            case 'federal_register_api':
                return $this->discover_from_federal_register($source);
            case 'pacer_api':
                return $this->discover_from_pacer($source);
            case 'fda_api':
                return $this->discover_from_fda($source);
            case 'epa_api':
                return $this->discover_from_epa($source);
            case 'treasury_api':
                return $this->discover_from_treasury($source);
            case 'congress_api':
                return $this->discover_from_congress($source);
            case 'uspto_api':
                return $this->discover_from_uspto($source);
            case 'census_api':
                return $this->discover_from_census($source);
            case 'noaa_api':
                return $this->discover_from_noaa($source);
            case 'nasa_api':
                return $this->discover_from_nasa($source);
            case 'bls_api':
                return $this->discover_from_bls($source);
            case 'custom':
                return $this->discover_from_custom($source);
            default:
                return [];
        }
    }

    /**
     * Discover shocking facts from RSS feeds using AI analysis
     */
    private function discover_from_rss($source) {
        // First get RSS content
        include_once(ABSPATH . WPINC . '/feed.php');
        $rss = fetch_feed($source['url']);

        if (is_wp_error($rss)) {
            return [];
        }

        $items = $rss->get_items(0, 20);
        $facts = [];

        foreach ($items as $item) {
            $content = $item->get_content() ?: $item->get_description();
            $title = $item->get_title();

            // Use AI to analyze if this contains shocking facts
            $analysis = $this->analyze_content_for_shock_value($title, $content);

            if ($analysis['is_shocking']) {
                $facts[] = [
                    'title' => $title,
                    'content' => $content,
                    'url' => $item->get_link(),
                    'source' => $source['label'],
                    'source_type' => 'rss',
                    'published_at' => $item->get_date('Y-m-d H:i:s'),
                    'ai_analysis' => $analysis,
                    'score' => $analysis['score']
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from GitHub using free APIs
     */
    private function discover_from_github($source) {
        $facts = [];

        // GitHub Search API - find repositories with shocking topics
        $queries = [
            'topic:conspiracy-theories',
            'topic:unbelievable-facts',
            'topic:shocking-discoveries',
            'topic:controversial-research',
            'topic:mind-blowing-science'
        ];

        foreach ($queries as $query) {
            $repos = $this->search_github_repos($query);

            foreach ($repos as $repo) {
                // Analyze repository description and README for shocking content
                $analysis = $this->analyze_github_repo($repo);

                if ($analysis['is_shocking']) {
                    $facts[] = [
                        'title' => $repo['name'] . ': ' . ($repo['description'] ?: 'Shocking discovery'),
                        'content' => $repo['description'] . "\n\n" . ($repo['readme_excerpt'] ?: ''),
                        'url' => $repo['html_url'],
                        'source' => 'GitHub',
                        'source_type' => 'github',
                        'published_at' => $repo['updated_at'],
                        'ai_analysis' => $analysis,
                        'score' => $analysis['score'],
                        'metadata' => [
                            'stars' => $repo['stargazers_count'],
                            'language' => $repo['language'],
                            'topics' => $repo['topics']
                        ]
                    ];
                }
            }
        }

        return $facts;
    }

    /**
     * Search GitHub repositories (free API, 1000 requests/hour)
     */
    private function search_github_repos($query) {
        $url = "https://api.github.com/search/repositories?q=" . urlencode($query) . "&sort=stars&order=desc&per_page=10";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['items'])) {
            return [];
        }

        return $data['items'];
    }

    /**
     * Analyze GitHub repository for shocking content
     */
    private function analyze_github_repo($repo) {
        $text_to_analyze = $repo['name'] . ' ' . ($repo['description'] ?: '') . ' ' . implode(' ', $repo['topics'] ?: []);

        return $this->analyze_content_for_shock_value($repo['name'], $text_to_analyze);
    }

    /**
     * Discover from Reddit (free API with rate limits)
     */
    private function discover_from_reddit($source) {
        $facts = [];

        $subreddits = ['conspiracy', 'Unbelievable', 'mindblowing', 'shocking', 'controversial'];

        foreach ($subreddits as $subreddit) {
            $posts = $this->fetch_reddit_posts($subreddit);

            foreach ($posts as $post) {
                $analysis = $this->analyze_content_for_shock_value($post['title'], $post['selftext']);

                if ($analysis['is_shocking']) {
                    $facts[] = [
                        'title' => $post['title'],
                        'content' => $post['selftext'],
                        'url' => 'https://reddit.com' . $post['permalink'],
                        'source' => 'Reddit r/' . $subreddit,
                        'source_type' => 'reddit',
                        'published_at' => date('Y-m-d H:i:s', $post['created_utc']),
                        'ai_analysis' => $analysis,
                        'score' => $analysis['score'],
                        'metadata' => [
                            'score' => $post['score'],
                            'comments' => $post['num_comments']
                        ]
                    ];
                }
            }
        }

        return $facts;
    }

    /**
     * Fetch Reddit posts (free API)
     */
    private function fetch_reddit_posts($subreddit) {
        $url = "https://www.reddit.com/r/$subreddit/hot.json?limit=10";

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['data']['children'])) {
            return [];
        }

        return array_map(function($child) {
            return $child['data'];
        }, $data['data']['children']);
    }

    /**
     * Use AI to analyze content for shock value
     */
    private function analyze_content_for_shock_value($title, $content) {
        // For now, return mock analysis - in production this would call GPT-4
        $combined_text = strtolower($title . ' ' . $content);

        $shock_indicators = [
            'shocking', 'unbelievable', 'mind-blowing', 'controversial', 'revealed',
            'exposed', 'secret', 'conspiracy', 'scandal', 'bombshell', 'stunning'
        ];

        $score = 0;
        foreach ($shock_indicators as $word) {
            if (strpos($combined_text, $word) !== false) {
                $score += 10;
            }
        }

        // Length bonus for detailed content
        if (strlen($content) > 500) {
            $score += 5;
        }

        return [
            'is_shocking' => $score >= 30,
            'score' => min(100, $score),
            'shock_factors' => $shock_indicators,
            'analysis_method' => 'keyword_analysis', // Will be 'ai_analysis' when GPT-4 integrated
            'confidence' => $score / 100
        ];
    }

    /**
     * Process discovered facts and save to database
     * @note Uses approvals table (candidates → approvals workflow)
     */
    private function process_discovered_facts($facts, $source) {
        global $wpdb;

        $saved = 0;
        $table_name = $wpdb->prefix . 'rawwire_approvals';

        foreach ($facts as $fact) {
            // Check for duplicates
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE url = %s",
                $fact['url']
            ));

            if ($existing) {
                continue; // Skip duplicates
            }

            // Prepare copyright information and metadata
            $metadata = [];
            $is_public_domain = 1; // Default to public domain
            if (isset($fact['copyright_info'])) {
                $metadata['copyright_info'] = $fact['copyright_info'];
                $is_public_domain = 0;
                $metadata['copyright_info']['citation'] = $this->generate_citation($fact, $fact['copyright_info']);
            } elseif (isset($source['config']['is_public_domain']) && !$source['config']['is_public_domain']) {
                $metadata['copyright_info'] = $source['config']['copyright_info'] ?? [];
                $is_public_domain = 0;
                $metadata['copyright_info']['citation'] = $this->generate_citation($fact, $metadata['copyright_info']);
            }
            
            // Add AI analysis and source metadata
            if (!empty($fact['ai_analysis'])) {
                $metadata['ai_analysis'] = $fact['ai_analysis'];
            }
            if (!empty($fact['metadata'])) {
                $metadata['source_metadata'] = $fact['metadata'];
            }
            $metadata['is_public_domain'] = $is_public_domain;

            // Insert into approvals table
            $result = $wpdb->insert($table_name, [
                'source' => $source['id'] ?? 'ai_discovery',
                'title' => $fact['title'],
                'excerpt' => wp_trim_words($fact['content'], 50),
                'content' => $fact['content'],
                'url' => $fact['url'],
                'score' => $fact['score'],
                'status' => 'pending',
                'category' => 'ai_discovery',
                'metadata' => !empty($metadata) ? json_encode($metadata) : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            if ($result) {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Generate citation for copyrighted content
     */
    private function generate_citation($fact, $copyright_info) {
        $citation = '';

        // Use provided citation format or generate standard one
        if (!empty($copyright_info['citation'])) {
            $citation = $copyright_info['citation'];
        } else {
            // Generate citation based on license type
            $license = $copyright_info['license'] ?? 'All Rights Reserved';
            $attribution = $copyright_info['attribution'] ?? '';

            switch ($license) {
                case 'CC BY':
                    $citation = "© {$attribution}. Licensed under Creative Commons Attribution.";
                    break;
                case 'CC BY-SA':
                    $citation = "© {$attribution}. Licensed under Creative Commons Attribution-ShareAlike.";
                    break;
                case 'CC BY-NC':
                    $citation = "© {$attribution}. Licensed under Creative Commons Attribution-NonCommercial.";
                    break;
                case 'CC BY-NC-SA':
                    $citation = "© {$attribution}. Licensed under Creative Commons Attribution-NonCommercial-ShareAlike.";
                    break;
                case 'CC0':
                    $citation = "© {$attribution}. Released under CC0 Public Domain Dedication.";
                    break;
                default:
                    $citation = "© {$attribution}. {$license}.";
            }

            // Add source URL if available
            if (!empty($fact['url'])) {
                $citation .= " Source: {$fact['url']}";
            }
        }

        return $citation;
    }

    /**
     * Get discovery statistics
     * @note Uses approvals table (workflow tables)
     */
    public static function get_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rawwire_approvals';

        return [
            'total_discovered' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE source LIKE '%discovery%' OR category = 'ai_discovery'"),
            'shocking_facts' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE score >= 70"),
            'pending_review' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'"),
            'last_discovery' => $wpdb->get_var("SELECT MAX(created_at) FROM $table_name WHERE source LIKE '%discovery%' OR category = 'ai_discovery'")
        ];
    }

    /**
     * Discover from SEC EDGAR (free API)
     */
    private function discover_from_sec($source) {
        $facts = [];

        // SEC EDGAR current events RSS feed
        $rss_url = "https://www.sec.gov/edgar/searchedgar/currentevents.atom";

        $response = wp_remote_get($rss_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $xml = wp_remote_retrieve_body($response);
        $feed = simplexml_load_string($xml);

        if (!$feed) {
            return [];
        }

        foreach ($feed->entry as $entry) {
            $title = (string) $entry->title;
            $summary = (string) $entry->summary;
            $link = (string) $entry->link['href'];

            // Check for shocking keywords
            $combined_text = strtolower($title . ' ' . $summary);
            $shocking_keywords = ['scandal', 'fraud', 'investigation', 'lawsuit', 'violation', 'penalty', 'settlement'];

            $is_shocking = false;
            foreach ($shocking_keywords as $keyword) {
                if (strpos($combined_text, $keyword) !== false) {
                    $is_shocking = true;
                    break;
                }
            }

            if ($is_shocking) {
                $facts[] = [
                    'title' => $title,
                    'content' => $summary,
                    'url' => $link,
                    'source' => 'SEC EDGAR',
                    'source_type' => 'sec',
                    'published_at' => date('Y-m-d H:i:s'),
                    'ai_analysis' => $this->analyze_content_for_shock_value($title, $summary),
                    'score' => 75
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from Federal Register (free API)
     */
    private function discover_from_federal_register($source) {
        $facts = [];

        $api_url = "https://www.federalregister.gov/api/v1/documents.json";
        $params = [
            'per_page' => 20,
            'order' => 'newest',
            'conditions[agencies][]' => 'epa,fda,treasury,justice,sec'
        ];

        $response = wp_remote_get($api_url . '?' . http_build_query($params), [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        foreach ($data['results'] as $document) {
            $title = $document['title'] ?? '';
            $abstract = $document['abstract'] ?? '';
            $document_number = $document['document_number'] ?? '';

            $combined_text = strtolower($title . ' ' . $abstract);
            $shocking_keywords = ['emergency', 'violation', 'penalty', 'investigation', 'safety', 'recall'];

            $is_shocking = false;
            foreach ($shocking_keywords as $keyword) {
                if (strpos($combined_text, $keyword) !== false) {
                    $is_shocking = true;
                    break;
                }
            }

            if ($is_shocking) {
                $facts[] = [
                    'title' => $title,
                    'content' => $abstract,
                    'url' => "https://www.federalregister.gov/documents/{$document['publication_date']}/{$document_number}",
                    'source' => 'Federal Register',
                    'source_type' => 'federal_register',
                    'published_at' => $document['publication_date'] ?? date('Y-m-d H:i:s'),
                    'ai_analysis' => $this->analyze_content_for_shock_value($title, $abstract),
                    'score' => 70
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from PACER (free court filings)
     */
    private function discover_from_pacer($source) {
        $facts = [];

        // Use RECAP or similar free court data sources
        $api_url = "https://www.courtlistener.com/api/rest/v3";

        $response = wp_remote_get($api_url . '/search/', [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        foreach ($data['results'] as $case) {
            $case_name = $case['caseName'] ?? '';
            $court = $case['court'] ?? '';
            $date_filed = $case['dateFiled'] ?? '';

            $combined_text = strtolower($case_name);
            $shocking_keywords = ['corruption', 'fraud', 'scandal', 'bribery', 'conspiracy', 'murder', 'theft'];

            $is_shocking = false;
            foreach ($shocking_keywords as $keyword) {
                if (strpos($combined_text, $keyword) !== false) {
                    $is_shocking = true;
                    break;
                }
            }

            if ($is_shocking) {
                $facts[] = [
                    'title' => $case_name,
                    'content' => "Court case filed in {$court}",
                    'url' => "https://www.courtlistener.com{$case['absolute_url']}",
                    'source' => 'PACER/CourtListener',
                    'source_type' => 'pacer',
                    'published_at' => $date_filed,
                    'ai_analysis' => $this->analyze_content_for_shock_value($case_name, $case_name),
                    'score' => 80
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from FDA (free API)
     */
    private function discover_from_fda($source) {
        $facts = [];

        // FDA recalls and warnings
        $api_url = "https://api.fda.gov/food/enforcement.json";

        $response = wp_remote_get($api_url . '?limit=20&sort=report_date:desc', [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        foreach ($data['results'] as $recall) {
            $product_description = $recall['product_description'] ?? '';
            $reason_for_recall = $recall['reason_for_recall'] ?? '';
            $classification = $recall['classification'] ?? '';

            $title = "FDA Recall: {$product_description}";
            $content = "Reason: {$reason_for_recall}. Classification: {$classification}";

            $facts[] = [
                'title' => $title,
                'content' => $content,
                'url' => $recall['recall_initiation_date'] ? "https://www.fda.gov/safety/recalls-market-withdrawals-safety-alerts/{$recall['recall_number']}" : '#',
                'source' => 'FDA',
                'source_type' => 'fda',
                'published_at' => $recall['recall_initiation_date'] ?? date('Y-m-d H:i:s'),
                'ai_analysis' => $this->analyze_content_for_shock_value($title, $content),
                'score' => 85
            ];
        }

        return $facts;
    }

    /**
     * Discover from EPA (free API)
     */
    private function discover_from_epa($source) {
        $facts = [];

        // EPA enforcement RSS feed
        $rss_url = "https://www.epa.gov/enforcement/enforcement-alerts-rss.xml";

        $response = wp_remote_get($rss_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $xml = wp_remote_retrieve_body($response);
        $feed = simplexml_load_string($xml);

        if (!$feed) {
            return [];
        }

        foreach ($feed->channel->item as $item) {
            $title = (string) $item->title;
            $description = (string) $item->description;
            $link = (string) $item->link;

            $combined_text = strtolower($title . ' ' . $description);
            $shocking_keywords = ['violation', 'penalty', 'fine', 'illegal', 'hazardous', 'toxic'];

            $is_shocking = false;
            foreach ($shocking_keywords as $keyword) {
                if (strpos($combined_text, $keyword) !== false) {
                    $is_shocking = true;
                    break;
                }
            }

            if ($is_shocking) {
                $facts[] = [
                    'title' => $title,
                    'content' => $description,
                    'url' => $link,
                    'source' => 'EPA',
                    'source_type' => 'epa',
                    'published_at' => date('Y-m-d H:i:s'),
                    'ai_analysis' => $this->analyze_content_for_shock_value($title, $description),
                    'score' => 75
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from Treasury/OFAC (free data)
     */
    private function discover_from_treasury($source) {
        $facts = [];

        // OFAC SDN list updates
        $api_url = "https://www.treasury.gov/ofac/downloads/sdn.xml";

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $xml = wp_remote_retrieve_body($response);
        $data = simplexml_load_string($xml);

        if (!$data) {
            return [];
        }

        // Get recent additions (simplified - in reality would compare with previous version)
        $recent_count = min(10, count($data->sdnEntry));

        for ($i = 0; $i < $recent_count; $i++) {
            $entry = $data->sdnEntry[$i];
            $name = (string) $entry->firstName . ' ' . (string) $entry->lastName;
            $remarks = (string) $entry->remarks;

            if (!empty($name)) {
                $facts[] = [
                    'title' => "OFAC Sanction: {$name}",
                    'content' => $remarks ?: 'Added to OFAC sanctions list',
                    'url' => 'https://www.treasury.gov/ofac/downloads/sdnlist.txt',
                    'source' => 'Treasury OFAC',
                    'source_type' => 'treasury',
                    'published_at' => date('Y-m-d H:i:s'),
                    'ai_analysis' => $this->analyze_content_for_shock_value($name, $remarks),
                    'score' => 70
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from Congress.gov (free API)
     */
    private function discover_from_congress($source) {
        $facts = [];

        $api_url = "https://api.congress.gov/v3/bill";
        $params = [
            'api_key' => '', // Would need API key for full access
            'limit' => 20,
            'sort' => '-introducedDate'
        ];

        $response = wp_remote_get($api_url . '?' . http_build_query($params), [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['bills'])) {
            return [];
        }

        foreach ($data['bills'] as $bill) {
            $title = $bill['title'] ?? '';
            $summary = $bill['summary'] ?? '';

            $combined_text = strtolower($title . ' ' . $summary);
            $shocking_keywords = ['emergency', 'oversight', 'investigation', 'scandal', 'corruption'];

            $is_shocking = false;
            foreach ($shocking_keywords as $keyword) {
                if (strpos($combined_text, $keyword) !== false) {
                    $is_shocking = true;
                    break;
                }
            }

            if ($is_shocking) {
                $facts[] = [
                    'title' => $title,
                    'content' => $summary,
                    'url' => $bill['url'] ?? "https://www.congress.gov/bill/{$bill['congress']}/{$bill['type']}/{$bill['number']}",
                    'source' => 'Congress.gov',
                    'source_type' => 'congress',
                    'published_at' => $bill['introducedDate'] ?? date('Y-m-d H:i:s'),
                    'ai_analysis' => $this->analyze_content_for_shock_value($title, $summary),
                    'score' => 65
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from USPTO (free API)
     */
    private function discover_from_uspto($source) {
        $facts = [];

        // USPTO patent search API
        $api_url = "https://developer.uspto.gov/ibd-api/v1/search";

        $response = wp_remote_post($api_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0',
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'searchText' => 'artificial intelligence OR blockchain OR quantum',
                'start' => 0,
                'rows' => 10
            ])
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        foreach ($data['results'] as $patent) {
            $title = $patent['title'] ?? '';
            $abstract = $patent['abstract'] ?? '';

            $facts[] = [
                'title' => "Patent: {$title}",
                'content' => $abstract,
                'url' => "https://patentcenter.uspto.gov/applications/{$patent['applicationNumber']}",
                'source' => 'USPTO',
                'source_type' => 'uspto',
                'published_at' => date('Y-m-d H:i:s'),
                'ai_analysis' => $this->analyze_content_for_shock_value($title, $abstract),
                'score' => 60
            ];
        }

        return $facts;
    }

    /**
     * Discover from Census Bureau (free API)
     */
    private function discover_from_census($source) {
        $facts = [];

        // Census data API - get latest population estimates
        $api_url = "https://api.census.gov/data/2020/acs/acs5";

        $response = wp_remote_get($api_url . '?get=NAME,B01003_001E&for=state:*', [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || count($data) < 2) {
            return [];
        }

        // Look for significant changes (simplified)
        for ($i = 1; $i < min(10, count($data)); $i++) {
            $state = $data[$i][0];
            $population = $data[$i][1];

            $facts[] = [
                'title' => "Census Data: {$state} Population",
                'content' => "2020 Census shows {$state} has a population of " . number_format($population),
                'url' => 'https://www.census.gov/data.html',
                'source' => 'US Census Bureau',
                'source_type' => 'census',
                'published_at' => date('Y-m-d H:i:s'),
                'ai_analysis' => $this->analyze_content_for_shock_value($state, "Population: {$population}"),
                'score' => 50
            ];
        }

        return $facts;
    }

    /**
     * Discover from NOAA (free API)
     */
    private function discover_from_noaa($source) {
        $facts = [];

        // NOAA climate data API
        $api_url = "https://www.ncdc.noaa.gov/cdo-web/api/v2/data";

        $response = wp_remote_get($api_url . '?datasetid=GHCND&stationid=GHCND:USW00014732&startdate=2024-01-01&enddate=2024-12-31&limit=10', [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['results'])) {
            return [];
        }

        foreach ($data['results'] as $record) {
            $datatype = $record['datatype'] ?? '';
            $value = $record['value'] ?? '';
            $date = $record['date'] ?? '';

            if ($datatype === 'TMAX' && $value > 350) { // Extreme heat
                $facts[] = [
                    'title' => "Extreme Weather: Record High Temperature",
                    'content' => "Temperature reached " . ($value / 10) . "°C on {$date}",
                    'url' => 'https://www.noaa.gov/weather',
                    'source' => 'NOAA',
                    'source_type' => 'noaa',
                    'published_at' => $date,
                    'ai_analysis' => $this->analyze_content_for_shock_value("Extreme Heat", "Temperature: {$value}"),
                    'score' => 75
                ];
            }
        }

        return $facts;
    }

    /**
     * Discover from NASA (free API)
     */
    private function discover_from_nasa($source) {
        $facts = [];

        // NASA APOD API
        $api_url = "https://api.nasa.gov/planetary/apod";

        $response = wp_remote_get($api_url . '?api_key=DEMO_KEY&count=5', [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0'
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data) {
            return [];
        }

        foreach ($data as $apod) {
            $title = $apod['title'] ?? '';
            $explanation = $apod['explanation'] ?? '';

            $facts[] = [
                'title' => "NASA Discovery: {$title}",
                'content' => $explanation,
                'url' => $apod['url'] ?? '',
                'source' => 'NASA',
                'source_type' => 'nasa',
                'published_at' => date('Y-m-d H:i:s'),
                'ai_analysis' => $this->analyze_content_for_shock_value($title, $explanation),
                'score' => 65
            ];
        }

        return $facts;
    }

    /**
     * Discover from BLS (free API)
     */
    private function discover_from_bls($source) {
        $facts = [];

        // BLS unemployment data
        $api_url = "https://api.bls.gov/publicAPI/v2/timeseries/data";

        $response = wp_remote_post($api_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RawWire-Discovery/1.0',
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'seriesid' => ['LNS14000000'],
                'startyear' => '2024',
                'endyear' => '2024'
            ])
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!$data || !isset($data['Results']['series'])) {
            return [];
        }

        foreach ($data['Results']['series'] as $series) {
            foreach ($series['data'] as $datapoint) {
                $period = $datapoint['period'] ?? '';
                $value = $datapoint['value'] ?? '';

                if ($period === 'M12' && $value > 4.0) { // High unemployment
                    $facts[] = [
                        'title' => "High Unemployment Rate",
                        'content' => "December 2024 unemployment rate: {$value}%",
                        'url' => 'https://www.bls.gov/news.release/empsit.nr0.htm',
                        'source' => 'Bureau of Labor Statistics',
                        'source_type' => 'bls',
                        'published_at' => date('Y-m-d H:i:s'),
                        'ai_analysis' => $this->analyze_content_for_shock_value("High Unemployment", "Rate: {$value}%"),
                        'score' => 70
                    ];
                }
            }
        }

        return $facts;
    }

    /**
     * Discover from custom source
     */
    private function discover_from_custom($source) {
        $facts = [];

        if (empty($source['config']['url'])) {
            return [];
        }

        $url = $source['config']['url'];
        $method = $source['config']['method'] ?? 'GET';
        $headers = $source['config']['headers'] ?? [];
        $params = $source['config']['params'] ?? [];
        $parser = $source['config']['parser'] ?? 'json';

        $headers['User-Agent'] = 'RawWire-Discovery/1.0';

        $args = [
            'timeout' => 30,
            'headers' => $headers
        ];

        if ($method === 'POST' && !empty($params)) {
            $args['body'] = json_encode($params);
            $args['headers']['Content-Type'] = 'application/json';
        } elseif ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);

        if ($parser === 'json') {
            $data = json_decode($body, true);
            if ($data) {
                // Generic JSON parser - assumes array of items with title/content fields
                if (is_array($data) && isset($data[0])) {
                    foreach (array_slice($data, 0, 10) as $item) {
                        if (isset($item['title']) && isset($item['content'])) {
                            $fact = [
                                'title' => $item['title'],
                                'content' => $item['content'],
                                'url' => $item['url'] ?? $url,
                                'source' => $source['label'],
                                'source_type' => 'custom',
                                'published_at' => date('Y-m-d H:i:s'),
                                'ai_analysis' => $this->analyze_content_for_shock_value($item['title'], $item['content']),
                                'score' => 60
                            ];

                            // Add copyright information if source is not public domain
                            if (isset($source['config']['is_public_domain']) && !$source['config']['is_public_domain']) {
                                $fact['copyright_info'] = $source['config']['copyright_info'] ?? [];
                            }

                            $facts[] = $fact;
                        }
                    }
                }
            }
        } elseif ($parser === 'xml') {
            $xml = simplexml_load_string($body);
            if ($xml) {
                // Generic XML parser - assumes RSS-like structure
                foreach ($xml->channel->item as $item) {
                    $title = (string) $item->title;
                    $description = (string) $item->description;
                    $link = (string) $item->link;

                    if ($title && $description) {
                        $fact = [
                            'title' => $title,
                            'content' => $description,
                            'url' => $link ?: $url,
                            'source' => $source['label'],
                            'source_type' => 'custom',
                            'published_at' => date('Y-m-d H:i:s'),
                            'ai_analysis' => $this->analyze_content_for_shock_value($title, $description),
                            'score' => 60
                        ];

                        // Add copyright information if source is not public domain
                        if (isset($source['config']['is_public_domain']) && !$source['config']['is_public_domain']) {
                            $fact['copyright_info'] = $source['config']['copyright_info'] ?? [];
                        }

                        $facts[] = $fact;
                    }
                }
            }
        }

        return $facts;
    }
}
