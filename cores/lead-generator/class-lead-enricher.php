<?php

/**
 * RawWire Lead Enricher — Deep Research Agent
 *
 * For approved leads: investigates the project further by researching
 * the filing party, contractor, location, and project details.
 * Uses AI to compile a comprehensive lead file.
 *
 * Also optionally uses TextRazor NLP API for entity extraction.
 *
 * @package RawWire_Dashboard
 * @since   1.0.27
 */

if (! defined('ABSPATH')) {
    exit;
}

class RawWire_Lead_Enricher
{

    /** @var RawWire_Lead_Generator */
    private $generator;

    /**
     * @param RawWire_Lead_Generator $generator
     */
    public function __construct($generator)
    {
        $this->generator = $generator;

        // Hook for async enrichment
        add_action('rawwire_lead_enrich_single', array($this, 'enrich'));
    }

    /**
     * Enrich a single content record with deep research
     *
     * @param int $content_id
     * @return array|WP_Error
     */
    public function enrich($content_id)
    {
        global $wpdb;
        $table = $this->generator->table('content');

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $content_id
        ), ARRAY_A);

        if (! $lead) {
            return new WP_Error('not_found', 'Lead record not found.');
        }

        // Get source record for full details
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->generator->table('sources'),
            $lead['source_id']
        ), ARRAY_A);

        // Get candidate record for score details
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->generator->table('candidates'),
            $lead['candidate_id']
        ), ARRAY_A);

        // Compile all available data
        $context = $this->build_context($lead, $source, $candidate);

        // Run AI research agent
        $research = $this->ai_research($context);

        // Run TextRazor NLP if available
        $entities = $this->textrazor_analyze($context);

        // Merge results
        $enriched = array(
            'project_name'     => ! empty($research['project_name']) ? $research['project_name'] : $this->generate_project_name($lead),
            'project_summary'  => ! empty($research['project_summary']) ? $research['project_summary'] : '',
            'filing_party_info' => wp_json_encode($this->merge_party_info($research, $entities, 'filing_party')),
            'contractor_info'  => wp_json_encode($this->merge_party_info($research, $entities, 'contractor')),
            'architect_info'   => wp_json_encode(isset($research['architect']) ? $research['architect'] : array()),
            'owner_info'       => wp_json_encode(isset($research['owner']) ? $research['owner'] : array()),
            'location_analysis' => ! empty($research['location_analysis']) ? $research['location_analysis'] : '',
            'market_context'   => ! empty($research['market_context']) ? $research['market_context'] : '',
            'green_analysis'   => ! empty($research['green_analysis']) ? $research['green_analysis'] : '',
            'opportunity_notes' => ! empty($research['opportunity_notes']) ? $research['opportunity_notes'] : '',
            'research_sources' => wp_json_encode(isset($research['sources']) ? $research['sources'] : array()),
            'status'           => 'ready',
            'enriched_at'      => current_time('mysql'),
            'status_changed_at' => current_time('mysql'),
        );

        // Add entity tags
        if (! empty($entities['tags'])) {
            $existing_tags = $lead['tags'] ? explode(',', $lead['tags']) : array();
            $enriched['tags'] = implode(',', array_unique(array_merge($existing_tags, $entities['tags'])));
        }

        $wpdb->update($table, $enriched, array('id' => $content_id));

        rawwire_log('lead_enricher', sprintf(
            'Enriched lead #%d (%s) — status: ready',
            $content_id,
            $lead['permit_nbr']
        ));

        return array(
            'success'     => true,
            'content_id'  => $content_id,
            'project_name' => $enriched['project_name'],
            'status'      => 'ready',
        );
    }

    /**
     * Build comprehensive context for AI research
     */
    private function build_context($lead, $source, $candidate)
    {
        $context = array(
            'permit_nbr'      => $lead['permit_nbr'],
            'address'         => $lead['primary_address'],
            'zip_code'        => $lead['zip_code'],
            'permit_type'     => $lead['permit_type'],
            'permit_sub_type' => $lead['permit_sub_type'],
            'use_desc'        => $lead['use_desc'],
            'work_desc'       => $lead['work_desc'],
            'valuation'       => $lead['valuation'],
            'square_footage'  => $lead['square_footage'],
            'issue_date'      => $lead['issue_date'],
            'score_composite' => $lead['score_composite'],
        );

        // Add source details
        if ($source) {
            $context['contractor_name']    = $source['contractor_name'];
            $context['contractor_license'] = $source['contractor_license'];
            $context['applicant_name']     = $source['applicant_name'];
            $context['applicant_business'] = $source['applicant_business'];
            $context['zone']               = $source['zone'];
            $context['community_plan']     = $source['community_plan_area'];
            $context['neighborhood']       = $source['neighborhood_council'];
            $context['construction_type']  = $source['construction_type'];
            $context['stories']            = $source['stories'];
            $context['height']             = $source['height'];
            $context['solar']              = $source['solar'];
            $context['ev']                 = $source['ev'];
            $context['status_desc']        = $source['status_desc'];
        }

        // Add candidate score breakdown
        if ($candidate) {
            $context['scores'] = array(
                'project_scale'    => $candidate['score_project_scale'],
                'type_fit'         => $candidate['score_type_fit'],
                'green_indicators' => $candidate['score_green_indicators'],
                'timeline'         => $candidate['score_timeline'],
                'location'         => $candidate['score_location'],
                'use_relevance'    => $candidate['score_use_relevance'],
                'complexity'       => $candidate['score_complexity'],
            );
            $context['score_reasoning'] = $candidate['score_reasoning'];
        }

        // Lat/Lon for location context
        if ($lead['lat'] && $lead['lon']) {
            $context['lat'] = $lead['lat'];
            $context['lon'] = $lead['lon'];
        }

        return $context;
    }

    /**
     * AI Research Agent — comprehensive project investigation
     */
    private function ai_research($context)
    {
        if (! class_exists('RawWire_AI_Adapter')) {
            return $this->fallback_research($context);
        }

        try {
            $ai = RawWire_AI_Adapter::get_instance();
        } catch (\Exception $e) {
            return $this->fallback_research($context);
        }

        $prompt = $this->build_research_prompt($context);

        $result = $ai->json_query($prompt, array(
            'temperature' => 0.4,
            'maxTokens'   => 2000,
        ));

        if (is_wp_error($result)) {
            rawwire_log('lead_enricher', 'AI research failed: ' . $result->get_error_message(), 'warning');
            return $this->fallback_research($context);
        }

        return is_array($result) ? $result : array();
    }

    /**
     * Build the research prompt for AI
     */
    private function build_research_prompt($context)
    {
        $val = number_format(floatval($context['valuation']));
        $scoring_info = '';
        if (! empty($context['scores'])) {
            $scoring_info = "\n\nSCORING BREAKDOWN:\n";
            foreach ($context['scores'] as $class => $score) {
                $scoring_info .= sprintf("- %s: %s/10\n", ucwords(str_replace('_', ' ', $class)), $score);
            }
        }

        return <<<PROMPT
You are a research agent for an interior design architecture firm specializing in green, sustainable interior structural design in Los Angeles. Your client approaches building owners and developers early in the construction/renovation process to offer sustainable interior architecture services.

RESEARCH THIS BUILDING PROJECT AND COMPILE A COMPREHENSIVE LEAD FILE:

PERMIT DATA:
- Permit #: {$context['permit_nbr']}
- Address: {$context['address']}, {$context['zip_code']}
- Type: {$context['permit_type']} ({$context['permit_sub_type']})
- Use: {$context['use_desc']}
- Valuation: \${$val}
- Square Footage: {$context['square_footage']}
- Work Description: {$context['work_desc']}
- Issued: {$context['issue_date']}
- Contractor: {$context['contractor_name']} (License: {$context['contractor_license']})
- Applicant: {$context['applicant_name']} / {$context['applicant_business']}
- Zone: {$context['zone']}
- Community Plan: {$context['community_plan']}
- Construction Type: {$context['construction_type']}
- Solar: {$context['solar']} | EV: {$context['ev']}
{$scoring_info}

RETURN a JSON object with these fields:
{
  "project_name": "A descriptive project name (e.g., 'Downtown LA Mixed-Use Tower - 1234 Main St')",
  "project_summary": "2-3 paragraph summary of the project, its significance, and why it's a good lead for sustainable interior design architecture services",
  "filing_party": {
    "name": "Name of the filing party/developer",
    "company": "Company name if identifiable",
    "role": "Developer/Owner/Architect",
    "contact_notes": "Any publicly available contact information or how to find them"
  },
  "contractor": {
    "name": "General contractor name",
    "company": "Company name",
    "license": "License number",
    "specialties": "Known specialties or notable projects"
  },
  "architect": {
    "name": "Project architect if identifiable from the permit or work description",
    "firm": "Architecture firm",
    "contact_notes": "How to find them"
  },
  "owner": {
    "name": "Property owner if identifiable",
    "company": "Ownership entity",
    "contact_notes": "How to find them"
  },
  "location_analysis": "Analysis of the neighborhood, nearby developments, area demographics, commercial activity, transit access, and why this location matters for sustainable design",
  "market_context": "Current market trends for this type of project in this area, similar recent projects, competitive landscape for interior design services",
  "green_analysis": "Sustainability assessment: CalGreen/Title 24 requirements applicable, LEED potential, green building trends in the area, specific sustainable design opportunities for this project",
  "opportunity_notes": "Specific talking points and approach strategy for reaching out to the decision-makers. What services would be most valuable? What's the ideal timing? Suggested outreach approach.",
  "sources": ["List of data sources and references used for this analysis"]
}

Be specific, factual, and actionable. Focus on information that would help the client win this project.
PROMPT;
    }

    /**
     * Fallback research when AI is unavailable
     */
    private function fallback_research($context)
    {
        $name = $this->generate_project_name($context);

        return array(
            'project_name'    => $name,
            'project_summary' => sprintf(
                '%s project at %s, %s. %s — %s with estimated valuation of $%s. %s',
                $context['permit_type'],
                $context['address'],
                $context['zip_code'],
                $context['permit_sub_type'],
                $context['use_desc'],
                number_format(floatval($context['valuation'])),
                $context['work_desc']
            ),
            'filing_party'    => array(
                'name'    => $context['applicant_name'] ?: 'Unknown',
                'company' => $context['applicant_business'] ?: 'Unknown',
            ),
            'contractor'      => array(
                'name'    => $context['contractor_name'] ?: 'Unknown',
                'license' => $context['contractor_license'] ?: 'Unknown',
            ),
            'location_analysis' => sprintf(
                'Located in %s zone, %s community plan area.',
                $context['zone'] ?: 'unknown',
                $context['community_plan'] ?: 'unknown'
            ),
            'green_analysis' => $this->basic_green_analysis($context),
            'opportunity_notes' => 'AI enrichment unavailable. Manual research recommended.',
            'sources' => array('LADBS Building Permit Records', 'LA City Open Data Portal'),
        );
    }

    /**
     * Basic green analysis without AI
     */
    private function basic_green_analysis($context)
    {
        $notes = array();
        $notes[] = 'All new construction and major renovations in LA must comply with CalGreen (California Green Building Standards Code) and Title 24 energy efficiency requirements.';

        if (strtolower($context['solar'] ?? '') === 'y') {
            $notes[] = 'Project includes solar installation — indicates commitment to renewable energy.';
        }
        if (strtolower($context['ev'] ?? '') === 'y') {
            $notes[] = 'Project includes EV charging infrastructure — forward-thinking sustainability approach.';
        }

        $val = floatval($context['valuation']);
        if ($val >= 1000000) {
            $notes[] = sprintf(
                'High-value project ($%s) — significant budget likely allocates for quality interior architectural services.',
                number_format($val)
            );
        }

        if (strpos(strtolower($context['permit_type'] ?? ''), 'new') !== false) {
            $notes[] = 'New construction offers the best opportunity for integrated sustainable design from the ground up.';
        }

        return implode("\n", $notes);
    }

    /**
     * TextRazor NLP Analysis for entity extraction
     */
    private function textrazor_analyze($context)
    {
        $settings = $this->generator->get_settings();
        $api_key  = $settings['textrazor_api_key'];

        if (empty($api_key)) {
            return array('tags' => array());
        }

        // Combine text fields for analysis
        $text = implode('. ', array_filter(array(
            $context['work_desc'],
            $context['use_desc'],
            $context['contractor_name'] ? 'Contractor: ' . $context['contractor_name'] : '',
            $context['applicant_business'] ? 'Applicant: ' . $context['applicant_business'] : '',
        )));

        if (strlen($text) < 20) {
            return array('tags' => array());
        }

        $response = wp_remote_post('https://api.textrazor.com/', array(
            'headers' => array(
                'X-TextRazor-Key' => $api_key,
                'Content-Type'    => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'text'       => $text,
                'extractors' => 'entities,topics',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            rawwire_log('lead_enricher', 'TextRazor failed: ' . $response->get_error_message(), 'warning');
            return array('tags' => array());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! $body || ! isset($body['response'])) {
            return array('tags' => array());
        }

        $tags = array();

        // Extract entity types as tags
        if (! empty($body['response']['entities'])) {
            foreach ($body['response']['entities'] as $entity) {
                if (isset($entity['confidenceScore']) && $entity['confidenceScore'] > 0.5) {
                    $tags[] = sanitize_title($entity['entityId']);
                }
            }
        }

        // Extract topics as tags
        if (! empty($body['response']['topics'])) {
            foreach ($body['response']['topics'] as $topic) {
                if (isset($topic['score']) && $topic['score'] > 0.5) {
                    $tags[] = sanitize_title($topic['label']);
                }
            }
        }

        return array(
            'tags'     => array_unique(array_slice($tags, 0, 15)),
            'entities' => isset($body['response']['entities']) ? $body['response']['entities'] : array(),
            'topics'   => isset($body['response']['topics']) ? $body['response']['topics'] : array(),
        );
    }

    /**
     * Generate a project name from context
     */
    private function generate_project_name($context)
    {
        $parts = array();

        // Location
        $addr = is_array($context) ? ($context['primary_address'] ?? $context['address'] ?? '') : '';
        if ($addr) {
            // Simplify address
            $parts[] = preg_replace('/^(\d+\s+\S+\s+\S+).*/', '$1', $addr);
        }

        // Type
        $use = is_array($context) ? ($context['use_desc'] ?? '') : '';
        if ($use) {
            $parts[] = $use;
        }

        $type = is_array($context) ? ($context['permit_type'] ?? '') : '';
        if (strpos(strtolower($type), 'new') !== false) {
            $parts[] = 'New Construction';
        } elseif (strpos(strtolower($type), 'addition') !== false) {
            $parts[] = 'Addition';
        } elseif (strpos(strtolower($type), 'alter') !== false) {
            $parts[] = 'Renovation';
        }

        return implode(' — ', $parts) ?: 'Unnamed Project';
    }

    /**
     * Merge AI research and NLP entity data for a party
     */
    private function merge_party_info($research, $entities, $party_type)
    {
        $info = isset($research[$party_type]) ? $research[$party_type] : array();

        // Ensure standard fields exist
        $defaults = array(
            'name'          => '',
            'company'       => '',
            'role'          => '',
            'contact_notes' => '',
            'license'       => '',
            'specialties'   => '',
        );

        return wp_parse_args($info, $defaults);
    }

    /**
     * Batch enrich all unenriched content records
     *
     * @param int $limit Max records to process
     * @return array Results
     */
    public function enrich_batch($limit = 5)
    {
        global $wpdb;
        $table = $this->generator->table('content');

        $pending = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = 'enriching' ORDER BY score_composite DESC LIMIT %d",
            $limit
        ));

        $results = array('enriched' => 0, 'failed' => 0);

        foreach ($pending as $id) {
            $r = $this->enrich($id);
            if (is_wp_error($r)) {
                $results['failed']++;
            } else {
                $results['enriched']++;
            }
        }

        return $results;
    }
}
