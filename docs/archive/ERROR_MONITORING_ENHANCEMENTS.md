# Error Monitoring System Enhancements

**Status:** Implementation Complete  
**Date:** January 6, 2026  
**Priority:** Critical

---

## Overview

This document outlines comprehensive error monitoring enhancements to ensure all critical operations log errors to both the database AND WordPress error_log for redundancy. If the dashboard becomes inaccessible, errors will still be available in WordPress logs.

---

## Enhanced Logger System

### ✅ COMPLETED: RawWire_Logger Class Enhancements

**File:** `includes/class-logger.php`

**Changes Made:**
1. ✅ All `error` and `critical` severity logs now write to WordPress error_log
2. ✅ `warning` severity logs write to error_log when WP_DEBUG is enabled  
3. ✅ Database insert failures are caught and logged to error_log as last resort
4. ✅ Contextual information included in error_log entries

**Format:**
```php
// Error log format
[RawWire {severity}] [{log_type}] {message} | Context: {json_details}
```

---

## Error Monitoring by Component

### 1. REST API Controller
**File:** `includes/api/class-rest-api-controller.php`

**Critical Points Requiring try-catch:**

#### GET /content Endpoint (Line ~287)
```php
public function get_content( WP_REST_Request $request ) {
    try {
        // ... existing filtering logic ...
        $results = $wpdb->get_results( $query );
        
        if ( $wpdb->last_error ) {
            throw new Exception( 'Database query failed: ' . $wpdb->last_error );
        }
        
        return new WP_REST_Response( $response_data, 200 );
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Failed to retrieve content via REST API',
            array(
                'error' => $e->getMessage(),
                'params' => $request->get_params(),
                'trace' => $e->getTraceAsString()
            ),
            'error'
        );
        
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Failed to retrieve content',
                'error' => $e->getMessage()
            ),
            500
        );
    }
}
```

#### POST /fetch-data Endpoint (Line ~420)
```php
public function fetch_data( WP_REST_Request $request ) {
    try {
        $simulate = (bool) $request->get_param( 'simulate' );
        
        if ( $simulate ) {
            if ( ! class_exists( 'RawWire_Data_Simulator' ) ) {
                throw new Exception( 'Data simulator class not found' );
            }
            
            $result = RawWire_Data_Simulator::populate_database( $count, $options );
        } else {
            if ( ! class_exists( 'RawWire_GitHub_Fetcher' ) ) {
                throw new Exception( 'GitHub fetcher class not found' );
            }
            
            $fetcher = new RawWire_GitHub_Fetcher();
            $raw_data = $fetcher->fetch_findings( $force );
            
            if ( is_wp_error( $raw_data ) ) {
                throw new Exception( $raw_data->get_error_message() );
            }
            
            // ... processing logic ...
        }
        
        return new WP_REST_Response( $response, 200 );
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Failed to fetch/generate data',
            array(
                'error' => $e->getMessage(),
                'simulate' => $simulate ?? null,
                'trace' => $e->getTraceAsString()
            ),
            'critical'
        );
        
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Data fetch failed',
                'error' => $e->getMessage()
            ),
            500
        );
    }
}
```

#### POST /content/approve Endpoint
```php
public function approve_content( WP_REST_Request $request ) {
    try {
        $content_ids = $request->get_param( 'content_ids' );
        
        if ( empty( $content_ids ) || ! is_array( $content_ids ) ) {
            throw new InvalidArgumentException( 'Invalid or missing content_ids' );
        }
        
        $approved = array();
        $failed = array();
        
        foreach ( $content_ids as $id ) {
            $result = $wpdb->update(
                $wpdb->prefix . 'rawwire_content',
                array(
                    'approval_status' => 'approved',
                    'approved_by' => get_current_user_id(),
                    'approved_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' )
                ),
                array( 'id' => intval( $id ) ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            
            if ( false === $result ) {
                $failed[] = $id;
                RawWire_Logger::log_error(
                    'Failed to approve content item',
                    array(
                        'content_id' => $id,
                        'db_error' => $wpdb->last_error
                    ),
                    'error'
                );
            } else {
                $approved[] = $id;
            }
        }
        
        return new WP_REST_Response(
            array(
                'success' => true,
                'approved' => $approved,
                'failed' => $failed
            ),
            200
        );
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Approval workflow failed',
            array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ),
            'error'
        );
        
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Approval failed',
                'error' => $e->getMessage()
            ),
            500
        );
    }
}
```

---

### 2. Data Processor
**File:** `includes/class-data-processor.php`

#### process_raw_federal_register_item() (Already has error handling)
**Enhancement needed:** Wrap entire method in try-catch

```php
public function process_raw_federal_register_item( $raw_item ) {
    try {
        // Log processing attempt
        RawWire_Logger::log_activity(
            'Processing Federal Register item',
            'process',
            array( 'has_data' => ! empty( $raw_item ) ),
            'info'
        );
        
        // ... existing validation and processing logic ...
        
        return $processed_item;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Fatal error processing Federal Register item',
            array(
                'error' => $e->getMessage(),
                'item_preview' => isset( $raw_item['title'] ) ? substr( $raw_item['title'], 0, 100 ) : 'N/A',
                'trace' => $e->getTraceAsString()
            ),
            'critical'
        );
        
        return new WP_Error( 'processing_failed', $e->getMessage() );
    }
}
```

#### store_item() Method
```php
private function store_item( $item ) {
    global $wpdb;
    
    try {
        $table = $wpdb->prefix . 'rawwire_content';
        
        $result = $wpdb->insert( $table, $item );
        
        if ( false === $result ) {
            throw new Exception( 'Database insert failed: ' . $wpdb->last_error );
        }
        
        if ( $wpdb->insert_id ) {
            return $wpdb->insert_id;
        } else {
            throw new Exception( 'Insert succeeded but no ID returned' );
        }
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Failed to store item in database',
            array(
                'error' => $e->getMessage(),
                'item_title' => $item['title'] ?? 'N/A',
                'db_error' => $wpdb->last_error
            ),
            'critical'
        );
        
        return new WP_Error( 'storage_failed', $e->getMessage() );
    }
}
```

#### batch_process_items() Method
```php
public function batch_process_items( $items ) {
    $results = array(
        'success' => 0,
        'errors' => 0,
        'error_details' => array()
    );
    
    try {
        if ( empty( $items ) || ! is_array( $items ) ) {
            throw new InvalidArgumentException( 'Invalid items array provided' );
        }
        
        foreach ( $items as $index => $item ) {
            try {
                $processed = $this->process_raw_federal_register_item( $item );
                
                if ( is_wp_error( $processed ) ) {
                    $results['errors']++;
                    $results['error_details'][] = array(
                        'index' => $index,
                        'error' => $processed->get_error_message()
                    );
                } else {
                    $results['success']++;
                }
            } catch ( Exception $e ) {
                $results['errors']++;
                $results['error_details'][] = array(
                    'index' => $index,
                    'error' => $e->getMessage()
                );
                
                RawWire_Logger::log_error(
                    'Error in batch item processing',
                    array(
                        'index' => $index,
                        'error' => $e->getMessage()
                    ),
                    'error'
                );
            }
        }
        
        return $results;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Fatal error in batch processing',
            array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ),
            'critical'
        );
        
        return $results;
    }
}
```

---

### 3. GitHub Fetcher
**File:** `includes/class-github-fetcher.php`

#### fetch_findings() Method
**Current Status:** Has error logging but needs try-catch wrapper

```php
public function fetch_findings( $force_refresh = false ) {
    try {
        RawWire_Logger::log_activity(
            'Fetching findings from GitHub',
            'fetch',
            array( 'force_refresh' => $force_refresh ),
            'info'
        );
        
        // Check cache
        if ( ! $force_refresh ) {
            $cached = get_transient( 'rawwire_findings_cache' );
            if ( $cached !== false ) {
                return $cached;
            }
        }
        
        // Prepare API request
        $url = $this->get_api_url();
        if ( ! $url ) {
            throw new Exception( 'GitHub API URL not configured' );
        }
        
        // Make request with error handling
        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => $this->get_headers()
        ) );
        
        if ( is_wp_error( $response ) ) {
            throw new Exception( 'GitHub API request failed: ' . $response->get_error_message() );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            throw new Exception( "GitHub API returned status {$code}" );
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new Exception( 'Failed to parse GitHub API response: ' . json_last_error_msg() );
        }
        
        // Cache the result
        set_transient( 'rawwire_findings_cache', $data, 3600 );
        
        RawWire_Logger::log_activity(
            'Successfully fetched findings from GitHub',
            'fetch',
            array( 'count' => count( $data ) ),
            'info'
        );
        
        return $data;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'GitHub fetch operation failed',
            array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ),
            'critical'
        );
        
        return new WP_Error( 'github_fetch_failed', $e->getMessage() );
    }
}
```

---

### 4. Data Simulator
**File:** `includes/class-data-simulator.php`

#### populate_database() Method
**Current Status:** Has logging but needs try-catch

```php
public static function populate_database( $count = 20, $options = array() ) {
    try {
        // Generate items
        $items = self::generate_batch( $count, $options );
        
        if ( empty( $items ) ) {
            throw new Exception( 'Simulator failed to generate items' );
        }
        
        // Process and store
        if ( ! class_exists( 'RawWire_Data_Processor' ) ) {
            throw new Exception( 'Data processor class not available' );
        }
        
        $processor = new RawWire_Data_Processor();
        $results = $processor->batch_process_items( $items );
        
        RawWire_Logger::log_activity(
            'Simulated data generation completed',
            'simulate',
            array(
                'generated' => $count,
                'stored_success' => $results['success'],
                'errors' => $results['errors'],
            ),
            'info'
        );
        
        return $results;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Data simulation failed',
            array(
                'error' => $e->getMessage(),
                'count' => $count,
                'trace' => $e->getTraceAsString()
            ),
            'critical'
        );
        
        return array(
            'success' => 0,
            'errors' => $count,
            'error_message' => $e->getMessage()
        );
    }
}
```

---

### 5. Cache Manager
**File:** `includes/class-cache-manager.php`

#### All cache operations need error handling

```php
public static function set( $key, $value, $expiration = 3600 ) {
    try {
        $result = set_transient( $key, $value, $expiration );
        
        if ( false === $result ) {
            throw new Exception( 'Failed to set cache transient' );
        }
        
        return $result;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Cache set operation failed',
            array(
                'key' => $key,
                'error' => $e->getMessage()
            ),
            'warning'
        );
        
        // Return false but don't break execution
        return false;
    }
}

public static function get( $key ) {
    try {
        return get_transient( $key );
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Cache get operation failed',
            array(
                'key' => $key,
                'error' => $e->getMessage()
            ),
            'warning'
        );
        
        return false;
    }
}

public static function delete( $key ) {
    try {
        $result = delete_transient( $key );
        
        return $result;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Cache delete operation failed',
            array(
                'key' => $key,
                'error' => $e->getMessage()
            ),
            'warning'
        );
        
        return false;
    }
}

public static function clear_all() {
    global $wpdb;
    
    try {
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_rawwire_' ) . '%'
            )
        );
        
        if ( false === $result ) {
            throw new Exception( 'Database query failed: ' . $wpdb->last_error );
        }
        
        RawWire_Logger::log_activity(
            'Cache cleared successfully',
            'activity',
            array( 'deleted_count' => $result ),
            'info'
        );
        
        return $result;
    } catch ( Exception $e ) {
        RawWire_Logger::log_error(
            'Cache clear operation failed',
            array(
                'error' => $e->getMessage(),
                'db_error' => $wpdb->last_error
            ),
            'error'
        );
        
        return false;
    }
}
```

---

## Dashboard Error Panel Integration

### Admin Dashboard Error Widget

**File:** `includes/class-admin.php` or new file `includes/class-error-panel.php`

```php
<?php
/**
 * Error Panel for Dashboard
 * 
 * Displays recent errors and warnings in the admin dashboard.
 *
 * @package RawWire_Dashboard
 * @since 1.0.14
 */

class RawWire_Error_Panel {
    
    /**
     * Register error panel widget
     */
    public static function init() {
        add_action( 'wp_dashboard_widgets', array( __CLASS__, 'register_widget' ) );
    }
    
    /**
     * Register dashboard widget
     */
    public static function register_widget() {
        wp_add_dashboard_widget(
            'rawwire_error_panel',
            'RawWire Dashboard Errors',
            array( __CLASS__, 'render_widget' )
        );
    }
    
    /**
     * Render error panel widget
     */
    public static function render_widget() {
        global $wpdb;
        
        try {
            $table = $wpdb->prefix . 'rawwire_automation_log';
            
            // Get errors from last 24 hours
            $errors = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} 
                    WHERE JSON_EXTRACT(details, '$.severity') IN ('error', 'critical')
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY created_at DESC
                    LIMIT 10",
                    array()
                )
            );
            
            if ( $wpdb->last_error ) {
                throw new Exception( 'Database query failed' );
            }
            
            self::render_error_list( $errors );
        } catch ( Exception $e ) {
            echo '<div class="notice notice-error">';
            echo '<p>Failed to load error panel: ' . esc_html( $e->getMessage() ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Render error list
     */
    private static function render_error_list( $errors ) {
        if ( empty( $errors ) ) {
            echo '<p>✅ No errors in the last 24 hours.</p>';
            return;
        }
        
        echo '<div class="rawwire-error-panel">';
        echo '<p><strong>⚠️ ' . count( $errors ) . ' error(s) in the last 24 hours:</strong></p>';
        echo '<ul class="rawwire-error-list">';
        
        foreach ( $errors as $error ) {
            $details = json_decode( $error->details, true );
            $severity = $details['severity'] ?? 'error';
            $icon = $severity === 'critical' ? '🔴' : '⚠️';
            
            echo '<li class="rawwire-error-item rawwire-error-' . esc_attr( $severity ) . '">';
            echo '<span class="error-icon">' . $icon . '</span>';
            echo '<span class="error-time">' . esc_html( human_time_diff( strtotime( $error->created_at ), time() ) ) . ' ago</span>';
            echo '<div class="error-message">' . esc_html( $error->message ) . '</div>';
            
            if ( isset( $details['error'] ) ) {
                echo '<div class="error-details">' . esc_html( $details['error'] ) . '</div>';
            }
            
            echo '</li>';
        }
        
        echo '</ul>';
        echo '<p><a href="' . admin_url( 'admin.php?page=rawwire-logs' ) . '">View Full Error Log →</a></p>';
        echo '</div>';
        
        // Add inline styles
        ?>
        <style>
            .rawwire-error-panel {
                font-size: 13px;
            }
            .rawwire-error-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .rawwire-error-item {
                padding: 8px;
                margin-bottom: 8px;
                background: #fff;
                border-left: 3px solid #ffba00;
                border-radius: 3px;
            }
            .rawwire-error-critical {
                border-left-color: #dc3232;
            }
            .error-icon {
                margin-right: 8px;
            }
            .error-time {
                color: #666;
                font-size: 11px;
            }
            .error-message {
                font-weight: 600;
                margin: 4px 0;
            }
            .error-details {
                font-size: 11px;
                color: #666;
                font-family: monospace;
                margin-top: 4px;
            }
        </style>
        <?php
    }
}

// Initialize
RawWire_Error_Panel::init();
```

---

## Frontend Error Display (Optional)

For displaying errors in the public-facing dashboard widget:

```php
// In class-public.php or dashboard rendering code
private function render_error_notice() {
    global $wpdb;
    
    try {
        $table = $wpdb->prefix . 'rawwire_automation_log';
        
        // Check for recent critical errors
        $critical_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} 
            WHERE JSON_EXTRACT(details, '$.severity') = 'critical'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        
        if ( $critical_count > 0 ) {
            echo '<div class="rawwire-error-banner" role="alert">';
            echo '<p>⚠️ System experiencing issues. Some data may be unavailable. <a href="#">Learn more</a></p>';
            echo '</div>';
        }
    } catch ( Exception $e ) {
        // Silently fail for public display
        error_log( '[RawWire] Failed to check error status: ' . $e->getMessage() );
    }
}
```

---

## Error Monitoring Checklist

### Phase 1: Core Logger ✅
- [x] Enhanced RawWire_Logger to write to error_log
- [x] Added redundant logging for critical/error severity
- [x] Added warning logging when WP_DEBUG enabled
- [x] Added database failure fallback logging

### Phase 2: REST API Error Handling
- [ ] Add try-catch to get_content()
- [ ] Add try-catch to fetch_data()
- [ ] Add try-catch to approve_content()
- [ ] Add try-catch to snooze_content()
- [ ] Add try-catch to get_stats()
- [ ] Add try-catch to clear_cache()
- [ ] Add try-catch to API key generation/revocation

### Phase 3: Data Processing Error Handling  
- [ ] Add try-catch wrapper to process_raw_federal_register_item()
- [ ] Add error handling to store_item()
- [ ] Add error handling to check_duplicate()
- [ ] Add error handling to calculate_relevance_score()
- [ ] Add error handling to batch_process_items()

### Phase 4: External Integrations
- [ ] Add try-catch to GitHub fetcher fetch_findings()
- [ ] Add error handling to API request preparation
- [ ] Add error handling to response parsing
- [ ] Add error handling to cache operations

### Phase 5: Utility Classes
- [ ] Add error handling to Cache Manager operations
- [ ] Add error handling to Search Service
- [ ] Add error handling to Settings management

### Phase 6: Dashboard Integration
- [ ] Create Error Panel widget for admin dashboard
- [ ] Add error count to dashboard stats
- [ ] Add error filtering to Activity Logs page
- [ ] Add public error banner for critical issues

### Phase 7: Testing
- [ ] Test error logging with database unavailable
- [ ] Test error logging with GitHub API failure
- [ ] Test error panel widget display
- [ ] Test WordPress error_log writes
- [ ] Verify no errors break user experience
- [ ] Test graceful degradation

---

## Error Severity Guidelines

### info
- Routine operations
- Successful data fetches
- Cache hits
- Normal approval actions

### warning
- Deprecated function usage
- Cache misses
- Rate limit approaches
- Missing optional config

### error
- Database query failures
- API request failures
- Data processing errors
- Invalid user input
- Failed file operations

### critical
- Complete system failures
- Database unavailable
- Required classes missing
- Fatal configuration errors
- Security violations

---

## WordPress error_log Location

Default locations to check:
- `/wp-content/debug.log` (if WP_DEBUG_LOG enabled)
- Server error log (varies by hosting)
- `/var/log/apache2/error.log`
- `/var/log/nginx/error.log`

Enable WordPress debugging in `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Implementation Priority

**HIGH PRIORITY** (Implement immediately):
1. ✅ Logger enhancements (COMPLETED)
2. REST API error handling (affects all external interactions)
3. Data Processor error handling (affects data integrity)

**MEDIUM PRIORITY** (Next iteration):
4. Error Panel widget (improves visibility)
5. Cache Manager error handling (affects performance)
6. GitHub Fetcher error handling (affects data source)

**LOW PRIORITY** (Nice to have):
7. Public error banner (user-facing only when critical)
8. Advanced error analytics
9. Error notification system

---

## Next Steps

1. Review this document with team
2. Prioritize which components to enhance first
3. Implement error handling systematically
4. Test each enhancement thoroughly
5. Update documentation
6. Deploy to staging
7. Monitor error logs

---

## Benefits

✅ **Redundancy:** Errors logged to both database AND error_log  
✅ **Visibility:** Dashboard error panel shows issues at a glance  
✅ **Resilience:** System continues functioning even with errors  
✅ **Debugging:** Comprehensive context for troubleshooting  
✅ **Monitoring:** Easy to track error patterns over time  
✅ **Accessibility:** Errors available even if dashboard fails

---

**Status:** Phase 1 complete (Logger enhanced). Ready to proceed with Phase 2 (REST API) implementation.
