<?php

// don't load directly
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

GFForms::include_payment_addon_framework();

class GFPayPalPaymentsPro extends GFPaymentAddOn {
	protected $_version = GF_PAYPALPAYMENTSPRO_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'gravityformspaypalpaymentspro';
	protected $_path = 'gravityformspaypalpaymentspro/paypalpaymentspro.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms PayPal Payments Pro Add-On';
	protected $_short_title = 'PayPal Payments Pro';
	protected $_supports_callbacks = true;
    protected $_requires_credit_card = true;
	protected $_enable_rg_autoupgrade = true;

	/**
	 * Members plugin integration
	 *
	 * @access protected
	 * @var    array
	 */
	protected $_capabilities = array(
		'gravityforms_paypalpaymentspro',
		'gravityforms_paypalpaymentspro_uninstall',
		'gravityforms_paypalpaymentspro_plugin_page',
	);

	/**
	 * Permissions
	 */
	protected $_capabilities_settings_page = 'gravityforms_paypalpaymentspro';
	protected $_capabilities_form_settings = 'gravityforms_paypalpaymentspro';
    protected $_capabilities_uninstall = 'gravityforms_paypalpaymentspro_uninstall';
	protected $_capabilities_plugin_page = 'gravityforms_paypalpaymentspro_plugin_page';

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFPayPalPaymentsPro
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFPayPalPaymentsPro();
		}

		return self::$_instance;
	}

	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------

	/**
	 * Includes the admin hooks.
	 *
	 * @since 2.5
	 */
	public function init_admin() {
		parent::init_admin();
		add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'filter_entry_detail_meta_boxes' ), 50, 2 );
	}

	/**
	 * Triggers updating of the entry payment status for subscriptions started before the cron job was fixed.
	 *
	 * @since 2.5
	 *
	 * @param array $meta_boxes The properties for the meta boxes.
	 * @param array $entry      The entry currently being viewed/edited.
	 *
	 * @return array
	 */
	public function filter_entry_detail_meta_boxes( $meta_boxes, $entry ) {
		if ( ! empty( $meta_boxes['payment'] ) && $this->has_subscription( $entry ) ) {
			$this->maybe_update_subscription_status( $entry );
		}

		return $meta_boxes;
	}

	/**
	 * Updates the entry payment status and sets the entry meta so the cron can check the status of active subscriptions.
	 *
	 * @since 2.5
	 *
	 * @param array $entry The entry currently being viewed/edited.
	 */
	public function maybe_update_subscription_status( $entry ) {
		$this->log_debug( __METHOD__ . "(): Checking if entry #{$entry['id']} needs to be registered with the cron for subscription status checks." );

		if ( rgar( $entry, 'payment_status' ) !== 'Active' ) {
			$this->log_debug( __METHOD__ . '(): aborting; not active' );

			return;
		}

		$meta_key = $this->get_cron_entry_meta_key();
		if ( ! empty( gform_get_meta( $entry['id'], $meta_key ) ) ) {
			$this->log_debug( __METHOD__ . '(): aborting; already has meta key' );

			return;
		}

		$feed    = $this->get_payment_feed( $entry );
		$profile = $this->get_subscription_status( $entry['transaction_id'], $feed, $entry['form_id'] );
		if ( rgar( $profile, 'RESULT' ) !== '0' ) {
			return;
		}

		$this->insert_subscription_transactions( $entry, $feed );

		switch ( strtolower( rgar( $profile, 'STATUS' ) ) ) {
			case 'active' :
				$next_timestamp = $this->get_timestamp_from_mdy( $profile['NEXTPAYMENT'] );
				gform_update_meta( $entry['id'], $meta_key, $next_timestamp );
				$this->log_debug( sprintf( '%s(): %s meta for entry #%d set to %d.', __METHOD__, $meta_key, $entry['id'], $next_timestamp ) );
				$this->add_note( $entry['id'], esc_html__( 'Entry registered for subscription status checks by the cron job.', 'gravityformspaypalpaymentspro' ) );

				return;

			case 'expired' :
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Expired' );
				$entry['payment_status'] = 'Expired';
				GFEntryDetail::set_current_entry( $entry );
				$this->log_debug( sprintf( '%s(): Entry #%d subscription status changed to expired.', __METHOD__, $entry['id'] ) );
				$this->add_note( $entry['id'], esc_html__( 'Subscription has expired.', 'gravityformspaypalpaymentspro' ) );

				return;

			default:
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Cancelled' );
				$entry['payment_status'] = 'Cancelled';
				GFEntryDetail::set_current_entry( $entry );
				$this->log_debug( sprintf( '%s(): Entry #%d subscription status changed to cancelled.', __METHOD__, $entry['id'] ) );
				$this->add_note( $entry['id'], esc_html__( 'Subscription has been cancelled.', 'gravityformspaypalpaymentspro' ) );

				return;
		}
	}

	/**
	 * Adds the subscription transactions to the database.
	 *
	 * The setup fee was added during form submission by GFPayPalPaymentsPro::process_subscription().
	 *
	 * @since 2.5
	 *
	 * @param array       $entry The current entry.
	 * @param array|false $feed  The feed which created the subscription.
	 */
	public function insert_subscription_transactions( $entry, $feed ) {
		$transactions = $this->get_subscription_transactions( $entry['transaction_id'], $feed, $entry['form_id'] );

		if ( empty( $transactions ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'gf_addon_payment_transaction';

		$inserted = array();

		foreach ( $transactions as $transaction ) {
			if ( ! $this->is_valid_transaction_state( $transaction ) ) {
				continue;
			}

			$date = date( 'Y-m-d H:i:s', strtotime( $transaction['TRANSTIME'] ) );

			$inserted[] = sprintf( '%s (%s)', $transaction['PNREF'], $date );

			$wpdb->insert(
				$table,
				array(
					'lead_id'          => $entry['id'],
					'transaction_type' => 'payment',
					'transaction_id'   => $transaction['PNREF'],
					'subscription_id'  => $entry['transaction_id'],
					'is_recurring'     => 1,
					'amount'           => $transaction['AMT'],
					'date_created'     => $date,
				),
				array(
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%f',
					'%s',
				)
			);
		}

		if ( ! empty( $inserted ) ) {
			$this->add_note( $entry['id'], sprintf( esc_html__( 'Historical transactions added: %s.', 'gravityformspaypalpaymentspro' ), implode( ', ', $inserted ) ), 'success' );
		}

	}

	// ------- Plugin settings -------

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public function get_menu_icon() {

		return file_get_contents( $this->get_base_path() . '/images/menu-icon.svg' );

	}

	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$description = '<p style="text-align: left;">' . sprintf( esc_html__( 'PayPal Payments Pro is a merchant account and gateway in one. Use Gravity Forms to collect payment information and automatically integrate to your PayPal Payments Pro account. If you don\'t have a PayPal Payments Pro account, you can %ssign up for one here.%s', 'gravityformspaypalpaymentspro' ), '<a href="https://registration.paypal.com/welcomePage.do?bundleCode=C3&country=US&partner=PayPal" target="_blank">', '</a>' ) . '</p>';

		return array(
			array(
				'description' => $description,
				'fields'      => array(
					array(
						'name'          => 'mode',
						'label'         => esc_html__( 'API', 'gravityformspaypalpaymentspro' ),
						'type'          => 'radio',
						'default_value' => 'production',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Live', 'gravityformspaypalpaymentspro' ),
								'value' => 'production',
							),
							array(
								'label' => esc_html__( 'Sandbox', 'gravityformspaypalpaymentspro' ),
								'value' => 'test',
							),
						),
						'horizontal'    => true,
					),
					array(
						'name'              => 'username',
						'label'             => esc_html__( 'Username', 'gravityformspaypalpaymentspro' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'is_valid_api_credentials' ),
					),
					array(
						'name'              => 'password',
						'label'             => esc_html__( 'Password', 'gravityformspaypalpaymentspro' ),
						'type'              => 'password',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'check_valid_api_credential_setting' ),
					),
					array(
						'name'              => 'vendor',
						'label'             => esc_html__( 'Vendor (optional)', 'gravityformspaypalpaymentspro' ),
						'description'       => esc_html__( 'Your merchant login ID if different from Username above.', 'gravityformspaypalpaymentspro' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'check_valid_api_credential_setting' ),
					),
					array(
						'name'              => 'partner',
						'label'             => esc_html__( 'Partner', 'gravityformspaypalpaymentspro' ),
						'description'       => esc_html__( 'If you have registered with a PayPal Reseller, enter their ID above.', 'gravityformspaypalpaymentspro' ),
						'type'              => 'text',
						'class'             => 'medium',
						'default_value'     => 'PayPal',
						'feedback_callback' => array( $this, 'check_valid_api_credential_setting' ),
					),
				),
			),
		);
	}

	/**
	 * Define the markup for the password type field.
	 *
	 * @param array     $field The field properties.
	 * @param bool|true $echo  Should the setting markup be echoed.
	 *
	 * @return string|void
	 */
	public function settings_password( $field, $echo = true ) {

		$field['type'] = 'text';

		$password_field = $this->settings_text( $field, false );

		//switch type="text" to type="password" so the password is not visible
		$password_field = str_replace( 'type="text"','type="password"', $password_field );

		if ( $echo ) {
			echo $password_field;
		}

		return $password_field;

	}

	//-------- Form Settings ---------

	/**
	 * Prevent feeds being listed or created if the api keys aren't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return $this->is_valid_api_credentials();
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page.
	 *
	 * @return array The feed settings.
	 */
	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		// Remove default options before adding custom.
		$default_settings = parent::remove_field( 'options', $default_settings );
		$default_settings = parent::remove_field( 'billingCycle', $default_settings );
		$default_settings = parent::remove_field( 'trial', $default_settings );

		// Add pay period if subscription.
		if ( $this->get_setting( 'transactionType' ) == 'subscription' ) {
			$pay_period_field = array(
				'name'    => 'payPeriod',
				'label'   => esc_html__( 'Pay Period', 'gravityformspaypalpaymentspro' ),
				'type'    => 'select',
				'choices' => array(
					array(
						'label' => esc_html__( 'Weekly', 'gravityformspaypalpaymentspro' ),
						'value' => 'WEEK',
					),
					array(
						'label' => esc_html__( 'Every Two Weeks', 'gravityformspaypalpaymentspro' ),
						'value' => 'BIWK',
					),
					array(
						'label' => esc_html__( 'Twice Every Month', 'gravityformspaypalpaymentspro' ),
						'value' => 'SMMO',
					),
					array(
						'label' => esc_html__( 'Every Four Weeks', 'gravityformspaypalpaymentspro' ),
						'value' => 'FRWK',
					),
					array(
						'label' => esc_html__( 'Monthly', 'gravityformspaypalpaymentspro' ),
						'value' => 'MONT',
					),
					array(
						'label' => esc_html__( 'Quarterly', 'gravityformspaypalpaymentspro' ),
						'value' => 'QTER',
					),
					array(
						'label' => esc_html__( 'Twice Every Year', 'gravityformspaypalpaymentspro' ),
						'value' => 'SMYR',
					),
					array(
						'label' => esc_html__( 'Yearly', 'gravityformspaypalpaymentspro' ),
						'value' => 'YEAR',
					),
				),
				'tooltip' => '<h6>' . esc_html__( 'Pay Period', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Select pay period.  This determines how often the recurring payment should occur.', 'gravityformspaypalpaymentspro' ),
			);
			$default_settings = $this->add_field_after( 'recurringAmount', $pay_period_field, $default_settings );

			// Add post fields if form has a post.
			$form = $this->get_current_form();

			if ( GFCommon::has_post_field( $form['fields'] ) ) {
				$post_settings = array(
					'name'    => 'post_checkboxes',
					'label'   => esc_html__( 'Posts', 'gravityformspaypalpaymentspro' ),
					'type'    => 'checkbox',
					'tooltip' => '<h6>' . esc_html__( 'Posts', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Enable this option if you would like to change the post status when a subscription is cancelled.', 'gravityformspaypalpaymentspro' ),
					'choices' => array(
						array(
							'label'    => esc_html__( 'Update Post when subscription is cancelled.', 'gravityformspaypalpaymentspro' ),
							'name'     => 'change_post_status',
							'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
						),
					),
				);

				$default_settings = $this->add_field_after( 'billingInformation', $post_settings, $default_settings );
			}
		}

		if ( $this->is_gravityforms_supported( '2.5-dev-1' ) ) {
			$dependency            = array(
				'live'   => true,
				'fields' => array(
					array(
						'field'  => 'apiSettingsEnabled',
						'values' => array( 'apiSettingsEnabled' ),
					),
				),
			);
			$api_settings_onchange = '';
			$hidden                = false;
		} else {
			$dependency            = false;
			$api_settings_onchange = "if(jQuery(this).prop('checked')){
			                                        jQuery('#gaddon-setting-row-overrideMode').show();
			                                        jQuery('#gaddon-setting-row-overrideUsername').show();
			                                        jQuery('#gaddon-setting-row-overridePassword').show();
			                                        jQuery('#gaddon-setting-row-overrideVendor').show();
			                                        jQuery('#gaddon-setting-row-overridePartner').show();
			                                    } else {
			                                        jQuery('#gaddon-setting-row-overrideMode').hide();
			                                        jQuery('#gaddon-setting-row-overrideUsername').hide();
			                                        jQuery('#gaddon-setting-row-overridePassword').hide();
			                                        jQuery('#gaddon-setting-row-overrideVendor').hide();
			                                        jQuery('#gaddon-setting-row-overridePartner').hide();
			                                        jQuery('#overrideUsername').val('');
			                                        jQuery('#overridePassword').val('');
			                                        jQuery('#overrideVendor').val('');
			                                        //jQuery('#overridePartner').val('');
			                                        jQuery('i').removeClass('icon-check fa-check gf_valid');
			                                    }";
			$hidden                = ! $this->get_setting( 'apiSettingsEnabled' );
		}

		$fields = array(
			array(
				'name'     => 'apiSettingsEnabled',
				'label'    => esc_html__( 'API Settings', 'gravityformspaypalpaymentspro' ),
				'type'     => 'checkbox',
				'tooltip'  => '<h6>' . esc_html__( 'API Settings', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Override the settings provided on the PayPal Payments Pro Settings page and use these instead for this feed.', 'gravityformspaypalpaymentspro' ),
				'choices'  => array(
					array(
						'label' => 'Override Default Settings',
						'name'  => 'apiSettingsEnabled',
					),
				),
				'onchange' => $api_settings_onchange,
			),
			array(
				'name'       => 'overrideMode',
				'label'      => esc_html__( 'API', 'gravityformspaypalpaymentspro' ),
				'type'       => 'radio',
				'tooltip'    => '<h6>' . esc_html__( 'API', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Select either Production or Sandbox API to override the chosen mode on the PayPal Payments Pro Settings page.', 'gravityformspaypalpaymentspro' ),
				'choices'    => array(
					array(
						'label' => esc_html__( 'Production', 'gravityformspaypalpaymentspro' ),
						'value' => 'production',
					),
					array(
						'label' => esc_html__( 'Sandbox', 'gravityformspaypalpaymentspro' ),
						'value' => 'test',
					),
				),
				'horizontal' => true,
				'dependency' => $dependency,
				'hidden'     => $hidden,
			),
			array(
				'name'              => 'overrideUsername',
				'label'             => esc_html__( 'Username', 'gravityformspaypalpaymentspro' ),
				'type'              => 'text',
				'class'             => 'medium',
				'tooltip'           => '<h6>' . esc_html__( 'Username', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Enter a new value to override the Username on the PayPal Payments Pro Settings page.', 'gravityformspaypalpaymentspro' ),
				'feedback_callback' => array( $this, 'is_valid_override_credentials' ),
				'dependency'        => $dependency,
				'hidden'            => $hidden,
			),
			array(
				'name'              => 'overridePassword',
				'label'             => esc_html__( 'Password', 'gravityformspaypalpaymentspro' ),
				'type'              => 'password',
				'class'             => 'medium',
				'tooltip'           => '<h6>' . esc_html__( 'Password', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Enter a new value to override the Password on the PayPal Payments Pro Settings page.', 'gravityformspaypalpaymentspro' ),
				'feedback_callback' => array( $this, 'check_valid_override_credential_setting' ),
				'dependency'        => $dependency,
				'hidden'            => $hidden,
			),
			array(
				'name'              => 'overrideVendor',
				'label'             => esc_html__( 'Vendor (optional)', 'gravityformspaypalpaymentspro' ),
				'type'              => 'text',
				'description'       => esc_html__( 'Your merchant login ID if different from Username above.', 'gravityformspaypalpaymentspro' ),
				'class'             => 'medium',
				'tooltip'           => '<h6>' . esc_html__( 'Vendor', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Enter a new value to override the Vendor on the PayPal Payments Pro Settings page.', 'gravityformspaypalpaymentspro' ),
				'feedback_callback' => array( $this, 'check_valid_override_credential_setting' ),
				'dependency'        => $dependency,
				'hidden'            => $hidden,
			),
			array(
				'name'              => 'overridePartner',
				'label'             => esc_html__( 'Partner', 'gravityformspaypalpaymentspro' ),
				'description'       => esc_html__( 'If you have registered with a PayPal Reseller, enter their ID above.', 'gravityformspaypalpaymentspro' ),
				'type'              => 'text',
				'class'             => 'medium',
				'tooltip'           => '<h6>' . esc_html__( 'Partner', 'gravityformspaypalpaymentspro' ) . '</h6>' . esc_html__( 'Enter a new value to override the Partner on the PayPal Payments Pro Settings page.', 'gravityformspaypalpaymentspro' ),
				'default_value'     => 'PayPal',
				'feedback_callback' => array( $this, 'check_valid_override_credential_setting' ),
				'dependency'        => $dependency,
				'hidden'            => $hidden,
			),

		);

		$default_settings = $this->add_field_after( 'conditionalLogic', $fields, $default_settings );

		return $default_settings;
	}

	/**
	 * Returns the markup for the change post status checkbox item.
	 *
	 * @param array  $choice     The choice properties.
	 * @param string $attributes The attributes for the input tag.
	 * @param string $value      Currently selection (1 if field has been checked. 0 or null otherwise).
	 * @param string $tooltip    The tooltip for this checkbox item.
	 *
	 * @return string
	 */
	public function checkbox_input_change_post_status( $choice, $attributes, $value, $tooltip ) {
		$markup = $this->checkbox_input( $choice, $attributes, $value, $tooltip );

		$dropdown_field = array(
			'name'     => 'update_post_action',
			'choices'  => array(
				array( 'label' => '' ),
				array( 'label' => esc_html__( 'Mark Post as Draft', 'gravityformspaypalpaymentspro' ), 'value' => 'draft' ),
				array( 'label' => esc_html__( 'Delete Post', 'gravityformspaypalpaymentspro' ), 'value' => 'delete' ),

			),
			'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
		);
		$markup .= '&nbsp;&nbsp;' . $this->settings_select( $dropdown_field, false );

		return $markup;
	}

	/**
	 * Prepend the name fields to the default billing_info_fields added by the framework.
	 *
	 * @return array
	 */
	public function billing_info_fields() {
		$fields = array(
				array(
						'name'     => 'lastName',
						'label'    => esc_html__( 'Last Name', 'gravityformspaypalpaymentspro' ),
						'required' => false,
				),
				array(
						'name'     => 'firstName',
						'label'    => esc_html__( 'First Name', 'gravityformspaypalpaymentspro' ),
						'required' => false,
				),
		);

		return array_merge( $fields, parent::billing_info_fields() );
	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events( $form ) {
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}

		return array(
				'complete_payment'          => esc_html__( 'Payment Completed', 'gravityformspaypalpaymentspro' ),
				'create_subscription'       => esc_html__( 'Subscription Created', 'gravityformspaypalpaymentspro' ),
				'cancel_subscription'       => esc_html__( 'Subscription Canceled', 'gravityformspaypalpaymentspro' ),
				'expire_subscription'       => esc_html__( 'Subscription Expired', 'gravityformspaypalpaymentspro' ),
				'add_subscription_payment'  => esc_html__( 'Subscription Payment Added', 'gravityformspaypalpaymentspro' ),
		);
	}

	// -------- Entry Detail ---------

	/**
	 * Handle cancelling the subscription from the entry detail page.
	 *
	 * @param array $entry The entry object currently being processed.
	 * @param array $feed  The feed object currently being processed.
	 *
	 * @return bool
	 */
	public function cancel( $entry, $feed ) {

		$args = array( 'TRXTYPE'       => 'R',
		               'TENDER'        => 'C',
		               'ORIGPROFILEID' => $entry['transaction_id'],
		               'ACTION'        => 'C'
		);

		$settings = $this->get_api_settings( $feed );
		$response = $this->post_to_payflow( $args, $settings, $entry['form_id'] );

		if ( ! empty( $response ) && $response['RESULT'] == '0' ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the current entry was processed by this add-on.
	 *
	 * @param int $entry_id The ID of the current Entry.
	 *
	 * @return bool
	 */
	public function is_payment_gateway( $entry_id ) {

		if ( $this->is_payment_gateway ) {
			return true;
		}

		$gateway = gform_get_meta( $entry_id, 'payment_gateway' );

		return in_array( $gateway, array( 'paypalpaymentspro', $this->_slug ) );
	}


	// # SUBMISSION ----------------------------------------------------------------------------------------------------

	/**
	 * Authorize and capture the transaction for the product & services type feed.
	 *
	 * @param array $feed            The feed object currently being processed.
	 * @param array $submission_data The customer and transaction data.
	 * @param array $form            The form object currently being processed.
	 * @param array $entry           The entry object currently being processed.
	 *
	 * @return array
	 */
	public function authorize( $feed, $submission_data, $form, $entry ) {

		// Credit Card Information.
		$args = $this->prepare_credit_card_transaction( $feed, $submission_data, $form, $entry );

		// Setting up sale transaction parameters.
		$args['TRXTYPE'] = 'S';

		/**
		 * Filter the transaction properties for the product and service feed.
		 *
		 * @since 1.0.0
		 * @since 2.0.0 Added the $submission_data, $feed, and $entry parameters.
		 *
		 * @param array $args            The transaction properties.
		 * @param int   $form_id         The ID of the form currently being processed.
		 * @param array $submission_data The customer and transaction data.
		 * @param array $feed            The feed object currently being processed.
		 * @param array $entry           The entry object currently being processed.
		 */
		$args = apply_filters( 'gform_paypalpaymentspro_args_before_payment', $args, $form['id'], $submission_data, $feed, $entry );

		if ( empty( $args['ACCT'] ) ) {
			return array(
				'is_authorized' => false,
				'error_message' => esc_html__( 'Please enter your credit card information.', 'gravityformspaypalpaymentspro' ),
			);
		}

		$settings = $this->get_api_settings( $feed );
		$response = $this->post_to_payflow( $args, $settings, $form['id'] );

		if ( isset( $response['RESULT'] ) && $response['RESULT'] == 0 ) {
			$this->log_debug( __METHOD__ . "(): Funds captured successfully. Amount: {$args['AMT']}. Transaction Id: {$response['PNREF']}." );
			$captured_payment = array(
				'is_success'     => true,
				'error_message'  => '',
				'transaction_id' => $response['PNREF'],
				'amount'         => $args['AMT'],
			);
			$auth = array(
				'is_authorized'    => true,
				'transaction_id'   => $response['PNREF'],
				'captured_payment' => $captured_payment,
			);

			$config = $this->get_config( $feed, $submission_data );

			/**
			 * @deprecated
			 */
			do_action( 'gform_paypalpaymentspro_post_capture', $args['AMT'], $entry, $form, $config );

		} else {
			$this->log_error( __METHOD__ . '(): Funds could not be captured.' );
			$auth = array(
				'is_success'     => false,
				'transaction_id' => $response['PNREF'],
				'error_message'  => $response['RESPMSG'],
			);
		}

		return $auth;

	}

	/**
	 * Create a recurring profile for the user and return any errors which occur.
	 *
	 * @param array $feed            The feed object currently being processed.
	 * @param array $submission_data The customer and transaction data.
	 * @param array $form            The form object currently being processed.
	 * @param array $entry           The entry object currently being processed.
	 *
	 * @return array
	 */
	public function subscribe( $feed, $submission_data, $form, $entry ) {

		$subscription = $this->prepare_credit_card_transaction( $feed, $submission_data, $form, $entry );

		// Setting up recurring transaction parameters
		$subscription['TRXTYPE'] = 'R';
		$subscription['ACTION']  = 'A';

		$subscription['START']             = date( 'mdY', mktime( 0, 0, 0, date( 'm' ), date( 'd' ) + 1, date( 'y' ) ) );
		$subscription['PROFILENAME']       = $subscription['FIRSTNAME'] . ' ' . $subscription['LASTNAME'];
		$subscription['MAXFAILEDPAYMENTS'] = '0';
		$subscription['PAYPERIOD']         = $feed['meta']['payPeriod'];
		$subscription['TERM']              = $feed['meta']['recurringTimes'];
		$subscription['AMT']               = $submission_data['payment_amount'];

		if ( $feed['meta']['setupFee_enabled'] && ! empty( $submission_data['setup_fee'] ) && $submission_data['setup_fee'] > 0 ) {
			$subscription['OPTIONALTRX']    = 'S';
			$subscription['OPTIONALTRXAMT'] = $submission_data['setup_fee'];
		} else {
			$subscription['OPTIONALTRX'] = 'A';
		}

		/**
		 * Filter the subscription transaction properties.
		 *
		 * @since 1.0.0
		 * @since 2.0.0 Added the $submission_data, $feed, and $entry parameters.
		 *
		 * @param array $subscription    The subscription transaction properties.
		 * @param int   $form_id         The ID of the form currently being processed.
		 * @param array $submission_data The customer and transaction data.
		 * @param array $feed            The feed object currently being processed.
		 * @param array $entry           The entry object currently being processed.
		 */
		$subscription = apply_filters( 'gform_paypalpaymentspro_args_before_subscription', $subscription, $form['id'], $submission_data, $feed, $entry );

		if ( empty( $subscription['ACCT'] ) ) {
			return array(
				'is_success' => false,
				'error_message' => esc_html__( 'Please enter your credit card information.', 'gravityformspaypalpaymentspro' ),
			);
		}

		$this->log_debug( __METHOD__ . '(): Creating recurring profile.' );
		$settings = $this->get_api_settings( $feed );
		$response = $this->post_to_payflow( $subscription, $settings, $form['id'] );

		if ( $response['RESULT'] == 0 ) {

			$subscription_id = $response['PROFILEID'];
			$this->log_debug( __METHOD__ . "(): Subscription created successfully. Subscription Id: {$subscription_id}" );

			if ( $feed['meta']['setupFee_enabled'] ) {
				$captured_payment    = array(
						'is_success'     => true,
						'transaction_id' => rgar( $response, 'RPREF' ),
						'amount'         => $submission_data['setup_fee'],
				);
				$subscription_result = array(
					'is_success'              => true,
					'subscription_id'         => $subscription_id,
					'captured_payment'        => $captured_payment,
					'amount'                  => $subscription['AMT'],
					'subscription_start_date' => $subscription['START'],
				);
			} else {
				$subscription_result = array(
					'is_success'              => true,
					'subscription_id'         => $subscription_id,
					'amount'                  => $subscription['AMT'],
					'subscription_start_date' => $subscription['START'],
				);
			}

		} else {
			$this->log_error( __METHOD__ . '(): There was an error creating Subscription.' );
			$error_message       = $this->get_error_message( $response );
			$subscription_result = array( 'is_success' => false, 'error_message' => $error_message );
		}

		return $subscription_result;
	}

	/**
	 * Processes the subscription after the entry has been saved.
	 *
	 * @since 2.5 Overrode to fix cron not processing entries due to missing entry meta.
	 *
	 * @param array $authorization   The subscription details from GFPayPalPaymentsPro::subscribe().
	 * @param array $feed            The feed which created the subscription.
	 * @param array $submission_data The submission data which was used to create the subscription.
	 * @param array $form            The form currently being processed.
	 * @param array $entry           The entry currently being processed.
	 *
	 * @return array
	 */
	public function process_subscription( $authorization, $feed, $submission_data, $form, $entry ) {
		gform_update_meta( $entry['id'], $this->get_cron_entry_meta_key(), $this->get_timestamp_from_mdy( $authorization['subscription']['subscription_start_date'] ) );

		return parent::process_subscription( $authorization, $feed, $submission_data, $form, $entry );
	}


	// # CRON JOB ------------------------------------------------------------------------------------------------------

	/**
	 * Check subscription status; Active subscriptions will be checked to see if their status needs to be updated.
	 *
	 * @since 2.0
	 * @since 2.5 Fixed cron not processing subscriptions created by v2.0+.
	 */
	public function check_status() {

		$query_timestamp = time();
		$meta_key        = $this->get_cron_entry_meta_key();

		$this->log_debug( sprintf( '%s(): Searching for entries with active subscriptions and %s meta less than %d.', __METHOD__, $meta_key, $query_timestamp ) );

		// Get entry table names and entry ID column.
		$entry_table      = self::get_entry_table_name();
		$entry_meta_table = self::get_entry_meta_table_name();
		$entry_id_column  = version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? 'lead_id' : 'entry_id';

		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id, l.form_id, l.transaction_id, m.meta_value as payment_date
			FROM {$entry_table} l INNER JOIN {$entry_meta_table} m ON l.id = m.{$entry_id_column}
			WHERE l.transaction_type = 2 AND l.payment_status = 'Active' AND meta_key = %s AND meta_value < %d",
			$meta_key, $query_timestamp
		) );

		if ( empty( $results ) ) {
			$this->log_debug( __METHOD__ . '(): No matching entries found.' );

			return;
		}

		$this->log_debug( sprintf( '%s(): Checking subscription statuses for %d entries.', __METHOD__, count( $results ) ) );

		foreach ( $results as $result ) {
			$this->log_debug( __METHOD__ . '(): Processing entry => ' . json_encode( $result ) );

			$entry_id = $result->id;
			$feed     = $this->get_payment_feed( array( 'id' => $entry_id ) );
			$profile  = $this->get_subscription_status( $result->transaction_id, $feed, $result->form_id );

			if ( rgar( $profile, 'RESULT' ) !== '0' ) {
				continue;
			}

			$entry           = GFAPI::get_entry( $entry_id );
			$status          = $profile['STATUS'];
			$subscription_id = $profile['PROFILEID'];

			switch ( strtolower( $status ) ) {
				case 'active' :
					if ( $result->payment_date == $this->get_timestamp_from_mdy( $profile['LASTCHANGED'] ) ) {
						gform_update_meta( $entry_id, $meta_key, $this->get_timestamp_from_mdy( $profile['NEXTPAYMENT'] ) );
						if ( $this->process_last_subscription_transaction( $entry, $feed ) ) {
							//deprecated
							do_action( 'gform_paypalpaymentspro_after_subscription_payment', $entry, $subscription_id, $profile['AMT'] );
						}
					} else {
						$this->log_debug( __METHOD__ . "(): No change to subscription for entry #{$entry_id}." );
					}

					break;

				case 'expired' :
					gform_delete_meta( $entry['id'], $meta_key );
					$this->process_last_subscription_transaction( $entry, $feed );

					$action = array(
						'subscription_id' => $subscription_id,
						'type'            => 'expire_subscription'
					);
					$this->expire_subscription( $entry, $action );

					//deprecated
					do_action( 'gform_paypalpaymentspro_subscription_expired', $entry, $subscription_id );

					break;

				default:
					gform_delete_meta( $entry['id'], $meta_key );
					$this->process_last_subscription_transaction( $entry, $feed );
					$this->cancel_subscription( $entry, $feed );
					do_action( 'gform_paypalpaymentspro_subscription_canceled', $entry, $subscription_id );
					break;
			}

		}

	}

	/**
	 * Returns the entry meta key used by the cron when finding the entries to process.
	 *
	 * @since 2.5
	 *
	 * @return string
	 */
	public function get_cron_entry_meta_key() {
		return $this->get_slug() . '_cron_timestamp';
	}

	/**
	 * Returns the timestamp for the supplied date string.
	 *
	 * @since 2.5
	 *
	 * @param string $mdy_date A date in the mdY format.
	 *
	 * @return false|int
	 */
	public function get_timestamp_from_mdy( $mdy_date ) {
		preg_match( '/(?<month>[\d]{2})(?<day>[\d]{2})(?<year>[\d]{4})/', $mdy_date, $matches );
		$date = sprintf( '%s-%s-%s 00:00:00', $matches['year'], $matches['month'], $matches['day'] );

		return strtotime( $date );
	}

	/**
	 * Requests the current status of the subscription from PayPal.
	 *
	 * @since 2.5
	 *
	 * @param string $profile_id          The PayPal profile ID for the subscription (The entry transaction ID).
	 * @param array  $feed                The feed which created the subscription.
	 * @param int    $form_id             The ID of the form which created the subscription.
	 * @param bool   $return_transactions Indicates if the transactions should be returned instead of subscription profile. Default is false.
	 *
	 * @return array
	 */
	public function get_subscription_status( $profile_id, $feed, $form_id, $return_transactions = false ) {
		$request_args = array(
			'TRXTYPE'       => 'R',
			'TENDER'        => 'C',
			'ACTION'        => 'I',
			'ORIGPROFILEID' => $profile_id,
		);

		if ( $return_transactions ) {
			$request_args['PAYMENTHISTORY'] = 'Y';
		}

		return $this->post_to_payflow( $request_args, $this->get_api_settings( $feed ), $form_id );
	}

	/**
	 * Requests the recurring transactions for the specified subscription from PayPal.
	 *
	 * @since 2.5
	 *
	 * @param string $profile_id The PayPal profile ID for the subscription (The entry transaction ID).
	 * @param array  $feed       The feed which created the subscription.
	 * @param int    $form_id    The ID of the form which created the subscription.
	 *
	 * @return array
	 */
	public function get_subscription_transactions( $profile_id, $feed, $form_id ) {
		$transactions = array();
		$history      = $this->get_subscription_status( $profile_id, $feed, $form_id, true );

		if ( rgar( $history, 'RESULT' ) !== '0' ) {
			return $transactions;
		}

		for ( $n = 1; ; $n ++ ) {
			if ( ! isset( $history[ 'P_PNREF' . $n ] ) ) {
				break;
			}

			$transactions[] = array(
				'RESULT'    => rgar( $history, 'P_RESULT' . $n ),
				'PNREF'     => rgar( $history, 'P_PNREF' . $n ),
				'TRANSTATE' => rgar( $history, 'P_TRANSTATE' . $n ),
				'TENDER'    => rgar( $history, 'P_TENDER' . $n ),
				'TRANSTIME' => rgar( $history, 'P_TRANSTIME' . $n ),
				'AMT'       => rgar( $history, 'P_AMT' . $n ),
			);
		}

		return $transactions;
	}

	/**
	 * Determines if the transaction was successful.
	 *
	 * @since 2.5
	 *
	 * @param array $transaction The transaction properties from PayPal.
	 *
	 * @return bool
	 */
	public function is_valid_transaction_state( $transaction ) {
		return ( $transaction['RESULT'] === '0' && $transaction['TRANSTATE'] >= 6 && $transaction['TRANSTATE'] <= 8 );
	}

	/**
	 * Adds the subscription payment.
	 *
	 * @since 2.5
	 *
	 * @param array $entry  The entry which created the subscription.
	 * @param array $action The transaction data..
	 */
	public function add_subscription_payment( $entry, $action = array() ) {
		$action['subscription_id'] = $entry['transaction_id'];
		$action['type']            = 'add_subscription_payment';
		parent::add_subscription_payment( $entry, $action );
	}

	/**
	 * Handles processing a subscription payment or failure, if the transaction occurred within the last 24 hours.
	 *
	 * @since 2.5
	 *
	 * @param array $entry The entry which created the subscription.
	 * @param array $feed  The feed which created the subscription.
	 *
	 * @return bool
	 */
	public function process_last_subscription_transaction( $entry, $feed ) {
		$transactions = $this->get_subscription_transactions( $entry['transaction_id'], $feed, $entry['form_id'] );

		if ( empty( $transactions ) ) {
			return false;
		}

		$last_transaction          = end( $transactions );
		$seconds_since_transaction = time() - strtotime( $last_transaction['TRANSTIME'] );

		if ( $seconds_since_transaction < DAY_IN_SECONDS ) {
			$action = array(
				'amount'         => $last_transaction['AMT'],
				'transaction_id' => $last_transaction['PNREF'],
			);

			if ( $this->is_valid_transaction_state( $last_transaction ) ) {
				$this->add_subscription_payment( $entry, $action );

				return true;
			} else {
				$action['type'] = 'fail_subscription_payment';
				$this->fail_subscription_payment( $entry, $action );
			}
		}

		return false;
	}


	// # HELPERS -------------------------------------------------------------------------------------------------------

	/**
	 * Retrieve the settings to use when making the request to PayPal API.
	 *
	 * @param bool|array $feed False or the feed currently being processed.
	 *
	 * @return array
	 */
	public function get_api_settings( $feed = false ) {
		if ( ! $feed ) {
			$feed = $this->current_feed;
		}

		if ( $feed && rgars( $feed, 'meta/apiSettingsEnabled' ) ) {
			$meta     = $feed['meta'];
			$settings = array(
				'mode'     => rgar( $meta, 'overrideMode' ),
				'username' => rgar( $meta, 'overrideUsername' ),
				'password' => rgar( $meta, 'overridePassword' ),
				'vendor'   => rgar( $meta, 'overrideVendor' ),
				'partner'  => rgar( $meta, 'overridePartner' ),
			);
		} else {
			$settings = $this->get_plugin_settings();
		}

		return $settings;
	}

	/**
	 * Maybe validate the override credentials.
	 *
	 * @return bool
	 */
	public function is_valid_override_credentials() {
		//get override credentials
		global $valid_override_username;
		$valid_override_username = false;

		$apiSettingsEnabled = $this->get_setting( 'apiSettingsEnabled' );
		if ( $apiSettingsEnabled ) {
			$custom_settings['mode']     = $this->get_setting( 'overrideMode' );
			$custom_settings['username'] = $this->get_setting( 'overrideUsername' );
			$custom_settings['password'] = $this->get_setting( 'overridePassword' );
			$custom_settings['vendor']   = $this->get_setting( 'overrideVendor' );
			$custom_settings['partner']  = $this->get_setting( 'overridePartner' );

			$valid_override_username = $this->is_valid_credentials( $custom_settings );
		}

		return $valid_override_username;
	}

	/**
	 * Validate the API credentials.
	 *
	 * @return bool
	 */
	public function is_valid_api_credentials() {
		//get api credentials
		$settings = $this->get_plugin_settings();
		global $valid_username;
		$valid_username = $this->is_valid_credentials( $settings );

		return $valid_username;
	}

	/**
	 * Validate the credentials.
	 *
	 * @param array $settings The plugin settings.
	 *
	 * @return bool
	 */
	public function is_valid_credentials( $settings ) {
		$args = array( 'TRXTYPE' => 'A', 'TENDER' => 'C' );

		if ( ! empty( $settings ) ) {
			$response = $this->post_to_payflow( $args, $settings );
		}

		if ( ! empty( $response ) && $response['RESULT'] != '1' && $response['RESPMSG'] != 'User authentication failed' ) {
			//$valid_username = true;
			return true;
		} else {
			//$valid_username = false;
			return false;
		}

	}

	/**
	 * Helper to check if the vendor, partner and password settings are valid.
	 *
	 * @return bool
	 */
	public function check_valid_api_credential_setting() {
		global $valid_username;

		return $valid_username;
	}

	/**
	 * Helper to check if the override vendor, partner and password settings are valid.
	 *
	 * @return bool
	 */
	public function check_valid_override_credential_setting() {
		global $valid_override_username;

		return $valid_override_username;
	}

	/**
	 * Post to the Payflow API.
	 *
	 * @param array    $nvp      The transaction arguments.
	 * @param array    $settings The plugin settings.
	 * @param null|int $form_id  The ID of the current Form.
	 *
	 * @return array
	 */
	public function post_to_payflow( $nvp = array(), $settings = array(), $form_id = null ) {
		// Set up your API credentials and PayFlow Pro end point.
		if ( ! empty( $settings ) ) {
			$API_UserName = $settings['username'];
			$API_Password = $settings['password'];
			$Vendor       = $settings['vendor'];
			$Vendor       = empty( $Vendor ) ? $API_UserName : $Vendor;
			$Partner      = $settings['partner'];
			$Partner      = empty( $Partner ) ? 'PayPal' : $Partner;
			$mode         = $settings['mode'];

			$API_Endpoint = $mode == 'test' ? 'https://pilot-payflowpro.paypal.com' : 'https://payflowpro.paypal.com';

			$api_info = compact( 'API_Endpoint', 'API_UserName', 'API_Password', 'Vendor', 'Partner' );
			$api_info = apply_filters( 'gform_paypalpaymentspro_api_before_send', $api_info, $form_id );
			extract( $api_info );

			// Set the curl parameters.
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $API_Endpoint );
			curl_setopt( $ch, CURLOPT_VERBOSE, 1 );


			/**
			 * Determines if the cURL CURLOPT_SSL_VERIFYPEER option is enabled.
			 *
			 * @since 2.2
			 *
			 * @param bool $is_enabled True to enable peer verification. False to bypass peer verification. Defaults to true.
			 */
			$verify_peer = apply_filters( 'gform_paypalpaymentspro_verifypeer', true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $verify_peer );

			/**
			 * Determines if the cURL CURLOPT_SSL_VERIFYHOST option is enabled.
			 *
			 * @since 2.2
			 *
			 * @param bool $is_enabled True to enable host verification. False to bypass host verification. Defaults to true.
			 */
			$verify_host = apply_filters( 'gform_paypalpaymentspro_verifyhost', true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $verify_host ? 2 : 0 );

			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_POST, 1 );

			$nvp = apply_filters( 'gform_paypalpaymentspro_args_before_send', $nvp, $form_id );

			$nvpstr = '';
			if ( is_array( $nvp ) ) {
				foreach ( $nvp as $key => $value ) {
					if ( is_array( $value ) ) {
						foreach ( $value as $item ) {
							if ( strlen( $nvpstr ) > 0 ) {
								$nvpstr .= '&';
							}
							$nvpstr .= "$key=" . $item;
						}
					} else {
						if ( strlen( $nvpstr ) > 0 ) {
							$nvpstr .= '&';
						}
						$nvpstr .= "$key=" . $value;
					}
				}
			}

			//add the bn code (build notation code)
			$nvpstr = "BUTTONSOURCE=Rocketgenius_SP&$nvpstr";

			// Set the API operation, version, and API signature in the request.
			$nvpreq = "VENDOR=$Vendor&PARTNER=$Partner&PWD=$API_Password&USER=$API_UserName&$nvpstr";

			$this->log_debug( __METHOD__ . "(): Sending request to PayPal - URL: {$API_Endpoint}." );

			if ( apply_filters( 'gform_paypalpaymentspro_log_api_request', false ) ) {
				$this->log_debug( __METHOD__ . "(): Request: {$nvpreq}." );
			}

			// Set the request as a POST FIELD for curl.
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $nvpreq );

			// Get response from the server.
			$httpResponse = curl_exec( $ch );

			// Extract the response details.
			$httpParsedResponseAr = array();
			if ( $httpResponse ) {
				$httpResponseAr = explode( '&', $httpResponse );
				foreach ( $httpResponseAr as $i => $value ) {
					$tmpAr = explode( '=', urldecode( $value ) );
					if ( sizeof( $tmpAr ) > 1 ) {
						$httpParsedResponseAr[ $tmpAr[0] ] = $tmpAr[1];
					}
				}

				$write_response_to_log = true;
				if ( $nvp['TRXTYPE'] == 'A' && $httpParsedResponseAr['RESULT'] == '23' ) {
					$write_response_to_log = false;
				}
				if ( $write_response_to_log ) {
					$this->log_debug( __METHOD__ . '(): Response from PayPal: ' . $httpResponse );
					$this->log_debug( __METHOD__ . '(): Friendly view of response: ' . print_r( $httpParsedResponseAr, true ) );
				}
			} else {
				$this->log_error( __METHOD__ . '(): Request failed; ' . curl_error( $ch ) . '; ' . json_encode( curl_getinfo( $ch ) ) );
			}

			return $httpParsedResponseAr;
		}
	}

	/**
	 * Prepare the transaction arguments.
	 *
	 * @param array $feed            The feed object currently being processed.
	 * @param array $submission_data The customer and transaction data.
	 * @param array $form            The form object currently being processed.
	 * @param array $entry           The entry object currently being processed.
	 *
	 * @return array
	 */
	public function prepare_credit_card_transaction( $feed, $submission_data, $form, $entry ) {

		$feed_name = rgar( $feed['meta'], 'feedName' );
		$this->log_debug( __METHOD__ . "(): Preparing transaction arguments based on feed #{$feed['id']} - {$feed_name}." );
		$this->log_debug( __METHOD__ . '(): $submission_data line_items => ' . print_r( $submission_data['line_items'], 1 ) );

		// Billing Information
		$card_number     = $submission_data['card_number'];
		$expiration_date = str_pad( $submission_data['card_expiration_date'][0], 2, '0', STR_PAD_LEFT ) . substr( $submission_data['card_expiration_date'][1], - 2 );; // ?? correct format ??
		$country               = $submission_data['country'];
		$country               = GFCommon::get_country_code( $country );
		$args                  = array();
		$args['ACCT']          = $card_number;
		$args['EXPDATE']       = $expiration_date;
		$args['CVV2']          = $submission_data['card_security_code'];
		$args['STREET']        = $submission_data['address'];
		$args['BILLTOSTREET2'] = $submission_data['address2'];
		$args['CITY']          = $submission_data['city'];
		$args['STATE']         = $submission_data['state'];
		$args['ZIP']           = $submission_data['zip'];
		$args['BILLTOCOUNTRY'] = $country == 'UK' ? 'GB' : $country;
		$args['CURRENCY']      = GFCommon::get_currency();

		// Customer Information
		$args['FIRSTNAME'] = $submission_data['firstName'];
		$args['LASTNAME']  = $submission_data['lastName'];
		$args['EMAIL']     = $submission_data['email'];

		// Product Information
		$i            = 0;
		$args['DESC'] = '';
		foreach ( $submission_data['line_items'] as $line_item ) {
			if ( $feed['meta']['transactionType'] == 'product' ) {
				$args["L_NAME$i"]   = $line_item['name'];
				$args["L_DESC$i"]   = $line_item['description'];
				$args["L_AMT$i"]    = $line_item['unit_price'];
				$args["L_NUMBER$i"] = $i + 1;
				$args["L_QTY$i"]    = $line_item['quantity'];
			} else {
				$args['DESC'] .= $i > 1 ? ', ' . $line_item['name'] : $line_item['name']; // ?? TO DO figure out why there is warning that desc is undefined
			}
			$i ++;

		}

		$args['AMT']    = $submission_data['payment_amount'];
		$args['TENDER'] = 'C';

		return $args;
	}

	/**
	 * Prepare the appropriate error message for the transaction result.
	 *
	 * @param array $response The response from the Payflow API.
	 *
	 * @return string
	 */
	public function get_error_message( $response ) {
		$code = $response['RESULT'];

		switch ( $code ) {
			case '50' :
				$message = esc_html__( 'This credit card has been declined by your bank. Please use another form of payment.', 'gravityformspaypalpaymentspro' );
				break;

			case '24' :
				$message = esc_html__( 'The credit card has expired.', 'gravityformspaypalpaymentspro' );
				break;

			case '1021' :
				$message = esc_html__( 'The merchant does not accept this type of credit card.', 'gravityformspaypalpaymentspro' );
				break;

			case "12" :
			case "23" :
				$message = esc_html__( 'There was an error processing your credit card. Please verify the information and try again.', 'gravityformspaypalpaymentspro' );
				break;

			default :
				$message = esc_html__( 'There was an error processing your request. Your credit card was not charged. Please try again.', 'gravityformspaypalpaymentspro' );
		}

		$message = '<!-- Error: ' . $code . ' -->' . $message;

		return $message;
	}

	/**
	 * Convert feed into config for hooks backwards compatibility.
	 *
	 * @param array $feed            The current feed object.
	 * @param array $submission_data The customer and transaction data.
	 *
	 * @return array
	 */
	private function get_config( $feed, $submission_data ) {

		$config = array();

		$config['id']        = $feed['id'];
		$config['form_id']   = $feed['form_id'];
		$config['is_active'] = $feed['is_active'];

		$config['meta']['type']               = rgar( $feed['meta'], 'transactionType' );
		$config['meta']['update_post_action'] = rgar( $feed['meta'], 'update_post_action' );

		$config['meta']['paypalpaymentspro_conditional_enabled'] = rgar( $feed['meta'], 'feed_condition_conditional_logic' );
		if ( $feed['meta']['feed_condition_conditional_logic'] ) {
			$config['meta']['paypalpaymentspro_conditional_field_id'] = $feed['meta']['feed_condition_conditional_logic_object']['conditionalLogic']['rules'][0]['fieldId'];
			$config['meta']['paypalpaymentspro_conditional_operator'] = $feed['meta']['feed_condition_conditional_logic_object']['conditionalLogic']['rules'][0]['operator'];
			$config['meta']['paypalpaymentspro_conditional_value']    = $feed['meta']['feed_condition_conditional_logic_object']['conditionalLogic']['rules'][0]['value'];
		}

		$config['meta']['api_settings_enabled'] = rgar( $feed['meta'], 'apiSettingsEnabled' );
		$config['meta']['api_mode']             = rgar( $feed['meta'], 'overrideMode' );
		$config['meta']['api_username']         = rgar( $feed['meta'], 'overrideUsername' );
		$config['meta']['api_password']         = rgar( $feed['meta'], 'overridePassword' );
		$config['meta']['api_vendor']           = rgar( $feed['meta'], 'overrideVendor' );
		$config['meta']['api_partner']          = rgar( $feed['meta'], 'overridePartner' );

		$config['meta']['customer_fields']['email']    = rgar( $feed['meta'], 'billingInformation_email' );
		$config['meta']['customer_fields']['address1'] = rgar( $feed['meta'], 'billingInformation_address' );
		$config['meta']['customer_fields']['address2'] = rgar( $feed['meta'], 'billingInformation_address2' );
		$config['meta']['customer_fields']['city']     = rgar( $feed['meta'], 'billingInformation_city' );
		$config['meta']['customer_fields']['state']    = rgar( $feed['meta'], 'billingInformation_state' );
		$config['meta']['customer_fields']['zip']      = rgar( $feed['meta'], 'billingInformation_zip' );
		$config['meta']['customer_fields']['country']  = rgar( $feed['meta'], 'billingInformation_country' );

		return $config;

	}

	/**
	 * Get version of Gravity Forms database.
	 *
	 * @since  2.2.2
	 * @access public
	 *
	 * @uses   GFFormsModel::get_database_version()
	 *
	 * @return string
	 */
	public static function get_gravityforms_db_version() {

	    return method_exists( 'GFFormsModel', 'get_database_version' ) ? GFFormsModel::get_database_version() : GFForms::$version;

    }

	/**
	 * Get name for entry table.
	 *
	 * @since  2.2.2
	 * @access public
	 *
	 * @uses   GFFormsModel::get_entry_table_name()
	 * @uses   GFFormsModel::get_lead_table_name()
	 * @uses   GFPayPalPaymentsPro::get_gravityforms_db_version()
	 *
	 * @return string
	 */
	public static function get_entry_table_name() {

		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_table_name() : GFFormsModel::get_entry_table_name();

    }

	/**
	 * Get name for entry meta table.
	 *
	 * @since  2.2.2
	 * @access public
	 *
	 * @uses   GFFormsModel::get_entry_meta_table_name()
	 * @uses   GFFormsModel::get_lead_meta_table_name()
	 * @uses   GFPayPalPaymentsPro::get_gravityforms_db_version()
	 *
	 * @return string
	 */
	public static function get_entry_meta_table_name() {

		return version_compare( self::get_gravityforms_db_version(), '2.3-dev-1', '<' ) ? GFFormsModel::get_lead_meta_table_name() : GFFormsModel::get_entry_meta_table_name();

    }

	// # TO FRAMEWORK MIGRATION ----------------------------------------------------------------------------------------

	/**
	 * Checks if a previous version was installed and if the feeds need migrating to the framework structure.
	 *
	 * @param string $previous_version The version number of the previously installed version.
	 */
	public function upgrade( $previous_version ) {
		if ( empty( $previous_version ) ) {
			$previous_version = get_option( 'gf_paypalpaymentspro_version' );
		}
		$previous_is_pre_addon_framework = ! empty( $previous_version ) && version_compare( $previous_version, '2.0.dev1', '<' );

		if ( $previous_is_pre_addon_framework ) {
			$this->log_debug( __METHOD__ . '(): Copying over data to new table structure.' );
			$old_feeds = $this->get_old_feeds();

			if ( ! $old_feeds ) {
				$this->log_debug( __METHOD__ . '(): No old feeds found to copy.' );

				return;
			}

			$counter = 1;
			foreach ( $old_feeds as $old_feed ) {
				$feed_name       = 'Feed ' . $counter;
				$form_id         = $old_feed['form_id'];
				$is_active       = $old_feed['is_active'];
				$customer_fields = rgar( $old_feed['meta'], 'customer_fields' );

				$new_meta = array(
						'feedName'           => $feed_name,
						'transactionType'    => rgar( $old_feed['meta'], 'type' ),
						'change_post_status' => rgar( $old_feed['meta'], 'update_post_action' ) ? '1' : '0',
						'update_post_action' => rgar( $old_feed['meta'], 'update_post_action' ),
						'recurringAmount'    => rgar( $old_feed['meta'], 'recurring_amount_field' ) == 'all' ? 'form_total' : rgar( $old_feed['meta'], 'recurring_amount_field' ),
						'recurringTimes'     => rgar( $old_feed['meta'], 'recurring_times' ),
						'payPeriod'          => rgar( $old_feed['meta'], 'pay_period' ),
						'paymentAmount'      => 'form_total', //default to this for new field in framework version
						'setupFee_enabled'   => rgar( $old_feed['meta'], 'setup_fee_enabled' ),
						'setupFee_product'   => rgar( $old_feed['meta'], 'setup_fee_amount_field' ),

						'billingInformation_firstName' => rgar( $customer_fields, 'first_name' ),
						'billingInformation_lastName'  => rgar( $customer_fields, 'last_name' ),
						'billingInformation_email'     => rgar( $customer_fields, 'email' ),
						'billingInformation_address'   => rgar( $customer_fields, 'address1' ),
						'billingInformation_address2'  => rgar( $customer_fields, 'address2' ),
						'billingInformation_city'      => rgar( $customer_fields, 'city' ),
						'billingInformation_state'     => rgar( $customer_fields, 'state' ),
						'billingInformation_zip'       => rgar( $customer_fields, 'zip' ),
						'billingInformation_country'   => rgar( $customer_fields, 'country' ),

						'apiSettingsEnabled' => rgar( $old_feed['meta'], 'api_settings_enabled' ),
						'overrideMode'       => rgar( $old_feed['meta'], 'api_mode' ),
						'overrideUsername'   => rgar( $old_feed['meta'], 'api_username' ),
						'overridePassword'   => rgar( $old_feed['meta'], 'api_password' ),
						'overrideVendor'     => rgar( $old_feed['meta'], 'api_vendor' ),
						'overridePartner'    => rgar( $old_feed['meta'], 'api_partner' ),
				);

				$optin_enabled = rgar( $old_feed['meta'], 'paypalpaymentspro_conditional_enabled' );
				if ( $optin_enabled ) {
					$new_meta['feed_condition_conditional_logic']        = 1;
					$new_meta['feed_condition_conditional_logic_object'] = array(
							'conditionalLogic' => array(
									'actionType' => 'show',
									'logicType'  => 'all',
									'rules'      => array(
											array(
													'fieldId'  => $old_feed['meta']['paypalpaymentspro_conditional_field_id'],
													'operator' => $old_feed['meta']['paypalpaymentspro_conditional_operator'],
													'value'    => $old_feed['meta']['paypalpaymentspro_conditional_value'],
											),
									)
							)
					);
				} else {
					$new_meta['feed_condition_conditional_logic'] = 0;
				}

				$this->insert_feed( $form_id, $is_active, $new_meta );
				$counter ++;

			}

			$old_settings = get_option( 'gf_paypalpaymentspro_settings' );

			if ( ! empty( $old_settings ) ) {
				$this->log_debug( __METHOD__ . '(): Copying plugin settings.' );
				$new_settings = array(
						'mode'     => rgar( $old_settings, 'mode' ),
						'username' => rgar( $old_settings, 'username' ),
						'password' => rgar( $old_settings, 'password' ),
						'vendor'   => rgar( $old_settings, 'vendor' ),
						'partner'  => rgar( $old_settings, 'partner' ),
				);

				parent::update_plugin_settings( $new_settings );
			}

			//copy existing transactions to new table
			$this->copy_transactions();

		}

	}

	/**
	 * Retrieve any old feeds which need migrating to the framework,
	 *
	 * @return bool|array
	 */
	public function get_old_feeds() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'rg_paypalpaymentspro';

		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$form_table_name = GFFormsModel::get_form_table_name();
		$sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
					FROM {$table_name} s
					INNER JOIN {$form_table_name} f ON s.form_id = f.id";

		$results = $wpdb->get_results( $sql, ARRAY_A );

		$count = sizeof( $results );

		$this->log_debug( __METHOD__ . '(): ' . $count . ' feed(s) found to copy.' );

		for ( $i = 0; $i < $count; $i ++ ) {
			$results[ $i ]['meta'] = maybe_unserialize( $results[ $i ]['meta'] );
		}

		return $results;
	}

	/**
	 * Copy transactions from the old add-on table to the framework table.
	 *
	 * @return bool
	 */
	public function copy_transactions() {
		global $wpdb;
		$old_table_name = $this->get_old_transaction_table_name();
		if ( ! $this->table_exists( $old_table_name ) ) {
			return false;
		}
		$this->log_debug( __METHOD__ . '(): Copying old PayPal Payments Pro transactions into new table structure.' );

		$new_table_name = $this->get_new_transaction_table_name();

		$sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
					SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

		$wpdb->query( $sql );

		$this->log_debug( __METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added." );
	}

	/**
	 * Returns the name of the old table used to store transactions.
	 *
	 * @return string
	 */
	public function get_old_transaction_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'rg_paypalpaymentspro_transaction';
	}

	/**
	 * Returns the name of the framework table used to store transactions.
	 *
	 * @return string
	 */
	public function get_new_transaction_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'gf_addon_payment_transaction';
	}

}