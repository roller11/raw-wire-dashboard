<?php
if (!defined("ABSPATH")) { exit; }
class RawWire_Logger {
  public static function log_activity(string $message,string $level="info",array $context=[]): void {
    $line=sprintf("[RawWire][%s] %s %s",strtoupper($level),$message,json_encode($context));
    if(defined("WP_DEBUG_LOG") && WP_DEBUG_LOG){ error_log($line); }
    do_action("rawwire_log_activity",$message,$level,$context);
  }
  public static function log_error(string $message,array $context=[]): void { self::log_activity($message,"error",$context); }
}
