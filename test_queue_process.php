<?php
// Mock WordPress environment for testing
namespace { // Global namespace for functions and main script
    define('ABSPATH', __DIR__ . '/');
    define('PW_PLUGIN_DIR', __DIR__ . '/');
    define('PW_VERSION', '3.4.0');
    define('ARRAY_A', 'ARRAY_A');

    // Mock classes
    class WP_Error {
        public function get_error_message() { return 'error'; }
    }
    // DateTime is built-in, do not mock

    // Mock Functions
    function get_option($key, $default = false) {
        if ($key === 'pw_sending_enabled' || $key === 'pw_settings') return ['sending_enabled' => true];
        // Need to check Settings::get logic
        return $default;
    }
    function update_option($key, $val) {}
    function current_time($t) {
        if ($t === 'mysql') return date('Y-m-d H:i:s');
        if ($t === 'timestamp') return time();
        if ($t === 'w') return date('w');
        if ($t === 'H') return date('H');
        return time();
    }
    function wp_timezone_string() { return 'UTC'; }
    function get_transient($k) { return false; }
    function set_transient($k, $v, $t) {}
    function delete_transient($k) {}
    // Remove rand() redeclaration as it is built-in
    function wp_parse_args($a, $b) { return array_merge($b, $a); }
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; }
    function wp_mkdir_p($dir) { return true; }
    function is_wp_error($t) { return false; }
    function wp_remote_post($url, $args) { return ['response' => ['code' => 200], 'body' => '{"status":"success","data":{"message_id":"123"}}']; }
    function wp_remote_retrieve_response_code($r) { return 200; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function __($s) { return $s; }
    function do_action($h) {}
    function apply_filters($h, $v) { return $v; }
    function sanitize_text_field($s) { return $s; }

    // Mock Database
    class MockDB {
        public $prefix = 'wp_';
        public $last_query;
        public function prepare($sql, ...$args) {
            // Simple replace for debugging
            foreach($args as $arg) {
                $sql = preg_replace('/%[sdF]/', "'$arg'", $sql, 1);
            }
            return $sql;
        }
        public function get_var($sql) {
            if (strpos($sql, 'postal_templates') !== false) return 'UTC'; // timezone
            return null;
        }
        public function get_row($sql, $type = 'ARRAY_A') {
            if (strpos($sql, 'postal_servers') !== false) {
                return ['id' => 1, 'domain' => 'server.com', 'active' => 1, 'api_key' => 'key', 'warmup_day' => 1];
            }
            return null;
        }
        public function get_results($sql, $type = 'ARRAY_A') {
            // Return pending items
            if (strpos($sql, "status = 'pending'") !== false) {
                echo "DB Query: SELECT pending items\n";
                return [
                    [
                        'id' => 1,
                        'template_id' => 1,
                        'isp' => 'gmail',
                        'to_email' => 'test@gmail.com',
                        'meta' => '{"prefix":"contact"}',
                        'attempts' => 0
                    ]
                ];
            }
            if (strpos($sql, 'postal_servers') !== false) {
                return [['id' => 1, 'domain' => 'server.com', 'active' => 1, 'api_key' => 'key', 'warmup_day' => 1, 'daily_limit' => 1000]];
            }
            return [];
        }
        public function update($table, $data, $where) {
            if (isset($data['status'])) echo "DB UPDATE: Item " . ($where['id'] ?? '?') . " status -> " . $data['status'] . "\n";
        }
        public function insert($table, $data) { echo "DB INSERT: $table\n"; return 1; }
        public function query($sql) {}
    }
    global $wpdb;
    $wpdb = new MockDB();
}

// Mock Namespaced Classes
namespace PostalWarmup\Services {
    class Encryption {
        public static function decrypt($s) { return $s; }
    }
    class ISPDetector {
        public static function detect($e) { return 'gmail'; }
    }
    class StrategyEngine {
        public static function calculate_daily_limit($s, $d, $i) { return 100; }
        public static function check_safety_rules($s, $stats) { return ['allowed' => true]; }
    }
    class LoadBalancer {
        public static function select_server($t, $c) {
            echo "LoadBalancer: Selecting server for template $t\n";
            return ['id' => 1, 'domain' => 'server.com', 'lb_metrics' => ['warmup_day' => 1]];
        }
    }
}

namespace PostalWarmup\Admin {
    class ISPManager {
        public static function get_by_key($k) { return []; }
    }
    class StrategyManager {}
    class Settings {
        public static function get($key, $default = null) {
            // Mock returning true for sending_enabled
            if ($key === 'sending_enabled') return true;
            if ($key === 'queue_batch_size') return 5;
            if ($key === 'schedule_start_hour') return 0;
            if ($key === 'schedule_end_hour') return 24;
            if ($key === 'api_timeout') return 15;
            if ($key === 'default_from_email') return '';
            if ($key === 'default_from_name') return '';
            if ($key === 'custom_headers') return '';
            if ($key === 'max_retries') return 3;
            if ($key === 'warmup_start') return 10;
            if ($key === 'warmup_increase_percent') return 20;
            if ($key === 'db_optimize_on_purge') return false;
            return $default;
        }
    }
}

namespace PostalWarmup\Core {
    class TemplateEngine {
        public static function prepare_template($p, $d, $pr, $t) {
            return ['name' => 'test', 'subject' => 'Sub', 'text' => 'Body', 'html' => 'Body', 'id' => 1, 'from_name' => 'Me', 'reply_to' => ''];
        }
    }
}

namespace PostalWarmup\Models {
    class Database {
        public static function get_server($id) {
            return ['id' => 1, 'domain' => 'server.com', 'api_key' => 'key', 'api_url' => 'https://api.postal'];
        }
        public static function get_server_by_domain($d) { return self::get_server(1); }
        public static function insert_stat_history($d) {}
        public static function increment_sent($d, $s, $t) {}
        public static function record_stat($s, $ok, $t) {}
    }
}

namespace PostalWarmup\API {
    class Client {
        public static function request($sid, $ep, $meth='GET', $data=[]) {
            return ['message_id' => 'msg-123'];
        }
    }
}

namespace { // Main execution
    // Autoload
    require_once 'src/Services/Logger.php';
    require_once 'src/API/Sender.php';
    require_once 'src/Services/QueueManager.php';
    require_once 'src/Models/Stats.php';

    // Test Script
    echo "Starting Queue Process Test...\n";

    // Simulate Process
    \PostalWarmup\Services\QueueManager::process_queue();

    echo "Test Finished.\n";
}
