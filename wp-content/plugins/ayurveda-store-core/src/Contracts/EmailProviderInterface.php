<?php

namespace Ashtanga\Store\Core\Contracts;

interface EmailProviderInterface {
	/**
	 * Send a business-specific notification through the configured WordPress mail transport.
	 * WooCommerce order emails remain owned by WooCommerce.
	 *
	 * @return true|\WP_Error
	 */
	public function send_notification( string $recipient, string $template, array $context );
}