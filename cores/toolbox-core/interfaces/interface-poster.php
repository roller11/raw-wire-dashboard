<?php
/**
 * Poster Adapter Interface
 * Interface for all content publishing/posting adapters.
 * 
 * @package RawWire_Dashboard
 * @subpackage Toolbox_Core
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/interface-adapter.php';

interface RawWire_Poster_Interface extends RawWire_Adapter_Interface {
    /**
     * Publish content to the destination
     * 
     * @param array $content Content data (title, body, media, etc.)
     * @param array $options Publishing options (status, schedule, etc.)
     * @return array|WP_Error {success: bool, id?: string, url?: string}
     */
    public function publish(array $content, array $options = array());

    /**
     * Update existing published content
     * 
     * @param string $id The ID of the post/content to update
     * @param array $content Updated content data
     * @param array $options Update options
     * @return array|WP_Error {success: bool, id?: string, url?: string}
     */
    public function update(string $id, array $content, array $options = array());

    /**
     * Delete published content
     * 
     * @param string $id The ID of the post/content to delete
     * @param array $options Delete options (force, etc.)
     * @return array|WP_Error {success: bool, id?: string}
     */
    public function delete(string $id, array $options = array());

    /**
     * Schedule content for future publishing
     * 
     * @param array $content Content data
     * @param string $datetime ISO 8601 datetime or strtotime-compatible string
     * @param array $options Schedule options
     * @return array|WP_Error {success: bool, id?: string, scheduled_time?: string}
     */
    public function schedule(array $content, string $datetime, array $options = array());

    /**
     * Upload media for use in posts
     * 
     * @param mixed $file File path, URL, or array with tmp_name/name
     * @param array $options Upload options (alt_text, caption, etc.)
     * @return array|WP_Error {success: bool, id?: string, url?: string}
     */
    public function upload_media($file, array $options = array());
}
