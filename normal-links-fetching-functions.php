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
            $job_company_name_classes = explode(',', $settings['job_company_name_field'] ?? ''); 
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
                    $found_job_types = array();

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
                    $job_company_name = '';
                    $job_company_url = ''; 
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

                
                    foreach ($job_company_name_classes as $class) {
                        $company_name_elements = $job_xpath->query("//*[contains(@class, '$class')]");
                        if ($company_name_elements && $company_name_elements->length > 0) {
                            foreach ($company_name_elements as $element) {
                                $job_company_name = trim($element->nodeValue);
                                if (strtolower($job_company_name) !== 'about') {
                                    $job_company_name = $job_company_name;
                                    error_log("Company name fetched: $job_company_name using class: $class");
                                    break 2; // Stop checking once a valid company name is found
                                } else {
                                    // Log if the current element's value is "about"
                                    error_log("Company name labeled as 'about' found using class: $class");
                                }
                            }
                        } else {
                            // Log if no elements were found with the current class
                            error_log("No company name found using class: $class");
                        }
                    }

                    // If no company name was found using HTML classes, try extracting it from JSON-LD schema
                    if (empty($job_company_name)) {
                        // Fetch all <script> elements with type "application/ld+json"
                        $json_ld_elements = $job_xpath->query("//script[@type='application/ld+json']");
                        foreach ($json_ld_elements as $json_ld_element) {
                            $json_content = $json_ld_element->nodeValue;
                            // Decode the JSON content
                            $json_data = json_decode($json_content, true);

                            // Handle cases where the JSON-LD may be an array
                            if (is_array($json_data)) {
                                // Loop through if there are multiple JSON-LD items in the script
                                foreach ($json_data as $json_item) {
                                    if (isset($json_item['hiringOrganization']['@type']) 
                                        && $json_item['hiringOrganization']['@type'] === 'Organization' 
                                        && isset($json_item['hiringOrganization']['name'])) {
                                        $job_company_name = $json_item['hiringOrganization']['name'];
                                        error_log("Company name fetched from schema: $job_company_name");
                                        break 2; // Stop after finding the first matching schema
                                    }
                                }
                            } else {
                                // Single JSON-LD object handling
                                if (isset($json_data['hiringOrganization']['@type']) 
                                    && $json_data['hiringOrganization']['@type'] === 'Organization' 
                                    && isset($json_data['hiringOrganization']['name'])) {
                                    $job_company_name = $json_data['hiringOrganization']['name'];
                                    error_log("Company name fetched from schema: $job_company_name");
                                    break; // Stop after finding the first matching schema
                                }
                            }
                        }

                        // If no company name was found in the schema, log the result
                        if (empty($job_company_name)) {
                            error_log("No company name found in schema.");
                        }
                    }

                    // Final logging of company name
                    error_log("Final Company Name: $job_company_name");



                    // Define a list of social media domains to exclude for company URLs
                    $social_media_domains = ['facebook.com', 'twitter.com', 'linkedin.com'];

                    $json_ld_elements = $job_xpath->query("//script[@type='application/ld+json']");
                    foreach ($json_ld_elements as $json_ld_element) {
                        $json_content = $json_ld_element->nodeValue;
                        // Decode the JSON content
                        $json_data = json_decode($json_content, true);

                        // Handle cases where the JSON-LD may be an array
                        if (is_array($json_data)) {
                            // Loop through if there are multiple JSON-LD items in the script
                            foreach ($json_data as $json_item) {
                                if (isset($json_item['sameAs'])) {
                                    $sameAs = $json_item['sameAs'];

                                    // Check if sameAs is an array or a string
                                    if (is_array($sameAs)) {
                                        foreach ($sameAs as $company_url) {
                                            $is_social_media = false;

                                            // Validate the company URL to exclude social media links
                                            foreach ($social_media_domains as $domain) {
                                                if (strpos($company_url, $domain) !== false) {
                                                    $is_social_media = true;
                                                    break;
                                                }
                                            }

                                            // If it's not a social media link, assign the company URL
                                            if (!$is_social_media) {
                                                $job_company_url = $company_url;
                                                error_log("Company website fetched from schema: $job_company_url");
                                                break 2; // Stop after finding the first valid company URL
                                            } else {
                                                error_log("Excluded social media URL from schema: $company_url");
                                            }
                                        }
                                    } else {
                                        // Handle case where sameAs is a single URL string
                                        $company_url = $sameAs;
                                        $is_social_media = false;

                                        // Validate the company URL to exclude social media links
                                        foreach ($social_media_domains as $domain) {
                                            if (strpos($company_url, $domain) !== false) {
                                                $is_social_media = true;
                                                break;
                                            }
                                        }

                                        // If it's not a social media link, assign the company URL
                                        if (!$is_social_media) {
                                            $job_company_url = $company_url;
                                            error_log("Company website fetched from schema: $job_company_url");
                                            break 2; // Stop after finding the first valid company URL
                                        } else {
                                            error_log("Excluded social media URL from schema: $company_url");
                                        }
                                    }
                                }
                            }
                        } else {
                            // Handle single JSON-LD object
                            if (isset($json_data['sameAs'])) {
                                $sameAs = $json_data['sameAs'];

                                // Check if sameAs is an array or a string
                                if (is_array($sameAs)) {
                                    foreach ($sameAs as $company_url) {
                                        $is_social_media = false;

                                        // Validate the company URL to exclude social media links
                                        foreach ($social_media_domains as $domain) {
                                            if (strpos($company_url, $domain) !== false) {
                                                $is_social_media = true;
                                                break;
                                            }
                                        }

                                        // If it's not a social media link, assign the company URL
                                        if (!$is_social_media) {
                                            $job_company_url = $company_url;
                                            error_log("Company website fetched from schema: $job_company_url");
                                            break 2; // Stop after finding the first valid company URL
                                        } else {
                                            error_log("Excluded social media URL from schema: $company_url");
                                        }
                                    }
                                } else {
                                    // Handle case where sameAs is a single URL string
                                    $company_url = $sameAs;
                                    $is_social_media = false;

                                    // Validate the company URL to exclude social media links
                                    foreach ($social_media_domains as $domain) {
                                        if (strpos($company_url, $domain) !== false) {
                                            $is_social_media = true;
                                            break;
                                        }
                                    }

                                    // If it's not a social media link, assign the company URL
                                    if (!$is_social_media) {
                                        $job_company_url = $company_url;
                                        error_log("Company website fetched from schema: $job_company_url");
                                        break; // Stop after finding the first valid company URL
                                    } else {
                                        error_log("Excluded social media URL from schema: $company_url");
                                    }
                                }
                            }
                        }
                    }
                    // Log the result if no valid company URL was found from schema
                    if (empty($job_company_url)) {
                        error_log("No valid company website found in schema.");

                        // Fallback: Search for company URL using defined HTML classes
                        $company_url_classes = [
                            'links_sm', // This class should be included to match the example provided
                            'w-richtext',
                            'dib link dim grey-700',
                            'ppma-author-user_url-profile-data ppma-author-field-meta ppma-author-field-type-url',
                            'flex flex-wrap items-center gap-1 text-sm',
                            'text-justify prose max-w-none'
                        ];

                        // Fetch company URL from HTML classes as a fallback
                        foreach ($company_url_classes as $class) {
                            // Query for <a> tags within elements with the class
                            $company_url_elements = $job_xpath->query("//*[contains(@class, '$class')]//a/@href");
                            foreach ($company_url_elements as $element) {
                                $company_url = trim($element->nodeValue);
                                $is_social_media = false;

                                // Validate the company URL to exclude social media links
                                foreach ($social_media_domains as $domain) {
                                    if (strpos($company_url, $domain) !== false) {
                                        $is_social_media = true;
                                        break;
                                    }
                                }

                                // If it's not a social media link, assign the company URL and stop the loop
                                if (!$is_social_media) {
                                    $job_company_url = $company_url;
                                    error_log("Company website fetched from HTML class '$class' (via <a> tag): $job_company_url");
                                    break 2; // Exit both loops once a valid company URL is found
                                } else {
                                    error_log("Excluded social media URL from HTML class '$class' (via <a> tag): $company_url");
                                }
                            }

                            // If no URL was found via <a> tags, check for @href directly
                            if (empty($job_company_url)) {
                                $company_url_elements = $job_xpath->query("//*[contains(@class, '$class')]/@href");
                                foreach ($company_url_elements as $element) {
                                    $company_url = trim($element->nodeValue);
                                    $is_social_media = false;

                                    // Validate the company URL to exclude social media links
                                    foreach ($social_media_domains as $domain) {
                                        if (strpos($company_url, $domain) !== false) {
                                            $is_social_media = true;
                                            break;
                                        }
                                    }

                                    // If it's not a social media link, assign the company URL and stop the loop
                                    if (!$is_social_media) {
                                        $job_company_url = $company_url;
                                        error_log("Company website fetched from HTML class '$class' (direct @href): $job_company_url");
                                        break 2; // Exit both loops once a valid company URL is found
                                    } else {
                                        error_log("Excluded social media URL from HTML class '$class' (direct @href): $company_url");
                                    }
                                }
                            }
                        }

                        // Log the final result if still no valid company URL is found
                        if (empty($job_company_url)) {
                            error_log("No valid company website found using HTML class fallback.");
                        }
                    }

                    // Output the final company URL
                    if (!empty($job_company_url)) {
                        echo "Company URL: " . esc_url($job_company_url);
                    }




                
                            // Fetch job types using defined classes
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

                            // Determine the primary job type to use
                            $job_type = !empty($found_job_types) ? $found_job_types[0] : 'Full Time'; // Default to 'Full Time' if none found

                            error_log("Final job type: $job_type");





                            // Attempt to fetch the description using HTML classes
                            foreach ($job_description_classes as $class) {
                                $job_description_elements = $job_xpath->query("//*[contains(@class, '$class')]");
                                if ($job_description_elements && $job_description_elements->length > 0) {
                                    $job_description = '';

                                    // Check if the job listing URL is from remotive.com
                                    if (strpos($job_list_link, 'remotive.com') !== false) {
                                        // Remotive.com-specific logic
                                        foreach ($job_description_elements as $element) {
                                            // Remove unwanted classes for remotive.com listings
                                            $exclude_classes = [
                                                'tw-hidden md:tw-pt-16 tw-p-6 md:tw-items-start md:tw-justify-between md:tw-flex-wrap md:tw-flex md:tw-content-left',
                                                'remotive-text-smaller',
                                                'tw-hidden md:tw-block remotive-text-smaller tw-p-4 tw-rounded-b-md tw-text-left remotive-bg-light-blue',
                                                'remotive-text-smaller remotive-bg-light md:remotive-bg-white tw-mx-auto md:tw-mx-4 tw-mt-4 tw-mb-0 tw-rounded-md',
                                                'remotive-bg-light tw-mt-2 tw-p-4 tw-rounded-md',
                                                'h2 remotive-text-bigger',
                                                'back',
                                                'remotive-btn-orange',
                                                'form',
                                                'tw-mt-4',
                                                'remotive-bg-light tw-mt-2 tw-p-4 tw-rounded-md'
                                            ];

                                            foreach ($exclude_classes as $exclude_class) {
                                                $xpath_exclude_query = ".//*[contains(@class, '$exclude_class') or ancestor::*[contains(@class, '$exclude_class')]]";
                                                $exclude_elements = $job_xpath->query($xpath_exclude_query, $element);
                                                foreach ($exclude_elements as $exclude_element) {
                                                    $exclude_element->parentNode->removeChild($exclude_element);
                                                }
                                            }

                                            // Fetch the inner HTML of each element
                                            $inner_html = '';
                                            foreach ($element->childNodes as $child) {
                                                $inner_html .= $element->ownerDocument->saveHTML($child);
                                            }

                                            // Remove excessive newlines and breaks that cause formatting issues
                                            $inner_html = preg_replace("/(<br\s*\/?>\s*){2,}/", "<br>", $inner_html); // Replace multiple <br> with single <br>
                                            $inner_html = preg_replace("/\n\s*\n+/", "\n", $inner_html); // Replace multiple newlines with a single newline

                                            $job_description .= $inner_html;
                                        }

                                        // Remove unwanted phrases from Remotive descriptions
                                        $unwanted_phrases = [
                                            '50% off',
                                            'in September 2024',
                                            'By joining now, I confirm that I have read and I accept',
                                            'Remotive',
                                            'Code of Conduct'
                                        ];
                                        foreach ($unwanted_phrases as $phrase) {
                                            $job_description = preg_replace('/' . preg_quote($phrase, '/') . '\s*/i', '', $job_description);
                                        }

                                    } else {
                                        // Original logic for other job links
                                        foreach ($job_description_elements as $element) {
                                            // Remove any child elements with the class 'job_application application'
                                            $xpath_child_query = ".//*[contains(@class, 'job_application application')]";
                                            $job_application_elements = $job_xpath->query($xpath_child_query, $element);

                                            foreach ($job_application_elements as $application_element) {
                                                $application_element->parentNode->removeChild($application_element);
                                            }

                                            // Fetch the inner HTML of each element
                                            $inner_html = '';
                                            foreach ($element->childNodes as $child) {
                                                $inner_html .= $element->ownerDocument->saveHTML($child);
                                            }

                                            // Remove excessive newlines and breaks for better formatting
                                            $inner_html = preg_replace("/(<br\s*\/?>\s*){2,}/", "<br>", $inner_html);
                                            $inner_html = preg_replace("/\n\s*\n+/", "\n", $inner_html);

                                            $job_description .= $inner_html;
                                        }
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

                                    // Correct encoding issues
                                    $job_description = preg_replace('/\xC2\xA0/', ' ', $job_description); // Replace non-breaking space
                                    $job_description = preg_replace('/Â/', '', $job_description); // Remove stray Â characters
                                    $job_description = preg_replace('/[^\x00-\x7F]/', '', $job_description); // Remove non-ASCII characters

                                    error_log("Job description fetched and cleaned using class: $class");
                                    break; // Stop checking classes once job description is found
                                }
                            }



                        


                           



                        // Define the base URL from the job URL
                            $base_url = parse_url($job_url);
                            $base_url = $base_url['scheme'] . '://' . $base_url['host']; // e.g., https://example.com

                            // Placeholder URL
                            $placeholder_logo_url = 'https://jobs.bgathuita.com/wp-content/uploads/sites/2/2024/09/logo-placeholder-image.png';

                            // Fetch company logo URL
                            foreach ($job_company_logo_classes as $class) {
                                $job_company_logo_elements = $job_xpath->query("//img[contains(@class, '$class')]");

                                if ($job_company_logo_elements && $job_company_logo_elements->length > 0) {
                                    foreach ($job_company_logo_elements as $element) {
                                        $src = $element->getAttribute('src');
                                        $data_src = $element->getAttribute('data-src');
                                        $data_lazyload = $element->getAttribute('data-lazyload');

                                        // Log details about the image found
                                        error_log('Remote Job Aggregator: Checking node with class: ' . $class);
                                        error_log('Remote Job Aggregator: Found src: ' . $src);
                                        error_log('Remote Job Aggregator: Found data-src: ' . $data_src);
                                        error_log('Remote Job Aggregator: Found data-lazyload: ' . $data_lazyload);

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

                                        // Check data-lazyload attribute if src and data-src are not valid
                                        if (!empty($data_lazyload) && strpos($data_lazyload, 'data:image/gif') === false && strpos($data_lazyload, 'data:') === false) {
                                            $job_company_logo_url = urldecode($data_lazyload);
                                            if (parse_url($job_company_logo_url, PHP_URL_SCHEME) === null) {
                                                // URL is relative, prepend the base URL
                                                $job_company_logo_url = $base_url . $job_company_logo_url;
                                            }
                                            break 2; // Exit both foreach loops as we found a valid image
                                        }
                                    }

                                    // If no valid logo URL is found, use the placeholder URL
                                    if (empty($job_company_logo_url)) {
                                        $job_company_logo_url = $placeholder_logo_url;
                                    }

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


                        // Fetch job application URL
                            foreach ($job_application_url_classes as $class) {
                                // First, try to find the URL in elements with the specified class
                                $job_application_url_elements = $job_xpath->query("//*[contains(@class, '$class')]/@href");
                                
                                if ($job_application_url_elements && $job_application_url_elements->length > 0) {
                                    $job_application_url = $job_application_url_elements->item(0)->nodeValue;

                                     // Check if the URL contains email protection pattern or is an email
                                    if (strpos($job_application_url, '/cdn-cgi/l/email-protection') !== false || filter_var($job_application_url, FILTER_VALIDATE_EMAIL)) {
                                        // Log detection of email or email protection
                                        error_log("Skipping Job since an Email or email protection was detected in URL: $job_application_url");
                                        
                                        // Skip the entire job if it contains or is an email
                                        continue 2; // Skip the current job and move to the next one
                                    } else {
                                        // Convert to absolute URL if necessary
                                        $job_application_url = adjust_job_link($job_url, $job_application_url);
                                    }

                                    // Fetch all links from settings (default and custom)
                                    $all_job_list_links = array_merge(
                                        array_column($default_link_settings, 'job_list_link'),
                                        array_column($custom_link_settings, 'job_list_link')
                                    );

                                    // Extract the domain of the job application URL
                                    $job_application_url_domain = parse_url($job_application_url, PHP_URL_HOST);

                                    // If the job application URL matches any of the domains in settings, skip the job
                                    foreach ($all_job_list_links as $job_list_link) {
                                        $job_list_link_domain = parse_url($job_list_link, PHP_URL_HOST);
                                        if ($job_application_url_domain === $job_list_link_domain) {
                                            error_log("Job application URL matches a main link domain. Skipping job: $job_application_url");
                                            continue 2; // Skip this job
                                        }
                                    }

                                    // Validate the URL and ensure it's not a 404 or invalid
                                    if (!filter_var($job_application_url, FILTER_VALIDATE_URL) || !is_url_valid($job_application_url)) {
                                        error_log("Invalid job application URL: $job_application_url. Skipping job.");
                                        continue; // Skip invalid URLs
                                    }

                                    // Log the fetched application URL and class used
                                    error_log("Fetched application URL: $job_application_url using class: $class");
                                    break; // Stop checking classes once job application URL is found
                                } else {
                                    // If no URL is found in elements with the specified class, try to find it in parent <a> tags
                                    $job_application_button_elements = $job_xpath->query("//*[contains(@class, '$class')]//ancestor::a/@href");
                                    if ($job_application_button_elements && $job_application_button_elements->length > 0) {
                                        $job_application_url = $job_application_button_elements->item(0)->nodeValue;

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

                                        // Fetch all links from settings (default and custom)
                                        $all_job_list_links = array_merge(
                                            array_column($default_link_settings, 'job_list_link'),
                                            array_column($custom_link_settings, 'job_list_link')
                                        );


                                        // Extract the domain of the job application URL
                                        $job_application_url_domain = parse_url($job_application_url, PHP_URL_HOST);

                                        // If the job application URL matches any of the domains in settings, skip the job
                                        foreach ($all_job_list_links as $job_list_link) {
                                            $job_list_link_domain = parse_url($job_list_link, PHP_URL_HOST);
                                            if ($job_application_url_domain === $job_list_link_domain) {
                                                error_log("Job application URL matches a main link domain. Skipping job: $job_application_url");
                                                continue 2; // Skip this job
                                            }
                                        }

                                        // Validate the URL and ensure it's not a 404 or invalid
                                        if (!filter_var($job_application_url, FILTER_VALIDATE_URL) || !is_url_valid($job_application_url)) {
                                            error_log("Invalid job application URL: $job_application_url. Skipping job.");
                                            continue; // Skip invalid URLs
                                        }

                                        // Log the fetched application URL and class used
                                        error_log("Fetched application URL: $job_application_url using class: $class");
                                        break; // Stop checking classes once job application URL is found
                                    } else {
                                        // Log if no elements were found with the current class
                                        error_log("No application URL found using class: $class");
                                    }
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
                                    Job Company Name Classes: " . json_encode($job_company_name_classes) . ", 
                                    Job Description Classes: " . json_encode($job_description_classes) . ", 
                                    Job Application URL Classes: " . json_encode($job_application_url_classes));
                                $link_indexes[$index]++;
                                continue; // Skip to next job URL
                            }


                            // Determine job category based on the job title
                            $job_category = determine_job_category($job_title, $categories_keywords);

                            // Ensure the job category exists
                            ensure_category_exists($job_category);


                    $post_status = get_option('rjobs_post_status', 'publish'); // Default to 'publish' if no status is selected

                    // Insert job post
                    
                    $job_data = array(
                        'post_title' => sanitize_text_field($job_title),
                        'post_content' => $job_description,
                        'post_status'   => $post_status, 
                        'post_type' => 'job_listing',
                        'comment_status' => 'closed',
                        'ping_status' => 'closed',
                        'meta_input' => array(
                            '_application' => sanitize_text_field($job_application_url), // Use sanitize_text_field to handle both email and URL
                            '_company_name'  => sanitize_text_field($job_company_name),
                            '_company_website' => esc_url_raw($job_company_url),
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
    // Check if the job link is an absolute URL
    if (strpos($job_link, 'http') === false) {
        // Extract the base URL (scheme and host) from the job_list_link
        $parsed_url = parse_url($job_list_link);
        $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

        // Check if the job link is a relative path and prepend the base URL
        if (strpos($job_link, '/') === 0) {
            // Case where the job link is a relative path (starts with '/')
            $job_link = rtrim($base_url, '/') . $job_link;
        } else {
            // Case where the job link is a relative URL, but missing the starting '/'
            // Simply append the relative job link to the base URL
            $job_link = rtrim($base_url, '/') . '/' . ltrim($job_link, '/');
        }
    }

    // Remove any duplicate slashes, especially after the base URL
    $job_link = preg_replace('#(?<!:)//+#', '/', $job_link);

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


function is_url_valid($url) {
    // Initialize cURL session
    $ch = curl_init($url);
    
    // Set cURL options to make a HEAD request
    curl_setopt($ch, CURLOPT_NOBODY, true); // Don't download the body
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout after 10 seconds
    
    // Execute the cURL request
    curl_exec($ch);
    
    // Get the HTTP status code
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Close the cURL session
    curl_close($ch);
    
    // Check if the response code is in the 200-399 range (successful responses)
    return ($http_code >= 200 && $http_code < 400);
}

