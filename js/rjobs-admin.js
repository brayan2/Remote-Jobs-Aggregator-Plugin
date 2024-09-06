// Handle bulk actions
jQuery(document).ready(function($) {
    $('#rjobs-bulk-action-form').on('submit', function(e) {
        e.preventDefault();

        var action = $('#rjobs_bulk_action').val();
        var job_ids = $('input[name="rjobs_job_ids[]"]:checked').map(function() {
            return $(this).val();
        }).get();

        if (job_ids.length === 0) {
            alert('Please select at least one job.');
            return;
        }

        $.ajax({
            type: 'POST',
            url: rjobs_ajax.ajax_url,
            data: {
                action: 'rjobs_bulk_action',
                rjobs_bulk_action: action,
                rjobs_job_ids: job_ids
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });
});

