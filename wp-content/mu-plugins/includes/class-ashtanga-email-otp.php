<?php

final class Ashtanga_Email_OTP {
	private const OTP_TTL = 10 * MINUTE_IN_SECONDS;
	private const MAX_ATTEMPTS = 5;
	private const RESEND_DELAY = MINUTE_IN_SECONDS;

	public static function boot() {
		add_action('wp_ajax_nopriv_ashtanga_send_email_otp', [self::class, 'send_code']);
		add_action('wp_ajax_ashtanga_send_email_otp', [self::class, 'send_code']);
		add_action('wp_ajax_nopriv_ashtanga_verify_email_otp', [self::class, 'verify_code_request']);
		add_action('wp_ajax_ashtanga_verify_email_otp', [self::class, 'verify_code_request']);
		add_action('phpmailer_init', [self::class, 'configure_smtp']);
		add_action('template_redirect', [self::class, 'start_auth_session'], 1);
		add_action('wp_footer', [self::class, 'render_checkout_script'], 50);
	}

	public static function configure_smtp($mailer) {
		if (!defined('ASHTANGA_GMAIL_SMTP_USERNAME') || !defined('ASHTANGA_GMAIL_SMTP_APP_PASSWORD')) {
			return;
		}

		$mailer->isSMTP();
		$mailer->Host = 'smtp.gmail.com';
		$mailer->SMTPAuth = true;
		$mailer->Username = ASHTANGA_GMAIL_SMTP_USERNAME;
		$mailer->Password = ASHTANGA_GMAIL_SMTP_APP_PASSWORD;
		$mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		$mailer->Port = 587;
		$mailer->SMTPAutoTLS = true;
		$mailer->Timeout = 15;
		$mailer->SMTPKeepAlive = false;
		$mailer->setFrom('ashtangaayurveda.in@gmail.com', 'Ashtanga Ayurveda');
	}

	public static function send_code() {
		if (!check_ajax_referer('ashtanga_email_otp', 'nonce', false)) {
			wp_send_json_error(['message' => 'This page has expired. Refresh and try again.'], 403);
		}

		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		if (!is_email($email)) {
			wp_send_json_error(['message' => 'Enter a valid email address.'], 400);
		}

		$session_id = self::current_session_id();
		if (!$session_id) {
			wp_send_json_error(['message' => 'We could not start sign-in. Refresh the page and try again.'], 503);
		}

		$email_key = self::key('email', strtolower($email));
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		$ip_key = self::key('ip', $ip);
		$email_count = (int) get_transient($email_key . '_count');
		$ip_count = (int) get_transient($ip_key . '_count');
		if (get_transient($email_key . '_cooldown') || $email_count >= 5 || $ip_count >= 20) {
			wp_send_json_error(['message' => 'Please wait a moment before requesting another code.'], 429);
		}

		set_transient($email_key . '_count', $email_count + 1, 15 * MINUTE_IN_SECONDS);
		set_transient($ip_key . '_count', $ip_count + 1, 15 * MINUTE_IN_SECONDS);
		set_transient($email_key . '_cooldown', 1, self::RESEND_DELAY);

		$code = (string) random_int(100000, 999999);
		$code_key = self::code_key($email, $session_id);
		set_transient($code_key, [
			'hash' => wp_hash_password($code),
			'attempts' => 0,
			'expires_at' => time() + self::OTP_TTL,
		], self::OTP_TTL);

		add_filter('wp_mail_from', [self::class, 'mail_from']);
		add_filter('wp_mail_from_name', [self::class, 'mail_from_name']);
		$sent = wp_mail(
			$email,
			'Your Ashtanga Ayurveda sign-in code',
			"Use this code to sign in or create your account: {$code}.\n\nIt expires in 10 minutes. If you did not request this code, you can ignore this email."
		);
		remove_filter('wp_mail_from', [self::class, 'mail_from']);
		remove_filter('wp_mail_from_name', [self::class, 'mail_from_name']);

		if (!$sent) {
			delete_transient($code_key);
			wp_send_json_error(['message' => 'We could not send your code. Please try again shortly.'], 503);
		}

		wp_send_json_success(['message' => 'A 6-digit code has been sent to your email. It expires in 10 minutes.']);
	}

	public static function verify_code_request() {
		if (!check_ajax_referer('ashtanga_email_otp', 'nonce', false)) {
			wp_send_json_error(['message' => 'This page has expired. Refresh and try again.'], 403);
		}

		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		$code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
		if (!is_email($email)) {
			wp_send_json_error(['message' => 'Enter a valid email address.'], 400);
		}
		if (!preg_match('/^[0-9]{6}$/', $code)) {
			wp_send_json_error(['message' => 'Enter the 6-digit code from your email.'], 400);
		}

		$session_id = self::current_session_id();
		$code_key = self::code_key($email, $session_id);
		if (!$session_id || !self::verify_code($code_key, $code)) {
			wp_send_json_error(['message' => 'That code is incorrect or expired. Check it or request a new one.'], 400);
		}

		$user_id = email_exists($email);
		if (!$user_id) {
			$user_id = self::create_customer($email);
			if (is_wp_error($user_id)) {
				if (email_exists($email)) {
					$user_id = email_exists($email);
				} else {
					wp_send_json_error(['message' => 'We could not create your account. Please try again.'], 500);
				}
			}
		}

		$user = get_user_by('id', $user_id);
		if (!$user || !self::is_customer_account($user)) {
			delete_transient($code_key);
			wp_send_json_error(['message' => 'This email cannot be used for store sign-in. Please contact customer care.'], 403);
		}

		delete_transient($code_key);
		wp_set_current_user($user->ID);
		wp_set_auth_cookie($user->ID, false, is_ssl());
		do_action('wp_login', $user->user_login, $user);
		wp_send_json_success(['message' => 'Email verified. Signing you in…']);
	}

	public static function mail_from() {
		return 'ashtangaayurveda.in@gmail.com';
	}

	public static function mail_from_name() {
		return 'Ashtanga Ayurveda';
	}

	public static function start_auth_session() {
		$is_checkout = function_exists('is_checkout') && is_checkout();
		$is_account_page = function_exists('is_account_page') && is_account_page();
		if (($is_checkout || $is_account_page) && !is_user_logged_in()) {
			self::current_session_id();
		}
	}

	private static function current_session_id() {
		$woocommerce = function_exists('WC') ? WC() : null;
		if (!$woocommerce || !$woocommerce->session) {
			return '';
		}

		$session_id = (string) $woocommerce->session->get_customer_id();
		if ($session_id) {
			$woocommerce->session->set_customer_session_cookie(true);
		}
		return $session_id;
	}

	private static function code_key($email, $session_id) {
		return self::key('code', strtolower($email) . ':' . $session_id);
	}

	private static function create_customer($email) {
		$local_part = strstr($email, '@', true);
		$username = sanitize_user((string) $local_part, true);
		$username = substr($username ?: 'customer', 0, 45);
		$base_username = $username;
		$suffix = 1;
		while (username_exists($username)) {
			$username = $base_username . $suffix;
			$suffix++;
		}

		return wp_insert_user([
			'user_login' => $username,
			'user_pass' => wp_generate_password(40, true, true),
			'user_email' => $email,
			'display_name' => $local_part ?: $username,
			'role' => 'customer',
		]);
	}

	private static function is_customer_account($user) {
		if (!$user instanceof WP_User || user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce')) {
			return false;
		}

		return (bool) array_intersect(['customer', 'subscriber'], (array) $user->roles);
	}

	private static function verify_code($key, $code) {
		$record = get_transient($key);
		if (!is_array($record) || empty($record['hash']) || (int) ($record['expires_at'] ?? 0) < time()) {
			delete_transient($key);
			return false;
		}

		if (wp_check_password($code, $record['hash'])) {
			return true;
		}

		$record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
		if ($record['attempts'] >= self::MAX_ATTEMPTS) {
			delete_transient($key);
		} else {
			set_transient($key, $record, max(1, (int) $record['expires_at'] - time()));
		}
		return false;
	}

	private static function key($type, $value) {
		return 'ashtanga_email_otp_' . $type . '_' . hash_hmac('sha256', $value, wp_salt('auth'));
	}

	public static function render_checkout_script() {
		$is_checkout = function_exists('is_checkout') && is_checkout();
		$is_account_page = function_exists('is_account_page') && is_account_page();
		if ((!$is_checkout && !$is_account_page) || ($is_checkout && is_order_received_page()) || is_user_logged_in()) {
			return;
		}

		$config = wp_json_encode([
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('ashtanga_email_otp'),
			'accountPage' => $is_account_page,
		]);
		?>
		<script>
		(() => {
			const config = <?php echo $config; ?>;
			const installButton = () => {
				const loginForm = config.accountPage ? document.querySelector('.woocommerce-form-login') : null;
				const email = config.accountPage ? loginForm?.querySelector('#username') : document.querySelector('#email, #billing_email');
				if (!email) return;
				const field = config.accountPage ? email.closest('.form-row') : email.closest('.wc-block-components-text-input, .form-row');
				const target = config.accountPage ? loginForm : field;
				if (!field || !target || target.querySelector('.ashtanga-email-otp-button')) return;

				email.type = 'email';
				email.autocomplete = 'email';
				if (config.accountPage) {
					const emailLabel = loginForm.querySelector('label[for="username"]');
					if (emailLabel?.firstChild) emailLabel.firstChild.textContent = 'Email address ';
					const passwordField = loginForm.querySelector('#password')?.closest('.form-row');
					const submitRow = loginForm.querySelector('button[name="login"]')?.closest('p');
					const lostPassword = loginForm.querySelector('.lost_password');
					if (passwordField) passwordField.hidden = true;
					if (submitRow) submitRow.hidden = true;
					if (lostPassword) lostPassword.hidden = true;
					loginForm.addEventListener('submit', event => event.preventDefault());
				}

				const controls = document.createElement('div');
				controls.className = 'ashtanga-email-otp';
				const instructions = document.createElement('div');
				instructions.className = 'ashtanga-email-otp-help';
				instructions.textContent = 'Sign in or create your account with a code sent to your email.';
				const code = document.createElement('input');
				code.type = 'text';
				code.inputMode = 'numeric';
				code.autocomplete = 'one-time-code';
				code.maxLength = 6;
				code.placeholder = '6-digit code';
				code.setAttribute('aria-label', '6-digit sign-in code');
				code.hidden = true;
				const sendButton = document.createElement('button');
				sendButton.type = 'button';
				sendButton.className = 'ashtanga-email-otp-button';
				sendButton.textContent = 'Email me a sign-in code';
				const verifyButton = document.createElement('button');
				verifyButton.type = 'button';
				verifyButton.className = 'ashtanga-email-otp-button';
				verifyButton.textContent = 'Sign in';
				verifyButton.hidden = true;
				const status = document.createElement('div');
				status.className = 'ashtanga-email-otp-status';
				status.id = 'ashtanga-email-otp-status';
				status.setAttribute('role', 'status');
				status.setAttribute('aria-live', 'polite');
				code.setAttribute('aria-describedby', status.id);
				let resendTimer = null;
				controls.append(sendButton, code, verifyButton);
				target.append(instructions, controls, status);

				const validEmail = () => {
					if (!email.value.trim() || !email.checkValidity()) {
						status.textContent = 'Enter a valid email address.';
						status.dataset.state = 'error';
						email.focus();
						return false;
					}
					return true;
				};

				const setStatus = (message, state = '') => {
					status.textContent = message;
					if (state) {
						status.dataset.state = state;
					} else {
						delete status.dataset.state;
					}
				};

				const resetCode = () => {
					if (resendTimer) window.clearInterval(resendTimer);
					resendTimer = null;
					code.value = '';
					code.disabled = false;
					code.hidden = true;
					verifyButton.hidden = true;
					verifyButton.disabled = false;
					verifyButton.textContent = 'Sign in';
					sendButton.disabled = false;
					sendButton.textContent = 'Email me a sign-in code';
					setStatus('');
				};

				email.addEventListener('input', resetCode);

				sendButton.addEventListener('click', async () => {
					if (!validEmail()) return;
					sendButton.disabled = true;
					sendButton.textContent = 'Sending code...';
					setStatus('');
					try {
						const data = new FormData();
						data.set('action', 'ashtanga_send_email_otp');
						data.set('nonce', config.nonce);
						data.set('email', email.value.trim());
						const response = await fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
						const result = await response.json();
						if (!response.ok || !result.success) throw new Error(result.data?.message || 'We could not send your code. Please try again shortly.');
						setStatus(result.data.message, 'success');
						code.hidden = false;
						verifyButton.hidden = false;
						code.focus();
						let seconds = 60;
						sendButton.textContent = `Resend in ${seconds}s`;
						resendTimer = window.setInterval(() => {
							seconds -= 1;
							if (seconds <= 0) {
								window.clearInterval(resendTimer);
								resendTimer = null;
								sendButton.disabled = false;
								sendButton.textContent = 'Resend code';
							} else {
								sendButton.textContent = `Resend in ${seconds}s`;
							}
						}, 1000);
					} catch (error) {
						setStatus(error.message || 'We could not send your code. Please try again shortly.', 'error');
						sendButton.disabled = false;
						sendButton.textContent = 'Email me a sign-in code';
					}
				});

				verifyButton.addEventListener('click', async () => {
					if (!validEmail()) return;
					if (!/^[0-9]{6}$/.test(code.value.trim())) {
						setStatus('Enter the 6-digit code from your email.', 'error');
						code.focus();
						return;
					}
					verifyButton.disabled = true;
					verifyButton.textContent = 'Signing in...';
					setStatus('');
					try {
						const data = new FormData();
						data.set('action', 'ashtanga_verify_email_otp');
						data.set('nonce', config.nonce);
						data.set('email', email.value.trim());
						data.set('code', code.value.trim());
						const response = await fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
						const result = await response.json();
						if (!response.ok || !result.success) throw new Error(result.data?.message || 'That code is incorrect or expired. Check it or request a new one.');
						setStatus(result.data.message, 'success');
						window.location.reload();
					} catch (error) {
						setStatus(error.message || 'That code is incorrect or expired. Check it or request a new one.', 'error');
						verifyButton.disabled = false;
						verifyButton.textContent = 'Sign in';
					}
				});
			};

			installButton();
			new MutationObserver(installButton).observe(document.body, { childList: true, subtree: true });
		})();
		</script>
		<?php
	}
}

Ashtanga_Email_OTP::boot();
