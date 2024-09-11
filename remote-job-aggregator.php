<?php
/*
Plugin Name: Remote Job Aggregator
Plugin URI: https://bgathuita.com
Description: Fetches remote jobs from various RSS feeds and displays them on your WordPress site
Version: 1.0.1  
Author: Brian Gathuita
Author URI: https://bgathuita.com
*/

include_once plugin_dir_path(__FILE__) . 'job-fetching-functions.php';

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include other plugin files
include_once plugin_dir_path(__FILE__) . 'admin-functions.php';
include_once plugin_dir_path(__FILE__) . 'job-fetching-functions.php';
include_once plugin_dir_path(__FILE__) . 'display-functions.php';
include_once plugin_dir_path(__FILE__) . 'helper-functions.php';

// Add necessary actions and filters
add_action('init', 'rjobs_init');
add_action('admin_menu', 'rjobs_admin_menu');
add_action('admin_enqueue_scripts', 'rjobs_admin_scripts');
add_action('wp_ajax_rjobs_bulk_action', 'rjobs_bulk_action');
add_action('admin_init', 'rjobs_update_plugin'); // Check for update requests
add_action('admin_init', 'rjobs_check_for_update'); // Check for plugin updates

// Register cron job on plugin initialization
function rjobs_init() {
    add_action('rjobs_fetch_jobs_cron', 'rjobs_fetch_jobs_cron');
    rjobs_schedule_cron(); // Ensure cron job is scheduled when plugin initializes
}

// Hook for plugin activation
register_activation_hook(__FILE__, 'rjobs_activate_plugin');
function rjobs_activate_plugin() {
    $current_version = '1.0.1'; // Update this to the current version
    $installed_version = get_option('rjobs_version');

    if ($installed_version !== $current_version) {
        // Perform update actions
        rjobs_update_function(); // Custom function for update tasks
        update_option('rjobs_version', $current_version); // Update the version option
    }

    rjobs_schedule_cron(); // Ensure cron job is scheduled on activation
}

// Hook for plugin deactivation
register_deactivation_hook(__FILE__, 'rjobs_deactivate_plugin');
function rjobs_deactivate_plugin() {
    wp_clear_scheduled_hook('rjobs_fetch_jobs_cron'); // Clear scheduled cron job on deactivation
}

// Function to handle plugin updates
function rjobs_update_function() {
    // Add update logic here if needed
    // Example: Perform database schema updates or other update tasks
}

// Function to handle update requests
function rjobs_update_plugin() {
    if (isset($_GET['action']) && $_GET['action'] === 'update' && isset($_GET['plugin']) && $_GET['plugin'] === 'remote-job-aggregator') {
        // Get the new version from the request or set it manually
        $new_version = isset($_GET['version']) ? sanitize_text_field($_GET['version']) : '1.0.2'; // Example new version

        // Update the plugin version
        update_option('rjobs_version', $new_version);

        // Add any additional update logic here
        // Example: Replace old files with new ones

        // Redirect to the plugins page after update
        wp_redirect(admin_url('plugins.php'));
        exit;
    }
}

// Function to check for plugin updates and prompt the user
function rjobs_check_for_update() {
    $current_version = '1.0.1'; // Set the current version of the plugin
    $installed_version = get_option('rjobs_version');

    if ($installed_version && version_compare($installed_version, $current_version, '<')) {
        add_action('admin_notices', function() use ($installed_version, $current_version) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php echo sprintf(
                    'A new version (%s) of the Remote Job Aggregator plugin is available. Your current version is %s. <a href="%s">Click here to update.</a>',
                    esc_html($current_version),
                    esc_html($installed_version),
                    esc_url(admin_url('plugins.php?action=update&plugin=remote-job-aggregator&version=' . urlencode($current_version)))
                ); ?></p>
            </div>
            <?php
        });
    }
}
