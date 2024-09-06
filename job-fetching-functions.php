<?php
include_once(plugin_dir_path(__FILE__) . 'admin-functions.php');
include_once(plugin_dir_path(__FILE__) . 'helper-functions.php');
include_once(plugin_dir_path(__FILE__) . 'normal-links-fetching-functions.php');

function rjobs_fetch_jobs() {
    $rss_feeds = get_option('rjobs_rss_feeds', array());
    $num_jobs = absint(get_option('rjobs_num_jobs', 10));
    $jobs = array();
    $total_jobs_fetched = 0;

    // Get the schedule frequency from options
    $schedule_frequency = get_option('rjobs_schedule_frequency', 'hourly');

    // Calculate interval based on schedule frequency
    $interval_seconds = rjobs_get_schedule_interval_seconds($schedule_frequency);

    // Get the timestamp of the last job fetch
    $last_fetch_time = get_option('rjobs_last_fetch_time', 0);

    // Check if the schedule interval has elapsed
    if (time() - $last_fetch_time >= $interval_seconds) {
        $date_threshold = date('Y-m-d', strtotime('-30 days'));

        $job_types = array(
            'Contractor' => array('contract', 'contractor'),
            'Full Time' => array('full time', 'full-time', 'permanent'),
            'Internship' => array('internship', 'intern', 'trainee'),
            'Part Time' => array('part time', 'part-time'),
            'Temporary' => array('temporary', 'temp', 'seasonal')
        );

        // Store previously fetched jobs
        $fetched_job_links = get_option('rjobs_fetched_job_links', array());

        // Filter enabled RSS feeds
        $enabled_rss_feeds = array_filter($rss_feeds, function($feed) {
            return isset($feed['enabled']) && $feed['enabled'];
        });

        // Get custom RSS feeds from settings
        $custom_rss_feeds = array_filter($rss_feeds, function($feed) {
            return isset($feed['name']) && $feed['name'] === 'Custom RSS Feed';
        });

        $all_rss_feeds = array_merge(
            array_column($enabled_rss_feeds, 'url'),
            array_column($custom_rss_feeds, 'url')
        );

        if (count($all_rss_feeds) > 0) {
            // Initialize feed indexes to track progress
            $feed_indexes = array();
            foreach ($all_rss_feeds as $feed_url) {
                $feed_indexes[$feed_url] = 0;
            }

            while ($total_jobs_fetched < $num_jobs) {
                $job_found = false;

                foreach ($all_rss_feeds as $feed_url) {
                    error_log("Fetching jobs from feed: $feed_url");
                    $feed_items = fetch_feed_items($feed_url);
                    if (!is_wp_error($feed_items) && is_array($feed_items)) {
                        // Get the current index for the feed
                        $index = $feed_indexes[$feed_url] ?? 0;
                        if ($index >= count($feed_items)) {
                            $feed_indexes[$feed_url] = 0; // Reset index if it exceeds feed items
                            continue;
                        }

                        $item = $feed_items[$index] ?? null;
                        $feed_indexes[$feed_url]++;

                        if ($item === null) {
                            continue; // Skip if the item is null
                        }

                        $pub_date = $item->get_date('Y-m-d');
                        error_log("Processing job with publication date: $pub_date");

                        // Only skip jobs older than 30 days
                        if ($pub_date < $date_threshold) {
                            error_log("Skipping job with publication date: $pub_date (older than 30 days)");
                            continue;
                        }

                        // Check if job exists in WP Job Manager
                        $job_url = esc_url($item->get_link());
                        $job_id = rjobs_job_exists_in_wp_job_manager($job_url);

                        if ($job_id) {
                            // Job exists in WP Job Manager, skip fetching
                            error_log("Skipping job as it exists in WP Job Manager: $job_url");
                            continue;
                        }

                        // Process the job listing
                        $author = $item->get_author();
                        $company_name = $author ? $author->get_name() : '';
                        $company_website = $author ? $author->get_link() : '';
                        $location = '';
                        $categories = array();

                        // Extract categories and tags from the item
                        $category_count = 0;
                        $tag_count = 0;
                        foreach ($item->get_categories() as $category) {
                            $categories[] = $category->get_label();
                            $category_count++;
                            if ($category_count >= 2) break; // Limit to at most two categories
                        }
                        if (method_exists($item, 'get_tags')) {
                            foreach ($item->get_tags() as $tag) {
                                $categories[] = $tag->get_label();
                                $tag_count++;
                                if ($tag_count >= 2) break; // Limit to at most two tags
                            }
                        }
                        $categories = array_unique($categories);
                        $categories = array_slice($categories, 0, 2);

                        // Determine job type from feed
                        $feed_job_type = '';
                        foreach ($item->get_categories() as $category) {
                            $label = strtolower($category->get_label());
                            foreach ($job_types as $type => $keywords) {
                                if (in_array($label, $keywords)) {
                                    $feed_job_type = $type;
                                    break 2;
                                }
                            }
                        }
                        $job_type = $feed_job_type ? $feed_job_type : 'Full Time';

                        // Insert job post first
                        $job_data = array(
                            'post_title' => sanitize_text_field($item->get_title()),
                            'post_content' => wp_kses_post($item->get_content()),
                            'post_status' => 'publish',
                            'post_type' => 'job_listing',
                            'comment_status' => 'closed',
                            'ping_status' => 'closed',
                            'meta_input' => array(
                                '_job_location' => sanitize_text_field($location),
                                '_company_name' => sanitize_text_field($company_name),
                                '_company_website' => esc_url($company_website),
                                '_application' => esc_url($job_link),
                                '_job_type' => $job_type
                            )
                        );

                        $current_user = wp_get_current_user();
                        $job_data['post_author'] = $current_user->ID ? $current_user->ID : get_option('admin_email');

                        $job_id = wp_insert_post($job_data);
                        if (!is_wp_error($job_id)) {
                            $category_ids = array();
                            foreach ($categories as $category_name) {
                                $term = get_term_by('name', $category_name, 'job_listing_category');
                                if (!is_wp_error($term) && $term !== false) {
                                    $category_ids[] = $term->term_id;
                                } else {
                                    $new_category = wp_insert_term($category_name, 'job_listing_category');
                                    if (!is_wp_error($new_category)) {
                                        $category_ids[] = $new_category['term_id'];
                                    } else {
                                        error_log('Error creating category "' . $category_name . '": ' . $new_category->get_error_message());
                                    }
                                }
                            }
                            $category_ids = array_slice($category_ids, 0, 2);
                            wp_set_object_terms($job_id, $category_ids, 'job_listing_category', false);
                            wp_set_object_terms($job_id, $job_type, 'job_listing_type', false);

                            // Process job description to apply styling if needed
                            $description = wp_kses_post($item->get_content());
                            $styled_description = rjobs_apply_description_styling($description);

                            // Check if there's an image in the RSS feed description
                            preg_match('/src=["\']([^"\']+)\?/i', $description, $image_matches);
                            $featured_image_url = !empty($image_matches[1]) ? $image_matches[1] : '';

                            // Log the source of the image if found in the description
                            if ($featured_image_url) {
                                error_log('Remote Job Aggregator: Image found in description for job ID ' . $job_id . ' URL: ' . $featured_image_url);
                            } else {
                                error_log('Remote Job Aggregator: No image found in description for job ID ' . $job_id);
                            }

                            // Check if there's an image in the RSS feed media:content
                            if (empty($featured_image_url)) {
                                $media_content_url = ''; // Default to empty

                                // Fetch media:content elements from the current job feed item
                                $media_content = $item->get_enclosures();
                                foreach ($media_content as $media) {
                                    if ($media->get_medium() == 'image' && $media->get_link()) {
                                        $media_content_url = $media->get_link();
                                        break;
                                    }
                                }

                                $featured_image_url = $media_content_url;

                                // Log the source of the image if found in media:content
                                if ($featured_image_url) {
                                    error_log('Remote Job Aggregator: Image found in media:content for job ID ' . $job_id . ' URL: ' . $featured_image_url);
                                } else {
                                    error_log('Remote Job Aggregator: No image found in media:content for job ID ' . $job_id);
                                }
                            }

                            $image_set = false;

                            // Attempt to set featured image from description or media:content
                            if ($featured_image_url && !should_skip_image($featured_image_url)) {
                                $adjusted_image_url = remove_imgix_parameters($featured_image_url); // Remove imgix parameters
                                $featured_image_id = upload_image_to_wp($adjusted_image_url, $job_id);
                                if ($featured_image_id) {
                                    set_post_thumbnail($job_id, $featured_image_id);
                                    $image_set = true;
                                    error_log('Remote Job Aggregator: Successfully set featured image for job ID ' . $job_id . ' URL: ' . $featured_image_url);
                                } else {
                                    error_log('Remote Job Aggregator: Failed to set featured image from URL (' . $featured_image_url . ') for job ID ' . $job_id);
                                }
                            } else {
                                error_log('Remote Job Aggregator: Skipping invalid or empty featured image URL for job ID ' . $job_id);
                            }

                            // If no suitable image found in description, fetch from application URL
                            if (!$image_set) {
                                $company_logo = fetch_company_logo_from_url(esc_url($item->get_link()));

                                if ($company_logo) {
                                    $company_logo_id = upload_image_to_wp($company_logo, $job_id);
                                    if ($company_logo_id) {
                                        set_post_thumbnail($job_id, $company_logo_id);
                                        $image_set = true;
                                    } else {
                                        error_log('Remote Job Aggregator: Failed to upload company logo as featured image for job ID ' . $job_id);
                                    }
                                } else {
                                    error_log('Remote Job Aggregator: No suitable image found for job ID ' . $job_id);
                                }
                            }

                            // Log error if no image could be set
                            if (!$image_set) {
                                error_log('Remote Job Aggregator: No suitable image found for job ID ' . $job_id);
                            }

                            $jobs[] = array(
                                'ID' => $job_id,
                                'post_title' => $item->get_title(),
                                'company_name' => $company_name,
                                'company_website' => $company_website,
                                'job_location' => $location,
                                'job_categories' => $categories,
                                'job_url' => esc_url($item->get_link())
                            );
                            $total_jobs_fetched++;
                            $job_found = true;

                            // Add the job link to the fetched job links array
                            $fetched_job_links[] = $job_link;

                            if ($total_jobs_fetched >= $num_jobs) break 2; // Exit both foreach loops if the limit is reached
                        } else {
                            error_log('Remote Job Aggregator: Error inserting job with title: ' . $item->get_title());
                            error_log('Remote Job Aggregator: Error message: ' . $job_id->get_error_message());
                        }
                    } else {
                        error_log('RSS Feed Error: ' . $feed_items->get_error_message());
                    }
                }

                if (!$job_found) {
                    break; // If no job was found in this round, exit the loop
                }
            }
        } else {
            error_log('No RSS Feeds configured.');
        }

        update_option('rjobs_last_fetch_time', time());
        update_option('rjobs_fetched_job_links', $fetched_job_links); // Update fetched job links option

    } else {
        $time_until_next_fetch = $interval_seconds - (time() - $last_fetch_time);
        wp_schedule_single_event(time() + $time_until_next_fetch, 'rjobs_fetch_jobs_hook');
    }

    if ($total_jobs_fetched >= $num_jobs) {
        rjobs_reactivate_cron();
    }

    error_log("Remote Job Aggregator: $total_jobs_fetched jobs fetched via cron.");

    return $jobs;
}
function fetch_feed_items($feed_url) {
    // Implement your logic to fetch feed items from $feed_url
    // Example:
    $feed = fetch_feed($feed_url);
    if (!is_wp_error($feed)) {
        $items = $feed->get_items();
        return $items;
    } else {
        // Log the error for debugging
        error_log('Error fetching feed from URL: ' . $feed_url . '. Error: ' . $feed->get_error_message());    
        return $feed; // Return WP_Error object on error
    }
}

function rjobs_apply_description_styling($description) {
    $description = preg_replace('/To apply:\s*(https?:\/\/[^\s]+)/i', '', $description);
    $description = preg_replace('/<li><h3>(.*?)<\/h3><\/li>/s', '<li><p>$1</p></li>', $description);
    $styled_description = '<div class="job-description">' . $description . '</div>';
    return $styled_description;
}

function rjobs_get_existing_job($job_title, $job_location, $company_name) {
    $args = array(
        'post_type' => 'job_listing',
        'title' => $job_title,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_job_location',
                'value' => $job_location,
                'compare' => '='
            ),
            array(
                'key' => '_company_name',
                'value' => $company_name,
                'compare' => '='
            )
        ),
        'post_status' => 'publish',
        'posts_per_page' => 1
    );
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        return $query->posts[0];
    } else {
        return null;
    }
}

function fetch_company_logo_from_url($application_url) {
    $response = wp_remote_get($application_url);

    if (is_wp_error($response)) {
        error_log('Remote Job Aggregator: Failed to fetch content from URL: ' . $application_url . '. Error: ' . $response->get_error_message());
        return '';
    }

    $body = wp_remote_retrieve_body($response);

    // Use DOMDocument to parse HTML and fetch images
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Define the classes to search for and their priority
    $image_classes = [
        'company_logo',
        'attachment-thumbnail',
        'size-thumbnail',
        'wp-post-image',
        'img.absolute.h-full.w-full.rounded-full.bg-white.border-4.border-white',
        'absolute h-full w-full rounded-full bg-white border-4 border-white',
        'multiple_authors_guest_author_avatar',
        'avatar-image',
        'logo lazyload',
        'listing-logo',
        'company_profile',
        'div.listing-logo',
        'employer-logo'
    ];

    $images = [];

    // Fetch all images matching specified classes
    foreach ($image_classes as $class) {
        $nodes = $xpath->query("//img[contains(@class, '$class')]");
        foreach ($nodes as $node) {
            $src = $node->getAttribute('src');
            $data_src = $node->getAttribute('data-src');

            error_log('Remote Job Aggregator: Checking node with class: ' . $class);
            error_log('Remote Job Aggregator: Found src: ' . $src);
            error_log('Remote Job Aggregator: Found data-src: ' . $data_src);

            // Skip if src attribute is a base64 image or contains "/assets/pixel.gif"
            if (strpos($src, 'data:image/gif') === 0 || strpos($src, 'data:') === 0 || strpos($src, '/assets/pixel.gif') !== false) {
                error_log('Remote Job Aggregator: Skipped base64 image or "/assets/pixel.gif" in src attribute: ' . $src);

                // Check data-src immediately if src is skipped
                if (!empty($data_src) && !is_ajax_loader($data_src) && strpos($data_src, 'data:image/gif') !== 0 && strpos($data_src, 'data:') !== 0) {
                    error_log('Remote Job Aggregator: Found image with data-src: ' . $data_src);
                    // Decode the URL to ensure special characters are handled properly
                    $decoded_data_src = urldecode($data_src);
                    $images[] = $decoded_data_src;
                    break 2; // Exit both foreach loops as we found a valid image
                } else {
                    error_log('Remote Job Aggregator: No valid image found in data-src attribute');
                }
            } else {
                // Use src if data-src is empty or invalid (and not already fetched src)
                if (!empty($src) && !is_ajax_loader($src) && strpos($src, 'data:image/gif') !== 0 && strpos($src, 'data:') !== 0) {
                    error_log('Remote Job Aggregator: Found image with valid src: ' . $src);
                    // Decode the URL to ensure special characters are handled properly
                    $decoded_src = urldecode($src);
                    $images[] = $decoded_src;
                    break 2; // Exit both foreach loops as we found a valid image
                } else {
                    error_log('Remote Job Aggregator: No valid image found in src attribute');
                }
            }
        }
    }

    // Log all fetched image URLs
    if (!empty($images)) {
        foreach ($images as $image) {
            error_log('Remote Job Aggregator: Fetched image URL: ' . $image);
        }
    } else {
        error_log('Remote Job Aggregator: No images found in content from URL: ' . $application_url);
    }

    // If multiple images found, pick the best one using existing logic
    $image_url = '';
    if (count($images) > 0) {
        $image_url = select_best_image($images);
    }

    if (empty($image_url)) {
        error_log('Remote Job Aggregator: No suitable image found for job ID 5794');
    } else {
        error_log('Remote Job Aggregator: Found suitable image URL: ' . $image_url);
    }

    return $image_url;
}

function extract_direct_image_url($image_url) {
    // Check if the URL has nested structures
    $nested_prefix = 'https://ik.imagekit.io/himalayas/';
    $cdn_prefix = 'https://cdn-images.himalayas.app/';
    $static_prefix = 'https://cdn.statically.io/img/';

    // Remove the nested structures
    if (strpos($image_url, $nested_prefix) === 0) {
        $image_url = substr($image_url, strlen($nested_prefix));
        error_log('Remote Job Aggregator: Removed nested prefix, remaining URL: ' . $image_url);
    } elseif (strpos($image_url, $static_prefix) === 0) {
        $image_url = substr($image_url, strlen($static_prefix));
        error_log('Remote Job Aggregator: Removed static prefix, remaining URL: ' . $image_url);
    }

    // Reconstruct a valid URL if prefix was removed
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        $image_url = 'https://' . $image_url;
        error_log('Remote Job Aggregator: Reconstructed URL: ' . $image_url);
    }

    error_log('Remote Job Aggregator: After extracting direct URL: ' . $image_url);

    return $image_url;
}

function select_best_image($images) {
    $best_image = '';
    $next_best_image = '';
    $remaining_image = '';

    // Define allowed image types in the preferred order
    $allowed_types = [
        IMAGETYPE_PNG,
        IMAGETYPE_JPEG,
        IMAGETYPE_GIF,
    ];

    foreach ($images as $image_url) {
        error_log('Remote Job Aggregator: Processing image URL: ' . $image_url);

        $image_url = extract_direct_image_url($image_url); // Extract direct image URL if nested
        $image_url = remove_imgix_parameters($image_url); // Remove imgix parameters
        error_log('Remote Job Aggregator: After extracting direct URL: ' . $image_url);

        // Check if the URL is valid after processing
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            error_log('Remote Job Aggregator: Invalid URL after processing: ' . $image_url);
            continue; // Skip invalid URLs
        }

        list($width, $height, $type) = @getimagesize($image_url); // Use @ to suppress errors

        if ($width && $height && in_array($type, $allowed_types)) {
            // Best case: Check for preferred image types in order
            if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG])) {
                $best_image = $image_url;
                error_log('Remote Job Aggregator: Best image selected: ' . $best_image);
                break; // Stop at the first best match
            } elseif (empty($next_best_image) && $type == IMAGETYPE_GIF) {
                $next_best_image = $image_url; // Store as next best if GIF
                error_log('Remote Job Aggregator: Next best GIF image found: ' . $next_best_image);
            } elseif (empty($remaining_image) && is_valid_image_for_wordpress($image_url)) {
                // Fallback: Any image that can be uploaded to WordPress media
                $remaining_image = $image_url;
                error_log('Remote Job Aggregator: Remaining image found: ' . $remaining_image);
            }
        } else {
            error_log('Remote Job Aggregator: No valid image dimensions or type for URL: ' . $image_url);
        }
    }

    // Return the best match based on the criteria
    if (!empty($best_image)) {
        return $best_image;
    } elseif (!empty($next_best_image)) {
        return $next_best_image;
    } else {
        return $remaining_image;
    }
}

function remove_imgix_parameters($image_url) {
    // Define the parameters to remove
    $parameters_to_remove = ['ixlib', 'rails-4.0.0', 'w', 'h', 'dpr', 'fit', 'auto', 'quality=100', 'f=auto'];

    // Use preg_replace to remove imgix parameters
    $image_url = preg_replace('/(\?|&)(?:' . implode('|', array_map('preg_quote', $parameters_to_remove)) . ')=[^&"]*(&|$)/i', '', $image_url);

    // Ensure no trailing '?'
    $image_url = rtrim($image_url, '?');

    error_log('Remote Job Aggregator: Removed imgix parameters, new URL: ' . $image_url);
    return $image_url;
}


function is_valid_image_for_wordpress($image_url) {
    // Check if the URL is empty or not valid
    if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // Extract the file extension from the URL
    $file_extension = pathinfo($image_url, PATHINFO_EXTENSION);

    // Define allowed image extensions for WordPress media library
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    return in_array(strtolower($file_extension), $allowed_extensions);
}

function upload_image_to_wp($image_url, $job_id) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');



    // Download the image file
    $temp_file = download_url($image_url);

    if (is_wp_error($temp_file)) {
        error_log('Remote Job Aggregator: Failed to download image: ' . $temp_file->get_error_message());
        return false;
    } else {
        error_log('Remote Job Aggregator: Image downloaded successfully');
    }

    // Check if the downloaded file is valid
    $file_size = filesize($temp_file);
    if (empty($file_size)) {
        error_log('Remote Job Aggregator: Downloaded file is empty or invalid.');
        @unlink($temp_file);
        return false;
    }

    // Get the MIME type of the downloaded file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $file_type = finfo_file($finfo, $temp_file);
    finfo_close($finfo);

    // Log the file type of the downloaded image
    error_log('Remote Job Aggregator: File type of downloaded image: ' . $file_type);

    // Sanitize the file name
    $file_name = sanitize_file_name(basename($image_url, '?' . parse_url($image_url, PHP_URL_QUERY))) . '.' . pathinfo($image_url, PATHINFO_EXTENSION);

    // If the extension is not correct, derive from MIME type
    if (!in_array(pathinfo($file_name, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif'])) {
        switch ($file_type) {
            case 'image/jpeg':
                $file_name .= '.jpg';
                break;
            case 'image/png':
                $file_name .= '.png';
                break;
            case 'image/gif':
                $file_name .= '.gif';
                break;
        }
    }

    // Prepare the file array for uploading.
    $file = [
        'name'     => $file_name,
        'type'     => $file_type,
        'tmp_name' => $temp_file,
        'error'    => 0,
        'size'     => $file_size,
    ];

    // Upload the image to the media library and associate it with $job_id.
    $attachment_id = media_handle_sideload($file, $job_id);

    // Check for upload errors.
    if (is_wp_error($attachment_id)) {
        error_log('Remote Job Aggregator: Failed to upload image: ' . $attachment_id->get_error_message());
        error_log('Remote Job Aggregator: Error code: ' . $attachment_id->get_error_code());

        // Clean up the temporary file
        @unlink($temp_file);
        return $attachment_id;
    }

    // Log after successful upload
    error_log('Remote Job Aggregator: Image uploaded successfully. Attachment ID: ' . $attachment_id);

    // Set the uploaded image as the featured image for the job listing.
    set_post_thumbnail($job_id, $attachment_id);

    // Log after setting as featured image
    error_log('Remote Job Aggregator: Image set as featured image for job ID ' . $job_id . '. Attachment ID: ' . $attachment_id);

    // Cleanup the temporary file.
    if (file_exists($temp_file)) {
        unlink($temp_file);
        error_log('Remote Job Aggregator: Temporary file deleted successfully: ' . $temp_file);
    } else {
        error_log('Remote Job Aggregator: Temporary file not found, unable to clean up.');
    }

  

    return $attachment_id;
}


