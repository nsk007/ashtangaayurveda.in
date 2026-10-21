<?php

namespace Ashtanga\Store\Core\Providers;

use Ashtanga\Store\Core\Contracts\OtpProviderInterface;
use Ashtanga\Store\Core\Config\Settings;
use Ashtanga\Store\Core\ValueObjects\OtpChallenge;

defined( 'ABSPATH' ) || exit;

final class Fast2SmsOtpProvider implements OtpProviderInterface {
	const API_URL = 'https://www.fast2sms.com/dev/otp';

	public function request_code( string $identifier, string $purpose ) {
		$phone = $this->normalize_phone( $identifier );

		if ( '' === $phone ) {
			return new \WP_Error( 'invalid_phone', __( 'Enter a valid Indian mobile number.', 'ayurveda-store-core' ) );
		}

		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'otp_not_configured', __( 'Phone verification is not configured.', 'ayurveda-store-core' ) );
		}

		$response = $this->post(
			'/send',
			array(
				'mobile'     => $phone,
				'otp_id'     => ASHTANGA_FAST2SMS_OTP_ID,
				'otp_expiry' => (int) ceil( Settings::security( 'otp_expiry_seconds' ) / 60 ),
				'otp_length' => 6,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		try {
			$challenge_id = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'otp_random_failure', __( 'Phone verification is temporarily unavailable.', 'ayurveda-store-core' ) );
		}

		return new OtpChallenge( $challenge_id, time() + Settings::security( 'otp_expiry_seconds' ), $phone, sanitize_key( $purpose ) );
	}

	public function resend_code( OtpChallenge $challenge ) {
		if ( $challenge->get_expires_at() <= time() || ! $this->has_credentials() ) {
			return new \WP_Error( 'otp_challenge_expired', __( 'This verification request has expired. Please request a new code.', 'ayurveda-store-core' ) );
		}

		return $this->post(
			'/resend',
			array( 'mobile' => $this->normalize_phone( $challenge->get_identifier() ) )
		);
	}

	public function verify_code( OtpChallenge $challenge, string $code ) {
		$phone = $this->normalize_phone( $challenge->get_identifier() );

		if ( $challenge->get_expires_at() <= time() || '' === $phone || ! preg_match( '/^[0-9]{4,10}$/', $code ) ) {
			return new \WP_Error( 'otp_invalid', __( 'The code is invalid or has expired.', 'ayurveda-store-core' ) );
		}

		if ( ! $this->has_credentials() ) {
			return new \WP_Error( 'otp_not_configured', __( 'Phone verification is not configured.', 'ayurveda-store-core' ) );
		}

		$response = $this->post(
			'/verify',
			array(
				'mobile' => $phone,
				'otp'    => $code,
			)
		);

		return is_wp_error( $response ) ? $response : true;
	}

	private function post( string $endpoint, array $body ) {
		$response = wp_remote_post(
			self::API_URL . $endpoint,
			array(
				'timeout'     => 12,
				'redirects'   => 0,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => ASHTANGA_FAST2SMS_API_KEY,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'otp_provider_unavailable', __( 'Phone verification is temporarily unavailable. Please try again.', 'ayurveda-store-core' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['return'] ) ) {
			return new \WP_Error( 'otp_provider_rejected', __( 'Phone verification could not be completed. Please try again.', 'ayurveda-store-core' ) );
		}

		return true;
	}

	private function has_credentials(): bool {
		return defined( 'ASHTANGA_FAST2SMS_API_KEY' ) && '' !== ASHTANGA_FAST2SMS_API_KEY &&
			defined( 'ASHTANGA_FAST2SMS_OTP_ID' ) && '' !== ASHTANGA_FAST2SMS_OTP_ID;
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