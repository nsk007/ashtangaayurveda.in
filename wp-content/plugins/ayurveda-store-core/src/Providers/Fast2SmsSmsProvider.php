<?php

namespace Ashtanga\Store\Core\Providers;

use Ashtanga\Store\Core\Contracts\SmsProviderInterface;

defined( 'ABSPATH' ) || exit;

final class Fast2SmsSmsProvider implements SmsProviderInterface {
	const API_URL = 'https://www.fast2sms.com/dev/bulkV2';

	public function send_template( string $recipient, string $template_id, array $variables ) {
		$phone = $this->normalize_phone( $recipient );
		$template_id = trim( $template_id );

		if ( '' === $phone || ! ctype_digit( $template_id ) ) {
			return new \WP_Error( 'sms_invalid_request', __( 'The SMS notification could not be prepared.', 'ayurveda-store-core' ) );
		}

		if ( ! defined( 'ASHTANGA_FAST2SMS_API_KEY' ) || '' === ASHTANGA_FAST2SMS_API_KEY ||
			! defined( 'ASHTANGA_FAST2SMS_SENDER_ID' ) || '' === ASHTANGA_FAST2SMS_SENDER_ID ) {
			return new \WP_Error( 'sms_not_configured', __( 'SMS notifications are not configured.', 'ayurveda-store-core' ) );
		}

		$values = array();

		foreach ( $variables as $value ) {
			if ( ! is_scalar( $value ) ) {
				return new \WP_Error( 'sms_invalid_variable', __( 'The SMS notification could not be prepared.', 'ayurveda-store-core' ) );
			}

			$value = sanitize_text_field( (string) $value );
			$value = trim( str_replace( array( '|', "\r", "\n", "\t" ), ' ', $value ) );
			$values[] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 30 ) : substr( $value, 0, 30 );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout'     => 12,
				'redirects'   => 0,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => ASHTANGA_FAST2SMS_API_KEY,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'sender_id'        => ASHTANGA_FAST2SMS_SENDER_ID,
						'message'          => (int) $template_id,
						'variables_values' => implode( '|', $values ),
						'route'            => 'dlt',
						'numbers'          => $phone,
					)
				),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'sms_provider_unavailable', __( 'The notification provider is temporarily unavailable.', 'ayurveda-store-core' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['return'] ) ) {
			return new \WP_Error( 'sms_send_failed', __( 'The notification could not be sent.', 'ayurveda-store-core' ) );
		}

		return true;
	}

	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );

		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 11 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		return preg_match( '/^[6-9][0-9]{9}$/', $digits ) ? $digits : '';
	}
}