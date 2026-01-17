<?php
if (!defined("ABSPATH")) { exit; }
function rawwire_v111_rate_limit(string $key, int $limit = 60, int $window = 60): bool {
  $now = time();
  $data = get_transient("rl_" . $key);
  if (!is_array($data)) { $data = ["count" => 0, "reset" => $now + $window]; }
  if ($data["reset"] <= $now) { $data = ["count" => 0, "reset" => $now + $window]; }
  $data["count"]++;
  set_transient("rl_" . $key, $data, $window);
  return $data["count"] <= $limit;
}
