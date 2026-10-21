<?php

namespace Ashtanga\Store\Core\Providers;

use Ashtanga\Store\Core\Contracts\PaymentProviderInterface;

defined( 'ABSPATH' ) || exit;

final class RazorpayGatewayAdapter implements PaymentProviderInterface {
	public function get_gateway_id(): string {
		return 'razorpay';
	}
}