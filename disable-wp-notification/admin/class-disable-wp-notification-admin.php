<?php
/**
*
* Defines the plugin name, version, and two hooks to
* enqueue the admin-specific stylesheet and JavaScript.
*
* @link       https://sourabhagrawal.com/
* @since      1.0.0
* @package    Disable_Wp_Notification
* @subpackage Disable_Wp_Notification/admin
* @author     Sourabh Agrawal <sourabh.asct@gmail.com>
*/
class Disable_Wp_Notification_Admin {
	
	/**
	* The ID of this plugin.
	*
	* @since    1.0.0
	* @access   private
	* @var      string    $plugin_name    The ID of this plugin.
	*/
	private $plugin_name;
	
	/**
	* The version of this plugin.
	*
	* @since    1.0.0
	* @access   private
	* @var      string    $version    The current version of this plugin.
	*/
	private $version;
	
	/**
	* Initialize the class and set its properties.
	*
	* @since    1.0.0
	* @param      string    $plugin_name       The name of this plugin.
	* @param      string    $version    The version of this plugin.
	*/
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}
	
	/**
	* Register the menu for the admin area.
	*
	* @since    1.0.0
	*/
	public function admin_menu() {
		/**
		* An instance of this class should be passed to the run() function
		* defined in Disable_Wp_Notification_Loader as all of the hooks are defined
		* in that particular class.
		*
		* The Disable_Wp_Notification_Loader will then create the relationship
		* between the defined hooks and the functions defined in this
		* class.
		*/
		
		/*
		*	Check if this setting can be managed by only the site administrator
		*	No other user role will be able to manage this settings
		*/
		
		if(function_exists('wp_get_current_user')){
			$user = wp_get_current_user();
			$CurentUserRoles = (array) $user->roles;
			if(in_array('administrator', $CurentUserRoles)){
				add_menu_page( __('Disable Notifications', 'disable-wp-notification'), __('Disable Notices', 'disable-wp-notification'), 'manage_options', 'disable-wp-notification', array($this, 'disable_notification'), 'dashicons-welcome-comments', 99  );
				
			}
		}
	}
	
	/**
	* Options page callback
	*
	* @since    3.2
	*/
	public function disable_notification()
	{
		$option = array();
		$option['enable'] = '';
		$option['all'] = '';
		$option['without-admin'] = '';
		
		// Set class property
		if(isset($_POST['disable_notifications'])){
			$user_role = sanitize_text_field($_POST['disable_notifications']['user_role']);
			
			$savedOptions['user_role'] = $user_role;
			update_option('disable_notifications', $savedOptions);
		}
		
		$options = get_option( 'disable_notifications' );
		if (!empty($options)) {
			if (in_array('enable', $options)) {
				$option['enable'] = 'checked = "checked"';
			}
			if (in_array('all', $options)) {
				$option['all'] = 'checked = "checked"';
			}
			if (in_array('without-admin', $options)) {
				$option['without-admin'] = 'checked = "checked"';
			}
		} 
		
		?>
		<div class="wrap">
		<div class="disable-notification-wrapper">
		<header> <?php echo __('Disable Notifications', 'disable-wp-notification'); ?></header>
		<section>
		<form method="post">
		<div class="menu-wrapper">
		<div class="left-menu">
		<div class="handler-wrapper">
		<div class="element-wrapper show">
		<div class="event-handler" aria-controls="area-1"> <?php echo __('Settings', 'disable-wp-notification');?> </div>
		<div class="handled-section" id="area-1">
		<div class="form-element">
		<div class="option"> 
		<input type="radio" <?php echo $option['enable']; ?> id="item-0" name="disable_notifications[user_role]" value="enable">
		</div>
		<div class="text"><label for="item-0"><?php echo __('Enable all notifications', 'disable-wp-notification'); ?> </label></div>
		</div>
		
		<div class="form-element">
		<div class="option"> 
		<input type="radio" <?php echo $option['all']; ?> id="item-1" name="disable_notifications[user_role]" value="all">
		</div>
		<div class="text"><label for="item-1"><?php echo __('Disable Notifications for all users', 'disable-wp-notification'); ?> </label></div>
		</div>
		<div class="form-element">
		<div class="option"> 
		<input type="radio" <?php echo $option['without-admin']; ?> id="item-2" name="disable_notifications[user_role]" value="without-admin">
		</div>
		<div class="text"><label for="item-2"><?php echo __('Disable Notifications for all users except admin', 'disable-wp-notification'); ?> </label></div>
		</div>
		
		<?php
		if(function_exists('submit_button')){
			submit_button(__('Update', 'disable-wp-notification'));
		} else {
			?> 
			<input type="submit" value="Update" name="disable_wp_notification_update">
			<?php
		}
		?>
		
		</div>
		</div>
		<div class="element-wrapper show">
		<div class="event-handler" aria-controls="area-2"> <?php echo __('Notifications', 'disable-wp-notification');?> </div>
		<div id="display-notifications" class="handled-section" id="area-2">
		<h1><?php echo __('All The Notifications', 'disable-wp-notification');?></h1>
		</div>
		</div>
		<div class="element-wrapper show">
		<div class="event-handler" aria-controls="area-3"> <?php echo __('About / Features', 'disable-wp-notification');?> </div>
		<div class="handled-section" id="area-2">
		<div class="description">
		<ul>
		<li><?php echo __('Disable All the Notifications.', 'disable-wp-notification');?></li>
		<li><?php echo __('Disable Plugin Update Notification.', 'disable-wp-notification');?></li>
		<li><?php echo __('Disable Theme Update Notification.', 'disable-wp-notification');?></li>
		</ul>
		</div>
		</div>
		</div>
		
		<div class="element-wrapper show">
		<div class="event-handler" aria-controls="area-3"> <?php echo __('Buy a coffee for developer', 'disable-wp-notification');?> </div>
		<div class="handled-section" id="area-2">
		<div class="description">
			<p><?php echo __('If you find this plugin valuable and would like to support its further development, you can show your appreciation by buying us a coffee through a voluntary monetary contribution. Your support will help us continue improving and growing the plugin. Additionally, we would greatly appreciate it if you could take a moment to leave a review or rating for the plugin, as it helps us reach more users and gather feedback for further enhancements. However, please note that both the contribution and review/rating are completely optional and not mandatory.', 'disable-wp-notification');?></p> <p><b><?php echo __('Thank you for considering it!', 'disable-wp-notification');?></b></p>
			
			<div class="rating-review">
				<h6>Write us a review</h6>
				<a href="https://wordpress.org/support/plugin/disable-wp-notification/reviews/#new-post" target="_new"><img src="<?php echo plugin_dir_url( __DIR__ ).'admin/img/rating-review.jpg';?>" alt="Review us"/></a>
			</div>
			<div class="phone-pe-wrapper">
				<h6>Pay With UPI</h6>
				<img src="<?php echo plugin_dir_url( __DIR__ ).'admin/img/phonepe.jpeg';?>" alt="Phone Pe">
			</div>
			<div class="paypal-wrapper">
			<h6>Pay With PayPal</h6>
				<div id="smart-button-container">
				<div style="text-align: center"><label for="description">Comment </label><input type="text" name="descriptionInput" id="description" maxlength="127" value=""></div>
				<p id="descriptionError" style="visibility: hidden; color:red; text-align: center;">Please enter a description</p>
				<div style="text-align: center"><label for="amount">Donate Amount </label><input name="amountInput" type="number" id="amount" value="" ><span> USD</span></div>
				<p id="priceLabelError" style="visibility: hidden; color:red; text-align: center;">Please enter a price</p>
				<div id="invoiceidDiv" style="text-align: center; display: none;"><label for="invoiceid"> </label><input name="invoiceid" maxlength="127" type="text" id="invoiceid" value="" ></div>
				<p id="invoiceidError" style="visibility: hidden; color:red; text-align: center;">Please enter an Invoice ID</p>
				<div style="text-align: center;margin-top: 0.625rem;width: 250px;margin-left: auto;margin-right: auto; margin-bottom: 20px;" id="paypal-button-container"></div>
				</div>
				<script src="https://www.paypal.com/sdk/js?client-id=sb&enable-funding=venmo&currency=USD" data-sdk-integration-source="button-factory"></script>
				<script>
				function initPayPalButton() {
					var description = document.querySelector('#smart-button-container #description');
					var amount = document.querySelector('#smart-button-container #amount');
					var descriptionError = document.querySelector('#smart-button-container #descriptionError');
					var priceError = document.querySelector('#smart-button-container #priceLabelError');
					var invoiceid = document.querySelector('#smart-button-container #invoiceid');
					var invoiceidError = document.querySelector('#smart-button-container #invoiceidError');
					var invoiceidDiv = document.querySelector('#smart-button-container #invoiceidDiv');
					
					var elArr = [description, amount];
					
					if (invoiceidDiv.firstChild.innerHTML.length > 1) {
						invoiceidDiv.style.display = "block";
					}
					
					var purchase_units = [];
					purchase_units[0] = {};
					purchase_units[0].amount = {};
					
					function validate(event) {
						return event.value.length > 0;
					}
					
					paypal.Buttons({
						style: {
							color: 'gold',
							shape: 'pill',
							label: 'pay',
							layout: 'horizontal',
							tagline: true
						},
						
						onInit: function (data, actions) {
							actions.disable();
							
							if(invoiceidDiv.style.display === "block") {
								elArr.push(invoiceid);
							}
							
							elArr.forEach(function (item) {
								item.addEventListener('keyup', function (event) {
									var result = elArr.every(validate);
									if (result) {
										actions.enable();
									} else {
										actions.disable();
									}
								});
							});
						},
						
						onClick: function () {
							if (description.value.length < 1) {
								descriptionError.style.visibility = "visible";
							} else {
								descriptionError.style.visibility = "hidden";
							}
							
							if (amount.value.length < 1) {
								priceError.style.visibility = "visible";
							} else {
								priceError.style.visibility = "hidden";
							}
							
							if (invoiceid.value.length < 1 && invoiceidDiv.style.display === "block") {
								invoiceidError.style.visibility = "visible";
							} else {
								invoiceidError.style.visibility = "hidden";
							}
							
							purchase_units[0].description = description.value;
							purchase_units[0].amount.value = amount.value;
							
							if(invoiceid.value !== '') {
								purchase_units[0].invoice_id = invoiceid.value;
							}
						},
						
						createOrder: function (data, actions) {
							return actions.order.create({
								purchase_units: purchase_units,
							});
						},
						
						onApprove: function (data, actions) {
							return actions.order.capture().then(function (orderData) {
								
								// Full available details
								console.log('Capture result', orderData, JSON.stringify(orderData, null, 2));
								
								// Show a success message within this page, e.g.
								const element = document.getElementById('paypal-button-container');
								element.innerHTML = '';
								element.innerHTML = '<h3>Thank you for your payment!</h3>';
								
								// Or go to another URL:  actions.redirect('thank_you.html');
								
							});
						},
						
						onError: function (err) {
							console.log(err);
						}
					}).render('#paypal-button-container');
				}
				initPayPalButton();
				</script>
			</div>

		</div>
		</div>
		</div>
		</div>
		</div>
		</div>
		</form>
		</section>
		</div>
		</div>
		<?php
	}
	/**
	* Register the stylesheets for the admin area.
	*
	* @since    3.2
	*/
	public function enqueue_styles() {
		
		/**
		* An instance of this class should be passed to the run() function
		* defined in Disable_Wp_Notification_Loader as all of the hooks are defined
		* in that particular class.
		*
		* The Disable_Wp_Notification_Loader will then create the relationship
		* between the defined hooks and the functions defined in this
		* class.
		*/
		
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/disable-wp-notification-admin.css', array(), $this->version, 'all' );
		
		if(isset($_POST['disable_notifications'])){
			$user_role = sanitize_text_field($_POST['disable_notifications']['user_role']);
			$options['user_role'] = $user_role;
		} else {
			$options = get_option('disable_notifications');
		}
		
		
		if(!empty($options) && function_exists('wp_get_current_user')){
			$user = wp_get_current_user();
			$CurentUserRoles = (array) $user->roles;
			if(in_array('enable', $options)){ } else {
				if (
					(in_array('all', $options))
					|| (!in_array('administrator', $CurentUserRoles) && in_array('without-admin', $options))
					) {
						?> 
						<style type="text/css">
						body.wp-admin:not(.theme-editor-php) .notice:not(.updated):not(.new-application-password-notice),
						body.wp-admin .update-nag,
						body.wp-admin #adminmenu .awaiting-mod, 
						#adminmenu .update-plugins,
						#message.woocommerce-message,
						body.wp-admin .plugin-update.colspanchange,
						body.wp-admin .fs-notice.fs-type-plugin,
						.notice.elementor-message.elementor-message-dismissed
						{display: none !important;}
						
						body.wp-admin #display-notifications .notice,
						body.wp-admin #display-notifications .update-nag,
						#display-notifications #message.woocommerce-message 
						{
							display: block !important;
						}
						</style>
						<?php
					}
				}
			}
		}
		/**
		* Register the JavaScript for the admin area.
		*
		* @since    1.0.3
		*/
		public function enqueue_scripts() {
			
			/**
			* An instance of this class should be passed to the run() function
			* defined in Disable_Wp_Notification_Loader as all of the hooks are defined
			* in that particular class.
			*
			* The Disable_Wp_Notification_Loader will then create the relationship
			* between the defined hooks and the functions defined in this
			* class.
			*/
			
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/disable-wp-notification-admin.js', array( 'jquery' ), $this->version, false );
			
			if(isset($_POST['disable_notifications'])){
				$user_role = sanitize_text_field($_POST['disable_notifications']['user_role']);
				$options['user_role'] = $user_role;
			} else {
				$options = get_option('disable_notifications');
			}
			
			if(!empty($options) && function_exists('wp_get_current_user')){
				$user = wp_get_current_user();
				$CurentUserRoles = (array) $user->roles;
				
				if(in_array('enable', $options)){ } else {
					if (
						(in_array('administrator', $CurentUserRoles) && in_array('all', $options))
						|| (!in_array('administrator', $CurentUserRoles) && in_array('without-admin', $options))
						) {
							
							wp_enqueue_script( $this->plugin_name.'-1', plugin_dir_url( __FILE__ ) . 'js/disable-wp-notifications.js', array( 'jquery' ), $this->version, false );
						}
					}
				}
			}
			
			public function add_settings_link( $links ) {
				$settings_link = '<a href="options-general.php?page=disable-wp-notification">' . __( 'Settings' ) . '</a>';
				array_push( $links, $settings_link );
				return $links;
			}
		}
		