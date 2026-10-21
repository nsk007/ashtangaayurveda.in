<?php

namespace Ashtanga\Store\Core;

use Ashtanga\Store\Core\Admin\SettingsPage;
use Ashtanga\Store\Core\Contracts\OtpProviderInterface;
use Ashtanga\Store\Core\Contracts\EmailProviderInterface;
use Ashtanga\Store\Core\Contracts\PaymentProviderInterface;
use Ashtanga\Store\Core\Contracts\SmsProviderInterface;
use Ashtanga\Store\Core\Providers\Fast2SmsOtpProvider;
use Ashtanga\Store\Core\Providers\Fast2SmsSmsProvider;
use Ashtanga\Store\Core\Providers\ProviderRegistry;
use Ashtanga\Store\Core\Providers\RazorpayGatewayAdapter;
use Ashtanga\Store\Core\Providers\WordPressEmailProvider;
use Ashtanga\Store\Core\Services\AccountExperience;
use Ashtanga\Store\Core\Services\CustomerAuthenticator;
use Ashtanga\Store\Core\Services\OtpController;
use Ashtanga\Store\Core\Services\PrivacyController;
use Ashtanga\Store\Core\Services\SmsNotificationDispatcher;
use Ashtanga\Store\Core\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public function boot() {
		$providers = new ProviderRegistry();
		$providers->register( OtpProviderInterface::class, 'fast2sms', new Fast2SmsOtpProvider() );
		$providers->register( SmsProviderInterface::class, 'fast2sms', new Fast2SmsSmsProvider() );
		$providers->register( EmailProviderInterface::class, 'wordpress-mail', new WordPressEmailProvider() );

		if ( class_exists( 'WC_Razorpay' ) ) {
			$providers->register( PaymentProviderInterface::class, 'razorpay', new RazorpayGatewayAdapter() );
		}

		do_action( 'ashtanga_store_core_register_providers', $providers );

		( new SettingsPage( $providers ) )->register();
		( new OtpController( $providers, new RateLimiter(), new CustomerAuthenticator() ) )->register();
		( new AccountExperience( $providers ) )->register();
		( new PrivacyController() )->register();
		( new SmsNotificationDispatcher( $providers ) )->register();
	}
}