jQuery(document).ready(function($) {
    $('#confirm_and_pay_button').on('click', function(e) {
        e.preventDefault(); // Забороняємо стандартну поведінку кнопки

        // Збираємо всі дані з форми
        var formData = $('#payment_form').serialize();

        // Отримуємо вибраний метод оплати
        var paymentMethod = $('select[name="payment_method"]').val();

        // Виконуємо AJAX-запит або робимо редірект на сторінку оплати
        if (paymentMethod === 'solid_payment_gateway') {
            // Якщо вибрано Solid Payment, відправляємо дані через AJAX
            $.ajax({
                url: solidSubscribeParams.ajax_url, // URL для обробки AJAX
                type: 'POST',
                data: {
                    action: 'process_payment', // Дія для обробки
                    form_data: formData
                },
                success: function(response) {
                    console.log(response); // Лог для налагодження
                    console.log(response.data); // Лог для налагодження
                    if (response.success) {
                        // Наприклад, редірект на сторінку оплати
                        window.location.href = response.data.payment_url;
                    } else {
                        alert('Помилка під час обробки оплати');
                    }
                }
            });
        } else {
            // Інший метод оплати (якщо він передбачає прямий редірект)
            window.location.href = '/another-payment-gateway-url';
        }
    });
});

jQuery(document).ready(function($) {
    // Перехоплюємо подію на кнопці додавання до кошика
    $('body').on('click', '.add_to_cart_button', function(e) {
        e.preventDefault();  // Зупиняємо стандартну поведінку

        var productID = $(this).data('product_id');  // Отримуємо ID продукту

        // Виконуємо AJAX-запит для перевірки, чи є продукт підпискою
        $.ajax({
            url: solidSubscribeParams.ajax_url,  // URL для обробки AJAX
            type: 'POST',
            data: {
                action: 'check_product_subscription',
                product_id: productID
            },
            success: function(response) {
                if (response.success && response.data.redirect_url) {
                    // Якщо товар є підпискою, виконуємо редірект
                    window.location.href = response.data.redirect_url;
                } else {
                    // Якщо товар не є підпискою, додаємо його до кошика через стандартний процес
                    window.location.href = '?add-to-cart=' + productID;
                }
            },
            error: function(xhr, status, error) {
                console.log("AJAX Error: " + error);  // Лог для налагодження
            }
        });
    });
});

jQuery(document).ready(function($) {

    // Перехоплюємо клік на кнопці "Subscribe"
    $('body').on('click', '.add_to_cart_button', function(e) {
        e.preventDefault();  // Зупиняємо стандартну поведінку кнопки

        var productID = $(this).data('product_id');  // Отримуємо ID продукту з кнопки

        // Виконуємо AJAX-запит для перевірки, чи є це товар підпискою
        $.ajax({
            url: solidSubscribeParams.ajax_url,  // URL для обробки AJAX
            type: 'POST',
            data: {
                action: 'check_product_subscription',
                product_id: productID
            },
            success: function(response) {
                if (response.success && response.data.is_subscription) {
                    // Якщо товар є підпискою, виконуємо редірект на сторінку з параметра redirect_url
                    // window.location.href = solidSubscribeParams.redirect_url + '?product_id=' + productID;

                    console.log(solidSubscribeParams.redirect_url + '?product_id=' + productID);
                } else {
                    // Якщо це не товар підписки, виводимо повідомлення або виконуємо іншу дію
                    alert('Цей товар не є підпискою.');
                }
            },
            error: function(xhr, status, error) {
                console.log("AJAX помилка: " + error);  // Лог для налагодження помилок
            }
        });
    });
});

jQuery(document).ready(function ($) {
    var params = new URLSearchParams(window.location.search);

    // Перевіряємо, чи є параметр product_id в URL
    if (params.has('product_id')) {
        var productID = params.get('product_id');

        $('input[name="product_id"]').val(productID);  // Записуємо ID продукту в приховане поле
    }
});

jQuery(document).ready(function($) {
    $('body').on('click', '.add_to_cart_button', function(e) {
        e.preventDefault(); // Зупиняємо стандартну поведінку
        var $this = $(this);

        // Отримуємо ID продукту
        var productID = $this.data('product_id');

        // Виконуємо AJAX-запит до WooCommerce
        $.ajax({
            url: wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart'),
            type: 'POST',
            data: {
                product_id: productID
            },
            success: function(response) {
                if (response.redirect) {
                    // Якщо сервер відповідає, що треба зробити редірект
                    window.location.href = response.redirect_url;
                } else {
                    // Якщо це не товар підписки, виконуємо стандартну поведінку WooCommerce
                    window.location.href = '?add-to-cart=' + productID;
                }
            }
        });
    });
});
