<?php

include_once(plugin_dir_path(__FILE__) . 'normal-links-fetching-functions.php');
include_once(plugin_dir_path(__FILE__) . 'job-fetching-functions.php');

// Enqueue scripts and styles for the admin pages
function rjobs_admin_scripts()
{
    if (get_current_screen()->id == 'toplevel_page_rjobs-listings') {
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('rjobs-admin-tabs', plugin_dir_url(__FILE__) . 'js/admin-tabs.js', array('jquery'), null, true);
        wp_enqueue_style('rjobs-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.css');
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css');

    }
}
add_action('admin_enqueue_scripts', 'rjobs_admin_scripts');


// Add the admin menu and submenus
function rjobs_admin_menu() {
    // Set the "Settings" page as the main admin page
    add_menu_page(
        'Remote Job Settings', // Page title
        'Remote Jobs',         // Menu title
        'manage_options',      // Capability
        'rjobs-settings',      // Menu slug (this will be the slug for the settings page)
        'rjobs_settings_page', // Function to display the settings page
        'dashicons-admin-site' // Icon for the menu
    );

    // Remove the main admin page by not adding it
    // If you had any other submenus, you can keep only the ones you want
    add_submenu_page(
        'rjobs-settings', // Parent slug (the settings page will be the only item)
        'Job Listings',   // Page title
        'Job Listings',   // Menu title
        'manage_options', // Capability
        'rjobs-listings', // Menu slug
        'rjobs_listings_page' // Function to display the job listings page
    );
}

add_action('admin_menu', 'rjobs_admin_menu');
// Function to display admin notices
function rjobs_display_admin_notices()
{
    $fetching_status = get_transient('rjobs_fetching_status');
    error_log("Fetching status in display function: " . $fetching_status); // Debugging line

    if ($fetching_status === 'stopped') {
        echo '<div class="notice notice-success is-dismissible"><p>Automatic job fetching has been stopped. <a href="' . esc_url(admin_url('admin.php?page=rjobs&action=continue_fetching_jobs&_wpnonce=' . wp_create_nonce('rjobs_control_jobs'))) . '">Resume fetching</a></p></div>';
    } elseif ($fetching_status === 'fetching') {
        echo '<div class="notice notice-info"><p>Automatic job fetching is currently in progress. <a href="' . esc_url(admin_url('admin.php?page=rjobs&action=stop_fetching_jobs&_wpnonce=' . wp_create_nonce('rjobs_control_jobs'))) . '">Stop fetching</a></p></div>';
    } elseif ($fetching_status === 'not_scheduled') {
        echo '<div class="notice notice-info"><p>No jobs are currently being fetched.</p></div>';
    }
}
add_action('admin_notices', 'rjobs_display_admin_notices');

function rjobs_main_settings_page() {
    if (isset($_POST['rjobs_save_main_settings']) && check_admin_referer('rjobs_save_main_settings', 'rjobs_main_settings_nonce')) {
        $num_jobs = absint($_POST['rjobs_num_jobs']);
        $schedule_frequency = sanitize_text_field($_POST['rjobs_schedule_frequency']);
        $fetch_source = sanitize_text_field($_POST['rjobs_fetch_source']); // New field for fetch source

        update_option('rjobs_num_jobs', $num_jobs);
        update_option('rjobs_schedule_frequency', $schedule_frequency);
        update_option('rjobs_fetch_source', $fetch_source); // Save fetch source option

        // Schedule or reschedule cron job based on new settings
        rjobs_schedule_cron();

        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
    }

    $num_jobs = get_option('rjobs_num_jobs', 10);
    $schedule_frequency = get_option('rjobs_schedule_frequency', 'daily');
    $fetch_source = get_option('rjobs_fetch_source', 'rss'); // Default to 'rss'

    echo '<form method="post" action="">';
    wp_nonce_field('rjobs_save_main_settings', 'rjobs_main_settings_nonce');
    echo '<h2>Main Settings</h2>';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="rjobs_num_jobs">Number of Jobs to Fetch</label></th>';
    echo '<td><input type="number" name="rjobs_num_jobs" value="' . esc_attr($num_jobs) . '" class="small-text" min="1"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th scope="row"><label for="rjobs_schedule_frequency">Fetch Frequency</label></th>';
    echo '<td>';
    echo '<select name="rjobs_schedule_frequency">';
    echo '<option value="minutely"' . selected($schedule_frequency, 'minutely', false) . '>Every 1 Minute</option>';
    echo '<option value="half_hourly"' . selected($schedule_frequency, 'half_hourly', false) . '>Every 30 Minutes</option>';
    echo '<option value="hourly"' . selected($schedule_frequency, 'hourly', false) . '>Every 1 Hour</option>';
    echo '<option value="twicedaily"' . selected($schedule_frequency, 'twicedaily', false) . '>Twice Daily</option>';
    echo '<option value="daily"' . selected($schedule_frequency, 'daily', false) . '>Daily</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th scope="row"><label for="rjobs_fetch_source">Fetch Source</label></th>';
    echo '<td>';
    echo '<select name="rjobs_fetch_source">';
    echo '<option value="rss"' . selected($fetch_source, 'rss', false) . '>RSS Feeds</option>';
    echo '<option value="normal_links"' . selected($fetch_source, 'normal_links', false) . '>Normal Links</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    echo '<p class="submit"><input type="submit" name="rjobs_save_main_settings" id="submit" class="button button-primary" value="Save Settings"></p>';
    echo '</form>';
}

function rjobs_normal_links_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'default_tab';

    // Default link data
    $default_links = array(
        array(
            'name' => 'Euremote Jobs',
            'job_list_link' => 'https://euremotejobs.com/job-region/remote-jobs-worldwide/',
            'job_link_classes' => 'job_listing-clickbox',
            'job_title_field' => 'page-title',
            'job_type_field' => 'job-type',
            'job_description_field' => 'col-sm-12',
            'job_company_logo_field' => 'company_logo',
            'job_application_url_field' => 'application_button_link button',
        ),
        array(
            'name' => 'Waivly Jobs',
            'job_list_link' => 'https://work.waivly.com/job-location-category/worldwide',
            'job_link_classes' => 'card job featured w-inline-block',
            'job_title_field' => 'title h2-size card-job-post',
            'job_type_field' => 'card-job-post-category-text',
            'job_description_field' => 'card-job-post-content-bottom',
            'job_company_logo_field' => 'image card-job-post-logo',
            'job_application_url_field' => 'card job featured w-inline-block',
        ),
        array(
            'name' => 'Nodesk Jobs',
            'job_list_link' => 'https://nodesk.co/remote-jobs/remote-first/',
            'job_link_classes' => 'link dim indigo-700',
            'job_title_field' => 'dn db-ns f3 grey-900 lh-title mb9 mt0',
            'job_type_field' => 'link dim grey-700',
            'job_description_field' => 'grey-800',
            'job_company_logo_field' => 'bg-white br-100 lazyload mr4-ns shadow-1 shadow-2-ns w9',
            'job_application_url_field' => 'dib link f8 fw5 dim white bg-indigo-500 br2 pa3 pa4-s ph6-ns pv4-ns shadow-2 tracked-wider ttu w-auto',
        ),
        array(
            'name' => 'Remote.co Jobs',
            'job_list_link' => 'https://remote.co/international-remote-jobs/',
            'job_link_classes' => 'card m-0 border-left-0 border-right-0 border-top-0 border-bottom',
            'job_title_field' => 'font-weight-bold',
            'job_type_field' => 'job_flag',
            'job_description_field' => 'job_description',
            'job_company_logo_field' => 'job_company_logo',
            'job_application_url_field' => 'application_button btn btn-primary text-uppercase font-weight-bold text-white d-none',
        )
    );

    // Handle form submission for adding a custom link
    if (isset($_POST['action']) && $_POST['action'] === 'rjobs_save_custom_link' && check_admin_referer('rjobs_save_custom_links_settings', 'rjobs_custom_links_settings_nonce')) {
        error_log('Adding a custom link');
        $link_data = array(
            'job_list_link' => sanitize_text_field($_POST['rjobs_job_list_link']),
            'job_link_classes' => sanitize_textarea_field($_POST['rjobs_job_link_classes']),
            'job_title_field' => sanitize_textarea_field($_POST['rjobs_job_title_field']),
            'job_type_field' => sanitize_textarea_field($_POST['rjobs_job_type_field']),
            'job_description_field' => sanitize_textarea_field($_POST['rjobs_job_description_field']),
            'job_company_logo_field' => sanitize_textarea_field($_POST['rjobs_job_company_logo_field']),
            'job_application_url_field' => sanitize_textarea_field($_POST['rjobs_job_application_url_field']),
        );

        $custom_links_settings = get_option('rjobs_custom_links_settings', array());

        // Check if the link already exists
        $link_exists = false;
        foreach ($custom_links_settings as $existing_link) {
            if ($existing_link['job_list_link'] === $link_data['job_list_link']) {
                $link_exists = true;
                break;
            }
        }

        if (!$link_exists) {
            // Add new link
            $custom_links_settings[] = $link_data;
            $success_message = 'Main link added successfully.';
        } else {
            $success_message = 'The Job List Link already exists.';
        }

        // Save updated custom links settings
        update_option('rjobs_custom_links_settings', $custom_links_settings);

        // Redirect back to the settings page with a success message
        wp_redirect(admin_url('admin.php?page=rjobs-settings&tab=normal&success=' . urlencode($success_message)));
        exit;
    }

    // Handle form submission for updating a custom link
    if (isset($_POST['action']) && $_POST['action'] === 'rjobs_edit_custom_link' && check_admin_referer('rjobs_save_custom_links_settings', 'rjobs_custom_links_settings_nonce')) {
        $index = isset($_POST['rjobs_link_index']) ? intval($_POST['rjobs_link_index']) : -1;
        error_log('Editing a custom link at index: ' . $index);

        if ($index >= 0) {
            $link_data = array(
                'job_list_link' => sanitize_text_field($_POST['rjobs_job_list_link']),
                'job_link_classes' => sanitize_textarea_field($_POST['rjobs_job_link_classes']),
                'job_title_field' => sanitize_textarea_field($_POST['rjobs_job_title_field']),
                'job_type_field' => sanitize_textarea_field($_POST['rjobs_job_type_field']),
                'job_description_field' => sanitize_textarea_field($_POST['rjobs_job_description_field']),
                'job_company_logo_field' => sanitize_textarea_field($_POST['rjobs_job_company_logo_field']),
                'job_application_url_field' => sanitize_textarea_field($_POST['rjobs_job_application_url_field']),
            );

            $custom_links_settings = get_option('rjobs_custom_links_settings', array());

            if (isset($custom_links_settings[$index])) {
                // Update existing link
                $custom_links_settings[$index] = $link_data;
                $success_message = 'Link updated successfully.';
            } else {
                $success_message = 'Invalid link index.';
            }

            // Save updated custom links settings
            update_option('rjobs_custom_links_settings', $custom_links_settings);

            // Redirect back to the settings page with a success message
            wp_redirect(admin_url('admin.php?page=rjobs-settings&tab=normal&success=' . urlencode($success_message)));
            exit;
        } else {
            error_log('Invalid index for updating a custom link: ' . $index);
        }
    }


    // Handle the form submission and update the settings based on the checkbox status for default links
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rjobs_default_links_settings_nonce']) && wp_verify_nonce($_POST['rjobs_default_links_settings_nonce'], 'rjobs_save_default_links_settings')) {
        $enabled_links = isset($_POST['default_links']) ? array_map('sanitize_text_field', $_POST['default_links']) : [];

        // Initialize the new settings array for default links
        $new_default_links_settings = [];

        // Update the settings to reflect the enabled/disabled status
        foreach ($default_links as $index => $link_settings) {
            $new_default_links_settings[$index] = $link_settings;
            $new_default_links_settings[$index]['enabled'] = in_array($index, $enabled_links);
        }

        // Save the updated default links settings
        update_option('rjobs_default_links_settings', $new_default_links_settings);

        // Display a notice indicating which links have been enabled or disabled
        $enabled_names = [];
        $disabled_names = [];
        foreach ($default_links as $index => $link_settings) {
            if (in_array($index, $enabled_links)) {
                $enabled_names[] = $link_settings['name'];
            } else {
                $disabled_names[] = $link_settings['name'];
            }
        }

        if (!empty($enabled_names)) {
            echo '<div class="notice notice-success is-dismissible"><p>Enabled: ' . implode(', ', $enabled_names) . '</p></div>';
        }
        if (!empty($disabled_names)) {
            echo '<div class="notice notice-warning is-dismissible"><p>Disabled: ' . implode(', ', $disabled_names) . '</p></div>';
        }
    }



    // Display notices if any
    if (isset($_GET['notice'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['notice']) . '</p></div>';
    } elseif (isset($_GET['success'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['success']) . '</p></div>';
    }

    
    

    echo '<style>
        
        .rjobs-modal { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.4); 
        }
        .rjobs-modal-content { 
            background-color: #fefefe; 
            margin: 10% auto; 
            padding: 20px; 
            border: 1px solid #888; 
            width: 50%; 
            max-width: 600px; 
            box-sizing: border-box; 
            z-index: 1001; /* Ensure modal content is above the modal background */
            position: relative; /* Position relative to the modal */
        }
        .rjobs-modal-content form { 
            display: grid; 
            grid-template-columns: auto 1fr; 
            grid-gap: 10px; 
            align-items: center; 
        }
        .rjobs-modal-content label { 
            display: flex; 
            align-items: center; 
            margin-bottom: 10px; 
        }
        .rjobs-modal-content label span { 
            color: red; 
            margin-left: 5px; 
        }
        .rjobs-modal-content input[type="text"], 
        .rjobs-modal-content textarea { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
        }
        .rjobs-modal-content .rjobs-save-button-container {
            grid-column: span 2;
            text-align: center;
            margin-top: 20px; /* Space between the last field and the button */
            padding-left: 31.5%;
        }
        .rjobs-modal-content button { 
            width: 100%; 
            max-width: 100%; /* Limit the width of the button */
            margin: 0 auto; /* Center the button */

        }
        .rjobs-close-modal { 
            float: right; 
            cursor: pointer; 
        }
            /* Style for the custom links section */
        #rjobs-custom-links-section {
            margin: 20px 0;
        }

        /* Container for the scrollable table */
        #rjobs-custom-links-table-container {
            max-height: 400px; /* Adjust height as needed */
            overflow-y: auto;
        }

        /* Ensures the table is styled correctly */
        .wp-list-table {
            border-collapse: collapse;
            width: 100%;
        }

     
            #rjobs-custom-links-container {
                border: 2px solid #ddd;
                padding: 20px;
                border-radius: 5px;
                overflow: hidden;
            }

            .rjobs-button-container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .rjobs-import-export-buttons {
                display: flex;
                gap: 10px;
            }
            .rjobs-import-export-buttons button {
                display: inline-block;
            }

            .rjobs-import-export-buttons input[type="file"] {
                display: none;
            }





            .rjobs-button-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
            }
            .rjobs-import-export-links, .rjobs-bulk-actions-form {
                display: flex;
                gap: 10px;
            }
            .rjobs-link-with-icon {
                display: inline-flex;
                align-items: center;
                text-decoration: none;
                border-left: 1px solid #ddd;
                padding-left: 10px;
                margin-left: 10px;
            }
            

            .rjobs-import-notices {
                margin: 0; 
                padding: 0;
                list-style-type: none;
                border-left: 4px solid #00b9eb; /* blue border */
                padding: 5px;
                background-color: #f0f0f1; 
            }
            .rjobs-import-notices li {
                margin-right: 5px;
                padding: 5px 0;
            }



            .rjobs-button-container {
                display: flex;
                align-items: center;
                margin-bottom: 10px;
            }

            .rjobs-bulk-actions {
                margin-left: 15px;
                display: flex;
                align-items: center;
            }

            #rjobs-bulk-action-dropdown {
                margin-right: 5px;
            }

            .rjobs-bulk-actions button {
                border: 1px solid #ccc;
                padding: 5px 10px;
            }



             .wp-list-table th,
            .wp-list-table td {
                border: 1px solid #ddd; /* Apply borders to all table cells */
                padding: 8px;
                text-align: left;
            }

            /* Remove the right border from the first column (checkbox column) */
            .wp-list-table td:first-child,
            .wp-list-table th:first-child {
                border-right: none; /* Remove right border */
            }

            .wp-list-table a {
                color: #0073aa;
            }

            .wp-list-table a:hover {
                color: #005177;
            }

            .rjobs-table-container input[type="checkbox"] {
                margin: 0;
            }

            .rjobs-table-container th input[type="checkbox"] {
                margin: 0;
            }

            .rjobs-table-container {
                max-height: 400px; /* Set a maximum height for the container */
                overflow-y: auto; /* Enable vertical scrolling */
                 /* Optional: Add a border around the table */
            }
                
                 
            .rjobs-table-container table {
                width: 100%; /* Ensure the table takes the full width */
                border-collapse: collapse; /* Collapse borders for a cleaner look */
            }

            .rjobs-table-container th {
                position: sticky; /* Make the header sticky */
                top: 0; /* Position it at the top of the container */
                background-color: #f9f9f9; /* Optional: Set a background color */
                z-index: 10; /* Ensure it stays above the body content */
            }

            .rjobs-table-container th, .rjobs-table-container td {
                padding: 8px; /* Add some padding */
                text-align: center; /* Align center to the center */
            }
           
 
            .rjobs-button-container form {
                display: inline-flex;
                align-items: center;
                margin-left: 20px;
            }

            #rjobs-bulk-actions-dropdown {
                margin-right: 10px;
            }



            /* Ensure the checkbox column is not separated and aligns well */
        .checkbox-column {
            width: 30px; /* Adjust width as needed */
            text-align: center;
            padding: 0;
        }
       

        /* Align checkboxes with text in the same row */
        .wp-list-table .link-checkbox {
            margin: 0;
            vertical-align: middle;
        }
        .wp-list-table td:first-child:hover, .wp-list-table th:first-child:hover {
            background: rgba(0, 0, 0, .05);
        }

        /* Ensure alignment of the checkboxes with the domain name text */
        .wp-list-table th, .wp-list-table td {
            vertical-align: middle;
            padding: 8px; /* Adjust as needed for spacing */
        }

        /* Style for the button container to ensure no big gap */
        .rjobs-button-container {
            display: flex;
            align-items: center;
        }

        .rjobs-main-bulk-buttons {
            display: flex;
            align-items: center;
            margin-right: 10px;
        }

        .rjobs-import-export-links {
            margin-left: 20px;
        }


       




            .default-links-container { 
                display: flex; 
                align-items: flex-start; 
            }
            .default-links-list { 
                margin-left: 20px; 
            }
            .default-link-item { 
                margin-bottom: 10px; 
            }
            .link-status-enabled { 
                color: green; 
            }
            .link-status-disabled { 
                color: red; 
            }
        </style>';



    // Fetch saved settings
    $custom_links_settings = get_option('rjobs_custom_links_settings', []);
    $default_links_settings = get_option('rjobs_default_links_settings', []);


    // Display default links with checkboxes
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=rjobs-settings&tab=normal')) . '">';
        wp_nonce_field('rjobs_save_default_links_settings', 'rjobs_default_links_settings_nonce');
        echo '<h2>Normal Links Settings</h2>';
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<div class="default-links-container">';
        echo '<th scope="row"><label for="rjobs_default_links">Default Links</label></th>';
        echo '<td>';
        echo '<div class="default-links-list">';

        if (!empty($default_links)) {
            foreach ($default_links as $index => $link_settings) {
                $checked = isset($default_links_settings[$index]['enabled']) && $default_links_settings[$index]['enabled'] ? 'checked' : '';
                $status = $checked ? '<span class="link-status-enabled">Enabled</span>' : '<span class="link-status-disabled">Disabled</span>';
                echo '<div class="default-link-item">';
                echo '<label><input type="checkbox" name="default_links[]" value="' . esc_attr($index) . '" ' . $checked . '> ' . esc_html($link_settings['name']) . ' - ' . $status . '</label>';
                echo '</div>';
            }
        } else {
            echo 'No default links available.';
        }
        echo '</div>';
        echo '</td>';
        echo '</div>';
        echo '</tr>';
        echo '</table>';
        echo '<br><button type="submit" class="button button-primary">Save Changes</button>';
        echo '</form>';



    // Display custom links
    // Display custom links

        echo '<h3>Custom Links</h3>';

        // Display the import/export notice if it exists
        $import_notices = get_transient('rjobs_import_notices');
        if ($import_notices) {
            echo '<div class="rjobs-import-notices">' . $import_notices . '</div>';
            delete_transient('rjobs_import_notices'); // Clear the notice after displaying
        }

        
        
        // Display the bulk action notice if it exists
        $bulk_action_notice = get_transient('rjobs_bulk_action_notice');
        if ($bulk_action_notice) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($bulk_action_notice) . '</p></div>';
            delete_transient('rjobs_bulk_action_notice'); // Clear the notice after displaying
        }
        echo '<div id="rjobs-custom-links-container">';
        
        // Container for buttons with flexbox
        echo '<div class="rjobs-button-container">';
        
        // Container for Main/Bulk buttons
        echo '<div class="rjobs-main-bulk-buttons">';
        echo '<button type="button" class="button" id="rjobs-add-link">Add Main Link</button>';
        echo '<form id="rjobs-bulk-actions-form">';
        echo '<select id="rjobs-bulk-actions-dropdown" name="bulk_action">
                <option value="">Bulk Actions</option>
                <option value="delete">Delete</option>
            </select>';
        echo '<button type="submit" class="button" id="rjobs-apply-bulk-action">Apply</button>';
        echo '</form>';
        echo '</div>'; // Close Main/Bulk buttons container


        
        // Container for import/export links
        echo '<div class="rjobs-import-export-links">';
        echo '<a href="#" id="rjobs-import-link" class="rjobs-link-with-icon"><i class="dashicons dashicons-upload"></i> Import Data</a>';
        echo '<a href="#" id="rjobs-export-csv-link" class="rjobs-link-with-icon"><i class="dashicons dashicons-download"></i> Export Data as CSV</a>';
        echo '<input type="file" id="rjobs-import-file" style="display: none;" />';
        echo '</div>'; // Close import/export links container
        
        echo '</div>'; // Close button container
        
        // Form for saving settings
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('rjobs_save_custom_links_settings', 'rjobs_custom_links_settings_nonce');
        echo '<div class="rjobs-table-container">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>
                <tr>
                    <th class="checkbox-column"><input type="checkbox" id="rjobs-select-all" /></th>
                    <th>Domain Name</th>
                    <th>Job List Link</th>
                    <th>Job Link</th>
                    <th>Job Title</th>
                    <th>Job Type</th>
                    <th>Job Description</th>
                    <th>Company Logo</th>
                    <th>Application URL</th>
                    <th>Actions</th>
                </tr>
            </thead>';
        echo '<tbody>';
        
        if (!empty($custom_links_settings)) {
            foreach ($custom_links_settings as $index => $link_settings) {
                $domain_name = parse_url($link_settings['job_list_link'], PHP_URL_HOST);
                $link_data = htmlspecialchars(json_encode($link_settings), ENT_QUOTES, 'UTF-8');
        
                echo '<tr data-index="' . esc_attr($index) . '" data-link=\'' . $link_data . '\'>';
                echo '<td><input type="checkbox" name="rjobs_link_ids[]" value="' . esc_attr($index) . '" class="link-checkbox" /></td>';
        
                echo '<td>' . esc_html($domain_name) . '</td>';
                echo '<td><a href="' . esc_url($link_settings['job_list_link']) . '" target="_blank">' . esc_html($link_settings['job_list_link']) . '</a></td>';
                echo '<td>' . esc_html($link_settings['job_link_classes']) . '</td>';
                echo '<td>' . esc_html($link_settings['job_title_field']) . '</td>';
                echo '<td>' . esc_html($link_settings['job_type_field']) . '</td>';
                echo '<td>' . esc_html($link_settings['job_description_field']) . '</td>';
                echo '<td>' . esc_html($link_settings['job_company_logo_field']) . '</td>';
                echo '<td>' . esc_html($link_settings['job_application_url_field']) . '</td>';
                echo '<td>';
                echo '<a href="#" class="rjobs-edit-link" data-index="' . esc_attr($index) . '" data-link=\'' . $link_data . '\' style="text-decoration: underline; color: blue;">Edit</a>';
                echo ' | '; // Optional: Add a separator between Edit and Delete links
                echo '<a href="#" class="rjobs-delete-link" data-index="' . esc_attr($index) . '" style="text-decoration: underline; color: red;">Delete</a>';
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="9">No custom links available.</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>'; // Close scrollable container
        echo '<input type="hidden" name="page" value="rjobs-settings">';
        echo '<input type="hidden" name="tab" value="normal">';
        echo '</form>';
        echo '</div>'; // Close main container

    





    // Combined Modal for Add/Edit Link
        echo '<div id="rjobs-link-modal" class="rjobs-modal" style="display:none;">';
        echo '    <div class="rjobs-modal-content">';
        echo '        <span class="rjobs-close-modal">&times;</span>';
        echo '        <h3 id="rjobs-modal-title">Add New Link</h3>';
        echo '        <form id="rjobs-link-form" method="post">';
        wp_nonce_field('rjobs_save_custom_links_settings', 'rjobs_custom_links_settings_nonce');
        echo '            <input type="hidden" name="action" id="rjobs-link-action" value="rjobs_save_custom_link">';
        echo '            <input type="hidden" name="rjobs_link_index" id="rjobs-link-index" value="">';
        echo '            <label for="rjobs_job_list_link">Job List Link<span>*</span></label>';
        echo '            <input type="text" name="rjobs_job_list_link" id="rjobs_job_list_link" required>';
        echo '            <label for="rjobs_job_link_classes">Job Link Classes<span>*</span></label>';
        echo '            <textarea name="rjobs_job_link_classes" id="rjobs_job_link_classes" required></textarea>';
        echo '            <label for="rjobs_job_title_field">Job Title Field<span>*</span></label>';
        echo '            <textarea name="rjobs_job_title_field" id="rjobs_job_title_field" required></textarea>';
        echo '            <label for="rjobs_job_type_field">Job Type Field<span>*</span></label>';
        echo '            <textarea name="rjobs_job_type_field" id="rjobs_job_type_field" required></textarea>';
        echo '            <label for="rjobs_job_description_field">Job Description Field<span>*</span></label>';
        echo '            <textarea name="rjobs_job_description_field" id="rjobs_job_description_field" required></textarea>';
        echo '            <label for="rjobs_job_company_logo_field">Job Company Logo Field<span>*</span></label>';
        echo '            <textarea name="rjobs_job_company_logo_field" id="rjobs_job_company_logo_field"></textarea>';
        echo '            <label for="rjobs_job_application_url_field">Job Application URL Field<span>*</span></label>';
        echo '            <textarea name="rjobs_job_application_url_field" id="rjobs_job_application_url_field" required></textarea>';
        echo '            <div class="rjobs-save-button-container"><button type="submit" class="button button-primary">Save Link</button></div>';
        echo '        </form>';
        echo '    </div>';
        echo '</div>';





        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            function openModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = "block";
                } else {
                    console.error("Modal with ID", modalId, "not found");
                }
            }
        
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = "none";
                }
            }
        
            // Event listener for "Add Main Link" button
            const addLinkButton = document.getElementById("rjobs-add-link");
            if (addLinkButton) {
                addLinkButton.addEventListener("click", function() {
                    document.getElementById("rjobs-modal-title").textContent = "Add New Link";
                    document.getElementById("rjobs-link-action").value = "rjobs_save_custom_link";
                    document.getElementById("rjobs-link-index").value = "";
                    document.getElementById("rjobs-link-form").reset(); // Clear the form fields
                    openModal("rjobs-link-modal");
                });
            } else {
                console.error("Add Main Link button not found");
            }
        
            // Event listeners for "Edit" buttons
            document.querySelectorAll(".rjobs-edit-link").forEach(function(button) {
                button.addEventListener("click", function() {
                    const index = this.getAttribute("data-index");
                    let linkData = {};
                    try {
                        linkData = JSON.parse(this.getAttribute("data-link") || "{}");
                    } catch (e) {
                        console.error("Failed to parse link data:", e);
                    }
        
                    document.getElementById("rjobs-modal-title").textContent = "Edit Link";
                    document.getElementById("rjobs-link-action").value = "rjobs_edit_custom_link";
                    document.getElementById("rjobs-link-index").value = index;
        
                    document.getElementById("rjobs_job_list_link").value = linkData.job_list_link || "";
                    document.getElementById("rjobs_job_link_classes").value = linkData.job_link_classes || "";
                    document.getElementById("rjobs_job_title_field").value = linkData.job_title_field || "";
                    document.getElementById("rjobs_job_type_field").value = linkData.job_type_field || "";
                    document.getElementById("rjobs_job_description_field").value = linkData.job_description_field || "";
                    document.getElementById("rjobs_job_company_logo_field").value = linkData.job_company_logo_field || "";
                    document.getElementById("rjobs_job_application_url_field").value = linkData.job_application_url_field || "";
        
                    openModal("rjobs-link-modal");
                });
            });
        
            // Event listener for closing modals
            document.querySelectorAll(".rjobs-close-modal").forEach(function(button) {
                button.addEventListener("click", function() {
                    closeModal("rjobs-link-modal");
                });
            });
        
            // Close modal if clicking outside of it
            window.addEventListener("click", function(event) {
                if (event.target.classList.contains("rjobs-modal")) {
                    closeModal(event.target.id);
                }
            });
        
            // Export links as CSV
            document.getElementById("rjobs-export-csv-link").addEventListener("click", function(e) {
                e.preventDefault();
                fetch("' . esc_url(admin_url('admin-ajax.php')) . '?action=rjobs_export_links&type=csv")
                    .then(response => response.text())
                    .then(csv => {
                        const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
                        const link = document.createElement("a");
                        link.href = URL.createObjectURL(blob);
                        link.download = "normal_links.csv";
                        link.click();
                    });
            });
        
            // Trigger file input click for import
            document.getElementById("rjobs-import-link").addEventListener("click", function(e) {
                e.preventDefault();
                document.getElementById("rjobs-import-file").click();
            });
        
            // Handle file import
            document.getElementById("rjobs-import-file").addEventListener("change", function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        fetch("' . admin_url('admin-ajax.php') . '?action=rjobs_import_links", {
                            method: "POST",
                            body: e.target.result,
                            headers: {
                                "Content-Type": "text/csv"
                            }
                        })
                        .then(response => response.text())
                        .then(result => {
                            location.reload(); // Reload the page to show the notice
                        });
                    };
                    reader.readAsText(file);
                }
            });
        
            // Select all checkboxes
            document.getElementById("rjobs-select-all").addEventListener("change", function() {
                var checked = this.checked;
                document.querySelectorAll("input[name=\'rjobs_link_ids[]\']").forEach(function(checkbox) {
                    checkbox.checked = checked;
                });
            });

            // Apply bulk action
            document.getElementById("rjobs-apply-bulk-action").addEventListener("click", function(event) {
                event.preventDefault(); // Prevent default form submission

                var action = document.getElementById("rjobs-bulk-actions-dropdown").value;
                if (action === "delete") {
                    if (confirm("Are you sure you want to delete all selected links?")) {
                        var form = document.createElement("form");
                        form.method = "post";
                        form.action = "' . esc_url(admin_url('admin-post.php')) . '";

                        // Add action input
                        var input = document.createElement("input");
                        input.type = "hidden";
                        input.name = "action";
                        input.value = "rjobs_bulk_delete";
                        form.appendChild(input);

                        // Add nonce input
                        var nonce = document.querySelector("input[name=\'rjobs_custom_links_settings_nonce\']").value;
                        var nonceInput = document.createElement("input");
                        nonceInput.type = "hidden";
                        nonceInput.name = "rjobs_custom_links_settings_nonce";
                        nonceInput.value = nonce;
                        form.appendChild(nonceInput);

                        // Add tab input dynamically based on the current tab
                        var currentTab = document.querySelector("input[name=\'tab\']").value; // Get current tab value
                        var tabInput = document.createElement("input");
                        tabInput.type = "hidden";
                        tabInput.name = "tab";
                        tabInput.value = currentTab; // Set it to the current tab
                        form.appendChild(tabInput);

                        // Add selected link IDs
                        document.querySelectorAll("input[name=\'rjobs_link_ids[]\']:checked").forEach(function(checkbox) {
                            var hiddenInput = document.createElement("input");
                            hiddenInput.type = "hidden";
                            hiddenInput.name = "rjobs_link_ids[]";
                            hiddenInput.value = checkbox.value;
                            form.appendChild(hiddenInput);
                        });

                        document.body.appendChild(form);
                        form.submit(); // Submit the form
                    }
                }
            });
        
            // Event listener for delete links
            document.querySelectorAll(".rjobs-delete-link").forEach(function(link) {
                link.addEventListener("click", function(event) {
                    event.preventDefault(); // Prevent the default link action
                    if (confirm("Are you sure you want to delete this link?")) {
                        const index = this.getAttribute("data-index");
                        const form = document.createElement("form");
                        form.method = "post";
                        form.action = "' . esc_url(admin_url('admin-post.php')) . '";
                        form.innerHTML = `
                            <input type="hidden" name="action" value="rjobs_delete_custom_link">
                            <input type="hidden" name="rjobs_link_index" value="${index}">
                            <input type="hidden" name="rjobs_custom_links_settings_nonce" value="${document.querySelector("input[name=\'rjobs_custom_links_settings_nonce\']").value}">
                            <input type="hidden" name="tab" value="normal">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });

            document.querySelectorAll(".rjobs-table-container td").forEach(function(td) {
                const maxLength = 30; // Set the maximum number of characters
                if (td.textContent.length > maxLength) {
                    td.textContent = td.textContent.slice(0, maxLength) + "..."; // Truncate and add ellipsis
                }
            });
        });
        </script>';
        

}


add_action('wp_ajax_rjobs_export_links', 'rjobs_export_links');

function rjobs_export_links() {
    // Verify user permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Set the filename for the CSV
    $filename = 'normal_links.csv';

    // Fetch the custom links settings
    $custom_links_settings = get_option('rjobs_custom_links_settings', array());

    // Set headers to force download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=' . $filename);

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add CSV column headers
    fputcsv($output, array('Domain Name', 'Job List Link', 'Job Link Classes', 'Job Title Field', 'Job Type Field', 'Job Description Field', 'Company Logo', 'Job Application URL Field'));

    // Add data rows
    if (!empty($custom_links_settings)) {
        foreach ($custom_links_settings as $link_settings) {
            $domain_name = parse_url($link_settings['job_list_link'], PHP_URL_HOST);
            fputcsv($output, array(
                $domain_name,
                $link_settings['job_list_link'],
                $link_settings['job_link_classes'],
                $link_settings['job_title_field'],
                $link_settings['job_type_field'],
                $link_settings['job_description_field'],
                $link_settings['job_company_logo_field'],
                $link_settings['job_application_url_field']
            ));
        }
    }

    // Close output stream
    fclose($output);
    exit;
}
function add_cors_http_header(){
    // Only enable CORS on admin-ajax requests
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        // Specify allowed domains
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    }

    // Handle preflight requests
    if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        exit;
    }
}
add_action('init', 'add_cors_http_header');


function esc_csv($value) {
    return str_replace('"', '""', $value); // Escape double quotes for CSV format
}


add_action('wp_ajax_rjobs_import_links', 'rjobs_import_links');
function rjobs_import_links() {
    // Verify user permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Get the CSV data from the request body
    $csv_data = file_get_contents('php://input');

    // Parse the CSV data
    $lines = explode(PHP_EOL, $csv_data);
    $header = str_getcsv(array_shift($lines));
    $new_links_settings = array();

    // Fetch the existing custom links settings
    $existing_links_settings = get_option('rjobs_custom_links_settings', array());

    // Track existing domains to show notices
    $existing_domains = array();
    foreach ($existing_links_settings as $link_settings) {
        $existing_domains[] = parse_url($link_settings['job_list_link'], PHP_URL_HOST);
    }

    // Process the imported data
    $notices = array();
    $new_domain_count = 0; // Counter for newly imported domains
    foreach ($lines as $line) {
        if (trim($line)) {
            $data = str_getcsv($line);
            $domain_name = parse_url($data[1], PHP_URL_HOST);

            if (in_array($domain_name, $existing_domains)) {

                $notices[] = "<li>The domain <strong style='color: red;'>$domain_name</strong> already exists.</li>";
            } else {
                $new_links_settings[] = array(
                    'job_list_link' => $data[1],
                    'job_link_classes' => $data[2],
                    'job_title_field' => $data[3],
                    'job_type_field' => $data[4],
                    'job_description_field' => $data[5],
                    'job_company_logo_field' => $data[6],
                    'job_application_url_field' => $data[7]
                );
                $existing_domains[] = $domain_name; // Add to existing domains to avoid duplicates in this import session
                $new_domain_count++; // Increment the new domain count

                
            }
        }
    }

    // Save the new settings
    if (!empty($new_links_settings)) {
        $merged_links_settings = array_merge($existing_links_settings, $new_links_settings);
        update_option('rjobs_custom_links_settings', $merged_links_settings);
    }

    // Set notices
    if (!empty($notices)) {
        // Create a notice for existing domains
        $notice_content = '<ul style="margin: 0; padding: 0; list-style-type: none;">' . implode("\n", $notices) . '</ul>';
        if ($new_domain_count > 0) {
            $notice_content .= '<p><strong>Import successful:</strong> <span style="color: green; font-weight: bold;">' . $new_domain_count . ' new domain(s) added.</span></p>';
        }
        set_transient('rjobs_import_notices', $notice_content, 30); // Notice lasts for 30 seconds
    } elseif ($new_domain_count > 0) {
        // Success notice with count of new domains
        set_transient('rjobs_import_notices', '<p><strong>Import successful:</strong> <span style="color: green; font-weight: bold;">' . $new_domain_count . ' new domain(s) added.</span></p>', 30);
    } else {
        // No new domains added, no additional success message
        set_transient('rjobs_import_notices', 'Import completed, but no new domains were added.', 30);
    }
        


    // Redirect to the settings page
    wp_redirect(esc_url(admin_url('admin.php?page=rjobs-settings&tab=normal')));
    exit;
}

add_action('admin_post_rjobs_bulk_delete', 'rjobs_bulk_delete');
function rjobs_bulk_delete() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Verify nonce
    if (!isset($_POST['rjobs_custom_links_settings_nonce']) || !wp_verify_nonce($_POST['rjobs_custom_links_settings_nonce'], 'rjobs_save_custom_links_settings')) {
        wp_die(__('Invalid nonce.'));
    }

    // Handle bulk delete
    $deleted_count = 0;
    if (isset($_POST['rjobs_link_ids'])) {
        $selected_indexes = $_POST['rjobs_link_ids'];
        $custom_links_settings = get_option('rjobs_custom_links_settings', array());

        // Count the number of selected links
        $total_selected = count($selected_indexes);

        foreach ($selected_indexes as $index) {
            if (isset($custom_links_settings[$index])) {
                unset($custom_links_settings[$index]);
                $deleted_count++;
            }
        }

        // Reindex array
        $custom_links_settings = array_values($custom_links_settings);
        update_option('rjobs_custom_links_settings', $custom_links_settings);

        // Set a success notice based on the number of selected links
        if ($deleted_count === $total_selected) {
            $notice = 'All ' . $total_selected . ' selected links have been deleted.';
        } else {
            $notice = $deleted_count . ' of ' . $total_selected . ' selected links have been deleted.';
        }
        set_transient('rjobs_bulk_action_notice', $notice, 30);
    } else {
        set_transient('rjobs_bulk_action_notice', 'No links were selected for deletion.', 30);
    }

    // Preserve the current tab
    $tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : 'normal';
    $redirect_url = add_query_arg('tab', $tab, admin_url('admin.php?page=rjobs-settings'));

    // Debugging output
    error_log('Redirecting to: ' . $redirect_url); // Log the redirect URL for debugging

    wp_redirect($redirect_url);
    exit;
}

add_action('admin_post_rjobs_delete_custom_link', 'rjobs_delete_custom_link');

function rjobs_delete_custom_link() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Verify nonce
    if (!isset($_POST['rjobs_custom_links_settings_nonce']) || !wp_verify_nonce($_POST['rjobs_custom_links_settings_nonce'], 'rjobs_save_custom_links_settings')) {
        wp_die(__('Invalid nonce.'));
    }

    // Get the index of the link to delete
    $index = isset($_POST['rjobs_link_index']) ? intval($_POST['rjobs_link_index']) : -1;
    error_log('Deleting a custom link at index: ' . $index);

    // Get the current settings
    $custom_links_settings = get_option('rjobs_custom_links_settings', array());

    // Check if the index is valid
    if ($index >= 0 && isset($custom_links_settings[$index])) {
        // Remove the link
        unset($custom_links_settings[$index]);
        // Reindex the array
        $custom_links_settings = array_values($custom_links_settings);
        // Save updated settings
        update_option('rjobs_custom_links_settings', $custom_links_settings);

        // Set a success message
        $success_message = 'Main link deleted successfully.';
    } else {
        error_log('Invalid index for deleting a custom link: ' . $index);
        $success_message = 'Invalid link index.';
    }

    // Preserve the current tab
    $tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : 'normal';

    // Redirect back to the settings page with a success message
    $redirect_url = add_query_arg('tab', $tab, admin_url('admin.php?page=rjobs-settings&success=' . urlencode($success_message)));
    wp_redirect($redirect_url);
    exit;
}



function rjobs_rss_feeds_settings_page() {
    // Define default RSS feeds
    $default_rss_feeds = [
        'https://weworkremotely.com/100-percent-remote-jobs.rsss' => 'We Work Remotely',
        'https://wpremotework.com/feed/' => 'WP Remote Work',
        'https://euremotejobs.com/job-region/remote-jobs-worldwide/feed/' => 'EU Remote Jobs',
        'https://jobicy.com/?feed=job_feed&search_region=emea' => 'Jobicy',
        'https://himalayas.app/jobs/countries/kenya?sort=recent/rsss' => 'Himalayas',
        'https://inclusivelyremote.com/job-location/worldwide/feed/' => 'Inclusively Remote',
    ];

    if (isset($_POST['rjobs_save_rss_settings']) && check_admin_referer('rjobs_save_rss_settings', 'rjobs_rss_settings_nonce')) {
        $custom_rss_feeds = array_map('esc_url', array_filter(array_map('trim', explode("\n", $_POST['rjobs_custom_rss_feeds']))));
        $selected_default_feeds = isset($_POST['rjobs_default_rss_feeds']) ? $_POST['rjobs_default_rss_feeds'] : [];

        // Prepare RSS feeds for saving
        $rss_feeds = [];
        foreach ($selected_default_feeds as $feed_url) {
            if (isset($default_rss_feeds[$feed_url])) {
                $rss_feeds[$feed_url] = ['url' => $feed_url, 'name' => $default_rss_feeds[$feed_url], 'enabled' => true];
            }
        }
        foreach ($custom_rss_feeds as $feed_url) {
            $rss_feeds[$feed_url] = ['url' => $feed_url, 'name' => 'Custom RSS Feed', 'enabled' => true];
        }

        update_option('rjobs_rss_feeds', $rss_feeds);

        // Determine enabled and disabled feeds
        $previous_rss_feeds = get_option('rjobs_rss_feeds', []);
        $enabled_feeds = array_diff_key($rss_feeds, $previous_rss_feeds);
        $disabled_feeds = array_diff_key($previous_rss_feeds, $rss_feeds);

        // Display a notice indicating changes
        if (!empty($enabled_feeds) || !empty($disabled_feeds)) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p><ul>';
            foreach ($enabled_feeds as $feed) {
                echo '<li>Enabled: ' . esc_html($feed['name']) . '</li>';
            }
            foreach ($disabled_feeds as $feed) {
                if (isset($feed['name'])) {
                    echo '<li>Disabled: ' . esc_html($feed['name']) . '</li>';
                }
            }
            echo '</ul></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }
    }

    $saved_rss_feeds = get_option('rjobs_rss_feeds', []);

    // Ensure $saved_rss_feeds is an array
    if (!is_array($saved_rss_feeds)) {
        $saved_rss_feeds = [];
    }

    // Debugging output
    error_log(print_r($saved_rss_feeds, true));

    echo '<form method="post" action="">';
    wp_nonce_field('rjobs_save_rss_settings', 'rjobs_rss_settings_nonce');
    echo '<h2>RSS Feeds Settings</h2>';
    echo '<table class="form-table">';

    // Display default RSS feeds with checkboxes
    echo '<tr>';
    echo '<th scope="row"><label for="rjobs_default_rss_feeds">Default RSS Feeds</label></th>';
    echo '<td>';
    foreach ($default_rss_feeds as $feed_url => $feed_name) {
        $checked = isset($saved_rss_feeds[$feed_url]) && $saved_rss_feeds[$feed_url]['enabled'] ? 'checked' : '';
        echo '<label><input type="checkbox" name="rjobs_default_rss_feeds[]" value="' . esc_url($feed_url) . '" ' . $checked . '> ' . esc_html($feed_name) . '</label><br>';
    }
    echo '</td>';
    echo '</tr>';

    // Display custom RSS feeds in a textarea
    echo '<tr>';
    echo '<th scope="row"><label for="rjobs_custom_rss_feeds">Custom RSS Feeds</label></th>';
    $custom_feeds = array_keys(array_filter($saved_rss_feeds, function ($feed) {
        // Debugging output
        error_log(print_r($feed, true));
        return is_array($feed) && isset($feed['name']) && $feed['name'] === 'Custom RSS Feed';
    }));
    echo '<td><textarea name="rjobs_custom_rss_feeds" rows="10" cols="50" class="large-text">' . esc_textarea(implode("\n", $custom_feeds)) . '</textarea></td>';
    echo '</tr>';

    echo '</table>';
    echo '<p class="submit"><input type="submit" name="rjobs_save_rss_settings" id="submit" class="button button-primary" value="Save Settings"></p>';
    echo '</form>';
}


function rjobs_settings_page()
{
    ?>
        <div class="wrap">
            <h1>Remote Jobs Aggregator Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=rjobs-settings&tab=main" class="nav-tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] == 'main') ? 'nav-tab-active' : ''; ?>">Main Settings</a>
                <a href="?page=rjobs-settings&tab=rss" class="nav-tab <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'rss') ? 'nav-tab-active' : ''; ?>">RSS Feeds</a>
                <a href="?page=rjobs-settings&tab=normal" class="nav-tab <?php echo (isset($_GET['tab']) && $_GET['tab'] == 'normal') ? 'nav-tab-active' : ''; ?>">Normal Links</a>
            </h2>
            <div class="tab-content">
                <?php
                if (isset($_GET['tab']) && $_GET['tab'] == 'rss') {
                    rjobs_rss_feeds_settings_page();
                } elseif (isset($_GET['tab']) && $_GET['tab'] == 'normal') {
                    rjobs_normal_links_settings_page();
                } else {
                    rjobs_main_settings_page();
                }
                ?>
            </div>
        </div>
    <?php
}
function rjobs_get_current_tab()
{
    

    return isset($_GET['tab']) ? $_GET['tab'] : 'rss_feeds';
}

// Function to display job listings page
function rjobs_listings_page()
{
    echo '<div class="wrap">';
    echo '<h1>Job Listings</h1>';

    // Check if jobs are currently being fetched
    $is_fetching_jobs = rjobs_is_cron_job_scheduled();

    // Display message if jobs are currently being fetched
    if ($is_fetching_jobs) {
        echo '<div class="notice notice-info"><p>Jobs are currently being fetched.</p></div>';
    } else {
        echo '<div class="notice notice-info"><p>No jobs are currently being fetched.</p></div>';
    }

    // Display fetched jobs
    $jobs = rjobs_get_all_jobs();

    if (empty($jobs)) {
        echo '<p>No jobs found.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Job Title</th>';
        echo '<th>Company</th>';
        echo '<th>Location</th>';
        echo '<th>Category</th>';
        echo '<th>Job Type</th>';
        echo '<th>Link</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($jobs as $job) {
            echo '<tr>';
            echo '<td>' . esc_html($job['post_title']) . '</td>';
            echo '<td>' . esc_html($job['company_name']) . '</td>';
            echo '<td>' . esc_html($job['job_location']) . '</td>';
            echo '<td>' . esc_html(implode(', ', $job['job_categories'])) . '</td>';
            echo '<td>' . esc_html($job['job_type']) . '</td>';
            echo '<td><a href="' . esc_url($job['job_url']) . '" target="_blank">View Job</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    echo '</div>';
}
function rjobs_get_all_jobs()
{
    $args = array(
        'post_type' => 'job_listing',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    );

    $query = new WP_Query($args);
    $jobs = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $company_name = get_post_meta($post_id, '_company_name', true);
            $job_location = get_post_meta($post_id, '_job_location', true);
            $job_type = get_post_meta($post_id, '_job_type', true);

            $categories = wp_get_post_terms($post_id, 'job_listing_category', array('fields' => 'names'));

            $jobs[] = array(
                'post_title' => get_the_title(),
                'company_name' => $company_name,
                'job_location' => $job_location,
                'job_categories' => $categories,
                'job_type' => $job_type,
                'job_url' => get_post_meta($post_id, '_application', true),
            );
        }
        wp_reset_postdata();
    }

    return $jobs;
}

// Hook into job deletion in WP Job Manager
function rjobs_delete_job_hook($post_id)
{
    if (get_post_type($post_id) === 'job_listing') {
        // Check if job fetching is currently stopped
        $is_fetching_stopped = !rjobs_is_cron_job_scheduled();

        // Only schedule cron job if fetching is not explicitly stopped
        if (!$is_fetching_stopped) {
            rjobs_schedule_cron();
        }
    }
}

// Ensure deletion hook is properly set
add_action('before_delete_post', 'rjobs_delete_job_hook');


// Example of calling fetch jobs explicitly, not triggered by page load
if (isset($_POST['rjobs_fetch']) && check_admin_referer('rjobs_fetch_jobs', 'rjobs_fetch_nonce')) {
    rjobs_fetch_jobs();
    rjobs_fetch_jobs_normal_links();
}


// Function to update fetching status message
function rjobs_update_fetching_status_message($status)
{
    set_transient('rjobs_fetching_status', $status, MINUTE_IN_SECONDS * 30); // Set transient for 30 minutes
    error_log("Fetching status set to: " . $status); // Debugging line
}

// Handle fetching actions
function rjobs_handle_fetching_actions()
{
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'stop_fetching_jobs' && check_admin_referer('rjobs_control_jobs')) {
            rjobs_deactivate_cron(); // Stop automatic fetching
            rjobs_update_fetching_status_message('stopped');
            wp_redirect(admin_url('admin.php?page=rjobs'));
            exit();
        } elseif ($_GET['action'] === 'continue_fetching_jobs' && check_admin_referer('rjobs_control_jobs')) {
            rjobs_reactivate_cron(); // Resume automatic fetching
            rjobs_update_fetching_status_message('fetching');
            wp_redirect(admin_url('admin.php?page=rjobs'));
            exit();
        }
    }
}
add_action('admin_init', 'rjobs_handle_fetching_actions');


// Function to reactivate the cron job for fetching jobs
// Reactivate cron job
function rjobs_reactivate_cron()
{
    // Get the schedule frequency from the settings
    $schedule_frequency = get_option('rjobs_schedule_frequency', 'hourly');

    // Calculate the interval based on schedule frequency
    $interval_seconds = rjobs_get_schedule_interval_seconds($schedule_frequency);

    // If the cron job is not scheduled, schedule it
    if (!wp_next_scheduled('rjobs_fetch_jobs_cron')) {
        wp_schedule_event(time(), $schedule_frequency, 'rjobs_fetch_jobs_cron');
        rjobs_update_fetching_status_message('fetching'); // Update status to indicate fetching
    } else {
        // Check if jobs were fetched recently
        $last_fetched_time = get_transient('rjobs_last_fetch_time');
        if (!$last_fetched_time || (time() - $last_fetched_time >= $interval_seconds)) {
            rjobs_update_fetching_status_message('not_scheduled'); // Update status to indicate not scheduled
        } else {
            rjobs_update_fetching_status_message('fetching'); // Update status to indicate fetching
        }
    }
    error_log('Cron job reactivated');
}

// Function to calculate schedule interval in seconds
function rjobs_get_schedule_interval_seconds($schedule_frequency)
{
    switch ($schedule_frequency) {
        case 'minutely':
            return MINUTE_IN_SECONDS; // 60 seconds
        case 'halfhourly':
            return MINUTE_IN_SECONDS * 30; // 30 minutes
        case 'hourly':
            return HOUR_IN_SECONDS;
        case 'twicedaily':
            return 12 * HOUR_IN_SECONDS;
        case 'daily':
            return DAY_IN_SECONDS;
            // Add more cases for other frequencies as needed
        default:
            return HOUR_IN_SECONDS; // Default to hourly if frequency is unknown
    }
}



function rjobs_fetch_source() {
    $fetch_source = get_option('rjobs_fetch_source', 'rss'); // Default to 'rss'
    $num_jobs = get_option('rjobs_num_jobs', 10);

    switch ($fetch_source) {
        case 'rss':
            rjobs_fetch_jobs($num_jobs);
            break;
        case 'normal_links':
            rjobs_fetch_jobs_normal_links($num_jobs);
            break;
        default:
            error_log('Remote Job Aggregator: Invalid fetch source - ' . $fetch_source);
            break;
    }
}

function rjobs_fetch_jobs_cron() {
    $fetch_source = get_option('rjobs_fetch_source', 'rss'); // Default to 'rss'
    $num_jobs = get_option('rjobs_num_jobs', 10);
    $current_source = ($fetch_source === 'rss') ? 'RSS Feeds' : 'Normal Links';

    error_log("Remote Job Aggregator: Fetching from source - $current_source");

    $jobs = [];

    switch ($fetch_source) {
        case 'rss':
            $jobs = rjobs_fetch_jobs($num_jobs); // Fetch jobs from RSS
            break;
        case 'normal_links':
            $jobs = rjobs_fetch_jobs_normal_links($num_jobs); // Fetch jobs from normal links
            break;
        default:
            error_log('Remote Job Aggregator: Invalid fetch source - ' . $fetch_source);
            break;
    }

    if (!is_array($jobs)) {
        $jobs = [];
    }

    error_log('Remote Job Aggregator: ' . count($jobs) . ' jobs fetched via cron from ' . $current_source);

    if (!empty($jobs)) {
        error_log('Remote Job Aggregator: Jobs fetched successfully via cron.');
    } else {
        error_log('Remote Job Aggregator: No new jobs found via cron.');
    }

    // If fetched jobs exceed or meet the limit, stop further processing and deactivate cron job
    if (count($jobs) >= $num_jobs) {
        error_log("Remote Job Aggregator: Stopping further fetching as $num_jobs jobs limit reached.");

        // Deactivate the cron job
        rjobs_deactivate_cron();
    }
}
add_action('rjobs_fetch_jobs_cron', 'rjobs_fetch_jobs_cron');

function rjobs_schedule_cron() {
    $frequency = get_option('rjobs_schedule_frequency', 'daily');

    // Clear existing cron jobs
    rjobs_deactivate_cron();

    // Schedule new cron job based on fetch frequency
    switch ($frequency) {
        case 'minutely':
            wp_schedule_event(time(), 'every_minute', 'rjobs_fetch_jobs_cron');
            break;
        case 'half_hourly':
            wp_schedule_event(time(), 'every_half_hour', 'rjobs_fetch_jobs_cron');
            break;
        case 'hourly':
            wp_schedule_event(time(), 'hourly', 'rjobs_fetch_jobs_cron');
            break;
        case 'twicedaily':
            wp_schedule_event(time(), 'twicedaily', 'rjobs_fetch_jobs_cron');
            break;
        case 'daily':
        default:
            wp_schedule_event(time(), 'daily', 'rjobs_fetch_jobs_cron');
            break;
    }

    error_log("Remote Job Aggregator: Cron job scheduled with frequency - $frequency");
}
// Function to check if cron job is scheduled
function rjobs_is_cron_job_scheduled()
{
    return wp_next_scheduled('rjobs_fetch_jobs_cron');
}
// Function to deactivate the cron job

function rjobs_deactivate_cron()
{
    $timestamp = wp_next_scheduled('rjobs_fetch_jobs_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'rjobs_fetch_jobs_cron');
        error_log('Remote Job Aggregator: Cron job deactivated');
    }
}
