<?php
/*
Plugin Name: Remote Job Aggregator
Plugin URI: https://bgathuita.com
Description: Fetches remote jobs from various RSS feeds and custom links, and displays them on your WordPress site.
Version: 1.0.1
Author: Brian Gathuita
Author URI: https://bgathuita.com
RequiresWP: 5.8
RequiresPHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

include_once plugin_dir_path(__FILE__) . 'job-fetching-functions.php';
include_once plugin_dir_path(__FILE__) . 'admin-functions.php';
include_once plugin_dir_path(__FILE__) . 'display-functions.php';
include_once plugin_dir_path(__FILE__) . 'helper-functions.php';

// Add necessary actions
add_action('init', 'rjobs_init');
add_action('admin_menu', 'rjobs_admin_menu');
add_action('admin_enqueue_scripts', 'rjobs_admin_scripts');
add_action('wp_ajax_rjobs_bulk_action', 'rjobs_bulk_action');
add_action('admin_init', 'rjobs_check_for_update'); // Check for plugin updates
add_filter('auto_update_plugin', 'rjobs_enable_auto_update', 10, 2); // Enable auto-updates

// Plugin activation hook
register_activation_hook(__FILE__, 'rjobs_activate_plugin');
function rjobs_activate_plugin() {
    $current_version = rjobs_get_current_version(); // Dynamically get current version
    $installed_version = get_option('rjobs_version');

    if ($installed_version !== $current_version) {
        // Perform update actions if necessary
        rjobs_update_function(); // Custom function for update tasks
        update_option('rjobs_version', $current_version); // Update the stored version to the current version
    }

    rjobs_schedule_cron(); // Schedule cron job
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'rjobs_deactivate_plugin');
function rjobs_deactivate_plugin() {
    wp_clear_scheduled_hook('rjobs_fetch_jobs_cron'); // Clear scheduled cron jobs on deactivation
}

// Fetch current plugin version dynamically from the plugin header
function rjobs_get_current_version() {
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
    return $plugin_data['Version'];
}

// Function to handle plugin updates
function rjobs_update_function() {
    // Add update logic if necessary
    // Example: Update database schema or perform migration tasks
}

// Function to check for plugin updates and prompt the user
function rjobs_check_for_update() {
    $current_version = rjobs_get_current_version(); // Dynamically get the current version from the plugin header
    $installed_version = get_option('rjobs_version');

    if (version_compare($installed_version, $current_version, '<')) {
        // Display admin notice to prompt the user to update
        add_action('admin_notices', function() use ($installed_version, $current_version) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php echo sprintf(
                    'A new version of the Remote Job Aggregator plugin is available. Your current version is %s. <a href="%s">Click here to update to version %s.</a>',
                    esc_html($installed_version),
                    esc_url(admin_url('plugins.php?action=update&plugin=remote-job-aggregator&version=' . urlencode($current_version))),
                    esc_html($current_version)
                ); ?></p>
            </div>
            <?php
        });
    }

    // Handle the update request when the user clicks the update link
    if (isset($_GET['action']) && $_GET['action'] === 'update' && isset($_GET['plugin']) && $_GET['plugin'] === 'remote-job-aggregator') {
        // Get the new version from the request (fallback to the current version if not provided)
        $new_version = isset($_GET['version']) ? sanitize_text_field($_GET['version']) : $current_version;

        // Update the plugin version in the database
        update_option('rjobs_version', $new_version);

        // Add any additional update logic here (e.g., replacing files, migrating data)

        // Redirect to the plugins page after the update
        wp_redirect(admin_url('plugins.php?update=success'));
        exit;
    }
}

// Multisite Network Support for Updates
if (is_multisite()) {
    add_action('network_admin_menu', 'rjobs_network_admin_menu');
    add_action('network_admin_init', 'rjobs_network_check_for_update');

    function rjobs_network_admin_menu() {
        // Add a network admin menu item if needed
    }

    function rjobs_network_check_for_update() {
        $current_version = rjobs_get_current_version(); // Dynamically get the current version from the plugin header
        $installed_version = get_site_option('rjobs_version');
        
        if (version_compare($installed_version, $current_version, '<')) {
            // Display network admin notice for update
            add_action('network_admin_notices', function() use ($installed_version, $current_version) {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php echo sprintf(
                        'A new version of the Remote Job Aggregator plugin is available. Your current version is %s. <a href="%s">Click here to update to version %s.</a>',
                        esc_html($installed_version),
                        esc_url(network_admin_url('plugins.php?action=update&plugin=remote-job-aggregator&version=' . urlencode($current_version))),
                        esc_html($current_version)
                    ); ?></p>
                </div>
                <?php
            });
        }

        // Handle the update request
        if (isset($_GET['action']) && $_GET['action'] === 'update' && isset($_GET['plugin']) && $_GET['plugin'] === 'remote-job-aggregator') {
            // Get the new version from the request
            $new_version = isset($_GET['version']) ? sanitize_text_field($_GET['version']) : $current_version;

            // Update the plugin version in the network options
            update_site_option('rjobs_version', $new_version);

            // Redirect to the network plugins page after the update
            wp_redirect(network_admin_url('plugins.php?update=success'));
            exit;
        }
    }
}

// Function for plugin initialization
function rjobs_init() {
    // Your initialization code here
    add_action('rjobs_fetch_jobs_cron', 'rjobs_fetch_jobs_cron');
    rjobs_schedule_cron(); // Example: Schedule cron job on init
}

// Function to enable auto-updates for the plugin
function rjobs_enable_auto_update($update, $item) {
    if (isset($item->slug) && $item->slug === 'remote-job-aggregator') {
        return true; // Enable auto-updates for the Remote Job Aggregator plugin
    }
    return $update;
}

// Function to check for existing folders starting with "Remote-Jobs-Aggregator"
add_filter('upgrader_pre_install', 'rjobs_pre_install', 10, 3);

function rjobs_pre_install($response, $hook_extra = null, $result = null) {
    // The uploaded plugin directory
    $plugin_dir = WP_PLUGIN_DIR;

    // Check for existing plugin folders that start with 'Remote-Jobs-Aggregator'
    $plugin_name_base = 'Remote-Jobs-Aggregator';

    $existing_folders = glob($plugin_dir . '/' . $plugin_name_base . '*', GLOB_ONLYDIR);

    // If any folder exists with a similar naming convention, prompt the replacement
    if (!empty($existing_folders)) {
        // Add admin notice for replacement prompt
        add_action('admin_notices', function() use ($existing_folders) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php echo sprintf(
                    'A folder starting with "Remote-Jobs-Aggregator" already exists: %s. Please ensure you want to replace it.',
                    implode(', ', array_map('basename', $existing_folders))
                ); ?></p>
            </div>
            <?php
        });

        // Halt the installation
        return new WP_Error('plugin_exists', 'Plugin with a similar name already exists.');
    }

    return $response;
}
