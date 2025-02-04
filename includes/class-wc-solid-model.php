<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WC_Solid_Subscribe_Model
{
    public static function get_subscription_mapping_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'solid_subscription_mappings';
    }

    public static function get_all_subscription_mappings()
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $sql = "SELECT * FROM $table_name";
        return $wpdb->get_results($sql);
    }

    public static function get_subscription_mapping_by_subscription_id($subscription_id)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE subscription_id = %d", $subscription_id);
        return $wpdb->get_row($sql);
    }

    public static function get_subscription_mapping_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE uuid = %s", $uuid);
        return $wpdb->get_row($sql);
    }

    public static function create_subscription_mapping($subscription_id, $uuid)
    {
        if (!self::get_subscription_mapping_by_subscription_id($subscription_id)) {
            global $wpdb;
            $table_name = self::get_subscription_mapping_table_name();
            $result = $wpdb->insert($table_name, [
                'subscription_id' => $subscription_id,
                'uuid' => $uuid,
            ]);
            if ($result === false) {
                error_log('Failed to insert subscription mapping: ' . $wpdb->last_error);
            }

            return $result;
        }
        return false;
    }

    public static function update_subscription_mapping_by_subscription_id($subscription_id, $new_uuid)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $wpdb->update(
            $table_name,
            ['uuid' => $new_uuid],
            ['subscription_id' => $subscription_id]
        );
    }

    public static function update_subscription_mapping_by_uuid($uuid, $new_subscription_id)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $wpdb->update(
            $table_name,
            ['subscription_id' => $new_subscription_id],
            ['uuid' => $uuid]
        );
    }

    public static function delete_subscription_mapping_by_subscription_id($subscription_id)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $wpdb->delete($table_name, ['subscription_id' => $subscription_id]);
    }

    public static function delete_subscription_mapping_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $wpdb->delete($table_name, ['uuid' => $uuid]);
    }

    public static function create_table()
    {
        global $wpdb;
        $table_name = self::get_subscription_mapping_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscription_id bigint(20) NOT NULL,
            uuid varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid)
        ) $charset_collate;";

        error_log($sql);

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

class WC_Solid_Product_Model
{
    public static function get_product_mapping_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'solid_product_mappings';
    }

    public static function get_all_product_mappings()
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $sql = "SELECT * FROM $table_name";
        return $wpdb->get_results($sql);
    }

    public static function get_product_mapping_by_product_id($product_id)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE product_id = %d", $product_id);
        return $wpdb->get_row($sql);
    }

    public static function get_product_mapping_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE uuid = %s", $uuid);
        return $wpdb->get_row($sql);
    }

    public static function create_product_mapping($product_id, $uuid, $is_editable = 1)
    {
        if (!self::get_product_mapping_by_product_id($product_id)) {
            global $wpdb;
            $table_name = self::get_product_mapping_table_name();
            $result = $wpdb->insert($table_name, [
                'product_id' => $product_id,
                'uuid' => $uuid,
                'is_editable' => $is_editable,
            ]);
            if ($result === false) {
                error_log('Failed to insert product mapping: ' . $wpdb->last_error);
            }

            return $result;
        }
        return false;
    }

    public static function update_product_mapping($product_id, $new_uuid, $is_editable = null)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $data = ['uuid' => $new_uuid];
        $where = ['product_id' => $product_id];

        if ($is_editable !== null) {
            $data['is_editable'] = $is_editable;
        }

        $wpdb->update($table_name, $data, $where);
    }

    public static function delete_product_mapping_by_product_id($product_id)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $wpdb->delete($table_name, ['product_id' => $product_id]);
    }

    public static function delete_product_mapping_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $wpdb->delete($table_name, ['uuid' => $uuid]);
    }

    public static function set_is_editable($product_id, $is_editable)
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $wpdb->update($table_name, ['is_editable' => $is_editable], ['product_id' => $product_id]);
    }

    public static function get_is_editable($product_id): ?string
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $sql = $wpdb->prepare("SELECT is_editable FROM $table_name WHERE product_id = %d", $product_id);
        return $wpdb->get_var($sql);
    }

    public static function create_table()
    {
        global $wpdb;
        $table_name = self::get_product_mapping_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            uuid varchar(255) NOT NULL,
            is_editable tinyint(1) DEFAULT 1 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

class WC_Solid_Product_List {
    public static function get_product_list_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'solid_product_list';
    }

    public static function create_table()
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id bigint(20) NOT NULL,
            uuid varchar(36) NOT NULL,
            country_name varchar(255) NOT NULL,
            label varchar(255) NOT NULL,
            banner_label varchar(255) NOT NULL,
            class varchar(255) NOT NULL,
            score varchar(255) NOT NULL,
            currency varchar(3) NOT NULL,
            sign_up_fee bigint(20) NULL,
            sign_up_fee_label varchar(255) NOT NULL,
            price bigint(20) NOT NULL,
            price_label varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            UNIQUE KEY (uuid),
            KEY (product_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function get_all_product_list()
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $sql = "SELECT * FROM $table_name";
        return $wpdb->get_results($sql);
    }

    public static function get_product_list_by_product_id($product_id)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE product_id = %d", $product_id);
        return $wpdb->get_results($sql);
    }

    public static function get_product_list_by_country_name($product_id, $country_name)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE product_id = %s AND country_name = %s", intval($product_id), $country_name);
        WC_Solid_Subscribe_Logger::debug($sql);
        return $wpdb->get_row($sql);
    }

    public static function get_product_list_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE uuid = %s", $uuid);
        return $wpdb->get_row($sql);
    }

    public static function get_product_list_by_country_name_and_currency($product_id, $country_name, $currency)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $sql = $wpdb->prepare("SELECT * FROM $table_name WHERE product_id = %d AND country_name = %s AND currency = %s", $product_id, $country_name, $currency);
        return $wpdb->get_row($sql);
    }

    public static function create_product_list(array $data)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $result = $wpdb->insert($table_name, [
            'product_id' => $data['product_id'],
            'uuid' => $data['uuid'],
            'country_name' => $data['country_name'],
            'label' => $data['label'],
            'banner_label' => $data['banner_label'],
            'class' => $data['class'],
            'score' => $data['score'],
            'currency' => $data['currency'],
            'sign_up_fee' => $data['sign_up_fee'],
            'sign_up_fee_label' => $data['sign_up_fee_label'],
            'price' => $data['price'],
            'price_label' => $data['price_label'],
        ]);
        if ($result === false) {
            WC_Solid_Subscribe_Logger::debug('Failed to insert product list: ' . $wpdb->last_error);
        }
    }

    public static function update_product_list(array $data)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $wpdb->update($table_name, [
            'uuid' => $data['uuid'],
            'country_name' => $data['country_name'],
            'label' => $data['label'],
            'banner_label' => $data['banner_label'],
            'class' => $data['class'],
            'score' => $data['score'],
            'currency' => $data['currency'],
            'sign_up_fee' => $data['sign_up_fee'],
            'sign_up_fee_label' => $data['sign_up_fee_label'],
            'price' => $data['price'],
            'price_label' => $data['price_label'],
        ], ['product_id' => $data['product_id']]);
    }

    public static function delete_product_list_by_product_id($product_id)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $wpdb->delete($table_name, ['product_id' => $product_id]);
    }

    public static function delete_product_list_by_uuid($uuid)
    {
        global $wpdb;
        $table_name = self::get_product_list_table_name();
        $wpdb->delete($table_name, ['uuid' => $uuid]);
    }
}

/**
 * Клас WC_Solid_Product_List_Builder
 *
 * Використовується для створення списку продуктів із заданими параметрами.
 */
class WC_Solid_Product_List_Builder
{
    /**
     * @var array Список продуктів.
     */
    private $product_list = [];

    /**
     * @var array Обов'язкові поля для продукту.
     */
    private const REQUIRED_FIELDS = [
        'product_id',
        'uuid',
        'country_name',
        'currency',
        'sign_up_fee',
        'price',
    ];

    /**
     * Створює новий елемент продукту у списку.
     *
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function createProductList(): WC_Solid_Product_List_Builder
    {
        $this->product_list[] = [];
        return $this;
    }

    /**
     * Отримує посилання на поточний продукт у списку.
     *
     * @return array& - Посилання на поточний елемент списку продуктів.
     */
    private function &getCurrentProductList(): array
    {
        return $this->product_list[count($this->product_list) - 1];
    }

    /**
     * Встановлює ID продукту для поточного продукту.
     *
     * @param int $product_id ID продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setProductId(int $product_id): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['product_id'] = $product_id;
        return $this;
    }

    /**
     * Встановлює UUID продукту для поточного продукту.
     *
     * @param string $uuid UUID продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setUuid(string $uuid): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['uuid'] = $uuid;
        return $this;
    }

    /**
     * Встановлює назву країни для поточного продукту.
     *
     * @param string $country_name Назва країни.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setCountryName(string $country_name): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['country_name'] = $country_name;
        return $this;
    }

    /**
     * Встановлює мітку продукту.
     *
     * @param string $label Мітка продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setLabel(string $label): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['label'] = $label;
        return $this;
    }

    /**
     * Встановлює мітку банера для продукту.
     *
     * @param string $banner_label Мітка банера.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setBannerLabel(string $banner_label): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['banner_label'] = $banner_label;
        return $this;
    }

    /**
     * Встановлює клас продукту.
     *
     * @param string $class Клас продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setClass(string $class): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['class'] = $class;
        return $this;
    }

    /**
     * Встановлює оцінку продукту.
     *
     * @param float|int $score Оцінка продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setScore($score): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['score'] = $score;
        return $this;
    }

    /**
     * Встановлює валюту для продукту.
     *
     * @param string $currency Валюта.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setCurrency(string $currency): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['currency'] = $currency;
        return $this;
    }

    /**
     * Встановлює вступний внесок для продукту.
     *
     * @param float|int $sign_up_fee Вступний внесок.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setSignUpFee($sign_up_fee): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['sign_up_fee'] = $sign_up_fee;
        return $this;
    }

    /**
     * Встановлює мітку для вступного внеску.
     *
     * @param string $sign_up_fee_label Мітка вступного внеску.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setSignUpFeeLabel(string $sign_up_fee_label): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['sign_up_fee_label'] = $sign_up_fee_label;
        return $this;
    }

    /**
     * Встановлює ціну продукту.
     *
     * @param float|int $price Ціна продукту.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setPrice($price): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['price'] = $price;
        return $this;
    }

    /**
     * Встановлює мітку для ціни продукту.
     *
     * @param string $price_label Мітка ціни.
     * @return $this Повертає поточний екземпляр класу для ланцюгового виклику методів.
     */
    public function setPriceLabel(string $price_label): WC_Solid_Product_List_Builder
    {
        $this->getCurrentProductList()['price_label'] = $price_label;
        return $this;
    }

    /**
     * Перевіряє, чи всі обов'язкові поля встановлені.
     *
     * @param array $product Продукт для перевірки.
     * @throws InvalidArgumentException Якщо обов'язкове поле відсутнє.
     */
    private function validateRequiredFields(array $product)
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($product[$field])) {
                throw new InvalidArgumentException("Field '$field' is required but not set.");
            }
        }
    }

    /**
     * Будує список продуктів, перевіряючи обов'язкові поля.
     *
     * @return array
     */
    public function build(): array
    {
        foreach ($this->product_list as $product) {
            $this->validateRequiredFields($product);
        }
        return $this->product_list;
    }
}
