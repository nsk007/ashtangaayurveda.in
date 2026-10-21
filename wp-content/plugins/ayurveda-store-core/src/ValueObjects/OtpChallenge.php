<?php

namespace Ashtanga\Store\Core\ValueObjects;

defined( 'ABSPATH' ) || exit;

final class OtpChallenge {
	private $id;
	private $expires_at;
	private $identifier;
	private $purpose;

	public function __construct( string $id, int $expires_at, string $identifier, string $purpose ) {
		$this->id = $id;
		$this->expires_at = $expires_at;
		$this->identifier = $identifier;
		$this->purpose = $purpose;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_expires_at(): int {
		return $this->expires_at;
	}

	public function get_identifier(): string {
		return $this->identifier;
	}

	public function get_purpose(): string {
		return $this->purpose;
	}
}