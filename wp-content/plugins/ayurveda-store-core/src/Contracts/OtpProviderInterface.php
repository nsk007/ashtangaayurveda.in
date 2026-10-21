<?php

namespace Ashtanga\Store\Core\Contracts;

use Ashtanga\Store\Core\ValueObjects\OtpChallenge;

interface OtpProviderInterface {
	/**
	 * Request a one-time code for a normalized identifier and purpose.
	 *
	 * @return OtpChallenge|\WP_Error
	 */
	public function request_code( string $identifier, string $purpose );

	/**
	 * Resend a code for an existing provider challenge.
	 *
	 * @return true|\WP_Error
	 */
	public function resend_code( OtpChallenge $challenge );

	/**
	 * Verify a code against a provider-issued challenge.
	 *
	 * @return true|\WP_Error
	 */
	public function verify_code( OtpChallenge $challenge, string $code );
}