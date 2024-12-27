jQuery(document).ready(function($) {
    $('#send_pause_request').on('click', function() {
        const startDate = $('#pause_start_date').val();
        const stopDate = $('#pause_stop_date').val();

        const data = {
            action: 'solid_pause_subscription', // WordPress AJAX action
            _nonce: pauseSubscriptionData.nonce, // Nonce для захисту
            start_point: {
                type: startDate ? 'specific_date' : 'immediate',
                date: startDate || null,
            },
            stop_point: {
                type: stopDate ? 'specific_date' : 'immediate',
                date: stopDate || null,
            },
            subscription_id: pauseSubscriptionData.subscription_id,
        };

        console.log(pauseSubscriptionData.ajaxurl, data);

        let node = this;

        // AJAX-запит до WordPress
        $.post(pauseSubscriptionData.ajaxurl, data, function(response) {
            if (response.success) {
                showAdminNotice('success', 'Subscription paused successfully!');
                if ($(node).closest('.inside').find('#remove_pause_request').length === 0) {
                    $(node).closest('.inside').append(`
                    <button type="button" id="remove_pause_request" class="button button-cancel" style="margin-top: 10px;">
                    Remove Subscription Pause
                    </button>`);
                }
            } else {
                showAdminNotice('error', 'Error pausing subscription: ' + (response.data.message || 'Unknown error'));
                console.error(response);
            }
        }).fail(function(xhr) {
            showAdminNotice('error', 'AJAX request failed: ' + xhr.status + ' ' + xhr.statusText);
            console.error(xhr);
        });
    });
});
jQuery(document).on('click', '#remove_pause_request', function () {
    const data = {
        action: 'solid_resume_subscription', // WordPress AJAX action
        _nonce: pauseSubscriptionData.nonce, // Nonce для захисту
        subscription_id: pauseSubscriptionData.subscription_id,
    };

    console.log(pauseSubscriptionData.ajaxurl, data);

    let node = this;

    // AJAX-запит до WordPress
    jQuery.post(pauseSubscriptionData.ajaxurl, data, function (response) {
        if (response.success) {
            showAdminNotice('success', 'Subscription pause removed successfully!');
            let inside = jQuery(node).closest('.inside');
            inside.find('#pause_start_date').val('');
            inside.find('#pause_stop_date').val('');
            jQuery(node).remove();
        } else {
            showAdminNotice('error', 'Error removing subscription pause: ' + (response.data.message || 'Unknown error'));
        }
    }).fail(function (xhr) {
        showAdminNotice('error', 'AJAX request failed: ' + xhr.status + ' ' + xhr.statusText);
    });
});
/**
 * Функція для показу повідомлень у стилі WordPress
 * @param {string} type - 'success' або 'error'
 * @param {string} message - Текст повідомлення
 */
function showAdminNotice(type, message) {
    const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
    const notice = `
        <div class="notice ${noticeClass} is-dismissible">
            <p>${message}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Dismiss this notice.</span>
            </button>
        </div>
    `;

    // Додаємо повідомлення у верхню частину сторінки
    jQuery('.wrap').prepend(notice);

    // Додаємо функціонал для закриття повідомлення
    jQuery('.notice.is-dismissible').on('click', '.notice-dismiss', function () {
        jQuery(this).closest('.notice').remove();
    });
}
