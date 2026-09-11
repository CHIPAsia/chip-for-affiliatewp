<?php
/**
 * Admin UI: settings section, notices, webhook URL hint.
 *
 * @package CHIPforAffiliateWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Cannot access directly.

/**
 * Registers the CHIP payout method label.
 *
 * @param array $payout_methods Registered payout methods.
 * @return array
 */
function chip_affiliatewp_register_payout_method( $payout_methods ) {
	if ( affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		$payout_methods['chip'] = __( 'CHIP Send', 'chip-for-affiliatewp' );
	}

	return $payout_methods;
}
add_filter( 'affwp_payout_methods', 'chip_affiliatewp_register_payout_method' );

/**
 * Reports whether CHIP Send is currently enabled.
 *
 * Registering the method only adds it to the registry; AffiliateWP treats a
 * registered method as enabled. The bundled methods answer this filter with
 * their own enable setting plus a credentials check, so unchecking the method
 * (or clearing its keys) removes it from the affiliate picker, the admin
 * assignment dropdown, and every "usable method" resolution.
 *
 * @param bool   $enabled       Whether the method is enabled.
 * @param string $payout_method Payout method key.
 * @return bool
 */
function chip_affiliatewp_is_payout_method_enabled( $enabled, $payout_method ) {
	if ( 'chip' !== $payout_method ) {
		return $enabled;
	}

	return (bool) affiliate_wp()->settings->get( 'chip_payouts' ) && chip_affiliatewp_has_credentials();
}
add_filter( 'affwp_is_payout_method_enabled', 'chip_affiliatewp_is_payout_method_enabled', 10, 2 );

/**
 * Reports whether a specific affiliate can be paid through CHIP Send.
 *
 * Mirrors the bundled methods: an affiliate is ready only when the method is
 * enabled and that affiliate has bank details on file. This drives the
 * ready/blocked split in the Payouts preview and the affiliate list.
 *
 * Deliberately local: this filter runs once per affiliate while building the
 * preview, so it must not call the CHIP API. Whether the bank account is
 * actually verified is resolved at submission time, where a rejection fails
 * the payout safely and releases its referrals.
 *
 * @param bool       $ready        Whether the affiliate is ready.
 * @param string     $method       Payout method key.
 * @param int        $affiliate_id Affiliate ID.
 * @param mixed|null $payout       Payout row when the check is per-payout.
 * @return bool
 */
function chip_affiliatewp_payout_method_is_affiliate_ready( $ready, $method, $affiliate_id, $payout = null ) {
	if ( 'chip' !== $method ) {
		return $ready;
	}

	if ( ! chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) ) {
		return false;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	return '' !== $details['account_number'] && '' !== $details['bank_code'];
}
add_filter( 'affwp_payout_method_is_affiliate_ready', 'chip_affiliatewp_payout_method_is_affiliate_ready', 10, 4 );

/**
 * Tells the affiliate what CHIP Send needs from them, on their dashboard.
 *
 * The bundled methods all surface their account state on the affiliate
 * dashboard, so an affiliate whose bank details are missing or still being
 * verified learns it there rather than by having a payout fail later. Only
 * shown when CHIP Send is the affiliate's effective method.
 *
 * @return void
 */
function chip_affiliatewp_affiliate_dashboard_notice() {
	if ( ! function_exists( 'affwp_get_affiliate_id' ) || ! function_exists( 'affwp_get_affiliate_usable_payout_method' ) ) {
		return;
	}

	$affiliate_id = affwp_get_affiliate_id();

	if ( ! $affiliate_id ) {
		return;
	}

	if ( 'chip' !== affwp_get_affiliate_usable_payout_method( $affiliate_id ) ) {
		return;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	if ( '' === $details['account_number'] || '' === $details['bank_code'] ) {
		$notice = array(
			'variant' => 'warning',
			'heading' => __( 'Add your bank details to get paid', 'chip-for-affiliatewp' ),
			'body'    => __( 'Your commissions are paid straight to your Malaysian bank account. Add your bank account details in your payout settings so we can send your next payout.', 'chip-for-affiliatewp' ),
		);
	} else {
		$notice = array(
			'variant' => 'info',
			'heading' => __( 'Payouts go to your bank account', 'chip-for-affiliatewp' ),
			/* translators: 1: bank name, 2: masked account number */
			'body'    => sprintf(
				__( 'Your commissions are sent to %1$s %2$s. Bank account verification can take a little while; you will be paid as soon as it completes.', 'chip-for-affiliatewp' ),
				$details['bank_name'],
				$details['account_number']
			),
		);
	}

	/**
	 * Filters the notice shown to CHIP Send affiliates on their dashboard.
	 *
	 * @param array $notice Notice arguments.
	 * @param int   $affiliate_id Affiliate ID.
	 */
	$notice = apply_filters( 'chip_affiliatewp_affiliate_dashboard_notice', $notice, $affiliate_id );

	if ( empty( $notice ) ) {
		return;
	}

	if ( function_exists( 'affwp_notice' ) ) {
		affwp_notice( $notice );
	}
}
add_action( 'affwp_affiliate_dashboard_notices', 'chip_affiliatewp_affiliate_dashboard_notice' );

/**
 * Registers CHIP Send as a payment-method card on the Payouts tab.
 *
 * AffiliateWP 2.29+ renders each payout provider from the
 * AffiliateWP_Payment_Methods registry, populated on the
 * `affwp_register_payment_methods` action. Registering here makes CHIP Send
 * appear exactly like the bundled Stripe / PayPal cards — same status badge,
 * same Configure button, same collapsible settings panel.
 *
 * The card status mirrors the state a merchant can act on:
 * - active          → method enabled and credentials present
 * - setup_required  → enabled but credentials missing
 * - available       → not enabled yet
 *
 * @return void
 */
function chip_affiliatewp_register_payment_method_card() {
	if ( ! class_exists( 'AffiliateWP_Payment_Methods' ) ) {
		return;
	}

	$enabled   = (bool) affiliate_wp()->settings->get( 'chip_payouts' );
	$has_creds = chip_affiliatewp_has_credentials();
	$test_mode = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );

	/*
	 * Card status follows the same vocabulary as the bundled methods:
	 * 'active' shows the mode badge, 'setup_required' keeps the Configure
	 * button available while the method is off or unconfigured. The core
	 * badge lookup only knows the bundled method ids, so 'setup_required'
	 * renders without a misleading badge for this method.
	 */
	$status = ( $enabled && $has_creds ) ? 'active' : 'setup_required';

	$config = array(
		'name'              => __( 'CHIP Send', 'chip-for-affiliatewp' ),
		'description'       => __( 'Pay affiliate commissions straight to Malaysian bank accounts', 'chip-for-affiliatewp' ),
		'icon'              => CHIP_AFFILIATEWP_URL . 'assets/logo.svg',
		'status'            => $status,
		'type'              => 'addon',
		'settings_callback' => 'chip_affiliatewp_render_settings_panel',
		'has_new_settings'  => true,
	);

	if ( 'active' === $status ) {
		$config['status_label'] = $test_mode
			? __( 'Test Mode', 'chip-for-affiliatewp' )
			: __( 'Live Mode', 'chip-for-affiliatewp' );
	}

	AffiliateWP_Payment_Methods::register( 'chip', $config );
}
add_action( 'affwp_register_payment_methods', 'chip_affiliatewp_register_payment_method_card' );

/**
 * Renders a labelled field using the native AffiliateWP input component.
 *
 * Falls back to plain markup when the component library is unavailable
 * (older AffiliateWP releases), so the panel still renders correctly.
 *
 * @param array $args {
 *     @type string $name   Input name attribute.
 *     @type string $label  Visible field label.
 *     @type string $desc   Optional help text.
 *     @type string $value  Current value.
 *     @type bool   $secret Credential-style input hints.
 *     @type string $width  'full' | 'narrow' | 'auto'.
 * }
 * @return void
 */
function chip_affiliatewp_ui_input( $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'id'          => '',
			'name'        => '',
			'label'       => '',
			'desc'        => '',
			'value'       => '',
			'placeholder' => '',
			'type'        => 'text',
			'secret'      => false,
			'width'       => 'full',
			'maxlength'   => 0,
			'class'       => '',
		)
	);

	if ( function_exists( 'affwp_input' ) ) {
		$input_args = array(
			'name'   => $args['name'],
			'value'  => $args['value'],
			'type'   => $args['type'],
			'secret' => (bool) $args['secret'],
			'width'  => $args['width'],
		);

		if ( '' !== $args['id'] ) {
			$input_args['id'] = $args['id'];
		}
		if ( '' !== $args['placeholder'] ) {
			$input_args['placeholder'] = $args['placeholder'];
		}
		if ( $args['maxlength'] > 0 ) {
			$input_args['maxlength'] = (int) $args['maxlength'];
		}

		affwp_input( $input_args );

		return;
	}

	// Fallback for older AffiliateWP releases without the component library.
	$type_attr = 'password' === $args['type'] ? 'password' : 'text';
	$id_attr   = '' !== $args['id'] ? $args['id'] : $args['name'];
	$classes   = 'w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500';
	if ( '' !== $args['class'] ) {
		$classes .= ' ' . $args['class'];
	}
	?>
	<input
		type="<?php echo esc_attr( $type_attr ); ?>"
		id="<?php echo esc_attr( $id_attr ); ?>"
		name="<?php echo esc_attr( $args['name'] ); ?>"
		value="<?php echo esc_attr( $args['value'] ); ?>"
		class="<?php echo esc_attr( $classes ); ?>"
		<?php if ( '' !== $args['placeholder'] ) : ?>placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"<?php endif; ?>
		<?php if ( $args['maxlength'] > 0 ) : ?>maxlength="<?php echo (int) $args['maxlength']; ?>"<?php endif; ?>
	/>
	<?php
	if ( '' !== $args['desc'] ) {
		printf( '<p class="mt-2 text-sm text-gray-600">%s</p>', esc_html( $args['desc'] ) );
	}
}

/**
 * Renders the CHIP Send settings panel inside its Payouts-tab card.
 *
 * Uses the native AffiliateWP UI components so the panel matches the
 * Stripe / PayPal panels. Fields post as `affwp_settings[...]` inside the
 * Payouts tab form and are stored by the standard settings save path.
 *
 * The enable toggle lives here rather than on the card row: the card template
 * renders toggles only for its bundled method ids, so a third-party method
 * exposes its on/off switch inside its own settings panel.
 *
 * @return void
 */
function chip_affiliatewp_render_settings_panel() {
	$enabled     = (bool) affiliate_wp()->settings->get( 'chip_payouts' );
	$test_mode   = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );
	$live_key    = (string) affiliate_wp()->settings->get( 'chip_live_api_key' );
	$live_secret = (string) affiliate_wp()->settings->get( 'chip_live_secret_key' );
	$test_key    = (string) affiliate_wp()->settings->get( 'chip_test_api_key' );
	$test_secret = (string) affiliate_wp()->settings->get( 'chip_test_secret_key' );

	$has_live = '' !== $live_key && '' !== $live_secret;
	$has_test = '' !== $test_key && '' !== $test_secret;
	?>
	<div class="max-w-3xl">

		<!-- Header Section -->
		<div class="mb-6">
			<h3 class="mb-2 text-xl font-semibold text-gray-900">
				<?php esc_html_e( 'CHIP Send Configuration', 'chip-for-affiliatewp' ); ?>
			</h3>
			<p class="text-sm text-gray-600">
				<?php esc_html_e( 'Configure how CHIP Send pays affiliate commissions straight to Malaysian bank accounts.', 'chip-for-affiliatewp' ); ?>
			</p>
		</div>

		<?php
		/*
		 * The core "Let affiliates choose <method>" row is deliberately not used
		 * here: AffiliateWP saves that toggle through a hardcoded key list that
		 * covers only its bundled methods, so a third-party method's toggle would
		 * silently revert on save. The affiliate picker still honours
		 * `affwp_hidden_affiliate_payout_methods`, so CHIP is offered to affiliates
		 * by default and can be hidden per site through the filter below.
		 */
		$chip_affiliates_can_choose = ! in_array(
			'chip',
			(array) get_option( 'affwp_hidden_affiliate_payout_methods', array() ),
			true
		);
		?>

		<?php if ( function_exists( 'affwp_toggle' ) ) : ?>
			<div class="mb-6 overflow-hidden bg-white rounded-lg border border-gray-200">
				<div class="flex gap-6 justify-between items-center p-5">
					<div class="flex-1 min-w-0">
						<span class="block mb-1 text-base font-medium text-gray-900">
							<?php esc_html_e( 'Let affiliates choose CHIP Send', 'chip-for-affiliatewp' ); ?>
						</span>
						<p class="text-sm text-gray-600">
							<?php esc_html_e( "CHIP Send appears in each affiliate's payout method options. When off, it is hidden there, but you can still assign it to specific affiliates on the Edit Affiliate screen.", 'chip-for-affiliatewp' ); ?>
						</p>
					</div>
					<div class="shrink-0">
						<?php
						printf( '<input type="hidden" name="affwp_settings[chip_affiliate_selectable]" value="0" />' );
						affwp_toggle(
							array(
								'name'    => 'affwp_settings[chip_affiliate_selectable]',
								'label'   => __( 'Let affiliates choose CHIP Send', 'chip-for-affiliatewp' ),
								'checked' => $chip_affiliates_can_choose,
								'size'    => 'sm',
								'color'   => 'blue',
							)
						);
						?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php
		// Master switch. Lives in the panel because the card row renders
		// toggles only for AffiliateWP's bundled method ids.
		if ( function_exists( 'affwp_toggle' ) ) {
			?>
			<div class="mb-6 overflow-hidden bg-white rounded-lg border border-gray-200">
				<div class="flex gap-6 justify-between items-center p-5">
					<div class="flex-1 min-w-0">
						<span class="block mb-1 text-base font-medium text-gray-900">
							<?php esc_html_e( 'Enable CHIP Send', 'chip-for-affiliatewp' ); ?>
						</span>
						<p class="text-sm text-gray-600">
							<?php esc_html_e( 'When off, CHIP Send is hidden from affiliates and no payouts are submitted to CHIP.', 'chip-for-affiliatewp' ); ?>
						</p>
					</div>
					<div class="shrink-0">
						<?php
						printf( '<input type="hidden" name="affwp_settings[chip_payouts]" value="0" />' );
						affwp_toggle(
							array(
								'name'    => 'affwp_settings[chip_payouts]',
								'label'   => __( 'Enable CHIP Send', 'chip-for-affiliatewp' ),
								'checked' => $enabled,
								'size'    => 'sm',
								'color'   => 'blue',
							)
						);
						?>
					</div>
				</div>
			</div>
			<?php
		}

		// Live credentials card.
		chip_affiliatewp_render_credentials_card(
			array(
				'id'          => 'live',
				'heading'     => __( 'Live Credentials', 'chip-for-affiliatewp' ),
				'description' => __( 'Process real payouts using your live CHIP account', 'chip-for-affiliatewp' ),
				'active'      => $has_live && ! $test_mode,
				'key_name'    => 'chip_live_api_key',
				'key_value'   => $live_key,
				'key_label'   => __( 'API Key', 'chip-for-affiliatewp' ),
				'key_ph'      => __( 'Enter your live CHIP Send API key', 'chip-for-affiliatewp' ),
				'sec_name'    => 'chip_live_secret_key',
				'sec_value'   => $live_secret,
				'sec_label'   => __( 'Secret Key', 'chip-for-affiliatewp' ),
				'sec_ph'      => __( 'Enter your live CHIP Send secret key', 'chip-for-affiliatewp' ),
			)
		);

		// Test credentials card.
		chip_affiliatewp_render_credentials_card(
			array(
				'id'          => 'test',
				'heading'     => __( 'Test Credentials', 'chip-for-affiliatewp' ),
				'description' => __( 'Process sandbox payouts with CHIP test credentials', 'chip-for-affiliatewp' ),
				'active'      => $has_test && $test_mode,
				'key_name'    => 'chip_test_api_key',
				'key_value'   => $test_key,
				'key_label'   => __( 'Test API Key', 'chip-for-affiliatewp' ),
				'key_ph'      => __( 'Enter your CHIP Send test API key', 'chip-for-affiliatewp' ),
				'sec_name'    => 'chip_test_secret_key',
				'sec_value'   => $test_secret,
				'sec_label'   => __( 'Test Secret Key', 'chip-for-affiliatewp' ),
				'sec_ph'      => __( 'Enter your CHIP Send test secret key', 'chip-for-affiliatewp' ),
			)
		);

		// Mode + reference settings card.
		if ( function_exists( 'affwp_toggle' ) ) {
			?>
			<div class="mb-6 overflow-hidden bg-white rounded-lg border border-gray-200">
				<div class="p-6">
					<div class="mb-4">
						<h4 class="text-base font-medium text-gray-900">
							<?php esc_html_e( 'Mode', 'chip-for-affiliatewp' ); ?>
						</h4>
						<p class="mt-1 text-sm text-gray-600">
							<?php esc_html_e( 'Test Mode routes every payout through CHIP test credentials and never moves real money.', 'chip-for-affiliatewp' ); ?>
						</p>
					</div>

					<div class="space-y-4">
						<div class="flex gap-6 justify-between items-center">
							<span class="text-sm font-medium text-gray-700">
								<?php esc_html_e( 'Test Mode', 'chip-for-affiliatewp' ); ?>
							</span>
							<?php
							printf( '<input type="hidden" name="affwp_settings[chip_test_mode]" value="0" />' );
							affwp_toggle(
								array(
									'name'    => 'affwp_settings[chip_test_mode]',
									'label'   => __( 'Test Mode', 'chip-for-affiliatewp' ),
									'checked' => $test_mode,
									'size'    => 'sm',
									'color'   => 'blue',
								)
							);
							?>
						</div>

						<div class="flex gap-6 justify-between items-center">
							<span class="text-sm font-medium text-gray-700">
								<?php esc_html_e( 'Email the affiliate a CHIP receipt on every payout', 'chip-for-affiliatewp' ); ?>
							</span>
							<?php
							printf( '<input type="hidden" name="affwp_settings[chip_send_recipient_receipt]" value="0" />' );
							affwp_toggle(
								array(
									'name'    => 'affwp_settings[chip_send_recipient_receipt]',
									'label'   => __( 'Email the affiliate a CHIP receipt on every payout', 'chip-for-affiliatewp' ),
									'checked' => (bool) affiliate_wp()->settings->get( 'chip_send_recipient_receipt' ),
									'size'    => 'sm',
									'color'   => 'blue',
								)
							);
							?>
						</div>

						<div class="pt-4 mt-4 border-t border-gray-200">
							<label for="chip-reference-prefix" class="block mb-1 text-sm font-medium text-gray-700">
								<?php esc_html_e( 'Reference Prefix', 'chip-for-affiliatewp' ); ?>
							</label>
							<?php
							chip_affiliatewp_ui_input(
								array(
									'id'          => 'chip-reference-prefix',
									'name'        => 'affwp_settings[chip_reference_prefix]',
									'value'       => (string) chip_affiliatewp_reference_prefix(),
									'placeholder' => __( '34', 'chip-for-affiliatewp' ),
									'maxlength'   => 2,
									'width'       => 'narrow',
								)
							);
							?>
							<p class="mt-2 text-sm text-gray-600">
								<?php esc_html_e( 'Two characters used to prefix CHIP Send references.', 'chip-for-affiliatewp' ); ?>
							</p>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		// Webhook status card.
		if ( function_exists( 'affwp_callout' ) ) {
			$configured = chip_affiliatewp_webhook_configured();
			?>
			<div class="overflow-hidden bg-white rounded-lg border border-gray-200">
				<div class="p-6">
					<div class="mb-4">
						<h4 class="flex items-center text-base font-medium text-gray-900">
							<?php esc_html_e( 'Webhook', 'chip-for-affiliatewp' ); ?>
							<?php if ( $configured ) : ?>
								<span class="px-2 py-1 ml-2 text-xs text-green-700 bg-green-50 rounded-md border border-green-200">
									<?php esc_html_e( 'Connected', 'chip-for-affiliatewp' ); ?>
								</span>
							<?php endif; ?>
						</h4>
					</div>
					<?php
					affwp_callout(
						array(
							'tone'    => $configured ? 'info' : 'warning',
							'heading' => $configured
								? __( 'Webhook connected', 'chip-for-affiliatewp' )
								: __( 'Webhook not set up yet', 'chip-for-affiliatewp' ),
							'content' => $configured
								? __( 'CHIP Send delivers payout status updates to this site and every delivery is verified against the webhook public key. Registration is automatic — nothing to configure here.', 'chip-for-affiliatewp' )
								: __( 'Payouts still settle — statuses are requeried hourly — but confirmations arrive faster with the webhook. Save your credentials to register it automatically.', 'chip-for-affiliatewp' ),
						)
					);
					?>
				</div>
			</div>
			<?php
			unset( $configured );
		}
		?>
	</div>
	<?php
	unset( $enabled, $test_mode, $live_key, $live_secret, $test_key, $test_secret, $has_live, $has_test );
}

/**
 * Renders one bordered credentials card inside the CHIP Send settings panel.
 *
 * Mirrors the bundled method panels: a bordered card, a heading with an
 * "Active" pill when the credential pair is in use, then labelled inputs.
 *
 * @param array $args Card definition.
 * @return void
 */
function chip_affiliatewp_render_credentials_card( $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'id'          => '',
			'heading'     => '',
			'description' => '',
			'active'      => false,
			'key_name'    => '',
			'key_value'   => '',
			'key_label'   => '',
			'key_ph'      => '',
			'sec_name'    => '',
			'sec_value'   => '',
			'sec_label'   => '',
			'sec_ph'      => '',
		)
	);

	$border_class = $args['active']
		? 'bg-green-50/20 border-l-4 border-green-500'
		: 'border-l-4 border-transparent';
	?>
	<div class="mb-6 overflow-hidden bg-white rounded-lg border border-gray-200">
		<div class="relative p-6 transition-colors <?php echo esc_attr( $border_class ); ?>">
			<div class="mb-4">
				<h4 class="flex items-center text-base font-medium text-gray-900">
					<?php echo esc_html( $args['heading'] ); ?>
					<?php if ( $args['active'] ) : ?>
						<span class="px-2 py-1 ml-2 text-xs text-green-700 bg-green-50 rounded-md border border-green-200">
							<?php esc_html_e( 'Active', 'chip-for-affiliatewp' ); ?>
						</span>
					<?php endif; ?>
				</h4>
				<?php if ( '' !== $args['description'] ) : ?>
					<p class="mt-1 text-sm text-gray-600"><?php echo esc_html( $args['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="space-y-4">
				<div>
					<label for="chip-<?php echo esc_attr( $args['id'] ); ?>-api-key" class="block mb-1 text-sm font-medium text-gray-700">
						<?php echo esc_html( $args['key_label'] ); ?>
					</label>
					<?php
					chip_affiliatewp_ui_input(
						array(
							'id'          => 'chip-' . $args['id'] . '-api-key',
							'name'        => 'affwp_settings[' . $args['key_name'] . ']',
							'value'       => (string) $args['key_value'],
							'placeholder' => $args['key_ph'],
							'secret'      => true,
						)
					);
					?>
				</div>

				<div>
					<label for="chip-<?php echo esc_attr( $args['id'] ); ?>-secret-key" class="block mb-1 text-sm font-medium text-gray-700">
						<?php echo esc_html( $args['sec_label'] ); ?>
					</label>
					<?php
					chip_affiliatewp_ui_input(
						array(
							'id'          => 'chip-' . $args['id'] . '-secret-key',
							'name'        => 'affwp_settings[' . $args['sec_name'] . ']',
							'value'       => (string) $args['sec_value'],
							'placeholder' => $args['sec_ph'],
							'type'        => 'password',
							'secret'      => true,
						)
					);
					?>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Forces a "processing" initial status for CHIP batch payouts.
 *
 * CHIP Send instructions settle asynchronously; referrals must stay unpaid
 * until CHIP confirms completion via webhook or requery.
 *
 * @param string $status        Default initial status.
 * @param string $payout_method Payout method identifier.
 * @return string
 */
function chip_affiliatewp_batch_initial_status( $status, $payout_method ) {
	if ( 'chip' === $payout_method && chip_affiliatewp_is_payout_method_enabled( true, 'chip' ) ) {
		return 'processing';
	}

	return $status;
}
add_filter( 'affwp_batch_payout_initial_status', 'chip_affiliatewp_batch_initial_status', 10, 2 );

/**
 * Submits CHIP payouts once the payout batch completes.
 */
add_action( 'affwp_batch_generate_payouts_completed', 'chip_affiliatewp_process_generated_batch' );

/**
 * Registers the single-referral payout handler for the "chip" method.
 *
 * @param array $handlers Map of payout-method slug => callable.
 * @return array
 */
function chip_affiliatewp_register_single_referral_handler( $handlers ) {
	if ( affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		$handlers['chip'] = 'chip_affiliatewp_pay_single_referral';
	}

	return $handlers;
}
add_filter( 'affwp_single_referral_payout_handlers', 'chip_affiliatewp_register_single_referral_handler' );

/**
 * Reports per-affiliate readiness for CHIP Send in the Payouts preview.
 *
 * AffiliateWP asks each method whether an affiliate can actually be paid
 * before rendering the batch preview rows. Without an answer here the core
 * default is 'ready', which would promise a payout that fails later because
 * the affiliate has no bank details on file. Reporting the real state means
 * the preview shows the same "not connected" badge Stripe / PayPal produce.
 *
 * @param string $status       Preflight status: 'ready', 'invite', or 'blocked'.
 * @param string $method       Payout method key.
 * @param int    $affiliate_id Affiliate ID.
 * @return string
 */
function chip_affiliatewp_preflight_payout_status( $status, $method, $affiliate_id ) {
	if ( 'chip' !== $method ) {
		return $status;
	}

	$details = chip_affiliatewp_get_bank_details( $affiliate_id );

	if ( '' === $details['account_number'] || '' === $details['bank_code'] ) {
		return 'blocked';
	}

	return 'ready';
}
add_filter( 'affwp_preflight_payout_status', 'chip_affiliatewp_preflight_payout_status', 10, 3 );

/**
 * Appends a bank-details readiness indicator to the Affiliates list.
 *
 * The bundled Stripe method annotates the payout-method column with a status
 * dot so an admin can see at a glance who is payable. CHIP Send mirrors that
 * using the same core indicator helper: green when the affiliate has bank
 * details on file, grey (with a tooltip) when they cannot be paid yet.
 *
 * @param string $value     Rendered payout-method cell value.
 * @param object $affiliate Affiliate row object.
 * @return string
 */
function chip_affiliatewp_affiliate_table_payout_method( $value, $affiliate ) {
	if ( ! function_exists( 'affwp_render_payout_method_status_indicator' ) ) {
		return $value;
	}

	if ( ! isset( $affiliate->affiliate_id ) ) {
		return $value;
	}

	if ( function_exists( 'affwp_get_affiliate_effective_method' )
		&& 'chip' !== affwp_get_affiliate_effective_method( $affiliate ) ) {
		return $value;
	}

	$details = chip_affiliatewp_get_bank_details( (int) $affiliate->affiliate_id );
	$ready   = '' !== $details['account_number'] && '' !== $details['bank_code'];

	$indicator = affwp_render_payout_method_status_indicator(
		array(
			'dot_class' => $ready ? 'bg-green-500' : 'bg-gray-300',
			'label'     => $ready
				? __( 'Ready', 'chip-for-affiliatewp' )
				: __( 'No bank details', 'chip-for-affiliatewp' ),
			'tooltip'   => array(
				/* translators: %s: payout method name. */
				'title'   => sprintf( __( '%s: bank details', 'chip-for-affiliatewp' ), __( 'CHIP Send', 'chip-for-affiliatewp' ) ),
				'content' => $ready
					? __( 'This affiliate has bank details on file and can be paid via CHIP Send.', 'chip-for-affiliatewp' )
					: __( 'This affiliate has no bank code or account number on file, so a CHIP Send payout would fail.', 'chip-for-affiliatewp' ),
				'type'    => $ready ? 'success' : 'info',
			),
		)
	);

	return sprintf( '<span class="inline-flex items-center gap-2">%1$s%2$s</span>', $value, $indicator );
}
add_filter( 'affwp_affiliate_table_payout_method', 'chip_affiliatewp_affiliate_table_payout_method', 15, 2 );

/**
 * Adds a note to the CHIP Send section of the batch payout preview.
 *
 * Mirrors the per-method notes the bundled providers render (for example
 * PayPal's invite explanation) so the preview explains CHIP Send's behaviour
 * in the same place and the same style.
 *
 * @param array $method_data Method section data from the preview renderer.
 * @return void
 */
function chip_affiliatewp_preview_payout_note( $method_data ) {
	unset( $method_data );

	$test_mode = (bool) affiliate_wp()->settings->get( 'chip_test_mode' );

	if ( function_exists( 'affwp_callout' ) ) {
		affwp_callout(
			array(
				'tone'    => 'info',
				'content' => $test_mode
					? __( 'CHIP Send is in Test Mode — payouts go to the CHIP Send staging environment and no real money moves.', 'chip-for-affiliatewp' )
					: __( 'CHIP Send pays each affiliate directly to their bank account. Payouts stay in Processing until CHIP confirms the transfer, then the referral is marked Paid.', 'chip-for-affiliatewp' ),
			)
		);

		return;
	}

	printf(
		'<p class="description">%s</p>',
		esc_html(
			$test_mode
				? __( 'CHIP Send is in Test Mode — payouts go to the CHIP Send staging environment and no real money moves.', 'chip-for-affiliatewp' )
				: __( 'CHIP Send pays each affiliate directly to their bank account. Payouts stay in Processing until CHIP confirms the transfer, then the referral is marked Paid.', 'chip-for-affiliatewp' )
		)
	);
}
add_action( 'affwp_preview_payout_note_chip', 'chip_affiliatewp_preview_payout_note' );

/**
 * Runs the status requery for a scheduled payout check.
 *
 * @param int $payout_id Payout ID.
 * @return void
 */
function chip_affiliatewp_run_scheduled_check( $payout_id ) {
	chip_affiliatewp_check_payout_status( (int) $payout_id, true );
}
add_action( 'chip_affiliatewp_check_payout_status', 'chip_affiliatewp_run_scheduled_check' );

/**
 * Runs the hourly sweep of processing payouts.
 */
add_action( 'chip_affiliatewp_hourly_sweep', 'chip_affiliatewp_sweep_processing_payouts' );

/**
 * Registers the CHIP Send settings so the save path sanitizes them by type.
 *
 * The Payouts tab renders custom card content rather than a generic settings
 * list, so there is no `affwp_settings_payouts` array in core to extend. The
 * keys are declared here anyway — through the standard
 * `affwp_settings_payouts_sanitize` filter the tab applies — so text fields
 * get `sanitize_text_field` treatment and the plugin reads predictable shapes.
 *
 * @param array $input Submitted Payouts-tab settings.
 * @return array
 */
function chip_affiliatewp_sanitize_settings( $input ) {
	if ( ! is_array( $input ) ) {
		return $input;
	}

	$chip_keys = array(
		'chip_payouts',
		'chip_test_mode',
		'chip_live_api_key',
		'chip_live_secret_key',
		'chip_test_api_key',
		'chip_test_secret_key',
		'chip_reference_prefix',
		'chip_send_recipient_receipt',
		'chip_webhook_public_key',
	);

	/*
	 * "Let affiliates choose CHIP Send" persists to the canonical
	 * `affwp_hidden_affiliate_payout_methods` option rather than to
	 * affwp_settings: the affiliate picker and the per-affiliate override both
	 * read that option, and AffiliateWP's own save handler only tracks its
	 * bundled method keys. Read $_POST (not $input) because WordPress runs this
	 * filter more than once per save and feeds each run's output back as $input.
	 */
	if ( isset( $_POST['affwp_settings'] ) && is_array( $_POST['affwp_settings'] ) ) {
		$posted = wp_unslash( $_POST['affwp_settings'] );

		if ( array_key_exists( 'chip_affiliate_selectable', $posted ) ) {
			$hidden = get_option( 'affwp_hidden_affiliate_payout_methods', array() );
			$hidden = is_array( $hidden ) ? $hidden : array();

			if ( ! empty( $posted['chip_affiliate_selectable'] ) ) {
				$hidden = array_values( array_diff( $hidden, array( 'chip' ) ) );
			} elseif ( ! in_array( 'chip', $hidden, true ) ) {
				$hidden[] = 'chip';
			}

			update_option( 'affwp_hidden_affiliate_payout_methods', array_values( $hidden ) );
		}
	}

	// The toggle key itself must never land in affwp_settings.
	unset( $input['chip_affiliate_selectable'] );

	foreach ( $chip_keys as $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}

		if ( in_array( $key, array( 'chip_payouts', 'chip_test_mode', 'chip_send_recipient_receipt' ), true ) ) {
			$input[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;

			continue;
		}

		if ( 'chip_webhook_public_key' === $key ) {
			$input[ $key ] = trim( (string) $input[ $key ] );

			continue;
		}

		if ( 'chip_reference_prefix' === $key ) {
			$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $input[ $key ] ) );

			$input[ $key ] = substr( $prefix, 0, 2 );

			continue;
		}

		// Credentials: plain text, never re-encoded.
		$input[ $key ] = sanitize_text_field( (string) $input[ $key ] );
	}

	return $input;
}
add_filter( 'affwp_settings_payouts_sanitize', 'chip_affiliatewp_sanitize_settings' );

/**
 * Auto-registers the CHIP Send webhook after settings are saved.
 *
 * Runs on every settings save while CHIP Send is enabled; chip_affiliatewp_ensure_webhook()
 * is idempotent and cheap when everything already matches (one GET). Never
 * fatal: failures are surfaced as an admin notice only.
 *
 * @param array $old_value Previous settings.
 * @param array $new_value New settings.
 * @return void
 */
function chip_affiliatewp_auto_register_webhook( $old_value, $new_value ) {
	unset( $old_value, $new_value );

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) || ! chip_affiliatewp_has_credentials() ) {
		return;
	}

	chip_affiliatewp_ensure_webhook();
}
add_action( 'update_option_affwp_settings', 'chip_affiliatewp_auto_register_webhook', 10, 2 );

/**
 * Collects webhook setup problems to show in the admin.
 *
 * @return string[] Human-readable messages; empty when everything is fine.
 */
function chip_affiliatewp_webhook_setup_notices() {
	$notices = array();

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return $notices;
	}

	if ( ! chip_affiliatewp_has_credentials() ) {
		$notices[] = __( 'CHIP Send API credentials are not set yet — payouts are disabled until they are configured.', 'chip-for-affiliatewp' );

		return $notices;
	}

	if ( chip_affiliatewp_webhook_configured() ) {
		return $notices;
	}

	$mode = affiliate_wp()->settings->get( 'chip_test_mode' ) ? 'test' : 'live';
	$keys = chip_affiliatewp_webhook_option_keys( $mode );

	$checked = absint( affiliate_wp()->settings->get( $keys['checked'], '' ) );

	if ( $checked && ( time() - $checked ) < HOUR_IN_SECONDS ) {
		// A recent attempt failed; do not retry on every page load.
		return $notices;
	}

	$result = chip_affiliatewp_ensure_webhook();

	if ( ! is_wp_error( $result ) ) {
		return $notices;
	}

	$notices[] = sprintf(
		/* translators: 1: Error message */
		__( 'CHIP Send webhook is not set up yet (%1$s). Payouts still work — statuses are requeried hourly — but confirmations arrive faster with the webhook.', 'chip-for-affiliatewp' ),
		$result->get_error_message()
	);

	return $notices;
}

/**
 * Renders one-time setup notices on AffiliateWP admin screens.
 *
 * @return void
 */
function chip_affiliatewp_admin_notices() {
	if ( ! current_user_can( 'manage_referrals' ) ) {
		return;
	}

	if ( ! affiliate_wp()->settings->get( 'chip_payouts' ) ) {
		return;
	}

	foreach ( chip_affiliatewp_webhook_setup_notices() as $notice ) {
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( $notice )
		);
	}
}
add_action( 'admin_notices', 'chip_affiliatewp_admin_notices' );
