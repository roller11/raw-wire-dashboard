<?php
if (!defined("ABSPATH")) { exit; }
function rawwire_v111_sanitize_text($value){ return is_string($value)?sanitize_text_field($value):""; }
function rawwire_v111_escape_html($value){ return esc_html((string)$value); }
function rawwire_v111_get_option(string $key,$default=""){ $val=get_option($key,$default); return is_string($val)?$val:$default; }
function rawwire_v111_current_user_can_manage(): bool { return current_user_can("manage_options"); }
