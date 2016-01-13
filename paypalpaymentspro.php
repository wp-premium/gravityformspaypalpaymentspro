<?php
/*
Plugin Name: Gravity Forms PayPal Payments Pro Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with PayPal Payments Pro, enabling end users to purchase goods and services through Gravity Forms.
Version: 1.8.1
Author: rocketgenius
Author URI: http://www.rocketgenius.com

------------------------------------------------------------------------

Copyright 2009 rocketgenius
last updated: October 20, 2010

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFPayPalPaymentsPro', 'init'));
register_activation_hook( __FILE__, array("GFPayPalPaymentsPro", "add_permissions"));

class GFPayPalPaymentsPro {

    private static $path = 'gravityformspaypalpaymentspro/paypalpaymentspro.php';
    private static $url = 'http://www.gravityforms.com';
    private static $slug = 'gravityformspaypalpaymentspro';
    private static $version = '1.8.1';
    private static $min_gravityforms_version = '1.7';
    public static $product_payment_response = '';
    public static $transaction_response = '';
    public static $recurring_profile = '';
    private static $supported_fields = array('checkbox', 'radio', 'select', 'text', 'website', 'textarea', 'email', 'hidden', 'number', 'phone', 'multiselect', 'post_title',
                                             'post_tags', 'post_custom_field', 'post_content', 'post_excerpt');

    //Plugin starting point. Will load appropriate files
    public static function init(){

        //check and update subscription status
        if( class_exists( 'RGForms' ) && RGForms::get('page') == 'gf_entries')
        {
            self::process_renewals();
        }

        //supports logging
        add_filter("gform_logging_supported", array("GFPayPalPaymentsPro", "set_logging_supported"));

        //setup cron job to run daily to check and update subscription status
        self::setup_cron();

        if(RG_CURRENT_PAGE == "plugins.php"){
            //loading translations
            load_plugin_textdomain('gravityformspaypalpaymentspro', FALSE, '/gravityformspaypalpaymentspro/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFPayPalPaymentsPro', 'plugin_row') );

            //force new remote request for version info on the plugin page
            self::flush_version_info();
        }

        if(!self::is_gravityforms_supported())
           return;

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravityformspaypalpaymentspro', FALSE, '/gravityformspaypalpaymentspro/languages' );

            //automatic upgrade hooks
            add_filter("transient_update_plugins", array('GFPayPalPaymentsPro', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFPayPalPaymentsPro', 'check_update'));
            add_action('install_plugins_pre_plugin-information', array('GFPayPalPaymentsPro', 'display_changelog'));

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFPayPalPaymentsPro", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFPayPalPaymentsPro', 'create_menu'));

            //enables credit card field
            add_filter("gform_enable_credit_card_field", "__return_true");

            //runs the setup when version changes
            self::setup();

            if(self::is_paypalpaymentspro_page()){

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(self::get_base_path() . "/data.php");

                //loading upgrade lib
                if(!class_exists("RGPayPalPaymentsProUpgrade"))
                    require_once("plugin-upgrade.php");

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFPayPalPaymentsPro', 'tooltips'));

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(self::get_base_path() . "/data.php");

                add_action('wp_ajax_gf_paypalpaymentspro_update_feed_active', array('GFPayPalPaymentsPro', 'update_feed_active'));
                add_action('wp_ajax_gf_select_paypalpaymentspro_form', array('GFPayPalPaymentsPro', 'select_paypalpaymentspro_form'));
                add_action('wp_ajax_gf_cancel_paypalpaymentspro_subscription', array('GFPayPalPaymentsPro', 'cancel_paypalpaymentspro_subscription'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("PayPal Payments Pro", array("GFPayPalPaymentsPro", "settings_page"), self::get_base_url() . "/images/paypal_wordpress_icon_32.png");
            }
            else if(RGForms::get("page") == "gf_entries"){
                add_action('gform_entry_info',array("GFPayPalPaymentsPro", "paypalpaymentspro_entry_info"), 10, 2);
	            add_action( 'gform_enable_entry_info_payment_details', array( 'GFPayPalPaymentsPro', 'disable_entry_info_payment' ), 10, 2 );

            }
        }
        else{
            //loading data class
            require_once(self::get_base_path() . "/data.php");

            //handling post submission.
            add_filter('gform_validation',array("GFPayPalPaymentsPro", "paypalpaymentspro_validation"), 10, 4);
            //add_action('gform_entry_created',array("GFPayPalPaymentsPro", "paypalpaymentspro_entry_created"), 10, 2);
            add_action('gform_entry_post_save',array("GFPayPalPaymentsPro", "paypalpaymentspro_commit_transaction"), 10, 2);

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFUser', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFUser', 'premium_update') );
        }
    }

    public static function setup_cron(){
       if(!wp_next_scheduled("paypalpaymentspro_renewal_cron"))
           wp_schedule_event(time(), "daily", "paypalpaymentspro_renewal_cron");
    }

    public static function update_feed_active(){
        check_ajax_referer('gf_paypalpaymentspro_update_feed_active','gf_paypalpaymentspro_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPayPalPaymentsProData::get_feed($id);
        GFPayPalPaymentsProData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }


    //-------------- Automatic upgrade ---------------------------------------

    //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

		//loading upgrade lib
		if( ! class_exists( 'RGPayPalPaymentsProUpgrade' ) ){
			require_once("plugin-upgrade.php");
		}
		$update = RGPayPalPaymentsProUpgrade::get_version_info( self::$slug, self::get_key(), self::$version );

        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

		//loading upgrade lib
		if( ! class_exists( 'RGPayPalPaymentsProUpgrade' ) ){
			require_once("plugin-upgrade.php");
		}
		$update = RGPayPalPaymentsProUpgrade::get_version_info( self::$slug, self::get_key(), self::$version );

        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }

    public static function flush_version_info(){
        if(!class_exists("RGPayPalPaymentsProUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalPaymentsProUpgrade::set_version_info(false);
    }

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("Gravity Forms " . self::$min_gravityforms_version . " is required. Activate it now or %spurchase it today!%s", "gravityformspaypalpaymentspro"), "<a href='http://www.gravityforms.com'>", "</a>");
            RGPayPalPaymentsProUpgrade::display_plugin_message($message, true);
        }
        else{
            $version_info = RGPayPalPaymentsProUpgrade::get_version_info(self::$slug, self::get_key(), self::$version);

            if(!$version_info["is_valid_key"]){
                $new_version = version_compare(self::$version, $version_info["version"], '<') ? __('There is a new version of Gravity Forms PayPal Payments Flow Add-On available.', 'gravityformspaypalpaymentspro') .' <a class="thickbox" title="Gravity Forms PayPal Payments Flow Add-On" href="plugin-install.php?tab=plugin-information&plugin=' . self::$slug . '&TB_iframe=true&width=640&height=808">'. sprintf(__('View version %s Details', 'gravityformspaypalpaymentspro'), $version_info["version"]) . '</a>. ' : '';
                $message = $new_version . sprintf(__('%sRegister%s your copy of Gravity Forms to receive access to automatic upgrades and support. Need a license key? %sPurchase one now%s.', 'gravityformspaypalpaymentspro'), '<a href="admin.php?page=gf_settings">', '</a>', '<a href="http://www.gravityforms.com">', '</a>') . '</div></td>';
                RGPayPalPaymentsProUpgrade::display_plugin_message($message);
            }
        }
    }

    //Displays current version details on Plugin's page

    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        //loading upgrade lib
        if(!class_exists("RGPayPalPaymentsProUpgrade"))
            require_once("plugin-upgrade.php");

        RGPayPalPaymentsProUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
        if(!class_exists("RGPayPalPaymentsProUpgrade"))
            require_once("plugin-upgrade.php");

        return RGPayPalPaymentsProUpgrade::check_update(self::$path, self::$slug, self::$url, self::$slug, self::get_key(), self::$version, $update_plugins_option);
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------


    //Creates PayPalPayments Pro left nav menu under Forms
    public static function create_menu($menus){
        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_paypalpaymentspro");
        if(!empty($permission))
            $menus[] = array("name" => "gf_paypalpaymentspro", "label" => __("PayPal Payments Pro", "gravityformspaypalpaymentspro"), "callback" =>  array("GFPayPalPaymentsPro", "paypalpaymentspro_page"), "permission" => $permission);
        return $menus;

    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_paypalpaymentspro_version") != self::$version){
            //loading data lib
            require_once(self::get_base_path() . "/data.php");
            GFPayPalPaymentsProData::update_table();
        }
        update_option("gf_paypalpaymentspro_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $paypalpaymentspro_tooltips = array(
            "paypalpaymentspro_transaction_type" => "<h6>" . __("Transaction Type", "gravityformspaypalpaymentspro") . "</h6>" . __("Select which PayPal Payments Pro transaction type should be used. Products and Services or Subscription.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_gravity_form" => "<h6>" . __("Gravity Form", "gravityformspaypalpaymentspro") . "</h6>" . __("Select which Gravity Forms you would like to integrate with PayPal Payments Pro.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_customer" => "<h6>" . __("Customer", "gravityformspaypalpaymentspro") . "</h6>" . __("Map your Form Fields to the available PayPal Payments Pro customer information fields.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_page_style" => "<h6>" . __("Page Style", "gravityformspaypalpaymentspro") . "</h6>" . __("This option allows you to select which PayPal Payments Pro page style should be used if you have setup a custom payment page style with PayPal Payments Pro.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_continue_button_label" => "<h6>" . __("Continue Button Label", "gravityformspaypalpaymentspro") . "</h6>" . __("Enter the text that should appear on the continue button once payment has been completed via PayPal Payments Pro.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_cancel_url" => "<h6>" . __("Cancel URL", "gravityformspaypalpaymentspro") . "</h6>" . __("Enter the URL the user should be sent to should they cancel before completing their PayPal Payments Pro payment.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_options" => "<h6>" . __("Options", "gravityformspaypalpaymentspro") . "</h6>" . __("Turn on or off the available PayPal Payments Pro checkout options.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_recurring_amount" => "<h6>" . __("Recurring Amount", "gravityformspaypalpaymentspro") . "</h6>" . __("Select which field determines the recurring payment amount, or select 'Form Total' to use the total of all pricing fields as the recurring amount.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_pay_period" => "<h6>" . __("Pay Period", "gravityformspaypalpaymentspro") . "</h6>" . __("Select pay period.  This determines how often the recurring payment should occur.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_recurring_times" => "<h6>" . __("Recurring Times", "gravityformspaypalpaymentspro") . "</h6>" . __("Select how many times the recurring payment should be made.  The default is to bill the customer until the subscription is canceled.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_setup_fee_enable" => "<h6>" . __("Setup Fee", "gravityformspaypalpaymentspro") . "</h6>" . __("Enable setup fee to charge a one time fee before the recurring payments begin.", "gravityformspaypalpaymentspro"),
            "paypalpaymentspro_conditional" => "<h6>" . __("PayPal Condition", "gravityformspaypalpaymentspro") . "</h6>" . __("When the PayPal condition is enabled, form submissions will only be sent to PayPal when the condition is met. When disabled, all form submissions will be sent to PayPal.", "gravityformspaypalpaymentspro")
        );
        return array_merge($tooltips, $paypalpaymentspro_tooltips);
    }

    public static function paypalpaymentspro_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the paypalpaymentspro feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("PayPal Payments Pro Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravityformspaypalpaymentspro"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_paypalpaymentspro_list");
            $id = absint($_POST["action_argument"]);
            GFPayPalPaymentsProData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravityformspaypalpaymentspro") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_paypalpaymentspro_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPayPalPaymentsProData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravityformspaypalpaymentspro") ?></div>
            <?php
        }

        $settings = get_option("gf_paypalpaymentspro_settings");
        $is_settings_configured = is_array($settings) && !rgempty("username", $settings) && !rgempty("password", $settings);

        ?>
        <div class="wrap">
            <img alt="<?php _e("PayPal Payments Pro Transactions", "gravityformspaypalpaymentspro") ?>" src="<?php echo self::get_base_url()?>/images/paypal_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php
            _e("PayPal Payments Pro Forms", "gravityformspaypalpaymentspro");

            if($is_settings_configured){
                ?>
                <a class="button add-new-h2" href="admin.php?page=gf_paypalpaymentspro&view=edit&id=0"><?php _e("Add New", "gravityformspaypalpaymentspro") ?></a>
                <?php
            }
            ?>
            </h2>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_paypalpaymentspro_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravityformspaypalpaymentspro") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravityformspaypalpaymentspro") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravityformspaypalpaymentspro") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravityformspaypalpaymentspro") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravityformspaypalpaymentspro") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypalpaymentspro") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypalpaymentspro") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypalpaymentspro") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravityformspaypalpaymentspro") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Transaction Type", "gravityformspaypalpaymentspro") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $feeds = GFPayPalPaymentsProData::get_feeds();

                        if(!$is_settings_configured){
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("To get started, please configure your %sPayPal Payments Pro Settings%s.", "gravityformspaypalpaymentspro"), '<a href="admin.php?page=gf_settings&addon=PayPal Payments Pro">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        else if(is_array($feeds) && sizeof($feeds) > 0){
                            foreach($feeds as $feed){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $feed["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($feed["is_active"]) ?>.png" alt="<?php echo $feed["is_active"] ? __("Active", "gravityformspaypalpaymentspro") : __("Inactive", "gravityformspaypalpaymentspro");?>" title="<?php echo $feed["is_active"] ? __("Active", "gravityformspaypalpaymentspro") : __("Inactive", "gravityformspaypalpaymentspro");?>" onclick="ToggleActive(this, <?php echo $feed['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_paypalpaymentspro&view=edit&id=<?php echo $feed["id"] ?>" title="<?php _e("Edit", "gravityformspaypalpaymentspro") ?>"><?php echo $feed["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e("Edit", "gravityformspaypalpaymentspro")?>" href="admin.php?page=gf_paypalpaymentspro&view=edit&id=<?php echo $feed["id"] ?>" ><?php _e("Edit", "gravityformspaypalpaymentspro") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Stats", "gravityformspaypalpaymentspro")?>" href="admin.php?page=gf_paypalpaymentspro&view=stats&id=<?php echo $feed["id"] ?>"><?php _e("Stats", "gravityformspaypalpaymentspro") ?></a>
                                            |
                                            </span>
                                            <span class="view">
                                            <a title="<?php _e("View Entries", "gravityformspaypalpaymentspro")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $feed["form_id"] ?>"><?php _e("Entries", "gravityformspaypalpaymentspro") ?></a>
                                            |
                                            </span>
                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravityformspaypalpaymentspro") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravityformspaypalpaymentspro") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravityformspaypalpaymentspro") ?>')){ DeleteSetting(<?php echo $feed["id"] ?>);}"><?php _e("Delete", "gravityformspaypalpaymentspro")?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                        <?php
                                            switch($feed["meta"]["type"]){
                                                case "product" :
                                                    _e("Product and Services", "gravityformspaypalpaymentspro");
                                                break;

                                                case "donation" :
                                                    _e("Donation", "gravityformspaypalpaymentspro");
                                                break;

                                                case "subscription" :
                                                    _e("Subscription", "gravityformspaypalpaymentspro");
                                                break;
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(__("You don't have any PayPal Payments Pro feeds configured. Let's go %screate one%s!", "gravityformspaypalpaymentspro"), '<a href="admin.php?page=gf_paypalpaymentspro&view=edit&id=0">', "</a>"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravityformspaypalpaymentspro") ?>').attr('alt', '<?php _e("Inactive", "gravityformspaypalpaymentspro") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravityformspaypalpaymentspro") ?>').attr('alt', '<?php _e("Active", "gravityformspaypalpaymentspro") ?>');
                }

                var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_paypalpaymentspro_update_feed_active" );
                mysack.setVar( "gf_paypalpaymentspro_update_feed_active", "<?php echo wp_create_nonce("gf_paypalpaymentspro_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravityformspaypalpaymentspro" ) ?>' )};
                mysack.runAJAX();

                return true;
            }

        </script>
        <?php
    }

    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_paypalpaymentspro_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms PaylPay Payments Pro Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravityformspaypalpaymentspro")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_paypalpaymentspro_submit"])){
            check_admin_referer("update", "gf_paypalpaymentspro_update");
            $password = $_POST["gf_paypalpaymentspro_password"];
            if($password == "*****")
                $password = $settings["password"];
            $settings = array(  "username" => $_POST["gf_paypalpaymentspro_username"],
                                "password" => $password,
                                "vendor" => $_POST["gf_paypalpaymentspro_vendor"],
                                "partner" => $_POST["gf_paypalpaymentspro_partner"],
                                "mode" => $_POST["gf_paypalpaymentspro_mode"]);
            update_option("gf_paypalpaymentspro_settings", $settings);
        }
        else{
            $settings = get_option("gf_paypalpaymentspro_settings");
        }

        self::log_debug("Validating credentials.");
        $is_valid = self::is_valid_key();

        $message = "";
        if($is_valid)
            $message = __("Valid PayPal credentials.", "gravityformspaypalpaymentspro");
        else if(!empty($settings["username"]))
            $message = __("Invalid PayPal credentials.", "gravityformspaypalpaymentspro");

        self::log_debug("Credential status: {$message}");
        ?>

        <style>
            .valid_credentials{color:green;}
            .invalid_credentials{color:red;}
            .size-1{width:400px;}
        </style>

        <form action="" method="post">
            <?php wp_nonce_field("update", "gf_paypalpaymentspro_update") ?>

            <table class="form-table">
                <tr>
                    <td colspan="2">
                        <h3><?php _e("PayPal Payments Pro Settings", "gravityformspaypalpaymentspro") ?></h3>
                        <p style="text-align: left;">
                            <?php _e(sprintf("PayPal Payments Pro is a merchant account and gateway in one. Use Gravity Forms to collect payment information and automatically integrate to your PayPal Payments Pro account. If you don't have a PayPal Payments Pro account, you can %ssign up for one here%s", "<a href='https://registration.paypal.com/welcomePage.do?bundleCode=C3&country=US&partner=PayPal' target='_blank'>" , "</a>"), "gravityformspaypalpaymentspro") ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" nowrap="nowrap"><label for="gf_paypalpaymentspro_mode"><?php _e("API", "gravityformspaypalpaymentspro"); ?></label> </th>
                    <td width="88%">
                        <input type="radio" name="gf_paypalpaymentspro_mode" id="gf_paypalpaymentspro_mode_production" value="production" <?php echo rgar($settings, 'mode') != "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpaymentspro_mode_production"><?php _e("Live", "gravityformspaypalpaymentspro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_paypalpaymentspro_mode" id="gf_paypalpaymentspro_mode_test" value="test" <?php echo rgar($settings, 'mode') == "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpaymentspro_mode_test"><?php _e("Sandbox", "gravityformspaypalpaymentspro"); ?></label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_paypalpaymentspro_username"><?php _e("Username", "gravityformspaypalpaymentspro"); ?></label> </th>
                    <td width="80%">
                        <input class="size-1" id="gf_paypalpaymentspro_username" name="gf_paypalpaymentspro_username" value="<?php echo esc_attr($settings["username"]) ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_paypalpaymentspro_password"><?php _e("Password", "gravityformspaypalpaymentspro"); ?></label> </th>
                    <td width="80%">
                        <input type="password" class="size-1" id="gf_paypalpaymentspro_password" name="gf_paypalpaymentspro_password" value="<?php echo empty($settings["password"]) ? '' : '*****' ?>"/>
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="gf_paypalpaymentspro_vendor"><?php _e("Vendor (optional)", "gravityformspaypalpaymentspro"); ?></label> </th>
                    <td width="80%">
                        <input class="size-1" id="gf_paypalpaymentspro_vendor" name="gf_paypalpaymentspro_vendor" value="<?php echo empty($settings["vendor"]) ? '' : $settings["vendor"] ?>" />
                        <?php if(!empty($settings["vendor"])) {?>
                            <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                        <?php } ?>
                        <br/> <small>Your merchant login ID if different from Username above.</small>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="gf_paypalpaymentspro_partner"><?php _e("Partner", "gravityformspaypalpaymentspro"); ?></label> </th>
                    <td width="80%">
                        <input class="size-1" id="gf_paypalpaymentspro_partner" name="gf_paypalpaymentspro_partner" value="<?php echo empty($settings["partner"]) ? 'PayPal' : $settings["partner"] ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid ? "tick.png" : "stop.png" ?>" border="0" alt="<?php $message ?>" title="<?php echo $message ?>" style="display:<?php echo empty($message) ? 'none;' : 'inline;' ?>" />
                        <br/> <small>If you have registered with a PayPal Reseller, enter their ID above.</small>
                    </td>
                    
                </tr>

                <tr>
                    <td colspan="2" ><input type="submit" name="gf_paypalpaymentspro_submit" class="button-primary" value="<?php _e("Save Settings", "gravityformspaypalpaymentspro") ?>" /></td>
                </tr>

            </table>

        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_paypalpaymentspro_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_paypalpaymentspro_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall PayPal Payments Pro Add-On", "gravityformspaypalpaymentspro") ?></h3>
                <div class="delete-alert alert_red">

                    <h3><i class="fa fa-exclamation-triangle gf_invalid"></i>Warning</h3>

                    <div class="gf_delete_notice" "=""><strong><?php _e("Warning! This operation deletes ALL PayPal Payments Pro Feeds.", "gravityformspaypalpaymentspro")?></strong><?php _e("If you continue, you will not be able to recover any PayPal Payments Pro data.", "gravityformspaypalpaymentspro") ?>                    </div>

                    <input type="submit" name="uninstall" value=" Uninstall PayPal Payments Pro Add-On" class="button" onclick="return confirm('<?php _e("Warning! ALL settings will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop')", "gravityformspaypalpaymentspro") ?>');">              
                </div>
            <?php } ?>
        </form>
        <?php
    }

    private static function is_valid_key($local_api_settings = array()){

        $args = array("TRXTYPE" => "A", "TENDER" => "C");

        if(!empty($local_api_settings))
            $response = self::post_to_payflow($args,$local_api_settings);
        else
            $response = self::post_to_payflow($args);

        if(!empty($response) && $response["RESULT"] != "1" && $response["RESPMSG"] != "User authentication failed"){
            return true;
        }
        else{
            return false;
        }

    }

    private static function post_to_payflow($nvp = array(), $local_api_settings = array(), $form_id = null) {

        // Set up your API credentials and PayFlow Pro end point.
        if(!empty($local_api_settings))
        {
            $API_UserName = $local_api_settings["username"];
            $API_Password = $local_api_settings["password"];
            $Vendor = $local_api_settings["vendor"];
            $Vendor = empty($Vendor) ? $API_UserName : $Vendor;
            $Partner = $local_api_settings["partner"];
            $Partner = empty($Partner) ? "PayPal" : $Partner;
            $mode = $local_api_settings["mode"];
        }
        else
        {
            $API_UserName = self::get_username();
            $API_Password = self::get_password();
            $Vendor = self::get_vendor();
            $Vendor = empty($Vendor) ? $API_UserName : $Vendor;
            $Partner = self::get_partner();
            $Partner = empty($Partner) ? "PayPal" : $Partner;
            $mode = self::get_mode();
        }


        $API_Endpoint = $mode == "test" ? 'https://pilot-payflowpro.paypal.com' : 'https://payflowpro.paypal.com';

        $api_info = compact("API_Endpoint", "API_UserName", "API_Password", "Vendor", "Partner");
        $api_info = apply_filters("gform_paypalpaymentspro_api_before_send", $api_info, $form_id);
        extract($api_info);

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);


        $nvp = apply_filters("gform_paypalpaymentspro_args_before_send", $nvp, $form_id);

        $nvpstr = "";
        if(is_array($nvp)){
            foreach($nvp as $key => $value) {
                if (is_array($value)) {
                    foreach($value as $item) {
                        if (strlen($nvpstr) > 0) 
                            $nvpstr .= "&";
                        $nvpstr .= "$key=".$item;
                    }
                } else {
                    if (strlen($nvpstr) > 0) 
                        $nvpstr .= "&";
                    $nvpstr .= "$key=".$value;
                }
            }
        }
        
        //add the bn code (build notation code)
        $nvpstr = "BUTTONSOURCE=Rocketgenius_SP&$nvpstr";

        // Set the API operation, version, and API signature in the request.
        $nvpreq = "VENDOR=$Vendor&PARTNER=$Partner&PWD=$API_Password&USER=$API_UserName&$nvpstr";

        self::log_debug("Sending request to PayPal - URL: {$API_Endpoint} Request: {$nvpreq}");

        // Set the request as a POST FIELD for curl.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

        // Get response from the server.
        $httpResponse = curl_exec($ch);

        // Extract the response details.
        $httpParsedResponseAr = array();
        if($httpResponse)
        {
            $httpResponseAr = explode("&", $httpResponse);
            foreach ($httpResponseAr as $i => $value) {
                $tmpAr = explode("=", urldecode($value));
                if(sizeof($tmpAr) > 1) {
                    $httpParsedResponseAr[$tmpAr[0]] = $tmpAr[1];
                }
            }
        }
        $write_response_to_log = true;
        if($nvp["TRXTYPE"] == "A" && $httpParsedResponseAr["RESULT"] == "23")
            $write_response_to_log = false;
        if($write_response_to_log)
        {
            self::log_debug("Response from PayPal: " . $httpResponse);
            self::log_debug("Friendly view of response: " . print_r($httpParsedResponseAr, true));
        }
        return $httpParsedResponseAr;
}

    private static function get_username(){
        $settings = get_option("gf_paypalpaymentspro_settings");
        $username = $settings["username"];
        return $username;
    }

    private static function get_password(){
        $settings = get_option("gf_paypalpaymentspro_settings");
        $password = $settings["password"];
        return $password;
    }
    
    private static function get_vendor(){
        $settings = get_option("gf_paypalpaymentspro_settings");
        $vendor = $settings["vendor"];
        return $vendor;
    }
    
    private static function get_partner(){
        $settings = get_option("gf_paypalpaymentspro_settings");
        $partner = $settings["partner"];
        return $partner;
    }

    private static function get_mode(){
        $settings = get_option("gf_paypalpaymentspro_settings");
        $mode = $settings["mode"];
        return $mode;
    }

    private static function get_local_api_settings($config){
        $local_api_settings = array("mode" => $config["meta"]["api_mode"], "username" => $config["meta"]["api_username"], "password" =>  $config["meta"]["api_password"], "vendor" =>  $config["meta"]["api_vendor"], "partner" =>  $config["meta"]["api_partner"]);
        return $local_api_settings;
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . __("Select a product", "gravityformspaypalpaymentspro") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

    private static function stats_page(){
        ?>
        <style>
            .paypalpaymentspro_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
            .paypalpaymentspro_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
            .paypalpaymentspro_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
            .paypalpaymentspro_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
            .paypalpaymentspro_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
            .paypalpaymentspro_summary_title {}
            #paypalpaymentspro_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
            #paypalpaymentspro_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}
            .paypalpaymentspro_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
            .paypalpaymentspro_tooltip_sales {line-height:130%;}
            .paypalpaymentspro_tooltip_revenue {line-height:130%;}
            .paypalpaymentspro_tooltip_revenue .paypalpaymentspro_tooltip_heading {}
            .paypalpaymentspro_tooltip_revenue .paypalpaymentspro_tooltip_value {}
            .paypalpaymentspro_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
        </style>

        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
        <script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

        <div class="wrap">
            <img alt="<?php _e("PayPal Pro", "gravityformspaypalpaymentspro") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Pro Stats", "gravityformspaypalpaymentspro") ?></h2>

            <form method="post" action="">
                <ul class="subsubsub">
                    <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_paypalpaymentspro&view=stats&id=<?php echo $_GET["id"] ?>"><?php _e("Daily", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_paypalpaymentspro&view=stats&id=<?php echo $_GET["id"] ?>&tab=weekly"><?php _e("Weekly", "gravityforms"); ?></a> | </li>
                    <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_paypalpaymentspro&view=stats&id=<?php echo $_GET["id"] ?>&tab=monthly"><?php _e("Monthly", "gravityforms"); ?></a></li>
                </ul>
                <?php
                $config = GFPayPalPaymentsProData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
                    <div class="paypalpaymentspro_message_container"><?php _e("No payments have been made yet.", "gravityformspaypalpaymentspro") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
                    <div class="paypalpaymentspro_graph_container">
                        <div id="graph_placeholder" style="width:100%;height:300px;"></div>
                    </div>

                    <script type="text/javascript">
                        var paypalpaymentspro_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

                        jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        jQuery(window).resize(function(){
                            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
                        });

                        var previousPoint = null;
                        jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                            startShowTooltip(item);
                        });

                        function startShowTooltip(item){
                            if (item) {
                                if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                                    previousPoint = item.datapoint;

                                    jQuery("#paypalpaymentspro_graph_tooltip").remove();
                                    var x = item.datapoint[0].toFixed(2),
                                        y = item.datapoint[1].toFixed(2);

                                    showTooltip(item.pageX, item.pageY, paypalpaymentspro_graph_tooltips[item.dataIndex]);
                                }
                            }
                            else {
                                jQuery("#paypalpaymentspro_graph_tooltip").remove();
                                previousPoint = null;
                            }
                        }

                        function showTooltip(x, y, contents) {
                            jQuery('<div id="paypalpaymentspro_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                                position: 'absolute',
                                display: 'none',
                                opacity: 0.90,
                                width:'150px',
                                height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                                top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                                left: x - 79
                            }).appendTo("body").fadeIn(200);
                        }

                        function convertToMoney(number){
                            var currency = getCurrentCurrency();
                            return currency.toMoney(number);
                        }

                        function formatWeeks(number){
                            number = number + "";
                            return "<?php _e("Week ", "gravityformspaypalpaymentspro") ?>" + number.substring(number.length-2);
                        }

                        function getCurrentCurrency(){
                            <?php
                            if(!class_exists("RGCurrency"))
                                require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");
                            $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                            ?>
                            var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                            return currency;
                        }
                    </script>
                <?php
                }
                $transaction_totals = GFPayPalPaymentsProData::get_transaction_totals($config);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $transaction_totals["orders"];
                        $sales_label = __("Total Orders", "gravityformspaypalpro");
                    break;

                    case "donation" :
                        $total_sales = $transaction_totals["orders"];
                        $sales_label = __("Total Donations", "gravityformspaypalpro");
                    break;

                    case "subscription" :
                        $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                        $total_sales = $payment_totals["active"];
                        $sales_label = __("Active Subscriptions", "gravityformspaypalpro");
                    break;
                }

                $total_revenue = empty($transaction_totals["revenue"]) ? 0 : $transaction_totals["revenue"];
                ?>
                <div class="paypalpaymentspro_summary_container">
                    <div class="paypalpaymentspro_summary_item">
                        <div class="paypalpaymentspro_summary_title"><?php _e("Total Revenue", "gravityformspaypalpaymentspro")?></div>
                        <div class="paypalpaymentspro_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
                    </div>
                    <div class="paypalpaymentspro_summary_item">
                        <div class="paypalpaymentspro_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                        <div class="paypalpaymentspro_summary_value"><?php echo $chart_info["revenue"] ?></div>
                    </div>
                    <div class="paypalpaymentspro_summary_item">
                        <div class="paypalpaymentspro_summary_title"><?php echo $sales_label?></div>
                        <div class="paypalpaymentspro_summary_value"><?php echo $total_sales ?></div>
                    </div>
                    <div class="paypalpaymentspro_summary_item">
                        <div class="paypalpaymentspro_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                        <div class="paypalpaymentspro_summary_value"><?php echo $chart_info["sales"] ?></div>
                    </div>
                </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
                    <div class="paypalpaymentspro_trial_disclaimer"><?php _e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "gravityformspaypalpaymentspro") ?></div>
                    <?php
                }
                ?>
            </form>
        </div>
        <?php
    }

    private function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

        $tz_offset = self::get_mysql_tz_offset();

        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_paypalpaymentspro_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";
        $series = "";
        $options ="";
        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";

                if($config["meta"]["type"] == "subscription"){
                    $sales_line = " <div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->renewals . "</span></div>";
                }
                else{
                    $sales_line = "<div class='paypalpaymentspro_tooltip_sales'><span class='paypalpaymentspro_tooltip_heading'>" . __("Orders", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div>";
                }

                $tooltips .= "\"<div class='paypalpaymentspro_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='paypalpaymentspro_tooltip_revenue'><span class='paypalpaymentspro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = __("Orders Today", "gravityformspaypalpaymentspro");
            break;

            case "donation" :
                $sales_label = __("Donations Today", "gravityformspaypalpaymentspro");
            break;

            case "subscription" :
                $sales_label = __("Subscriptions Today", "gravityformspaypalpaymentspro");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue Today", "gravityformspaypalpaymentspro"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paypalpaymentspro_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
            $sales_week = 0;
            $revenue_week = 0;
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypalpaymentspro_tooltip_sales'><span class='paypalpaymentspro_tooltip_heading'>" . __("Orders", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypalpaymentspro_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . __("Week",  "gravityformspaypalpaymentspro") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='paypalpaymentspro_tooltip_revenue'><span class='paypalpaymentspro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Week", "gravityformspaypalpaymentspro");
                break;

                case "donation" :
                    $sales_label = __("Donations this Week", "gravityformspaypalpaymentspro");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Week", "gravityformspaypalpaymentspro");
                break;
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Week", "gravityformspaypalpaymentspro"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;
            $tz_offset = self::get_mysql_tz_offset();

            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paypalpaymentspro_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

            $sales_month = 0;
            $revenue_month = 0;
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

                    if($config["meta"]["type"] == "subscription"){
                        $sales_line = " <div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("New Subscriptions", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div><div class='paypalpaymentspro_tooltip_subscription'><span class='paypalpaymentspro_tooltip_heading'>" . __("Renewals", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->renewals . "</span></div>";
                    }
                    else{
                        $sales_line = "<div class='paypalpaymentspro_tooltip_sales'><span class='paypalpaymentspro_tooltip_heading'>" . __("Orders", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . $result->new_sales . "</span></div>";
                    }

                    $tooltips .= "\"<div class='paypalpaymentspro_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='paypalpaymentspro_tooltip_revenue'><span class='paypalpaymentspro_tooltip_heading'>" . __("Revenue", "gravityformspaypalpaymentspro") . ": </span><span class='paypalpaymentspro_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = __("Orders this Month", "gravityformspaypalpaymentspro");
                break;

                case "donation" :
                    $sales_label = __("Donations this Month", "gravityformspaypalpaymentspro");
                break;

                case "subscription" :
                    $sales_label = __("Subscriptions this Month", "gravityformspaypalpaymentspro");
                break;
            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => __("Revenue this Month", "gravityformspaypalpaymentspro"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . __("Jan", "gravityformspaypalpaymentspro") ."','" . __("Feb", "gravityformspaypalpaymentspro") ."','" . __("Mar", "gravityformspaypalpaymentspro") ."','" . __("Apr", "gravityformspaypalpaymentspro") ."','" . __("May", "gravityformspaypalpaymentspro") ."','" . __("Jun", "gravityformspaypalpaymentspro") ."','" . __("Jul", "gravityformspaypalpaymentspro") ."','" . __("Aug", "gravityformspaypalpaymentspro") ."','" . __("Sep", "gravityformspaypalpaymentspro") ."','" . __("Oct", "gravityformspaypalpaymentspro") ."','" . __("Nov", "gravityformspaypalpaymentspro") ."','" . __("Dec", "gravityformspaypalpaymentspro") ."']";
    }

    public static function paypalpaymentspro_entry_info($form_id, $lead) {

        // adding cancel subscription button and script to entry info section
        $lead_id = $lead["id"];
        $config = self::get_config_by_entry($lead_id);
        $payment_status = $lead["payment_status"];
        $transaction_type = $lead["transaction_type"];
        $gateway = gform_get_meta($lead_id, "payment_gateway");
        $cancelsub_button = "";

        if($transaction_type == 2 && $payment_status <> "Canceled" && $gateway == "paypalpaymentspro")
        {
            $cancelsub_button .= '<input id="cancelsub" type="button" name="cancelsub" value="' . __("Cancel Subscription", "gravityformspaypalpaymentspro") . '" class="button" onclick=" if( confirm(\'' . __("Warning! This subscription will be canceled. This cannot be undone. \'OK\' to cancel subscription, \'Cancel\' to stop", "gravityformspaypalpaymentspro") . '\')){cancel_paypalpaymentspro_subscription();};"/>';
            $cancelsub_button .= '<img src="'. GFPayPalPaymentsPro::get_base_url() . '/images/loading.gif" id="paypalpaymentspro_wait" style="display: none;"/>';
            $cancelsub_button .= '<script type="text/javascript">
                function cancel_paypalpaymentspro_subscription(){
                    jQuery("#paypalpaymentspro_wait").show();
                    jQuery("#cancelsub").attr("disabled", true);
                    var lead_id = ' . $lead_id  .'
                    jQuery.post(ajaxurl, {
                            action:"gf_cancel_paypalpaymentspro_subscription",
                            leadid:lead_id,
                            gf_cancel_pfp_subscription: "' . wp_create_nonce('gf_cancel_pfp_subscription') . '"},
                            function(response){
                                jQuery("#paypalpaymentspro_wait").hide();
                                if(response == "1")
                                {
                                    jQuery("#gform_payment_status").html("' . __("Canceled", "gravityformspaypalpaymentspro") . '");
                                    jQuery("#cancelsub").hide();
                                }
                                else
                                {
                                    jQuery("#cancelsub").attr("disabled", false);
                                    alert("' . __("The subscription could not be canceled. Please try again later.") . '");
                                }
                            }
                            );
                }
            </script>';

            echo $cancelsub_button;
        }
    }

    public static function cancel_paypalpaymentspro_subscription() {
        check_ajax_referer("gf_cancel_pfp_subscription","gf_cancel_pfp_subscription");

        $lead_id = $_POST["leadid"];
        $lead = RGFormsModel::get_lead($lead_id);

        //Getting feed config
        $form = RGFormsModel::get_form_meta($lead["form_id"]);
        $config = self::get_config_by_entry($lead_id);

        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
        {
             $local_api_settings = self::get_local_api_settings($config);
        }

        $args = array("TRXTYPE" => "R", "TENDER" => "C", "ORIGPROFILEID" => $lead["transaction_id"], "ACTION" => "C");

        self::log_debug("Canceling subscription.");
        if(!empty($local_api_settings))
                $response = self::post_to_payflow($args,$local_api_settings, $form["id"]);
            else
                $response = self::post_to_payflow($args, array(), $form["id"]);

        if(!empty($response) && $response["RESULT"] == "0"){
            self::cancel_subscription($lead);
            self::log_debug("Subscription canceled.");
            die("1");
        }
        else{
            self::log_error("Unable to cancel subscription.");
            die("0");
        }

    }

    private static function cancel_subscription($lead){

        $lead["payment_status"] = "Canceled";
        GFAPI::update_entry( $lead );

        $config = self::get_config_by_entry($lead["id"]);
        if(!$config)
            return;

        //1- delete post or mark it as a draft based on configuration
        if(rgars($config, "meta/update_post_action") == "draft" && !rgempty("post_id", $lead)){
            $post = get_post($lead["post_id"]);
            $post->post_status = 'draft';
            wp_update_post($post);
        }
        else if(rgars($config, "meta/update_post_action") == "delete" && !rgempty("post_id", $lead)){
            wp_delete_post($lead["post_id"]);
        }

        //2- call subscription canceled hook
        do_action("gform_paypalpaymentspro_subscription_canceled", $lead, $lead["transaction_id"]);

    }

    // Edit Page
    private static function edit_page(){
        ?>
        <style>
            #paypalpaymentspro_submit_container{clear:both;}
            .paypalpaymentspro_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
            .paypalpaymentspro_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .paypalpaymentspro_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
            .paypalpaymentspro_validation_error span {color: red;}
            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0; padding-left:5px;}
            .margin_vertical_30{margin: 30px 0; padding-left:5px;}
            .width-1{width:300px;}
            .gf_paypalpaymentspro_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
        </style>

        <script type="text/javascript">
            var form = Array();
            function ToggleSetupFee(){
                if(jQuery('#gf_paypalpaymentspro_setup_fee').is(':checked')){
                    jQuery('#paypalpaymentspro_setup_fee_container').show('slow');
                }
                else{
                    jQuery('#paypalpaymentspro_setup_fee_container').hide('slow');
                }
            }

        </script>

        <div class="wrap">
            <img alt="<?php _e("PayPal Payments Pro", "gravityformspaypalpaymentspro") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/paypal_wordpress_icon_32.png"/>
            <h2><?php _e("PayPal Payments Pro Transaction Settings", "gravityformspaypalpaymentspro") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["paypalpaymentspro_setting_id"]) ? $_POST["paypalpaymentspro_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPayPalPaymentsProData::get_feed($id);
        $is_validation_error = false;

        $_POST;
        //updating meta information
        if(rgpost("gf_paypalpaymentspro_submit")){
            $config["form_id"] = absint(rgpost("gf_paypalpaymentspro_form"));
            $config["meta"]["type"] = rgpost("gf_paypalpaymentspro_type");
            $config["meta"]["disable_note"] = rgpost("gf_paypalpaymentspro_disable_note");
            $config["meta"]["disable_shipping"] = rgpost('gf_paypalpaymentspro_disable_shipping');
            $config["meta"]["update_post_action"] = rgpost('gf_paypalpaymentspro_update_action');

            // paypalpaymentspro conditional
            $config["meta"]["paypalpaymentspro_conditional_enabled"] = rgpost('gf_paypalpaymentspro_conditional_enabled');
            $config["meta"]["paypalpaymentspro_conditional_field_id"] = rgpost('gf_paypalpaymentspro_conditional_field_id');
            $config["meta"]["paypalpaymentspro_conditional_operator"] = rgpost('gf_paypalpaymentspro_conditional_operator');
            $config["meta"]["paypalpaymentspro_conditional_value"] = rgpost('gf_paypalpaymentspro_conditional_value');

            //recurring fields
            $config["meta"]["recurring_amount_field"] = rgpost("gf_paypalpaymentspro_recurring_amount");
            $config["meta"]["pay_period"] = rgpost("gf_paypalpaymentspro_pay_period");
            $config["meta"]["recurring_times"] = rgpost("gf_paypalpaymentspro_recurring_times");
            $config["meta"]["setup_fee_enabled"] = rgpost('gf_paypalpaymentspro_setup_fee');
            $config["meta"]["setup_fee_amount_field"] = rgpost('gf_paypalpaymentspro_setup_fee_amount');

            //api settings fields
            $config["meta"]["api_settings_enabled"] = rgpost('gf_paypalpaymentspro_api_settings');
            $config["meta"]["api_mode"] = rgpost('gf_paypalpaymentspro_api_mode');
            $config["meta"]["api_username"] = rgpost('gf_paypalpaymentspro_api_username');
            $config["meta"]["api_password"] = rgpost('gf_paypalpaymentspro_api_password');
            $config["meta"]["api_vendor"] = rgpost('gf_paypalpaymentspro_api_vendor');
            $config["meta"]["api_partner"] = rgpost('gf_paypalpaymentspro_api_partner');

            if(!empty($config["meta"]["api_settings_enabled"]))
            {

                $local_api_settings = self::get_local_api_settings($config);
                self::log_debug("Validating credentials.");
                $is_valid = self::is_valid_key($local_api_settings);
                if($is_valid)
                {
                    $config["meta"]["api_valid"] = true;
                    $config["meta"]["api_message"] = "Valid PayPal credentials.";
                    self::log_debug($config["meta"]["api_message"]);
                }
                else
                {
                    $config["meta"]["api_valid"] = false;
                    $config["meta"]["api_message"] = "Invalid PayPal credentials.";
                    self::log_error($config["meta"]["api_message"]);
                }
            }

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){

                $config["meta"]["customer_fields"][$field["name"]] = $_POST["paypalpaymentspro_customer_field_{$field["name"]}"];

            }

            $config = apply_filters('gform_paypalpaymentspro_save_config', $config);

            $is_validation_error = apply_filters("gform_paypalpaymentspro_config_validation", false, $config);

            if(!$is_validation_error){
                $id = GFPayPalPaymentsProData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravityformspaypalpaymentspro"), "<a href='?page=gf_paypalpaymentspro'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }
        }

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();
        $settings = get_option("gf_paypalpaymentspro_settings");
        ?>

        <form method="post" action="">
            <input type="hidden" name="paypalpaymentspro_setting_id" value="<?php echo $id ?>" />

            <div class="margin_vertical_10 <?php echo $is_validation_error ? "paypalpaymentspro_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
                    <span><?php _e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
            </div> <!-- / validation message -->

            <div class="margin_vertical_10">
                <label class="left_header" for="gf_paypalpaymentspro_type"><?php _e("Transaction Type", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_transaction_type") ?></label>

                <select id="gf_paypalpaymentspro_type" name="gf_paypalpaymentspro_type" onchange="SelectType(jQuery(this).val());">
                    <option value=""><?php _e("Select a transaction type", "gravityformspaypalpaymentspro") ?></option>
                    <option value="product" <?php echo rgar($config['meta'], 'type') == "product" ? "selected='selected'" : "" ?>><?php _e("Products and Services", "gravityformspaypalpaymentspro") ?></option>
                    <option value="subscription" <?php echo rgar($config['meta'], 'type') == "subscription" ? "selected='selected'" : "" ?>><?php _e("Subscriptions", "gravityformspaypalpaymentspro") ?></option>
                </select>
            </div>


            <div id="paypalpaymentspro_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_paypalpaymentspro_form" class="left_header"><?php _e("Gravity Form", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_gravity_form") ?></label>

                <select id="gf_paypalpaymentspro_form" name="gf_paypalpaymentspro_form" onchange="SelectForm(jQuery('#gf_paypalpaymentspro_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">

                    <option value=""><?php _e("Select a form", "gravityformspaypalpaymentspro"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFPayPalPaymentsProData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>
                            <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>
                        <?php
                    }
                    ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFPayPalPaymentsPro::get_base_url() ?>/images/loading.gif" id="paypalpaymentspro_wait" style="display: none;"/>

                <div id="gf_paypalpaymentspro_invalid_product_form" class="gf_paypalpaymentspro_invalid_form"  style="display:none;">
                    <?php _e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "gravityformspaypalpaymentspro") ?>
                </div>
                <div id="gf_paypalpaymentspro_invalid_donation_form" class="gf_paypalpaymentspro_invalid_form" style="display:none;">
                    <?php _e("The form selected does not have any Donation fields. Please add a Donation field to the form and try again.", "gravityformspaypalpaymentspro") ?>
                </div>
            </div>

            <div id="paypalpaymentspro_field_group" valign="top" <?php
            echo empty($config["meta"]["type"]) || empty($config["form_id"]) ? "style='display:none;'" : ""
            ?>>

                <div id="paypalpaymentspro_field_container_subscription" class="paypalpaymentspro_field_container" valign="top" <?php echo rgars($config, "meta/type") != "subscription" ? "style='display:none;'" : ""?>>
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_recurring_amount"><?php _e("Recurring Amount", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_recurring_amount") ?></label>
                        <select id="gf_paypalpaymentspro_recurring_amount" name="gf_paypalpaymentspro_recurring_amount">
                            <?php echo self::get_product_options($form, rgar($config["meta"],"recurring_amount_field"),true) ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_pay_period"><?php _e("Pay Period", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_pay_period") ?></label>

                        <select id="gf_paypalpaymentspro_pay_period" name="gf_paypalpaymentspro_pay_period">
                            <option value="WEEK" <?php echo rgars($config, "meta/pay_period") == "WEEK" ? "selected='selected'" : "" ?>><?php _e("Weekly", "gravityformspaypalpaymentspro") ?></option>
                            <option value="BIWK" <?php echo rgars($config, "meta/pay_period") == "BIWK" ? "selected='selected'" : "" ?>><?php _e("Every Two Weeks", "gravityformspaypalpaymentspro") ?></option>
                            <option value="SMMO" <?php echo rgars($config, "meta/pay_period") == "SMMO" ? "selected='selected'" : "" ?>><?php _e("Twice Every Month", "gravityformspaypalpaymentspro") ?></option>
                            <option value="FRWK" <?php echo rgars($config, "meta/pay_period") == "FRWK" ? "selected='selected'" : "" ?>><?php _e("Every Four Weeks", "gravityformspaypalpaymentspro") ?></option>
                            <option value="MONT" <?php echo rgars($config, "meta/pay_period") == "MONT" ? "selected='selected'" : "" ?>><?php _e("Monthly", "gravityformspaypalpaymentspro") ?></option>
                            <option value="QTER" <?php echo rgars($config, "meta/pay_period") == "QTER" ? "selected='selected'" : "" ?>><?php _e("Quarterly", "gravityformspaypalpaymentspro") ?></option>
                            <option value="SMYR" <?php echo rgars($config, "meta/pay_period") == "SMYR" ? "selected='selected'" : "" ?>><?php _e("Twice Every Year", "gravityformspaypalpaymentspro") ?></option>
                            <option value="YEAR" <?php echo rgars($config, "meta/pay_period") == "YEAR" ? "selected='selected'" : "" ?>><?php _e("Yearly", "gravityformspaypalpaymentspro") ?></option>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_recurring_times"><?php _e("Recurring Times", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_recurring_times") ?></label>
                        <select id="gf_paypalpaymentspro_recurring_times" name="gf_paypalpaymentspro_recurring_times">
                            <option value="0"><?php _e("Infinite", "gravityformspaypalpaymentspro") ?></option>
                            <?php
                            for($i=2; $i<=30; $i++){
                                $selected = ($i == rgar($config["meta"],"recurring_times")) ? 'selected="selected"' : '';
                                ?>
                                <option value="<?php echo $i ?>" <?php echo $selected; ?>><?php echo $i ?></option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_setup_fee"><?php _e("Setup Fee", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_setup_fee_enable") ?></label>
                        <input type="checkbox" onchange="if(this.checked) {jQuery('#gf_paypalpaymentspro_setup_fee_amount').val('Select a field');}" name="gf_paypalpaymentspro_setup_fee" id="gf_paypalpaymentspro_setup_fee" value="1" onclick="ToggleSetupFee();" <?php echo rgars($config, "meta/setup_fee_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypalpaymentspro_setup_fee"><?php _e("Enable", "gravityformspaypalpaymentspro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <span id="paypalpaymentspro_setup_fee_container" <?php echo rgars($config, "meta/setup_fee_enabled") ? "" : "style='display:none;'" ?>>
                            <select id="gf_paypalpaymentspro_setup_fee_amount" name="gf_paypalpaymentspro_setup_fee_amount">
                                <?php echo self::get_product_options($form, rgar($config["meta"],"setup_fee_amount_field"),false) ?>
                            </select>
                        </span>
                    </div>

                </div>
                <div class="margin_vertical_10">
                    <label class="left_header"><?php _e("Customer", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_customer") ?></label>
                    <div id="paypalpaymentspro_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                    </div>
                </div>

                <?php
                    $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                ?>

                <div class="margin_vertical_10"  >
                    <ul style="overflow:hidden;">
                        <li id="paypalpaymentspro_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                            <label class="left_header"><?php _e("Options", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_options") ?></label>
                            <input type="checkbox" name="gf_paypalpaymentspro_update_post" id="gf_paypalpaymentspro_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_paypalpaymentspro_update_action').val(action);" />
                            <label class="inline" for="gf_paypalpaymentspro_update_post"><?php _e("Update Post when subscription is cancelled.", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_update_post") ?></label>
                            <select id="gf_paypalpaymentspro_update_action" name="gf_paypalpaymentspro_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_paypalpaymentspro_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php _e("Mark Post as Draft", "gravityformspaypalpaymentspro") ?></option>
                                <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php _e("Delete Post", "gravityformspaypalpaymentspro") ?></option>
                            </select>
                        </li>
                        <?php do_action("gform_paypalpaymentspro_action_fields", $config, $form) ?>
                    </ul>
                </div>

                <?php do_action("gform_paypalpaymentspro_add_option_group", $config, $form); ?>

                <div id="gf_paypalpaymentspro_conditional_section" valign="top" class="margin_vertical_10">
                    <label for="gf_paypalpaymentspro_conditional_optin" class="left_header"><?php _e("PayPal Condition", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_conditional") ?></label>

                    <div id="gf_paypalpaymentspro_conditional_option">
                        <table cellspacing="0" cellpadding="0">
                            <tr>
                                <td>
                                    <input type="checkbox" id="gf_paypalpaymentspro_conditional_enabled" name="gf_paypalpaymentspro_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_paypalpaymentspro_conditional_container').fadeIn('fast');} else{ jQuery('#gf_paypalpaymentspro_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_enabled') ? "checked='checked'" : ""?>/>
                                    <label for="gf_paypalpaymentspro_conditional_enable"><?php _e("Enable", "gravityformspaypalpaymentspro"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="gf_paypalpaymentspro_conditional_container" <?php echo !rgar($config['meta'], 'paypalpaymentspro_conditional_enabled') ? "style='display:none'" : ""?>>

                                        <div id="gf_paypalpaymentspro_conditional_fields" style="display:none">
                                            <?php _e("Send to PayPal if ", "gravityformspaypalpaymentspro") ?>

                                            <select id="gf_paypalpaymentspro_conditional_field_id" name="gf_paypalpaymentspro_conditional_field_id" class="optin_select" onchange='jQuery("#gf_paypalpaymentspro_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="gf_paypalpaymentspro_conditional_operator" name="gf_paypalpaymentspro_conditional_operator">
                                                <option value="is" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravityformspaypalpaymentspro") ?></option>
                                                <option value="isnot" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravityformspaypalpaymentspro") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravityformspaypalpaymentspro") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravityformspaypalpaymentspro") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravityformspaypalpaymentspro") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravityformspaypalpaymentspro") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'paypalpaymentspro_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravityformspaypalpaymentspro") ?></option>
                                            </select>
                                            <div id="gf_paypalpaymentspro_conditional_value_container" name="gf_paypalpaymentspro_conditional_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="gf_paypalpaymentspro_conditional_message" style="display:none">
                                            <?php _e("To create a registration condition, your form must have a field supported by conditional logic.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div> <!-- / paypalpaymentspro conditional -->

                <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_settings"><?php _e("API Settings", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_settings_enable") ?></label>
                        <input type="checkbox" name="gf_paypalpaymentspro_api_settings" id="gf_paypalpaymentspro_api_settings" value="1" onclick="if(jQuery(this).is(':checked')) jQuery('#paypalpaymentspro_api_settings_container').show('slow'); else jQuery('#paypalpaymentspro_api_settings_container').hide('slow');" <?php echo rgars($config, "meta/api_settings_enabled") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paypalpaymentspro_api_settings"><?php _e("Override Default Settings", "gravityformspaypalpaymentspro"); ?></label>
                </div>

                <div id="paypalpaymentspro_api_settings_container" <?php echo rgars($config, "meta/api_settings_enabled") ? "" : "style='display:none;'" ?>>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_mode"><?php _e("API", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_mode") ?></label>
                        <input type="radio" name="gf_paypalpaymentspro_api_mode" value="production" <?php echo rgar($config["meta"],"api_mode") != "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpaymentspro_api_mode_production"><?php _e("Production", "gravityformspaypalpaymentspro"); ?></label>
                        &nbsp;&nbsp;&nbsp;
                        <input type="radio" name="gf_paypalpaymentspro_api_mode" value="test" <?php echo rgar($config["meta"],"api_mode") == "test" ? "checked='checked'" : "" ?>/>
                        <label class="inline" for="gf_paypalpaymentspro_api_mode_test"><?php _e("Sandbox", "gravityformspaypalpaymentspro"); ?></label>
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_username"><?php _e("Username", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_username") ?></label>
                        <input class="size-1" id="gf_paypalpaymentspro_api_username" name="gf_paypalpaymentspro_api_username" value="<?php echo rgar($config["meta"],"api_username") ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo rgars( $config, 'meta/api_message' );  ?>" title="<?php echo rgars( $config, 'meta/api_message' ); ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                    </div>

                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_password"><?php _e("Password", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_password") ?></label>
                        <input type="password" class="size-1" id="gf_paypalpaymentspro_api_password" name="gf_paypalpaymentspro_api_password" value="<?php echo rgar($config["meta"],"api_password") ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo rgars( $config, 'meta/api_message' ); ?>" title="<?php echo rgars( $config, 'meta/api_message' ); ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                    </div>
                    
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_vendor"><?php _e("Vendor (optional)", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_vendor") ?></label>
                        <input class="size-1" id="gf_paypalpaymentspro_api_vendor" name="gf_paypalpaymentspro_api_vendor" value="<?php echo rgar($config["meta"],"api_vendor") ?>" />
                        <?php $api_vendor = rgar($config["meta"],"api_vendor"); if(!empty($api_vendor)) { ?>
                            <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo $config["meta"]["api_message"]  ?>" title="<?php echo $config["meta"]["api_message"] ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                        <?php } ?>
                        <br/> <small>Your merchant login ID if different from Username above.</small>
                    </div>
                    
                    <div class="margin_vertical_10">
                        <label class="left_header" for="gf_paypalpaymentspro_api_partner"><?php _e("Partner", "gravityformspaypalpaymentspro"); ?> <?php gform_tooltip("paypalpaymentspro_api_partner") ?></label>
                        <input class="size-1" id="gf_paypalpaymentspro_api_partner" name="gf_paypalpaymentspro_api_partner" value="<?php echo empty($config["meta"]["api_partner"]) ? "PayPal" : $config["meta"]["api_partner"] ?>" />
                        <img src="<?php echo self::get_base_url() ?>/images/<?php echo rgars($config, "meta/api_valid") ? "tick.png" : "stop.png" ?>" border="0" alt="<?php echo rgars( $config, 'meta/api_message' );  ?>" title="<?php echo rgars( $config, 'meta/api_message' ); ?>" style="display:<?php echo empty($config["meta"]["api_message"]) ? 'none;' : 'inline;' ?>" />
                        <br/> <small>The ID provided to you by the authorized PayPal Reseller.</small>
                    </div>

                </div>

                <div id="paypalpaymentspro_submit_container" class="margin_vertical_30">
                    <input type="submit" name="gf_paypalpaymentspro_submit" value="<?php echo empty($id) ? __("  Save  ", "gravityformspaypalpaymentspro") : __("Update", "gravityformspaypalpaymentspro"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravityformspaypalpaymentspro"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_paypalpaymentspro'" />
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">

            function SelectType(type){
                jQuery("#paypalpaymentspro_field_group").slideUp();

                jQuery("#paypalpaymentspro_field_group input[type=\"text\"], #paypalpaymentspro_field_group select").val("");

                jQuery("#paypalpaymentspro_field_group input:checked").attr("checked", false);

                if(type){
                    jQuery("#paypalpaymentspro_form_container").slideDown();
                    jQuery("#gf_paypalpaymentspro_form").val("");
                }
                else{
                    jQuery("#paypalpaymentspro_form_container").slideUp();
                }
            }

            function SelectForm(type, formId, settingId){
                if(!formId){
                    jQuery("#paypalpaymentspro_field_group").slideUp();
                    return;
                }

                jQuery("#paypalpaymentspro_wait").show();
                jQuery("#paypalpaymentspro_field_group").slideUp();

                var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_paypalpaymentspro_form" );
                mysack.setVar( "gf_select_paypalpaymentspro_form", "<?php echo wp_create_nonce("gf_select_paypalpaymentspro_form") ?>" );
                mysack.setVar( "type", type);
                mysack.setVar( "form_id", formId);
                mysack.setVar( "setting_id", settingId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#paypalpaymentspro_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravityformspaypalpaymentspro") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function EndSelectForm(form_meta, customer_fields, recurring_amount_options, product_field_options){
                //setting global form object
                form = form_meta;

                var type = jQuery("#gf_paypalpaymentspro_type").val();

                jQuery(".gf_paypalpaymentspro_invalid_form").hide();
                if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
                    jQuery("#gf_paypalpaymentspro_invalid_product_form").show();
                    jQuery("#paypalpaymentspro_wait").hide();
                    return;
                }
                else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
                    jQuery("#gf_paypalpaymentspro_invalid_donation_form").show();
                    jQuery("#paypalpaymentspro_wait").hide();
                    return;
                }

                jQuery(".paypalpaymentspro_field_container").hide();
                jQuery("#paypalpaymentspro_customer_fields").html(customer_fields);
                jQuery("#gf_paypalpaymentspro_recurring_amount").html(recurring_amount_options);
                jQuery("#gf_paypalpaymentspro_trial_amount").html(product_field_options);
                jQuery("#gf_paypalpaymentspro_setup_fee_amount").html(product_field_options);

                //displaying delayed post creation setting if current form has a post field
                var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
                if(post_fields.length > 0){
                    jQuery("#paypalpaymentspro_post_action").show();
                }
                else{
                    //jQuery("#gf_paypalpaymentspro_delay_post").attr("checked", false);
                    jQuery("#paypalpaymentspro_post_action").hide();
                }

                if(type == "subscription" && post_fields.length > 0){
                    jQuery("#paypalpaymentspro_post_update_action").show();
                }
                else{
                    jQuery("#gf_paypalpaymentspro_update_post").attr("checked", false);
                    jQuery("#paypalpaymentspro_post_update_action").hide();
                }

                //Calling callback functions
                jQuery(document).trigger('paypalpaymentsproFormSelected', [form]);

                jQuery("#gf_paypalpaymentspro_conditional_enabled").attr('checked', false);
                SetPayPalPaymentsProCondition("","");

                jQuery("#paypalpaymentspro_field_container_" + type).show();
                jQuery("#paypalpaymentspro_field_group").slideDown();
                jQuery("#paypalpaymentspro_wait").hide();
            }

            function GetFieldsByType(types){
                var fields = new Array();
                for(var i=0; i<form["fields"].length; i++){
                    if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                        fields.push(form["fields"][i]);
                }
                return fields;
            }

            function IndexOf(ary, item){
                for(var i=0; i<ary.length; i++)
                    if(ary[i] == item)
                        return i;
                return -1;
            }

        </script>

        <script type="text/javascript">
            // Paypal Conditional Functions
            <?php
            if(!empty($config["form_id"])){
                ?>

                // initilize form object
                form = <?php echo GFCommon::json_encode($form)?> ;

                // initializing registration condition drop downs
                jQuery(document).ready(function(){
                    var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["paypalpaymentspro_conditional_field_id"])?>";
                    var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["paypalpaymentspro_conditional_value"])?>";
                    SetPayPalPaymentsProCondition(selectedField, selectedValue);
                });

                <?php
            }
            ?>

            function SetPayPalPaymentsProCondition(selectedField, selectedValue){

                // load form fields
                jQuery("#gf_paypalpaymentspro_conditional_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#gf_paypalpaymentspro_conditional_field_id").val();
                var checked = jQuery("#gf_paypalpaymentspro_conditional_enabled").attr('checked');

                if(optinConditionField){
                    jQuery("#gf_paypalpaymentspro_conditional_message").hide();
                    jQuery("#gf_paypalpaymentspro_conditional_fields").show();
                    jQuery("#gf_paypalpaymentspro_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#gf_paypalpaymentspro_conditional_value").val(selectedValue);
                }
                else{
                    jQuery("#gf_paypalpaymentspro_conditional_message").show();
                    jQuery("#gf_paypalpaymentspro_conditional_fields").hide();
                }

                if(!checked) jQuery("#gf_paypalpaymentspro_conditional_container").hide();

            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
                    str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_paypalpaymentspro_conditional_value", "name"=> "gf_paypalpaymentspro_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
                }
                else if(field.choices){
                    str += '<select id="gf_paypalpaymentspro_conditional_value" name="gf_paypalpaymentspro_conditional_value" class="optin_select">'

                    for(var i=0; i<field.choices.length; i++){
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if(isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if(!isAnySelected && selectedValue){
                        str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }
                    str += "</select>";
                }
                else
                {
                    selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
                    //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
                    str += "<input type='text' placeholder='<?php _e("Enter value", "gravityforms"); ?>' id='gf_paypalpaymentspro_conditional_value' name='gf_paypalpaymentspro_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(!text)
                    return "";

                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
                inputType = field.inputType ? field.inputType : field.type;
                var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title",
                                        "post_tags", "post_custom_field", "post_content", "post_excerpt"];

                var index = jQuery.inArray(inputType, supported_fields);

                return index >= 0;
            }

        </script>

        <?php

    }

    public static function select_paypalpaymentspro_form(){

        check_ajax_referer("gf_select_paypalpaymentspro_form", "gf_select_paypalpaymentspro_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "",true);
        $product_fields = self::get_product_options($form, "",false);

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "', '" . str_replace("'", "\'", $product_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_paypalpaymentspro");
        $wp_roles->add_cap("administrator", "gravityforms_paypalpaymentspro_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_paypalpaymentspro", "gravityforms_paypalpaymentspro_uninstall"));
    }

    public static function has_paypalpaymentspro_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["paypalpaymentspro_conditional_operator"]) ? $config["paypalpaymentspro_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["paypalpaymentspro_conditional_field_id"]);

        if(empty($field) || !$config["paypalpaymentspro_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["paypalpaymentspro_conditional_value"], $operator);
        $go_to_paypalpaymentspro = $is_value_match && $is_visible;

        return  $go_to_paypalpaymentspro;
    }

    public static function get_config($form){
        if(!class_exists("GFPayPalPaymentsProData"))
            require_once(self::get_base_path() . "/data.php");

        //Getting settings associated with this transaction
        $configs = GFPayPalPaymentsProData::get_feed_by_form($form["id"]);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_paypalpaymentspro_condition($form, $config))
                return $config;
        }
        return false;

    }

    public static function get_config_by_entry($entry_id){
        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        $feed_id = gform_get_meta($entry_id, "paypalpaymentspro_feed_id");
        $config = GFPayPalPaymentsProData::get_feed($feed_id);
        return $config;
    }

    public static function get_creditcard_field($form){
        $fields = GFCommon::get_fields_by_type($form, array("creditcard"));
        return empty($fields) ? false : $fields[0];
    }

    private static function is_ready_for_capture($validation_result){

        //if form has already failed validation or this is not the last page, abort
        if($validation_result["is_valid"] == false || !self::is_last_page($validation_result["form"]))
            return false;

        //getting config that matches condition (if conditions are enabled)
        $config = self::get_config($validation_result["form"]);
        if(!$config)
            return false;

        //making sure credit card field is visible
        $creditcard_field = self::get_creditcard_field($validation_result["form"]);
        if(RGFormsModel::is_field_hidden($validation_result["form"], $creditcard_field, array()))
            return false;

        return $config;
    }

    private static function is_last_page($form){
        $current_page = GFFormDisplay::get_source_page($form["id"]);
        $target_page = GFFormDisplay::get_target_page($form, $current_page, rgpost("gform_field_values"));
        return $target_page == 0;
    }

    private static function has_visible_products($form){
        foreach($form["fields"] as $field){
            if($field["type"] == "product" && !RGFormsModel::is_field_hidden($form, $field, ""))
                return true;
        }
        return false;
    }

    public static function get_product_billing_data($form, $lead, $config){

        // get products
        $products = GFCommon::get_product_fields($form, $lead);

        $data = array();
        $data["billing"] = array('DESC'=>'');
        $data["products"] = $products;
        $data["amount"] = 0;
        $item = 0;

        //------------------------------------------------------
        //creating line items and recurring description
        $recurring_amount_field = $config["meta"]["recurring_amount_field"];
        foreach($products["products"] as $product_id => $product)
        {
            if(!self::include_in_total($product_id, $config)){
                continue;
            }

            $product_amount = GFCommon::to_number($product["price"]);
            if(is_array(rgar($product,"options"))){
                foreach($product["options"] as $option){
                    $product_amount += $option["price"];
                }
            }
            $data["amount"] += ($product_amount * $product["quantity"]);

            //adding line items
            if($config["meta"]["type"] == "product"){
                $data["billing"]['L_NAME'.$item] = $product["name"];
                $data["billing"]['L_DESC'.$item] = $product["name"];
                $data["billing"]['L_AMT'.$item]  = $product_amount;
                $data["billing"]['L_NUMBER'.$item] = $item+1;
                $data["billing"]['L_QTY'.$item] = $product["quantity"];
            }
            else
            {
                //adding recurring description
                $data["billing"]['DESC'] .=  $item > 1 ? ", " . $product["name"] : $product["name"];
            }

            $item++;
        }

        //adding shipping information if feed is configured for products and services or a subscription based on the form total
        if(!empty($products["shipping"]["name"]) && ($config["meta"]["type"] == "product" || $recurring_amount_field == "all")){
            if($config["meta"]["type"] == "product"){
                $data["billing"]['L_NAME'.$item] = $products["shipping"]["name"];
                $data["billing"]['L_AMT'.$item]  = $products["shipping"]["price"];
                $data["billing"]['L_NUMBER'.$item] = $item+1;
                $data["billing"]['L_QTY'.$item] = 1;
            }
            $data["amount"] += $products["shipping"]["price"];
        }

        $data["line_items"] = $item;

        return $data;
    }

    public static function paypalpaymentspro_validation($validation_result){

        $config = self::is_ready_for_capture($validation_result);
        if(!$config)
            return $validation_result;

        require_once(self::get_base_path() . "/data.php");

        $form = $validation_result["form"];
        
        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
             $local_api_settings = self::get_local_api_settings($config);
             
        $billing = self::prepare_credit_card_transaction($form,$config);
        $amount = $billing['AMT'];

        if($config["meta"]["type"] == "product"){

            if($amount == 0)
            {
                self::log_debug("Amount is 0. No need to authorize payment, but act as if transaction was successful");

                //blank out credit card field if this is the last page
                if(self::is_last_page($form)){
	                $card_field = self::get_creditcard_field( $form );
	                if ( $card_field ) {
		                $_POST["input_{$card_field["id"]}_1"] = "";
	                }
                }
                //creating dummy transaction response if there are any visible product fields in the form
                if(self::has_visible_products($form)){
                    self::$transaction_response = array("transaction_id" => "N/A", "amount" => 0, "transaction_type" => 1, 'config_id' => $config['id']);
                }
	            return $validation_result;
            }
            else
            {
            
                self::log_debug("Capturing payment in the amount of " . $amount . ".");
                $response = self::capture_product_payment($config,$form);
                if($response["RESULT"] == 0)
                {
                    self::log_debug("Payment in the amount of " . $amount . " was captured successfully.");
                    return $validation_result;
                }       
                else
                {
                    self::log_error("Payment Capture was NOT successful.");
                    return self::set_validation_result($validation_result, $_POST, $response);
                } 
            }
        }
        else
        {
            //setting up recurring transaction parameters 
            $billing['TENDER'] = "C";
            $billing['TRXTYPE'] = "R";
            $billing['ACTION'] = "A";

            //setting up a recurring payment
            $billing['START'] = date("mdY", mktime(0, 0, 0, date("m"), date("d")+1, date("y")));
            $billing['PROFILENAME'] = $billing['FIRSTNAME'] . " " . $billing['LASTNAME'];
            $billing['MAXFAILEDPAYMENTS'] = "0";
            $billing['PAYPERIOD'] = $config["meta"]["pay_period"];
            $billing['TERM'] = $config["meta"]["recurring_times"];
            $billing['AMT'] = $amount;

            //setup fee
            $setup_fee_amount = 0;
            if($config["meta"]["setup_fee_enabled"])
            {
                $lead = RGFormsModel::create_lead($form);
                $product_billing_data = self::get_product_billing_data($form, $lead, $config);
                $products = $product_billing_data["products"];
                $setup_fee_product = rgar($products["products"], $config["meta"]["setup_fee_amount_field"]);
                if(!empty($setup_fee_product))
                    $setup_fee_amount = self::get_product_price($setup_fee_product);
            }

            //saving recurring profile to create after submission
            self::$recurring_profile = $billing;
            
            self::log_debug("Creating recurring profile.");
            $response = self::create_subscription_profile($config,$form,$setup_fee_amount);

            if($response["RESULT"] == 0)
            {
                $initial_payment_message = "";
                if($setup_fee_amount > 0)
                    $initial_payment_message = " Setup fee in the amount of " . $amount . " was captured successfully.";
                self::log_debug("Recurring profile created." . $initial_payment_message);
                return $validation_result;
            }
            else
            {
                $initial_payment_message = "";
                if($setup_fee_amount > 0)
                    $initial_payment_message = " Setup fee was NOT captured.";
                self::log_error("Recurring profile was NOT created." . $initial_payment_message);
                return self::set_validation_result($validation_result, $_POST, $response);
            } 
        }

	    return $validation_result;
    }
    
    private static function create_subscription_profile($config,$form,$fee_amount){

        $subscription = self::$recurring_profile;
        //$subscription["PROFILENAME"] .= "  " . $entry["id"];
        if(!empty($fee_amount) && $fee_amount > 0)
        {
            $subscription['OPTIONALTRX']="S";
            $subscription['OPTIONALTRXAMT']= $fee_amount;
        }
        else
        {
            $subscription['OPTIONALTRX']="A";
        }

        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
             $local_api_settings = self::get_local_api_settings($config);

        $subscription = apply_filters("gform_paypalpaymentspro_args_before_subscription", $subscription, $form["id"]);
        $response = self::post_to_payflow($subscription,$local_api_settings, $form["id"]);

        if($response["RESULT"] == 0){
            self::$transaction_response = array("transaction_id" => rgar($response,"RPREF"),  "subscription_amount" => $subscription['AMT'], "initial_payment_amount" => $fee_amount, "transaction_type" => 2, 'config_id' => $config['id'], 'profile_id' =>  $response['PROFILEID']);
        }
        
        return $response;
    
    }

    private static function prepare_credit_card_transaction($form,$config){
    
        // Billing Information
        $card_field = self::get_creditcard_field($form);
        $card_number = rgpost("input_{$card_field["id"]}_1");
        $expiration_date = rgpost("input_{$card_field["id"]}_2");
        $country = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["country"]));
        $country = GFCommon::get_country_code($country);

        $args = array();
        $args['ACCT'] = $card_number;
        if(strlen($expiration_date[0]) == 1) $expiration_date[0] = '0'.$expiration_date[0];
            $args['EXPDATE'] = $expiration_date[0].substr($expiration_date[1], -2);
        $args['CVV2'] = rgpost("input_{$card_field["id"]}_3");
        $args['STREET'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["address1"]));
        $args['CITY'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["city"]));
        $args['STATE'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["state"]));
        $args['ZIP'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["zip"]));
        $args['BILLTOCOUNTRY'] = $country == "UK" ? "GB" : $country;
        $args['CURRENCY'] = GFCommon::get_currency();

        // Customer Contact
        $args['FIRSTNAME'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["first_name"]));
        $args['LASTNAME'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["last_name"]));
        $args['EMAIL'] = rgpost('input_'. str_replace(".", "_",$config["meta"]["customer_fields"]["email"]));

        $lead = RGFormsModel::create_lead($form);
        $product_billing_data = self::get_product_billing_data($form, $lead, $config);
        $amount = $product_billing_data["amount"];
        $products = $product_billing_data["products"];
        $args = array_merge($args, $product_billing_data["billing"]);
        
        $args["AMT"] = $amount;
        $args["TENDER"] = "C";
        
        return $args;
        
    }
    
    private static function include_in_total($product_id, $config){
        //always include all products in a product feed
        if ($config["meta"]["type"] == "product")
            return true;

        $recurring_field = $config["meta"]["recurring_amount_field"];

        if($recurring_field == $product_id){
            return true;
        }
        else if($recurring_field == "all"){
            //don't use field that is mapped to the setup fee
            if($config["meta"]["setup_fee_enabled"] && $config["meta"]["setup_fee_amount_field"] == $product_id)
                return false;
            else
                return true;
        }

        return false;

    }

    private static function get_product_price($product){
        $amount = GFCommon::to_number($product["price"]);
        if($product["options"])
        {
            foreach($product["options"] as $option){
                $amount += $option["price"];
            }
        }

        $amount *= $product["quantity"];

        return $amount;

    }

    private static function set_validation_result($validation_result,$post,$response){

        $message = "";
        $code = $response["RESULT"];
        $error_long_message = rgar($response,"RESPMSG");

        switch($code){
            case "50" :
                $message = __("<!-- Payment Error: " . $code . " -->This credit card has been declined by your bank. Please use another form of payment.", "gravityforms");
            break;

            case "24" :
                $message = __("<!-- Payment Error: " . $code . " -->The credit card has expired.", "gravityforms");
            break;

            case "1021" :
                $message = __("<!-- Payment Error: " . $code . " -->The merchant does not accept this type of credit card.", "gravityforms");
            break;

            case "12" :
            case "23" :
                $message = __("<!-- Payment Error: " . $code . " -->There was an error processing your credit card. Please verify the information and try again.", "gravityforms");
            break;

            default :
                $message = __("<!-- Payment Error: " . $code . " -->There was an error processing your request. Your credit card was not charged. Please try again.", "gravityforms");
        }

        self::log_debug("Validation result - Error code: {$code} Message: {$message}");

        foreach($validation_result["form"]["fields"] as &$field)
        {
            if($field["type"] == "creditcard")
            {
                $field["failed_validation"] = true;
                $field["validation_message"] = $message;
                break;
             }

        }
        $validation_result["is_valid"] = false;
        return $validation_result;
    }


    public static function process_renewals(){

        if(!self::is_gravityforms_supported())
            return;

        // getting user information
        $user_id = 0;
        $user_name = "System";

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        // getting all paypalpaymentspro subscription feeds
        $recurring_feeds = GFPayPalPaymentsProData::get_feeds();
        foreach($recurring_feeds as $feed)
        {
            // process renewalls if paypal payments pro feed is subscription feed
            if($feed["meta"]["type"]=="subscription")
            {
                $form_id = $feed["form_id"];

                $querytime = strtotime(gmdate("Y-m-d"));
                $querydate = gmdate("mdY", $querytime);

                // finding leads with a late payment date
                global $wpdb;
                $results = $wpdb->get_results("SELECT l.id, l.transaction_id, m.meta_value as payment_date
                                                FROM {$wpdb->prefix}rg_lead l
                                                INNER JOIN {$wpdb->prefix}rg_lead_meta m ON l.id = m.lead_id
                                                WHERE l.form_id={$form_id}
                                                AND payment_status = 'Active'
                                                AND meta_key = 'subscription_payment_date'
                                                AND meta_value <'{$querydate}'");
                foreach($results as $result)
                {
                    $entry_id = $result->id;
                    $subscription_id = $result->transaction_id;

                    $entry = RGFormsModel::get_lead($entry_id);

                    // Determine if feed specific api settings are enabled
                    $local_api_settings = array();
                    if($feed["meta"]["api_settings_enabled"] == 1)
                         $local_api_settings = self::get_local_api_settings($feed);

                    // Get the subscription profile status
                    $profile_status_request = array();
                    $profile_status_request["TRXTYPE"] = "R";
                    $profile_status_request["TENDER"] = "C";
                    $profile_status_request["ACTION"] = "I";
                    $profile_status_request["ORIGPROFILEID"] = $subscription_id;

                    $profile_status = self::post_to_payflow($profile_status_request, $local_api_settings, $entry["form_id"]);

                    // Get the subscription profile status
                    $payment_status_request = array();
                    $payment_status_request["TRXTYPE"] = "R";
                    $payment_status_request["TENDER"] = "C";
                    $payment_status_request["ACTION"] = "I";
                    $payment_status_request["ORIGPROFILEID"] = $subscription_id;
                    $payment_status_request["PAYMENTHISTORY"] = "Y";

                    $payment_status = self::post_to_payflow($payment_status_request,$local_api_settings, $entry["form_id"]);

                    $status = $profile_status["STATUS"];

                    switch(strtolower($status)){
                        case "active" :

                            // getting new payment date
                            $new_payment_date = $profile_status["NEXTPAYMENT"];

                            if($new_payment_date > $querydate)
                            {
                                // finding payment count
                                $payment_count = gform_get_meta($entry_id, "subscription_payment_count");
                                $new_payment_count = $payment_count + 1;

                                // update subscription payment and lead information
                                gform_update_meta($entry_id, "subscription_payment_count",$new_payment_count);
                                gform_update_meta($entry_id, "subscription_payment_date",$new_payment_date);
                                RGFormsModel::add_note($entry_id, $user_id, $user_name, sprintf(__("Subscription payment has been made. Amount: %s. Subscriber Id: %s", "gravityforms"), GFCommon::to_money($profile_status["AMT"], $entry["currency"]),$subscription_id));
                                $transaction_id = $subscription_id;
                                GFPayPalPaymentsProData::insert_transaction($entry["id"], $feed["id"], "payment", $subscription_id, $transaction_id, $profile_status["AMT"]);

                                do_action("gform_paypalpaymentspro_after_subscription_payment", $entry, $subscription_id, $profile_status["AMT"]);
                            }

                         break;

                         case "expired" :
                               $entry["payment_status"] = "Expired";
                               GFAPI::update_entry( $entry );
                               RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription has successfully completed its billing schedule. Subscriber Id: %s", "gravityforms"), $subscription_id));

                               do_action("gform_paypalpaymentspro_subscription_expired", $entry, $subscription_id);
                         break;

                         case "too many failures":
                         case "deactivated by merchant":
                               self::cancel_subscription($entry);
                               RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription was cancelled due to transaction failures. Subscriber Id: %s", "gravityforms"), $subscription_id));

                         break;

                         default:
                              self::cancel_subscription($entry);
                              RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Subscription was cancelled due to transaction failures. Subscriber Id: %s", "gravityforms"), $subscription_id));

                         break;
                    }
                }

            }
        }

    }

    public static function paypalpaymentspro_commit_transaction($entry,$form){

        if(empty(self::$transaction_response))
            return $entry;

        $entry_id = $entry["id"];
        $transaction_type = self::$transaction_response["transaction_type"];
        $transaction_id = self::$transaction_response["transaction_id"];
        
        //Current Currency
        $currency = GFCommon::get_currency();
        
        //saving feed id
        $config = self::get_config($form);
        gform_update_meta($entry_id, "paypalpaymentspro_feed_id", $config["id"]);
        //updating form meta with current payment gateway
        gform_update_meta($entry_id, "payment_gateway", "paypalpaymentspro");

        if($transaction_type == "2")
        {
            
            $subscription_amount = rgar(self::$transaction_response, "subscription_amount");
            $fee_amount = rgar(self::$transaction_response, "initial_payment_amount");
            $profile_id = rgar(self::$transaction_response, "profile_id");
            
            $tomorrow = mktime(0,0,0,date("m"),date("d")+1,date("Y"));

            $entry["payment_status"] = "Active";
            $entry["transaction_id"] = $profile_id;
            $entry["payment_date"] = date("Y-m-d H:i:s", $tomorrow);

            //Add subsciption creation and initial payment note
            RGFormsModel::add_note($entry["id"], 0, "System", sprintf(__("Subscription has been created and initial payment will be made %s. Amount: %s. Subscriber Id: %s", "gravityforms"), date("m-d-Y", $tomorrow), GFCommon::to_money($subscription_amount, $currency),$profile_id));
            GFPayPalPaymentsProData::insert_transaction($entry["id"], $config["id"], "payment", $profile_id, $transaction_id, $subscription_amount);

            //Add note for setup fee payment if completed
            if(!empty($fee_amount) && $fee_amount > 0){
                RGFormsModel::add_note($entry["id"], 0, "System", sprintf(__("Setup fee payment has been made. Amount: %s. Subscriber Id: %s", "gravityforms"), GFCommon::to_money($fee_amount, $currency),$profile_id));
                GFPayPalPaymentsProData::insert_transaction($entry["id"], $config["id"], "payment", $profile_id, $transaction_id, $fee_amount);
            }

            gform_update_meta($entry["id"], "subscription_payment_date",date("mdY", $tomorrow));
            
        }
        else
        {
            
            if(self::$transaction_response["transaction_id"])
            {
                $amount = self::$transaction_response["amount"];
                $entry["payment_amount"] = $amount;
                
                $entry["transaction_id"] = $transaction_id;
                $entry["payment_status"] = "Approved";
                
                $payment_date = gmdate("Y-m-d H:i:s");
                $entry["payment_date"] = $payment_date;
                
                GFPayPalPaymentsProData::insert_transaction($entry["id"],$config["id"], "payment", $transaction_id, $transaction_id, $amount);     
                
                do_action("gform_paypalpaymentspro_post_capture", $amount, $entry, $form, $config);
                
            }
                
        }
        
        $entry["currency"] = $currency;
        $entry["transaction_type"] = $transaction_type;
        $entry["is_fulfilled"] = true;

	    GFAPI::update_entry( $entry );
        
        return $entry;

    }

    private static function capture_product_payment($config,$form){

        // Determine if feed specific api settings are enabled
        $local_api_settings = array();
        if($config["meta"]["api_settings_enabled"] == 1)
             $local_api_settings = self::get_local_api_settings($config);
         
        $args = self::prepare_credit_card_transaction($form,$config);

        $args["TRXTYPE"] = "S";
        
        $args = apply_filters("gform_paypalpaymentspro_args_before_payment", $args, $form["id"]);

        $response = self::post_to_payflow($args,$local_api_settings, $form["id"]);
        
        if(isset($response["RESULT"]) && $response["RESULT"] == 0)
        {
            self::$transaction_response = array("transaction_id" =>  $response["PNREF"], "amount" => $args["AMT"], "transaction_type" => 1, 'config_id' => $config['id'], "product_payment_success" => $response["RESULT"]);
        }

        return $response;
        
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFPayPalPaymentsPro::has_access("gravityforms_paypalpaymentspro_uninstall"))
            die(__("You don't have adequate permission to uninstall the PayPal Payments Pro Add-On.", "gravityformspaypalpaymentspro"));

        //droping all tables
        GFPayPalPaymentsProData::drop_tables();

        //removing options
        delete_option("gf_paypalpaymentspro_version");
        delete_option("gf_paypalpaymentspro_settings");

        //Deactivating plugin
        $plugin = "gravityformspaypalpaymentspro/paypalpaymentspro.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));

    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){

        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='paypalpaymentspro_col_heading'>" . __("PayPal Fields", "gravityformspaypalpaymentspro") . "</td><td class='paypalpaymentspro_col_heading'>" . __("Form Fields", "gravityformspaypalpaymentspro") . "</td></tr>";

        $customer_fields = self::get_customer_fields();

        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='paypalpaymentspro_field_cell'>" . $field["label"]  . "</td><td class='paypalpaymentspro_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(array("name" => "first_name" , "label" => "First Name"), array("name" => "last_name" , "label" =>"Last Name"),
        array("name" => "email" , "label" =>"Email"), array("name" => "address1" , "label" =>"Billing Address"), array("name" => "address2" , "label" =>"Billing Address 2"),
        array("name" => "city" , "label" =>"Billing City"), array("name" => "state" , "label" =>"Billing State"), array("name" => "zip" , "label" =>"Billing Zip"),
        array("name" => "country" , "label" =>"Billing Country"));
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "paypalpaymentspro_customer_field_" . $variable_name;

        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";

        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));
            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field, $form_total){
        $str = "<option value=''>" . __("Select a field", "gravityformspaypalpaymentspro") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);
            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        if($form_total){
            $selected = $selected_field == 'all' ? "selected='selected'" : "";
            $str .= "<option value='all' " . $selected . ">" . __("Form Total", "gravityformspaypalpaymentspro") ."</option>";
        }

        return $str;
    }

    private static function get_form_fields($form){

        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){
                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function is_paypalpaymentspro_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_paypalpaymentspro"));
    }

    public static function set_logging_supported($plugins)
    {
        $plugins[self::$slug] = "PayPal Payments Pro";
        return $plugins;
    }

    private static function log_error($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    private static function log_debug($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }

    //Returns the url of the plugin's root folder
    public static function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    public static function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

	public function disable_entry_info_payment( $is_enabled, $entry ) {

		$config = self::get_config_by_entry( $entry['id'] );

		return $config ? false : $is_enabled;
	}
}
?>