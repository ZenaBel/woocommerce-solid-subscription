<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Выход, если доступ осуществляется напрямую
}

class WC_Solid_Subscribe_Webhook_Handler {
    public function __construct() {
        $this->settings = get_option( 'woocommerce_solid_settings', [] ); // Получение настроек Solid для WooCommerce
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
        foreach ($notification->invoices as $invoice) {
            if (isset($invoice->orders)) {
                foreach ($invoice->orders as $order) {
                    // 104_1724147277 повернути 104
                    $order_id = explode('_', $order->id)[0];
                    break 2;
                }
            }
        }

        $subscription_id = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(init) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(init) ID подписки: ' . $subscription_id );

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        $order->update_meta_data( '_solid_subscription_id', $subscription_id );

        if ( $notification->subscription->status === 'active' ) {
            $order->update_status( 'processing', __( 'Подписка активирована', 'wc-solid' ) );
        } else {
            $order->update_status( 'cancelled', __( 'Подписка отменена', 'wc-solid' ) );
        }
    }

    public function process_cancel_subscription( $notification ) {
        WC_Solid_Subscribe_Logger::debug( 'Получено уведомление о подписке: ' . json_encode( $notification ) );
        $order_id = null;
        foreach ($notification->invoices as $invoice) {
            if (isset($invoice->orders)) {
                foreach ($invoice->orders as $order) {
                    // 104_1724147277 повернути 104
                    $order_id = explode('_', $order->id)[0];
                    break 2;
                }
            }
        }

        $subscription_id = $notification->subscription->id ?? null;

        WC_Solid_Subscribe_Logger::debug( '(cancel) ID заказа: ' . $order_id );
        WC_Solid_Subscribe_Logger::debug( '(cancel) ID подписки: ' . $subscription_id );

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            WC_Solid_Subscribe_Logger::alert( 'Не удалось найти заказ по ID заказа: ' . $order_id );
            return;
        }

        $order->update_status( 'cancelled', __( 'Подписка отменена', 'wc-solid' ) );
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
                        $this->process_solidgate_subscription( $notification );
                        break;
                    case 'cancel':
                        $this->process_cancel_subscription( $notification );
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
}
