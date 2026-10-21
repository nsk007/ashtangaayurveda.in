<?php

namespace Ashtanga\Store\Core\Services;

use Ashtanga\Store\Core\Config\Settings;
use Ashtanga\Store\Core\Contracts\OtpProviderInterface;
use Ashtanga\Store\Core\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class AccountExperience {
	private $providers;

	public function __construct( ProviderRegistry $providers ) {
		$this->providers = $providers;
	}

	public function register() {
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', array( $this, 'disable_guest_checkout_setting' ) );
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'require_account' ), 0 );
		add_filter( 'woocommerce_checkout_must_be_logged_in_message', array( $this, 'checkout_login_message' ) );
		add_action( 'template_redirect', array( $this, 'redirect_guest_checkout' ), 1 );
		add_action( 'woocommerce_checkout_process', array( $this, 'block_classic_guest_checkout' ), 0 );
		add_filter( 'the_content', array( $this, 'replace_incomplete_account_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ashtanga_store_save_profile', array( $this, 'save_profile' ) );
	}

	public function disable_guest_checkout_setting( $pre_value = false ) {
		return 'no';
	}

	public function require_account( $required = false ): bool {
		return true;
	}

	public function redirect_guest_checkout() {
		if ( is_admin() || is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ||
			( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) ) {
			return;
		}

		$account_url = wc_get_page_permalink( 'myaccount' );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'ashtanga_store_after_profile', wc_get_checkout_url() );
			WC()->session->set_customer_session_cookie( true );
		}

		wp_safe_redirect( $account_url ? $account_url : home_url( '/' ) );
		exit;
	}

	public function block_classic_guest_checkout() {
		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'Verify your number and sign in before placing your order.', 'ayurveda-store-core' ), 'error' );
		}
	}

	public function checkout_login_message( $message ): string {
		return __( 'Verify your number on the account page before checking out.', 'ayurveda-store-core' );
	}

	public function replace_incomplete_account_content( $content ) {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! in_the_loop() ) {
			return $content;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return $content;
		}

		if ( ! is_user_logged_in() ) {
			return $this->render_otp_form();
		}

		if ( ! $this->has_complete_profile( get_current_user_id() ) ) {
			return $this->render_profile_form( get_current_user_id() );
		}

		return $content;
	}

	public function enqueue_assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_script(
			'ashtanga-store-onboarding',
			ASHTANGA_STORE_CORE_URL . 'assets/js/onboarding.js',
			array(),
			ASHTANGA_STORE_CORE_VERSION,
			true
		);

		wp_localize_script(
			'ashtanga-store-onboarding',
			'ashtangaStoreOnboarding',
			array(
				'endpoint' => rest_url( 'ashtanga-store/v1/otp/' ),
				'nonce'    => wp_create_nonce( 'ashtanga_store_otp' ),
				'cooldown' => Settings::security( 'otp_resend_cooldown' ),
			)
		);
	}

	public function save_profile() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You must be signed in to update your account.', 'ayurveda-store-core' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ashtanga_store_profile' );

		$user_id = get_current_user_id();
		$stored_phone = get_user_meta( $user_id, 'billing_phone', true );
		$phone = is_string( $stored_phone ) ? $this->normalize_phone( $stored_phone ) : '';
		$phone_hash = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
		$verified_hash = (string) get_user_meta( $user_id, CustomerAuthenticator::VERIFIED_PHONE_HASH_META, true );

		if ( '' === $phone || '' === $verified_hash || ! hash_equals( $verified_hash, $phone_hash ) ) {
			wp_safe_redirect( add_query_arg( 'profile_error', 'verification', wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		$first_name = sanitize_text_field( $this->posted_string( 'first_name' ) );
		$last_name = sanitize_text_field( $this->posted_string( 'last_name' ) );
		$email = sanitize_email( $this->posted_string( 'email' ) );
		$address_1 = sanitize_text_field( $this->posted_string( 'shipping_address_1' ) );
		$address_2 = sanitize_text_field( $this->posted_string( 'shipping_address_2' ) );
		$city = sanitize_text_field( $this->posted_string( 'shipping_city' ) );
		$state = sanitize_text_field( $this->posted_string( 'shipping_state' ) );
		$postcode = preg_replace( '/\D+/', '', sanitize_text_field( $this->posted_string( 'shipping_postcode' ) ) );
		$states = WC()->countries->get_states( 'IN' );

		if ( '' === $first_name || '' === $last_name || ! is_email( $email ) || '' === $address_1 || '' === $city || ! is_array( $states ) || ! array_key_exists( $state, $states ) || ! preg_match( '/^[0-9]{6}$/', $postcode ) ) {
			wp_safe_redirect( add_query_arg( 'profile_error', 'details', wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		$user_update = wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			)
		);

		if ( is_wp_error( $user_update ) ) {
			wp_safe_redirect( add_query_arg( 'profile_error', 'details', wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}

		$address = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'address_1'  => $address_1,
			'address_2'  => $address_2,
			'city'       => $city,
			'state'      => $state,
			'postcode'   => $postcode,
			'country'    => 'IN',
		);

		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'shipping_phone', $phone );
		update_user_meta( $user_id, 'ashtanga_email_verified', 'no' );

		foreach ( $address as $field => $value ) {
			update_user_meta( $user_id, 'billing_' . $field, $value );
			update_user_meta( $user_id, 'shipping_' . $field, $value );
		}

		update_user_meta( $user_id, 'ashtanga_profile_completed', 'yes' );

		$redirect = wc_get_page_permalink( 'myaccount' );

		if ( function_exists( 'WC' ) && WC()->session ) {
			$redirect = wp_validate_redirect( WC()->session->get( 'ashtanga_store_after_profile', '' ), $redirect );
			WC()->session->set( 'ashtanga_store_after_profile', null );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private function render_otp_form(): string {
		$provider = $this->providers->get_configured( OtpProviderInterface::class, 'otp' );

		if ( is_wp_error( $provider ) ) {
			return '<section class="ashtanga-account-access"><p>' . esc_html__( 'Phone verification is not configured yet.', 'ayurveda-store-core' ) . ' <a href="' . esc_url( 'mailto:support@ashtangaayurveda.in' ) . '">' . esc_html__( 'Contact customer care', 'ayurveda-store-core' ) . '</a></p></section>';
		}

		$output = '<section class="ashtanga-account-access">';
		$output .= '<h1>' . esc_html__( 'Sign in to continue', 'ayurveda-store-core' ) . '</h1>';
		$output .= '<p>' . esc_html__( 'Verify your number to continue and securely save your order details.', 'ayurveda-store-core' ) . '</p>';
		$output .= '<form data-ashtanga-otp-form novalidate>';
		$output .= '<label for="ashtanga-otp-phone">' . esc_html__( 'Mobile number', 'ayurveda-store-core' ) . '</label>';
		$output .= '<input id="ashtanga-otp-phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" maxlength="14" required>';
		$output .= '<button type="button" data-ashtanga-send>' . esc_html__( 'Send code', 'ayurveda-store-core' ) . '</button>';
		$output .= '<div data-ashtanga-code-step hidden>';
		$output .= '<p data-ashtanga-masked></p>';
		$output .= '<label for="ashtanga-otp-code">' . esc_html__( 'Verification code', 'ayurveda-store-core' ) . '</label>';
		$output .= '<input id="ashtanga-otp-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" pattern="[0-9]{4,10}">';
		$output .= '<button type="button" data-ashtanga-verify>' . esc_html__( 'Verify and continue', 'ayurveda-store-core' ) . '</button>';
		$output .= '<button type="button" data-ashtanga-resend disabled>' . esc_html__( 'Resend code', 'ayurveda-store-core' ) . '</button>';
		$output .= '</div><p data-ashtanga-status role="status" aria-live="polite"></p><p>' . esc_html__( 'Unable to receive a code?', 'ayurveda-store-core' ) . ' <a href="' . esc_url( 'mailto:support@ashtangaayurveda.in' ) . '">' . esc_html__( 'Contact customer care', 'ayurveda-store-core' ) . '</a></p></form></section>';

		return $output;
	}

	private function render_profile_form( int $user_id ): string {
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		$values = array(
			'first_name'          => get_user_meta( $user_id, 'first_name', true ),
			'last_name'           => get_user_meta( $user_id, 'last_name', true ),
			'email'               => get_user_meta( $user_id, 'billing_email', true ),
			'shipping_address_1'  => get_user_meta( $user_id, 'shipping_address_1', true ),
			'shipping_address_2'  => get_user_meta( $user_id, 'shipping_address_2', true ),
			'shipping_city'       => get_user_meta( $user_id, 'shipping_city', true ),
			'shipping_state'      => get_user_meta( $user_id, 'shipping_state', true ),
			'shipping_postcode'   => get_user_meta( $user_id, 'shipping_postcode', true ),
		);
		$error = isset( $_GET['profile_error'] ) && is_string( $_GET['profile_error'] ) ? sanitize_key( wp_unslash( $_GET['profile_error'] ) ) : '';
		$error_message = 'verification' === $error
			? __( 'Please verify your mobile number again or contact customer care.', 'ayurveda-store-core' )
			: ( 'details' === $error ? __( 'Check the required details and enter a valid six-digit PIN code.', 'ayurveda-store-core' ) : '' );

		$output = '<section class="ashtanga-account-profile"><h1>' . esc_html__( 'Delivery details', 'ayurveda-store-core' ) . '</h1>';
		$output .= '<p>' . esc_html__( 'These details will be saved securely to your customer account for checkout.', 'ayurveda-store-core' ) . '</p>';

		if ( '' !== $error_message ) {
			$output .= '<p role="alert">' . esc_html( $error_message ) . '</p>';
		}

		$output .= '<p><strong>' . esc_html__( 'Verified mobile', 'ayurveda-store-core' ) . '</strong><br>' . esc_html( $phone ) . '</p>';
		$output .= '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		$output .= '<input type="hidden" name="action" value="ashtanga_store_save_profile">';
		$output .= wp_nonce_field( 'ashtanga_store_profile', '_wpnonce', true, false );
		$output .= $this->profile_input( 'first_name', __( 'First name', 'ayurveda-store-core' ), $values['first_name'], 'text', true );
		$output .= $this->profile_input( 'last_name', __( 'Last name', 'ayurveda-store-core' ), $values['last_name'], 'text', true );
		$output .= $this->profile_input( 'email', __( 'Email address', 'ayurveda-store-core' ), $values['email'], 'email', true );
		$output .= $this->profile_input( 'shipping_address_1', __( 'Address', 'ayurveda-store-core' ), $values['shipping_address_1'], 'text', true );
		$output .= $this->profile_input( 'shipping_address_2', __( 'Apartment, suite, etc. (optional)', 'ayurveda-store-core' ), $values['shipping_address_2'], 'text', false );
		$output .= $this->profile_input( 'shipping_city', __( 'Town / city', 'ayurveda-store-core' ), $values['shipping_city'], 'text', true );
		$output .= $this->profile_state_select( $values['shipping_state'] );
		$output .= $this->profile_input( 'shipping_postcode', __( 'PIN code', 'ayurveda-store-core' ), $values['shipping_postcode'], 'text', true );
		$output .= '<button type="submit">' . esc_html__( 'Save details and continue', 'ayurveda-store-core' ) . '</button></form></section>';

		return $output;
	}

	private function profile_input( string $name, string $label, $value, string $type, bool $required ): string {
		$id = 'ashtanga-profile-' . $name;
		$required_attribute = $required ? ' required' : '';
		$autocomplete = 'email' === $type ? 'email' : ( 'first_name' === $name ? 'given-name' : ( 'last_name' === $name ? 'family-name' : 'off' ) );

		return '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="' . esc_attr( $autocomplete ) . '"' . $required_attribute . '></p>';
	}

	private function profile_state_select( $current ): string {
		$states = WC()->countries->get_states( 'IN' );
		$output = '<p><label for="ashtanga-profile-shipping_state">' . esc_html__( 'State', 'ayurveda-store-core' ) . '</label><select id="ashtanga-profile-shipping_state" name="shipping_state" required>';
		$output .= '<option value="">' . esc_html__( 'Select a state', 'ayurveda-store-core' ) . '</option>';

		if ( is_array( $states ) ) {
			foreach ( $states as $code => $name ) {
				$output .= '<option value="' . esc_attr( $code ) . '" ' . selected( (string) $current, (string) $code, false ) . '>' . esc_html( $name ) . '</option>';
			}
		}

		return $output . '</select></p>';
	}

	private function has_complete_profile( int $user_id ): bool {
		if ( 'yes' !== get_user_meta( $user_id, 'ashtanga_profile_completed', true ) ) {
			return false;
		}

		$required = array( 'first_name', 'last_name', 'billing_email', 'shipping_address_1', 'shipping_city', 'shipping_state', 'shipping_postcode' );

		foreach ( $required as $key ) {
			if ( '' === trim( (string) get_user_meta( $user_id, $key, true ) ) ) {
				return false;
			}
		}

		return is_email( (string) get_user_meta( $user_id, 'billing_email', true ) ) &&
			preg_match( '/^[0-9]{6}$/', (string) get_user_meta( $user_id, 'shipping_postcode', true ) );
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

	private function posted_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}
}