<?php

namespace Ashtanga\Store\Core\Support;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {
	public function cooldown( string $scope, string $subject, int $seconds ): bool {
		if ( '' === $subject || $seconds < 1 ) {
			return false;
		}

		$subject_hash = hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) );
		$key = 'ashtanga_store_cooldown_' . sanitize_key( $scope ) . '_' . substr( $subject_hash, 0, 40 );
		$result = AtomicLock::run(
			'cooldown:' . $key,
			static function () use ( $key, $seconds ) {
				if ( false !== get_transient( $key ) ) {
					return false;
				}

				return set_transient( $key, 1, $seconds );
			}
		);

		return true === $result;
	}

	public function allow( string $scope, string $subject, int $limit, int $window ): bool {
		if ( '' === $subject || $limit < 1 || $window < 1 ) {
			return false;
		}

		$subject_hash = hash_hmac( 'sha256', $subject, wp_salt( 'auth' ) );
		$key = 'ashtanga_store_rate_' . sanitize_key( $scope ) . '_' . substr( $subject_hash, 0, 40 );

		$result = AtomicLock::run(
			'rate:' . $key,
			static function () use ( $key, $limit, $window ) {
				$count = get_transient( $key );
				$count = false === $count ? 0 : absint( $count );

				if ( $count >= $limit ) {
					return false;
				}

				return set_transient( $key, $count + 1, $window );
			}
		);

		return true === $result;
	}

	public function client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$ip = '' !== $remote_addr ? sanitize_text_field( wp_unslash( $remote_addr ) ) : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}
}