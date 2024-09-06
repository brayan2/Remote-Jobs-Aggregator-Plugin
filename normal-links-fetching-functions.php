<?php
include_once(plugin_dir_path(__FILE__) . 'admin-functions.php');
include_once(plugin_dir_path(__FILE__) . 'helper-functions.php');
include_once(plugin_dir_path(__FILE__) . 'job-fetching-functions.php');

/**
 * Fetch jobs from normal links based on settings.
 *
 * @param int $num_jobs Number of jobs to fetch.
 * @return array Array of inserted job IDs.
 */
function rjobs_fetch_jobs_normal_links($num_jobs = 10) {
    // Get the schedule frequency and calculate interval based on schedule frequency
    $schedule_frequency = get_option('rjobs_schedule_frequency', 'hourly');
    $interval_seconds = rjobs_get_schedule_interval_seconds($schedule_frequency);

    // Get the timestamp of the last job fetch
    $last_fetch_time = get_option('rjobs_last_fetch_time', 0);


    // Check if the schedule interval has elapsed
    if (time() - $last_fetch_time < $interval_seconds) {
        return array(); // Or WP_Error indicating not enough time has passed
    }

// Fetch settings for default and custom links
$default_link_settings = get_option('rjobs_default_links_settings');
$custom_link_settings = get_option('rjobs_custom_links_settings');

if (empty($default_link_settings) && empty($custom_link_settings)) {
    $error_log = 'Error fetching settings for normal links.';
    error_log($error_log);
    return new WP_Error('error_fetching_settings', $error_log);
}

    // Initialize transient key for link settings
    $transient_key = 'rjobs_normal_links_settings';
    $transient_default_link_settings = get_transient($transient_key . '_default');
    $transient_custom_link_settings = get_transient($transient_key . '_custom');

    // Generate MD5 hash of the current settings
    $default_link_settings_hash = md5(json_encode($default_link_settings));
    $custom_link_settings_hash = md5(json_encode($custom_link_settings));

    // Check if there is a change in default link settings
    if ($transient_default_link_settings === false || $default_link_settings_hash !== get_transient($transient_key . '_default_hash')) {
        set_transient($transient_key . '_default', $default_link_settings, HOUR_IN_SECONDS);
        set_transient($transient_key . '_default_hash', $default_link_settings_hash, HOUR_IN_SECONDS);
    }

    // Check if there is a change in custom link settings
    if ($transient_custom_link_settings === false || $custom_link_settings_hash !== get_transient($transient_key . '_custom_hash')) {
        set_transient($transient_key . '_custom', $custom_link_settings, HOUR_IN_SECONDS);
        set_transient($transient_key . '_custom_hash', $custom_link_settings_hash, HOUR_IN_SECONDS);
    }

    // Filter enabled links from the default section
    $enabled_default_links = array_filter($default_link_settings, function($settings) {
        return isset($settings['enabled']) && $settings['enabled'];
    });

    // Merge enabled default links and all custom links
    $all_links_settings = array_merge($enabled_default_links, $custom_link_settings);

    // Array to store inserted job IDs
    $inserted_job_ids = array();

    // Counter for fetched jobs
    $fetched_job_count = 0;

    // Initialize link indexes for tracking
    $link_indexes = get_transient('rjobs_link_indexes');
    if ($link_indexes === false || count($link_indexes) !== count($all_links_settings)) {
        $link_indexes = array_fill(0, count($all_links_settings), 0);
        set_transient('rjobs_link_indexes', $link_indexes, HOUR_IN_SECONDS);
    }

    

    error_log('Link indexes: ' . print_r($link_indexes, true));
    error_log('All links settings: ' . print_r($all_links_settings, true));

    // Continue fetching jobs until the desired number of jobs have been fetched
    while ($fetched_job_count < $num_jobs) {
        $all_links_exhausted = true;

        // Loop through each link setting
        foreach ($all_links_settings as $index => $settings) {
            if ($fetched_job_count >= $num_jobs) {
                break; // Stop if the desired number of jobs have been fetched
            }

            $job_list_link = $settings['job_list_link'] ?? '';

            // Log the main link being fetched from
            error_log("Fetching from main link: $job_list_link");

            // Extract classes for job details
            $job_link_classes = explode(',', $settings['job_link_classes'] ?? '');
            $job_title_classes = explode(',', $settings['job_title_field'] ?? '');
            $job_type_classes = explode(',', $settings['job_type_field'] ?? '');
            $job_description_classes = explode(',', $settings['job_description_field'] ?? '');
            $job_company_logo_classes = explode(',', $settings['job_company_logo_field'] ?? '');
            $job_application_url_classes = explode(',', $settings['job_application_url_field'] ?? '');

            // Fetch job links from the transient or make a request if necessary
            $job_links = get_transient('job_list_transient_' . md5($job_list_link));
            if ($job_links === false) {
                // Fetch job details HTML content
                $job_response = wp_remote_get($job_list_link, array('timeout' => 30)); // Increase timeout if necessary
                if (is_wp_error($job_response)) {
                    error_log('Error fetching job list link: ' . $job_response->get_error_message());
                    continue; // Skip to next link settings
                }


                $job_body = wp_remote_retrieve_body($job_response);

                // Load job details HTML content
                $job_dom = new DOMDocument();
                libxml_use_internal_errors(true); // Suppress warnings
                $job_dom->loadHTML($job_body);
                libxml_clear_errors();

                $job_xpath = new DOMXPath($job_dom);

                // Fetch individual job links based on provided classes/selectors
                $job_links = array();
                foreach ($job_link_classes as $class) {
                    $job_link_elements = $job_xpath->query("//*[contains(@class, '$class')]/@href");
                    if ($job_link_elements) {
                        foreach ($job_link_elements as $element) {
                            $job_link = $element->nodeValue;
                            // Convert relative job link to absolute URL if necessary
                            $job_link = adjust_job_link($job_list_link, $job_link);
                            $job_links[] = $job_link;

                            // Log the job link and class used
                            error_log("Job link fetched: $job_link using class: $class");
                        }
                    }else {
                        // Log if no elements were found with the current class
                        error_log("No job links found using class: $class");
                    }

                }
       

                // Set transient to cache job links for a specified time (e.g., 1 hour)
                set_transient('job_list_transient_' . md5($job_list_link), $job_links, HOUR_IN_SECONDS);
            }

            // Check if there are job links available for this main link list
            if (isset($job_links[$link_indexes[$index]])) {
                $all_links_exhausted = false;

                // Fetch the next job link for this main link list
                $job_url = $job_links[$link_indexes[$index]];

                // Add log for the job link being fetched
                error_log("Fetching job link: $job_url");

                // Validate job URL
                if (!filter_var($job_url, FILTER_VALIDATE_URL)) {
                    $error_log = "Invalid job URL: $job_url";
                    error_log($error_log);
                    // Move to the next job link
                    $link_indexes[$index]++;
                    continue; // Skip to next job URL
                }

                try {


                    // Define allowed job types with normalized versions
                    $allowed_job_types = array(
                        'Freelance',
                        'Full Time',
                        'Internship',
                        'Part Time',
                        'Temporary'
                    );

                    // Function to normalize job type strings
                    if (!function_exists('normalize_job_type')) {
                        function normalize_job_type($job_type) {
                            // Remove hyphens and convert to title case
                            $job_type = str_replace('-', ' ', $job_type);
                            $job_type = ucwords(strtolower($job_type));
                            return $job_type;
                        }
                    }
                   // Fetch job details HTML content
                    $job_response = wp_remote_get($job_url, array('timeout' => 30)); // Increase timeout if necessary
                    if (is_wp_error($job_response)) {
                        $error_log = 'Error fetching job link: ' . $job_response->get_error_message();
                        error_log($error_log);
                        // Move to the next job link
                        $link_indexes[$index]++;
                        continue; // Skip to next job URL
                    }

                    $job_body = wp_remote_retrieve_body($job_response);

                    // Load job details HTML content
                    $job_dom = new DOMDocument();
                    libxml_use_internal_errors(true); // Suppress warnings
                    $job_dom->loadHTML($job_body);
                    libxml_clear_errors();

                    $job_xpath = new DOMXPath($job_dom);


                    // Define main categories and their related keywords
                    $categories_keywords = array(
                        'Development' => array('Developer', 'Engineer', 'Programmer', 'Coder', 'Devops', 'Solution Architect', 'Architect'),
                        'Design' => array('Designer', 'UX', 'UI', 'Graphic', 'Visual'),
                        'Sales' => array('Sales', 'Account', 'Business Development', 'BDR', 'Seller'),
                        'Marketing' => array('Marketing', 'SEO', 'SEM', 'Content', 'Brand'),
                        'Customer Support' => array('Customer Support', 'Support', 'Client Success', 'Helpdesk', 'Customer Service', 'Service Desk'),
                        'Content Creation' => array('Writer', 'Editor', 'Copywriter', 'Content', 'Journalist'),
                        'Data Analysis' => array('Data Analyst', 'Data Scientist', 'Data Engineer', 'Data', 'Analytics', 'Business Intelligence'),
                        'Management and Finance' => array('Manager', 'Director', 'Finance', 'CFO', 'Controller', 'Project Manager', 'PM'),
                        'Human Resources' => array('HR', 'Human Resources', 'Recruiter', 'Talent', 'People Operations'),
                        'All other Jobs' => array()
                    );

                    // Function to determine job category based on job title
                    if (!function_exists('determine_job_category')) {
                        function determine_job_category($job_title, $categories_keywords) {
                            foreach ($categories_keywords as $category => $keywords) {
                                foreach ($keywords as $keyword) {
                                    if (stripos($job_title, $keyword) !== false) {
                                        return $category;
                                    }
                                }
                            }
                            // If no keywords match, assign to "All other Jobs"
                            return 'All other Jobs';
                        }
                    }

                    // Function to ensure category exists or create it if missing
                    if (!function_exists('ensure_category_exists')) {
                        function ensure_category_exists($category_name) {
                            if (!term_exists($category_name, 'job_listing_category')) {
                                wp_insert_term($category_name, 'job_listing_category');
                                error_log("Category '$category_name' created.");
                            } else {
                                error_log("Category '$category_name' already exists.");
                            }
                        }
                    }


                    // Initialize variables to store fetched job details
                    $job_title = '';
                    $job_description = '';
                    $job_application_url = '';
                    $found_job_types = array();

                    // Fetch job title
                    foreach ($job_title_classes as $class) {
                        $job_title_elements = $job_xpath->query("//*[contains(@class, '$class')]");
                        if ($job_title_elements && $job_title_elements->length > 0) {
                            $job_title = trim($job_title_elements->item(0)->nodeValue);
                            error_log("Job title fetched: $job_title using class: $class");
                            break; // Stop checking classes once job title is found
                        }else {
                            // Log if no elements were found with the current class
                            error_log("No job title found using class: $class");
                        }
                    }
                
                    // Fetch job types
                    foreach ($job_type_classes as $class) {
                        $job_type_elements = $job_xpath->query("//*[contains(@class, '$class')]");
                        if ($job_type_elements && $job_type_elements->length > 0) {
                            foreach ($job_type_elements as $element) {
                                $fetched_job_type = trim($element->nodeValue);
                                $normalized_job_type = normalize_job_type($fetched_job_type);

                                // Check if the normalized job type is in the allowed job types list
                                if (in_array($normalized_job_type, $allowed_job_types)) {
                                    $found_job_types[] = $normalized_job_type;
                                    error_log("Job type found: $normalized_job_type using class: $class");
                                } 
                            }
                        } else {
                            // Log if no elements were found with the current class
                            error_log("No job type found using class: $class");
                        }
                    }

                    // Check adjacent tags for job type information
                    $job_type_elements = $job_xpath->query("//span[contains(text(), 'Job Type')]/following-sibling::span");
                    foreach ($job_type_elements as $element) {
                        $fetched_job_type = trim($element->nodeValue);
                        $normalized_job_type = normalize_job_type($fetched_job_type);

                        // Check if the normalized job type is in the allowed job types list
                        if (in_array($normalized_job_type, $allowed_job_types)) {
                            $found_job_types[] = $normalized_job_type;
                            error_log("Job type found in adjacent span: $normalized_job_type");
                            break; // Stop once a valid job type is found
                        } else {
                            error_log("Fetched job type from adjacent span ($normalized_job_type) is not in allowed list.");
                        }
                    }


                    // Determine the primary job type to use (e.g., first in the list)
                    $job_type = !empty($found_job_types) ? $found_job_types[0] : '';


                    
                    // Fetch job description
                    foreach ($job_description_classes as $class) {
                        $job_description_elements = $job_xpath->query("//*[contains(@class, '$class')]");
                        if ($job_description_elements && $job_description_elements->length > 0) {
                            $job_description = '';

                            foreach ($job_description_elements as $element) {
                                // Remove any child elements with the class 'job_application application'
                                $xpath_child_query = ".//*[contains(@class, 'job_application application')]";
                                $job_application_elements = $job_xpath->query($xpath_child_query, $element);

                                // Remove unwanted elements from the description
                                foreach ($job_application_elements as $application_element) {
                                    $application_element->parentNode->removeChild($application_element);
                                }

                                // Fetch the inner HTML of each element
                                $inner_html = '';
                                foreach ($element->childNodes as $child) {
                                    $inner_html .= $element->ownerDocument->saveHTML($child);
                                }
                                $job_description .= $inner_html;
                            }

                            // Sanitize and allow safe HTML
                            $allowed_html = array(
                                'p' => array(),
                                'a' => array('href' => array(), 'title' => array()),
                                'b' => array(),
                                'i' => array(),
                                'ul' => array(),
                                'ol' => array(),
                                'li' => array(),
                                'strong' => array(),
                                'em' => array(),
                                'blockquote' => array(),
                                'br' => array(),
                                'hr' => array(),
                                'h1' => array(),
                                'h2' => array(),
                                'h3' => array(),
                                'h4' => array(),
                                'h5' => array(),
                                'h6' => array()
                            );
                            $job_description = wp_kses($job_description, $allowed_html);
                            error_log("Job description fetched and cleaned using class: $class");
                            break; // Stop checking classes once job description is found
                        } else {
                            // Log if no elements were found with the current class
                            error_log("No job description found using class: $class");
                        }
                    }



                    // Define the base URL from the job URL
                    $base_url = parse_url($job_url);
                    $base_url = $base_url['scheme'] . '://' . $base_url['host']; // e.g., https://example.com
                    // Fetch company logo URL
                    foreach ($job_company_logo_classes as $class) {
                        $job_company_logo_elements = $job_xpath->query("//img[contains(@class, '$class')]");

                        if ($job_company_logo_elements && $job_company_logo_elements->length > 0) {
                            foreach ($job_company_logo_elements as $element) {
                                $src = $element->getAttribute('src');
                                $data_src = $element->getAttribute('data-src');

                                // Log details about the image found
                                error_log('Remote Job Aggregator: Checking node with class: ' . $class);
                                error_log('Remote Job Aggregator: Found src: ' . $src);
                                error_log('Remote Job Aggregator: Found data-src: ' . $data_src);

                                // Check src attribute
                                if (!empty($src) && strpos($src, 'data:image/gif') === false && strpos($src, 'data:') === false) {
                                    $job_company_logo_url = urldecode($src);
                                    if (parse_url($job_company_logo_url, PHP_URL_SCHEME) === null) {
                                        // URL is relative, prepend the base URL
                                        $job_company_logo_url = $base_url . $job_company_logo_url;
                                    }
                                    break 2; // Exit both foreach loops as we found a valid image
                                }

                                // Check data-src attribute if src is not valid
                                if (!empty($data_src) && strpos($data_src, 'data:image/gif') === false && strpos($data_src, 'data:') === false) {
                                    $job_company_logo_url = urldecode($data_src);
                                    if (parse_url($job_company_logo_url, PHP_URL_SCHEME) === null) {
                                        // URL is relative, prepend the base URL
                                        $job_company_logo_url = $base_url . $job_company_logo_url;
                                    }
                                    break 2; // Exit both foreach loops as we found a valid image
                                }
                            }

                            if (!empty($job_company_logo_url)) {
                                // Convert to absolute URL if necessary
                                $job_company_logo_url = adjust_job_link($job_url, $job_company_logo_url);

                                // Log the fetched company logo URL and class used
                                error_log("Fetched company logo URL: $job_company_logo_url using class: $class");

                                
                                break; // Stop checking classes once company logo is found and uploaded
                            } else {
                                // Log if no elements were found with the current class
                                error_log("No company logo found using class: $class");
                            }
                        }
                    }

  


                  // Fetch job application URL
                    foreach ($job_application_url_classes as $class) {
                        $job_application_url_elements = $job_xpath->query("//*[contains(@class, '$class')]/@href");
                        if ($job_application_url_elements && $job_application_url_elements->length > 0) {
                            $job_application_url = $job_application_url_elements->item(0)->nodeValue;

                            // Check if the URL contains email protection pattern
                            if (strpos($job_application_url, '/cdn-cgi/l/email-protection') !== false) {
                                // Log detection of email protection
                                error_log("Email protection detected in URL: $job_application_url");
                                
                                // Use the job link as the application URL instead
                                $job_application_url = $job_url;
                            } else {
                                // Convert to absolute URL if necessary
                                $job_application_url = adjust_job_link($job_url, $job_application_url);
                            }
                            
                            // Log the fetched application URL and class used
                            error_log("Fetched application URL: $job_application_url using class: $class");
                            break; // Stop checking classes once job application URL is found
                        } else {
                            // Log if no elements were found with the current class
                            error_log("No application URL found using class: $class");
                        }
                    }


                    // Check if job exists in WP Job Manager
                    $existing_job = rjobs_job_exists_in_wp_job_manager($job_title, $job_application_url);

                    if ($existing_job) {
                        // Job exists in WP Job Manager, skip fetching
                        error_log("Skipping existing job: $job_title");
                        $link_indexes[$index]++;
                        continue;
                    }

                    // Log errors if job details are not found
                    if (empty($job_title) || empty($job_description)) {
                        error_log("Missing job details for job link: $job_url. 
                            Job Title Classes: " . json_encode($job_title_classes) . ", 
                            Job Description Classes: " . json_encode($job_description_classes) . ", 
                            Job Application URL Classes: " . json_encode($job_application_url_classes));
                        $link_indexes[$index]++;
                        continue; // Skip to next job URL
                    }


                    // Determine job category based on the job title
                    $job_category = determine_job_category($job_title, $categories_keywords);

                    // Ensure the job category exists
                    ensure_category_exists($job_category);


                    // Insert job post
                    $job_data = array(
                        'post_title' => sanitize_text_field($job_title),
                        'post_content' => $job_description,
                        'post_status' => 'publish',
                        'post_type' => 'job_listing',
                        'comment_status' => 'closed',
                        'ping_status' => 'closed',
                        'meta_input' => array(
                            '_application' => sanitize_text_field($job_application_url), // Use sanitize_text_field to handle both email and URL
                            
                        )
                    );

                    $current_user = wp_get_current_user();
                    $job_data['post_author'] = $current_user->ID ? $current_user->ID : get_option('admin_email');

                    $job_id = wp_insert_post($job_data);
                    if (!is_wp_error($job_id)) {
                        $inserted_job_ids[] = $job_id;
                        $fetched_job_count++;
                        $link_indexes[$index]++; // Move to the next job link for this main link list
                            // Log the assignment of job type
                        if (!empty($job_type)) {
                            // Check if the term exists
                            $term = get_term_by('name', $job_type, 'job_listing_type');
                            if ($term) {
                                // Assign the job type term to the post
                                wp_set_post_terms($job_id, array($term->term_id), 'job_listing_type', true);
                                error_log("Job type '$job_type' has been assigned to job ID $job_id using taxonomy 'job_listing_type'.");
                            } else {
                                error_log("Job type '$job_type' does not exist in taxonomy 'job_listing_type'.");
                            }
                        } else {
                            error_log("No job type assigned to job ID $job_id.");
                        }

                            // Assign job category to taxonomy
                        $category_term = get_term_by('name', $job_category, 'job_listing_category');
                        if ($category_term) {
                            wp_set_post_terms($job_id, array($category_term->term_id), 'job_listing_category', true);
                            error_log("Job category '$job_category' has been assigned to job ID $job_id using taxonomy 'job_listing_category'.");
                        } else {
                            error_log("Job category '$job_category' does not exist in taxonomy 'job_listing_category'.");
                        }

                    } else {
                        $error_log = 'Error inserting job post: ' . $job_id->get_error_message();
                        error_log($error_log);
                    }

                    // Optionally, update post meta or add additional meta fields if needed
                    update_post_meta($job_id, '_job_type', $job_type);
                  

                    // Handle Upload and set the company logo as the featured image for the job post
                    if (!empty($job_company_logo_url)) {
                        $attachment_id = upload_image_to_wp($job_company_logo_url, $job_id);
                        if ($attachment_id) {
                            error_log("Company logo set as featured image for job ID $job_id.");
                        } else {
                            error_log("Failed to set company logo as featured image for job ID $job_id.");
                        }
                    }
                    

                } catch (Exception $e) {
                    $error_log = 'Error processing job link: ' . $job_url . '. Exception: ' . $e->getMessage();
                    error_log($error_log);
                }

                
                // Move to the next job link
                $link_indexes[$index]++;
            }
        
        }

        // Break the loop if all main link lists are exhausted
        if ($all_links_exhausted) {
            break;
        }


    }

    // Update the last fetch time
    update_option('rjobs_last_fetch_time', time());

    // Update transient with the new link indexes
    set_transient('rjobs_link_indexes', $link_indexes, HOUR_IN_SECONDS);


    // Log the number of jobs inserted
    error_log("Total jobs fetched and inserted: " . count($inserted_job_ids));

    // Return the array of inserted job IDs
    return $inserted_job_ids;
}
   
/**
 * Adjust job link based on the main job list link.
 */
function adjust_job_link($job_list_link, $job_link) {
    // Check if the job link is relative and make it absolute
    if (strpos($job_link, 'http') === false) {
        // Handle specific cases like the waivly link
        if (strpos($job_list_link, 'work.waivly.com') !== false && strpos($job_list_link, '/job-location-category/international') !== false) {
            $base_url = 'https://work.waivly.com';
            $job_link = $base_url . '/' . ltrim($job_link, '/');
        } else {
            // Generic case for other links
            $parsed_url = parse_url($job_list_link);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            $job_link = $base_url . '/' . ltrim($job_link, '/');
        }
    }

    return $job_link;
}


// Check if a job already exists in WP Job Manager based on application URL.
function rjobs_job_exists_in_wp_job_manager($job_title, $job_application_url = '') {
    // Query WP Job Manager for existing jobs with the given application URL
    $args = array(
        'post_type' => 'job_listing',
        'post_status' => 'publish',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_application', // Ensure this matches your meta key
                'value' => $job_application_url,
                'compare' => '='
            )
        ),
        's' => $job_title, // Search for the job title in post content
        'posts_per_page' => 1
    );

    $query = new WP_Query($args);
    
    // Log the check for debugging
    error_log("Checking if job exists in WP Job Manager: Title: $job_title, Application URL: $job_application_url");

    if ($query->have_posts()) {
        return $query->posts[0]; // Return the post object if it exists
    } else {
        return null; // Return null if the job does not exist
    }
}



