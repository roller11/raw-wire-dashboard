<?php
/**
 * Discord Webhook Poster Adapter (Flagship Tier)
 * 
 * Posts content to Discord channels via webhooks.
 * Supports rich embeds, file attachments, and thread creation.
 * 
 * @package RawWire\Toolbox\Adapters\Posters
 * @since 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

class RawWire_Poster_Discord extends RawWire_Adapter_Base implements RawWire_Poster_Interface {

    protected $name = 'Discord Webhook';
    protected $version = '1.0.0';
    protected $tier = 'flagship';
    protected $capabilities = array(
        'text_posts',
        'rich_embeds',
        'media_upload',
        'multiple_embeds',
        'custom_username',
        'custom_avatar',
        'mentions',
        'threads',
        'components',
        'edit',
        'delete',
    );
    protected $required_fields = array('webhook_url');

    /**
     * Validate configuration
     */
    public function validate_config(array $config) {
        if (empty($config['webhook_url'])) {
            $this->last_error = 'Webhook URL is required';
            return false;
        }

        // Validate webhook URL format
        if (!preg_match('/^https:\/\/discord\.com\/api\/webhooks\/\d+\/[\w-]+$/', $config['webhook_url'])) {
            $this->last_error = 'Invalid Discord webhook URL format';
            return false;
        }

        return true;
    }

    /**
     * Test connection by getting webhook info
     */
    public function test_connection() {
        try {
            $response = wp_remote_get($this->config['webhook_url'], array(
                'timeout' => 15,
            ));

            if (is_wp_error($response)) {
                $this->last_error = $response->get_error_message();
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $this->last_error = $body['message'] ?? 'Failed to verify webhook';
                return false;
            }

            $this->log('Discord webhook verified', 'info', array(
                'webhook_id' => $body['id'] ?? '',
                'channel_id' => $body['channel_id'] ?? '',
                'guild_id' => $body['guild_id'] ?? '',
                'name' => $body['name'] ?? '',
            ));

            return true;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Publish a message to Discord
     */
    public function publish(array $content, array $options = array()) {
        try {
            $payload = $this->build_payload($content, $options);

            // Check if we have files to upload
            if (!empty($content['files'])) {
                return $this->publish_with_files($payload, $content['files'], $options);
            }

            $url = $this->config['webhook_url'];
            
            // Add query parameters
            $query_params = array();
            if (!empty($options['wait'])) {
                $query_params['wait'] = 'true';
            }
            if (!empty($options['thread_id'])) {
                $query_params['thread_id'] = $options['thread_id'];
            }
            if (!empty($query_params)) {
                $url .= '?' . http_build_query($query_params);
            }

            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $this->last_error = $response->get_error_message();
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code >= 400) {
                $error_message = $body['message'] ?? 'Discord API error';
                $this->log('Discord publish failed', 'error', array(
                    'code' => $code,
                    'error' => $error_message,
                    'errors' => $body['errors'] ?? array(),
                ));
                return new WP_Error('discord_error', $error_message);
            }

            $message_id = $body['id'] ?? null;

            $this->log('Message published to Discord', 'info', array(
                'message_id' => $message_id,
                'channel_id' => $body['channel_id'] ?? '',
            ));

            return array(
                'success' => true,
                'id' => $message_id,
                'data' => $body,
            );
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log('Discord publish exception', 'error', array('error' => $e->getMessage()));
            return new WP_Error('publish_failed', $e->getMessage());
        }
    }

    /**
     * Build webhook payload
     */
    protected function build_payload(array $content, array $options = array()) {
        $payload = array();

        // Basic content
        if (!empty($content['content']) || !empty($content['text'])) {
            $text = $content['content'] ?? $content['text'];
            // Discord limit is 2000 characters
            $payload['content'] = mb_substr($text, 0, 2000);
        }

        // Custom username and avatar
        if (!empty($options['username']) || !empty($this->config['default_username'])) {
            $payload['username'] = $options['username'] ?? $this->config['default_username'];
        }
        if (!empty($options['avatar_url']) || !empty($this->config['default_avatar'])) {
            $payload['avatar_url'] = $options['avatar_url'] ?? $this->config['default_avatar'];
        }

        // Text-to-speech
        if (!empty($options['tts'])) {
            $payload['tts'] = true;
        }

        // Embeds
        if (!empty($content['embeds'])) {
            $payload['embeds'] = $this->build_embeds((array) $content['embeds']);
        } elseif (!empty($content['embed'])) {
            $payload['embeds'] = $this->build_embeds(array($content['embed']));
        }

        // Allowed mentions
        if (isset($content['allowed_mentions'])) {
            $payload['allowed_mentions'] = $content['allowed_mentions'];
        } elseif (!empty($this->config['suppress_mentions'])) {
            $payload['allowed_mentions'] = array('parse' => array());
        }

        // Components (buttons, select menus)
        if (!empty($content['components'])) {
            $payload['components'] = $content['components'];
        }

        // Flags
        if (!empty($options['flags'])) {
            $payload['flags'] = $options['flags'];
        }

        // Thread name (for forum channels)
        if (!empty($options['thread_name'])) {
            $payload['thread_name'] = mb_substr($options['thread_name'], 0, 100);
        }

        return $payload;
    }

    /**
     * Build embeds array
     */
    protected function build_embeds(array $embeds) {
        $built = array();

        foreach (array_slice($embeds, 0, 10) as $embed) {
            $e = array();

            // Title (max 256 chars)
            if (!empty($embed['title'])) {
                $e['title'] = mb_substr($embed['title'], 0, 256);
            }

            // Description (max 4096 chars)
            if (!empty($embed['description'])) {
                $e['description'] = mb_substr($embed['description'], 0, 4096);
            }

            // URL
            if (!empty($embed['url'])) {
                $e['url'] = esc_url($embed['url']);
            }

            // Timestamp (ISO8601)
            if (!empty($embed['timestamp'])) {
                $e['timestamp'] = $embed['timestamp'];
            } elseif (!empty($embed['include_timestamp'])) {
                $e['timestamp'] = gmdate('c');
            }

            // Color (decimal integer)
            if (!empty($embed['color'])) {
                if (is_string($embed['color']) && strpos($embed['color'], '#') === 0) {
                    $e['color'] = hexdec(ltrim($embed['color'], '#'));
                } else {
                    $e['color'] = absint($embed['color']);
                }
            }

            // Footer
            if (!empty($embed['footer'])) {
                $e['footer'] = array(
                    'text' => mb_substr($embed['footer']['text'] ?? $embed['footer'], 0, 2048),
                );
                if (!empty($embed['footer']['icon_url'])) {
                    $e['footer']['icon_url'] = $embed['footer']['icon_url'];
                }
            }

            // Author
            if (!empty($embed['author'])) {
                $e['author'] = array(
                    'name' => mb_substr($embed['author']['name'] ?? '', 0, 256),
                );
                if (!empty($embed['author']['url'])) {
                    $e['author']['url'] = $embed['author']['url'];
                }
                if (!empty($embed['author']['icon_url'])) {
                    $e['author']['icon_url'] = $embed['author']['icon_url'];
                }
            }

            // Image
            if (!empty($embed['image'])) {
                $e['image'] = array(
                    'url' => is_string($embed['image']) ? $embed['image'] : ($embed['image']['url'] ?? ''),
                );
            }

            // Thumbnail
            if (!empty($embed['thumbnail'])) {
                $e['thumbnail'] = array(
                    'url' => is_string($embed['thumbnail']) ? $embed['thumbnail'] : ($embed['thumbnail']['url'] ?? ''),
                );
            }

            // Fields (max 25)
            if (!empty($embed['fields'])) {
                $e['fields'] = array();
                foreach (array_slice($embed['fields'], 0, 25) as $field) {
                    $e['fields'][] = array(
                        'name' => mb_substr($field['name'] ?? '', 0, 256),
                        'value' => mb_substr($field['value'] ?? '', 0, 1024),
                        'inline' => !empty($field['inline']),
                    );
                }
            }

            if (!empty($e)) {
                $built[] = $e;
            }
        }

        return $built;
    }

    /**
     * Publish with file attachments using multipart form
     */
    protected function publish_with_files(array $payload, array $files, array $options = array()) {
        $url = $this->config['webhook_url'];
        
        $query_params = array();
        if (!empty($options['wait'])) {
            $query_params['wait'] = 'true';
        }
        if (!empty($options['thread_id'])) {
            $query_params['thread_id'] = $options['thread_id'];
        }
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        $boundary = wp_generate_password(24, false);
        $body = '';

        // Add payload_json
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"payload_json\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode($payload) . "\r\n";

        // Add files
        $file_index = 0;
        foreach ($files as $file) {
            $file_content = '';
            $filename = '';

            if (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
                $temp = download_url($file);
                if (!is_wp_error($temp)) {
                    $file_content = file_get_contents($temp);
                    $filename = basename(parse_url($file, PHP_URL_PATH));
                    @unlink($temp);
                }
            } elseif (is_string($file) && file_exists($file)) {
                $file_content = file_get_contents($file);
                $filename = basename($file);
            } elseif (is_array($file)) {
                if (!empty($file['content'])) {
                    $file_content = $file['content'];
                } elseif (!empty($file['path']) && file_exists($file['path'])) {
                    $file_content = file_get_contents($file['path']);
                } elseif (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
                    $file_content = file_get_contents($file['tmp_name']);
                }
                $filename = $file['name'] ?? $file['filename'] ?? 'file' . $file_index;
            }

            if (!empty($file_content)) {
                $mime_type = 'application/octet-stream';
                if (function_exists('mime_content_type') && is_string($file) && file_exists($file)) {
                    $mime_type = mime_content_type($file);
                }

                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"files[{$file_index}]\"; filename=\"{$filename}\"\r\n";
                $body .= "Content-Type: {$mime_type}\r\n\r\n";
                $body .= $file_content . "\r\n";

                $file_index++;
            }
        }

        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_response = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error('discord_error', $body_response['message'] ?? 'Upload failed');
        }

        return array(
            'success' => true,
            'id' => $body_response['id'] ?? null,
            'data' => $body_response,
        );
    }

    /**
     * Update an existing message (requires message ID)
     */
    public function update(string $id, array $content, array $options = array()) {
        try {
            $url = $this->config['webhook_url'] . '/messages/' . $id;
            
            if (!empty($options['thread_id'])) {
                $url .= '?thread_id=' . $options['thread_id'];
            }

            $payload = $this->build_payload($content, $options);

            $response = wp_remote_request($url, array(
                'method' => 'PATCH',
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code >= 400) {
                return new WP_Error('discord_error', $body['message'] ?? 'Update failed');
            }

            $this->log('Discord message updated', 'info', array('message_id' => $id));

            return array(
                'success' => true,
                'id' => $id,
                'data' => $body,
            );
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('update_failed', $e->getMessage());
        }
    }

    /**
     * Delete a message
     */
    public function delete(string $id, array $options = array()) {
        try {
            $url = $this->config['webhook_url'] . '/messages/' . $id;
            
            if (!empty($options['thread_id'])) {
                $url .= '?thread_id=' . $options['thread_id'];
            }

            $response = wp_remote_request($url, array(
                'method' => 'DELETE',
                'timeout' => 15,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ($code >= 400) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return new WP_Error('discord_error', $body['message'] ?? 'Delete failed');
            }

            $this->log('Discord message deleted', 'info', array('message_id' => $id));

            return array(
                'success' => true,
                'id' => $id,
            );
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            return new WP_Error('delete_failed', $e->getMessage());
        }
    }

    /**
     * Schedule - Discord doesn't support native scheduling
     * Store for cron processing
     */
    public function schedule(array $content, string $datetime, array $options = array()) {
        $scheduled = get_option('rawwire_scheduled_discord', array());

        $message_data = array(
            'id' => uniqid('discord_'),
            'content' => $content,
            'options' => $options,
            'webhook_url' => $this->config['webhook_url'],
            'scheduled_time' => strtotime($datetime),
            'created_at' => time(),
            'status' => 'pending',
        );

        $scheduled[] = $message_data;
        update_option('rawwire_scheduled_discord', $scheduled);

        if (!wp_next_scheduled('rawwire_process_scheduled_discord')) {
            wp_schedule_event(time(), 'hourly', 'rawwire_process_scheduled_discord');
        }

        $this->log('Discord message scheduled', 'info', array(
            'message_id' => $message_data['id'],
            'scheduled_time' => $datetime,
        ));

        return array(
            'success' => true,
            'id' => $message_data['id'],
            'scheduled_time' => $datetime,
            'status' => 'scheduled',
        );
    }

    /**
     * Upload media - Discord handles this via multipart publish
     */
    public function upload_media($file, array $options = array()) {
        // Discord doesn't have separate media upload - files are sent with messages
        // Return a structure that can be used with publish
        
        $file_data = array();

        if (is_string($file) && filter_var($file, FILTER_VALIDATE_URL)) {
            $temp = download_url($file);
            if (is_wp_error($temp)) {
                return $temp;
            }
            $file_data = array(
                'path' => $temp,
                'name' => basename(parse_url($file, PHP_URL_PATH)),
                'temp' => true,
            );
        } elseif (is_string($file) && file_exists($file)) {
            $file_data = array(
                'path' => $file,
                'name' => basename($file),
            );
        } elseif (is_array($file) && isset($file['tmp_name'])) {
            $file_data = array(
                'path' => $file['tmp_name'],
                'name' => $file['name'] ?? 'file',
            );
        } else {
            return new WP_Error('invalid_file', 'Invalid file input');
        }

        return array(
            'success' => true,
            'file' => $file_data,
            'note' => 'Use this file data in the publish() files array',
        );
    }

    /**
     * Send a notification with predefined styling
     */
    public function send_notification(string $type, string $title, string $message, array $extra = array()) {
        $colors = array(
            'success' => '#57F287',
            'warning' => '#FEE75C',
            'error' => '#ED4245',
            'info' => '#5865F2',
        );

        $icons = array(
            'success' => '✅',
            'warning' => '⚠️',
            'error' => '❌',
            'info' => 'ℹ️',
        );

        $content = array(
            'embed' => array(
                'title' => ($icons[$type] ?? '') . ' ' . $title,
                'description' => $message,
                'color' => $colors[$type] ?? $colors['info'],
                'include_timestamp' => true,
            ),
        );

        // Add fields from extra
        if (!empty($extra['fields'])) {
            $content['embed']['fields'] = $extra['fields'];
        }

        // Add footer
        if (!empty($extra['footer'])) {
            $content['embed']['footer'] = array('text' => $extra['footer']);
        }

        return $this->publish($content, $extra['options'] ?? array());
    }
}
