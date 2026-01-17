<?php
/**
 * WordPress REST Poster Adapter (Free Tier)
 * 
 * Posts content to WordPress sites using the built-in REST API.
 * No external dependencies - uses native WordPress functions.
 * 
 * @package RawWire\Toolbox\Adapters\Posters
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Poster_WordPress extends RawWire_Adapter_Base implements RawWire_Poster_Interface {

    protected $name = 'WordPress REST';
    protected $version = '1.0.0';
    protected $tier = 'free';
    protected $capabilities = array(
        'posts',
        'pages',
        'custom_post_types',
        'media_upload',
        'featured_image',
        'categories',
        'tags',
        'custom_fields',
        'scheduling',
        'drafts',
    );
    protected $required_fields = array();

    /**
     * Validate configuration
     */
    public function validate_config(array $config) {
        // For local posting, we only need the post type
        // For remote sites, we need site_url, username, and app_password
        if (!empty($config['remote_site'])) {
            if (empty($config['site_url'])) {
                $this->last_error = 'Site URL is required for remote posting';
                return false;
            }
            if (empty($config['username']) || empty($config['app_password'])) {
                $this->last_error = 'Username and Application Password are required for remote posting';
                return false;
            }
        }

        return true;
    }

    /**
     * Test connection
     */
    public function test_connection() {
        try {
            if (!empty($this->config['remote_site'])) {
                return $this->test_remote_connection();
            }

            // Local test - verify we can create posts
            if (!current_user_can('edit_posts')) {
                $this->last_error = 'Current user does not have permission to create posts';
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log('Connection test failed', 'error', array('error' => $e->getMessage()));
            return false;
        }
    }

    /**
     * Test remote site connection
     */
    protected function test_remote_connection() {
        $site_url = trailingslashit($this->config['site_url']);
        $endpoint = $site_url . 'wp-json/wp/v2/users/me';

        $response = $this->make_remote_request('GET', $endpoint);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        return true;
    }

    /**
     * Make authenticated request to remote site
     */
    protected function make_remote_request(string $method, string $url, array $body = array()) {
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['app_password']),
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        );

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $message = $body['message'] ?? 'Unknown error';
            return new WP_Error('remote_error', $message, array('code' => $code));
        }

        return $body;
    }

    /**
     * Publish content
     */
    public function publish(array $content, array $options = array()) {
        try {
            if (!empty($this->config['remote_site'])) {
                return $this->publish_remote($content, $options);
            }

            return $this->publish_local($content, $options);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log('Publish failed', 'error', array(
                'error' => $e->getMessage(),
                'content' => array_keys($content),
            ));
            return new WP_Error('publish_failed', $e->getMessage());
        }
    }

    /**
     * Publish to local site
     */
    protected function publish_local(array $content, array $options = array()) {
        $post_type = $options['post_type'] ?? $this->config['post_type'] ?? 'post';
        $status = $options['status'] ?? 'publish';

        $post_data = array(
            'post_title' => sanitize_text_field($content['title'] ?? ''),
            'post_content' => wp_kses_post($content['content'] ?? ''),
            'post_status' => $status,
            'post_type' => $post_type,
        );

        // Handle excerpt
        if (!empty($content['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($content['excerpt']);
        }

        // Handle author
        if (!empty($options['author_id'])) {
            $post_data['post_author'] = absint($options['author_id']);
        }

        // Handle slug
        if (!empty($content['slug'])) {
            $post_data['post_name'] = sanitize_title($content['slug']);
        }

        // Handle parent (for pages/hierarchical types)
        if (!empty($options['parent_id'])) {
            $post_data['post_parent'] = absint($options['parent_id']);
        }

        // Handle menu order
        if (isset($options['menu_order'])) {
            $post_data['menu_order'] = absint($options['menu_order']);
        }

        // Insert the post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            $this->last_error = $post_id->get_error_message();
            return $post_id;
        }

        // Handle categories
        if (!empty($content['categories'])) {
            $this->set_terms($post_id, $content['categories'], 'category');
        }

        // Handle tags
        if (!empty($content['tags'])) {
            $this->set_terms($post_id, $content['tags'], 'post_tag');
        }

        // Handle custom taxonomies
        if (!empty($content['taxonomies'])) {
            foreach ($content['taxonomies'] as $taxonomy => $terms) {
                $this->set_terms($post_id, $terms, $taxonomy);
            }
        }

        // Handle featured image
        if (!empty($content['featured_image'])) {
            $this->set_featured_image($post_id, $content['featured_image']);
        }

        // Handle custom fields / meta
        if (!empty($content['meta'])) {
            foreach ($content['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), $value);
            }
        }

        $this->log('Content published locally', 'info', array(
            'post_id' => $post_id,
            'post_type' => $post_type,
            'status' => $status,
        ));

        return array(
            'success' => true,
            'id' => $post_id,
            'url' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
        );
    }

    /**
     * Publish to remote site
     */
    protected function publish_remote(array $content, array $options = array()) {
        $site_url = trailingslashit($this->config['site_url']);
        $post_type = $options['post_type'] ?? $this->config['post_type'] ?? 'posts';
        $endpoint = $site_url . 'wp-json/wp/v2/' . $post_type;

        $body = array(
            'title' => $content['title'] ?? '',
            'content' => $content['content'] ?? '',
            'status' => $options['status'] ?? 'publish',
        );

        if (!empty($content['excerpt'])) {
            $body['excerpt'] = $content['excerpt'];
        }

        if (!empty($content['slug'])) {
            $body['slug'] = $content['slug'];
        }

        if (!empty($content['categories'])) {
            $body['categories'] = $content['categories'];
        }

        if (!empty($content['tags'])) {
            $body['tags'] = $content['tags'];
        }

        if (!empty($content['featured_image_id'])) {
            $body['featured_media'] = $content['featured_image_id'];
        }

        if (!empty($content['meta'])) {
            $body['meta'] = $content['meta'];
        }

        $response = $this->make_remote_request('POST', $endpoint, $body);

        if (is_wp_error($response)) {
            return $response;
        }

        $this->log('Content published remotely', 'info', array(
            'remote_id' => $response['id'] ?? null,
            'site' => $this->config['site_url'],
        ));

        return array(
            'success' => true,
            'id' => $response['id'],
            'url' => $response['link'] ?? '',
        );
    }

    /**
     * Update existing content
     */
    public function update(string $id, array $content, array $options = array()) {
        try {
            if (!empty($this->config['remote_site'])) {
                return $this->update_remote($id, $content, $options);
            }

            return $this->update_local($id, $content, $options);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('update_failed', $e->getMessage());
        }
    }

    /**
     * Update local post
     */
    protected function update_local(string $id, array $content, array $options = array()) {
        $post_id = absint($id);
        
        $post_data = array('ID' => $post_id);

        if (isset($content['title'])) {
            $post_data['post_title'] = sanitize_text_field($content['title']);
        }

        if (isset($content['content'])) {
            $post_data['post_content'] = wp_kses_post($content['content']);
        }

        if (isset($content['excerpt'])) {
            $post_data['post_excerpt'] = sanitize_textarea_field($content['excerpt']);
        }

        if (!empty($options['status'])) {
            $post_data['post_status'] = $options['status'];
        }

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return $result;
        }

        // Update taxonomies
        if (!empty($content['categories'])) {
            $this->set_terms($post_id, $content['categories'], 'category');
        }

        if (!empty($content['tags'])) {
            $this->set_terms($post_id, $content['tags'], 'post_tag');
        }

        // Update meta
        if (!empty($content['meta'])) {
            foreach ($content['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), $value);
            }
        }

        // Update featured image
        if (!empty($content['featured_image'])) {
            $this->set_featured_image($post_id, $content['featured_image']);
        }

        return array(
            'success' => true,
            'id' => $post_id,
            'url' => get_permalink($post_id),
        );
    }

    /**
     * Update remote post
     */
    protected function update_remote(string $id, array $content, array $options = array()) {
        $site_url = trailingslashit($this->config['site_url']);
        $post_type = $options['post_type'] ?? $this->config['post_type'] ?? 'posts';
        $endpoint = $site_url . 'wp-json/wp/v2/' . $post_type . '/' . $id;

        $body = array();

        if (isset($content['title'])) {
            $body['title'] = $content['title'];
        }

        if (isset($content['content'])) {
            $body['content'] = $content['content'];
        }

        if (isset($content['excerpt'])) {
            $body['excerpt'] = $content['excerpt'];
        }

        if (!empty($options['status'])) {
            $body['status'] = $options['status'];
        }

        $response = $this->make_remote_request('POST', $endpoint, $body);

        if (is_wp_error($response)) {
            return $response;
        }

        return array(
            'success' => true,
            'id' => $response['id'],
            'url' => $response['link'] ?? '',
        );
    }

    /**
     * Delete content
     */
    public function delete(string $id, array $options = array()) {
        try {
            if (!empty($this->config['remote_site'])) {
                return $this->delete_remote($id, $options);
            }

            $post_id = absint($id);
            $force = $options['force'] ?? false;

            $result = wp_delete_post($post_id, $force);

            if (!$result) {
                return new WP_Error('delete_failed', 'Failed to delete post');
            }

            $this->log('Content deleted', 'info', array('post_id' => $post_id));

            return array('success' => true, 'id' => $post_id);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('delete_failed', $e->getMessage());
        }
    }

    /**
     * Delete remote content
     */
    protected function delete_remote(string $id, array $options = array()) {
        $site_url = trailingslashit($this->config['site_url']);
        $post_type = $options['post_type'] ?? $this->config['post_type'] ?? 'posts';
        $endpoint = $site_url . 'wp-json/wp/v2/' . $post_type . '/' . $id;

        if (!empty($options['force'])) {
            $endpoint .= '?force=true';
        }

        $response = $this->make_remote_request('DELETE', $endpoint);

        if (is_wp_error($response)) {
            return $response;
        }

        return array('success' => true, 'id' => $id);
    }

    /**
     * Schedule content for future publishing
     */
    public function schedule(array $content, string $datetime, array $options = array()) {
        $options['status'] = 'future';

        // Parse datetime
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return new WP_Error('invalid_datetime', 'Invalid datetime format');
        }

        if (!empty($this->config['remote_site'])) {
            $content['date'] = gmdate('c', $timestamp);
            return $this->publish_remote($content, $options);
        }

        // For local posting, use post_date
        $result = $this->publish_local($content, $options);
        
        if (!is_wp_error($result) && !empty($result['id'])) {
            wp_update_post(array(
                'ID' => $result['id'],
                'post_date' => gmdate('Y-m-d H:i:s', $timestamp),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
            ));
        }

        return $result;
    }

    /**
     * Upload media
     */
    public function upload_media($file, array $options = array()) {
        try {
            if (!empty($this->config['remote_site'])) {
                return $this->upload_media_remote($file, $options);
            }

            return $this->upload_media_local($file, $options);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('upload_failed', $e->getMessage());
        }
    }

    /**
     * Upload media locally
     */
    protected function upload_media_local($file, array $options = array()) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Handle URL download
        if (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
            $tmp = download_url($file);
            if (is_wp_error($tmp)) {
                return $tmp;
            }

            $file_array = array(
                'name' => basename(parse_url($file, PHP_URL_PATH)),
                'tmp_name' => $tmp,
            );
        } elseif (is_array($file) && isset($file['tmp_name'])) {
            $file_array = $file;
        } else {
            return new WP_Error('invalid_file', 'Invalid file input');
        }

        $post_id = $options['post_id'] ?? 0;
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return $attachment_id;
        }

        // Set alt text if provided
        if (!empty($options['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($options['alt_text']));
        }

        // Set title and caption
        if (!empty($options['title']) || !empty($options['caption'])) {
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $options['title'] ?? '',
                'post_excerpt' => $options['caption'] ?? '',
            ));
        }

        $this->log('Media uploaded', 'info', array('attachment_id' => $attachment_id));

        return array(
            'success' => true,
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        );
    }

    /**
     * Upload media to remote site
     */
    protected function upload_media_remote($file, array $options = array()) {
        $site_url = trailingslashit($this->config['site_url']);
        $endpoint = $site_url . 'wp-json/wp/v2/media';

        // Download file if URL
        if (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
            $tmp = download_url($file);
            if (is_wp_error($tmp)) {
                return $tmp;
            }
            $file_path = $tmp;
            $filename = basename(parse_url($file, PHP_URL_PATH));
        } elseif (is_array($file) && isset($file['tmp_name'])) {
            $file_path = $file['tmp_name'];
            $filename = $file['name'];
        } else {
            return new WP_Error('invalid_file', 'Invalid file input');
        }

        $file_content = file_get_contents($file_path);
        $mime_type = mime_content_type($file_path);

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['app_password']),
                'Content-Type' => $mime_type,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ),
            'body' => $file_content,
            'timeout' => 60,
        );

        $response = wp_remote_request($endpoint, $args);

        // Clean up temp file
        if (isset($tmp)) {
            @unlink($tmp);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code >= 400) {
            return new WP_Error('upload_failed', $body['message'] ?? 'Upload failed');
        }

        return array(
            'success' => true,
            'id' => $body['id'],
            'url' => $body['source_url'] ?? '',
        );
    }

    /**
     * Set terms for a post
     */
    protected function set_terms(int $post_id, array $terms, string $taxonomy) {
        $term_ids = array();

        foreach ($terms as $term) {
            if (is_numeric($term)) {
                $term_ids[] = absint($term);
            } else {
                // Try to find or create the term
                $existing = term_exists($term, $taxonomy);
                if ($existing) {
                    $term_ids[] = is_array($existing) ? $existing['term_id'] : $existing;
                } else {
                    $created = wp_insert_term($term, $taxonomy);
                    if (!is_wp_error($created)) {
                        $term_ids[] = $created['term_id'];
                    }
                }
            }
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, $taxonomy);
        }
    }

    /**
     * Set featured image
     */
    protected function set_featured_image(int $post_id, $image) {
        // If it's already an attachment ID
        if (is_numeric($image)) {
            set_post_thumbnail($post_id, absint($image));
            return;
        }

        // If it's a URL, download and attach
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            $result = $this->upload_media_local($image, array('post_id' => $post_id));
            if (!is_wp_error($result) && !empty($result['id'])) {
                set_post_thumbnail($post_id, $result['id']);
            }
        }
    }
}
