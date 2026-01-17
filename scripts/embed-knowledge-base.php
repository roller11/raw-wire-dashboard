<?php
/**
 * Raw Wire Knowledge Base Embedding Script
 *
 * Creates semantic embeddings of all Raw Wire documentation for AI-powered
 * search and retrieval of project knowledge.
 *
 * Usage:
 *   wp eval-file scripts/embed-knowledge-base.php
 *   or via CLI: php scripts/embed-knowledge-base.php (requires WP bootstrap)
 *
 * @package RawWire_Dashboard
 * @since 1.0.22
 */

// Increase memory limit for large documents
ini_set( 'memory_limit', '256M' );

// Bootstrap WordPress if not already loaded
if ( ! defined( 'ABSPATH' ) ) {
    // Try to find wp-load.php
    $wp_load_paths = array(
        dirname( __FILE__ ) . '/../../../../wp-load.php',
        '/var/www/html/wp-load.php',
    );
    
    foreach ( $wp_load_paths as $path ) {
        if ( file_exists( $path ) ) {
            require_once $path;
            break;
        }
    }
    
    if ( ! defined( 'ABSPATH' ) ) {
        die( "Could not find WordPress installation.\n" );
    }
}

/**
 * Raw Wire Knowledge Base Embedder
 * 
 * Collects documentation and creates embeddings for semantic search
 */
class RawWire_Knowledge_Embedder {
    
    /**
     * @var RawWire_AI_Adapter
     */
    private $ai;
    
    /**
     * @var string Plugin root directory
     */
    private $plugin_dir;
    
    /**
     * @var array Collected documents
     */
    private $documents = array();
    
    /**
     * @var array Embedded documents with vectors
     */
    private $embeddings = array();
    
    /**
     * Knowledge domains for organization
     */
    const DOMAINS = array(
        'vision'       => 'Vision, Goals & Philosophy',
        'architecture' => 'Technical Architecture',
        'api'          => 'API Reference',
        'workflow'     => 'Workflows & Data Flow',
        'features'     => 'Features & Capabilities',
        'ai'           => 'AI Integration & Tools',
        'setup'        => 'Setup & Configuration',
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_dir = dirname( __DIR__ );
        
        // Initialize AI Adapter
        if ( class_exists( 'RawWire_AI_Adapter' ) ) {
            $this->ai = RawWire_AI_Adapter::get_instance();
        }
    }
    
    /**
     * Run the embedding process
     */
    public function run() {
        error_reporting( E_ALL );
        ini_set( 'display_errors', 1 );
        
        $this->log( 'Raw Wire Knowledge Base Embedding' );
        $this->log( '=' . str_repeat( '=', 50 ) );
        
        // Step 1: Collect documents
        $this->log( '' );
        $this->log( 'Step 1: Collecting documentation...' );
        try {
            $this->collect_documents();
        } catch ( Exception $e ) {
            $this->log( 'ERROR in collect_documents: ' . $e->getMessage() );
            return;
        } catch ( Error $e ) {
            $this->log( 'FATAL ERROR in collect_documents: ' . $e->getMessage() );
            return;
        }
        $this->log( sprintf( '   Found %d documents', count( $this->documents ) ) );
        
        // Step 2: Check AI availability
        $ai_available = false;
        if ( $this->ai && method_exists( $this->ai, 'is_available' ) ) {
            $ai_available = $this->ai->is_available();
        }
        
        // Also check if embedding method exists
        $can_embed = $ai_available && method_exists( $this->ai, 'create_embedding' );
        
        if ( ! $can_embed ) {
            $this->log( '' );
            $this->log( 'AI Engine embeddings not available.' );
            $this->log( 'Reasons: ' . ( $this->ai ? 'AI Adapter loaded' : 'No AI Adapter' ) . 
                       ', ' . ( $ai_available ? 'AI available' : 'AI not configured' ) );
            $this->log( 'Saving document metadata only (can be embedded later).' );
            $this->save_metadata_only();
            return;
        }
        
        // Step 3: Create embeddings
        $this->log( '' );
        $this->log( 'Step 2: Creating embeddings...' );
        $this->create_embeddings();
        
        // Step 4: Save to database
        $this->log( '' );
        $this->log( 'Step 3: Saving to database...' );
        $this->save_embeddings();
        
        // Step 5: Summary
        $this->print_summary();
    }
    
    /**
     * Collect all documentation files
     */
    private function collect_documents() {
        // Core vision documents (highest priority)
        $this->add_document( 'CLAUDE.md', 'vision', 10 );
        $this->add_document( 'README.md', 'vision', 9 );
        
        // Architecture documents
        $this->add_document( 'docs/ARCHITECTURE_PERMANENT_RECORD.md', 'architecture', 8 );
        $this->add_document( 'docs/TEMPLATE_FIRST_ARCHITECTURE.md', 'architecture', 8 );
        $this->add_document( 'docs/SYNC_FLOW_MAP.md', 'workflow', 8 );
        $this->add_document( 'docs/WORKFLOW_SPEC.md', 'workflow', 7 );
        
        // AI-specific documents
        $this->add_document( 'docs/AI_NERVE_CENTER_ANALYSIS.md', 'ai', 9 );
        $this->add_document( 'docs/AI_NERVE_CENTER_IMPLEMENTATION_PLAN.md', 'ai', 8 );
        $this->add_document( 'AI-SETUP-GUIDE.md', 'ai', 7 );
        
        // API documentation
        $docs_api = $this->plugin_dir . '/docs/api';
        if ( is_dir( $docs_api ) ) {
            foreach ( glob( $docs_api . '/*.md' ) as $file ) {
                $this->add_document( 'docs/api/' . basename( $file ), 'api', 6 );
            }
        }
        
        // Core README files
        $this->add_document( 'cores/module-core/README.md', 'architecture', 6 );
        $this->add_document( 'cores/toolbox-core/README.md', 'features', 6 );
        
        // Manual documents
        $docs_manuals = $this->plugin_dir . '/docs/manuals';
        if ( is_dir( $docs_manuals ) ) {
            foreach ( glob( $docs_manuals . '/*.md' ) as $file ) {
                $this->add_document( 'docs/manuals/' . basename( $file ), 'setup', 5 );
            }
        }
        
        // Changelog for feature history
        $this->add_document( 'CHANGELOG.md', 'features', 4 );
        
        // Add inline vision statement
        $this->add_inline_document(
            'raw_wire_vision',
            'vision',
            $this->get_vision_statement(),
            10
        );
        
        // Add architecture summary
        $this->add_inline_document(
            'three_core_architecture',
            'architecture',
            $this->get_architecture_summary(),
            10
        );
        
        // Add AI capabilities
        $this->add_inline_document(
            'ai_capabilities',
            'ai',
            $this->get_ai_capabilities(),
            9
        );
    }
    
    /**
     * Add a file-based document
     */
    private function add_document( $relative_path, $domain, $priority = 5 ) {
        $full_path = $this->plugin_dir . '/' . $relative_path;
        
        if ( ! file_exists( $full_path ) ) {
            $this->log( "   [Not found] {$relative_path}" );
            return;
        }
        
        $content = file_get_contents( $full_path );
        if ( empty( $content ) ) {
            return;
        }
        
        // Clean markdown for embedding
        $content = $this->clean_content( $content );
        
        // Chunk large documents
        $chunks = $this->chunk_content( $content, $relative_path );
        
        foreach ( $chunks as $i => $chunk ) {
            $id = sanitize_title( $relative_path ) . ( count( $chunks ) > 1 ? "-part-{$i}" : '' );
            
            $this->documents[] = array(
                'id'       => $id,
                'domain'   => $domain,
                'title'    => basename( $relative_path ),
                'path'     => $relative_path,
                'content'  => $chunk,
                'priority' => $priority,
                'type'     => 'file',
                'chunk'    => $i,
                'chunks'   => count( $chunks ),
            );
        }
        
        $this->log( sprintf( '   [OK] %s (%d chars, %d chunks)', $relative_path, strlen( $content ), count( $chunks ) ) );
    }
    
    /**
     * Add an inline document (curated content)
     */
    private function add_inline_document( $id, $domain, $content, $priority = 5 ) {
        if ( empty( $content ) ) {
            return;
        }
        
        $this->documents[] = array(
            'id'       => $id,
            'domain'   => $domain,
            'title'    => ucwords( str_replace( '_', ' ', $id ) ),
            'path'     => null,
            'content'  => $content,
            'priority' => $priority,
            'type'     => 'inline',
            'chunk'    => 0,
            'chunks'   => 1,
        );
        
        $this->log( sprintf( '   ✅ [Inline] %s (%d chars)', $id, strlen( $content ) ) );
    }
    
    /**
     * Clean content for embedding
     */
    private function clean_content( $content ) {
        // Remove code blocks but keep descriptions
        $content = preg_replace( '/```[\s\S]*?```/', '[code block]', $content );
        
        // Remove excessive whitespace
        $content = preg_replace( '/\n{3,}/', "\n\n", $content );
        
        // Remove markdown link syntax but keep text
        $content = preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $content );
        
        // Remove HTML tags
        $content = strip_tags( $content );
        
        return trim( $content );
    }
    
    /**
     * Chunk content into embedding-friendly sizes
     * 
     * @param string $content The content to chunk
     * @param string $source Source identifier for context
     * @return array Array of content chunks
     */
    private function chunk_content( $content, $source ) {
        $max_chunk_size = 4000; // Characters per chunk (safe for most embedding models)
        $overlap = 200;         // Overlap between chunks for context continuity
        
        if ( strlen( $content ) <= $max_chunk_size ) {
            return array( $content );
        }
        
        $chunks = array();
        $position = 0;
        $content_length = strlen( $content );
        $safety_counter = 0;
        $max_iterations = ceil( $content_length / ( $max_chunk_size - $overlap ) ) + 10;
        
        while ( $position < $content_length && $safety_counter < $max_iterations ) {
            $safety_counter++;
            
            $chunk = substr( $content, $position, $max_chunk_size );
            $chunk_length = strlen( $chunk );
            
            // Try to break at paragraph boundary
            if ( $chunk_length === $max_chunk_size && $position + $max_chunk_size < $content_length ) {
                $last_para = strrpos( $chunk, "\n\n" );
                if ( $last_para !== false && $last_para > $max_chunk_size * 0.5 ) {
                    $chunk = substr( $chunk, 0, $last_para );
                    $chunk_length = strlen( $chunk );
                }
            }
            
            $chunks[] = $chunk;
            
            // Move position forward - ensure we always advance
            $advance = max( $chunk_length - $overlap, 500 );
            $position += $advance;
        }
        
        return $chunks;
    }
    
    /**
     * Create embeddings for all documents
     */
    private function create_embeddings() {
        $total = count( $this->documents );
        $success = 0;
        $failed = 0;
        
        foreach ( $this->documents as $i => $doc ) {
            $this->log( sprintf( '   [%d/%d] Embedding: %s...', $i + 1, $total, $doc['id'] ) );
            
            try {
                $result = $this->ai->create_embedding( $doc['content'], array(
                    'model' => 'text-embedding-3-small', // OpenAI default
                ) );
                
                // Check for WP_Error
                if ( is_wp_error( $result ) ) {
                    $this->log( '      [FAIL] ' . $result->get_error_message() );
                    $failed++;
                    continue;
                }
                
                if ( ! empty( $result['vector'] ) ) {
                    $doc['vector'] = $result['vector'];
                    $doc['model'] = $result['model'] ?? 'unknown';
                    $doc['dimensions'] = $result['dimensions'] ?? count( $result['vector'] );
                    $this->embeddings[] = $doc;
                    $success++;
                } else {
                    $this->log( '      [FAIL] No vector returned' );
                    $failed++;
                }
            } catch ( Exception $e ) {
                $this->log( '      [FAIL] ' . $e->getMessage() );
                $failed++;
            } catch ( Error $e ) {
                $this->log( '      [FATAL] ' . $e->getMessage() );
                $failed++;
            }
            
            // Rate limiting - be nice to the API
            usleep( 100000 ); // 100ms between requests
        }
        
        $this->log( '' );
        $this->log( sprintf( '   Success: %d | Failed: %d', $success, $failed ) );
        
        // If all failed, save metadata anyway
        if ( $success === 0 && $failed > 0 ) {
            $this->log( '' );
            $this->log( '   All embeddings failed - saving metadata for fallback search.' );
            $this->save_metadata_only();
        }
    }
    
    /**
     * Save embeddings to database
     */
    private function save_embeddings() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_knowledge_base';
        
        // Create table if not exists
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            doc_id varchar(255) NOT NULL,
            domain varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            path varchar(500) DEFAULT NULL,
            content longtext NOT NULL,
            vector longtext NOT NULL,
            model varchar(100) NOT NULL,
            dimensions int(11) NOT NULL,
            priority tinyint(4) NOT NULL DEFAULT 5,
            chunk_index tinyint(4) NOT NULL DEFAULT 0,
            total_chunks tinyint(4) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY doc_id (doc_id),
            KEY domain (domain),
            KEY priority (priority)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        // Clear existing entries
        $wpdb->query( "TRUNCATE TABLE {$table_name}" );
        
        // Insert new embeddings
        $inserted = 0;
        foreach ( $this->embeddings as $doc ) {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'doc_id'       => $doc['id'],
                    'domain'       => $doc['domain'],
                    'title'        => $doc['title'],
                    'path'         => $doc['path'],
                    'content'      => $doc['content'],
                    'vector'       => wp_json_encode( $doc['vector'] ),
                    'model'        => $doc['model'],
                    'dimensions'   => $doc['dimensions'],
                    'priority'     => $doc['priority'],
                    'chunk_index'  => $doc['chunk'],
                    'total_chunks' => $doc['chunks'],
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
            );
            
            if ( $result ) {
                $inserted++;
            }
        }
        
        $this->log( sprintf( '   ✅ Inserted %d embeddings into %s', $inserted, $table_name ) );
        
        // Save metadata
        update_option( 'rawwire_knowledge_base_meta', array(
            'last_updated'   => current_time( 'mysql' ),
            'document_count' => count( $this->embeddings ),
            'domains'        => array_unique( array_column( $this->embeddings, 'domain' ) ),
            'model'          => $this->embeddings[0]['model'] ?? 'unknown',
            'dimensions'     => $this->embeddings[0]['dimensions'] ?? 0,
        ) );
    }
    
    /**
     * Save metadata only (when AI not available)
     */
    private function save_metadata_only() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rawwire_knowledge_docs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            doc_id varchar(255) NOT NULL,
            domain varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            path varchar(500) DEFAULT NULL,
            content longtext NOT NULL,
            priority tinyint(4) NOT NULL DEFAULT 5,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY doc_id (doc_id)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        $wpdb->query( "TRUNCATE TABLE {$table_name}" );
        
        foreach ( $this->documents as $doc ) {
            $wpdb->insert(
                $table_name,
                array(
                    'doc_id'   => $doc['id'],
                    'domain'   => $doc['domain'],
                    'title'    => $doc['title'],
                    'path'     => $doc['path'],
                    'content'  => $doc['content'],
                    'priority' => $doc['priority'],
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%d' )
            );
        }
        
        $this->log( sprintf( '   ✅ Saved %d documents to %s', count( $this->documents ), $table_name ) );
        $this->log( '   ℹ️  Run again after configuring AI Engine for embeddings.' );
    }
    
    /**
     * Print summary
     */
    private function print_summary() {
        $this->log( "\n" . str_repeat( '=', 52 ) );
        $this->log( '📊 KNOWLEDGE BASE SUMMARY' );
        $this->log( str_repeat( '=', 52 ) );
        
        // Count by domain
        $by_domain = array();
        foreach ( $this->embeddings as $doc ) {
            $domain = $doc['domain'];
            if ( ! isset( $by_domain[ $domain ] ) ) {
                $by_domain[ $domain ] = 0;
            }
            $by_domain[ $domain ]++;
        }
        
        $this->log( "\n📁 Documents by Domain:" );
        foreach ( self::DOMAINS as $key => $label ) {
            $count = $by_domain[ $key ] ?? 0;
            $this->log( sprintf( '   %-20s %d', $label, $count ) );
        }
        
        $this->log( sprintf( "\n📈 Total Embeddings: %d", count( $this->embeddings ) ) );
        
        if ( ! empty( $this->embeddings ) ) {
            $this->log( sprintf( '🧠 Embedding Model: %s', $this->embeddings[0]['model'] ?? 'unknown' ) );
            $this->log( sprintf( '📐 Vector Dimensions: %d', $this->embeddings[0]['dimensions'] ?? 0 ) );
        }
        
        $this->log( "\n✅ Knowledge base ready for semantic search!" );
        $this->log( "\nUsage:" );
        $this->log( '   $adapter = RawWire_AI_Adapter::get_instance();' );
        $this->log( '   $results = $adapter->search_knowledge_base("your query");' );
    }
    
    /**
     * Get the vision statement
     */
    private function get_vision_statement() {
        return <<<'VISION'
# Raw Wire Vision Statement

Raw Wire is a template-driven WordPress platform designed to democratize AI-powered content discovery and publishing for small businesses and independent creators.

## Core Mission
To build a news aggregation and content creation system that:
1. Discovers shocking, unusual, and high-impact information from government sources
2. Uses AI to intelligently filter and score content beyond simple keywords
3. Automates the content pipeline while maintaining human oversight
4. Provides affordable AI capabilities to small business customers

## The "AI Nerve Center" Concept
Raw Wire aims to be the brain of content operations, automatically:
- Scraping government documents, regulations, and public records
- Identifying content that is controversial, precedent-setting, or has hidden agendas
- Scoring content for shock value, virality potential, and audience relevance
- Generating summaries, rewrites, and social media posts
- Publishing to WordPress and social platforms

## Three-Core Architecture
The system is built on three interconnected cores:
1. **Dashboard Core** - Foundation handling auth, routing, and WordPress integration
2. **Module Core** - Human interface for templates, panels, and user interactions
3. **Toolkit Core** - External functionality including AI, scrapers, and workflows

## Template-First Philosophy
All business logic lives in JSON templates, not in code. This allows:
- Non-developers to customize behavior
- Rapid deployment of new features
- Clean separation between infrastructure and business rules
- AI assistants to suggest correct patterns

## Target Audience
- Small businesses needing content automation
- Independent journalists and researchers
- Government accountability organizations
- Anyone who wants AI-powered content curation without enterprise costs

Raw Wire proves that powerful AI tooling doesn't require massive budgets - it requires smart architecture and focused development.
VISION;
    }
    
    /**
     * Get architecture summary
     */
    private function get_architecture_summary() {
        return <<<'ARCH'
# Raw Wire Three-Core Architecture

## Dashboard Core (Foundation Layer)
Location: `raw-wire-dashboard.php`, `includes/`, `rest-api.php`, `services/`

Responsibilities:
- WordPress plugin bootstrap and lifecycle
- User authentication and capability checks
- REST API endpoint registration
- Database table management (6 workflow tables)
- Logging and error handling
- Cron and background job scheduling
- Service layer orchestration

Key Services:
- `class-scraper-service.php` - Government source scraping
- `class-scoring-handler.php` - AI-powered content scoring
- `class-migration-service.php` - Database schema management
- `class-sync-service.php` - Workflow orchestration

## Module Core (Human Interface Layer)
Location: `cores/module-core/`

Responsibilities:
- UI rendering and template mounting
- Panel and page management
- User interaction handling
- Fallback content when templates not configured

Key Rule: Modules are FALLBACKS ONLY. No business logic, no database queries, no embedded scripts.

## Toolkit Core (External Functionality Layer)
Location: `cores/toolbox-core/`

Responsibilities:
- AI Engine integration via AI Adapter
- Scraper adapters for different sources
- Generator adapters for content creation
- MCP Server for AI tool-calls
- Tool Registry for dynamic tool management
- Workflow handling for complex operations

Key Components:
- `class-ai-adapter.php` - Unified AI interface
- `class-mcp-server.php` - Model Context Protocol server
- `class-tool-registry.php` - Dynamic tool registration
- `adapters/scrapers/` - Source-specific scrapers
- `adapters/generators/` - Content generators

## Template Engine
Location: `cores/template-engine/`

Responsibilities:
- JSON template parsing and validation
- Page and panel rendering from configuration
- DataSource resolution (db:tablename:conditions)
- Action button handler registration
- Workflow trigger binding

## Data Flow
```
Candidates → Approvals → Content → Releases → Published
    ↓
 Archives (rejected items)
```

All tables prefixed: wp_rawwire_candidates, wp_rawwire_approvals, etc.
ARCH;
    }
    
    /**
     * Get AI capabilities
     */
    private function get_ai_capabilities() {
        return <<<'AI'
# Raw Wire AI Capabilities

## AI Adapter (Unified Interface)
The `RawWire_AI_Adapter` class provides a single interface to all AI functionality:

### Text Generation
- `simple_query($prompt, $params)` - Basic text completion
- `structured_query($prompt, $schema, $params)` - JSON-structured responses
- `chat($bot_id, $message, $params)` - Conversational with memory

### Environment Selection
- `use_groq($model)` - Switch to Groq (free Llama)
- `use_fast()` - Use fastest available model
- `use_quality()` - Use highest quality model
- `build_options($params)` - Merge user params with defaults

### Embeddings
- `create_embedding($text)` - Single text to vector
- `create_embeddings_batch($texts)` - Multiple texts
- `calculate_similarity($text1, $text2)` - Compare texts
- `semantic_search($query, $corpus)` - Find similar content

### Content Analysis
- `analyze_content($content, $criteria)` - Multi-criteria analysis
- `moderation_check($content)` - Content safety check

## AI Scraper (Semantic Discovery)
The `RawWire_Scraper_AI` class enables intelligent content discovery:

### Abstract Concepts
Instead of keyword matching, evaluates content for:
- Shocking/Surprising (unexpected revelations)
- Controversial (policy disagreements)
- Unusual Patterns (statistical anomalies)
- High Impact (large populations affected)
- Hidden Agenda (buried provisions)
- Urgency (fast-tracking indicators)
- Precedent (new legal territory)
- Policy Reversal (changed positions)
- Financial Impact (large dollar amounts)
- Environmental Impact (ecosystem effects)

### Sources
- Federal Register (regulations)
- Regulations.gov (rule comments)
- Congress.gov (legislation)
- Custom API endpoints

## MCP Server (Tool Calling)
The `RawWire_MCP_Server` enables AI to call tools:
- Registered via Tool Registry
- Exposed to AI Engine as functions
- Enables natural language operation

## Groq Integration
Free Llama access via Groq API:
- `llama-3.3-70b-versatile` - Quality (200-840 TPS)
- `llama-3.1-8b-instant` - Speed (750+ TPS)
- Registered as native AI Engine provider
AI;
    }
    
    /**
     * Log message
     */
    private function log( $message ) {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::log( $message );
        } else {
            echo $message . "\n";
        }
    }
}

// Run the embedder
$embedder = new RawWire_Knowledge_Embedder();
$embedder->run();
