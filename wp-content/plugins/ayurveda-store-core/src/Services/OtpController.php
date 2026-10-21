<?php

namespace Ashtanga\Store\Core\Services;

use Ashtanga\Store\Core\Contracts\OtpProviderInterface;
use Ashtanga\Store\Core\Config\Settings;
use Ashtanga\Store\Core\Providers\ProviderRegistry;
use Ashtanga\Store\Core\Support\RateLimiter;
use Ashtanga\Store\Core\ValueObjects\OtpChallenge;

defined( 'ABSPATH' ) || exit;

final class OtpController {
	private $providers;
	private $rate_limiter;
	private $authenticator;

	public function __construct( ProviderRegistry $providers, RateLimiter $rate_limiter, CustomerAuthenticator $authenticator ) {
		$this->providers = $providers;
		$this->rate_limiter = $rate_limiter;
		$this->authenticator = $authenticator;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'ashtanga-store/v1',
			'/otp/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_code' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ashtanga-store/v1',
			'/otp/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend_code' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ashtanga-store/v1',
			'/otp/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_code' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function request_code( \WP_REST_Request $request ) {
		$nonce_error = $this->validate_nonce( $request );

		if ( is_wp_error( $nonce_error ) ) {
			return $nonce_error;
		}

		$phone = $this->normalize_phone( $this->request_string( $request, 'phone' ) );

		if ( '' === $phone ) {
			return $this->error( 'invalid_phone', __( 'Enter a valid 10-digit Indian mobile number.', 'ayurveda-store-core' ), 400 );
		}

		if ( ! $this->allow_send( $phone ) ) {
			return $this->error( 'otp_rate_limited', __( 'Please wait a few minutes before trying again.', 'ayurveda-store-core' ), 429 );
		}

		$provider = $this->providers->get_configured( OtpProviderInterface::class, 'otp' );

		if ( is_wp_error( $provider ) ) {
			return $this->error( 'otp_unavailable', __( 'Phone verification is temporarily unavailable. Please contact customer care.', 'ayurveda-store-core' ), 503 );
		}

		$challenge = $provider->request_code( $phone, 'customer_access' );

		if ( is_wp_error( $challenge ) ) {
			return $this->error( 'otp_delivery_failed', __( 'We could not send a code right now. Please try again later.', 'ayurveda-store-core' ), 503 );
		}

		$record = array(
			'phone_hash' => $this->phone_hash( $phone ),
			'expires_at' => min( $challenge->get_expires_at(), time() + Settings::security( 'otp_expiry_seconds' ) ),
			'purpose'    => 'customer_access',
		);

		if ( ! set_transient( $this->challenge_key( $challenge->get_id() ), $record, Settings::security( 'otp_expiry_seconds' ) ) ) {
			return $this->error( 'otp_session_failed', __( 'We could not start verification. Please request a new code.', 'ayurveda-store-core' ), 503 );
		}

		return rest_ensure_response(
			array(
				'challenge' => $challenge->get_id(),
				'masked'    => $this->mask_phone( $phone ),
				'expires'   => $record['expires_at'],
			)
		);
	}

	public function resend_code( \WP_REST_Request $request ) {
		$nonce_error = $this->validate_nonce( $request );

		if ( is_wp_error( $nonce_error ) ) {
			return $nonce_error;
		}

		$challenge_id = $this->validate_challenge_id( $this->request_string( $request, 'challenge' ) );
		$phone = $this->normalize_phone( $this->request_string( $request, 'phone' ) );
		$record = $challenge_id ? get_transient( $this->challenge_key( $challenge_id ) ) : false;

		if ( ! $challenge_id || '' === $phone || ! $this->matches_challenge( $record, $phone ) ) {
			return $this->error( 'otp_invalid', __( 'This verification request has expired. Please request a new code.', 'ayurveda-store-core' ), 400 );
		}

		if ( ! $this->allow_send( $phone ) ) {
			return $this->error( 'otp_rate_limited', __( 'Please wait a few minutes before trying again.', 'ayurveda-store-core' ), 429 );
		}

		$provider = $this->providers->get_configured( OtpProviderInterface::class, 'otp' );

		if ( is_wp_error( $provider ) ) {
			return $this->error( 'otp_unavailable', __( 'Phone verification is temporarily unavailable. Please contact customer care.', 'ayurveda-store-core' ), 503 );
		}

		$challenge = new OtpChallenge( $challenge_id, absint( $record['expires_at'] ), $phone, $record['purpose'] );
		$result = $provider->resend_code( $challenge );

		if ( is_wp_error( $result ) ) {
			return $this->error( 'otp_delivery_failed', __( 'We could not resend a code right now. Please try again later.', 'ayurveda-store-core' ), 503 );
		}

		return rest_ensure_response( array( 'masked' => $this->mask_phone( $phone ) ) );
	}

	public function verify_code( \WP_REST_Request $request ) {
		$nonce_error = $this->validate_nonce( $request );

		if ( is_wp_error( $nonce_error ) ) {
			return $nonce_error;
		}

		$challenge_id = $this->validate_challenge_id( $this->request_string( $request, 'challenge' ) );
		$phone = $this->normalize_phone( $this->request_string( $request, 'phone' ) );
		$code = preg_replace( '/\D+/', '', $this->request_string( $request, 'code' ) );
		$record = $challenge_id ? get_transient( $this->challenge_key( $challenge_id ) ) : false;

		if ( ! $challenge_id || '' === $phone || ! preg_match( '/^[0-9]{4,10}$/', $code ) || ! $this->matches_challenge( $record, $phone ) ) {
			return $this->error( 'otp_invalid', __( 'The code is invalid or has expired. Please request a new code.', 'ayurveda-store-core' ), 400 );
		}

		if ( ! $this->rate_limiter->allow( 'otp-verify-challenge', $challenge_id, Settings::security( 'otp_verify_attempt_limit' ), Settings::security( 'otp_expiry_seconds' ) ) ||
			! $this->rate_limiter->allow( 'otp-verify-ip', $this->rate_limiter->client_ip(), Settings::security( 'otp_verify_ip_limit' ), 600 ) ) {
			delete_transient( $this->challenge_key( $challenge_id ) );
			return $this->error( 'otp_attempts_exceeded', __( 'Too many attempts. Please request a new code.', 'ayurveda-store-core' ), 429 );
		}

		$provider = $this->providers->get_configured( OtpProviderInterface::class, 'otp' );

		if ( is_wp_error( $provider ) ) {
			return $this->error( 'otp_unavailable', __( 'Phone verification is temporarily unavailable. Please contact customer care.', 'ayurveda-store-core' ), 503 );
		}

		$challenge = new OtpChallenge( $challenge_id, absint( $record['expires_at'] ), $phone, $record['purpose'] );
		$verified = $provider->verify_code( $challenge, $code );

		if ( is_wp_error( $verified ) ) {
			return $this->error( 'otp_invalid', __( 'The code is invalid or has expired. Please try again.', 'ayurveda-store-core' ), 400 );
		}

		delete_transient( $this->challenge_key( $challenge_id ) );

		$user_id = $this->authenticator->authenticate( $phone );

		if ( is_wp_error( $user_id ) ) {
			return $this->error( 'account_recovery_required', $user_id->get_error_message(), 409 );
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => __( 'Your number is verified. Complete your delivery details to continue.', 'ayurveda-store-core' ),
				'redirect' => wc_get_page_permalink( 'myaccount' ),
			)
		);
	}

	private function allow_send( string $phone ): bool {
		return $this->rate_limiter->allow( 'otp-send-phone', $phone, Settings::security( 'otp_send_phone_limit' ), 600 ) &&
			$this->rate_limiter->allow( 'otp-send-ip', $this->rate_limiter->client_ip(), Settings::security( 'otp_send_ip_limit' ), 600 ) &&
			$this->rate_limiter->allow( 'otp-send-site', 'global', Settings::security( 'otp_send_site_hour_limit' ), 3600 ) &&
			$this->rate_limiter->cooldown( 'otp-send-phone', $phone, Settings::security( 'otp_resend_cooldown' ) );
	}

	private function validate_nonce( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-Ashtanga-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'ashtanga_store_otp' ) ) {
			return $this->error( 'invalid_nonce', __( 'Refresh the page and try again.', 'ayurveda-store-core' ), 403 );
		}

		return true;
	}

	private function request_string( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	private function validate_challenge_id( string $challenge_id ): string {
		return preg_match( '/^[a-f0-9]{64}$/', $challenge_id ) ? $challenge_id : '';
	}

	private function challenge_key( string $challenge_id ): string {
		return 'ashtanga_store_challenge_' . hash( 'sha256', $challenge_id );
	}

	private function matches_challenge( $record, string $phone ): bool {
		return is_array( $record ) && isset( $record['phone_hash'], $record['expires_at'], $record['purpose'] ) &&
			absint( $record['expires_at'] ) > time() &&
		hash_equals( (string) $record['phone_hash'], $this->phone_hash( $phone ) );
	}

	private function phone_hash( string $phone ): string {
		return hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}

	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', sanitize_text_field( $phone ) );

		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 11 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		return preg_match( '/^[6-9][0-9]{9}$/', $digits ) ? $digits : '';
	}

	private function mask_phone( string $phone ): string {
		return substr( $phone, 0, 2 ) . 'XXXXXX' . substr( $phone, -2 );
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}