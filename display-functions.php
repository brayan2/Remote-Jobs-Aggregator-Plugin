<?php

function rjobs_the_job_listing() {
    $jobs = rjobs_fetch_jobs();

    if (empty($jobs)) {
        echo '<p>No jobs found.</p>';
        return;
    }

    echo '<ul class="job_listings">';

    foreach ($jobs as $job) {
        $post = get_post($job['ID']);
        setup_postdata($post);
        get_job_manager_template_part('content', 'job_listing'); // This assumes 'content-job_listing.php' in your theme or child theme
        wp_reset_postdata();
    }

    echo '</ul>';
}
