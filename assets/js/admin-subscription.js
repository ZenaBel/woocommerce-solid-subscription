jQuery(document).ready(function($) {
    $('#send_pause_request').on('click', function() {
        const startDate = $('#pause_start_date').val();
        const stopDate = $('#pause_stop_date').val();

        const data = {
            action: 'pause_subscription', // WordPress AJAX action
            _nonce: pauseSubscriptionData.nonce, // Nonce для захисту
            start_point: {
                type: startDate ? 'specific_date' : 'immediate',
                date: startDate || null,
            },
            stop_point: {
                type: stopDate ? 'specific_date' : 'immediate',
                date: stopDate || null,
            },
        };

        console.log(pauseSubscriptionData.ajaxurl, data);

        // AJAX-запит до WordPress
        $.post(pauseSubscriptionData.ajaxurl, data, function(response) {
            if (response.success) {
                alert('Subscription paused successfully!');
                console.log(response);
            } else {
                alert('Error pausing subscription: ' + (response.data.message || 'Unknown error'));
                console.error(response);
            }
        }).fail(function(xhr) {
            alert('AJAX request failed: ' + xhr.status + ' ' + xhr.statusText);
            console.error(xhr);
        });
    });
});
