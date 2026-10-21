<?php
add_action('wp_enqueue_scripts', function () {
	wp_enqueue_style('ashtanga-store', get_stylesheet_uri(), [], '1.0.0');
});

add_action('after_setup_theme', function () {
	add_theme_support('woocommerce');
	add_theme_support('wc-product-gallery-zoom');
	add_theme_support('wc-product-gallery-lightbox');
	add_theme_support('wc-product-gallery-slider');
	add_theme_support('title-tag');
	add_theme_support('post-thumbnails');
});

add_shortcode('ashtanga_store_header', function () {
	$logo = get_theme_file_uri('/assets/images/ashtanga-logo.png');
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
		<a class="ashtanga-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Ashtanga Ayurveda home"><img src="<?php echo esc_url($logo); ?>" alt="Ashtanga Ayurveda"></a>
		<form class="ashtanga-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
			<label class="screen-reader-text" for="ashtanga-search-field">Search products</label>
			<input id="ashtanga-search-field" type="search" name="s" placeholder="Search products" value="<?php echo esc_attr(get_search_query()); ?>">
			<input type="hidden" name="post_type" value="product"><button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg></button>
		</form>
		<nav class="ashtanga-nav" aria-label="Quick links"><a class="ashtanga-cart" href="<?php echo esc_url($cart); ?>" aria-label="Cart, <?php echo esc_attr($count); ?> items"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 1.9-1.4L22 8H6"></path><circle cx="10" cy="21" r="1"></circle><circle cx="18" cy="21" r="1"></circle></svg><span class="ashtanga-cart-count"><?php echo esc_html($count); ?></span></a></nav>
	</div></header>
	<dialog class="ashtanga-menu" id="ashtanga-store-menu" aria-labelledby="ashtanga-menu-title">
		<div class="ashtanga-menu__heading"><h2 id="ashtanga-menu-title">Browse Ashtanga</h2><button class="ashtanga-menu__close" type="button" aria-label="Close store menu"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"></path></svg></button></div>
		<nav class="ashtanga-menu__nav" aria-label="Store menu">
			<a class="ashtanga-menu__link" href="<?php echo esc_url(home_url('/')); ?>">Home</a>
			<details class="ashtanga-menu__group"><summary>Shop</summary><div class="ashtanga-menu__submenu"><a href="<?php echo esc_url($shop); ?>">All products</a><a href="<?php echo esc_url($skin_care); ?>">Skin Care</a><a href="<?php echo esc_url($hair_care); ?>">Hair Care</a><a href="<?php echo esc_url($bath_body); ?>">Bath &amp; Body</a></div></details>
			<a class="ashtanga-menu__link ashtanga-menu__cart" href="<?php echo esc_url($cart); ?>">Cart <span><?php echo esc_html($count); ?></span></a>
			<a class="ashtanga-menu__link" href="<?php echo esc_url(home_url('/about-ashtanga/')); ?>">About Ashtanga Ayurveda</a>
			<details class="ashtanga-menu__group"><summary>Contact</summary><div class="ashtanga-menu__submenu"><a href="tel:+919876543210">Call +91 98765 43210</a><a href="https://wa.me/919876543210">WhatsApp</a><a href="mailto:support@ashtangaayurveda.in">Email customer care</a></div></details>
			<a class="ashtanga-menu__link" href="<?php echo esc_url($account); ?>">My account &amp; orders</a>
		</nav>
		<div class="ashtanga-menu__policies"><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>">Shipping</a><a href="<?php echo esc_url(home_url('/returns-refunds/')); ?>">Returns</a></div>
	</dialog>
	<?php return preg_replace('/>\s+</', '><', ob_get_clean());
});

add_shortcode('ashtanga_store_footer', function () {
	ob_start(); ?>
	<footer class="ashtanga-footer"><div class="ashtanga-footer__inner">
		<section><h2>Ashtanga Ayurveda</h2><p>Pure, natural, and timeless care rooted in nature.</p><p>Ashtanga Ayurveda<br>Nature Valley Road, Kerala, India - 671551</p></section>
		<section><h3>Customer care</h3><div class="ashtanga-footer__links"><a href="tel:+919876543210">+91 98765 43210</a><a href="https://wa.me/919876543210">WhatsApp support</a><a href="mailto:support@ashtangaayurveda.in">support@ashtangaayurveda.in</a><a href="https://instagram.com/ashtanga_herbal_official">@ashtanga_herbal_official</a></div></section>
		<section><h3>Helpful links</h3><div class="ashtanga-footer__links"><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>">Shipping policy</a><a href="<?php echo esc_url(home_url('/returns-refunds/')); ?>">Returns &amp; refunds</a><a href="<?php echo esc_url(home_url('/about-ashtanga/')); ?>">About Ashtanga Ayurveda</a></div></section>
	</div><div class="ashtanga-footer__bottom">© <?php echo esc_html(wp_date('Y')); ?> Ashtanga Ayurveda</div></footer>
	<?php return preg_replace('/>\s+</', '><', ob_get_clean());
});

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
		const trigger = document.querySelector('.ashtanga-menu-trigger');
		const menu = document.querySelector('#ashtanga-store-menu');
		const closeButton = menu?.querySelector('.ashtanga-menu__close');
		if (!trigger || !menu || typeof menu.showModal !== 'function') return;

		trigger.addEventListener('click', () => {
			menu.showModal();
			trigger.setAttribute('aria-expanded', 'true');
			trigger.setAttribute('aria-label', 'Close store menu');
		});
		closeButton?.addEventListener('click', () => menu.close());
		menu.addEventListener('cancel', event => {
			e.preventDefault();
			menu.close();
		});
		menu.addEventListener('keydown', event => {
			if (event.key === 'Escape') menu.close();
		});
		menu.addEventListener('click', event => {
			if (event.target === menu) menu.close();
		});
		menu.addEventListener('close', () => {
			trigger.setAttribute('aria-expanded', 'false');
			trigger.setAttribute('aria-label', 'Open store menu');
			trigger.focus();
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