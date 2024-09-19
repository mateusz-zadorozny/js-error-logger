<?php

/**
 * Plugin Name: JavaScript Error Logger
 * Description: Captures JavaScript errors and saves them to the WordPress database.
 * Version: 1.0
 * Author: Mateusz Zadorożny
 * Text Domain: js-error-logger
 * Author URI: https://mpress.cc
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class JSErrorLogger
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'js_error_logs';

        // Plugin activation and deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_plugin']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX handlers
        add_action('wp_ajax_jel_log_error', [$this, 'log_error']);
        add_action('wp_ajax_nopriv_jel_log_error', [$this, 'log_error']);

        // Admin menu
        add_action('admin_menu', [$this, 'admin_menu']);

        // Handle clear all action
        add_action('admin_post_jel_clear_logs', [$this, 'clear_logs']);
    }

    /**
     * Plugin activation: Create custom database table.
     */
    public function activate_plugin()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            message text NOT NULL,
            source text NOT NULL,
            lineno int(11) NOT NULL,
            colno int(11) NOT NULL,
            stack longtext,
            user_agent text NOT NULL,
            ip_address varchar(100) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Plugin deactivation: Drop custom database table.
     */
    public function deactivate_plugin()
    {
        global $wpdb;
        $sql = "DROP TABLE IF EXISTS $this->table_name;";
        $wpdb->query($sql);
    }

    /**
     * Enqueue JavaScript files.
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'jel-error-catcher',
            plugin_dir_url(__FILE__) . 'js/error-catcher.js',
            [],
            '1.0',
            true
        );

        // Pass AJAX URL and nonce to the script
        wp_localize_script('jel-error-catcher', 'jel_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jel_nonce'),
        ]);
    }

    /**
     * Handle AJAX request to log errors.
     */
    public function log_error()
    {
        check_ajax_referer('jel_nonce', 'security');

        global $wpdb;

        $error_data = [
            'timestamp' => current_time('mysql'),
            'message' => isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '',
            'source' => isset($_POST['source']) ? sanitize_text_field($_POST['source']) : '',
            'lineno' => isset($_POST['lineno']) ? intval($_POST['lineno']) : 0,
            'colno' => isset($_POST['colno']) ? intval($_POST['colno']) : 0,
            'stack' => isset($_POST['stack']) ? sanitize_textarea_field($_POST['stack']) : '',
            'user_agent' => isset($_POST['user_agent']) ? sanitize_text_field($_POST['user_agent']) : '',
            'ip_address' => $_SERVER['REMOTE_ADDR'],
        ];

        $wpdb->insert($this->table_name, $error_data);

        wp_send_json_success();
    }

    /**
     * Add admin menu item.
     */
    public function admin_menu()
    {
        add_menu_page(
            'JS Error Logs',
            'JS Error Logs',
            'manage_options',
            'jel-error-logs',
            [$this, 'error_logs_page'],
            'dashicons-warning',
            100
        );
    }

    /**
     * Display error logs in the admin area.
     */
    public function error_logs_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        // Handle messages
        if (isset($_GET['jel_cleared']) && $_GET['jel_cleared'] == '1') {
            echo '<div class="updated notice is-dismissible"><p>All logs have been cleared.</p></div>';
        }

        $errors = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY timestamp DESC", ARRAY_A);

        echo '<div class="wrap"><h1>JavaScript Error Logs</h1>';

        // Add "Clear All" button
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="jel_clear_logs">';
        wp_nonce_field('jel_clear_logs_action', 'jel_clear_logs_nonce');
        echo '<p><input type="submit" class="button button-secondary" value="Clear All Logs" onclick="return confirm(\'Are you sure you want to clear all logs?\');"></p>';
        echo '</form>';

        if (empty($errors)) {
            echo '<p>No errors logged.</p></div>';
            return;
        }

        // Add custom styles
        echo '<style>
        .jel-stack-trace {
            max-width: 400px;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        .jel-table th, .jel-table td {
            vertical-align: top;
        }
    </style>';

        echo '<table class="widefat fixed jel-table" cellspacing="0">';
        echo '<thead><tr>
            <th scope="col">Time</th>
            <th scope="col">Message</th>
            <th scope="col">Source</th>
            <th scope="col">Line</th>
            <th scope="col">Column</th>
            <th scope="col">Stack</th>
            <th scope="col">User Agent</th>
            <th scope="col">IP Address</th>
        </tr></thead>';
        echo '<tbody>';

        foreach ($errors as $error) {
            echo '<tr>';
            echo '<td>' . esc_html($error['timestamp']) . '</td>';
            echo '<td>' . esc_html($error['message']) . '</td>';
            echo '<td>' . esc_html($error['source']) . '</td>';
            echo '<td>' . esc_html($error['lineno']) . '</td>';
            echo '<td>' . esc_html($error['colno']) . '</td>';
            echo '<td class="jel-stack-trace">' . esc_html($error['stack']) . '</td>';
            echo '<td>' . esc_html($error['user_agent']) . '</td>';
            echo '<td>' . esc_html($error['ip_address']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Clear all logs from the database.
     */
    public function clear_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        // Verify nonce
        if (!isset($_POST['jel_clear_logs_nonce']) || !wp_verify_nonce($_POST['jel_clear_logs_nonce'], 'jel_clear_logs_action')) {
            wp_die('Nonce verification failed');
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE $this->table_name");

        // Redirect back to the logs page with a success message
        wp_redirect(add_query_arg('jel_cleared', '1', admin_url('admin.php?page=jel-error-logs')));
        exit;
    }
}

new JSErrorLogger();