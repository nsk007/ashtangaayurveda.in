<?php

namespace Ashtanga\Store\Core\Config;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION_NAME = 'ashtanga_store_core_settings';
	const DEFAULT_SECURITY = array(
		'otp_expiry_seconds'       => 300,
		'otp_resend_cooldown'      => 30,
		'otp_send_phone_limit'     => 3,
		'otp_send_ip_limit'        => 10,
		'otp_send_site_hour_limit' => 300,
		'otp_verify_attempt_limit' => 5,
		'otp_verify_ip_limit'      => 30,
	);
	const SECURITY_BOUNDS = array(
		'otp_expiry_seconds'       => array( 60, 300 ),
		'otp_resend_cooldown'      => array( 10, 300 ),
		'otp_send_phone_limit'     => array( 1, 5 ),
		'otp_send_ip_limit'        => array( 1, 30 ),
		'otp_send_site_hour_limit' => array( 10, 1000 ),
		'otp_verify_attempt_limit' => array( 1, 10 ),
		'otp_verify_ip_limit'      => array( 1, 100 ),
	);
	const SMS_EVENTS = array(
		'order_processing',
		'order_completed',
		'cod_on_hold',
	);

	public static function all() {
		$settings = get_option( self::OPTION_NAME, array() );

		return is_array( $settings ) ? $settings : array();
	}

	public static function provider_id( $key ) {
		$settings = self::all();
		$providers = isset( $settings['providers'] ) && is_array( $settings['providers'] )
			? $settings['providers']
			: array();

		return isset( $providers[ $key ] ) && is_string( $providers[ $key ] ) ? sanitize_key( $providers[ $key ] ) : '';
	}

	public static function security( string $key ): int {
		if ( ! isset( self::DEFAULT_SECURITY[ $key ], self::SECURITY_BOUNDS[ $key ] ) ) {
			return 0;
		}

		$settings = self::all();
		$security = isset( $settings['security'] ) && is_array( $settings['security'] ) ? $settings['security'] : array();
		$value = isset( $security[ $key ] ) && is_scalar( $security[ $key ] ) ? absint( $security[ $key ] ) : self::DEFAULT_SECURITY[ $key ];
		$bounds = self::SECURITY_BOUNDS[ $key ];

		return min( $bounds[1], max( $bounds[0], $value ) );
	}

	public static function sms_template_id( string $event ): string {
		if ( ! in_array( $event, self::SMS_EVENTS, true ) ) {
			return '';
		}

		$settings = self::all();
		$templates = isset( $settings['sms_templates'] ) && is_array( $settings['sms_templates'] ) ? $settings['sms_templates'] : array();
		$template_id = isset( $templates[ $event ] ) && is_scalar( $templates[ $event ] ) ? (string) $templates[ $event ] : '';

		return ctype_digit( $template_id ) ? $template_id : '';
	}
}