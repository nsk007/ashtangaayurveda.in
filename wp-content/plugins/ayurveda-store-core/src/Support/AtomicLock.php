<?php

namespace Ashtanga\Store\Core\Support;

defined( 'ABSPATH' ) || exit;

final class AtomicLock {
	const STALE_AFTER = 60;

	public static function run( string $name, callable $callback ) {
		$option_name = '_ashtanga_store_lock_' . hash( 'sha256', $name );
		$token = wp_generate_uuid4();
		$lock = array(
			'token'   => $token,
			'created' => time(),
		);

		if ( ! add_option( $option_name, $lock, '', 'no' ) ) {
			$current = get_option( $option_name, array() );
			$created = is_array( $current ) && isset( $current['created'] ) ? absint( $current['created'] ) : time();

			if ( time() - $created <= self::STALE_AFTER ) {
				return new \WP_Error( 'operation_busy', __( 'Please try again in a moment.', 'ayurveda-store-core' ) );
			}

			if ( ! self::delete_if_unchanged( $option_name, $current ) ) {
				return new \WP_Error( 'operation_busy', __( 'Please try again in a moment.', 'ayurveda-store-core' ) );
			}

			if ( ! add_option( $option_name, $lock, '', 'no' ) ) {
				return new \WP_Error( 'operation_busy', __( 'Please try again in a moment.', 'ayurveda-store-core' ) );
			}
		}

		try {
			return call_user_func( $callback );
		} finally {
			$current = get_option( $option_name, array() );

			if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( $token, $current['token'] ) ) {
				self::delete_if_unchanged( $option_name, $current );
			}
		}
	}

	private static function delete_if_unchanged( string $option_name, array $expected ): bool {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return false;
		}

		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => maybe_serialize( $expected ),
			),
			array( '%s', '%s' )
		);

		if ( 1 === $deleted ) {
			wp_cache_delete( $option_name, 'options' );
		}

		return 1 === $deleted;
	}
}