<?php
/**
 * Approval Workflow Class
 * 
 * Manages content approval lifecycle with history tracking.
 * Supports individual and bulk approval operations.
 * 
 * @package RawWire_Dashboard
 * @since 1.0.0
 */

if (!defined("ABSPATH")) {
    exit;
}

class RawWire_Approval_Workflow {
    
    /**
     * Valid state transitions (state machine)
     *
     * OPTIMIZATION 1: State machine implementation
     *
     * @var array
     */
    private static $state_transitions = array(
        'pending' => array('approved', 'rejected', 'review'),
        'review' => array('approved', 'rejected', 'pending'),
        'approved' => array('published', 'pending'),
        'rejected' => array('pending'),
        'published' => array(),
    );

    /**
     * Approval levels configuration
     *
     * OPTIMIZATION 3: Multi-level approval hierarchy
     *
     * @var array
     */
    private static $approval_levels = array(
        1 => 'reviewer',      // Can review and recommend
        2 => 'manager',       // Can approve up to $10k
        3 => 'director',      // Can approve up to $100k
        4 => 'executive',     // Can approve anything
    );

    /**
     * Approval expiry in days
     *
     * OPTIMIZATION 2: Auto-expire approvals
     *
     * @var int
     */
    private static $approval_expiry_days = 30;

    /**
     * Approval analytics data
     *
     * OPTIMIZATION 5: Track approval metrics
     *
     * @var array
     */
    private static $analytics = array(
        'total_approvals' => 0,
        'total_rejections' => 0,
        'avg_approval_time' => 0,
        'approval_rate' => 0,
    );
    
    /**
     * Validate state transition
     *
     * OPTIMIZATION 1: State machine validation
     *
     * @param  string $from_state Current state
     * @param  string $to_state Desired state
     * @return bool|WP_Error
     */
    private static function validate_state_transition($from_state, $to_state) {
        if (!isset(self::$state_transitions[$from_state])) {
            return new WP_Error('invalid_state', "Invalid current state: {$from_state}");
        }

        if (!in_array($to_state, self::$state_transitions[$from_state])) {
            return new WP_Error(
                'invalid_transition',
                "Cannot transition from {$from_state} to {$to_state}"
            );
        }

        return true;
    }

    /**
     * Get required approval level for content
     *
     * OPTIMIZATION 3: Multi-level approval
     *
     * @param  array $content Content data
     * @return int Required approval level
     */
    private static function get_required_approval_level($content) {
        // Determine level based on content value/impact
        $value = 0;
        if (isset($content['metadata'])) {
            $metadata = json_decode($content['metadata'], true);
            $value = $metadata['estimated_value'] ?? 0;
        }

        if ($value >= 100000) return 4; // Executive
        if ($value >= 10000) return 3;  // Director
        if ($value >= 1000) return 2;   // Manager
        return 1; // Reviewer
    }

    /**
     * Check if user has required approval level
     *
     * OPTIMIZATION 3: Approval hierarchy validation
     *
     * @param  int $user_id User ID
     * @param  int $required_level Required level
     * @return bool
     */
    private static function user_has_approval_level($user_id, $required_level) {
        $user_level = get_user_meta($user_id, 'rawwire_approval_level', true);
        if (empty($user_level)) {
            // Default: admins have level 4, editors level 2
            $user = get_user_by('id', $user_id);
            if (in_array('administrator', $user->roles)) {
                $user_level = 4;
            } elseif (in_array('editor', $user->roles)) {
                $user_level = 2;
            } else {
                $user_level = 1;
            }
        }

        return $user_level >= $required_level;
    }

    /**
     * Send approval notification
     *
     * OPTIMIZATION 4: Notification system
     *
     * @param  int    $content_id Content ID
     * @param  string $action Action performed
     * @param  int    $user_id User who performed action
     * @param  string $notes Additional notes
     * @return void
     */
    private static function send_notification($content_id, $action, $user_id, $notes = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $content = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $content_id),
            ARRAY_A
        );

        if (!$content) return;

        $user = get_user_by('id', $user_id);
        $admin_email = get_option('admin_email');

        $subject = sprintf(
            '[%s] Content %s: %s',
            get_bloginfo('name'),
            $action,
            $content['title']
        );

        $message = sprintf(
            "Content Status Update\n\n" .
            "Title: %s\n" .
            "Action: %s\n" .
            "Performed by: %s\n" .
            "Notes: %s\n\n" .
            "View content: %s",
            $content['title'],
            $action,
            $user->display_name,
            $notes,
            admin_url('admin.php?page=rawwire-dashboard&content_id=' . $content_id)
        );

        wp_mail($admin_email, $subject, $message);

        // Trigger webhook
        do_action('rawwire_approval_notification', $content_id, $action, $user_id, $notes);
    }

    /**
     * Check and expire old approvals
     *
     * OPTIMIZATION 2: Auto-expire approvals
     *
     * @return int Number of expired approvals
     */
    public static function expire_old_approvals() {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $expiry_date = date('Y-m-d H:i:s', strtotime('-' . self::$approval_expiry_days . ' days'));

        $count = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = 'pending', updated_at = %s 
                 WHERE status = 'approved' AND updated_at < %s",
                current_time('mysql'),
                $expiry_date
            )
        );

        if ($count > 0 && class_exists('RawWire_Logger')) {
            RawWire_Logger::log_activity(
                'Expired old approvals',
                'approval',
                array('count' => $count, 'expiry_days' => self::$approval_expiry_days),
                'info'
            );
        }

        return $count;
    }

    /**
     * Get approval analytics
     *
     * OPTIMIZATION 5: Approval analytics
     *
     * @param  int $days Days to analyze (default 30)
     * @return array Analytics data
     */
    public static function get_analytics($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'rawwire_content';
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as total_approvals,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as total_rejections,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(*) as total
                 FROM {$table}
                 WHERE updated_at >= %s",
                $start_date
            ),
            ARRAY_A
        );

        $approval_rate = $stats['total'] > 0
            ? round(($stats['total_approvals'] / $stats['total']) * 100, 2)
            : 0;

        return array(
            'total_approvals' => (int) $stats['total_approvals'],
            'total_rejections' => (int) $stats['total_rejections'],
            'pending' => (int) $stats['pending'],
            'approval_rate' => $approval_rate,
            'period_days' => $days,
        );
    }
    
    /**
     * Approve a single content item
     * 
     * @param int $content_id Content ID to approve
     * @param int $user_id User performing the approval
     * @param string $notes Optional approval notes
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function approve_content(int $content_id, int $user_id, string $notes = "") {
        // Log approval attempt
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Approval requested', 'approval', array(
                'content_id' => $content_id,
                'user_id' => $user_id,
                'has_notes' => !empty($notes)
            ));
        }
        
        // Check permissions
        if (!current_user_can("manage_options")) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Approval denied - insufficient permissions', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id
                ));
            }
            return new WP_Error(
                "forbidden",
                "Insufficient capabilities to approve content"
            );
        }
        
        // Validate content exists
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_content";
        
        $content = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $content_id),
            ARRAY_A
        );
        
        if (!$content) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Approval failed - content not found', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id
                ));
            }
            return new WP_Error(
                "not_found",
                "Content not found"
            );
        }
        
        // Check if already approved
        if ($content["status"] === "approved") {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Duplicate approval attempt', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'current_status' => 'approved',
                    'title' => substr($content["title"] ?? 'Unknown', 0, 100)
                ));
            }
            return new WP_Error(
                "already_approved",
                "Content is already approved"
            );
        }

        // OPTIMIZATION 1: Validate state transition
        $current_state = $content["status"] ?? 'pending';
        $transition_valid = self::validate_state_transition($current_state, 'approved');
        if (is_wp_error($transition_valid)) {
            return $transition_valid;
        }

        // OPTIMIZATION 3: Check approval level
        $required_level = self::get_required_approval_level($content);
        if (!self::user_has_approval_level($user_id, $required_level)) {
            return new WP_Error(
                'insufficient_level',
                "Your approval level is insufficient for this content (requires level {$required_level})"
            );
        }
        
        // Log pre-approval state
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::debug('Content state before approval', 'approval', array(
                'content_id' => $content_id,
                'previous_status' => $content["status"] ?? 'unknown',
                'title' => substr($content["title"] ?? 'Unknown', 0, 100)
            ));
        }
        
        // Update status to approved
        $updated = $wpdb->update(
            $table,
            array("status" => "approved", "updated_at" => current_time("mysql")),
            array("id" => $content_id),
            array("%s", "%s"),
            array("%d")
        );
        
        if ($updated === false) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::log_error('Approval database update failed', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'db_error' => $wpdb->last_error
                ), 'error');
            }
            return new WP_Error(
                "db_error",
                "Failed to update content status"
            );
        }
        
        // Record approval in history
        self::record_approval($content_id, $user_id, $notes);
        
        // OPTIMIZATION 4: Send notification
        self::send_notification($content_id, 'approved', $user_id, $notes);
        
        // Log successful approval
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Content approved successfully', 'approval', array(
                'content_id' => $content_id,
                'user_id' => $user_id,
                'previous_status' => $content["status"] ?? 'unknown',
                'notes' => substr($notes, 0, 200),
                'title' => substr($content["title"] ?? 'Unknown', 0, 100)
            ));
        }
        
        // Trigger action hook for extensibility
        do_action("rawwire_content_approved", $content_id, $user_id, $notes);
        
        return true;
    }
    
    /**
     * Reject a content item
     * 
     * @param int $content_id Content ID to reject
     * @param int $user_id User performing the rejection
     * @param string $reason Rejection reason
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function reject_content(int $content_id, int $user_id, string $reason = "") {
        // Log rejection attempt
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Rejection requested', 'approval', array(
                'content_id' => $content_id,
                'user_id' => $user_id,
                'has_reason' => !empty($reason)
            ));
        }
        
        // Check permissions
        if (!current_user_can("manage_options")) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Rejection denied - insufficient permissions', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id
                ));
            }
            return new WP_Error(
                "forbidden",
                "Insufficient capabilities to reject content"
            );
        }
        
        // Update status to rejected
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_content";
        
        $updated = $wpdb->update(
            $table,
            array("status" => "rejected", "updated_at" => current_time("mysql")),
            array("id" => $content_id),
            array("%s", "%s"),
            array("%d")
        );
        
        if ($updated === false) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::log_error('Rejection database update failed', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'db_error' => $wpdb->last_error
                ), 'error');
            }
            return new WP_Error(
                "db_error",
                "Failed to update content status"
            );
        }
        
        // Record rejection in history
        self::record_rejection($content_id, $user_id, $reason);
        
        // Log successful rejection
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Content rejected successfully', 'approval', array(
                'content_id' => $content_id,
                'user_id' => $user_id,
                'reason' => substr($reason, 0, 200)
            ));
        }
        
        // Trigger action hook
        do_action("rawwire_content_rejected", $content_id, $user_id, $reason);
        
        return true;
    }
    
    /**
     * Bulk approve multiple content items
     * 
     * @param array $content_ids Array of content IDs
     * @param int $user_id User performing the approvals
     * @param string $notes Optional approval notes
     * @return array Array of approved content IDs
     */
    public static function bulk_approve(array $content_ids, int $user_id, string $notes = ""): array {
        // Log bulk approval start
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Bulk approval started', 'approval', array(
                'count' => count($content_ids),
                'user_id' => $user_id,
                'content_ids' => $content_ids
            ));
        }
        
        $approved = array();
        $failed = array();
        
        foreach ($content_ids as $content_id) {
            $result = self::approve_content((int)$content_id, $user_id, $notes);
            if ($result === true) {
                $approved[] = (int)$content_id;
            } else {
                $failed[] = array(
                    'content_id' => (int)$content_id,
                    'error' => is_wp_error($result) ? $result->get_error_message() : 'Unknown error'
                );
            }
        }
        
        // Log bulk approval summary
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Bulk approval completed', 'approval', array(
                'total' => count($content_ids),
                'approved' => count($approved),
                'failed' => count($failed),
                'user_id' => $user_id,
                'failed_items' => $failed
            ));
        }
        
        return $approved;
    }
    
    /**
     * Bulk reject multiple content items
     * 
     * @param array $content_ids Array of content IDs
     * @param int $user_id User performing the rejections
     * @param string $reason Rejection reason
     * @return array Array of rejected content IDs
     */
    public static function bulk_reject(array $content_ids, int $user_id, string $reason = ""): array {
        // Log bulk rejection start
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Bulk rejection started', 'approval', array(
                'count' => count($content_ids),
                'user_id' => $user_id,
                'content_ids' => $content_ids
            ));
        }
        
        $rejected = array();
        $failed = array();
        
        foreach ($content_ids as $content_id) {
            $result = self::reject_content((int)$content_id, $user_id, $reason);
            if ($result === true) {
                $rejected[] = (int)$content_id;
            } else {
                $failed[] = array(
                    'content_id' => (int)$content_id,
                    'error' => is_wp_error($result) ? $result->get_error_message() : 'Unknown error'
                );
            }
        }
        
        // Log bulk rejection summary
        if (class_exists('RawWire_Logger')) {
            RawWire_Logger::info('Bulk rejection completed', 'approval', array(
                'total' => count($content_ids),
                'rejected' => count($rejected),
                'failed' => count($failed),
                'user_id' => $user_id,
                'failed_items' => $failed
            ));
        }
        
        return $rejected;
    }
    
    /**
     * Record approval in history table
     * 
     * @param int $content_id Content ID
     * @param int $user_id User ID
     * @param string $notes Approval notes
     * @return void
     */
    public static function record_approval(int $content_id, int $user_id, string $notes = ""): void {
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_approval_history";
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            // Table doesn't exist yet, skip history recording
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Approval history table missing', 'approval', array(
                    'table' => $table,
                    'content_id' => $content_id
                ));
            }
            return;
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                "content_id" => $content_id,
                "user_id" => $user_id,
                "action" => "approved",
                "notes" => sanitize_textarea_field($notes),
                "created_at" => current_time("mysql")
            ),
            array("%d", "%d", "%s", "%s", "%s")
        );
        
        if ($result === false) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::log_error('Failed to record approval in history', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'db_error' => $wpdb->last_error
                ), 'error');
            }
        } else {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::debug('Approval recorded in history', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'history_id' => $wpdb->insert_id
                ));
            }
        }
    }
    
    /**
     * Record rejection in history table
     * 
     * @param int $content_id Content ID
     * @param int $user_id User ID
     * @param string $reason Rejection reason
     * @return void
     */
    public static function record_rejection(int $content_id, int $user_id, string $reason = ""): void {
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_approval_history";
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::warning('Approval history table missing', 'approval', array(
                    'table' => $table,
                    'content_id' => $content_id
                ));
            }
            return;
        }
        
        $result = $wpdb->insert(
            $table,
            array(
                "content_id" => $content_id,
                "user_id" => $user_id,
                "action" => "rejected",
                "notes" => sanitize_textarea_field($reason),
                "created_at" => current_time("mysql")
            ),
            array("%d", "%d", "%s", "%s", "%s")
        );
        
        if ($result === false) {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::log_error('Failed to record rejection in history', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'db_error' => $wpdb->last_error
                ), 'error');
            }
        } else {
            if (class_exists('RawWire_Logger')) {
                RawWire_Logger::debug('Rejection recorded in history', 'approval', array(
                    'content_id' => $content_id,
                    'user_id' => $user_id,
                    'history_id' => $wpdb->insert_id
                ));
            }
        }
    }
    
    /**
     * Get approval history for a content item
     * 
     * @param int $content_id Content ID
     * @return array Array of history records
     */
    public static function get_history(int $content_id): array {
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_approval_history";
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            return array();
        }
        
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT h.*, u.display_name as user_name 
                 FROM {$table} h 
                 LEFT JOIN {$wpdb->users} u ON h.user_id = u.ID 
                 WHERE h.content_id = %d 
                 ORDER BY h.created_at DESC",
                $content_id
            ),
            ARRAY_A
        );
        
        return $history ?: array();
    }
    
    /**
     * Get approval statistics for a user
     * 
     * @param int $user_id User ID
     * @return array Statistics array
     */
    public static function get_user_stats(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . "rawwire_approval_history";
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            return array();
        }
        
        $approved = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND action = 'approved'",
                $user_id
            )
        );
        
        $rejected = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND action = 'rejected'",
                $user_id
            )
        );
        
        return array(
            "total" => $approved + $rejected,
            "approved" => $approved,
            "rejected" => $rejected
        );
    }
}

// Hook for backward compatibility
add_action("rawwire_content_approved", array("RawWire_Approval_Workflow", "record_approval"), 10, 3);

