<?php
/**
 * Plugin Name: Ashtanga Store Setup
 * Description: Seeds Ashtanga's starter catalog and store pages once WooCommerce is ready.
 * Version: 1.0.0
 */

add_action('admin_init', 'ashtanga_seed_store_once', 30);
add_action('init', 'ashtanga_update_brand_copy_once', 40);
add_action('init', 'ashtanga_enable_cod_once', 30);

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

function ashtanga_enable_cod_once() {
	if (get_option('ashtanga_cod_configured') || !function_exists('WC')) {
		return;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	if (!isset($gateways['cod'])) {
		return;
	}
	$current = get_option('woocommerce_cod_settings', []);
	$settings = array_merge(is_array($current) ? $current : [], [
		'enabled' => 'yes',
		'title' => 'Cash on delivery',
		'description' => 'Pay when your order is delivered.',
		'instructions' => 'Please pay the delivery partner when your order arrives.',
		'enable_for_methods' => [],
		'enable_for_virtual' => 'no',
	]);
	update_option('woocommerce_cod_settings', $settings);
	update_option('ashtanga_cod_configured', gmdate('c'));
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
		'shipping-policy' => ['Shipping Policy', '<p>Free shipping on all orders above ₹499. Standard delivery takes 3–5 business days via trusted courier partners. Delivery times may vary by destination and local conditions.</p><h2>Payment methods</h2><p>We accept UPI, Google Pay, PhonePe, credit and debit cards, net banking, and Cash on Delivery (COD), subject to availability at checkout.</p>'],
		'returns-refunds' => ['Returns & Refunds', '<p>Our 7-day easy return policy applies to items received damaged or incorrect. Please contact customer care within 7 days of delivery with your order number and photos of the item.</p><p>Items must be unopened and unused in their original packaging to be eligible for exchange. Once a return is approved and inspected, we will confirm the available resolution.</p><h2>Contact customer care</h2><p><a href="mailto:support@ashtangaayurveda.in">support@ashtangaayurveda.in</a><br><a href="tel:+919876543210">+91 98765 43210</a></p>'],
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
	ashtanga_configure_free_shipping();
	update_option('ashtanga_store_seeded', gmdate('c'));
}

function ashtanga_configure_free_shipping() {
	if (!class_exists('WC_Shipping_Zone')) {
		return;
	}

	$zone = null;
	foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
		$candidate = new WC_Shipping_Zone((int) $zone_data['id']);
		foreach ($candidate->get_zone_locations() as $location) {
			if ($location->type === 'country' && $location->code === 'IN') {
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

	$instance_id = 0;
	foreach ($zone->get_shipping_methods(false) as $method) {
		if ($method->id === 'free_shipping') {
			$instance_id = (int) $method->instance_id;
			break;
		}
	}
	if (!$instance_id) {
		$instance_id = (int) $zone->add_shipping_method('free_shipping');
	}
	if ($instance_id) {
		update_option('woocommerce_free_shipping_' . $instance_id . '_settings', [
			'title' => 'Free shipping on orders over ₹499',
			'requires' => 'min_amount',
			'min_amount' => '499',
			'ignore_discounts' => 'no',
		]);
	}
}

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