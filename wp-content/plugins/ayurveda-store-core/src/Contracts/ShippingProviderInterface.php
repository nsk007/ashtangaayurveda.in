<?php

namespace Ashtanga\Store\Core\Contracts;

interface ShippingProviderInterface {
	/**
	 * Return registered WooCommerce shipping method IDs owned by this provider.
	 */
	public function get_shipping_method_ids(): array;

	/**
	 * Check an informational product-page destination PIN code.
	 * Authoritative checkout rates remain the responsibility of WooCommerce shipping methods.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_serviceability( string $destination_postcode );
}