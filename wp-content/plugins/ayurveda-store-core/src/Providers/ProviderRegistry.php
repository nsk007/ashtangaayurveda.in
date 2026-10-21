<?php

namespace Ashtanga\Store\Core\Providers;

use Ashtanga\Store\Core\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	private $providers = array();

	public function register( string $contract, string $id, $provider ) {
		$id = sanitize_key( $id );

		if ( ! interface_exists( $contract ) || ! ( $provider instanceof $contract ) || '' === $id ) {
			return new \WP_Error( 'invalid_provider', __( 'The provider does not implement the required contract.', 'ayurveda-store-core' ) );
		}

		$this->providers[ $contract ][ $id ] = $provider;

		return true;
	}

	public function all( string $contract ): array {
		return isset( $this->providers[ $contract ] ) ? $this->providers[ $contract ] : array();
	}

	public function get( string $contract, string $id ) {
		$id = sanitize_key( $id );

		if ( ! isset( $this->providers[ $contract ][ $id ] ) ) {
			return new \WP_Error( 'provider_unavailable', __( 'The selected provider is not registered.', 'ayurveda-store-core' ) );
		}

		return $this->providers[ $contract ][ $id ];
	}

	public function get_configured( string $contract, string $settings_key ) {
		$id = Settings::provider_id( $settings_key );

		if ( '' === $id ) {
			return new \WP_Error( 'provider_not_configured', __( 'No provider is configured for this service.', 'ayurveda-store-core' ) );
		}

		return $this->get( $contract, $id );
	}
}