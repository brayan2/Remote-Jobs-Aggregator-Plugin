<?php

function rjobs_bulk_action() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized user', 403);
    }

    if (!isset($_POST['rjobs_bulk_action']) || !isset($_POST['rjobs_job_ids'])) {
        wp_send_json_error('Invalid request', 400);
    }

    check_admin_referer('rjobs_bulk_action', 'rjobs_bulk_nonce');

    $action = sanitize_text_field($_POST['rjobs_bulk_action']);
    $job_ids = array_map('absint', $_POST['rjobs_job_ids']);

    switch ($action) {
        case 'delete':
            foreach ($job_ids as $job_id) {
                wp_delete_post($job_id, true);
            }
            wp_send_json_success('Jobs deleted successfully.');
            break;

        default:
            wp_send_json_error('Invalid bulk action.', 400);
            break;
    }
}

// Register AJAX handlers
add_action('wp_ajax_rjobs_bulk_action', 'rjobs_bulk_action');
function rjobs_delete_job_from_plugin($post_id, $company_name, $job_location) {
    global $wpdb;

    // Example: Delete job from your custom plugin table
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}your_plugin_jobs_table WHERE post_id = %d AND company_name = %s AND job_location = %s",
            $post_id,
            $company_name,
            $job_location
        )
    );
}

function rjobs_delete_media_files($post_id) {
    $featured_image_id = get_post_thumbnail_id($post_id);
    if ($featured_image_id) {
        wp_delete_attachment($featured_image_id, true); // True deletes permanently
    }

    // Additional media files handling if necessary
}
if (!function_exists('rjobs_job_already_exists')) {
    function rjobs_job_already_exists($title, $location, $company_name) {
        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}posts
             WHERE post_title = %s
             AND meta_value = %s
             AND meta_value = %s
             AND post_type = 'job_listing'",
            $title,
            $location,
            $company_name
        );

        return $wpdb->get_var($query) > 0;
    }
}
if (!function_exists('is_ajax_loader')) {
    function is_ajax_loader($url) {
        return strpos($url, 'ajax-loader.gif') !== false;
    }
}


function should_skip_image($image_url) {
    // Add logic to determine if the image should be skipped
    // Example:
    // Check if $image_url matches known AJAX loader URLs to exclude
    $ajax_loader_urls = [
        'https://wpremotework.com/wp-content/plugins/page-views-count/ajax-loader-2x.gif',
        // Add more URLs to skip if necessary
    ];

    foreach ($ajax_loader_urls as $loader_url) {
        if (strpos($image_url, $loader_url) !== false) {
            return true; // Skip image
        }
    }

    // Add more conditions as needed to skip images based on specific criteria
    // Example:
    // if (strpos($image_url, 'example.com/invalid-image') !== false) {
    //     return true; // Skip image
    // }

    // If none of the conditions are met, do not skip the image
    return false;
}
function is_ajax_loader($url) {
    // List of known AJAX loader URLs to exclude
    $ajax_loader_urls = [
        'https://wpremotework.com/wp-content/plugins/page-views-count/ajax-loader-2x.gif'
    ];

    foreach ($ajax_loader_urls as $loader_url) {
        if (strpos($url, $loader_url) !== false) {
            return true;
        }
    }
    
    return false;
}
