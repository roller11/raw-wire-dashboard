<?php
/**
 * Twitter/X API Poster Adapter (Value Tier)
 * 
 * Posts content to Twitter/X using their API v2.
 * Requires API keys from the Twitter Developer Portal.
 * 
 * @package RawWire\Toolbox\Adapters\Posters
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Poster_Twitter extends RawWire_Adapter_Base implements RawWire_Poster_Interface {

    protected $name = 'Twitter/X API';
    protected $version = '1.0.0';
    protected $tier = 'value';
    protected $capabilities = array(
        'text_posts',
        'media_upload',
        'threads',
        'polls',
        'scheduling',
        'reply_to',
        'quote_tweet',
        'delete',
    );
    protected $required_fields = array('api_key', 'api_secret', 'access_token', 'access_secret');

    protected $api_base = 'https://api.twitter.com/2';
    protected $upload_base = 'https://upload.twitter.com/1.1';

    /**
     * Validate configuration
     */
    public function validate_config(array $config) {
        foreach ($this->required_fields as $field) {
            if (empty($config[$field])) {
                $this->last_error = sprintf('Missing required field: %s', $field);
                return false;
            }
        }
        return true;
    }

    /**
     * Test connection by verifying credentials
     */
    public function test_connection() {
        try {
            $response = $this->make_api_request('GET', '/users/me');

            if (is_wp_error($response)) {
                $this->last_error = $response->get_error_message();
                return false;
            }

            if (!empty($response['data']['id'])) {
                $this->log('Twitter connection verified', 'info', array(
                    'user_id' => $response['data']['id'],
                    'username' => $response['data']['username'] ?? '',
                ));
                return true;
            }

            $this->last_error = 'Could not verify Twitter credentials';
            return false;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Generate OAuth 1.0a signature
     */
    protected function generate_oauth_signature(string $method, string $url, array $params) {
        $consumer_key = $this->config['api_key'];
        $consumer_secret = $this->config['api_secret'];
        $token = $this->config['access_token'];
        $token_secret = $this->config['access_secret'];

        $oauth = array(
            'oauth_consumer_key' => $consumer_key,
            'oauth_nonce' => md5(uniqid(wp_rand(), true)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        );

        // Combine and sort parameters
        $all_params = array_merge($oauth, $params);
        ksort($all_params);

        // Create parameter string
        $param_string = http_build_query($all_params, '', '&', PHP_QUERY_RFC3986);

        // Create base string
        $base_string = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($param_string);

        // Create signing key
        $signing_key = rawurlencode($consumer_secret) . '&' . rawurlencode($token_secret);

        // Generate signature
        $signature = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        $oauth['oauth_signature'] = $signature;

        return $oauth;
    }

    /**
     * Build OAuth header
     */
    protected function build_oauth_header(array $oauth) {
        $parts = array();
        foreach ($oauth as $key => $value) {
            $parts[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';
        }
        return 'OAuth ' . implode(', ', $parts);
    }

    /**
     * Make API request with OAuth 1.0a authentication
     */
    protected function make_api_request(string $method, string $endpoint, array $body = array(), bool $is_upload = false) {
        $base_url = $is_upload ? $this->upload_base : $this->api_base;
        $url = $base_url . $endpoint;

        // For GET requests, add query params
        $query_params = array();
        if ($method === 'GET' && !empty($body)) {
            $query_params = $body;
            $body = array();
        }

        // Generate OAuth signature
        $oauth = $this->generate_oauth_signature($method, $url, $query_params);

        // Build URL with query params
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => $this->build_oauth_header($oauth),
            ),
            'timeout' => 30,
        );

        if (!empty($body)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $error_message = 'Twitter API error';
            if (!empty($body['errors'][0]['message'])) {
                $error_message = $body['errors'][0]['message'];
            } elseif (!empty($body['detail'])) {
                $error_message = $body['detail'];
            } elseif (!empty($body['title'])) {
                $error_message = $body['title'];
            }

            $this->log('Twitter API error', 'error', array(
                'code' => $code,
                'endpoint' => $endpoint,
                'error' => $error_message,
                'response' => $body,
            ));

            return new WP_Error('twitter_api_error', $error_message, array('code' => $code));
        }

        return $body;
    }

    /**
     * Publish a tweet
     */
    public function publish(array $content, array $options = array()) {
        try {
            $body = array();

            // Tweet text (required)
            $text = $content['content'] ?? $content['text'] ?? '';
            if (empty($text)) {
                return new WP_Error('missing_content', 'Tweet text is required');
            }

            // Enforce character limit
            if (mb_strlen($text) > 280) {
                $text = mb_substr($text, 0, 277) . '...';
            }

            $body['text'] = $text;

            // Handle media attachments
            if (!empty($content['media_ids'])) {
                $body['media'] = array('media_ids' => (array) $content['media_ids']);
            } elseif (!empty($content['media'])) {
                // Upload media first
                $media_ids = array();
                foreach ((array) $content['media'] as $media_item) {
                    $result = $this->upload_media($media_item);
                    if (!is_wp_error($result) && !empty($result['id'])) {
                        $media_ids[] = $result['id'];
                    }
                }
                if (!empty($media_ids)) {
                    $body['media'] = array('media_ids' => $media_ids);
                }
            }

            // Reply to another tweet
            if (!empty($options['reply_to'])) {
                $body['reply'] = array('in_reply_to_tweet_id' => $options['reply_to']);
            }

            // Quote tweet
            if (!empty($options['quote_tweet_id'])) {
                $body['quote_tweet_id'] = $options['quote_tweet_id'];
            }

            // Poll
            if (!empty($content['poll'])) {
                $body['poll'] = array(
                    'options' => array_slice((array) $content['poll']['options'], 0, 4),
                    'duration_minutes' => min(absint($content['poll']['duration'] ?? 1440), 10080),
                );
            }

            // Reply settings
            if (!empty($options['reply_settings'])) {
                $body['reply_settings'] = $options['reply_settings']; // 'mentionedUsers', 'following'
            }

            // Post the tweet
            $response = $this->make_api_request('POST', '/tweets', $body);

            if (is_wp_error($response)) {
                return $response;
            }

            $tweet_id = $response['data']['id'] ?? null;

            $this->log('Tweet published', 'info', array(
                'tweet_id' => $tweet_id,
                'text_length' => mb_strlen($text),
            ));

            return array(
                'success' => true,
                'id' => $tweet_id,
                'url' => $tweet_id ? "https://twitter.com/i/web/status/{$tweet_id}" : '',
                'data' => $response['data'] ?? array(),
            );
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log('Tweet publish failed', 'error', array('error' => $e->getMessage()));
            return new WP_Error('publish_failed', $e->getMessage());
        }
    }

    /**
     * Post a thread (multiple connected tweets)
     */
    public function publish_thread(array $tweets, array $options = array()) {
        $results = array();
        $previous_id = $options['reply_to'] ?? null;

        foreach ($tweets as $index => $tweet_content) {
            $tweet_options = $options;

            if ($previous_id) {
                $tweet_options['reply_to'] = $previous_id;
            }

            $result = $this->publish($tweet_content, $tweet_options);

            if (is_wp_error($result)) {
                $this->log('Thread interrupted at tweet ' . ($index + 1), 'warning', array(
                    'error' => $result->get_error_message(),
                    'completed_tweets' => count($results),
                ));
                return new WP_Error(
                    'thread_interrupted',
                    sprintf('Thread failed at tweet %d: %s', $index + 1, $result->get_error_message()),
                    array('completed' => $results)
                );
            }

            $results[] = $result;
            $previous_id = $result['id'];
        }

        return array(
            'success' => true,
            'tweets' => $results,
            'thread_url' => $results[0]['url'] ?? '',
        );
    }

    /**
     * Update - Twitter doesn't support editing (returns error)
     */
    public function update(string $id, array $content, array $options = array()) {
        // Twitter Edit is only available for Twitter Blue users via specific endpoints
        // For now, return not supported
        return new WP_Error('not_supported', 'Tweet editing is not supported via this adapter. Delete and repost instead.');
    }

    /**
     * Delete a tweet
     */
    public function delete(string $id, array $options = array()) {
        try {
            $response = $this->make_api_request('DELETE', '/tweets/' . $id);

            if (is_wp_error($response)) {
                return $response;
            }

            $deleted = $response['data']['deleted'] ?? false;

            $this->log('Tweet deleted', 'info', array('tweet_id' => $id, 'deleted' => $deleted));

            return array(
                'success' => $deleted,
                'id' => $id,
            );
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('delete_failed', $e->getMessage());
        }
    }

    /**
     * Schedule - Twitter API v2 doesn't have native scheduling
     * This would require a separate scheduling system
     */
    public function schedule(array $content, string $datetime, array $options = array()) {
        // Store scheduled tweet in WP options for a cron job to process
        $scheduled_tweets = get_option('rawwire_scheduled_tweets', array());

        $tweet_data = array(
            'id' => uniqid('tweet_'),
            'content' => $content,
            'options' => $options,
            'scheduled_time' => strtotime($datetime),
            'created_at' => time(),
            'status' => 'pending',
        );

        $scheduled_tweets[] = $tweet_data;
        update_option('rawwire_scheduled_tweets', $scheduled_tweets);

        // Schedule the cron event if not already scheduled
        if (!wp_next_scheduled('rawwire_process_scheduled_tweets')) {
            wp_schedule_event(time(), 'hourly', 'rawwire_process_scheduled_tweets');
        }

        $this->log('Tweet scheduled', 'info', array(
            'tweet_id' => $tweet_data['id'],
            'scheduled_time' => $datetime,
        ));

        return array(
            'success' => true,
            'id' => $tweet_data['id'],
            'scheduled_time' => $datetime,
            'status' => 'scheduled',
        );
    }

    /**
     * Upload media to Twitter
     */
    public function upload_media($file, array $options = array()) {
        try {
            // Download file if URL
            $temp_file = null;
            if (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
                $temp_file = download_url($file);
                if (is_wp_error($temp_file)) {
                    return $temp_file;
                }
                $file_path = $temp_file;
                $filename = basename(parse_url($file, PHP_URL_PATH));
            } elseif (is_array($file) && isset($file['tmp_name'])) {
                $file_path = $file['tmp_name'];
                $filename = $file['name'] ?? 'media';
            } elseif (is_string($file) && file_exists($file)) {
                $file_path = $file;
                $filename = basename($file);
            } else {
                return new WP_Error('invalid_file', 'Invalid file input');
            }

            $file_size = filesize($file_path);
            $mime_type = mime_content_type($file_path);

            // Determine media category
            $media_category = 'tweet_image';
            if (strpos($mime_type, 'video') !== false) {
                $media_category = 'tweet_video';
            } elseif (strpos($mime_type, 'gif') !== false) {
                $media_category = 'tweet_gif';
            }

            // For small files (< 5MB), use simple upload
            // For larger files, use chunked upload
            if ($file_size < 5 * 1024 * 1024 && $media_category === 'tweet_image') {
                $result = $this->upload_media_simple($file_path, $mime_type);
            } else {
                $result = $this->upload_media_chunked($file_path, $file_size, $mime_type, $media_category);
            }

            // Clean up temp file
            if ($temp_file) {
                @unlink($temp_file);
            }

            return $result;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('upload_failed', $e->getMessage());
        }
    }

    /**
     * Simple media upload (for images < 5MB)
     */
    protected function upload_media_simple(string $file_path, string $mime_type) {
        $url = $this->upload_base . '/media/upload.json';

        $oauth = $this->generate_oauth_signature('POST', $url, array());

        $boundary = wp_generate_password(24, false);
        $body = '';

        // Add media field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"media\"; filename=\"media\"\r\n";
        $body .= "Content-Type: {$mime_type}\r\n\r\n";
        $body .= file_get_contents($file_path) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Authorization' => $this->build_oauth_header($oauth),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
            'timeout' => 60,
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error('upload_failed', $data['errors'][0]['message'] ?? 'Upload failed');
        }

        return array(
            'success' => true,
            'id' => $data['media_id_string'] ?? '',
        );
    }

    /**
     * Chunked media upload (for videos and large files)
     */
    protected function upload_media_chunked(string $file_path, int $file_size, string $mime_type, string $media_category) {
        $url = $this->upload_base . '/media/upload.json';

        // INIT
        $init_params = array(
            'command' => 'INIT',
            'total_bytes' => $file_size,
            'media_type' => $mime_type,
            'media_category' => $media_category,
        );

        $oauth = $this->generate_oauth_signature('POST', $url, $init_params);

        $init_response = wp_remote_post($url, array(
            'headers' => array('Authorization' => $this->build_oauth_header($oauth)),
            'body' => $init_params,
            'timeout' => 30,
        ));

        if (is_wp_error($init_response)) {
            return $init_response;
        }

        $init_data = json_decode(wp_remote_retrieve_body($init_response), true);
        $media_id = $init_data['media_id_string'] ?? null;

        if (!$media_id) {
            return new WP_Error('init_failed', 'Failed to initialize media upload');
        }

        // APPEND (chunks)
        $chunk_size = 1 * 1024 * 1024; // 1MB chunks
        $handle = fopen($file_path, 'rb');
        $segment = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);

            $append_params = array(
                'command' => 'APPEND',
                'media_id' => $media_id,
                'segment_index' => $segment,
            );

            $boundary = wp_generate_password(24, false);
            $body = '';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"media\"\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= $chunk . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $oauth = $this->generate_oauth_signature('POST', $url, $append_params);

            $append_url = $url . '?' . http_build_query($append_params);

            $append_response = wp_remote_post($append_url, array(
                'headers' => array(
                    'Authorization' => $this->build_oauth_header($oauth),
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ),
                'body' => $body,
                'timeout' => 120,
            ));

            if (is_wp_error($append_response)) {
                fclose($handle);
                return $append_response;
            }

            $segment++;
        }

        fclose($handle);

        // FINALIZE
        $finalize_params = array(
            'command' => 'FINALIZE',
            'media_id' => $media_id,
        );

        $oauth = $this->generate_oauth_signature('POST', $url, $finalize_params);

        $finalize_response = wp_remote_post($url, array(
            'headers' => array('Authorization' => $this->build_oauth_header($oauth)),
            'body' => $finalize_params,
            'timeout' => 30,
        ));

        if (is_wp_error($finalize_response)) {
            return $finalize_response;
        }

        $finalize_data = json_decode(wp_remote_retrieve_body($finalize_response), true);

        // Check for processing status (for videos)
        if (!empty($finalize_data['processing_info'])) {
            $result = $this->wait_for_processing($media_id, $finalize_data['processing_info']);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return array(
            'success' => true,
            'id' => $media_id,
        );
    }

    /**
     * Wait for media processing to complete
     */
    protected function wait_for_processing(string $media_id, array $processing_info) {
        $url = $this->upload_base . '/media/upload.json';
        $max_attempts = 30;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $wait_seconds = $processing_info['check_after_secs'] ?? 5;
            sleep($wait_seconds);

            $status_params = array(
                'command' => 'STATUS',
                'media_id' => $media_id,
            );

            $oauth = $this->generate_oauth_signature('GET', $url, $status_params);

            $status_response = wp_remote_get($url . '?' . http_build_query($status_params), array(
                'headers' => array('Authorization' => $this->build_oauth_header($oauth)),
                'timeout' => 30,
            ));

            if (is_wp_error($status_response)) {
                return $status_response;
            }

            $status_data = json_decode(wp_remote_retrieve_body($status_response), true);
            $processing_info = $status_data['processing_info'] ?? null;

            if (!$processing_info) {
                return true; // Processing complete
            }

            $state = $processing_info['state'] ?? '';

            if ($state === 'succeeded') {
                return true;
            }

            if ($state === 'failed') {
                return new WP_Error('processing_failed', $processing_info['error']['message'] ?? 'Media processing failed');
            }

            $attempt++;
        }

        return new WP_Error('processing_timeout', 'Media processing timed out');
    }
}
