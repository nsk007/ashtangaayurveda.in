(() => {
	const forms = document.querySelectorAll('[data-ashtanga-otp-form]');
	const config = window.ashtangaStoreOnboarding;

	if (!forms.length || !config) return;

	forms.forEach(form => {
		const phone = form.querySelector('[name="phone"]');
		const code = form.querySelector('[name="code"]');
		const sendButton = form.querySelector('[data-ashtanga-send]');
		const verifyButton = form.querySelector('[data-ashtanga-verify]');
		const resendButton = form.querySelector('[data-ashtanga-resend]');
		const codeStep = form.querySelector('[data-ashtanga-code-step]');
		const masked = form.querySelector('[data-ashtanga-masked]');
		const status = form.querySelector('[data-ashtanga-status]');	
		let challenge = '';
		let cooldownTimer;

		const post = async (action, body) => {
			const response = await fetch(config.endpoint + action, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-Ashtanga-Nonce': config.nonce
				},
				body: JSON.stringify(body)
			});
			const data = await response.json();
			if (!response.ok) throw new Error(data.message || 'Request failed. Please try again.');
			return data;
		};

		const startCooldown = () => {
			let seconds = Number(config.cooldown) || 30;
			resendButton.disabled = true;
			resendButton.textContent = `Resend code (${seconds}s)`;
			window.clearInterval(cooldownTimer);
			cooldownTimer = window.setInterval(() => {
				seconds -= 1;
				if (seconds <= 0) {
					window.clearInterval(cooldownTimer);
					resendButton.disabled = false;
					resendButton.textContent = 'Resend code';
					return;
				}
				resendButton.textContent = `Resend code (${seconds}s)`;
			}, 1000);
		};

		sendButton.addEventListener('click', async () => {
			status.textContent = '';
			sendButton.disabled = true;
			try {
				const data = await post('request', { phone: phone.value });
				challenge = data.challenge;
				masked.textContent = `Code sent to ${data.masked}`;
				codeStep.hidden = false;
				status.textContent = 'Enter the code sent to your mobile number.';
				startCooldown();
				code.focus();
			} catch (error) {
				status.textContent = error.message;
			} finally {
				sendButton.disabled = false;
			}
		});

		resendButton.addEventListener('click', async () => {
			resendButton.disabled = true;
			status.textContent = '';
			try {
				const data = await post('resend', { challenge, phone: phone.value });
				masked.textContent = `Code sent to ${data.masked}`;
				status.textContent = 'A new code has been sent.';
				startCooldown();
			} catch (error) {
				status.textContent = error.message;
				resendButton.disabled = false;
			}
		});

		verifyButton.addEventListener('click', async () => {
			verifyButton.disabled = true;
			status.textContent = '';
			try {
				const data = await post('verify', { challenge, phone: phone.value, code: code.value });
				status.textContent = data.message;
				window.location.assign(data.redirect || window.location.href);
			} catch (error) {
				status.textContent = error.message;
				verifyButton.disabled = false;
			}
		});
	});
})();