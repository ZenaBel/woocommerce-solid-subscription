<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Solid_Subscribe_Logger {

	public static $logger;
	const WC_LOG_FILENAME = 'woocommerce-gateway-solid-subscribe';

	public static function get_message( $message, $start_time = null, $end_time = null ) {
		if ( ! is_null( $start_time ) ) {

			$formatted_start_time = date_i18n( get_option( 'date_format' ) . ' g:ia', $start_time );
			$end_time             = is_null( $end_time ) ? current_time( 'timestamp' ) : $end_time;
			$formatted_end_time   = date_i18n( get_option( 'date_format' ) . ' g:ia', $end_time );
			$elapsed_time         = round( abs( $end_time - $start_time ) / 60, 2 );

			$log_entry  = "\n" . '====solid Version: ' . WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_VERSION . '====' . "\n";
			$log_entry .= '====Start Log ' . $formatted_start_time . '====' . "\n" . $message . "\n";
			$log_entry .= '====End Log ' . $formatted_end_time . ' (' . $elapsed_time . ')====' . "\n\n";

		} else {
			$log_entry  = "\n" . '====solid Version: ' . WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_VERSION . '====' . "\n";
			$log_entry .= '====Start Log====' . "\n" . $message . "\n" . '====End Log====' . "\n\n";

		}

		return $log_entry;
	}

	public static function debug( $message, $start_time = null, $end_time = null ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'wc_solid_logging', true, $message ) ) {

			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			$settings = get_option( 'woocommerce_solid_subscribe_settings' );

			if ( empty( $settings ) || isset( $settings['logging'] ) && 'yes' !== $settings['logging'] ) {
				return;
			}
			$msg = self::get_message($message, $start_time, $end_time);
			self::$logger->debug( $msg, [ 'source' => self::WC_LOG_FILENAME ] );
		}
	}

	public static function alert( $message, $start_time = null, $end_time = null ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'wc_solid_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}
			self::$logger->alert( $message, [ 'source' => self::WC_LOG_FILENAME ] );
		}
	}
}
