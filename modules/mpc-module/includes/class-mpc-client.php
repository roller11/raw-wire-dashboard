<?php
if (!defined('ABSPATH')) { exit; }

class RawWire_MPC_Client {
    protected $endpoint;
    public function __construct($endpoint = '') { $this->endpoint = $endpoint; }
    public function request_content($type = 'overview') {
        // Simulated content responses
        $mock = array(
            'overview' => array('type'=>'html','content'=>'<h4>MPC Overview</h4><p>Dynamic from MPC</p>'),
            'controls' => array('type'=>'controls','content'=>'<button class="mpc-btn">Macro A</button>'),
            'code' => array('type'=>'code','content'=>'<?php echo "Hello"; ?>'),
            'image' => array('type'=>'image','content'=>'<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==">')
        );
        return $mock[$type] ?? array('type'=>'text','content'=>'No content');
    }
}
