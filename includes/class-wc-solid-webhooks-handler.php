<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Выход, если доступ осуществляется напрямую
}

class WC_Solid_Subscribe_Webhook_Handler {
    protected $settings;
    public function __construct() {
        $this->settings = get_option( 'woocommerce_solid_subscribe_settings', [] ); // Получение настроек Solid для WooCommerce
    }

    /**
     * Получает заказ по ID транзакции.
     *
     * @since 4.0.0
     * @since 4.1.16 Возвращает false, если ID транзакции пуст.
     * @param string $transaction_id
     */
    public static function get_order_by_charge_id( $transaction_id ) {
        global $wpdb;

        if ( empty( $transaction_id ) ) {
            WC_Solid_Subscribe_Logger::alert( 'Попытка запроса заказа с пустым uniq_order_id');
            return false;
        }

        // Запрос для получения ID заказа по метаданным с уникальным идентификатором заказа
        $order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $transaction_id, '_uniq_order_id' ) );

        if ( ! empty( $order_id ) ) {
            return wc_get_order( $order_id ); // Возвращаем объект заказа, если найден
        }

        return false; // Возвращаем false, если заказ не найден
    }

    /**
     * Получает заголовки входящего запроса. Некоторые серверы не используют Apache,
     * и "getallheaders()" не будет работать, поэтому может потребоваться создание собственных заголовков.
     *
     * @since 4.0.0
     * @version 4.0.0
     */
    public function get_request_headers() {
        if ( ! function_exists( 'getallheaders' ) ) {
            $headers = [];

            // Перебираем серверные переменные для формирования заголовков запроса
            foreach ( $_SERVER as $name => $value ) {
                if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
                    $headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
                }
            }

            return $headers; // Возвращаем созданные заголовки
        } else {
            return getallheaders(); // Используем встроенную функцию, если она доступна
        }
    }

    // Обрабатываем авторизацию по webhook уведомлению
    public function process_webhook_auth( $notification ) {
        $order = $this->get_order_by_charge_id( $notification->order->order_id ); // Получаем заказ по ID

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $notification->order->order_id );
            return;
        }

        $order->set_transaction_id( $notification->transaction->id ); // Устанавливаем ID транзакции для заказа
        $order->add_order_note( sprintf( __( 'Solid auth OK (ID транзакции: %s)', 'wc-solid' ), $notification->transaction->id ) ); // Добавляем заметку к заказу
    }

    // Обрабатываем успешное подтверждение платежа по webhook уведомлению
    public function process_webhook_charge_approved( $notification ) {
        $order = $this->get_order_by_charge_id( $notification->order->order_id ); // Получаем заказ по ID

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $notification->order->order_id );
            return;
        }

        // Сохраняем другие данные, такие как сборы
        $order->set_transaction_id( $notification->transaction->id ); // Устанавливаем ID транзакции для заказа

        $order->payment_complete( $notification->transaction->id ); // Отмечаем заказ как завершенный

        $method = isset($notification->order->method) ? $notification->order->method : 'default'; // Получаем метод оплаты

        // Добавляем заметку о завершении платежа
        $order->add_order_note( sprintf( __( 'Платеж завершен (ID транзакции: %1$s) (Метод: %2$s)', 'wc-solid' ), $notification->transaction->id, $method ) );

        if ( is_callable( [ $order, 'save' ] ) ) {
            $order->save(); // Сохраняем изменения заказа
        }
    }

    // Обрабатываем отклоненный платеж по webhook уведомлению
    public function process_webhook_charge_declined( $notification ) {
        $order = $this->get_order_by_charge_id( $notification->order->order_id ); // Получаем заказ по ID

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $notification->order->order_id );
            return;
        }

        // Если заказ уже имеет статус "failed" или "cancelled", прекращаем выполнение
        if ( $order->has_status([ 'failed', 'cancelled' ] ) ) {
            return;
        }
        $message = __( 'Этот платеж не был успешным.', 'wc-solid' );
        $order->update_status( 'cancelled', $message ); // Обновляем статус заказа на "отменен"
    }

    // Обрабатываем возврат платежа по webhook уведомлению
    public function process_webhook_charge_refunded( $notification ) {
        $order = $this->get_order_by_charge_id( $notification->order->order_id ); // Получаем заказ по ID

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $notification->order->order_id );
            return;
        }

        // Если заказ уже имеет статус "failed", "cancelled" или "refunded", прекращаем выполнение
        if ( $order->has_status([ 'failed', 'cancelled', 'refunded' ] ) ) {
            return;
        }

        $order_id = $order->get_id(); // Получаем ID заказа
        $currency = $order->get_currency(); // Получаем валюту заказа
        $raw_amount = $notification->transaction->amount /= 100; // Конвертируем сумму транзакции

        $amount = wc_price( $raw_amount, [ 'currency' => $currency ] ); // Форматируем сумму для вывода

        $reason = __( 'Возврат через Solid Dashboard', 'wc-solid' ); // Причина возврата

        // Создаем возврат
        $refund = wc_create_refund(
            [
                'order_id' => $order_id,
                'amount'   => $raw_amount,
                'reason'   => $reason,
            ]
        );

        if ( is_wp_error( $refund ) ) {
            WC_Solid_Subscribe_Logger::alert( 'Возврат не удался: ', $refund->get_error_message()); // Логируем ошибку, если возврат не удался
        } else {
            $order->add_order_note( sprintf( __( 'Заказ был успешно возвращен (Сумма: %1$s; ID транзакции: %2$s - %3$s).', 'wc-solid' ), $amount, $notification->transaction->id, $reason ) ); // Добавляем заметку о возврате
        }

    }

    public function process_solidgate_subscription($notification)
    {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о подписке: ' . json_encode( $notification ) );
        $order_id = null;
        $order_uuid = null;
        foreach ($notification->invoices as $invoice) {
            if (isset($invoice->orders)) {
                foreach ($invoice->orders as $order) {
                    if (strpos($order->id, '_') !== false) {
                        $order_id = explode('_', $order->id)[0];
                        $order_uuid = $order->id;
                        break 2;
                    }
                }
            }
        }

        $subscription_uuid = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(init) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(init) UUID заказа: ' . $order_uuid );
        WC_Solid_Subscribe_Logger::debug( '(init) UUID подписки: ' . $subscription_uuid );

        if ( ! $order_id ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        if ( ! $subscription_uuid ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписку по UUID подписки: ' . $subscription_uuid );
            return;
        }

        $order = wc_get_order( $order_id );

        $subscriptions = wcs_get_subscriptions_for_order($order);

        if ( ! $subscriptions ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписки по ID заказа: ' . $order_id );
            return;
        }

        foreach ($subscriptions as $subscription) {
            WC_Solid_Subscribe_Model::create_subscription_mapping($subscription->get_id(), $subscription_uuid);
        }

        WC_Solid_Subscribe_Logger::debug( '(init) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(init) UUID подписки: ' . $subscription_uuid );

        // TOKEN
        $data = [
            'order_id' => $order_uuid,
        ];

        $response = WC_Solid_Gateway_Subscribe::get_instance()->api->checkOrderStatus($data);

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( $response, true );

            // Перевіряємо наявність токена перед створенням
            if (!empty($body['order']['token'])) {
                $token = new WC_Payment_Token_Solid();
                $token->set_gateway_id( WC_Solid_Gateway_Subscribe::get_instance()->id );
                $token->set_token( $body['order']['token'] );
                if (isset($body['order']['subscription_id'])) {
                    $token->set_subscription_id( $body['order']['subscription_id'] );
                }
                $token->save();
                WC_Solid_Subscribe_Logger::debug('Токен успішно збережено');
            } else {
                WC_Solid_Subscribe_Logger::alert('Токен не знайдено у відповіді API');
            }
        } else {
            WC_Solid_Subscribe_Logger::alert('Помилка при отриманні статусу замовлення: ' . $response);
        }

        if ( $notification->subscription->status === 'active' ) {
            $order->update_status( 'processing', __( 'Подписка активирована', 'wc-solid' ) );
            WC_Subscriptions_Manager::activate_subscriptions_for_order( $order );
        } else {
            $order->update_status( 'cancelled', __( 'Подписка отменена', 'wc-solid' ) );
            WC_Subscriptions_Manager::cancel_subscriptions_for_order( $order );
        }
    }

    public function process_cancel_subscription( $notification ) {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о подписке: ' . json_encode( $notification ) );

        $subscription_uuid = $notification->subscription->id ?? null;

        $subscription_id = $this->get_subscription_id($subscription_uuid);

        if ( ! $subscription_id ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписку по UUID подписки: ' . $subscription_uuid );
            return;
        }

        WC_Solid_Subscribe_Logger::debug( '(cancel) ID подписки: ' . $subscription_id );
        WC_Solid_Subscribe_Logger::debug( '(cancel) UUID подписки: ' . $subscription_uuid );

        $subscription = wcs_get_subscription($subscription_id);

        if ( ! $subscription ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписку по ID подписки: ' . $subscription_id );
            return;
        }

        $order = $subscription->get_parent();

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $subscription_id );
            return;
        }

        $order->update_status( 'cancelled', __( 'Subscription cancelled', 'wc-solid' ) );
        $subscription->update_status( 'cancelled', __( 'Subscription cancelled', 'wc-solid' ) );
    }

    public function process_renew_subscription( $notification ) {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о подписке: ' . json_encode( $notification ) );

        $subscription_uuid = $notification->subscription->id ?? null;

        $subscription_id = $this->get_subscription_id($subscription_uuid);

        if ( ! $subscription_id ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписку по UUID подписки: ' . $subscription_uuid );
            return;
        }

        WC_Solid_Subscribe_Logger::debug( '(renew) ID подписки: ' . $subscription_id );
        WC_Solid_Subscribe_Logger::debug( '(renew) UUID подписки: ' . $subscription_uuid );

        $subscription = wcs_get_subscription($subscription_id);

        if ( ! $subscription ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти подписку по ID подписки: ' . $subscription_id );
            return;
        }

        if (!in_array($subscription->get_status(), ['cancelled', 'expired'])) {
            WC_Solid_Subscribe_Logger::alert( 'Подписка не отменена или не истекла: ' . $subscription_id );
            return;
        }

        $order = $subscription->get_parent();

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $subscription_id );
            return;
        }

        WC_Solid_Gateway_Subscribe::get_instance()->renew_subscription( $subscription_id, $subscription_uuid );

        $order->update_status( 'processing', __( 'Subscription extended', 'wc-solid' ) );
        $order->update_status( 'completed', __( 'Subscription extended', 'wc-solid' ) );
        WC_Subscriptions_Manager::activate_subscriptions_for_order( $order );
    }

    public function process_expire_subscription( $notification ) {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о подписке: ' . json_encode( $notification ) );
        $order_id = $this->get_subscription_id($notification);

        $subscription_id = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(expire) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(expire) ID подписки: ' . $subscription_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        $order->update_status( 'cancelled', __( 'Подписка истекла', 'wc-solid' ) );
        WC_Subscriptions_Manager::expire_subscriptions_for_order( $order_id );
    }

    public function process_pause_schedule_create($notification)
    {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о паузе подписки: ' . json_encode( $notification ) );
        $order_id = $this->get_subscription_id($notification);

        WC_Solid_Subscribe_Logger::debug( '(pause_schedule.create) ID заказа: ' . $order_id );

        $subscription_id = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(pause_schedule.create) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(pause_schedule.create) ID подписки: ' . $subscription_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order);

        foreach ($subscriptions as $subscription) {
            if ($subscription->has_status(['active', 'on-hold'])) {
                $start_point = get_post_meta($subscription->get_id(), '_pause_start_point', true);
                $stop_point = get_post_meta($subscription->get_id(), '_pause_stop_point', true);
                if ($start_point && $stop_point) {
                    update_post_meta($subscription->get_id(), '_pause_start_point', date($notification->subscription->pause->from_date, 'Y-m-d'));
                    update_post_meta($subscription->get_id(), '_pause_stop_point', date($notification->subscription->pause->to_date, 'Y-m-d'));
                } else {
                    add_post_meta($subscription->get_id(), '_pause_start_point', date($notification->subscription->pause->from_date, 'Y-m-d'));
                    add_post_meta($subscription->get_id(), '_pause_stop_point', date($notification->subscription->pause->to_date, 'Y-m-d'));
                }
                $subscription->update_status('on-hold', __('Subscription paused via Solid Dashboard', 'wc-solid'));
                $subscription->set_next_payment_date($notification->subscription->pause->to_date);
            }
        }
    }

    public function process_pause_schedule_delete($notification)
    {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о паузе подписки: ' . json_encode( $notification ) );
        $order_id = $this->get_subscription_id($notification);

        $subscription_id = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(pause_schedule.delete) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(pause_schedule.delete) ID подписки: ' . $subscription_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order);

        foreach ($subscriptions as $subscription) {
            if ($subscription->has_status(['on-hold'])) {
                $start_point = get_post_meta($subscription->get_id(), '_pause_start_point', true);
                $stop_point = get_post_meta($subscription->get_id(), '_pause_stop_point', true);
                if ($start_point && $stop_point) {
                    delete_post_meta($subscription->get_id(), '_pause_start_point');
                    delete_post_meta($subscription->get_id(), '_pause_stop_point');
                }
                $subscription->update_status('active', __('Subscription unpaused via Solid Dashboard', 'wc-solid'));
                $subscription->set_next_payment_date($notification->subscription->next_charge_at);
            }
        }
    }

    /**
     * Обрабатывает входящий webhook.
     *
     * @since 4.0.0
     * @version 4.0.0
     * @param string $request_body
     */
    public function process_webhook($type, $request_body ) {
        $notification = json_decode( $request_body ); // Декодируем тело запроса
        switch ( $type ) {
            case 'alt_order.updated':
            case 'order.updated':
                // Обрабатываем различные статусы заказа
                switch ($notification->order->status) {
                    case 'auth_ok':
                        $this->process_webhook_auth( $notification ); // Обработка авторизации
                        break;
                    case 'settle_ok':
                    case 'approved':
                        $this->process_webhook_charge_approved( $notification ); // Обработка подтверждения платежа
                        break;
                    case 'declined':
                        $this->process_webhook_charge_declined( $notification ); // Обработка отклоненного платежа
                        break;
                    case 'refunded':
                        $this->process_webhook_charge_refunded( $notification ); // Обработка возврата платежа
                        break;

                    default:
                        WC_Solid_Subscribe_Logger::debug( sprintf( 'Необработанный hook: %1$s -> %2$s', $type, $notification->order->status ) );
                        break;
                }
                break;
            case 'subscribe.updated':
                switch ($notification->callback_type) {
                    case 'init':
                    case 'active':
                        $this->process_solidgate_subscription( $notification );
                        break;
                    case 'cancel':
                        $this->process_cancel_subscription( $notification );
                        break;
                    case 'renew':
                    case 'restore':
                        $this->process_renew_subscription( $notification );
                        break;
                    case 'expire':
                        $this->process_expire_subscription( $notification );
                        break;
                    case 'pause_schedule.create':
                    case 'pause_schedule.update':
                        $this->process_pause_schedule_create( $notification );
                        break;
                    case 'pause_schedule.delete':
                        $this->process_pause_schedule_delete( $notification );
                        break;
                    default:
                        WC_Solid_Subscribe_Logger::debug( sprintf( 'Необработанный hook: %1$s -> %2$s', $type, $notification->callback_type ) );
                        break;
                }
                break;
            default:
                WC_Solid_Subscribe_Logger::debug( sprintf( 'Необработанный hook: %1$s',$type ) );
                break;
        }
    }

    /**
     * Повертає ID підписки по UUID підписки
     *
     * @param $subscription_uuid - UUID підписки
     * @return mixed|null
     */
    private function get_subscription_id($subscription_uuid)
    {
        $mapping = WC_Solid_Subscribe_Model::get_subscription_mapping_by_uuid($subscription_uuid);
        return $mapping->subscription_id ?? null;
    }
}
