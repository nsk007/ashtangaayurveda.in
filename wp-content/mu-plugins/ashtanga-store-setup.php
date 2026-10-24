<?php
/**
 * Plugin Name: Ashtanga Store Setup
 * Description: Seeds Ashtanga's starter catalog and store pages once WooCommerce is ready.
 * Version: 1.0.0
 */

require_once __DIR__ . '/includes/class-ashtanga-email-otp.php';

add_action('admin_init', 'ashtanga_seed_store_once', 30);
add_action('init', 'ashtanga_update_brand_copy_once', 40);
add_action('init', 'ashtanga_update_checkout_copy_once', 41);
add_action('init', 'ashtanga_enforce_store_requirements', 30);
add_action('init', 'ashtanga_add_estimated_product_shipping_data', 40);
add_action('init', 'ashtanga_configure_shiprocket_shipping', 40);
add_action('woocommerce_checkout_process', 'ashtanga_require_authenticated_classic_checkout');
add_action('admin_notices', 'ashtanga_store_setup_notices');
add_filter('woocommerce_available_payment_gateways', 'ashtanga_prepaid_gateways_only');
add_filter('option_woocommerce_razorpay_settings', 'ashtanga_razorpay_settings_defaults');
add_filter('rest_request_before_callbacks', 'ashtanga_require_authenticated_checkout', 10, 3);

function ashtanga_local_checkout_testing_enabled() {
	return function_exists('wp_get_environment_type')
		&& 'local' === wp_get_environment_type()
		&& defined('ASHTANGA_LOCAL_CHECKOUT_TESTING')
		&& true === ASHTANGA_LOCAL_CHECKOUT_TESTING;
}

function ashtanga_update_brand_copy_once() {
	if (get_option('ashtanga_brand_copy_v2')) {
		return;
	}

	$replacements = [
		'Ashtanga Herbal Works' => 'Ashtanga Ayurveda',
		'About Ashtanga' => 'About Ashtanga Ayurveda',
		'Welcome to Ashtanga,' => 'Welcome to Ashtanga Ayurveda,',
		'support@ashtangabeauty.com' => 'support@ashtangaayurveda.in',
	];
	foreach (['about-ashtanga', 'returns-refunds'] as $slug) {
		$page = get_page_by_path($slug);
		if (!$page) {
			continue;
		}
		$title = str_replace(array_keys($replacements), array_values($replacements), $page->post_title);
		$content = str_replace(array_keys($replacements), array_values($replacements), $page->post_content);
		if ($title !== $page->post_title || $content !== $page->post_content) {
			wp_update_post(['ID' => $page->ID, 'post_title' => $title, 'post_content' => $content]);
		}
	}
	update_option('ashtanga_brand_copy_v2', gmdate('c'));
}

function ashtanga_update_checkout_copy_once() {
	if (get_option('ashtanga_checkout_copy_v1')) {
		return;
	}

	$page = get_page_by_path('shipping-policy');
	if ($page) {
		$old_copy = 'We accept UPI, Google Pay, PhonePe, credit and debit cards, net banking, and Cash on Delivery (COD), subject to availability at checkout.';
		$new_copy = 'Payment is accepted using the prepaid options shown at checkout. Cash on Delivery is not available.';
		$content = str_replace($old_copy, $new_copy, $page->post_content);
		if ($content !== $page->post_content) {
			wp_update_post(['ID' => $page->ID, 'post_content' => $content]);
		}
	}

	update_option('ashtanga_checkout_copy_v1', gmdate('c'));
}

function ashtanga_enforce_store_requirements() {
	if (!class_exists('WooCommerce')) {
		return;
	}

	$options = [
		'woocommerce_default_country' => 'IN:KL',
		'woocommerce_allowed_countries' => 'specific',
		'woocommerce_specific_allowed_countries' => ['IN'],
		'woocommerce_ship_to_countries' => 'specific',
		'woocommerce_specific_ship_to_countries' => ['IN'],
		'woocommerce_weight_unit' => 'kg',
		'woocommerce_dimension_unit' => 'cm',
		// Render checkout for the email sign-in form; checkout handlers still reject guests.
		'woocommerce_enable_guest_checkout' => 'yes',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_enable_myaccount_registration' => 'no',
		'woocommerce_enable_checkout_login_reminder' => 'no',
		'woocommerce_registration_generate_username' => 'yes',
		'woocommerce_registration_generate_password' => 'yes',
		'woocommerce_checkout_phone_field' => 'required',
		'woocommerce_ship_to_destination' => 'shipping',
	];
	foreach ($options as $name => $value) {
		if (get_option($name) !== $value) {
			update_option($name, $value);
		}
	}

	$current = get_option('woocommerce_cod_settings', []);
	$current = is_array($current) ? $current : [];
	$cod_enabled = ashtanga_local_checkout_testing_enabled() ? 'yes' : 'no';
	if ($cod_enabled !== ($current['enabled'] ?? 'no')) {
		$current['enabled'] = $cod_enabled;
		update_option('woocommerce_cod_settings', $current);
	}

	$razorpay = get_option('woocommerce_razorpay_settings', []);
	$razorpay = is_array($razorpay) ? $razorpay : [];
	$razorpay['description'] = $razorpay['description'] ?? '';
	$razorpay['enabled'] = !empty($razorpay['key_id']) && !empty($razorpay['key_secret']) ? 'yes' : 'no';
	if ($razorpay !== get_option('woocommerce_razorpay_settings', [])) {
		update_option('woocommerce_razorpay_settings', $razorpay);
	}
}

function ashtanga_prepaid_gateways_only($gateways) {
	$razorpay = get_option('woocommerce_razorpay_settings', []);
	if (!is_array($razorpay) || empty($razorpay['key_id']) || empty($razorpay['key_secret'])) {
		unset($gateways['razorpay']);
	}

	foreach (array_keys($gateways) as $gateway_id) {
		if ('razorpay' !== $gateway_id && !(ashtanga_local_checkout_testing_enabled() && 'cod' === $gateway_id)) {
			unset($gateways[$gateway_id]);
		}
	}
	return $gateways;
}

function ashtanga_razorpay_settings_defaults($settings) {
	if (!is_array($settings)) {
		return [];
	}
	if (!isset($settings['description'])) {
		$settings['description'] = '';
	}
	return $settings;
}

function ashtanga_require_authenticated_checkout($response, $handler, $request) {
	if (is_wp_error($response) || 'POST' !== $request->get_method() || '/wc/store/v1/checkout' !== $request->get_route()) {
		return $response;
	}

	function ashtanga_require_authenticated_classic_checkout() {
		if (!is_user_logged_in()) {
			wc_add_notice('Sign in with your email code before placing your order.', 'error');
		}
	}
	if (!is_user_logged_in()) {
		return new WP_Error('ashtanga_sign_in_required', 'Sign in with your email code before placing your order.', ['status' => 401]);
	}
	return $response;
}

function ashtanga_estimated_shipping_data($product_name, $size) {
	$size = strtolower((string) $size);
	$name = strtolower((string) $product_name);

	if (str_contains($name, 'body wash')) {
		return ['weight' => 0.65, 'length' => 22, 'width' => 8, 'height' => 8];
	}
	if (str_contains($size, '190gm')) {
		return ['weight' => 0.30, 'length' => 12, 'width' => 10, 'height' => 7];
	}
	if (str_contains($size, '100gm') || str_contains($size, '100ml')) {
		return ['weight' => 0.22, 'length' => 14, 'width' => 10, 'height' => 4];
	}
	if (str_contains($size, '80gm')) {
		return ['weight' => 0.20, 'length' => 10, 'width' => 8, 'height' => 4];
	}
	if (str_contains($size, '50gm') || str_contains($size, '50ml')) {
		return ['weight' => 0.15, 'length' => 8, 'width' => 6, 'height' => 6];
	}
	if (str_contains($size, '40gm')) {
		return ['weight' => 0.12, 'length' => 12, 'width' => 8, 'height' => 3];
	}
	if (str_contains($size, '30ml')) {
		return ['weight' => 0.10, 'length' => 10, 'width' => 5, 'height' => 5];
	}
	if (str_contains($size, '20gm')) {
		return ['weight' => 0.08, 'length' => 8, 'width' => 6, 'height' => 5];
	}
	if (str_contains($size, '8gm') || str_contains($size, '2.5gm')) {
		return ['weight' => 0.05, 'length' => 10, 'width' => 4, 'height' => 3];
	}
	if (str_contains($name, 'scrubber')) {
		return ['weight' => 0.15, 'length' => 20, 'width' => 15, 'height' => 5];
	}

	return ['weight' => 0.18, 'length' => 10, 'width' => 8, 'height' => 4];
}

function ashtanga_set_estimated_shipping_data($product, $size = '', $replace_existing = false) {
	$estimate = ashtanga_estimated_shipping_data($product->get_name(), $size);
	if ($replace_existing || !$product->get_weight()) {
		$product->set_weight(wc_get_weight($estimate['weight'], get_option('woocommerce_weight_unit', 'kg'), 'kg'));
	}
	if ($replace_existing || !$product->get_length()) {
		$product->set_length(wc_get_dimension($estimate['length'], get_option('woocommerce_dimension_unit', 'cm'), 'cm'));
	}
	if ($replace_existing || !$product->get_width()) {
		$product->set_width(wc_get_dimension($estimate['width'], get_option('woocommerce_dimension_unit', 'cm'), 'cm'));
	}
	if ($replace_existing || !$product->get_height()) {
		$product->set_height(wc_get_dimension($estimate['height'], get_option('woocommerce_dimension_unit', 'cm'), 'cm'));
	}
}

function ashtanga_add_estimated_product_shipping_data() {
	if (!function_exists('wc_get_products') || get_option('ashtanga_shipping_estimates_added_v3')) {
		return;
	}

	$replace_existing = (bool) (get_option('ashtanga_shipping_estimates_added') || get_option('ashtanga_shipping_estimates_added_v2'));
	$product_ids = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']);
	foreach ($product_ids as $product_id) {
		$product = wc_get_product($product_id);
		if (!$product) {
			continue;
		}
		ashtanga_set_estimated_shipping_data($product, $product->get_attribute('Size'), $replace_existing);
		$product->save();
	}

	update_option('ashtanga_shipping_estimates_added_v3', gmdate('c'));
}

function ashtanga_configure_shiprocket_shipping() {
	if (!class_exists('Shiprocket_Woocommerce_Shipping') || !class_exists('WC_Shipping_Zone')) {
		return;
	}

	$settings = get_option('woocommerce_shiprocket_woocommerce_shipping_settings', []);
	$settings = is_array($settings) ? $settings : [];
	$settings = array_merge($settings, [
		'integration_id' => $settings['integration_id'] ?? '',
		'realtime_enabled' => 'yes',
		'zone_wise_enabled' => 'yes',
		'shipping_title' => '',
		'fallback_rate' => '0',
		'shipping_title_flat' => '',
		'flat_rate' => '0',
	]);
	if ($settings !== get_option('woocommerce_shiprocket_woocommerce_shipping_settings', [])) {
		update_option('woocommerce_shiprocket_woocommerce_shipping_settings', $settings);
	}

	$zone = null;
	foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
		$candidate = new WC_Shipping_Zone((int) $zone_data['id']);
		foreach ($candidate->get_zone_locations() as $location) {
			if ('country' === $location->type && 'IN' === $location->code) {
				$zone = $candidate;
				break 2;
			}
		}
	}
	if (!$zone) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name('India');
		$zone->add_location('IN', 'country');
		$zone->save();
	}

	$shiprocket_method_found = false;
	foreach ($zone->get_shipping_methods() as $method) {
		if ('shiprocket_woocommerce_shipping' === $method->id) {
			$shiprocket_method_found = true;
		} elseif ('free_shipping' === $method->id) {
			$zone->delete_shipping_method($method->instance_id);
		}
	}
	if (!$shiprocket_method_found) {
		$zone->add_shipping_method('shiprocket_woocommerce_shipping');
	}

	ashtanga_configure_local_test_shipping($zone);
}

function ashtanga_configure_local_test_shipping($zone) {
	$instance_id = (int) get_option('ashtanga_local_test_flat_rate_instance_id', 0);
	if (!ashtanga_local_checkout_testing_enabled()) {
		if ($instance_id) {
			$zone->delete_shipping_method($instance_id);
			delete_option('ashtanga_local_test_flat_rate_instance_id');
			delete_option('woocommerce_flat_rate_' . $instance_id . '_settings');
		}
		return;
	}

	if (!$instance_id) {
		$instance_id = (int) $zone->add_shipping_method('flat_rate');
		if (!$instance_id) {
			return;
		}
		update_option('ashtanga_local_test_flat_rate_instance_id', $instance_id);
	}

	update_option('woocommerce_flat_rate_' . $instance_id . '_settings', [
		'title' => 'Local test shipping',
		'tax_status' => 'none',
		'cost' => '0',
		'type' => 'class',
	]);
}

function ashtanga_store_setup_notices() {
	if (!current_user_can('manage_woocommerce')) {
		return;
	}

	echo '<div class="notice notice-info"><p>Catalog shipping weights and dimensions are temporary estimates. Replace them with the packed parcel measurements before using live shipping rates.</p></div>';
	echo '<div class="notice notice-info"><p>Checkout is displayed before sign-in so customers can enter their email code. Order submission is blocked until they sign in; guest orders are not accepted.</p></div>';
	if (ashtanga_local_checkout_testing_enabled()) {
		echo '<div class="notice notice-warning"><p>Local checkout testing is enabled: Cash on Delivery and free test shipping are available. This test setting is restricted to the local environment.</p></div>';
	}

	$razorpay = get_option('woocommerce_razorpay_settings', []);
	if (!is_array($razorpay) || empty($razorpay['key_id']) || empty($razorpay['key_secret'])) {
		echo '<div class="notice notice-warning"><p>Online checkout is locked until Razorpay is configured. Add your Razorpay Key ID and Key Secret under WooCommerce &rarr; Settings &rarr; Payments &rarr; Razorpay.</p></div>';
	}

	$settings = get_option('woocommerce_shiprocket_woocommerce_shipping_settings', []);
	if (!is_array($settings) || empty($settings['integration_id'])) {
		echo '<div class="notice notice-warning"><p>Shiprocket delivery estimates require a connected Shiprocket account. Configure it under WooCommerce &rarr; Settings &rarr; Shipping &rarr; Shiprocket App Configuration.</p></div>';
	}
}

function ashtanga_seed_store_once() {
	if (get_option('ashtanga_store_seeded') || !function_exists('wc_get_product')) {
		return;
	}

	switch_theme('ashtanga-store');

	$catalog = [
		'skin-care' => [
			['Aloevera Gel', '50gm', 150, 120, 'The secret to radiant skin. Moisturizes skin and scalp, fights acne, delays the signs of aging, gives glowing skin, prevents hair fall, and is great for dry, frizzy hair.', 'aloevera-gel'],
			['Kesar Gel', '50gm', 300, 260, 'Saffron-infused skincare miracle. Hydrates skin, gives radiant glow, lightens dark pigmentation, repairs skin damage, and brightens skin tone.', 'kesar-gel'],
			['Golden Oil', '30ml', 180, 150, 'Unveil your skin’s royal glow. Brightens dull skin, reduces pigmentation, improves radiance, and enhances natural glow.', 'golden-oil'],
			['Anti-Acne Toner', '50ml', 170, 140, 'Vanish blemishes with every tone. Fights acne and blemishes, is good for oily skin, balances skin pH, removes dead skin, and minimizes open pores.', 'anti-acne-toner'],
			['Aloevera Toner', '50ml', 160, 130, 'For a soothing sensation. Removes dead skin, cleanses skin, minimizes open pores, moisturizes, refreshes, and balances skin pH.', 'aloevera-toner'],
			['Under Eye Cream', '20gm', 180, 150, 'Reduces dark circles, puffiness, and fine lines around the delicate eye area with natural herbal extracts.', 'aloevera-gel'],
			['Eladi Moisturizing Cream', '50gm', 250, 200, 'Deeply nourishes and protects dry skin, improving texture and restoring natural elasticity.', 'kesar-gel'],
			['Beetroot Lip Balm', '8gm', 120, 100, 'Nourishes the lips, reduces pigmentation, hydrates, makes lips soft and supple, and protects from drying.', 'beetroot-lip-balm'],
			['Herbal Kajal Stick', '2.5gm', 290, 250, 'Traditional soothing herbal kajal that cools eyes and gives a rich, dark finish.', 'charcoal-soap'],
			['Foot Butter', '190gm', 220, 190, 'An intense hydration formula to heal cracked heels and soothe tired feet.', 'aloevera-gel'],
		],
		'hair-care' => [
			['Hair Wash Powder', '100gm', 180, 150, 'Revitalize your hair roots. Cleanses the scalp, prevents scalp itching, strengthens hair roots, and gives smooth, voluminous hair.', 'hair-wash-powder'],
			['Anti-Dandruff Pack', '100gm', 190, 160, 'Your scalp’s best friend. Helps free the scalp from dandruff, keeps hair soft and healthy, relieves an itchy scalp, and nourishes roots.', 'anti-dandruff-pack'],
			['Hair Dye', '40gm', 170, 140, 'Herbal black hair dye with fast action, easy application, and a voluminous finish.', 'hair-dye'],
			['Hibiscus Hair Gel', '50gm', 190, 160, 'Makes hair healthy and lustrous, helps stop hair loss, treats dandruff, adds volume, and conditions against frizz.', 'hibiscus-hair-gel'],
			['Anti-Dandruff Shampoo', '100ml', 220, 190, 'Gentle herbal formulation to clear stubborn flakes while maintaining natural moisture balance.', 'anti-dandruff-pack'],
		],
		'bath-body' => [
			['Hair Removal Powder', '100gm', 190, 160, 'Pain-free, instant hair removal with no irritation or rashes, and added tan removal benefits.', 'hair-removal-powder'],
			['Herbal Bath Scrubber / Incha', '', 70, 50, 'Gateway to a traditional, eco-friendly cleansing experience. 100% natural for gentle exfoliation; removes dead cells and gives soft, youthful skin.', 'bath-scrubber'],
			['Nalpamaradi Soap', '80gm', 80, 65, 'Traditional skin-illuminating herbal soap for clear, glowing skin.', 'charcoal-soap'],
			['Manjishta Soap', '80gm', 80, 65, 'Purifying soap known for clearing blemishes and evening out skin tone.', 'charcoal-soap'],
			['Neem Tulsi Soap', '80gm', 80, 65, 'Antibacterial protection with natural neem and tulsi extracts to keep skin fresh and germ-free.', 'charcoal-soap'],
			['Charcoal Soap', '80gm', 80, 65, 'Deep clean and detox. Absorbs dirt, oil, and impurities, unclogs pores, and reduces breakouts.', 'charcoal-soap'],
			['Baby Soap', '', 90, 70, 'Ultra-gentle, tear-free, moisturizing herbal soap formulated specifically for delicate baby skin.', 'charcoal-soap'],
			['Manjishta Body Wash', '', 400, 350, 'Luxurious herbal body wash that deeply cleanses, hydrates, and imparts a natural glow.', 'charcoal-soap'],
		],
	];

	$uploads = wp_upload_dir();
	foreach ($catalog as $category_slug => $products) {
		$category_name = $category_slug === 'skin-care' ? 'Skin Care & Gels' : ($category_slug === 'hair-care' ? 'Hair Care' : 'Bath & Body Essentials');
		$term = term_exists($category_slug, 'product_cat');
		if (!$term) {
			$term = wp_insert_term($category_name, 'product_cat', ['slug' => $category_slug]);
		}
		$term_id = is_wp_error($term) ? 0 : (int) (is_array($term) ? $term['term_id'] : $term);

		foreach ($products as $item) {
			[$name, $size, $regular_price, $sale_price, $description, $image_slug] = $item;
			$existing = get_posts(['post_type' => 'product', 'post_status' => 'any', 'title' => $name, 'numberposts' => 1, 'fields' => 'ids']);
			if ($existing) {
				continue;
			}

			$product = new WC_Product_Simple();
			$product->set_name($name);
			$product->set_status('publish');
			$product->set_catalog_visibility('visible');
			$product->set_description($description);
			$product->set_regular_price((string) $regular_price);
			$product->set_sale_price((string) $sale_price);
			$product->set_category_ids($term_id ? [$term_id] : []);
			$product->set_sku('ASHT-' . strtoupper(substr(md5($category_slug . $name), 0, 8)));
			$product->set_stock_status('instock');
			ashtanga_set_estimated_shipping_data($product, $size);
			if ($size !== '') {
				$size_attribute = new WC_Product_Attribute();
				$size_attribute->set_name('Size');
				$size_attribute->set_options([$size]);
				$size_attribute->set_position(0);
				$size_attribute->set_visible(true);
				$size_attribute->set_variation(false);
				$product->set_attributes([$size_attribute]);
			}
			$product_id = $product->save();
			update_post_meta($product_id, '_ashtanga_size', $size);

			$image_path = $uploads['basedir'] . '/ashtanga-products/' . sanitize_file_name($image_slug) . '.jpg';
			if (file_exists($image_path)) {
				$attachment_id = ashtanga_import_product_image($image_path, $product_id, $name);
				if ($attachment_id) {
					$product->set_image_id($attachment_id);
					$product->save();
				}
			}
		}
	}

	$pages = [
		'ashtanga-home' => ['Ashtanga | Natural Herbal Care', ''],
		'about-ashtanga' => ['About Ashtanga Ayurveda', '<p>Welcome to Ashtanga Ayurveda, where ancient herbal traditions meet modern self-care. We craft natural cosmetics, hair care, and skincare essentials designed to nourish your body and soul. Every product is carefully formulated using botanical extracts, herbs, and traditional wisdom.</p><h2>Our mission</h2><p>To bring the healing touch of nature directly to your daily routine with safe, sustainable, and effective herbal solutions.</p><p><strong>Ashtanga Ayurveda</strong><br>Nature Valley Road, Kerala, India - 671551</p>'],
		'shipping-policy' => ['Shipping Policy', '<p>Free shipping on all orders above ₹499. Standard delivery takes 3–5 business days via trusted courier partners. Delivery times may vary by destination and local conditions.</p><h2>Payment methods</h2><p>Payment is accepted using the prepaid options shown at checkout. Cash on Delivery is not available.</p>'],
		'returns-refunds' => ['Returns Policy', '<p>Orders are prepaid and are not eligible for change-of-mind returns or exchanges.</p><p>If an order arrives damaged or incorrect, email <a href="mailto:support@ashtangaayurveda.in">support@ashtangaayurveda.in</a> with your order number and details so we can review it. This policy does not limit any consumer rights or remedies that cannot legally be excluded.</p>'],
	];
	$page_ids = [];
	foreach ($pages as $slug => $page_data) {
		$page = get_page_by_path($slug);
		$page_id = $page ? $page->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page_data[0], 'post_name' => $slug, 'post_content' => $page_data[1]]);
		if (!is_wp_error($page_id)) {
			$page_ids[$slug] = (int) $page_id;
		}
	}
	if (isset($page_ids['ashtanga-home'])) {
		update_option('show_on_front', 'page');
		update_option('page_on_front', $page_ids['ashtanga-home']);
	}
	update_option('woocommerce_currency', 'INR');
	update_option('ashtanga_store_seeded', gmdate('c'));
}

function ashtanga_update_returns_policy_once() {
	if (get_option('ashtanga_returns_policy_v1')) {
		return;
	}

	$page = get_page_by_path('returns-refunds');
	if ($page) {
		wp_update_post([
			'ID' => $page->ID,
			'post_title' => 'Returns Policy',
			'post_content' => '<p>Orders are prepaid and are not eligible for change-of-mind returns or exchanges.</p><p>If an order arrives damaged or incorrect, email <a href="mailto:support@ashtangaayurveda.in">support@ashtangaayurveda.in</a> with your order number and details so we can review it. This policy does not limit any consumer rights or remedies that cannot legally be excluded.</p>',
		]);
	}
	update_option('ashtanga_returns_policy_v1', gmdate('c'));
}

add_action('init', 'ashtanga_update_returns_policy_once', 40);

function ashtanga_import_product_image($image_path, $product_id, $description) {
	$hash = md5_file($image_path);
	$existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_ashtanga_asset_hash', 'meta_value' => $hash, 'numberposts' => 1, 'fields' => 'ids']);
	if ($existing) {
		return (int) $existing[0];
	}

	$filename = basename($image_path);
	$upload = wp_upload_bits($filename, null, file_get_contents($image_path));
	if (!empty($upload['error'])) {
		return 0;
	}
	$filetype = wp_check_filetype($filename, null);
	$attachment_id = wp_insert_attachment(['post_mime_type' => $filetype['type'], 'post_title' => sanitize_text_field($description), 'post_status' => 'inherit'], $upload['file'], $product_id);
	if (is_wp_error($attachment_id)) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
	wp_update_attachment_metadata($attachment_id, $metadata);
	update_post_meta($attachment_id, '_ashtanga_asset_hash', $hash);
	return (int) $attachment_id;
}