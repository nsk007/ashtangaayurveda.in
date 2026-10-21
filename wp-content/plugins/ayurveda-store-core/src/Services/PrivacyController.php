<?php

namespace Ashtanga\Store\Core\Services;

defined( 'ABSPATH' ) || exit;

final class PrivacyController {
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['ashtanga-store-core'] = array(
			'exporter_friendly_name' => __( 'Ashtanga Ayurveda Store Account', 'ayurveda-store-core' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['ashtanga-store-core'] = array(
			'eraser_friendly_name' => __( 'Ashtanga Ayurveda Store Account', 'ayurveda-store-core' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	public function export( string $email_address, int $page = 1 ): array {
		$user_ids = 1 === $page ? $this->find_user_ids( $email_address ) : array();

		if ( ! $user_ids ) {
			return array( 'data' => array(), 'done' => true );
		}

		$fields = array(
			'first_name'          => __( 'First name', 'ayurveda-store-core' ),
			'last_name'           => __( 'Last name', 'ayurveda-store-core' ),
			'billing_email'       => __( 'Checkout email (not verified)', 'ayurveda-store-core' ),
			'billing_phone'       => __( 'Verified mobile number', 'ayurveda-store-core' ),
			'shipping_address_1'  => __( 'Delivery address', 'ayurveda-store-core' ),
			'shipping_address_2'  => __( 'Delivery address line 2', 'ayurveda-store-core' ),
			'shipping_city'       => __( 'Delivery city', 'ayurveda-store-core' ),
			'shipping_state'      => __( 'Delivery state', 'ayurveda-store-core' ),
			'shipping_postcode'   => __( 'Delivery PIN code', 'ayurveda-store-core' ),
			'ashtanga_email_verified'    => __( 'Email verification status', 'ayurveda-store-core' ),
			'ashtanga_profile_completed' => __( 'Profile completion status', 'ayurveda-store-core' ),
		);
		$groups = array();

		foreach ( $user_ids as $user_id ) {
			$data = array();
			$verified_phone = '' !== (string) get_user_meta( $user_id, CustomerAuthenticator::VERIFIED_PHONE_HASH_META, true );
			$fields['billing_phone'] = $verified_phone ? __( 'Verified mobile number', 'ayurveda-store-core' ) : __( 'Mobile number', 'ayurveda-store-core' );

			foreach ( $fields as $key => $label ) {
				$value = get_user_meta( $user_id, $key, true );

				if ( '' === $value || null === $value ) {
					continue;
				}

				$data[] = array(
					'name'  => $label,
					'value' => is_scalar( $value ) ? (string) $value : '',
				);
			}

			$groups[] = array(
				'group_id'    => 'ashtanga-store-core',
				'group_label' => __( 'Store account', 'ayurveda-store-core' ),
				'item_id'     => 'user-' . absint( $user_id ),
				'data'        => $data,
			);
		}

		return array(
			'data' => $groups,
			'done' => true,
		);
	}

	public function erase( string $email_address, int $page = 1 ): array {
		$user_ids = 1 === $page ? $this->find_user_ids( $email_address ) : array();
		$removed = 0;

		foreach ( $user_ids as $user_id ) {
			foreach ( array( CustomerAuthenticator::VERIFIED_PHONE_HASH_META, 'ashtanga_email_verified', 'ashtanga_profile_completed' ) as $meta_key ) {
				if ( metadata_exists( 'user', $user_id, $meta_key ) ) {
					delete_user_meta( $user_id, $meta_key );
					++$removed;
				}
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	private function find_user_ids( string $email_address ): array {
		$email_address = sanitize_email( $email_address );

		if ( ! is_email( $email_address ) ) {
			return array();
		}

		$user_ids = get_users(
			array(
				'meta_key'   => 'billing_email',
				'meta_value' => $email_address,
				'number'     => 100,
				'fields'     => 'ID',
			)
		);
		$user = get_user_by( 'email', $email_address );

		if ( $user ) {
			$user_ids[] = absint( $user->ID );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
	}
}