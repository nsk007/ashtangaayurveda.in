<?php
add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('ashtanga-store', get_stylesheet_uri(), [], (string) filemtime(get_stylesheet_directory() . '/style.css'));
});

add_action('after_setup_theme', function () {
	add_theme_support('woocommerce');
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');
	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
});

add_action('woocommerce_save_account_details_errors', function ($errors, $user) {
	$current_user = get_user_by('id', $user->ID);
	if ($current_user instanceof WP_User) {
		$user->user_email = $current_user->user_email;
	}

	if (!isset($_POST['account_phone'])) {
		return;
	}

	$phone = sanitize_text_field(wp_unslash($_POST['account_phone']));
	$digits = preg_replace('/\D+/', '', $phone);
	if ('' !== $phone && (!preg_match('/^[0-9+().\-\s]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15)) {
		$errors->add('account_phone_invalid', 'Enter a valid phone number, including its country code if needed.');
	}
}, 10, 2);

add_action('woocommerce_save_account_details', function ($user_id) {
	if (!isset($_POST['account_phone'])) {
		return;
	}

	$phone = sanitize_text_field(wp_unslash($_POST['account_phone']));
	update_user_meta($user_id, 'billing_phone', $phone);
});

add_action('wp', function () {
	if (function_exists('shiprocket_show_check_pincode')) {
		remove_action('woocommerce_single_product_summary', 'shiprocket_show_check_pincode', 20);
	}
});

add_action('init', function () {
	if (get_page_by_path('delivery-availability')) {
		return;
	}

	$page_id = wp_insert_post([
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'Delivery availability',
		'post_name' => 'delivery-availability',
		'post_content' => '<p>Check whether delivery service is currently available to your PIN code. Product-specific checks are available on each product page.</p>[ashtanga_delivery_checker]',
	], true);

	if (is_wp_error($page_id)) {
		error_log('Ashtanga Store: Could not create the delivery availability page: ' . $page_id->get_error_message());
	}
}, 20);

function ashtanga_render_delivery_checker($product_id = 0) {
	?>
	<section class="ashtanga-delivery-checker" aria-labelledby="ashtanga-delivery-checker-title">
		<form action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="post" class="ashtanga-delivery-checker__form">
			<input type="hidden" name="action" value="ashtanga_check_delivery">
			<input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('ashtanga_check_delivery')); ?>">
			<input type="hidden" name="product_id" value="<?php echo esc_attr(absint($product_id)); ?>">
			<label id="ashtanga-delivery-checker-title" for="ashtanga-delivery-postcode">PIN code</label>
			<div class="ashtanga-delivery-checker__controls">
				<input id="ashtanga-delivery-postcode" name="postcode" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit PIN" required>
				<button type="submit">
					<span class="ashtanga-delivery-checker__button-label">Check availability</span>
					<svg class="ashtanga-delivery-checker__spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle></svg>
					<svg class="ashtanga-delivery-checker__complete-icon" viewBox="0 0 24 24" aria-hidden="true"><path class="ashtanga-delivery-checker__icon-available" d="m5 12 4 4L19 6"></path><path class="ashtanga-delivery-checker__icon-unavailable" d="m6 6 12 12M18 6 6 18"></path><path class="ashtanga-delivery-checker__icon-error" d="M12 8v5m0 3h.01M10.3 3.8 1.9 18.3A2 2 0 0 0 3.6 21h16.8a2 2 0 0 0 1.7-2.7L13.7 3.8a2 2 0 0 0-3.4 0Z"></path></svg>
				</button>
			</div>
		</form>
		<div class="ashtanga-delivery-checker__message" role="status" aria-live="polite" hidden>
			<svg class="ashtanga-delivery-checker__message-icon" viewBox="0 0 24 24" aria-hidden="true"><path class="ashtanga-delivery-checker__icon-available" d="m5 12 4 4L19 6"></path><path class="ashtanga-delivery-checker__icon-unavailable" d="m6 6 12 12M18 6 6 18"></path><path class="ashtanga-delivery-checker__icon-error" d="M12 8v5m0 3h.01M10.3 3.8 1.9 18.3A2 2 0 0 0 3.6 21h16.8a2 2 0 0 0 1.7-2.7L13.7 3.8a2 2 0 0 0-3.4 0Z"></path></svg>
			<span class="ashtanga-delivery-checker__message-text"></span>
			<button class="ashtanga-delivery-checker__dismiss" type="button" aria-label="Dismiss delivery message">&times;</button>
		</div>
	</section>
	<?php
}

add_shortcode('ashtanga_delivery_checker', function () {
	ob_start();
	ashtanga_render_delivery_checker(is_product() ? get_queried_object_id() : 0);
	return ob_get_clean();
});

add_action('wp_ajax_ashtanga_check_delivery', 'ashtanga_check_delivery');
add_action('wp_ajax_nopriv_ashtanga_check_delivery', 'ashtanga_check_delivery');

function ashtanga_check_delivery() {
	if (!check_ajax_referer('ashtanga_check_delivery', 'nonce', false)) {
		wp_send_json_error(['message' => 'Refresh the page and try again.'], 403);
	}

	$postcode = isset($_POST['postcode']) ? sanitize_text_field(wp_unslash($_POST['postcode'])) : '';
	$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
	if (!preg_match('/^[0-9]{6}$/', $postcode)) {
		wp_send_json_error(['message' => 'Enter a valid 6-digit PIN code.'], 400);
	}

	$settings = get_option('woocommerce_shiprocket_woocommerce_shipping_settings', []);
	$integration_id = is_array($settings) ? sanitize_text_field($settings['integration_id'] ?? '') : '';
	if (!$integration_id || !defined('SHIPROCKET_WC_RATE_URL') || !defined('SOURCE_WC_APP')) {
		wp_send_json_error(['message' => 'Could not check delivery right now. Try again.'], 503);
	}

	if ($product_id) {
		$product = wc_get_product($product_id);
		if (!$product || 'publish' !== get_post_status($product_id)) {
			wp_send_json_error(['message' => 'This product is not available for delivery checks.'], 404);
		}

		$weight = $product->get_weight();
		$dimensions = [$product->get_length(), $product->get_width(), $product->get_height()];
		if (!is_numeric($weight) || (float) $weight <= 0) {
			wp_send_json_error(['message' => 'Could not check delivery right now. Try again.'], 503);
		}
		foreach ($dimensions as $dimension) {
			if (!is_numeric($dimension) || (float) $dimension <= 0) {
				wp_send_json_error(['message' => 'Could not check delivery right now. Try again.'], 503);
			}
		}
	} else {
		$weight = wc_get_weight(0.5, 'kg', get_option('woocommerce_weight_unit', 'kg'));
	}

	$address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$limit_key = 'ashtanga_pin_' . hash('sha256', $address . ':' . $product_id);
	$attempts = (int) get_transient($limit_key);
	if ($attempts >= 12) {
		wp_send_json_error(['message' => 'Too many checks. Try again shortly.'], 429);
	}
	set_transient($limit_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

	$response = wp_remote_get(
		add_query_arg(
			[
				'weight' => $weight,
				'cod' => '0',
				'delivery_postcode' => $postcode,
				'store_url' => home_url('/'),
				'merchant_id' => $integration_id,
				'unit' => get_option('woocommerce_weight_unit'),
			],
			SHIPROCKET_WC_RATE_URL
		),
		[
			'headers' => ['authorization' => 'ACCESS_TOKEN:' . SOURCE_WC_APP],
			'timeout' => 15,
		]
	);
	if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
		wp_send_json_error(['message' => 'Could not check delivery right now. Try again.'], 503);
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	if (!is_array($data) || 200 !== (int) ($data['status'] ?? 0) || !isset($data['data']) || !is_array($data['data']) || !isset($data['data']['available_courier_companies']) || !is_array($data['data']['available_courier_companies'])) {
		wp_send_json_error(['message' => 'Could not check delivery right now. Try again.'], 503);
	}
	$couriers = array_filter(
		$data['data']['available_courier_companies'],
		static function ($courier) {
			return is_array($courier) && !empty($courier['courier_company_id']);
		}
	);
	if (!$couriers) {
		wp_send_json_success(['message' => 'Delivery is not available to this PIN code.', 'available' => false]);
	}

	$recommended_id = $data['data']['recommended_courier_company_id'] ?? null;
	$recommended = null;
	foreach ($couriers as $courier) {
		if ($recommended_id && (string) ($courier['courier_company_id'] ?? '') === (string) $recommended_id) {
			$recommended = $courier;
			break;
		}
	}
	$estimated_delivery = $recommended['etd'] ?? $couriers[0]['etd'] ?? '';
	$message = 'Delivery is available to ' . $postcode . '.';
	if (!$product_id) {
		$message .= ' Final availability and shipping charges are confirmed at checkout.';
	}
	if ($estimated_delivery) {
		$message .= ' Estimated delivery: ' . sanitize_text_field($estimated_delivery) . '.';
	}
	wp_send_json_success(['message' => $message, 'available' => true]);
}

function ashtanga_render_account_prompt($class = '') {
	$class = trim('ashtanga-account-prompt ' . $class);
	if (is_user_logged_in()) {
		$user = wp_get_current_user();
		$name = $user->first_name ?: $user->display_name;
		return '<span class="' . esc_attr($class) . '" data-account-name="' . esc_attr($name) . '">' . esc_html(sprintf('Welcome back, %s!', $name)) . '</span>';
	}

	$account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
	return '<a class="' . esc_attr($class) . '" href="' . esc_url($account) . '">Sign in</a>';
}

add_shortcode('ashtanga_store_hero', function () {
	ob_start(); ?>
	<section class="ashtanga-hero"><div class="ashtanga-hero__inner"><p class="ashtanga-eyebrow">Beauty Blossomed By Nature</p><h1>Pure skincare, rooted in tradition.</h1><p>Explore Ayurvedic skincare and herbal care, thoughtfully created to bring nature’s goodness into your daily routine.</p><?php echo ashtanga_render_account_prompt('ashtanga-hero__account'); ?><a class="ashtanga-button" href="#skin-care">Shop our collection</a></div></section>
	<?php return ob_get_clean();
});

add_shortcode('ashtanga_store_header', function () {
	$logo = get_theme_file_uri('/assets/images/ashtanga-logo.svg');
	$shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
	$cart = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
	$account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
	$skin_care = get_term_link('skin-care', 'product_cat');
	$hair_care = get_term_link('hair-care', 'product_cat');
	$bath_body = get_term_link('bath-body', 'product_cat');
	$skin_care = is_wp_error($skin_care) ? $shop : $skin_care;
	$hair_care = is_wp_error($hair_care) ? $shop : $hair_care;
	$bath_body = is_wp_error($bath_body) ? $shop : $bath_body;
	$count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	ob_start(); ?>
	<header class="ashtanga-header"><div class="ashtanga-header__inner">
		<button class="ashtanga-menu-trigger" type="button" aria-label="Open store menu" aria-controls="ashtanga-store-menu" aria-expanded="false"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"></path></svg></button>
		<a class="ashtanga-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Ashtanga Ayurveda home"><img src="<?php echo esc_url($logo); ?>" alt="Ashtanga — Beauty Blossomed By Nature"></a>
		<form class="ashtanga-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
			<label class="screen-reader-text" for="ashtanga-search-field">Search products</label>
			<input id="ashtanga-search-field" type="search" name="s" placeholder="Search products" value="<?php echo esc_attr(get_search_query()); ?>">
			<input type="hidden" name="post_type" value="product"><button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg></button>
		</form>
		<nav class="ashtanga-nav" aria-label="Quick links"><a class="ashtanga-cart" href="<?php echo esc_url($cart); ?>" aria-label="Cart, <?php echo esc_attr($count); ?> items"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 1.9-1.4L22 8H6"></path><circle cx="10" cy="21" r="1"></circle><circle cx="18" cy="21" r="1"></circle></svg><span class="ashtanga-cart-count"><?php echo esc_html($count); ?></span></a></nav>
	</div></header>
	<dialog class="ashtanga-menu" id="ashtanga-store-menu" aria-labelledby="ashtanga-menu-title">
		<div class="ashtanga-menu__heading"><h2 id="ashtanga-menu-title">Menu</h2><button class="ashtanga-menu__close" type="button" aria-label="Close store menu"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"></path></svg></button></div>
		<div class="ashtanga-menu__account"><?php echo ashtanga_render_account_prompt('ashtanga-menu__account-prompt'); ?></div>
		<nav class="ashtanga-menu__nav" aria-label="Store menu">
			<a class="ashtanga-menu__link" href="<?php echo esc_url(home_url('/')); ?>">Home</a>
			<details class="ashtanga-menu__group"><summary>Shop</summary><div class="ashtanga-menu__submenu"><a href="<?php echo esc_url($shop); ?>">All products</a><a href="<?php echo esc_url($skin_care); ?>">Skin care</a><a href="<?php echo esc_url($hair_care); ?>">Hair care</a><a href="<?php echo esc_url($bath_body); ?>">Bath &amp; body</a></div></details>
			<a class="ashtanga-menu__link ashtanga-menu__cart" href="<?php echo esc_url($cart); ?>">Cart <span><?php echo esc_html($count); ?></span></a>
			<a class="ashtanga-menu__link" href="<?php echo esc_url(home_url('/about-ashtanga/')); ?>">Our story</a>
			<a class="ashtanga-menu__link" href="<?php echo esc_url(home_url('/#ashtanga-customer-care')); ?>">Contact us</a>
			<a class="ashtanga-menu__link" href="<?php echo esc_url($account); ?>">My account</a>
		</nav>
		<div class="ashtanga-menu__footer">
			<div class="ashtanga-menu__policies"><a href="<?php echo esc_url(home_url('/delivery-availability/')); ?>">Delivery availability</a><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>">Shipping policy</a><a href="<?php echo esc_url(home_url('/returns-refunds/')); ?>">Returns policy</a></div>
			<?php if (is_user_logged_in()) : ?>
				<a class="ashtanga-menu__logout" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Log out</a>
			<?php endif; ?>
		</div>
	</dialog>
	<?php return preg_replace('/>\s+</', '><', ob_get_clean());
});

add_shortcode('ashtanga_store_footer', function () {
	ob_start(); ?>
	<footer class="ashtanga-footer"><div class="ashtanga-footer__inner">
		<section><h2>Ashtanga Ayurveda</h2><p>Beauty Blossomed By Nature</p><p>Yethadka PO, Badiadka<br>Kasaragod, Kerala, India - 671552</p></section>
		<section id="ashtanga-customer-care"><h3>Customer care</h3><div class="ashtanga-footer__links"><a class="ashtanga-footer__email" href="mailto:ashtangaayurveda.in@gmail.com" aria-label="Email Ashtanga Ayurveda"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg><span>ashtangaayurveda.in@gmail.com</span></a><a class="ashtanga-footer__whatsapp" href="https://wa.me/919496675909" aria-label="Chat with Ashtanga Ayurveda on WhatsApp"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 11.7a8.6 8.6 0 0 1-12.7 7.5L3 20.5l1.3-4.6a8.6 8.6 0 1 1 16.2-4.2Z"></path><path d="M8.6 8.1c.2-.4.4-.4.7-.4h.5c.2 0 .4.1.5.4l.8 1.8c.1.2.1.4 0 .6l-.5.6c-.2.2-.2.4 0 .6.4.7 1 1.3 1.7 1.7.2.1.4.1.6-.1l.7-.8c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.3.4.5 0 .4-.2 1.2-.7 1.6-.5.5-1.2.7-1.8.6-1-.2-2.2-.8-3.5-1.9-1-.9-1.8-2.1-2-3.1-.2-.9.1-1.7.5-2.2Z"></path></svg><span>+91 94966 75909</span></a></div></section>
		<section><h3>Helpful links</h3><div class="ashtanga-footer__links"><a href="<?php echo esc_url(home_url('/delivery-availability/')); ?>">Delivery availability</a><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>">Shipping policy</a><a href="<?php echo esc_url(home_url('/returns-refunds/')); ?>">Returns policy</a><a href="<?php echo esc_url(home_url('/about-ashtanga/')); ?>">Our story</a></div></section>
	</div><div class="ashtanga-footer__bottom"><a class="ashtanga-footer__instagram" href="https://www.instagram.com/ashtangaayurveda.in/" aria-label="Follow us on Instagram">Follow us on <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle class="ashtanga-footer__instagram-dot" cx="18" cy="6" r="1"></circle></svg></a><span> · </span>© <?php echo esc_html(wp_date('Y')); ?> Ashtanga Ayurveda<span> · </span>Developed with &hearts; by <a class="ashtanga-footer__developer" href="https://thensk.in" target="_blank" rel="noopener noreferrer">NSK<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 4h6v6m0-6-9 9"></path><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6"></path></svg></a></div></footer>
	<?php return preg_replace('/>\s+</', '><', ob_get_clean());
});

add_filter('the_content', function ($content) {
	if (is_admin() || !is_singular('page')) {
		return $content;
	}

	$slug = get_post_field('post_name', get_queried_object_id());
	if ('about-ashtanga' === $slug) {
		return '<p>Ashtanga was inspired by Dr. Krishnapriya T’s experience finding relief from breakouts through Ayurvedic medicine. That experience led her to create herbal care rooted in traditional wisdom and thoughtfully made for modern routines.</p><p>Today, Ashtanga brings this heritage to skincare, hair care and bath and body, with products inspired by nature and Ayurveda.</p>';
	}

	if ('shipping-policy' === $slug && false !== strpos($content, 'Free shipping on all orders above')) {
		return '<p>We deliver to PIN codes served by our courier partners. Use the PIN code checker on the home or product page for an initial serviceability check.</p><p>Available shipping options and applicable charges are shown at checkout before payment. Final serviceability is confirmed when your order is processed. Orders are prepaid and are dispatched after payment is confirmed. Delivery estimates, when provided, may vary by destination and courier service.</p>';
	}

	if ('returns-refunds' === $slug) {
		return '<p>Orders are prepaid and are not eligible for change-of-mind returns or exchanges.</p><p>If an order arrives damaged or incorrect, contact <a href="mailto:ashtangaayurveda.in@gmail.com">ashtangaayurveda.in@gmail.com</a> within 24 hours of delivery. Include your order number and a clear, continuous video showing the unopened package and the unpacking of the item so we can review your report.</p><p>This policy does not limit any consumer rights or remedies that cannot legally be excluded.</p>';
	}

	return $content;
}, 20);

add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
	$count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	$cart = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
	$fragments['a.ashtanga-cart'] = '<a class="ashtanga-cart" href="' . esc_url($cart) . '" aria-label="' . esc_attr(sprintf(_n('Cart, %d item', 'Cart, %d items', $count, 'ashtanga-store'), $count)) . '"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 1.9-1.4L22 8H6"></path><circle cx="10" cy="21" r="1"></circle><circle cx="18" cy="21" r="1"></circle></svg><span class="ashtanga-cart-count">' . esc_html($count) . '</span></a>';
	return $fragments;
});

add_action('wp_footer', function () {
	?>
	<script>
	(() => {
		const greetingNodes = document.querySelectorAll('.ashtanga-account-prompt[data-account-name]');
		const hour = new Date().getHours();
		const greetings = hour >= 5 && hour < 12
			? ['Good morning, %s!', 'A lovely morning to you, %s!', 'Welcome back, %s!']
			: (hour >= 12 && hour < 16
				? ['Good afternoon, %s!', 'Lovely to see you, %s!', 'Welcome back, %s!']
				: (hour >= 16 && hour < 22
					? ['Good evening, %s!', 'Lovely to see you, %s!', 'Welcome back, %s!']
					: ['Hello, %s!', 'Lovely to see you, %s!', 'Welcome back, %s!']));
		const greeting = greetings[Math.floor(Math.random() * greetings.length)];
		greetingNodes.forEach(node => {
			node.textContent = greeting.replace('%s', node.dataset.accountName);
		});

		const trigger = document.querySelector('.ashtanga-menu-trigger');
		const menu = document.querySelector('#ashtanga-store-menu');
		const closeButton = menu?.querySelector('.ashtanga-menu__close');
		if (trigger && menu && typeof menu.showModal === 'function') {
			trigger.addEventListener('click', () => {
				menu.showModal();
				trigger.setAttribute('aria-expanded', 'true');
				trigger.setAttribute('aria-label', 'Close store menu');
			});
			closeButton?.addEventListener('click', () => menu.close());
			menu.addEventListener('cancel', event => {
				event.preventDefault();
				menu.close();
			});
			menu.addEventListener('keydown', event => {
				if (event.key === 'Escape') menu.close();
			});
			menu.addEventListener('click', event => {
				if (event.target === menu || event.target.closest('a')) menu.close();
			});
			menu.addEventListener('close', () => {
				trigger.setAttribute('aria-expanded', 'false');
				trigger.setAttribute('aria-label', 'Open store menu');
				trigger.focus();
			});
		}

		document.querySelectorAll('.ashtanga-delivery-checker__form').forEach(form => {
			const button = form.querySelector('button[type="submit"]');
			const checker = form.closest('.ashtanga-delivery-checker');
			const message = checker.querySelector('.ashtanga-delivery-checker__message');
			const messageText = message.querySelector('.ashtanga-delivery-checker__message-text');
			const dismissButton = message.querySelector('.ashtanga-delivery-checker__dismiss');
			const postcode = form.querySelector('[name="postcode"]');

			const hideMessage = () => {
				message.hidden = true;
				message.removeAttribute('data-state');
			};

			dismissButton.addEventListener('click', hideMessage);
			postcode.addEventListener('input', () => {
				hideMessage();
				button.removeAttribute('data-state');
				button.classList.remove('is-complete');
			});

			form.addEventListener('submit', async event => {
				event.preventDefault();
				const formData = new FormData(form);
				hideMessage();
				button.removeAttribute('data-state');
				button.classList.remove('is-complete');
				button.classList.add('is-checking');
				button.disabled = true;
				postcode.disabled = true;
				let state = 'error';
				let text = 'Unable to check now. Please try again.';
				try {
					const response = await fetch(form.getAttribute('action'), { method: 'POST', body: formData });
					const result = await response.json();
					text = result.data?.message || text;
					if (result.success && result.data?.available === true) {
						state = 'available';
					} else if (result.success && result.data?.available === false) {
						state = 'unavailable';
					}
				} catch (error) {
					text = 'Unable to check now. Please try again.';
				} finally {
					button.classList.remove('is-checking');
					button.disabled = false;
					postcode.disabled = false;
					button.dataset.state = state;
					button.classList.add('is-complete');
					message.dataset.state = state;
					messageText.textContent = text;
					message.hidden = false;
				}
			});
		});
	})();
	</script>
	<?php
}, 100);

add_shortcode('ashtanga_product_department', function ($attributes) {
	$attributes = shortcode_atts(['category' => '', 'title' => '', 'description' => ''], $attributes, 'ashtanga_product_department');
	$category = sanitize_title($attributes['category']);
	if (!$category || !function_exists('wc_get_products')) {
		return '';
	}
	$products = wc_get_products(['status' => 'publish', 'limit' => -1, 'category' => [$category], 'orderby' => 'menu_order title', 'order' => 'ASC']);
	if (!$products) {
		return '';
	}
	$previous_product = $GLOBALS['product'] ?? null;
	ob_start(); ?>
	<section class="ashtanga-section" id="<?php echo esc_attr($category); ?>"><div class="ashtanga-section__heading"><div><h2><?php echo esc_html($attributes['title']); ?></h2><p><?php echo esc_html($attributes['description']); ?></p></div><a href="<?php echo esc_url(get_term_link($category, 'product_cat')); ?>">View all</a></div><div class="ashtanga-products"><ul class="products columns-5">
	<?php foreach ($products as $product) : $GLOBALS['product'] = $product; ?>
		<li <?php wc_product_class('', $product); ?>><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo $product->get_image('woocommerce_thumbnail'); ?><?php if ($product->is_on_sale()) : ?><span class="onsale">Offer</span><?php endif; ?><h3 class="woocommerce-loop-product__title"><?php echo esc_html($product->get_name()); ?></h3></a><p class="ashtanga-size"><?php echo esc_html($product->get_attribute('Size') ?: get_post_meta($product->get_id(), '_ashtanga_size', true)); ?></p><span class="price"><?php echo wp_kses_post($product->get_price_html()); ?></span><?php woocommerce_template_loop_add_to_cart(['quantity' => 1]); ?></li>
	<?php endforeach; ?>
	</ul></div></section>
	<?php
	$GLOBALS['product'] = $previous_product;
	return preg_replace('/>\s+</', '><', ob_get_clean());
});