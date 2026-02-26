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
    class WP_REST_Request {
        public $params;
        public function __construct($params) { $this->params = $params; }
        public function get_json_params() { return $this->params; }
        public function get_query_params() { return []; }
    }
    class WP_REST_Response {
        public function __construct($data, $status) {}
    }

    // Mock Functions
    function register_rest_route($ns, $route, $args) {}
    function get_option($key, $default = false) {
        if ($key === 'pw_thread_enabled') return true;
        if ($key === 'pw_thread_max_exchanges') return 3;
        if ($key === 'pw_thread_delay_min') return 1;
        if ($key === 'pw_thread_delay_max') return 2;
        if ($key === 'pw_webhook_secret') return 'secret';
        if ($key === 'pw_settings') return [];
        return $default;
    }
    function update_option($key, $val) {}
    function wp_generate_password() { return 'secret'; }
    function is_wp_error($t) { return false; }
    function wp_remote_request($u, $a) { return []; }
    function wp_remote_retrieve_response_code($r) { return 200; }
    function wp_remote_retrieve_body($r) { return '{}'; }
    function current_time($t) { return date('Y-m-d H:i:s'); }
    function sanitize_text_field($s) { return $s; }
    function sanitize_email($s) { return $s; }
    function absint($n) { return (int)$n; }
    function checked($a, $b) {}
    function selected($a, $b) {}
    function esc_attr($s) { return $s; }
    function esc_html($s) { return $s; }
    function esc_textarea($s) { return $s; }
    function __($s) { return $s; }
    function do_action($h) {}
    function apply_filters($h, $v) { return $v; }
    function get_transient($k) { return false; }
    function set_transient($k, $v, $t) {}
    function get_rest_url() { return ''; }
    function add_query_arg() { return ''; }
    function wp_parse_args($a, $b) { return array_merge($b, $a); }
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir()]; } // Mock for Logger
    function wp_mkdir_p($dir) { return true; }

    // Mock Database
    class MockDB {
        public $prefix = 'wp_';
        public $last_query;
        public $get_row_handler;
        public $get_var_handler;

        public function prepare($sql, ...$args) { return $sql; }
        public function get_var($sql) {
            if ($this->get_var_handler) return ($this->get_var_handler)($sql);
            return null;
        }
        public function get_row($sql, $type = 'ARRAY_A') {
            if ($this->get_row_handler) return ($this->get_row_handler)($sql);
            return null;
        }
        public function get_results($sql, $type = 'ARRAY_A') { return []; }
        public function update($table, $data, $where) { echo "DB UPDATE: $table\n"; }
        public function insert($table, $data) { echo "DB INSERT: $table\n"; return 1; }
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
    class StrategyEngine {}
    class LoadBalancer {
        public static function select_server($t, $c) {
            return ['id' => 1, 'domain' => 'server.com', 'lb_metrics' => []];
        }
    }
}

namespace PostalWarmup\Admin {
    class ISPManager {
        public static function get_by_key($k) { return []; }
    }
    class StrategyManager {}
}

namespace { // Main execution
    // Autoload
    require_once 'src/Services/Logger.php';
    require_once 'src/Services/QueueManager.php';
    require_once 'src/Models/Database.php';
    require_once 'src/Admin/Settings.php';
    require_once 'src/Admin/TemplateManager.php';
    require_once 'src/API/WebhookHandler.php';

    // Test Script
    use PostalWarmup\API\WebhookHandler;

    echo "Starting Test...\n";

    $handler = new WebhookHandler();

    // Mock Payload
    $payload = [
        'rcpt_to' => 'support@server.com', // incoming message (reply)
        'mail_from' => 'user@gmail.com',
        'subject' => 'Help',
        'in_reply_to' => '<parent-id@server.com>',
        'bounce' => false,
        'auto_submitted' => ''
    ];

    // Mock DB for parent finding
    $wpdb->get_row_handler = function($sql) {
        if (strpos($sql, 'postal_stats_history') !== false) {
            // Found parent history
            return [
                'id' => 1,
                'message_id' => '<parent-id@server.com>',
                'server_id' => 1,
                'meta' => json_encode(['template_name' => 'support'])
            ];
        }
        // Mock Server lookup
        if (strpos($sql, 'postal_servers') !== false) {
            return ['id' => 1, 'domain' => 'server.com', 'active' => 1, 'api_key' => 'enc_key'];
        }
        return null;
    };

    // Mock Template ID lookup
    $wpdb->get_var_handler = function($sql) {
        // QueueManager checks template name
        if (strpos($sql, 'postal_templates') !== false && strpos($sql, 'id') !== false) {
            return 123; // Template ID found
        }
        return null;
    };

    // Request
    $request = new \WP_REST_Request($payload);

    // Execute
    $handler->handle_webhook($request);

    echo "Test finished (Check output for DB INSERT).\n";
}
