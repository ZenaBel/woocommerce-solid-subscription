<?php


use SolidGate\API\ApiCustom;


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('WC_Solid_Gateway_Subscribe')) {

    /**
     * @property WC_Solid_Subscribe_Webhook_Handler $hooks
     * @property string $logging
     * @property string $google_pay_merchant_id
     * @property string $payment_public_name
     * @property string $payment_methods
     * @property string $private_key
     * @property string $integration_type
     * @property string $public_key
     * @property string $webhook_private_key
     * @property string $webhook_public_key
     * @property ApiCustom $api
     */
    class WC_Solid_Gateway_Subscribe extends WC_Payment_Gateway
    {
        private static $instance = null;

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {
            // dd(plugins_url('assets/img/solidgate.png', __FILE__));
            $this->id = 'solid_subscribe'; // payment gateway plugin ID
            $this->icon = plugins_url('assets/img/solidgate.svg', dirname(__FILE__)); // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = false; // in case you need a custom credit card form
            $this->method_title = 'Visa/Mastercard Subscribe'; // title of the payment method for the admin page
            $this->method_description = 'Visa/Mastercard Subscribe'; // payment method description for the admin page
            //$this->order_button_text = 'Pay via Solid';
            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'tokenization',
            );
            $this->hooks = new WC_Solid_Subscribe_Webhook_Handler();
            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->google_pay_merchant_id = $this->get_option('google_pay_subscribe_merchant_id');

            $this->logging = $this->get_option('logging');
            $this->description = $this->get_option('description');
            $this->payment_public_name = $this->get_option('payment_public_name');
            $this->payment_methods = $this->get_option('payment_methods');
            $this->enabled = $this->get_option('enabled');

            $this->private_key = $this->get_option('private_key');
            $this->integration_type = $this->get_option('integration_type');
            $this->public_key = $this->get_option('public_key');

            $this->webhook_private_key = $this->get_option('webhook_private_key');
            $this->webhook_public_key = $this->get_option('webhook_public_key');

            $this->api = new ApiCustom($this->public_key, $this->private_key);
            if ('form' == $this->integration_type) {
                $this->init_scripts();
                $this->has_fields = true;
            }

            // This action hook saves the settings
            // add_action( 'woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_action('woocommerce_api_' . $this->id . '_success', array($this, 'solid_order_success_callback'));
            add_action('woocommerce_api_' . $this->id . '_hook', [$this, 'check_for_webhook']);
            add_action('woocommerce_api_' . $this->id . '_refund', array($this, 'solid_wh_refund_callback'));
            add_action('woocommerce_api_' . $this->id . '_failture', array($this, 'solid_order_failture_callback'));
            add_action('woocommerce_subscription_cancelled_' . $this->id, [$this, 'cancel_subscription']);
            add_action('woocommerce_process_product_meta', [$this, 'save_subscription_product_fields'], 10, 2);
            add_action('woocommerce_payment_complete', [$this, 'create_subscription'], 10, 1);
            add_action('add_meta_boxes', [$this, 'add_subscription_meta_box']);
            add_action('add_meta_boxes', [$this, 'add_pause_meta_box']);
            add_action('add_meta_boxes', [$this, 'add_restore_subscription_meta_box']);
            add_action('add_meta_boxes', [$this, 'add_product_meta_box']);
            add_action('admin_notices', [$this, 'display_admin_notices']);
            add_action('admin_enqueue_scripts', function () {
                $nonce = wp_create_nonce('pause_subscription_nonce');

                wp_enqueue_script(
                    'admin-subscription-js',
                    dirname(plugin_dir_url(__FILE__)) . '/assets/js/admin-subscription.js',
                    ['jquery'],
                    '1.0.0',
                    true
                );
                // Передаємо глобальні змінні в JavaScript
                wp_localize_script('admin-subscription-js', 'pauseSubscriptionData', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => $nonce,
                    'subscription_id' => $_GET['post'] ?? 0,
                ]);
            });
            add_action('woocommerce_new_order_item', [$this, 'handle_subscription_item_added'], 10, 3);
            add_action('woocommerce_before_delete_order_item', [$this, 'handle_subscription_item_removed'], 10, 2);

            add_action('add_meta_boxes', function () {
                add_meta_box(
                    'custom_repeater', // ID метабоксу
                    'Country List', // Заголовок
                    [$this, 'render_country_list_meta_box'], // Функція рендеру
                    'product', // Пост-тип
                    'normal', // Розташування
                    'default' // Пріоритет
                );
            });

            add_action('save_post', [$this, 'save_product_list']);
        }

        public static function get_instance(): ?WC_Solid_Gateway_Subscribe
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function save_product_list($post_id)
        {
            // Перевірка nonce
            if (!isset($_POST['country_list_nonce']) || !wp_verify_nonce($_POST['country_list_nonce'], 'save_country_list')) {
                return;
            }

            // Перевірка прав
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // Отримання існуючих даних
            $existing_items = WC_Solid_Product_List::get_product_list_by_product_id($post_id);

            $existing_keys = [];
            if ($existing_items) {
                foreach ($existing_items as $item) {
                    $existing_keys[] = $item->uuid;
                }
            }

            // Збереження даних
            $product_mapping = WC_Solid_Product_Model::get_product_mapping_by_product_id($post_id);
            if (!$product_mapping) {
                return;
            }
            $product_uuid = $product_mapping->uuid;

            $response = $this->api->getProduct($product_uuid);

            if (is_wp_error($response)) {
                $admin_notice = __('Failed to get product details.', 'wc-solid');
                set_transient('solidgate_sync_notice', $admin_notice, 30);
                return;
            }

            $product_solid = json_decode($response);

            $is_trial = $product_solid->trial ?? false;

            if (isset($_POST['country_list']) && is_array($_POST['country_list'])) {

                $submitted_keys = []; // Для зберігання нових UUID

                foreach ($_POST['country_list'] as $product) {
                    if (empty($product['currency']) || empty($product['price']) || empty(sanitize_text_field($product['country_name']))) {
                        // Логування помилки
                        WC_Solid_Subscribe_Logger::debug('Validation failed for product: ' . print_r($product, true));

                        $admin_notice = __('Validation failed for product. Fields "Currency", "Price" and "Country" are required.', 'wc-solid');
                        set_transient('solidgate_sync_notice', $admin_notice, 30);
                        continue;
                    }

                    if ($is_trial && empty($product['sign_up_fee'])) {
                        $admin_notice = __('Validation failed for product. Fields "Currency", "Price", "Country" and "Sign-up Fee" are required.', 'wc-solid');
                        set_transient('solidgate_sync_notice', $admin_notice, 30);
                        continue;
                    }

                    // Отримання існуючого елемента, якщо такий є
                    if ($item = WC_Solid_Product_List::get_product_list_by_country_name_and_currency($post_id, $product['country_name'], $product['currency'])) {
                        // Оновлення, якщо дані змінилися
                        if ($item->price != $product['price'] || $item->sign_up_fee != $product['sign_up_fee']) {
                            $body = [
                                'status' => 'active',
                                'product_price' => (int)($product['price'] * 100),
                                'currency' => sanitize_text_field($product['currency']),
                                'country' => sanitize_text_field($product['country_name']),
                            ];

                            if ($product['sign_up_fee']) {
                                $body['trial_price'] = (int)($product['sign_up_fee'] * 100);
                            }

                            $response = $this->api->updatePrice($product_uuid, $item->uuid, $body);

                            WC_Solid_Subscribe_Logger::debug('Update product list response: ' . print_r($response, true));

                            if (!is_wp_error($response)) {
                                WC_Solid_Subscribe_Logger::debug('Update product list response: ' . print_r($response, true));
                                $price_uuid = json_decode($response, true)['id'];

                                $data = [
                                    'product_id' => $post_id,
                                    'uuid' => $price_uuid,
                                    'price_id' => $price_uuid ?? '',
                                    'country' => $product['country_name'],
                                    'label' => $product['label'] ?? '',
                                    'banner_label' => $product['banner_label'] ?? '',
                                    'class' => $product['class'] ?? '',
                                    'score' => $product['score'] ?? '',
                                    'currency' => $product['currency'],
                                    'sign_up_fee' => $product['sign_up_fee'] ?? '',
                                    'sign_up_fee_label' => $product['sign_up_fee_label'] ?? '',
                                    'price' => $product['price'],
                                    'price_label' => $product['price_label'] ?? '',
                                ];

                                WC_Solid_Subscribe_Logger::debug('Update product list: ' . print_r($data, true));

                                WC_Solid_Product_List::update_product_list($data);
                            }
                        }

                        $data = [
                            'product_id' => $post_id,
                            'uuid' => $item->uuid,
                            'country_name' => sanitize_text_field($product['country_name']),
                            'label' => sanitize_text_field($product['label']) ?? '',
                            'banner_label' => sanitize_text_field($product['banner_label']) ?? '',
                            'class' => sanitize_text_field($product['class']) ?? '',
                            'score' => sanitize_text_field($product['score']) ?? '',
                            'currency' => sanitize_text_field($product['currency']),
                            'sign_up_fee' => sanitize_text_field($product['sign_up_fee']) ?? null,
                            'sign_up_fee_label' => sanitize_text_field($product['sign_up_fee_label']) ?? '',
                            'price' => sanitize_text_field($product['price']),
                            'price_label' => sanitize_text_field($product['price_label']) ?? '',
                        ];

                        WC_Solid_Subscribe_Logger::debug('Update product list (no price): ' . print_r($data, true));

                        WC_Solid_Product_List::update_product_list($data);

                        $submitted_keys[] = $item->uuid;
                    } else {
                        // Додавання нового елемента
                        $body = [
                            'default' => false,
                            'status' => 'active',
                            'product_price' => (int)($product['price'] * 100),
                            'currency' => sanitize_text_field($product['currency']),
                            'country' => sanitize_text_field($product['country_name']),
                        ];

                        if ($product['sign_up_fee']) {
                            $body['trial_price'] = (int)($product['sign_up_fee'] * 100);
                        }

                        $response = $this->api->createPrice($product_uuid, $body);

                        WC_Solid_Subscribe_Logger::debug('Create product list response: ' . print_r($response, true));

                        if (!is_wp_error($response)) {
                            $price_uuid = json_decode($response, true)['id'];

                            $data = [
                                'product_id' => $post_id,
                                'uuid' => $price_uuid,
                                'country_name' => sanitize_text_field($product['country_name']),
                                'label' => sanitize_text_field($product['label']) ?? '',
                                'banner_label' => sanitize_text_field($product['banner_label']) ?? '',
                                'class' => sanitize_text_field($product['class']) ?? '',
                                'score' => sanitize_text_field($product['score']) ?? '',
                                'currency' => sanitize_text_field($product['currency']),
                                'sign_up_fee' => sanitize_text_field($product['sign_up_fee']) ?? null,
                                'sign_up_fee_label' => sanitize_text_field($product['sign_up_fee_label']) ?? '',
                                'price' => sanitize_text_field($product['price']),
                                'price_label' => sanitize_text_field($product['price_label']) ?? '',
                            ];

                            WC_Solid_Subscribe_Logger::debug('Create product list: ' . print_r($data, true));

                            WC_Solid_Product_List::create_product_list($data);
                            $submitted_keys[] = $price_uuid;
                        }
                    }
                }

                // Видалення записів, які більше не існують у нових даних
                $keys_to_delete = array_diff($existing_keys, $submitted_keys);

                WC_Solid_Subscribe_Logger::debug('Keys to delete: ' . print_r($keys_to_delete, true));

                foreach ($keys_to_delete as $key) {
                    $this->api->updatePrice($product_uuid, $key, ['status' => 'disabled']);
                    WC_Solid_Product_List::delete_product_list_by_uuid($key);
                }
            } else {

                $keys = array_map(function ($item) {
                    return $item->uuid;
                }, $existing_items);

                foreach ($keys as $key) {
                    $this->api->updatePrice($product_uuid, $key, ['status' => 'disabled']);
                    WC_Solid_Product_List::delete_product_list_by_uuid($key);
                }
                WC_Solid_Product_List::delete_product_list_by_product_id($post_id);
            }
        }

        public function render_country_list_meta_box($post)
        {
            // Отримуємо збережені дані
            $country_list = WC_Solid_Product_List::get_product_list_by_product_id($post->ID);

            // Якщо дані не масив — ініціалізуємо порожній масив
            if (!is_array($country_list)) {
                $country_list = [];
            }

            $currencies = $this->get_iso_4217_currencies();
            $countries = $this->get_iso_3166_countries();

            // Генеруємо nonce для безпеки
            wp_nonce_field('save_country_list', 'country_list_nonce');
            ?>
            <div id="country-list-wrapper" class="country-list-wrapper">
                <?php foreach ($country_list as $index => $row) : ?>
                    <div class="country-list-row" data-index="<?php echo $index; ?>">
                        <div class="country-list-row-inner">
                            <div class="field">
                                <label>Country:
                                    <select class="select23" name="country_list[<?php echo $index; ?>][country_name]">
                                        <option value="">Select Country</option>
                                        <?php foreach ($countries as $country) : ?>
                                            <option value="<?php echo esc_attr($country['code']); ?>" <?php selected($row->country_name, $country['code']); ?>>
                                                <?php echo esc_html($country['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="field">
                                <label>Label:</label>
                                <input type="text" name="country_list[<?php echo $index; ?>][label]"
                                       value="<?php echo esc_attr($row->label ?? ''); ?>" placeholder="Label">
                            </div>
                            <div class="field">
                                <label>Banner Label:</label>
                                <input type="text" name="country_list[<?php echo $index; ?>][banner_label]"
                                       value="<?php echo esc_attr($row->banner_label ?? ''); ?>"
                                       placeholder="Banner Label">
                            </div>
                            <div class="field">
                                <label>Class:</label>
                                <input type="text" name="country_list[<?php echo $index; ?>][class]"
                                       value="<?php echo esc_attr($row->class ?? ''); ?>" placeholder="Class">
                            </div>
                            <div class="field">
                                <label>Score:</label>
                                <input type="number" name="country_list[<?php echo $index; ?>][score]"
                                       value="<?php echo esc_attr($row->score ?? ''); ?>" placeholder="Score">
                            </div>
                            <div class="field">
                                <label>Currency:</label>
                                <select class="select23" name="country_list[<?php echo $index; ?>][currency]">
                                    <option value="">Select Currency</option>
                                    <?php foreach ($currencies as $currency) : ?>
                                        <option value="<?php echo esc_attr($currency['code']); ?>" <?php selected($row->currency, $currency['code']); ?>>
                                            <?php echo esc_html($currency['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Sign-up Fee:</label>
                                <input type="number" name="country_list[<?php echo $index; ?>][sign_up_fee]"
                                       value="<?php echo esc_attr($row->sign_up_fee ?? ''); ?>"
                                       placeholder="Sign-up Fee">
                            </div>
                            <div class="field">
                                <label>Sign-up Fee Label:</label>
                                <input type="text" name="country_list[<?php echo $index; ?>][sign_up_fee_label]"
                                       value="<?php echo esc_attr($row->sign_up_fee_label ?? ''); ?>"
                                       placeholder="Sign-up Fee Label">
                            </div>
                            <div class="field">
                                <label>Price:</label>
                                <input type="number" name="country_list[<?php echo $index; ?>][price]"
                                       value="<?php echo esc_attr($row->price ?? ''); ?>" placeholder="Price">
                            </div>
                            <div class="field">
                                <label>Price Label:</label>
                                <input type="text" name="country_list[<?php echo $index; ?>][price_label]"
                                       value="<?php echo esc_attr($row->price_label ?? ''); ?>"
                                       placeholder="Price Label">
                            </div>
                        </div>
                        <button type="button" class="remove-row">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-country-row">Add Row</button>

            <script>
                (function ($) {
                    $(document).ready(function () {
                        // Ініціалізація Select2
                        $('.select23').select2();

                        let index = <?php echo count($country_list); ?>;

                        $('#add-country-row').on('click', function () {
                            const countryOptions = <?php echo json_encode(array_map(function ($country) {
                                return "<option value='{$country['code']}'>{$country['name']}</option>";
                            }, $countries)); ?>;
                            const currencyOptions = <?php echo json_encode(array_map(function ($currency) {
                                return "<option value='{$currency['code']}'>{$currency['name']}</option>";
                            }, $currencies)); ?>;

                            $('#country-list-wrapper').append(`
                        <div class="country-list-row" data-index="${index}">
                            <div class="country-list-row-inner">
                                <div class="field">
                                    <label>Country:</label>
                                    <select class="select23" name="country_list[${index}][country_name]">
                                        <option value="">Select Country</option>
                                        ${countryOptions.join('')}
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Label:</label>
                                    <input type="text" name="country_list[${index}][label]" placeholder="Label">
                                </div>
                                <div class="field">
                                    <label>Banner Label:</label>
                                    <input type="text" name="country_list[${index}][banner_label]" placeholder="Banner Label">
                                </div>
                                <div class="field">
                                    <label>Class:</label>
                                    <input type="text" name="country_list[${index}][class]" placeholder="Class">
                                </div>
                                <div class="field">
                                    <label>Score:</label>
                                    <input type="number" name="country_list[${index}][score]" placeholder="Score">
                                </div>
                                <div class="field">
                                    <label>Currency:</label>
                                    <select class="select23" name="country_list[${index}][currency]">
                                        <option value="">Select Currency</option>
                                        ${currencyOptions.join('')}
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Sign-up Fee:</label>
                                    <input type="number" name="country_list[${index}][sign_up_fee]" placeholder="Sign-up Fee">
                                </div>
                                <div class="field">
                                    <label>Sign-up Fee Label:</label>
                                    <input type="text" name="country_list[${index}][sign_up_fee_label]" placeholder="Sign-up Fee Label">
                                </div>
                                <div class="field">
                                    <label>Price:</label>
                                    <input type="number" name="country_list[${index}][price]" placeholder="Price">
                                </div>
                                <div class="field">
                                    <label>Price Label:</label>
                                    <input type="text" name="country_list[${index}][price_label]" placeholder="Price Label">
                                </div>
                            </div>
                            <button type="button" class="remove-row">Remove</button>
                        </div>
                    `);
                            $('.select23').select2();
                            index++;
                        });

                        $(document).on('click', '.remove-row', function () {
                            $(this).closest('.country-list-row').remove();
                        });
                    });
                })(jQuery);
            </script>

            <style>
                .country-list-wrapper {
                    margin-top: 20px;
                }

                .country-list-row {
                    margin-bottom: 20px;
                    padding: 15px;
                    border: 1px solid #ddd;
                    background-color: #f9f9f9;
                    border-radius: 5px;
                }

                .country-list-row-inner {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 15px;
                }

                .field {
                    display: flex;
                    flex-direction: column;
                }

                .field label {
                    font-weight: bold;
                    margin-bottom: 5px;
                }

                .remove-row {
                    margin-top: 10px;
                    color: #fff;
                    background-color: #d9534f;
                    border: none;
                    border-radius: 3px;
                    padding: 5px 10px;
                    cursor: pointer;
                }

                .remove-row:hover {
                    background-color: #c9302c;
                }

                #add-country-row {
                    margin-top: 20px;
                    color: #fff;
                    background-color: #5bc0de;
                    border: none;
                    border-radius: 3px;
                    padding: 10px 15px;
                    cursor: pointer;
                    display: inline-block;
                }

                #add-country-row:hover {
                    background-color: #31b0d5;
                }

                .select23 {
                    width: 100%;
                }

                /* Адаптивність для екранів середнього розміру */
                @media (max-width: 1524px) {
                    .country-list-row-inner {
                        grid-template-columns: repeat(2, 1fr);
                    }
                }

                /* Адаптивність для мобільних екранів */
                @media (max-width: 1300px) {
                    .country-list-row-inner {
                        grid-template-columns: 1fr;
                    }

                    .field label {
                        margin-bottom: 3px;
                    }

                    .country-list-row {
                        padding: 10px 5px;
                    }

                    .remove-row {
                        width: 100%;
                    }

                    #add-country-row {
                        width: 100%;
                        text-align: center;
                    }
                }
            </style>
            <?php
        }

        public function handle_subscription_item_added($item_id, $item, $order_id)
        {
            $subscription = wcs_get_subscription($order_id);

            if (!$subscription) {
                return;
            }

            if ($subscription->get_payment_method() !== $this->id) {
                return;
            }

            if ($subscription->get_status() !== 'active') {
                return;
            }

            if (count($subscription->get_items()) > 1) {
                return;
            }

            $item = current($subscription->get_items());

            $product_id = $item->get_product_id();

            $product_uuid = WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id)->uuid;

            if (!$product_uuid) {
                return;
            }

            $subscription_uuid = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id())->uuid;

            WC_Solid_Subscribe_Logger::debug('$product_uuid: ' . print_r($product_uuid, true));
            WC_Solid_Subscribe_Logger::debug('$subscription_uuid: ' . print_r($subscription_uuid, true));

            if (!$subscription_uuid) {
                return;
            }

            $is_switch_product = $this->switch_product_subscription($product_uuid, $subscription_uuid);

            if ($is_switch_product) {
                $subscription->add_order_note(
                    sprintf(
                        __('Subscription product was switched to %s', 'wc-solid'),
                        get_the_title($product_id)
                    )
                );
            }

            WC_Solid_Subscribe_Logger::debug('$product_uuid: ' . print_r($product_uuid, true));

            WC_Solid_Subscribe_Logger::debug('woocommerce_new_order_item: ' . print_r($item_id, true) . "\n" . print_r($item, true) . "\n" . print_r($order_id, true));
        }

        public function handle_subscription_item_removed($item_id)
        {
            global $wpdb;
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
                $item_id
            ));
            WC_Solid_Subscribe_Logger::debug("Order ID: $order_id");

            // Отримати підписку
            $subscription = wcs_get_subscription($order_id);

            // Отримати деталі про підписку
            if (!$subscription) {
                return;
            }

            if ($subscription->get_payment_method() !== $this->id) {
                return;
            }

            if ($subscription->get_status() !== 'active') {
                return;
            }

            if (count($subscription->get_items()) > 1 || count($subscription->get_items()) == 0) {
                return;
            }

            $item = current($subscription->get_items());

            $product_id = $item->get_product_id();

            $product_uuid = WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id)->uuid;

            if (!$product_uuid) {
                return;
            }

            $subscription_uuid = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id())->uuid;

            $is_switch_product = $this->switch_product_subscription($product_uuid, $subscription_uuid);

            if ($is_switch_product) {
                $subscription->add_order_note(
                    sprintf(
                        __('Subscription product was switched to %s', 'wc-solid'),
                        get_the_title($product_id)
                    )
                );
            }

            WC_Solid_Subscribe_Logger::debug("(ID: $item_id) is being deleted from subscription #{$subscription->get_id()}");
        }

        public function switch_product_subscription($new_product_uuid, $subscription_uuid): bool
        {
            $data = [
                'subscription_id' => $subscription_uuid,
                'new_product_id' => $new_product_uuid,
            ];

            $response = $this->api->switchProductSubscription($data);

            WC_Solid_Subscribe_Logger::debug('Switch product subscription response: ' . print_r($response, true));

            if (!is_wp_error($response)) {
                $subscription = WC_Solid_Subscribe_Model::get_subscription_mapping_by_uuid($subscription_uuid);
                $product = WC_Solid_Product_Model::get_product_mapping_by_uuid($new_product_uuid);

                if ($subscription) {
                    $subscription_id = $subscription->subscription_id;
                    $product_id = $product->product_id;
                    WC_Solid_Product_Model::update_product_mapping($subscription_id, $product_id);
                }
                return true;
            } else {
                $notice = __('Switching product subscription failed', 'wc-solid');
                set_transient('solidgate_sync_notice', $notice, 30);
                return false;
            }
        }

        /**
         * Init required js and css assets
         */
        protected function init_scripts()
        {
            add_action('wp_enqueue_scripts', array($this, 'wc_solid_enqueue_scripts'));
        }

        /**
         * Add script to load card form
         */
        public function wc_solid_enqueue_scripts()
        {
            wp_register_style('solid-custom-style', plugins_url('/woocommerce-solid-subscription/assets/css/style.css'), array(), WC()->version);
            wp_enqueue_style('solid-custom-style');
            wp_enqueue_style('jquery-modal-style', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.css');
            wp_enqueue_script('jquery');
            wp_enqueue_script(
                'solid-form-script',
                'https://cdn.solidgate.com/js/solid-form.js',
                [],
                WC()->version
            );
            wp_enqueue_script(
                'jquery-modal',
                'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.js',
                array(
                    'jquery',
                ),
                WC()->version
            );

            wp_enqueue_script(
                'solid-woocommerce-subscription',
                plugins_url('/woocommerce-solid-subscription/assets/js/solid.js'),
                array(
                    'jquery',
                ),
                WC()->version
            );


        }

        function validate_signature(string $jsonString): string
        {
            return base64_encode(
                hash_hmac('sha512',
                    $this->webhook_public_key . $jsonString . $this->webhook_public_key,
                    $this->webhook_private_key)
            );
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Solid Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'logging' => array(
                    'title' => 'Logging',
                    'label' => 'Enable Logging',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'integration_type' => array(
                    'title' => 'Integration type',
                    'type' => 'select',
                    'default' => 'page',
                    'options' => array('form' => 'Integrated form', 'page' => 'Payment page')
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Visa/Mastercard',
                    'desc_tip' => true,
                ),
                'payment_methods' => array(
                    'title' => 'Custom payment methods',
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'default' => '',
                    'options' => array('paypal' => 'PayPal'),
                    'desc_tip' => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __('Select payment methods', 'woocommerce'),
                    )
                ),
                "google_pay_merchant_id" => array(
                    'title' => 'Google Pay merchant ID',
                    'type' => 'text',
                    'description' => 'Type here your google_pay_merchant_id to enable google pay button',
                    'desc_tip' => true,
                ),
                'payment_public_name' => array(
                    'title' => 'Merchant',
                    'type' => 'text',
                    'default' => 'Merchant',
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Visa/Mastercard.',
                ),
                'public_key' => array(
                    'title' => 'Public Key',
                    'type' => 'text'
                ),
                'private_key' => array(
                    'title' => 'Private Key',
                    'type' => 'password'
                ),
                'webhook_public_key' => array(
                    'title' => 'Webhook Public Key',
                    'type' => 'text'
                ),
                'webhook_private_key' => array(
                    'title' => 'Webhook Private Key',
                    'type' => 'password'
                )
            );
        }

        public function check_for_webhook()
        {
            if (!isset($_SERVER['REQUEST_METHOD'])
                || ('POST' !== $_SERVER['REQUEST_METHOD'])
                || !isset($_GET['wc-api'])
                || ('solid_subscribe_hook' !== $_GET['wc-api'])
            ) {
                return;
            }

            $request_body = file_get_contents('php://input');
            $request_headers = array_change_key_case($this->hooks->get_request_headers(), CASE_UPPER);

            WC_Solid_Subscribe_Logger::debug('Incoming webhook: ' . print_r($request_headers, true) . "\n" . print_r($request_body, true));
            if ($request_headers['SIGNATURE'] == $this->validate_signature($request_body)) {
                WC_Solid_Subscribe_Logger::debug('Incoming webhook123: ' . print_r($_GET['type'], true) . "\n" . print_r($request_headers, true) . "\n" . print_r($request_body, true));
                $this->hooks->process_webhook($_GET['type'], $request_body);
                status_header(200);
            } else {
                WC_Solid_Subscribe_Logger::debug('Incoming webhook failed validation: ' . print_r($request_body, true));

                status_header(204);
            }
            exit;

        }

        /**
         * Process refunds for WC 2.2+
         *
         * @param int $order_id The order ID.
         * @param float|null $amount The amount to refund. Default null.
         * @param string $reason The reason for the refund. Default null.
         * @return bool|WP_Error
         */
        public function process_refund($order_id, $amount = null, $reason = null)
        {
            $order = wc_get_order($order_id);
            if (!is_a($order, 'WC_Order')) {
                return new WP_Error('solid_refund_error', __('Order not valid', 'wc-solid'));
            }

            $transction_id = get_post_meta($order->get_id(), '_uniq_order_id', true);

            if (!$transction_id || empty($transction_id)) {
                return new WP_Error('solid_refund_error', __('No valid Order ID found', 'wc-solid'));
            }

            if (is_null($amount) || $amount <= 0) {
                return new WP_Error('solid_refund_error', __('Amount not valid', 'wc-solid'));
            }

            if (is_null($reason) || '' === $reason) {
                $reason = sprintf(__('Refund for Order # %s', 'wc-solid'), $order->get_order_number());
            }

            try {
                $response = $this->api->refund([
                    'order_id' => $transction_id,
                    'amount' => intval($amount * 100),
                    'refund_reason_code' => '0021'
                ]);

                WC_Solid_Subscribe_Logger::debug('Refund response: ' . print_r($response, true));

                if (!is_wp_error($response)) {
                    $body = json_decode($response['body'], true);
                    if ($body['order']) {
                        return true;
                    } else {
                        return new WP_Error('solid_refund_error', 'Refunding failed');
                    }

                } else {
                    return new WP_Error('solid_refund_error', 'Refunding failed');
                }
            } catch (Exception $e) {
                return new WP_Error('solid_refund_error', $e->getMessage());
            }
        }

        public function payment_fields()
        {
            $description = $this->get_description();

            // Ваш контейнер для модального вікна
            echo '<div class="status-box">';
            echo '<div id="solid-checkout-modal" class="modal">';
            echo '</div>';
            echo '<div id="solid-payment-form-container"></div>';

            // Опис платіжного методу (якщо заданий)
            if ($description) {
                echo wpautop(wptexturize($description));
            }

            echo '</div>'; // закриття status-box
        }

        public function verify_nonce($plugin_id, $nonce_id = ''): bool
        {
            $nonce = (isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '');
            $nonce = (is_array($nonce) ? $nonce[0] : $nonce);
            $nonce_id = ($nonce_id == "" ? $plugin_id : $nonce_id);
            WC_Solid_Subscribe_Logger::debug('Nonce: ' . print_r($nonce, true));
            WC_Solid_Subscribe_Logger::debug('Nonce ID: ' . print_r($nonce_id, true));
            WC_Solid_Subscribe_Logger::debug('Nonce check: ' . print_r(wp_verify_nonce($nonce, $nonce_id), true));
            if (!(wp_verify_nonce($nonce, $nonce_id))) {
                return false;
            } else {
                return true;
            }
        }

        public function get_solid_order_body($order_id): array
        {
            $order = wc_get_order($order_id);
            $uniq_order_id = get_post_meta($order_id, '_uniq_order_id', true);
            WC_Solid_Subscribe_Logger::debug('used $uniq_order_id:' . print_r($uniq_order_id, true));

            $items_str = '';
            $order_description = '';
            $price_id = '';
            $is_subscription = false;

            // Проходимо по товарах у замовленні
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();

                // Перевіряємо, чи є товар підпискою
                if (WC_Subscriptions_Product::is_subscription($product_id)) {
                    $is_subscription = true;
                    $subscription_duration = WC_Subscriptions_Product::get_length($product_id);
                    $subscription_period = WC_Subscriptions_Product::get_period($product_id);
                    $free_trial_duration = WC_Subscriptions_Product::get_trial_length($product_id);
                    $free_trial_period = WC_Subscriptions_Product::get_trial_period($product_id);
                    $sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee($product_id);
                    $subscription_product_id = WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id)->uuid;

//                    $body_price = [
//                        'product_id' => $subscription_product_id,
//                        'location' => [
//                            'ip_address' => $_SERVER['REMOTE_ADDR'],
//                        ],
//                    ];

//                    WC_Solid_Subscribe_Logger::debug('Price calculation request: ' . print_r($body_price, true));

//                    $response = $this->api->calculatePrice($body_price);

//                    WC_Solid_Subscribe_Logger::debug('Price calculation response: ' . print_r($response, true));

//                    if (!is_wp_error($response)) {
//                        $body = json_decode($response, true);
//                        $price_id = $body['price_id'];
//                        WC_Solid_Subscribe_Logger::debug('Price ID: ' . print_r($price_id, true));
//                    } else {
//                        WC_Solid_Subscribe_Logger::debug('Price calculation failed: ' . print_r($response, true));
//                        $price_id = $subscription_product_id;
//                    }

                    $country = $this->get_country_alpha3($this->get_user_currency());

                    WC_Solid_Subscribe_Logger::debug('Country: ' . print_r($country, true));

                    if ($country) {
//                        WC_Solid_Subscribe_Logger::debug('($product_id, $country)', print_r([WC_Solid_Product_List::get_product_list_by_country_name($product_id, $country)], true));
                        WC_Solid_Subscribe_Logger::debug('$country', print_r(WC_Solid_Product_List::get_product_list_by_country_name($product_id, $country)->uuid, true));
                        $price_id = WC_Solid_Product_List::get_product_list_by_country_name($product_id, $country)->uuid;
                        WC_Solid_Subscribe_Logger::debug('Price ID: ' . print_r($price_id, true));
                    } else {
                        $price_id = $subscription_product_id;
                    }

                    WC_Solid_Subscribe_Logger::debug('Subscription product ID: ' . print_r($subscription_product_id, true));

                    // Додаємо інформацію про підписку в опис замовлення
                    $order_description .= $item->get_name() . ' (' . $item->get_quantity() . ') - Subscription: ' . $subscription_duration . ' ' . $subscription_period;

                    if ($free_trial_duration > 0) {
                        $order_description .= ', Free period: ' . $free_trial_duration . ' ' . $free_trial_period . '; ';
                    } else {
                        $order_description .= '; ';
                    }

                    if ($sign_up_fee > 0) {
                        $order_description .= ', Paid Trial Fee: ' . $sign_up_fee;
                    }
                } else {
                    // Додаємо звичайний товар в опис замовлення
                    $order_description .= $item->get_name() . ' (' . $item->get_quantity() . '); ';
                }

                $items_str .= $item->get_name() . ', ';
            }

            // Загальні дані замовлення, незалежно від типу товару
            $common_order_data = [
                'order_id' => $uniq_order_id,
                'order_description' => $order_description,
                'order_items' => $items_str,
                'order_number' => $order_id,
                'type' => 'auth',
                'settle_interval' => 0,
                'customer_email' => $order->get_billing_email(),
                'customer_first_name' => $order->get_billing_first_name() ?: ' ',
                'customer_last_name' => $order->get_billing_last_name() ?: ' ',
                'website' => get_home_url(),
            ];

            // Додаткові дані для підписки
            if ($is_subscription) {
                $common_order_data['product_price_id'] = $price_id;
                $common_order_data['customer_account_id'] = $order->get_customer_id() . '_' . $order->get_billing_email();
            } else {
                // Додаткові дані для звичайного товару
                $common_order_data['currency'] = $order->get_currency();
                $common_order_data['amount'] = round($order->get_total() * 100);
                $common_order_data["google_pay_merchant_id"] = $this->google_pay_merchant_id;
            }

            WC_Solid_Subscribe_Logger::debug('Common order data: ' . print_r($common_order_data, true));

            return $common_order_data;
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order_title = 'Your order';
            $uniq_order_id = $order->get_id() . '_' . time();

            $order->add_order_note('Payment was started (Order ID: ' . $uniq_order_id . ').');

            // Зберігаємо унікальний ідентифікатор замовлення для ідентифікації у Solid
            update_post_meta($order->get_id(), '_uniq_order_id', $uniq_order_id);
            update_post_meta($order->get_id(), 'uniq_order_id', $uniq_order_id); // Використовується лише для відображення на сторінці замовлення

            // Використання get_solid_order_body для отримання даних замовлення
            $order_body = $this->get_solid_order_body($order_id);
            $nonce = wp_create_nonce('s_checkout_nonce');
            $order_body['success_url'] = home_url() . '/?wc-api=solid_subscribe_success&order_id=' . $order_id . '&_wpnonce=' . $nonce;
            $order_body['fail_url'] = home_url() . '/?wc-api=solid_subscribe_failture&order_id=' . $order_id . '&_wpnonce=' . $nonce;

            // Налаштування сторінки замовлення
            $page_customization = [
                'public_name' => $this->payment_public_name,
                'order_title' => $order_title,
                'order_description' => $order_body['order_description']
            ];

            // Додавання кастомних методів оплати, якщо вони є
            if (!empty($this->payment_methods)) {
                $page_customization['payment_methods'] = $this->payment_methods;
            }

            // Вибір форми оплати в залежності від типу інтеграції
            if ($this->integration_type === 'form') {
                WC_Solid_Subscribe_Logger::debug('$order_body: ' . print_r($order_body, true));
                $response = $this->api->formMerchantData($order_body)->toArray();
                return [
                    'result' => 'success',
                    "form" => $response,
                    "redirects" => [
                        'success_url' => $order_body['success_url'],
                        'fail_url' => $order_body['fail_url'],
                    ]
                ];
            } else {
                // Підготовка запиту для API, окреме тіло запиту для ініціалізації
                $request_body = json_encode([
                    'order' => $order_body,
                    'page_customization' => $page_customization
                ]);

                $signature = $this->api->generateSignature($request_body);

                $args = [
                    'headers' => [
                        'merchant' => $this->public_key,
                        'Signature' => $signature,
                        'Content-Type' => 'application/json'
                    ],
                    'body' => $request_body
                ];

                // Відправка запиту на ініціалізацію платежу
                $response = wp_remote_post('https://payment-page.solidgate.com/api/v1/init', $args);

                if (!is_wp_error($response)) {
                    $response_body = json_decode($response['body'], true);
                    if (isset($response_body['url'])) {
                        if (isset($response_body['subscription_id'])) {
                            $subscription_id = sanitize_text_field($response_body['subscription_id']);
                            $order->update_meta_data('_solid_subscription_id', $subscription_id);
                        }
                        return ['result' => 'success', 'redirect' => $response_body['url']];
                    } else {
                        wc_add_notice('Connection error. [' . $response_body['error']['code'] . ']', 'error');
                    }
                } else {
                    wc_add_notice('Connection error.', 'error');
                }

                WC_Solid_Subscribe_Logger::debug('Request body: ' . print_r($request_body, true));
                WC_Solid_Subscribe_Logger::debug('Response: ' . print_r($response, true));
            }
        }


        public function solid_order_success_callback()
        {
            if (!$this->verify_nonce($this->id, 's_checkout_nonce')) {
                die();
            }

            // Отримуємо замовлення за ID
            $order_id = intval($_GET['order_id']);
            $order = wc_get_order($order_id);

            if (!$order) {
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            // Отримуємо унікальний ідентифікатор замовлення для нотаток
            $uniq_order_id = get_post_meta($order->get_id(), '_uniq_order_id', true);

            $order->payment_complete();
            $order->reduce_order_stock();

            // Перевірка наявності підписки в замовленні
            if (wcs_order_contains_subscription($order_id)) {
                // Додаємо нотатку про успішну оплату підписки
                $order->add_order_note(sprintf(__('Subscription payment successfully completed (Order ID: %s)', 'wc-solid'), $uniq_order_id));

                // Додатково можна оновити статус підписки через WooCommerce Subscriptions, якщо потрібно
                $subscriptions = wcs_get_subscriptions_for_order($order_id);
                foreach ($subscriptions as $subscription) {
                    $subscription->update_status('active'); // Активація підписки
                    $subscription->add_order_note(__('Subscription activated after successful payment.', 'wc-solid'));
                }
            } else {
                // Якщо замовлення не містить підписки, просто додаємо нотатку про успішну оплату
                $order->add_order_note(sprintf(__('Payment has been successfully completed (Order ID: %s)', 'wc-solid'), $uniq_order_id));
            }

            $order->add_order_note(sprintf(__('Payment has been successfully completed (Order ID: %s)', 'wc-solid'), $uniq_order_id));

            wc_add_notice(__('Payment successful. Thank you for your payment.'), 'success');
            wp_safe_redirect($this->get_return_url($order));
            exit;

        }

        public function display_admin_notices(): void
        {
            // Отримуємо повідомлення з транзієнту
            if ($notice = get_transient('solidgate_sync_notice')) {
                echo '<div class="notice notice-warning"><p>' . esc_html($notice) . '</p></div>';
                // Видаляємо повідомлення після відображення
                delete_transient('solidgate_sync_notice');
            }
        }


        /**
         * Хук для обробки продукту під час його створення або оновлення
         *
         * @param $post_id
         * @param $post
         * @return void
         */
        public function save_subscription_product_fields($post_id, $post)
        {
            $product = wc_get_product($post_id);

            if ($this->is_synchronized_with_solidgate($post_id)) {
                return;
            }

            // Обробка продукту як підписки
            if (!WC_Subscriptions_Product::is_subscription($product)) {
                return;
            }

            // Отримуємо параметри підписки
            $subscription_period = WC_Subscriptions_Product::get_period($product);
            $subscription_interval = WC_Subscriptions_Product::get_interval($product);
            $free_trial_duration = WC_Subscriptions_Product::get_trial_length($product);
            $free_trial_period = WC_Subscriptions_Product::get_trial_period($product);
            $sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee($product);

            // Формуємо масив даних для Solidgate
            $data = [
                'name' => $product->get_name(),
                'description' => !empty($product->get_description()) ? $product->get_description() : $product->get_name(),
                'status' => $product->get_status() === 'publish' ? 'active' : 'disabled',
                'term_length' => 20,
                'payment_action' => 'auth_settle',
                'settle_interval' => 48,
                'billing_period' => [
                    'unit' => $subscription_period,
                    'value' => intval($subscription_interval),
                ],
            ];

            // Додаємо інформацію про тріал
            if ($free_trial_duration > 0 || $sign_up_fee > 0) {
                $trial_data = [
                    'billing_period' => [
                        'unit' => $free_trial_duration > 0 ? $free_trial_period : $subscription_period,
                        'value' => $free_trial_duration > 0 ? intval($free_trial_duration) : intval($subscription_interval),
                    ],
                ];

                // Логіка для платного тріалу
                if ($sign_up_fee > 0) {
                    $trial_data['payment_action'] = 'auth_settle'; // Платний тріал
                    $trial_data['settle_interval'] = 48; // Інтервал у хвилинах (наприклад, 48 хвилин)
                } else {
                    // Логіка для безкоштовного тріалу
                    $trial_data['payment_action'] = 'auth_void'; // Безкоштовний тріал із поверненням коштів
                }

                $data['trial'] = $trial_data;
            }

            // Додаємо короткий опис, якщо він є
            if ($product->get_short_description()) {
                $data['public_description'] = html_entity_decode($product->get_short_description(), ENT_QUOTES, 'UTF-8');
            }

            WC_Solid_Subscribe_Logger::debug('Subscription product data for Solidgate: ' . print_r($data, true));

            // Створення нового продукту в Solidgate
            $response = $this->api->addProduct($data);
            $solidgate_product = json_decode($response, true);

            WC_Solid_Subscribe_Logger::debug('New Solidgate product created: ' . print_r($solidgate_product, true));

            // Перевіряємо, чи було успішно створено продукт у Solidgate
            if (empty($solidgate_product['id'])) {
                $error_message = __('Failed to create product in Solidgate. Please check your configuration and try again.', 'woocommerce');
                WC_Solid_Subscribe_Logger::alert($error_message);
                add_action('admin_notices', function () use ($error_message) {
                    echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
                });
                return;
            }

            // Зберігаємо ID Solidgate продукту в мета-даних продукту WooCommerce
            $solidgate_product_id = $solidgate_product['id'];
            WC_Solid_Product_Model::create_product_mapping($post_id, $solidgate_product_id);
            WC_Solid_Subscribe_Logger::debug('New Solidgate product created: ' . print_r($solidgate_product, true));

            $price = $product->get_regular_price() ?: $product->get_sale_price();
            // Формування даних для ціни
            $dataPrice = [
                'default' => true,
                'status' => 'active',
                'product_price' => (int)($price * 100),
                'currency' => get_woocommerce_currency(),
            ];

            if ($free_trial_duration > 0) {
                $dataPrice['trial_price'] = (int)($sign_up_fee * 100);
            }

            // Додаємо перевірку перед створенням ціни
            if (!empty($solidgate_product_id)) {
                // Створення ціни у Solidgate
                $price_response = $this->api->addPrice($solidgate_product_id, $dataPrice);
                $price_data = json_decode($price_response, true);

                if (isset($price_data['id'])) {
                    update_post_meta($post_id, '_solidgate_price_id', $price_data['id']);
                    WC_Solid_Subscribe_Logger::debug('New Solidgate price created: ' . print_r($price_data, true));
                }
            } else {
                WC_Solid_Subscribe_Logger::alert('Failed to create price in Solidgate due to missing product ID.');
            }
        }

        private function is_synchronized_with_solidgate($post_id): bool
        {
            $solidgate_product_id = WC_Solid_Product_Model::get_product_mapping_by_product_id($post_id)->uuid;
            if ($solidgate_product_id) {
                $admin_notice = __('This product has already been synchronized with Solidgate. Changes to the price or period will not be synchronized. To apply these changes, create a new product in Solidgate.', 'woocommerce');
                set_transient('solidgate_sync_notice', $admin_notice, 30);
                return true;
            }
            return false;
        }


        public function solid_order_failture_callback()
        {
            if (!$this->verify_nonce($this->id, 's_checkout_nonce')) {
                die();
            }

            $order = wc_get_order(intval($_GET['order_id']));
            if (!$order) {
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            $uniq_order_id = get_post_meta($order->get_id(), '_uniq_order_id', true);

            $order->update_status('failed', sprintf(__('Payment failed (Order ID: %s)', 'wc-solid'), $uniq_order_id));

            $errorMessage = __('You have cancelled. Please try to process your order again.', 'wc-solid');

            try {
                $response = $this->api->status(['order_id' => $uniq_order_id]);

                $body = json_decode($response, true);
                if ($body['error']['code']) {
                    $order->add_order_note('Customer attempted to pay, but the payment failed or got declined. (Error code: ' . $body['error']['code'] . ')');
                    $errorMessage = $this->error_code_lookup($body['error']['code']);
                }
            } catch (Exception $e) {
                $order->add_order_note('Customer attempted to pay, but the payment failed or got declined. (Error: ' . $e->getMessage() . ')');
                WC_Solid_Subscribe_Logger::debug('Status Exception: ' . print_r($e, true));
            }
            wc_add_notice($errorMessage, 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        public function create_subscription($order_id)
        {
            // Отримуємо замовлення
            $order = wc_get_order($order_id);

            // Перевіряємо, чи замовлення існує
            if (!$order) {
                return;
            }

            // Отримуємо елементи замовлення
            $items = $order->get_items();

            foreach ($items as $item_id => $item) {
                $product_id = $item->get_product_id();

                if (WC_Subscriptions_Product::is_subscription($product_id)) {
                    // Формуємо дані для запиту до Solidgate
                    $data = array(
                        'plan_id' => WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id)->uuid,
                        'customer' => array(
                            'email' => $order->get_billing_email(),
                            'phone' => $order->get_billing_phone(),
                            'first_name' => $order->get_billing_first_name(),
                            'last_name' => $order->get_billing_last_name(),
                        ),
                        'currency' => $order->get_currency(),
                        'amount' => WC_Subscriptions_Order::get_total_initial_payment($order) * 100, // Вказуємо в копійках/центах, залежно від вимог API
                        'description' => 'Subscription for order #' . $order_id,
                        // Інші необхідні поля залежно від документації Solidgate
                    );

                    // Виконуємо запит до Solidgate для створення підписки
                    $response = wp_remote_post('https://api.solidgate.com/subscriptions', array(
                        'method' => 'POST',
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'Merchant' => 'your-merchant-key',
                            'Signature' => 'your-signature-key',
                        ),
                        'body' => json_encode($data),
                    ));

                    // Обробка відповіді від Solidgate
                    if (is_wp_error($response)) {
                        $error_message = $response->get_error_message();
                        WC_Solid_Subscribe_Logger::alert('Solidgate Subscription Error: ' . $error_message);
                    } else {
                        $response_body = json_decode(wp_remote_retrieve_body($response), true);

                        WC_Solid_Subscribe_Logger::alert('Solidgate Subscription Response: ' . print_r($response_body, true));

                        if (isset($response_body['subscription_id'])) {
                            $subscription_id = sanitize_text_field($response_body['subscription_id']);

                            // Зберігаємо subscription_id у мета-даних замовлення
                            $order->update_meta_data('_solid_subscription_id', $subscription_id);
                            $order->save();

                            WC_Solid_Subscribe_Logger::alert('Solidgate Subscription Created: ' . $subscription_id);
                        } else {
                            WC_Solid_Subscribe_Logger::alert('Solidgate Subscription Error: Invalid response');
                        }
                    }
                }
            }
        }


        public function add_subscription_meta_box()
        {
            global $post;

            $subscription = wcs_get_subscription($post->ID);

            if (!($subscription instanceof WC_Subscription)) {
                return;
            }

            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id());

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            if ($subscription_id) {
                add_meta_box(
                    'subscription_meta_box',              // ID метабокса
                    __('Subscription Details', 'textdomain'), // Назва метабокса
                    [$this, 'display_subscription_meta_box'],      // Функція, яка виводить контент метабокса
                    'shop_subscription',                    // Тип поста, для якого виводиться метабокс
                    'side',                               // Розташування (side для правої колонки)
                    'high'                                // Пріоритет відображення
                );
            }
        }

        public function add_pause_meta_box()
        {
            global $post;

            $subscription = wcs_get_subscription($post->ID);

            if (!($subscription instanceof WC_Subscription)) {
                return;
            }

            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id());

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            if ($subscription_id && !in_array($subscription->get_status(), ['expired', 'cancelled'])) {
                add_meta_box(
                    'pause_meta_box',              // ID метабокса
                    __('Subscription Pause', 'textdomain'),
                    [$this, 'display_subscription_pause_meta_box'],
                    'shop_subscription',
                    'side',
                    'high'
                );
            }
        }

        public function add_restore_subscription_meta_box()
        {
            global $post;

            $subscription = wcs_get_subscription($post->ID);

            if (!($subscription instanceof WC_Subscription)) {
                return;
            }

            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id());

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            if ($subscription_id && in_array($subscription->get_status(), ['cancelled', 'expired'])) {
                add_meta_box(
                    'restore_meta_box',              // ID метабокса
                    __('Subscription Restore', 'textdomain'),
                    [$this, 'display_subscription_restore_meta_box'],
                    'shop_subscription',
                    'side',
                    'high'
                );
            }
        }

        public function add_product_meta_box()
        {
            global $post;

            $product = wc_get_product($post->ID);

            if ($product instanceof WC_Product) {
                $product_id = $product->get_id();
            } else {
                return;
            }

            $product_mapping = WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id);
            $solidgate_product_id = $product_mapping ? $product_mapping->uuid : null;

            if ($solidgate_product_id) {
                add_meta_box(
                    'product_meta_box',              // ID метабокса
                    __('Solidgate Product Details', 'textdomain'), // Назва метабокса
                    [$this, 'display_product_meta_box'],      // Функція, яка виводить контент метабокса
                    'product',                    // Тип поста, для якого виводиться метабокс
                    'side',                               // Розташування (side для правої колонки)
                    'high'                                // Пріоритет відображення
                );
            }
        }

        public function display_subscription_meta_box($post)
        {
            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($post->ID);

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            WC_Solid_Subscribe_Logger::debug('meta_box Subscription ID: ' . print_r($subscription_id, true));

            if ($subscription_id) {

                $data = [
                    'subscription_id' => $subscription_id,
                ];

                // Виконуємо запит до API для отримання деталей підписки
                $response = $this->api->getSubscriptionStatus($data);

                if (is_wp_error($response)) {
                    echo '<p>' . __('Failed to retrieve subscription details.', 'textdomain') . '</p>';
                    return;
                }

                $subscription_details = json_decode($response);

                if (!empty($subscription_details->subscription)) {
                    $subscription = $subscription_details->subscription;
                    $product = $subscription_details->product;
                    $customer = $subscription_details->customer;
                    $invoices = $subscription_details->invoices;

                    echo '<p><strong>' . __('Subscription ID:', 'textdomain') . '</strong> ' . esc_html($subscription->id ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Status:', 'textdomain') . '</strong> ' . esc_html($subscription->status ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Started At:', 'textdomain') . '</strong> ' . esc_html($subscription->started_at ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Next Charge At:', 'textdomain') . '</strong> ' . esc_html($subscription->next_charge_at ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Payment Type:', 'textdomain') . '</strong> ' . esc_html($subscription->payment_type ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Cancelled At:', 'textdomain') . '</strong> ' . esc_html($subscription->cancelled_at ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Cancellation Reason:', 'textdomain') . '</strong> ' . esc_html($subscription->cancel_message ?? 'N/A') . '</p>';

                    echo '<hr>';

                    echo '<h4>' . __('Product Details', 'textdomain') . '</h4>';
//                    echo '<p><strong>' . __('Product ID:', 'textdomain') . '</strong> ' . esc_html($product->product_id ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Product Name:', 'textdomain') . '</strong> ' . esc_html($product->name ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Amount:', 'textdomain') . '</strong> ' . esc_html((int)$product->amount / 100 ?? 'N/A') . ' ' . esc_html($product->currency ?? 'N/A') . '</p>';

                    echo '<hr>';

                    echo '<h4>' . __('Customer Details', 'textdomain') . '</h4>';
                    echo '<p><strong>' . __('Customer Email:', 'textdomain') . '</strong> ' . esc_html($customer->customer_email ?? 'N/A') . '</p>';
//                    echo '<p><strong>' . __('Customer Account ID:', 'textdomain') . '</strong> ' . esc_html($customer->customer_account_id ?? 'N/A') . '</p>';

                    echo '<hr>';

                    if (!empty($invoices)) {
                        echo '<h4>' . __('Invoices', 'textdomain') . '</h4>';
                        foreach ($invoices as $invoice_id => $invoice) {
//                            echo '<p><strong>' . __('Invoice ID:', 'textdomain') . '</strong> ' . esc_html($invoice->id ?? 'N/A') . '</p>';
                            echo '<p><strong>' . __('Amount:', 'textdomain') . '</strong> ' . esc_html((int)$invoice->amount / 100 ?? 'N/A') . '</p>';
                            echo '<p><strong>' . __('Status:', 'textdomain') . '</strong> ' . esc_html($invoice->status ?? 'N/A') . '</p>';
                            echo '<p><strong>' . __('Billing Period:', 'textdomain') . '</strong> ' . esc_html($invoice->billing_period_started_at ?? 'N/A') . ' - ' . esc_html($invoice->billing_period_ended_at ?? 'N/A') . '</p>';

                            if (!empty($invoice->orders)) {
                                foreach ($invoice->orders as $order_id => $order) {
                                    echo '<hr>';
                                    echo '<p><strong>' . __('Order ID:', 'textdomain') . '</strong> ' . esc_html($order->id ?? 'N/A') . '</p>';
                                    echo '<p><strong>' . __('Order Status:', 'textdomain') . '</strong> ' . esc_html($order->status ?? 'N/A') . '</p>';
                                    echo '<p><strong>' . __('Order Amount:', 'textdomain') . '</strong> ' . esc_html((int)$order->amount / 100 ?? 'N/A') . '</p>';
                                    echo '<p><strong>' . __('Operation:', 'textdomain') . '</strong> ' . esc_html($order->operation ?? 'N/A') . '</p>';
                                }
                            }
                        }
                    }
                } else {
                    echo '<p>' . __('No subscription details found.', 'textdomain') . '</p>';
                }
            } else {
                echo '<p>' . __('This order is not linked to any subscription.', 'textdomain') . '</p>';
            }
        }

        public function display_subscription_pause_meta_box($post)
        {
            $pause_start_point = get_post_meta($post->ID, '_pause_start_point', true);
            $pause_stop_point = get_post_meta($post->ID, '_pause_stop_point', true);
            $existing_pause = get_post_meta($post->ID, '_pause_schedule_exists', true);
            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($post->ID);

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            if (!$subscription_id) {
                echo '<p>' . __('This order is not linked to any subscription.', 'textdomain') . '</p>';
                return;
            }

            echo '<div style="margin-top: 10px;">';
            echo '<label for="pause_start_date">' . __('Start Date (optional):', 'textdomain') . '</label>';
            echo '<input type="date" id="pause_start_date" name="pause_start_date" value="' . esc_attr($pause_start_point['date'] ?? '') . '">';
            echo '</div>';
            echo '<div style="margin-top: 10px;">';
            echo '<label for="pause_stop_date">' . __('Stop Date (optional):', 'textdomain') . '</label>';
            echo '<input type="date" id="pause_stop_date" name="pause_stop_date" value="' . esc_attr($pause_stop_point['date'] ?? '') . '">';
            echo '</div>';
            echo '<button type="button" id="send_pause_request" class="button button-primary" style="margin-top: 10px;">';
            echo __('Pause Subscription', 'textdomain');
            echo '</button>';

            if ($existing_pause) {
                echo '<button type="button" id="remove_pause_request" class="button button-cancel" style="margin-top: 10px;">';
                echo __('Remove Subscription Pause', 'textdomain');
                echo '</button>';
            }
        }

        public function display_subscription_restore_meta_box($post)
        {
            $subscription = wcs_get_subscription($post->ID);
            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($post->ID);

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            if (!$subscription_id && !in_array($subscription->get_status(), ['cancelled', 'expired'])) {
                echo '<p>' . __('This order is not linked to any subscription.', 'textdomain') . '</p>';
                return;
            }

            echo '<button type="button" id="send_restore_request" class="button button-primary" style="margin-top: 10px;">';
            echo __('Restore Subscription', 'textdomain');
            echo '</button>';
        }

        public function display_product_meta_box($post)
        {
            // Отримуємо ID продукту
            $product_id = $post->ID;

            // Отримуємо Solidgate Product ID
            $solidgate_product_id = WC_Solid_Product_Model::get_product_mapping_by_product_id($product_id)->uuid;

            if (!$solidgate_product_id) {
                echo '<p>' . __('This product is not linked to any Solidgate product.', 'textdomain') . '</p>';
                return;
            }

            // Запит до API для отримання деталей продукту
            $response = $this->api->getProduct($solidgate_product_id);

            if (is_wp_error($response)) {
                echo '<p>' . __('Failed to retrieve product details.', 'textdomain') . '</p>';
                return;
            }

            $product_details = json_decode($response);

            if (!empty($product_details)) {
                echo '<p><strong>' . __('Product ID:', 'textdomain') . '</strong> ' . esc_html($product_details->id ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Name:', 'textdomain') . '</strong> ' . esc_html($product_details->name ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Description:', 'textdomain') . '</strong> ' . esc_html($product_details->description ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Public Description:', 'textdomain') . '</strong> ' . esc_html($product_details->public_description ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Status:', 'textdomain') . '</strong> ' . esc_html($product_details->status ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Created At:', 'textdomain') . '</strong> ' . esc_html($product_details->created_at ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Updated At:', 'textdomain') . '</strong> ' . esc_html($product_details->updated_at ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Term Length:', 'textdomain') . '</strong> ' . esc_html($product_details->term_length ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Payment Action:', 'textdomain') . '</strong> ' . esc_html($product_details->payment_action ?? 'N/A') . '</p>';
                echo '<p><strong>' . __('Settle Interval:', 'textdomain') . '</strong> ' . esc_html($product_details->settle_interval ?? 'N/A') . '</p>';

                if (!empty($product_details->billing_period)) {
                    echo '<hr>';
                    echo '<h4>' . __('Billing Period', 'textdomain') . '</h4>';
                    echo '<p><strong>' . __('Unit:', 'textdomain') . '</strong> ' . esc_html($product_details->billing_period->unit ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Value:', 'textdomain') . '</strong> ' . esc_html($product_details->billing_period->value ?? 'N/A') . '</p>';
                }

                if (!empty($product_details->trial)) {
                    echo '<hr>';
                    echo '<h4>' . __('Trial Details', 'textdomain') . '</h4>';
                    echo '<p><strong>' . __('Billing Period Unit:', 'textdomain') . '</strong> ' . esc_html($product_details->trial->billing_period->unit ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Billing Period Value:', 'textdomain') . '</strong> ' . esc_html($product_details->trial->billing_period->value ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Payment Action:', 'textdomain') . '</strong> ' . esc_html($product_details->trial->payment_action ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Settle Interval:', 'textdomain') . '</strong> ' . esc_html($product_details->trial->settle_interval ?? 'N/A') . '</p>';
                }

                if (!empty($product_details->tax)) {
                    echo '<hr>';
                    echo '<h4>' . __('Tax Details', 'textdomain') . '</h4>';
                    echo '<p><strong>' . __('Tax Profile ID:', 'textdomain') . '</strong> ' . esc_html($product_details->tax->profile_id ?? 'N/A') . '</p>';
                    echo '<p><strong>' . __('Tax Included:', 'textdomain') . '</strong> ' . esc_html($product_details->tax->is_included ? __('Yes', 'textdomain') : __('No', 'textdomain')) . '</p>';
                }
            } else {
                echo '<p>' . __('No product details found.', 'textdomain') . '</p>';
            }
        }

        public function pause_subscription()
        {
            if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'pause_subscription_nonce')) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }

            WC_Solid_Subscribe_Logger::debug('Pause Subscription Request: ' . print_r($_POST, true));

            if (!empty($_POST['subscription_id'])) {
                $subscription_id = sanitize_text_field($_POST['subscription_id']);
            } else {
                wp_send_json_error(['message' => 'Subscription Id is required']);
                return;
            }

            // Отримуємо дані із запиту
            $start_point = $_POST['start_point'] ?? [];
            $stop_point = $_POST['stop_point'] ?? [];

            // Якщо не вказано дату, встановлюємо type як "immediate"
            $start_point['type'] = empty($start_point['date']) ? 'immediate' : 'specific_date';
            $stop_point['type'] = empty($stop_point['date']) ? 'infinite' : 'specific_date';

            // Зберігаємо дані у мета-дані замовлення
            update_post_meta($subscription_id, '_pause_start_point', $start_point);
            update_post_meta($subscription_id, '_pause_stop_point', $stop_point);

            try {
                $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription_id);

                if ($subscription_mapping) {
                    $subscription_uuid = $subscription_mapping->uuid;
                } else {
                    wp_send_json_error(['message' => 'Subscription not found']);
                    return;
                }


                $body = [
                    'start_point' => [
                        'type' => $start_point['type'],
//                        'date' => $start_point['date'] ?? null,
                    ],
                    'stop_point' => [
                        'type' => $stop_point['type'],
//                        'date' => $stop_point['date'] ?? null,
                    ],
                ];

                if ($start_point['type'] === 'specific_date') {
                    $body['start_point']['date'] = date('Y-m-d H:i:s', strtotime($start_point['date'] . ' 00:00:00'));
                }

                if ($stop_point['type'] === 'specific_date') {
                    $body['stop_point']['date'] = date('Y-m-d H:i:s', strtotime($stop_point['date'] . ' 23:59:59'));
                }

                // Виконуємо паузу або оновлення паузи
                $existing_pause = get_post_meta($subscription_id, '_pause_schedule_exists', true);

                if ($existing_pause) {
                    $response = $this->api->updatePauseSchedule($subscription_uuid, $body);
                    $action = 'Update Pause Schedule';
                } else {
                    $response = $this->api->pauseSchedule($subscription_uuid, $body);
                    $action = 'Pause Schedule';
                    update_post_meta($subscription_id, '_pause_schedule_exists', true);
                    wcs_get_subscription($subscription_id)->update_status('on-hold');
                }

                WC_Solid_Subscribe_Logger::debug("$action Response: " . print_r($response, true));

                if (!is_wp_error($response)) {
                    $body = json_decode($response, true);
                    if ($body['pause']) {
                        wp_send_json_success(['message' => 'Subscription paused successfully']);
                    } else {
                        wp_send_json_error(['message' => 'Failed to pause subscription']);
                    }
                } else {
                    wp_send_json_error(['message' => 'Failed to pause subscription']);
                }
            } catch (Exception $e) {
                WC_Solid_Subscribe_Logger::alert('Pause Subscription Exception: ' . print_r($e, true));
                wp_send_json_error(['message' => 'Failed to pause subscription']);
            }
        }

        public function resume_subscription()
        {
            if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'pause_subscription_nonce')) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }

            WC_Solid_Subscribe_Logger::debug('Remove Pause Subscription Request: ' . print_r($_POST, true));

            if (!empty($_POST['subscription_id'])) {
                $subscription_id = sanitize_text_field($_POST['subscription_id']);
            } else {
                wp_send_json_error(['message' => 'Subscription Id is required']);
            }

            try {
                $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription_id);

                if ($subscription_mapping) {
                    $subscription_uuid = $subscription_mapping->uuid;
                } else {
                    wp_send_json_error(['message' => 'Subscription not found']);
                    return;
                }


                $response = $this->api->removePauseSchedule($subscription_uuid);

                WC_Solid_Subscribe_Logger::debug('Remove Pause Subscription Response: ' . print_r($response, true));

                if (!is_wp_error($response)) {
                    $body = json_decode($response, true);
                    if ($body['status'] === 'active') {
                        delete_post_meta($subscription_id, '_pause_schedule_exists');
                        delete_post_meta($subscription_id, '_pause_start_point');
                        delete_post_meta($subscription_id, '_pause_stop_point');
                        wcs_get_subscription($subscription_id)->update_status('active');
                        wp_send_json_success(['message' => 'Subscription pause removed successfully']);
                    } else {
                        wp_send_json_error(['message' => 'Failed to remove subscription pause']);
                    }

                } else {
                    wp_send_json_error(['message' => 'Failed to remove subscription pause']);
                }
            } catch (Exception $e) {
                WC_Solid_Subscribe_Logger::alert('Remove Pause Subscription Exception: ' . print_r($e, true));
                wp_send_json_error(['message' => 'Failed to remove subscription pause']);
            }
        }

        public function restore_subscription()
        {
            if (!isset($_POST['_nonce']) || !wp_verify_nonce($_POST['_nonce'], 'pause_subscription_nonce')) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }

            WC_Solid_Subscribe_Logger::debug('Restore Subscription Request: ' . print_r($_POST, true));

            if (!empty($_POST['subscription_id'])) {
                $subscription_id = sanitize_text_field($_POST['subscription_id']);
            } else {
                wp_send_json_error(['message' => 'Subscription Id is required']);
            }

            if (!in_array(wcs_get_subscription($subscription_id)->get_status(), ['cancelled', 'expired'])) {
                wp_send_json_error(['message' => 'Subscription is not cancelled or expired']);
            }

            try {
                $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription_id);

                if ($subscription_mapping) {
                    $subscription_uuid = $subscription_mapping->uuid;
                } else {
                    wp_send_json_error(['message' => 'Subscription not found']);
                    return;
                }

                $data = [
                    'subscription_id' => $subscription_uuid,
                ];

                $response = $this->api->reactivateSubscription($data);

                WC_Solid_Subscribe_Logger::debug('Restore Subscription Response: ' . print_r($response, true));

                if (!is_wp_error($response)) {
                    $body = json_decode($response, true);
                    if ($body['status'] === 'ok') {
                        WC_Solid_Subscribe_Logger::debug('Очіукую що помилка тут');
                        $new_subscription_id = $this->renew_subscription($subscription_id, $subscription_uuid);
                        if (!$new_subscription_id) {
                            wp_send_json_error(['message' => 'Failed to restore subscription']);
                        }
                        WC_Solid_Subscribe_Logger::debug('New Subscription ID: ' . print_r($new_subscription_id, true));
                        wp_send_json_success(['message' => 'Subscription restored successfully', 'url' => get_edit_post_link($new_subscription_id)]);
                    } else {
                        wp_send_json_error(['message' => 'Failed to restore subscription']);
                    }

                } else {
                    wp_send_json_error(['message' => 'Failed to restore subscription']);
                }
            } catch (Exception $e) {
                WC_Solid_Subscribe_Logger::alert('Restore Subscription Exception: ' . print_r($e, true));
                wp_send_json_error(['message' => 'Failed to restore subscription']);
            }
        }

        /**
         * Створення нової підписки, як сутність
         *
         * @param $subscription_id
         * @return bool|int
         */
        public function renew_subscription($subscription_id, $subscription_uuid)
        {
            $order_id = wcs_get_subscription($subscription_id)->get_parent_id();
            $order = wc_get_order($order_id);

            $old_subscription = wcs_get_subscription($subscription_id);

            if (!$order_id) {
                WC_Solid_Subscribe_Logger::debug('Order ID is missing for subscription ID: ' . $subscription_id);
                return false;
            }
            if (!$order) {
                WC_Solid_Subscribe_Logger::debug('Order object could not be loaded for order ID: ' . $order_id);
                return false;
            }

            WC_Solid_Subscribe_Logger::debug('Old Subscription ID: ' . print_r($old_subscription->get_id(), true));

            try {
                if ($order) {
                    $new_subscription = wcs_create_subscription([
                        'order_id' => $order_id,
                        'billing_period' => $old_subscription->get_billing_period(),
                        'billing_interval' => $old_subscription->get_billing_interval(),
                    ]);

                    WC_Solid_Subscribe_Logger::debug('New Subscription ID: ' . print_r($new_subscription, true));

                    $new_subscription->set_trial_end_date($old_subscription->get_date('trial_end'));
                    $new_subscription->set_payment_method($old_subscription->get_payment_method());
                    $new_subscription->set_start_date($old_subscription->get_date('start'));
                    $new_subscription->set_end_date($old_subscription->get_date('end'));
                    $new_subscription->set_customer_id($old_subscription->get_customer_id());
                    $new_subscription->set_payment_method($old_subscription->get_payment_method());
                    $new_subscription->set_customer_note(
                        sprintf(__('Subscription renewed from #%s', 'textdomain'), $old_subscription->get_id())
                    );

                    foreach ($old_subscription->get_items() as $item) {
                        $product = $item->get_product();
                        if (!$product) {
                            WC_Solid_Subscribe_Logger::alert('Product is missing for item in subscription ID: ' . $old_subscription->get_id());
                            continue;
                        }

                        $new_subscription->add_product(
                            $product,
                            $item->get_quantity()
                        );
                    }

                    $new_subscription->calculate_totals();

                    $new_subscription->update_status('active');

                    $new_subscription->save();

                    WC_Solid_Subscribe_Logger::debug('New Subscription ID: ' . print_r($new_subscription->get_id(), true));

                    WC_Solid_Subscribe_Logger::debug('Subscription $subscription_uuid, $new_subscription->get_id() ' . print_r($subscription_uuid, true) . ' asdasdsaaasdad ' . print_r($new_subscription->get_id(), true));

                    WC_Solid_Subscribe_Model::update_subscription_mapping_by_uuid($subscription_uuid, $new_subscription->get_id());

                    return $new_subscription->get_id();
                }
            } catch (Exception $e) {
                WC_Solid_Subscribe_Logger::alert('Renew Subscription Exception: ' . $e->getMessage());
            }

            WC_Solid_Subscribe_Logger::debug('Failed to renew subscription');

            return false;
        }

        public function send_status_change_to_gateway($subscription, $new_status, $old_status)
        {
            $subscription_mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id());

            if ($subscription_mapping) {
                $subscription_id = $subscription_mapping->uuid;
            } else {
                return;
            }

            // Логи для відлагодження
            WC_Solid_Subscribe_Logger::debug("Підписка #$subscription_id: статус змінено з $old_status на $new_status");

            // Відправляємо дані на платіжний шлюз
            $gateway_response = $this->send_status_to_gateway($subscription_id, $old_status, $new_status);

            // Логи відповіді шлюзу
            WC_Solid_Subscribe_Logger::debug("Відповідь платіжного шлюзу: $gateway_response");
        }

        private function send_status_to_gateway($subscription_id, $old_status, $new_status): string
        {
            if ($new_status === 'cancelled' && $old_status === 'on-hold') {
                $data = [
                    'subscription_id' => $subscription_id,
                    'force' => true,
                    'cancel_code' => '8.06',
                ];
                $response = $this->api->cancelSubscription($data);
            } elseif ($new_status === 'cancelled' && $old_status === 'active') {
                $data = [
                    'subscription_id' => $subscription_id,
                    'force' => true,
                    'cancel_code' => '8.06',
                ];
                $response = $this->api->cancelSubscription($data);
            } elseif ($new_status === 'pending-cancel' && $old_status === 'active') {
                $data = [
                    'subscription_id' => $subscription_id,
                    'force' => false,
                    'cancel_code' => '8.06',
                ];
                $response = $this->api->cancelSubscription($data);
            } elseif ($new_status === 'pending-cancel' && $old_status === 'on-hold') {
                $data = [
                    'subscription_id' => $subscription_id,
                    'force' => false,
                    'cancel_code' => '8.06',
                ];
                $response = $this->api->cancelSubscription($data);
            } elseif ($new_status === 'cancelled' && $old_status === 'pending-cancel') {
                $data = [
                    'subscription_id' => $subscription_id,
                    'force' => true,
                    'cancel_code' => '8.06',
                ];
                $response = $this->api->cancelSubscription($data);
            } elseif ($new_status === 'active' && $old_status === 'pending-cancel') {
                $data = [
                    'subscription_id' => $subscription_id,
                ];
                $response = $this->api->reactivateSubscription($data);
            } else {
                $response = 'No action required';
            }

            return $response;
        }

        public function cancel_subscription($order, $product)
        {
            $subscriptions = wcs_get_subscriptions_for_order($order);

            foreach ($subscriptions as $subscription) {
                $subscription_uuid = WC_Solid_Subscribe_Model::get_subscription_mapping_by_subscription_id($subscription->get_id())->uuid;

                $data = [
                    'subscription_id' => $subscription_uuid,
                    'force' => false,
                    'cancel_code' => '8.06',
                ];

                $response = $this->api->cancelSubscription($data);

                if (!is_wp_error($response)) {
                    $body = json_decode($response, true);
                    if ($body['status'] === 'ok') {
                        $subscription->update_status('pending-cancel');
                    }
                }
            }
        }


        public function error_code_lookup($code): string
        {
            $messages = array(
                '3.02' => 'Not enough funds for payment on the card. Please try to use another card or choose another payment method.',
                '2.06' => 'CVV code is wrong. CVV code is the last three digits on the back of the card. Please, try again.',
                '2.08' => 'Card number is wrong. Enter the card number exactly as it is written on your bank card.',
                '2.09' => 'This card has expired. Try using another card.',
                '3.06' => 'Unfortunately, debit cards do not support online payments. Try using a credit card or choose another payment method. ',
                '4.09' => 'Your payment was declined due to technical error. Please contact support team.',
            );
            return $messages[$code] ?? 'Card is blocked for the Internet payments. Contact support of your bank to unblock your card or use another card.';
        }

        private function get_iso_4217_currencies(): array
        {
            return [
                ['code' => 'AED', 'name' => 'United Arab Emirates Dirham'],
                ['code' => 'AFN', 'name' => 'Afghan Afghani'],
                ['code' => 'ALL', 'name' => 'Albanian Lek'],
                ['code' => 'AMD', 'name' => 'Armenian Dram'],
                ['code' => 'ANG', 'name' => 'Netherlands Antillean Guilder'],
                ['code' => 'AOA', 'name' => 'Angolan Kwanza'],
                ['code' => 'ARS', 'name' => 'Argentine Peso'],
                ['code' => 'AUD', 'name' => 'Australian Dollar'],
                ['code' => 'AWG', 'name' => 'Aruban Florin'],
                ['code' => 'AZN', 'name' => 'Azerbaijani Manat'],
                ['code' => 'BAM', 'name' => 'Bosnia-Herzegovina Convertible Mark'],
                ['code' => 'BBD', 'name' => 'Barbadian Dollar'],
                ['code' => 'BDT', 'name' => 'Bangladeshi Taka'],
                ['code' => 'BGN', 'name' => 'Bulgarian Lev'],
                ['code' => 'BHD', 'name' => 'Bahraini Dinar'],
                ['code' => 'BIF', 'name' => 'Burundian Franc'],
                ['code' => 'BMD', 'name' => 'Bermudian Dollar'],
                ['code' => 'BND', 'name' => 'Brunei Dollar'],
                ['code' => 'BOB', 'name' => 'Bolivian Boliviano'],
                ['code' => 'BRL', 'name' => 'Brazilian Real'],
                ['code' => 'BSD', 'name' => 'Bahamian Dollar'],
                ['code' => 'BTN', 'name' => 'Bhutanese Ngultrum'],
                ['code' => 'BWP', 'name' => 'Botswana Pula'],
                ['code' => 'BYN', 'name' => 'Belarusian Ruble'],
                ['code' => 'BZD', 'name' => 'Belize Dollar'],
                ['code' => 'CAD', 'name' => 'Canadian Dollar'],
                ['code' => 'CDF', 'name' => 'Congolese Franc'],
                ['code' => 'CHF', 'name' => 'Swiss Franc'],
                ['code' => 'CLP', 'name' => 'Chilean Peso'],
                ['code' => 'CNY', 'name' => 'Chinese Yuan'],
                ['code' => 'COP', 'name' => 'Colombian Peso'],
                ['code' => 'CRC', 'name' => 'Costa Rican Colón'],
                ['code' => 'CUP', 'name' => 'Cuban Peso'],
                ['code' => 'CVE', 'name' => 'Cape Verdean Escudo'],
                ['code' => 'CZK', 'name' => 'Czech Koruna'],
                ['code' => 'DJF', 'name' => 'Djiboutian Franc'],
                ['code' => 'DKK', 'name' => 'Danish Krone'],
                ['code' => 'DOP', 'name' => 'Dominican Peso'],
                ['code' => 'DZD', 'name' => 'Algerian Dinar'],
                ['code' => 'EGP', 'name' => 'Egyptian Pound'],
                ['code' => 'ERN', 'name' => 'Eritrean Nakfa'],
                ['code' => 'ETB', 'name' => 'Ethiopian Birr'],
                ['code' => 'EUR', 'name' => 'Euro'],
                ['code' => 'FJD', 'name' => 'Fijian Dollar'],
                ['code' => 'FKP', 'name' => 'Falkland Islands Pound'],
                ['code' => 'FOK', 'name' => 'Faroese Króna'],
                ['code' => 'GBP', 'name' => 'British Pound'],
                ['code' => 'GEL', 'name' => 'Georgian Lari'],
                ['code' => 'GHS', 'name' => 'Ghanaian Cedi'],
                ['code' => 'GIP', 'name' => 'Gibraltar Pound'],
                ['code' => 'GMD', 'name' => 'Gambian Dalasi'],
                ['code' => 'GNF', 'name' => 'Guinean Franc'],
                ['code' => 'GTQ', 'name' => 'Guatemalan Quetzal'],
                ['code' => 'GYD', 'name' => 'Guyanese Dollar'],
                ['code' => 'HKD', 'name' => 'Hong Kong Dollar'],
                ['code' => 'HNL', 'name' => 'Honduran Lempira'],
                ['code' => 'HRK', 'name' => 'Croatian Kuna'],
                ['code' => 'HTG', 'name' => 'Haitian Gourde'],
                ['code' => 'HUF', 'name' => 'Hungarian Forint'],
                ['code' => 'IDR', 'name' => 'Indonesian Rupiah'],
                ['code' => 'ILS', 'name' => 'Israeli New Shekel'],
                ['code' => 'INR', 'name' => 'Indian Rupee'],
                ['code' => 'IQD', 'name' => 'Iraqi Dinar'],
                ['code' => 'IRR', 'name' => 'Iranian Rial'],
                ['code' => 'ISK', 'name' => 'Icelandic Króna'],
                ['code' => 'JMD', 'name' => 'Jamaican Dollar'],
                ['code' => 'JOD', 'name' => 'Jordanian Dinar'],
                ['code' => 'JPY', 'name' => 'Japanese Yen'],
                ['code' => 'KES', 'name' => 'Kenyan Shilling'],
                ['code' => 'KGS', 'name' => 'Kyrgyzstani Som'],
                ['code' => 'KHR', 'name' => 'Cambodian Riel'],
                ['code' => 'KPW', 'name' => 'North Korean Won'],
                ['code' => 'KRW', 'name' => 'South Korean Won'],
                ['code' => 'KWD', 'name' => 'Kuwaiti Dinar'],
                ['code' => 'KYD', 'name' => 'Cayman Islands Dollar'],
                ['code' => 'KZT', 'name' => 'Kazakhstani Tenge'],
                ['code' => 'LAK', 'name' => 'Lao Kip'],
                ['code' => 'LBP', 'name' => 'Lebanese Pound'],
                ['code' => 'LKR', 'name' => 'Sri Lankan Rupee'],
                ['code' => 'LRD', 'name' => 'Liberian Dollar'],
                ['code' => 'LSL', 'name' => 'Lesotho Loti'],
                ['code' => 'LYD', 'name' => 'Libyan Dinar'],
                ['code' => 'MAD', 'name' => 'Moroccan Dirham'],
                ['code' => 'MDL', 'name' => 'Moldovan Leu'],
                ['code' => 'MGA', 'name' => 'Malagasy Ariary'],
                ['code' => 'MKD', 'name' => 'Macedonian Denar'],
                ['code' => 'MMK', 'name' => 'Myanmar Kyat'],
                ['code' => 'MNT', 'name' => 'Mongolian Tögrög'],
                ['code' => 'MOP', 'name' => 'Macanese Pataca'],
                ['code' => 'MRU', 'name' => 'Mauritanian Ouguiya'],
                ['code' => 'MUR', 'name' => 'Mauritian Rupee'],
                ['code' => 'MVR', 'name' => 'Maldivian Rufiyaa'],
                ['code' => 'MWK', 'name' => 'Malawian Kwacha'],
                ['code' => 'MXN', 'name' => 'Mexican Peso'],
                ['code' => 'MYR', 'name' => 'Malaysian Ringgit'],
                ['code' => 'MZN', 'name' => 'Mozambican Metical'],
                ['code' => 'NAD', 'name' => 'Namibian Dollar'],
                ['code' => 'NGN', 'name' => 'Nigerian Naira'],
                ['code' => 'NOK', 'name' => 'Norwegian Krone'],
                ['code' => 'NPR', 'name' => 'Nepalese Rupee'],
                ['code' => 'NZD', 'name' => 'New Zealand Dollar'],
                ['code' => 'OMR', 'name' => 'Omani Rial'],
                ['code' => 'PAB', 'name' => 'Panamanian Balboa'],
                ['code' => 'PEN', 'name' => 'Peruvian Sol'],
                ['code' => 'PGK', 'name' => 'Papua New Guinean Kina'],
                ['code' => 'PHP', 'name' => 'Philippine Peso'],
                ['code' => 'PKR', 'name' => 'Pakistani Rupee'],
                ['code' => 'PLN', 'name' => 'Polish Złoty'],
                ['code' => 'PYG', 'name' => 'Paraguayan Guaraní'],
                ['code' => 'QAR', 'name' => 'Qatari Riyal'],
                ['code' => 'RON', 'name' => 'Romanian Leu'],
                ['code' => 'RSD', 'name' => 'Serbian Dinar'],
                ['code' => 'RUB', 'name' => 'Russian Ruble'],
                ['code' => 'RWF', 'name' => 'Rwandan Franc'],
                ['code' => 'SAR', 'name' => 'Saudi Riyal'],
                ['code' => 'SBD', 'name' => 'Solomon Islands Dollar'],
                ['code' => 'SCR', 'name' => 'Seychellois Rupee'],
                ['code' => 'SDG', 'name' => 'Sudanese Pound'],
                ['code' => 'SEK', 'name' => 'Swedish Krona'],
                ['code' => 'SGD', 'name' => 'Singapore Dollar'],
                ['code' => 'SHP', 'name' => 'Saint Helena Pound'],
                ['code' => 'SLL', 'name' => 'Sierra Leonean Leone'],
                ['code' => 'SOS', 'name' => 'Somali Shilling'],
                ['code' => 'SRD', 'name' => 'Surinamese Dollar'],
                ['code' => 'SSP', 'name' => 'South Sudanese Pound'],
                ['code' => 'STN', 'name' => 'São Tomé and Príncipe Dobra'],
                ['code' => 'SYP', 'name' => 'Syrian Pound'],
                ['code' => 'SZL', 'name' => 'Eswatini Lilangeni'],
                ['code' => 'THB', 'name' => 'Thai Baht'],
                ['code' => 'TJS', 'name' => 'Tajikistani Somoni'],
                ['code' => 'TMT', 'name' => 'Turkmenistani Manat'],
                ['code' => 'TND', 'name' => 'Tunisian Dinar'],
                ['code' => 'TOP', 'name' => 'Tongan Paʻanga'],
                ['code' => 'TRY', 'name' => 'Turkish Lira'],
                ['code' => 'TTD', 'name' => 'Trinidad and Tobago Dollar'],
                ['code' => 'TWD', 'name' => 'New Taiwan Dollar'],
                ['code' => 'TZS', 'name' => 'Tanzanian Shilling'],
                ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia'],
                ['code' => 'UGX', 'name' => 'Ugandan Shilling'],
                ['code' => 'USD', 'name' => 'United States Dollar'],
                ['code' => 'UYU', 'name' => 'Uruguayan Peso'],
                ['code' => 'UZS', 'name' => 'Uzbekistani Soʻm'],
                ['code' => 'VES', 'name' => 'Venezuelan Bolívar'],
                ['code' => 'VND', 'name' => 'Vietnamese Đồng'],
                ['code' => 'XAF', 'name' => 'Central African CFA Franc'],
                ['code' => 'XCD', 'name' => 'East Caribbean Dollar'],
                ['code' => 'XOF', 'name' => 'West African CFA Franc'],
                ['code' => 'XPF', 'name' => 'CFP Franc'],
                ['code' => 'YER', 'name' => 'Yemeni Rial'],
                ['code' => 'ZAR', 'name' => 'South African Rand'],
                ['code' => 'ZMW', 'name' => 'Zambian Kwacha'],
                ['code' => 'ZWL', 'name' => 'Zimbabwean Dollar'],
            ];
        }

        private function get_iso_3166_countries(): array
        {
            return [
                ['code' => 'AFG', 'name' => 'Afghanistan'],
                ['code' => 'ALB', 'name' => 'Albania'],
                ['code' => 'DZA', 'name' => 'Algeria'],
                ['code' => 'ASM', 'name' => 'American Samoa'],
                ['code' => 'AND', 'name' => 'Andorra'],
                ['code' => 'AGO', 'name' => 'Angola'],
                ['code' => 'AIA', 'name' => 'Anguilla'],
                ['code' => 'ATA', 'name' => 'Antarctica'],
                ['code' => 'ATG', 'name' => 'Antigua and Barbuda'],
                ['code' => 'ARG', 'name' => 'Argentina'],
                ['code' => 'ARM', 'name' => 'Armenia'],
                ['code' => 'ABW', 'name' => 'Aruba'],
                ['code' => 'AUS', 'name' => 'Australia'],
                ['code' => 'AUT', 'name' => 'Austria'],
                ['code' => 'AZE', 'name' => 'Azerbaijan'],
                ['code' => 'BHS', 'name' => 'Bahamas'],
                ['code' => 'BHR', 'name' => 'Bahrain'],
                ['code' => 'BGD', 'name' => 'Bangladesh'],
                ['code' => 'BRB', 'name' => 'Barbados'],
                ['code' => 'BLR', 'name' => 'Belarus'],
                ['code' => 'BEL', 'name' => 'Belgium'],
                ['code' => 'BLZ', 'name' => 'Belize'],
                ['code' => 'BEN', 'name' => 'Benin'],
                ['code' => 'BMU', 'name' => 'Bermuda'],
                ['code' => 'BTN', 'name' => 'Bhutan'],
                ['code' => 'BOL', 'name' => 'Bolivia'],
                ['code' => 'BIH', 'name' => 'Bosnia and Herzegovina'],
                ['code' => 'BWA', 'name' => 'Botswana'],
                ['code' => 'BVT', 'name' => 'Bouvet Island'],
                ['code' => 'BRA', 'name' => 'Brazil'],
                ['code' => 'IOT', 'name' => 'British Indian Ocean Territory'],
                ['code' => 'BRN', 'name' => 'Brunei Darussalam'],
                ['code' => 'BGR', 'name' => 'Bulgaria'],
                ['code' => 'BFA', 'name' => 'Burkina Faso'],
                ['code' => 'BDI', 'name' => 'Burundi'],
                ['code' => 'CPV', 'name' => 'Cabo Verde'],
                ['code' => 'KHM', 'name' => 'Cambodia'],
                ['code' => 'CMR', 'name' => 'Cameroon'],
                ['code' => 'CAN', 'name' => 'Canada'],
                ['code' => 'CYM', 'name' => 'Cayman Islands'],
                ['code' => 'CAF', 'name' => 'Central African Republic'],
                ['code' => 'TCD', 'name' => 'Chad'],
                ['code' => 'CHL', 'name' => 'Chile'],
                ['code' => 'CHN', 'name' => 'China'],
                ['code' => 'CXR', 'name' => 'Christmas Island'],
                ['code' => 'CCK', 'name' => 'Cocos (Keeling) Islands'],
                ['code' => 'COL', 'name' => 'Colombia'],
                ['code' => 'COM', 'name' => 'Comoros'],
                ['code' => 'COG', 'name' => 'Congo'],
                ['code' => 'COD', 'name' => 'Congo (Democratic Republic of the)'],
                ['code' => 'COK', 'name' => 'Cook Islands'],
                ['code' => 'CRI', 'name' => 'Costa Rica'],
                ['code' => 'HRV', 'name' => 'Croatia'],
                ['code' => 'CUB', 'name' => 'Cuba'],
                ['code' => 'CUW', 'name' => 'Curaçao'],
                ['code' => 'CYP', 'name' => 'Cyprus'],
                ['code' => 'CZE', 'name' => 'Czechia'],
                ['code' => 'DNK', 'name' => 'Denmark'],
                ['code' => 'DJI', 'name' => 'Djibouti'],
                ['code' => 'DMA', 'name' => 'Dominica'],
                ['code' => 'DOM', 'name' => 'Dominican Republic'],
                ['code' => 'ECU', 'name' => 'Ecuador'],
                ['code' => 'EGY', 'name' => 'Egypt'],
                ['code' => 'SLV', 'name' => 'El Salvador'],
                ['code' => 'GNQ', 'name' => 'Equatorial Guinea'],
                ['code' => 'ERI', 'name' => 'Eritrea'],
                ['code' => 'EST', 'name' => 'Estonia'],
                ['code' => 'SWZ', 'name' => 'Eswatini'],
                ['code' => 'ETH', 'name' => 'Ethiopia'],
                ['code' => 'FLK', 'name' => 'Falkland Islands (Malvinas)'],
                ['code' => 'FRO', 'name' => 'Faroe Islands'],
                ['code' => 'FJI', 'name' => 'Fiji'],
                ['code' => 'FIN', 'name' => 'Finland'],
                ['code' => 'FRA', 'name' => 'France'],
                ['code' => 'GUF', 'name' => 'French Guiana'],
                ['code' => 'PYF', 'name' => 'French Polynesia'],
                ['code' => 'ATF', 'name' => 'French Southern Territories'],
                ['code' => 'GAB', 'name' => 'Gabon'],
                ['code' => 'GMB', 'name' => 'Gambia'],
                ['code' => 'GEO', 'name' => 'Georgia'],
                ['code' => 'DEU', 'name' => 'Germany'],
                ['code' => 'GHA', 'name' => 'Ghana'],
                ['code' => 'GIB', 'name' => 'Gibraltar'],
                ['code' => 'GRC', 'name' => 'Greece'],
                ['code' => 'GRL', 'name' => 'Greenland'],
                ['code' => 'GRD', 'name' => 'Grenada'],
                ['code' => 'GLP', 'name' => 'Guadeloupe'],
                ['code' => 'GUM', 'name' => 'Guam'],
                ['code' => 'GTM', 'name' => 'Guatemala'],
                ['code' => 'GGY', 'name' => 'Guernsey'],
                ['code' => 'GIN', 'name' => 'Guinea'],
                ['code' => 'GNB', 'name' => 'Guinea-Bissau'],
                ['code' => 'GUY', 'name' => 'Guyana'],
                ['code' => 'HTI', 'name' => 'Haiti'],
                ['code' => 'HMD', 'name' => 'Heard Island and McDonald Islands'],
                ['code' => 'VAT', 'name' => 'Holy See'],
                ['code' => 'HND', 'name' => 'Honduras'],
                ['code' => 'HKG', 'name' => 'Hong Kong'],
                ['code' => 'HUN', 'name' => 'Hungary'],
                ['code' => 'ISL', 'name' => 'Iceland'],
                ['code' => 'IND', 'name' => 'India'],
                ['code' => 'IDN', 'name' => 'Indonesia'],
                ['code' => 'IRN', 'name' => 'Iran'],
                ['code' => 'IRQ', 'name' => 'Iraq'],
                ['code' => 'IRL', 'name' => 'Ireland'],
                ['code' => 'IMN', 'name' => 'Isle of Man'],
                ['code' => 'ISR', 'name' => 'Israel'],
                ['code' => 'ITA', 'name' => 'Italy'],
                ['code' => 'JAM', 'name' => 'Jamaica'],
                ['code' => 'JPN', 'name' => 'Japan'],
                ['code' => 'JEY', 'name' => 'Jersey'],
                ['code' => 'JOR', 'name' => 'Jordan'],
                ['code' => 'KAZ', 'name' => 'Kazakhstan'],
                ['code' => 'KEN', 'name' => 'Kenya'],
                ['code' => 'KIR', 'name' => 'Kiribati'],
                ['code' => 'PRK', 'name' => 'North Korea'],
                ['code' => 'KOR', 'name' => 'South Korea'],
                ['code' => 'KWT', 'name' => 'Kuwait'],
                ['code' => 'KGZ', 'name' => 'Kyrgyzstan'],
                ['code' => 'LAO', 'name' => 'Laos'],
                ['code' => 'LVA', 'name' => 'Latvia'],
                ['code' => 'LBN', 'name' => 'Lebanon'],
                ['code' => 'LSO', 'name' => 'Lesotho'],
                ['code' => 'LBR', 'name' => 'Liberia'],
                ['code' => 'LBY', 'name' => 'Libya'],
                ['code' => 'LIE', 'name' => 'Liechtenstein'],
                ['code' => 'LTU', 'name' => 'Lithuania'],
                ['code' => 'LUX', 'name' => 'Luxembourg'],
                ['code' => 'MAC', 'name' => 'Macao'],
                ['code' => 'MDG', 'name' => 'Madagascar'],
                ['code' => 'MWI', 'name' => 'Malawi'],
                ['code' => 'MYS', 'name' => 'Malaysia'],
                ['code' => 'MDV', 'name' => 'Maldives'],
                ['code' => 'MLI', 'name' => 'Mali'],
                ['code' => 'MLT', 'name' => 'Malta'],
                ['code' => 'MHL', 'name' => 'Marshall Islands'],
                ['code' => 'MTQ', 'name' => 'Martinique'],
                ['code' => 'MRT', 'name' => 'Mauritania'],
                ['code' => 'MUS', 'name' => 'Mauritius'],
                ['code' => 'MYT', 'name' => 'Mayotte'],
                ['code' => 'MEX', 'name' => 'Mexico'],
                ['code' => 'FSM', 'name' => 'Micronesia'],
                ['code' => 'MDA', 'name' => 'Moldova'],
                ['code' => 'MCO', 'name' => 'Monaco'],
                ['code' => 'MNG', 'name' => 'Mongolia'],
                ['code' => 'MNE', 'name' => 'Montenegro'],
                ['code' => 'MSR', 'name' => 'Montserrat'],
                ['code' => 'MAR', 'name' => 'Morocco'],
                ['code' => 'MOZ', 'name' => 'Mozambique'],
                ['code' => 'MMR', 'name' => 'Myanmar'],
                ['code' => 'NAM', 'name' => 'Namibia'],
                ['code' => 'NRU', 'name' => 'Nauru'],
                ['code' => 'NPL', 'name' => 'Nepal'],
                ['code' => 'NLD', 'name' => 'Netherlands'],
                ['code' => 'NCL', 'name' => 'New Caledonia'],
                ['code' => 'NZL', 'name' => 'New Zealand'],
                ['code' => 'NIC', 'name' => 'Nicaragua'],
                ['code' => 'NER', 'name' => 'Niger'],
                ['code' => 'NGA', 'name' => 'Nigeria'],
                ['code' => 'NIU', 'name' => 'Niue'],
                ['code' => 'NFK', 'name' => 'Norfolk Island'],
                ['code' => 'MNP', 'name' => 'Northern Mariana Islands'],
                ['code' => 'NOR', 'name' => 'Norway'],
                ['code' => 'OMN', 'name' => 'Oman'],
                ['code' => 'PAK', 'name' => 'Pakistan'],
                ['code' => 'PLW', 'name' => 'Palau'],
                ['code' => 'PSE', 'name' => 'Palestine'],
                ['code' => 'PAN', 'name' => 'Panama'],
                ['code' => 'PNG', 'name' => 'Papua New Guinea'],
                ['code' => 'PRY', 'name' => 'Paraguay'],
                ['code' => 'PER', 'name' => 'Peru'],
                ['code' => 'PHL', 'name' => 'Philippines'],
                ['code' => 'PCN', 'name' => 'Pitcairn'],
                ['code' => 'POL', 'name' => 'Poland'],
                ['code' => 'PRT', 'name' => 'Portugal'],
                ['code' => 'PRI', 'name' => 'Puerto Rico'],
                ['code' => 'QAT', 'name' => 'Qatar'],
                ['code' => 'MKD', 'name' => 'North Macedonia'],
                ['code' => 'ROU', 'name' => 'Romania'],
                ['code' => 'RUS', 'name' => 'Russia'],
                ['code' => 'RWA', 'name' => 'Rwanda'],
                ['code' => 'REU', 'name' => 'Réunion'],
                ['code' => 'BLM', 'name' => 'Saint Barthélemy'],
                ['code' => 'SHN', 'name' => 'Saint Helena'],
                ['code' => 'KNA', 'name' => 'Saint Kitts and Nevis'],
                ['code' => 'LCA', 'name' => 'Saint Lucia'],
                ['code' => 'MAF', 'name' => 'Saint Martin'],
                ['code' => 'SPM', 'name' => 'Saint Pierre and Miquelon'],
                ['code' => 'VCT', 'name' => 'Saint Vincent and the Grenadines'],
                ['code' => 'WSM', 'name' => 'Samoa'],
                ['code' => 'SMR', 'name' => 'San Marino'],
                ['code' => 'STP', 'name' => 'Sao Tome and Principe'],
                ['code' => 'SAU', 'name' => 'Saudi Arabia'],
                ['code' => 'SEN', 'name' => 'Senegal'],
                ['code' => 'SRB', 'name' => 'Serbia'],
                ['code' => 'SYC', 'name' => 'Seychelles'],
                ['code' => 'SLE', 'name' => 'Sierra Leone'],
                ['code' => 'SGP', 'name' => 'Singapore'],
                ['code' => 'SXM', 'name' => 'Sint Maarten'],
                ['code' => 'SVK', 'name' => 'Slovakia'],
                ['code' => 'SVN', 'name' => 'Slovenia'],
                ['code' => 'SLB', 'name' => 'Solomon Islands'],
                ['code' => 'SOM', 'name' => 'Somalia'],
                ['code' => 'ZAF', 'name' => 'South Africa'],
                ['code' => 'SGS', 'name' => 'South Georgia'],
                ['code' => 'SSD', 'name' => 'South Sudan'],
                ['code' => 'ESP', 'name' => 'Spain'],
                ['code' => 'LKA', 'name' => 'Sri Lanka'],
                ['code' => 'SDN', 'name' => 'Sudan'],
                ['code' => 'SUR', 'name' => 'Suriname'],
                ['code' => 'SJM', 'name' => 'Svalbard and Jan Mayen'],
                ['code' => 'SWE', 'name' => 'Sweden'],
                ['code' => 'CHE', 'name' => 'Switzerland'],
                ['code' => 'SYR', 'name' => 'Syria'],
                ['code' => 'TWN', 'name' => 'Taiwan'],
                ['code' => 'TJK', 'name' => 'Tajikistan'],
                ['code' => 'TZA', 'name' => 'Tanzania'],
                ['code' => 'THA', 'name' => 'Thailand'],
                ['code' => 'TLS', 'name' => 'Timor-Leste'],
                ['code' => 'TGO', 'name' => 'Togo'],
                ['code' => 'TKL', 'name' => 'Tokelau'],
                ['code' => 'TON', 'name' => 'Tonga'],
                ['code' => 'TTO', 'name' => 'Trinidad and Tobago'],
                ['code' => 'TUN', 'name' => 'Tunisia'],
                ['code' => 'TUR', 'name' => 'Turkey'],
                ['code' => 'TKM', 'name' => 'Turkmenistan'],
                ['code' => 'TCA', 'name' => 'Turks and Caicos Islands'],
                ['code' => 'TUV', 'name' => 'Tuvalu'],
                ['code' => 'UGA', 'name' => 'Uganda'],
                ['code' => 'UKR', 'name' => 'Ukraine'],
                ['code' => 'ARE', 'name' => 'United Arab Emirates'],
                ['code' => 'GBR', 'name' => 'United Kingdom'],
                ['code' => 'USA', 'name' => 'United States'],
                ['code' => 'URY', 'name' => 'Uruguay'],
                ['code' => 'UZB', 'name' => 'Uzbekistan'],
                ['code' => 'VUT', 'name' => 'Vanuatu'],
                ['code' => 'VEN', 'name' => 'Venezuela'],
                ['code' => 'VNM', 'name' => 'Vietnam'],
                ['code' => 'WLF', 'name' => 'Wallis and Futuna'],
                ['code' => 'ESH', 'name' => 'Western Sahara'],
                ['code' => 'YEM', 'name' => 'Yemen'],
                ['code' => 'ZMB', 'name' => 'Zambia'],
                ['code' => 'ZWE', 'name' => 'Zimbabwe'],
            ];
        }

        private function get_country_alpha3($code)
        {
            $country_codes = array(
                'AF' => 'AFG',
                'AL' => 'ALB',
                'DZ' => 'DZA',
                'AS' => 'ASM',
                'AD' => 'AND',
                'AO' => 'AGO',
                'AI' => 'AIA',
                'AQ' => 'ATA',
                'AG' => 'ATG',
                'AR' => 'ARG',
                'AM' => 'ARM',
                'AW' => 'ABW',
                'AU' => 'AUS',
                'AT' => 'AUT',
                'AZ' => 'AZE',
                'BS' => 'BHS',
                'BH' => 'BHR',
                'BD' => 'BGD',
                'BB' => 'BRB',
                'BY' => 'BLR',
                'BE' => 'BEL',
                'BZ' => 'BLZ',
                'BJ' => 'BEN',
                'BM' => 'BMU',
                'BT' => 'BTN',
                'BO' => 'BOL',
                'BA' => 'BIH',
                'BW' => 'BWA',
                'BV' => 'BVT',
                'BR' => 'BRA',
                'IO' => 'IOT',
                'BN' => 'BRN',
                'BG' => 'BGR',
                'BF' => 'BFA',
                'BI' => 'BDI',
                'CV' => 'CPV',
                'KH' => 'KHM',
                'CM' => 'CMR',
                'CA' => 'CAN',
                'KY' => 'CYM',
                'CF' => 'CAF',
                'TD' => 'TCD',
                'CL' => 'CHL',
                'CN' => 'CHN',
                'CX' => 'CXR',
                'CC' => 'CCK',
                'CO' => 'COL',
                'KM' => 'COM',
                'CG' => 'COG',
                'CD' => 'COD',
                'CK' => 'COK',
                'CR' => 'CRI',
                'HR' => 'HRV',
                'CU' => 'CUB',
                'CW' => 'CUW',
                'CY' => 'CYP',
                'CZ' => 'CZE',
                'DK' => 'DNK',
                'DJ' => 'DJI',
                'DM' => 'DMA',
                'DO' => 'DOM',
                'EC' => 'ECU',
                'EG' => 'EGY',
                'SV' => 'SLV',
                'GQ' => 'GNQ',
                'ER' => 'ERI',
                'EE' => 'EST',
                'SZ' => 'SWZ',
                'ET' => 'ETH',
                'FK' => 'FLK',
                'FO' => 'FRO',
                'FJ' => 'FJI',
                'FI' => 'FIN',
                'FR' => 'FRA',
                'GF' => 'GUF',
                'PF' => 'PYF',
                'TF' => 'ATF',
                'GA' => 'GAB',
                'GM' => 'GMB',
                'GE' => 'GEO',
                'DE' => 'DEU',
                'GH' => 'GHA',
                'GI' => 'GIB',
                'GR' => 'GRC',
                'GL' => 'GRL',
                'GD' => 'GRD',
                'GP' => 'GLP',
                'GU' => 'GUM',
                'GT' => 'GTM',
                'GG' => 'GGY',
                'GN' => 'GIN',
                'GW' => 'GNB',
                'GY' => 'GUY',
                'HT' => 'HTI',
                'HM' => 'HMD',
                'VA' => 'VAT',
                'HN' => 'HND',
                'HK' => 'HKG',
                'HU' => 'HUN',
                'IS' => 'ISL',
                'IN' => 'IND',
                'ID' => 'IDN',
                'IR' => 'IRN',
                'IQ' => 'IRQ',
                'IE' => 'IRL',
                'IM' => 'IMN',
                'IL' => 'ISR',
                'IT' => 'ITA',
                'JM' => 'JAM',
                'JP' => 'JPN',
                'JE' => 'JEY',
                'JO' => 'JOR',
                'KZ' => 'KAZ',
                'KE' => 'KEN',
                'KI' => 'KIR',
                'KP' => 'PRK',
                'KR' => 'KOR',
                'KW' => 'KWT',
                'KG' => 'KGZ',
                'LA' => 'LAO',
                'LV' => 'LVA',
                'LB' => 'LBN',
                'LS' => 'LSO',
                'LR' => 'LBR',
                'LY' => 'LBY',
                'LI' => 'LIE',
                'LT' => 'LTU',
                'LU' => 'LUX',
                'MO' => 'MAC',
                'MG' => 'MDG',
                'MW' => 'MWI',
                'MY' => 'MYS',
                'MV' => 'MDV',
                'ML' => 'MLI',
                'MT' => 'MLT',
                'MH' => 'MHL',
                'MQ' => 'MTQ',
                'MR' => 'MRT',
                'MU' => 'MUS',
                'YT' => 'MYT',
                'MX' => 'MEX',
                'FM' => 'FSM',
                'MD' => 'MDA',
                'MC' => 'MCO',
                'MN' => 'MNG',
                'ME' => 'MNE',
                'MS' => 'MSR',
                'MA' => 'MAR',
                'MZ' => 'MOZ',
                'MM' => 'MMR',
                'NA' => 'NAM',
                'NR' => 'NRU',
                'NP' => 'NPL',
                'NL' => 'NLD',
                'NC' => 'NCL',
                'NZ' => 'NZL',
                'NI' => 'NIC',
                'NE' => 'NER',
                'NG' => 'NGA',
                'NU' => 'NIU',
                'NF' => 'NFK',
                'MP' => 'MNP',
                'NO' => 'NOR',
                'OM' => 'OMN',
                'PK' => 'PAK',
                'PW' => 'PLW',
                'PS' => 'PSE',
                'PA' => 'PAN',
                'PG' => 'PNG',
                'PY' => 'PRY',
                'PE' => 'PER',
                'PH' => 'PHL',
                'PN' => 'PCN',
                'PL' => 'POL',
                'PT' => 'PRT',
                'PR' => 'PRI',
                'QA' => 'QAT',
                'MK' => 'MKD',
                'RO' => 'ROU',
                'RU' => 'RUS',
                'RW' => 'RWA',
                'RE' => 'REU',
                'BL' => 'BLM',
                'SH' => 'SHN',
                'KN' => 'KNA',
                'LC' => 'LCA',
                'MF' => 'MAF',
                'PM' => 'SPM',
                'VC' => 'VCT',
                'WS' => 'WSM',
                'SM' => 'SMR',
                'ST' => 'STP',
                'SA' => 'SAU',
                'SN' => 'SEN',
                'RS' => 'SRB',
                'SC' => 'SYC',
                'SL' => 'SLE',
                'SG' => 'SGP',
                'SX' => 'SXM',
                'SK' => 'SVK',
                'SI' => 'SVN',
                'SB' => 'SLB',
                'SO' => 'SOM',
                'ZA' => 'ZAF',
                'GS' => 'SGS',
                'SS' => 'SSD',
                'ES' => 'ESP',
                'LK' => 'LKA',
                'SD' => 'SDN',
                'SR' => 'SUR',
                'SJ' => 'SJM',
                'SE' => 'SWE',
                'CH' => 'CHE',
                'SY' => 'SYR',
                'TW' => 'TWN',
                'TJ' => 'TJK',
                'TZ' => 'TZA',
                'TH' => 'THA',
                'TL' => 'TLS',
                'TG' => 'TGO',
                'TK' => 'TKL',
                'TO' => 'TON',
                'TT' => 'TTO',
                'TN' => 'TUN',
                'TR' => 'TUR',
                'TM' => 'TKM',
                'TC' => 'TCA',
                'TV' => 'TUV',
                'UG' => 'UGA',
                'UA' => 'UKR',
                'AE' => 'ARE',
                'GB' => 'GBR',
                'US' => 'USA',
                'UY' => 'URY',
                'UZ' => 'UZB',
                'VU' => 'VUT',
                'VE' => 'VEN',
                'VN' => 'VNM',
                'WF' => 'WLF',
                'EH' => 'ESH',
                'YE' => 'YEM',
                'ZM' => 'ZMB',
                'ZW' => 'ZWE',
            );

            return $country_codes[$code];
        }

        private function get_user_currency()
        {
            if (class_exists('WC_Geolocation')) {
                return (new WC_Geolocation())->geolocate_ip()['country'];
            }

            return get_option('woocommerce_currency'); // Повертає стандартну валюту магазину
        }
    }
}
