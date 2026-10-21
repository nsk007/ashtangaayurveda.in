<?php

namespace Ashtanga\Store\Core\Contracts;

interface SmsProviderInterface {
	/**
	 * Send an approved transactional template.
	 *
	 * @return true|\WP_Error
	 */
	public function send_template( string $recipient, string $template_id, array $variables );
}