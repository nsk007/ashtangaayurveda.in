<?php

namespace Ashtanga\Store\Core\Contracts;

interface PaymentProviderInterface {
	/**
	 * Return the ID of a registered WooCommerce payment gateway.
	 * The adapter must not process credentials, authorize, capture, or refund payments.
	 */
	public function get_gateway_id(): string;
}