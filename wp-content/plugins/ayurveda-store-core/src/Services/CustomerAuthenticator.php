<?php

namespace Ashtanga\Store\Core\Services;

use Ashtanga\Store\Core\Support\AtomicLock;

defined( 'ABSPATH' ) || exit;

final class CustomerAuthenticator {
	const VERIFIED_PHONE_HASH_META = '_ashtanga_store_verified_phone_hash';

	public function authenticate( string $phone ) {
		$phone = $this->normalize_phone( $phone );

		if ( '' === $phone ) {
			return new \WP_Error( 'invalid_phone', __( 'Phone verification could not be completed.', 'ayurveda-store-core' ) );
		}

		$phone_hash = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
		$user_id = AtomicLock::run(
			'customer-phone:' . $phone_hash,
			function () use ( $phone, $phone_hash ) {
				$matches = get_users(
					array(
						'meta_key'   => self::VERIFIED_PHONE_HASH_META,
						'meta_value' => $phone_hash,
						'number'     => 3,
						'fields'     => 'ID',
					)
				);

				if ( count( $matches ) > 1 ) {
					return new \WP_Error( 'duplicate_verified_phone', __( 'We could not safely match this number to one account. Please contact customer care.', 'ayurveda-store-core' ) );
				}

				if ( 1 === count( $matches ) ) {
					return absint( $matches[0] );
				}

				$legacy_user_id = $this->find_legacy_customer( $phone );

				if ( is_wp_error( $legacy_user_id ) ) {
					return $legacy_user_id;
				}

				if ( $legacy_user_id ) {
					if ( ! add_user_meta( $legacy_user_id, self::VERIFIED_PHONE_HASH_META, $phone_hash, true ) ) {
						return new \WP_Error( 'phone_binding_failed', __( 'We could not safely match this number to one account. Please contact customer care.', 'ayurveda-store-core' ) );
					}

					update_user_meta( $legacy_user_id, 'billing_phone', $phone );

					return $legacy_user_id;
				}

				return $this->create_customer( $phone, $phone_hash );
			}
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || $this->is_staff( $user_id ) ) {
			return new \WP_Error( 'account_unavailable', __( 'We could not safely sign you in. Please contact customer care.', 'ayurveda-store-core' ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set_customer_session_cookie( true );
		}

		return $user_id;
	}

	private function find_legacy_customer( string $phone ) {
		$matches = array();
		$variants = array( $phone, '+91' . $phone, '91' . $phone, '+91 ' . $phone, '0' . $phone );

		foreach ( $variants as $variant ) {
			$user_ids = get_users(
				array(
					'meta_key'   => 'billing_phone',
					'meta_value' => $variant,
					'number'     => 3,
					'fields'     => 'ID',
				)
			);

			foreach ( $user_ids as $user_id ) {
				$matches[ absint( $user_id ) ] = absint( $user_id );
			}
		}

		if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'ambiguous_legacy_phone', __( 'We could not safely match this number to one account. Please contact customer care.', 'ayurveda-store-core' ) );
		}

		if ( ! $matches ) {
			return 0;
		}

		$user_id = (int) reset( $matches );

		if ( $this->is_staff( $user_id ) || ! $this->has_paid_order_with_phone( $user_id, $phone ) ) {
			return new \WP_Error( 'legacy_account_recovery', __( 'We could not safely match this number to one account. Please contact customer care.', 'ayurveda-store-core' ) );
		}

		return $user_id;
	}

	private function has_paid_order_with_phone( int $user_id, string $phone ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-processing', 'wc-completed' ),
				'limit'       => 20,
				'return'      => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( $phone === $this->normalize_phone( (string) $order->get_billing_phone() ) ) {
				return true;
			}
		}

		return false;
	}

	private function create_customer( string $phone, string $phone_hash ) {
		$uuid = strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$email = 'customer+' . $uuid . '@invalid.invalid';
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'customer_' . $uuid,
				'user_pass'    => wp_generate_password( 48, true, true ),
				'user_email'   => $email,
				'display_name' => __( 'Customer', 'ayurveda-store-core' ),
				'nickname'     => __( 'Customer', 'ayurveda-store-core' ),
				'role'         => 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error( 'customer_creation_failed', __( 'We could not create your account. Please try again or contact customer care.', 'ayurveda-store-core' ) );
		}

		if ( ! add_user_meta( $user_id, self::VERIFIED_PHONE_HASH_META, $phone_hash, true ) ) {
			return new \WP_Error( 'phone_binding_failed', __( 'We could not finish setting up your account. Please contact customer care.', 'ayurveda-store-core' ) );
		}

		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'ashtanga_email_verified', 'no' );
		update_user_meta( $user_id, 'ashtanga_profile_completed', 'no' );

		return (int) $user_id;
	}

	private function is_staff( int $user_id ): bool {
		foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_users', 'edit_posts' ) as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				return true;
			}
		}

		return false;
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