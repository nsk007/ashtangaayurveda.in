<?php

namespace Ashtanga\Store\Core\Admin;

use Ashtanga\Store\Core\Config\Settings;
use Ashtanga\Store\Core\Contracts\EmailProviderInterface;
use Ashtanga\Store\Core\Contracts\OtpProviderInterface;
use Ashtanga\Store\Core\Contracts\PaymentProviderInterface;
use Ashtanga\Store\Core\Contracts\ShippingProviderInterface;
use Ashtanga\Store\Core\Contracts\SmsProviderInterface;
use Ashtanga\Store\Core\Providers\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private $providers;
	private $provider_fields;
	private $security_fields;
	private $sms_fields;

	public function __construct( ProviderRegistry $providers ) {
		$this->providers = $providers;
		$this->provider_fields = array(
			'otp'      => array( OtpProviderInterface::class, __( 'Identity verification / OTP', 'ayurveda-store-core' ) ),
			'sms'      => array( SmsProviderInterface::class, __( 'Transactional SMS', 'ayurveda-store-core' ) ),
			'email'    => array( EmailProviderInterface::class, __( 'Business notifications email', 'ayurveda-store-core' ) ),
			'payment'  => array( PaymentProviderInterface::class, __( 'WooCommerce payment gateway', 'ayurveda-store-core' ) ),
			'shipping' => array( ShippingProviderInterface::class, __( 'Shipping serviceability', 'ayurveda-store-core' ) ),
		);
		$this->security_fields = array(
			'otp_expiry_seconds'       => array( __( 'OTP expiry (seconds)', 'ayurveda-store-core' ), 60, 300 ),
			'otp_resend_cooldown'      => array( __( 'OTP resend cooldown (seconds)', 'ayurveda-store-core' ), 10, 300 ),
			'otp_send_phone_limit'     => array( __( 'OTP sends per mobile per 10 minutes', 'ayurveda-store-core' ), 1, 5 ),
			'otp_send_ip_limit'        => array( __( 'OTP sends per IP per 10 minutes', 'ayurveda-store-core' ), 1, 30 ),
			'otp_send_site_hour_limit' => array( __( 'OTP sends per site per hour', 'ayurveda-store-core' ), 10, 1000 ),
			'otp_verify_attempt_limit' => array( __( 'Verification attempts per code', 'ayurveda-store-core' ), 1, 10 ),
			'otp_verify_ip_limit'      => array( __( 'Verification attempts per IP per 10 minutes', 'ayurveda-store-core' ), 1, 100 ),
		);
		$this->sms_fields = array(
			'order_processing' => __( 'Order processing DLT template ID', 'ayurveda-store-core' ),
			'order_completed'  => __( 'Order completed DLT template ID', 'ayurveda-store-core' ),
			'cod_on_hold'      => __( 'COD order confirmation DLT template ID', 'ayurveda-store-core' ),
		);
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Ayurveda Store Core', 'ayurveda-store-core' ),
			__( 'Ayurveda Store Core', 'ayurveda-store-core' ),
			'manage_options',
			'ayurveda-store-core',
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting(
			'ashtanga_store_core',
			Settings::OPTION_NAME,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		add_settings_section(
			'ashtanga_store_core_providers',
			__( 'Provider selection', 'ayurveda-store-core' ),
			array( $this, 'render_section' ),
			'ayurveda-store-core'
		);

		foreach ( $this->provider_fields as $key => $field ) {
			add_settings_field(
				'ashtanga_store_core_' . $key,
				$field[1],
				array( $this, 'render_provider_field' ),
				'ayurveda-store-core',
				'ashtanga_store_core_providers',
				array( 'key' => $key, 'contract' => $field[0] )
			);
		}

		add_settings_section(
			'ashtanga_store_core_security',
			__( 'Onboarding limits', 'ayurveda-store-core' ),
			array( $this, 'render_security_section' ),
			'ayurveda-store-core'
		);

		foreach ( $this->security_fields as $key => $field ) {
			add_settings_field(
				'ashtanga_store_core_' . $key,
				$field[0],
				array( $this, 'render_security_field' ),
				'ayurveda-store-core',
				'ashtanga_store_core_security',
				array( 'key' => $key, 'min' => $field[1], 'max' => $field[2] )
			);
		}

		add_settings_section(
			'ashtanga_store_core_sms',
			__( 'Transactional SMS templates', 'ayurveda-store-core' ),
			array( $this, 'render_sms_section' ),
			'ayurveda-store-core'
		);

		foreach ( $this->sms_fields as $key => $label ) {
			add_settings_field(
				'ashtanga_store_core_sms_' . $key,
				$label,
				array( $this, 'render_sms_field' ),
				'ayurveda-store-core',
				'ashtanga_store_core_sms',
				array( 'key' => $key )
			);
		}
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$requested = isset( $input['providers'] ) && is_array( $input['providers'] ) ? $input['providers'] : array();
		$requested_security = isset( $input['security'] ) && is_array( $input['security'] ) ? $input['security'] : array();
		$requested_sms = isset( $input['sms_templates'] ) && is_array( $input['sms_templates'] ) ? $input['sms_templates'] : array();
		$sanitized = array( 'providers' => array() );

		foreach ( $this->provider_fields as $key => $field ) {
			$id = isset( $requested[ $key ] ) && is_string( $requested[ $key ] ) ? sanitize_key( $requested[ $key ] ) : Settings::provider_id( $key );
			$sanitized['providers'][ $key ] = is_wp_error( $this->providers->get( $field[0], $id ) ) ? '' : $id;
		}

		foreach ( $this->security_fields as $key => $field ) {
			$value = isset( $requested_security[ $key ] ) && is_scalar( $requested_security[ $key ] ) ? absint( $requested_security[ $key ] ) : Settings::security( $key );
			$sanitized['security'][ $key ] = min( $field[2], max( $field[1], $value ) );
		}

		foreach ( $this->sms_fields as $key => $label ) {
			$value = isset( $requested_sms[ $key ] ) && is_scalar( $requested_sms[ $key ] ) ? sanitize_text_field( (string) $requested_sms[ $key ] ) : Settings::sms_template_id( $key );
			$sanitized['sms_templates'][ $key ] = ctype_digit( $value ) ? $value : '';
		}

		return $sanitized;
	}

	public function render_section() {
		echo '<p>' . esc_html__( 'Select only registered adapters. Provider credentials are not stored by this setting.', 'ayurveda-store-core' ) . '</p>';

		if ( 'fast2sms' === Settings::provider_id( 'otp' ) &&
			( ! defined( 'ASHTANGA_FAST2SMS_API_KEY' ) || '' === ASHTANGA_FAST2SMS_API_KEY ||
			! defined( 'ASHTANGA_FAST2SMS_OTP_ID' ) || '' === ASHTANGA_FAST2SMS_OTP_ID ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Fast2SMS is selected, but its API key and OTP template ID must be defined server-side before verification can work.', 'ayurveda-store-core' ) . '</p></div>';
		}
	}

	public function render_security_section() {
		echo '<p>' . esc_html__( 'Limits are enforced server-side. OTP values are handled by the provider and are never stored by this plugin.', 'ayurveda-store-core' ) . '</p>';
	}

	public function render_security_field( $args ) {
		$key = $args['key'];
		$name = Settings::OPTION_NAME . '[security][' . $key . ']';

		echo '<input type="number" step="1" min="' . esc_attr( $args['min'] ) . '" max="' . esc_attr( $args['max'] ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'ashtanga-setting-' . $key ) . '" value="' . esc_attr( Settings::security( $key ) ) . '">';
	}

	public function render_sms_section() {
		echo '<p>' . esc_html__( 'Leave a field blank to disable that transactional SMS. Use only DLT-approved numeric template IDs. Promotional messages are not sent.', 'ayurveda-store-core' ) . '</p>';
	}

	public function render_sms_field( $args ) {
		$key = $args['key'];
		$name = Settings::OPTION_NAME . '[sms_templates][' . $key . ']';

		echo '<input type="text" inputmode="numeric" pattern="[0-9]*" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'ashtanga-sms-template-' . $key ) . '" value="' . esc_attr( Settings::sms_template_id( $key ) ) . '">';
	}

	public function render_provider_field( $args ) {
		$key = $args['key'];
		$contract = $args['contract'];
		$available = $this->providers->all( $contract );
		$current = Settings::provider_id( $key );
		$name = Settings::OPTION_NAME . '[providers][' . $key . ']';

		if ( empty( $available ) ) {
			echo '<p>' . esc_html__( 'No verified provider adapter is registered. This service remains disabled.', 'ayurveda-store-core' ) . '</p>';
			return;
		}

		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( 'ashtanga-provider-' . $key ) . '">';
		echo '<option value="">' . esc_html__( 'Not configured', 'ayurveda-store-core' ) . '</option>';

		foreach ( $available as $id => $provider ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $current, $id, false ) . '>' . esc_html( $id ) . '</option>';
		}

		echo '</select>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Ayurveda Store Core', 'ayurveda-store-core' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'ashtanga_store_core' );
		do_settings_sections( 'ayurveda-store-core' );
		submit_button();
		echo '</form></div>';
	}
}