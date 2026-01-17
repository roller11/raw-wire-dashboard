<?php
/** panel template for mpc-module */
if (!isset($content_for_tpl)) $content_for_tpl = array('type'=>'text','content'=>'');
$type = $content_for_tpl['type'] ?? 'text';
$data = $content_for_tpl['content'] ?? '';
switch($type) {
    case 'html': echo $data; break;
    case 'code': echo '<pre><code>' . esc_html($data) . '</code></pre>'; break;
    case 'image': echo $data; break;
    case 'controls': echo '<div class="mpc-controls">' . $data . '</div>'; break;
    default: echo '<div class="mpc-text">' . esc_html($data) . '</div>';
}
