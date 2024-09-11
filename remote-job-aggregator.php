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

// Register cron job on plugin initialization
function rjobs_init() {
    add_action('rjobs_fetch_jobs_cron', 'rjobs_fetch_jobs_cron');
    rjobs_schedule_cron(); // Ensure cron job is scheduled when plugin initializes
}

// Hook for plugin activation
register_activation_hook(__FILE__, 'rjobs_activate_plugin');
function rjobs_activate_plugin() {
    rjobs_schedule_cron(); // Ensure cron job is scheduled on activation
}

// Hook for plugin deactivation
register_deactivation_hook(__FILE__, 'rjobs_deactivate_plugin');
function rjobs_deactivate_plugin() {
    wp_clear_scheduled_hook('rjobs_fetch_jobs_cron'); // Clear scheduled cron job on deactivation
}
