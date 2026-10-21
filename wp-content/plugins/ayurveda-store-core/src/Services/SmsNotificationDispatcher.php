<?php

namespace Ashtanga\Store\Core\Services;

use Ashtanga\Store\Core\Config\Settings;
use Ashtanga\Store\Core\Contracts\SmsProviderInterface;
use Ashtanga\Store\Core\Providers\ProviderRegistry;
use Ashtanga\Store\Core\Support\AtomicLock;

defined( 'ABSPATH' ) || exit;

final class SmsNotificationDispatcher {
	const ACTION = 'ashtanga_store_core_send_sms_notification';

	private $providers;

	public function __construct( ProviderRegistry $providers ) {
		$this->providers = $providers;
	}

	public function register() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_processing' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_completed' ), 10, 2 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'queue_on_hold' ), 10, 2 );
		add_action( self::ACTION, array( $this, 'send' ), 10, 2 );
	}

	public function queue_processing( $order_id, $order = null ) {
		$this->queue( absint( $order_id ), 'order_processing' );
	}

	public function queue_completed( $order_id, $order = null ) {
		$this->queue( absint( $order_id ), 'order_completed' );
	}

	public function queue_on_hold( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( $order && 'cod' === $order->get_payment_method() ) {
			$this->queue( absint( $order_id ), 'cod_on_hold' );
		}
	}

	public function send( $order_id, $event ) {
		$order_id = absint( $order_id );
		$event = sanitize_key( (string) $event );

		if ( ! in_array( $event, Settings::SMS_EVENTS, true ) ) {
			return;
		}

		AtomicLock::run(
			'sms-send:' . $order_id . ':' . $event,
			function () use ( $order_id, $event ) {
				$order = wc_get_order( $order_id );
				$status_key = $this->status_meta_key( $event );

				if ( ! $order || 'queued' !== $order->get_meta( $status_key, true ) ) {
					return false;
				}

				$template_id = Settings::sms_template_id( $event );
				$provider = $this->providers->get_configured( SmsProviderInterface::class, 'sms' );

				if ( '' === $template_id || is_wp_error( $provider ) ) {
					$this->record_failure( $order, $event, $status_key, 'provider_not_configured' );
					return false;
				}

				$order->update_meta_data( $status_key, 'sending' );
				$order->save();

				$variables = apply_filters(
					'ashtanga_store_core_sms_variables',
					array(
						(string) $order->get_order_number(),
					(string) $order->get_billing_first_name(),
					),
					$order,
					$event
				);

				if ( ! is_array( $variables ) ) {
					$this->record_failure( $order, $event, $status_key, 'invalid_template_variables' );
					return false;
				}

				$result = $provider->send_template( (string) $order->get_billing_phone(), $template_id, $variables );

				if ( is_wp_error( $result ) ) {
					$this->record_failure( $order, $event, $status_key, $result->get_error_code() );
					return false;
				}

				$order->update_meta_data( $status_key, 'sent' );
				$order->save();

				return true;
			}
		);
	}

	private function queue( int $order_id, string $event ) {
		if ( '' === Settings::sms_template_id( $event ) ) {
			return;
		}

		AtomicLock::run(
			'sms-queue:' . $order_id . ':' . $event,
			function () use ( $order_id, $event ) {
				$order = wc_get_order( $order_id );
				$status_key = $this->status_meta_key( $event );

				if ( ! $order || '' !== (string) $order->get_meta( $status_key, true ) ) {
					return false;
				}

				$order->update_meta_data( $status_key, 'queued' );
				$order->save();

				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$action_id = as_enqueue_async_action( self::ACTION, array( $order_id, $event ), 'ashtanga-store-core', true );
					$scheduled = $action_id || ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ACTION, array( $order_id, $event ), 'ashtanga-store-core' ) );
				} else {
					$scheduled = wp_schedule_single_event( time() + 5, self::ACTION, array( $order_id, $event ) );
				}

				if ( ! $scheduled ) {
					$this->record_failure( $order, $event, $status_key, 'notification_queue_failed' );
					return false;
				}

				return true;
			}
		);
	}

	private function record_failure( \WC_Order $order, string $event, string $status_key, string $error_code ) {
		$order->update_meta_data( $status_key, 'failed' );
		$order->save();

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'SMS notification failed; no automatic retry was attempted.',
				array(
					'source'    => 'ayurveda-store-core',
					'order_id'  => $order->get_id(),
					'event'     => $event,
					'error_code' => sanitize_key( $error_code ),
				)
			);
		}
	}

	private function status_meta_key( string $event ): string {
		return '_ashtanga_store_sms_' . sanitize_key( $event );
	}
}