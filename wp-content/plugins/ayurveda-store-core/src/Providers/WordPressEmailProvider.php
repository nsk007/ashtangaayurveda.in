<?php

namespace Ashtanga\Store\Core\Providers;

use Ashtanga\Store\Core\Contracts\EmailProviderInterface;

defined( 'ABSPATH' ) || exit;

final class WordPressEmailProvider implements EmailProviderInterface {
	public function send_notification( string $recipient, string $template, array $context ) {
		if ( ! is_email( $recipient ) ) {
			return new \WP_Error( 'email_invalid_recipient', __( 'The email notification could not be prepared.', 'ayurveda-store-core' ) );
		}

		$message = apply_filters( 'ashtanga_store_core_email_notification', null, sanitize_key( $template ), $context );

		if ( ! is_array( $message ) || empty( $message['subject'] ) || empty( $message['body'] ) ) {
			return new \WP_Error( 'email_template_missing', __( 'The email notification template is not configured.', 'ayurveda-store-core' ) );
		}

		$subject = sanitize_text_field( (string) $message['subject'] );
		$body = wp_kses_post( (string) $message['body'] );
		$sent = wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		return $sent ? true : new \WP_Error( 'email_delivery_failed', __( 'The email notification could not be sent.', 'ayurveda-store-core' ) );
	}
}