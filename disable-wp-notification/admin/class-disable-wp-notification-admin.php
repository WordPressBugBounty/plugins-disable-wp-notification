<?php
/**
 * Defines the plugin name, version, and hooks to
 * manage notice interception, settings, and drawer UI.
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
	 * Accumulated notices captured during the current page load.
	 *
	 * @since    4.0
	 * @access   private
	 * @var      array     $new_blocked_notices
	 */
	private $new_blocked_notices = array();
	
	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param    string    $plugin_name       The name of this plugin.
	 * @param    string    $version           The version of this plugin.
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
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			$CurentUserRoles = (array) $user->roles;
			if ( in_array( 'administrator', $CurentUserRoles ) ) {
				$icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' .
					'<defs>' .
					'<mask id="dwpn-logo-mask-menu">' .
					'<rect width="100" height="100" fill="white" />' .
					'<line x1="30" y1="13" x2="75" y2="87" stroke="black" stroke-width="12" stroke-linecap="square" />' .
					'</mask>' .
					'</defs>' .
					'<path d="M 13,13 L 50,13 C 71,13 87,29 87,50 C 87,71 71,87 50,87 L 13,87 L 43,50 Z" fill="currentColor" mask="url(#dwpn-logo-mask-menu)" />' .
					'</svg>';
				$icon_data = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );

				add_menu_page( 
					__( 'Disable Notifications', 'disable-wp-notification' ), 
					__( 'Disable Notices', 'disable-wp-notification' ), 
					'manage_options', 
					'disable-wp-notification', 
					array( $this, 'disable_notification' ), 
					$icon_data, 
					99  
				);
			}
		}
	}

	/**
	 * Initialize notice capturing hooks.
	 *
	 * @since    4.0
	 */
	public function init_notice_capture() {
		// Only intercept notices on back-end admin screens and not during AJAX requests
		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		$options = get_option( 'disable_notifications', array() );

		// Migrate older settings format (from version 3.4 or older)
		if ( is_array( $options ) && ! isset( $options['user_role'] ) ) {
			$migrated_role = '';
			if ( in_array( 'all', $options, true ) ) {
				$migrated_role = 'all';
			} elseif ( in_array( 'without-admin', $options, true ) ) {
				$migrated_role = 'without-admin';
			} elseif ( in_array( 'enable', $options, true ) ) {
				$migrated_role = 'enable';
			}

			if ( ! empty( $migrated_role ) ) {
				$options = array(
					'user_role'     => $migrated_role,
					'block_core'    => 0,
					'block_plugins' => 0,
					'block_themes'  => 0,
					'hide_bell'     => 0,
					'blocked_types' => array(),
					'muted_plugins' => array()
				);
				update_option( 'disable_notifications', $options );
			}
		}

		$user_role_setting = isset( $options['user_role'] ) ? $options['user_role'] : '';

		$is_settings_page = ( isset( $_GET['page'] ) && 'disable-wp-notification' === $_GET['page'] );

		// Check if there are any active rules that would block notices
		$has_active_filters = (
			( isset( $options['block_core'] ) && $options['block_core'] ) ||
			( isset( $options['block_plugins'] ) && $options['block_plugins'] ) ||
			( isset( $options['block_themes'] ) && $options['block_themes'] ) ||
			( isset( $options['blocked_types'] ) && ! empty( $options['blocked_types'] ) ) ||
			( isset( $options['muted_plugins'] ) && ! empty( $options['muted_plugins'] ) )
		);
		$current_user_id = get_current_user_id();
		$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
		$has_dismissed = ( is_array( $dismissed_notices ) && ! empty( $dismissed_notices ) );
		$has_blocking_rules = $has_active_filters || $has_dismissed;

		// If global mode is "Show All" and there are no active specific blocking rules, we don't need to intercept anything
		if ( 'enable' === $user_role_setting && ! $has_blocking_rules && ! $is_settings_page ) {
			return;
		}

		// If global mode is "Disable for non-admins", and the current user is an admin,
		// we only need to intercept if there are active specific blocking rules for this admin.
		if ( 'without-admin' === $user_role_setting && current_user_can( 'manage_options' ) && ! $has_blocking_rules && ! $is_settings_page ) {
			return;
		}

		// Register buffering hooks
		$notice_hooks = array( 'all_admin_notices', 'admin_notices', 'user_admin_notices', 'network_admin_notices' );
		foreach ( $notice_hooks as $hook ) {
			add_action( $hook, array( $this, 'start_notice_buffer' ), -9999 );
			add_action( $hook, array( $this, 'end_notice_buffer' ), 9999 );
		}

		// Hook to save accumulated notices at the end of the request
		add_action( 'shutdown', array( $this, 'save_accumulated_notices' ) );

		// Register Admin Bar Bell and Footer Drawer
		if ( current_user_can( 'manage_options' ) ) {
			$hide_bell = isset( $options['hide_bell'] ) && $options['hide_bell'];
			if ( ! $hide_bell ) {
				add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_bell' ), 99 );
				add_action( 'admin_footer', array( $this, 'render_notification_drawer' ) );
			}
		}
	}

	/**
	 * Start notice buffer.
	 *
	 * @since    4.0
	 */
	public function start_notice_buffer() {
		ob_start();
	}

	/**
	 * End notice buffer and process outputs.
	 *
	 * @since    4.0
	 */
	public function end_notice_buffer() {
		$html = ob_get_clean();
		if ( ! empty( $html ) ) {
			$this->process_notices( $html );
		}
	}

	/**
	 * Process and filter notice HTML.
	 *
	 * @since    4.0
	 * @param    string    $html
	 */
	public function process_notices( $html ) {
		if ( empty( $html ) || ( strpos( $html, 'notice' ) === false && strpos( $html, 'update-nag' ) === false && strpos( $html, 'updated' ) === false && strpos( $html, 'e-conversion-banner' ) === false ) ) {
			echo $html;
			return;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="wp-notices-wrapper">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		// Query top-level notice boxes
		$nodes = $xpath->query( "//div[contains(@class, 'notice') or contains(@class, 'update-nag') or contains(@class, 'woocommerce-message') or contains(@class, 'plugin-update') or contains(@class, 'fs-notice') or contains(@class, 'elementor-message') or contains(@class, 'updated') or contains(@class, 'e-conversion-banner')] | //p[contains(@class, 'notice') or contains(@class, 'update-nag')]" );

		foreach ( $nodes as $node ) {
			$parent = $node->parentNode;
			$is_nested = false;
			while ( $parent && $parent->nodeName !== 'html' ) {
				if ( $parent->nodeType === XML_ELEMENT_NODE ) {
					$class = $parent->getAttribute( 'class' );
					if ( preg_match( '/\b(notice|update-nag|woocommerce-message|plugin-update|fs-notice|elementor-message|updated|e-conversion-banner)\b/', $class ) ) {
						$is_nested = true;
						break;
					}
				}
				$parent = $parent->parentNode;
			}
			if ( $is_nested ) {
				continue;
			}

			$notice_html = $dom->saveHTML( $node );
			
			$source_data = $this->detect_notice_source( $notice_html );
			$notice_type = $this->detect_notice_type( $notice_html );
			
			$is_update = false;
			if ( strpos( $notice_html, 'update-nag' ) !== false || strpos( $notice_html, 'plugin-update' ) !== false || strpos( $notice_html, 'update-core.php' ) !== false ) {
				$is_update = true;
			}

			$should_block = $this->should_block_notice( $notice_html, $source_data, $notice_type, $is_update );

			if ( $should_block ) {
				$hash = md5( trim( strip_tags( $notice_html ) ) );
				$this->save_blocked_notice( $hash, $notice_html, $source_data, $notice_type );
				$node->parentNode->removeChild( $node );
			}
		}

		$wrapper = $dom->getElementById( 'wp-notices-wrapper' );
		if ( $wrapper ) {
			$inner_html = '';
			foreach ( $wrapper->childNodes as $child ) {
				$inner_html .= $dom->saveHTML( $child );
			}
			echo $inner_html;
		} else {
			echo $html;
		}
	}

	/**
	 * Detect source of notice (WordPress core, active plugin or theme).
	 *
	 * @since    4.0
	 * @param    string    $html
	 * @return   array
	 */
	private function detect_notice_source( $html ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}

		// Explicit keyword checks for popular plugins to ensure accurate detection
		$keyword_slugs = array(
			'optincraft'               => 'optincraft',
			'elementor'                => 'elementor',
			'google-sitemap-generator' => 'google-sitemap-generator',
			'google xml sitemaps'      => 'google-sitemap-generator',
			'sitemap-generator'        => 'google-sitemap-generator',
		);

		foreach ( $keyword_slugs as $keyword => $target_slug ) {
			if ( stripos( $html, $keyword ) !== false ) {
				foreach ( $active_plugins as $plugin_path ) {
					if ( dirname( $plugin_path ) === $target_slug ) {
						$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
						$name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $target_slug ) );
						return array( 'slug' => $target_slug, 'name' => $name );
					}
				}
			}
		}

		foreach ( $active_plugins as $plugin_path ) {
			$slug = dirname( $plugin_path );
			if ( '.' === $slug || empty( $slug ) ) {
				continue;
			}

			if ( stripos( $html, $slug ) !== false ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
				$name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $slug ) );
				return array( 'slug' => $slug, 'name' => $name );
			}
		}

		$theme = wp_get_theme();
		$theme_slug = $theme->get_stylesheet();
		if ( stripos( $html, $theme_slug ) !== false ) {
			return array( 'slug' => $theme_slug, 'name' => $theme->get( 'Name' ) );
		}

		if ( strpos( $html, 'update-nag' ) !== false || strpos( $html, 'update-core.php' ) !== false ) {
			return array( 'slug' => 'wp-core', 'name' => 'WordPress Core' );
		}

		return array( 'slug' => 'other', 'name' => 'Other / System' );
	}

	/**
	 * Detect notice type (error, warning, success, info).
	 *
	 * @since    4.0
	 * @param    string    $html
	 * @return   string
	 */
	private function detect_notice_type( $html ) {
		if ( preg_match( '/\b(notice-error|error)\b/i', $html ) ) {
			return 'error';
		} elseif ( preg_match( '/\b(notice-warning)\b/i', $html ) ) {
			return 'warning';
		} elseif ( preg_match( '/\b(notice-success|updated)\b/i', $html ) ) {
			return 'success';
		} elseif ( preg_match( '/\b(notice-info)\b/i', $html ) ) {
			return 'info';
		}
		return 'info';
	}

	/**
	 * Decide if notice should be blocked.
	 *
	 * @since    4.0
	 * @param    string    $notice_html
	 * @param    array     $source
	 * @param    string    $type
	 * @param    bool      $is_update
	 * @return   bool
	 */
	private function should_block_notice( $notice_html, $source, $type, $is_update ) {
		$options = get_option( 'disable_notifications', array() );

		// Check if dismissed permanently
		$hash = md5( trim( strip_tags( $notice_html ) ) );
		$current_user_id = get_current_user_id();
		$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
		if ( is_array( $dismissed_notices ) && in_array( $hash, $dismissed_notices ) ) {
			return true;
		}

		// Only exclusion is when user updates the page (e.g. settings saved, post updated, etc.)
		$is_action_request = (
			$_SERVER['REQUEST_METHOD'] === 'POST' ||
			isset( $_GET['settings-updated'] ) ||
			isset( $_GET['message'] ) ||
			( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'success', 'updated', 'edit' ) ) )
		);

		if ( $is_action_request ) {
			// Allow status updates, action-oriented success/warning/error alerts to display
			if ( preg_match( '/\b(notice-success|updated|settings-error|notice-error|error)\b/i', $notice_html ) ) {
				return false;
			}
		}

		// 1. Check update rules
		if ( $is_update ) {
			if ( 'wp-core' === $source['slug'] ) {
				$block_core = isset( $options['block_core'] ) ? $options['block_core'] : false;
				if ( $block_core ) {
					return true;
				}
			}
			if ( strpos( $notice_html, 'plugin-update' ) !== false ) {
				$block_plugins = isset( $options['block_plugins'] ) ? $options['block_plugins'] : false;
				if ( $block_plugins ) {
					return true;
				}
			}
			if ( strpos( $notice_html, 'theme-update' ) !== false || ( 'other' === $source['slug'] && strpos( $notice_html, 'theme' ) !== false && strpos( $notice_html, 'update' ) !== false ) ) {
				$block_themes = isset( $options['block_themes'] ) ? $options['block_themes'] : false;
				if ( $block_themes ) {
					return true;
				}
			}
		}

		// 2. Check plugin muting
		if ( ! empty( $source['slug'] ) && 'other' !== $source['slug'] && 'wp-core' !== $source['slug'] ) {
			$muted_plugins = isset( $options['muted_plugins'] ) ? $options['muted_plugins'] : array();
			if ( in_array( $source['slug'], $muted_plugins ) ) {
				return true;
			}
		}

		// 3. Check level rules
		$blocked_types = isset( $options['blocked_types'] ) ? $options['blocked_types'] : array();
		if ( in_array( $type, $blocked_types ) ) {
			return true;
		}

		// 4. Check global fallback settings
		$user_role_setting = isset( $options['user_role'] ) ? $options['user_role'] : '';
		if ( 'all' === $user_role_setting ) {
			return true;
		}
		if ( 'without-admin' === $user_role_setting ) {
			if ( current_user_can( 'manage_options' ) ) {
				return false;
			}
			return true;
		}

		return false;
	}

	/**
	 * Save notice to class property.
	 *
	 * @since    4.0
	 * @param    string    $hash
	 * @param    string    $html
	 * @param    array     $source
	 * @param    string    $type
	 */
	private function save_blocked_notice( $hash, $html, $source, $type ) {
		$this->new_blocked_notices[$hash] = array(
			'id'          => $hash,
			'html'        => $html,
			'source_slug' => $source['slug'],
			'source_name' => $source['name'],
			'type'        => $type,
			'time'        => time(),
			'url'         => $_SERVER['REQUEST_URI']
		);
	}

	/**
	 * Save accumulated notices to user transient.
	 *
	 * @since    4.0
	 */
	public function save_accumulated_notices() {
		if ( empty( $this->new_blocked_notices ) ) {
			return;
		}

		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$existing = get_transient( $transient_key );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( $this->new_blocked_notices as $hash => $notice ) {
			$existing[$hash] = $notice;
		}

		if ( count( $existing ) > 50 ) {
			$existing = array_slice( $existing, -50, 50, true );
		}

		set_transient( $transient_key, $existing, 24 * HOUR_IN_SECONDS );
	}

	/**
	 * AJAX handler to dismiss a notice.
	 *
	 * @since    4.0
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'dwpn_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( $_POST['hash'] ) : '';
		if ( empty( $hash ) ) {
			wp_send_json_error( array( 'message' => 'Invalid hash ID' ) );
		}

		$current_user_id = get_current_user_id();
		$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
		if ( ! is_array( $dismissed_notices ) ) {
			$dismissed_notices = array();
		}

		if ( ! in_array( $hash, $dismissed_notices ) ) {
			$dismissed_notices[] = $hash;
			update_user_meta( $current_user_id, 'dwpn_dismissed_notices', $dismissed_notices );
		}

		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$active_notices = get_transient( $transient_key );
		if ( is_array( $active_notices ) && isset( $active_notices[$hash] ) ) {
			unset( $active_notices[$hash] );
			set_transient( $transient_key, $active_notices, 24 * HOUR_IN_SECONDS );
		}

		wp_send_json_success( array( 'message' => 'Dismissed' ) );
	}

	/**
	 * AJAX handler to clear all notices.
	 *
	 * @since    4.0
	 */
	public function ajax_clear_all_notices() {
		check_ajax_referer( 'dwpn_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		
		$active_notices = get_transient( $transient_key );
		if ( is_array( $active_notices ) && ! empty( $active_notices ) ) {
			$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
			if ( ! is_array( $dismissed_notices ) ) {
				$dismissed_notices = array();
			}
			foreach ( array_keys( $active_notices ) as $hash ) {
				if ( ! in_array( $hash, $dismissed_notices ) ) {
					$dismissed_notices[] = $hash;
				}
			}
			update_user_meta( $current_user_id, 'dwpn_dismissed_notices', $dismissed_notices );
		}

		delete_transient( $transient_key );
		wp_send_json_success( array( 'message' => 'Cleared all' ) );
	}

	/**
	 * Add Bell Icon with Badge Count in WP Admin Bar.
	 *
	 * @since    4.0
	 */
	public function add_admin_bar_bell( $wp_admin_bar ) {
		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$blocked_notices = get_transient( $transient_key );
		$count = is_array( $blocked_notices ) ? count( $blocked_notices ) : 0;

		$badge = '';
		$class = 'dwpn-bell-trigger';
		if ( $count > 0 ) {
			$badge = '<span class="dwpn-bell-badge">' . $count . '</span>';
			$class .= ' has-notifications';
		}

		$title = '<span class="ab-icon"></span>' . $badge;

		$wp_admin_bar->add_node( array(
			'id'    => 'dwpn-notifications-bell',
			'title' => $title,
			'href'  => '#',
			'meta'  => array(
				'class' => $class,
				'title' => __( 'Notifications Center', 'disable-wp-notification' )
			),
		) );
	}

	/**
	 * Render Notification Center Drawer in footer.
	 *
	 * @since    4.0
	 */
	public function render_notification_drawer() {
		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$blocked_notices = get_transient( $transient_key );
		if ( ! is_array( $blocked_notices ) ) {
			$blocked_notices = array();
		}

		uasort( $blocked_notices, function( $a, $b ) {
			return $b['time'] - $a['time'];
		} );

		$nonce = wp_create_nonce( 'dwpn_ajax_nonce' );
		?>
		<div id="dwpn-drawer-overlay" class="dwpn-drawer-overlay"></div>
		<div id="dwpn-drawer" class="dwpn-drawer" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="dwpn-drawer-header">
				<h3>
					<?php echo __( 'Notification Center', 'disable-wp-notification' ); ?>
					<span class="dwpn-count-indicator"><?php echo count( $blocked_notices ); ?></span>
				</h3>
				<div class="dwpn-drawer-actions">
					<?php if ( ! empty( $blocked_notices ) ) : ?>
						<button id="dwpn-clear-all" class="dwpn-btn-clear"><?php echo __( 'Clear All', 'disable-wp-notification' ); ?></button>
					<?php endif; ?>
					<button id="dwpn-drawer-close" class="dwpn-btn-close">&times;</button>
				</div>
			</div>
			
			<div class="dwpn-drawer-body">
				<?php if ( empty( $blocked_notices ) ) : ?>
					<div class="dwpn-no-notifications">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="dwpn-empty-icon"><circle cx="12" cy="12" r="10"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>
						<p><?php echo __( 'All clear! No blocked notifications.', 'disable-wp-notification' ); ?></p>
					</div>
				<?php else : ?>
					<div class="dwpn-notice-list">
						<?php foreach ( $blocked_notices as $hash => $notice ) : 
							$time_diff = human_time_diff( $notice['time'], time() ) . ' ' . __( 'ago', 'disable-wp-notification' );
							$source_name = esc_html( $notice['source_name'] );
							$type_class = 'dwpn-type-' . esc_attr( $notice['type'] );
							?>
							<div class="dwpn-notice-item <?php echo $type_class; ?>" data-hash="<?php echo esc_attr( $hash ); ?>">
								<div class="dwpn-notice-meta">
									<span class="dwpn-notice-source"><?php echo $source_name; ?></span>
									<span class="dwpn-notice-time"><?php echo $time_diff; ?></span>
								</div>
								<div class="dwpn-notice-content">
									<?php 
									$clean_html = str_replace(
										array( 'class="notice ', 'class=\'notice ', 'class="notice"', 'class=\'notice\'' ),
										array( 'class="dwpn-rendered-notice ', 'class=\'dwpn-rendered-notice ', 'class="dwpn-rendered-notice"', 'class=\'dwpn-rendered-notice\'' ),
										$notice['html']
									);
									echo wp_kses_post( $clean_html ); 
									?>
								</div>
								<div class="dwpn-notice-footer">
									<button class="dwpn-action-dismiss" data-hash="<?php echo esc_attr( $hash ); ?>">
										<?php echo __( 'Dismiss', 'disable-wp-notification' ); ?>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="dwpn-drawer-footer">
				<a href="<?php echo admin_url( 'options-general.php?page=disable-wp-notification' ); ?>" class="dwpn-btn-settings">
					<?php echo __( 'Go to Settings', 'disable-wp-notification' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Options page callback.
	 *
	 * @since    3.2
	 */
	public function disable_notification() {
		// Set class property
		if ( isset( $_POST['disable_notifications'] ) ) {
			check_admin_referer( 'dwpn_save_settings_nonce', 'dwpn_nonce' );

			$post_data = $_POST['disable_notifications'];
			$savedOptions = array();
			
			$savedOptions['user_role'] = sanitize_text_field( isset( $post_data['user_role'] ) ? $post_data['user_role'] : 'enable' );
			$savedOptions['block_core'] = isset( $post_data['block_core'] ) ? 1 : 0;
			$savedOptions['block_plugins'] = isset( $post_data['block_plugins'] ) ? 1 : 0;
			$savedOptions['block_themes'] = isset( $post_data['block_themes'] ) ? 1 : 0;
			$savedOptions['hide_bell'] = isset( $post_data['hide_bell'] ) ? 1 : 0;
			
			// Blocked notice levels
			$savedOptions['blocked_types'] = array();
			if ( isset( $post_data['blocked_types'] ) && is_array( $post_data['blocked_types'] ) ) {
				foreach ( $post_data['blocked_types'] as $type ) {
					$savedOptions['blocked_types'][] = sanitize_text_field( $type );
				}
			}
			
			// Muted plugins slugs
			$savedOptions['muted_plugins'] = array();
			if ( isset( $post_data['muted_plugins'] ) && is_array( $post_data['muted_plugins'] ) ) {
				foreach ( $post_data['muted_plugins'] as $slug ) {
					$savedOptions['muted_plugins'][] = sanitize_text_field( $slug );
				}
			}
			
			update_option( 'disable_notifications', $savedOptions );
			echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Settings saved successfully.', 'disable-wp-notification' ) . '</p></div>';
		}

		if ( isset( $_POST['dwpn_reset_dismissed'] ) ) {
			check_admin_referer( 'dwpn_reset_dismissed_nonce', 'dwpn_reset_nonce' );
			$current_user_id = get_current_user_id();
			delete_user_meta( $current_user_id, 'dwpn_dismissed_notices' );
			echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Cleared alerts history has been restored successfully. All alerts will show again when triggered.', 'disable-wp-notification' ) . '</p></div>';
		}
		
		$options = get_option( 'disable_notifications', array() );
		
		$user_role = isset( $options['user_role'] ) ? $options['user_role'] : 'enable';
		$block_core = isset( $options['block_core'] ) ? $options['block_core'] : 0;
		$block_plugins = isset( $options['block_plugins'] ) ? $options['block_plugins'] : 0;
		$block_themes = isset( $options['block_themes'] ) ? $options['block_themes'] : 0;
		$hide_bell = isset( $options['hide_bell'] ) ? $options['hide_bell'] : 0;
		$blocked_types = isset( $options['blocked_types'] ) ? $options['blocked_types'] : array();
		$muted_plugins = isset( $options['muted_plugins'] ) ? $options['muted_plugins'] : array();

		// Fetch active plugins for granular muting list
		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}

		$plugin_list = array();
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $active_plugins as $plugin_path ) {
			$slug = dirname( $plugin_path );
			if ( '.' === $slug || empty( $slug ) || 'disable-wp-notification' === $slug ) {
				continue;
			}
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
			$plugin_list[$slug] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $slug ) );
		}
		asort( $plugin_list );

		// Fetch blocked notices history for stats
		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$blocked_history = get_transient( $transient_key );
		if ( ! is_array( $blocked_history ) ) {
			$blocked_history = array();
		}
		$total_blocked_now = count( $blocked_history );
		?>
		<div id="dwpn-settings-page" class="wrap">
			<div class="dwpn-settings-header">
				<div class="dwpn-brand">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" class="dwpn-brand-logo">
						<defs>
							<mask id="dwpn-logo-mask-settings">
								<rect width="100" height="100" fill="white" />
								<line x1="30" y1="13" x2="75" y2="87" stroke="black" stroke-width="12" stroke-linecap="square" />
							</mask>
						</defs>
						<path d="M 13,13 L 50,13 C 71,13 87,29 87,50 C 87,71 71,87 50,87 L 13,87 L 43,50 Z" fill="currentColor" mask="url(#dwpn-logo-mask-settings)" />
					</svg>
					<span class="dwpn-brand-text"><?php echo __( 'Disable WP Notification', 'disable-wp-notification' ); ?> <span class="dwpn-version-badge">v4.1</span></span>
				</div>
				<p class="dwpn-tagline"><?php echo __( 'Keep your WordPress dashboard clean and focused. Automatically disable cluttering administrative alerts and collect them into a central, easy-to-read Notification Center.', 'disable-wp-notification' ); ?></p>
			</div>

			<div class="dwpn-settings-container">
				<div class="dwpn-sidebar">
					<ul class="dwpn-tabs">
						<li class="dwpn-tab active" data-tab="tab-dashboard">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
							<?php echo __( 'Dashboard', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-general">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
							<?php echo __( 'General Settings', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-filters">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
							<?php echo __( 'Granular Filters', 'disable-wp-notification' ); ?>
						</li>
						<?php /*
						<li class="dwpn-tab" data-tab="tab-plugins">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><path d="m21 16-4 4-4-4"></path><path d="M17 20V4"></path><path d="m3 8 4-4 4 4"></path><path d="M7 4v16"></path></svg>
							<?php echo __( 'Muted Plugins', 'disable-wp-notification' ); ?>
						</li>
						*/ ?>
						<li class="dwpn-tab" data-tab="tab-history">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path></svg>
							<?php echo __( 'Blocked History', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-coffee">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="4"></circle><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"></line><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"></line><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"></line><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"></line></svg>
							<?php echo __( 'Support & Feedback', 'disable-wp-notification' ); ?>
						</li>
					</ul>
				</div>

				<div class="dwpn-main">
					<form method="post" action="">
						<?php wp_nonce_field( 'dwpn_save_settings_nonce', 'dwpn_nonce' ); ?>

						<!-- Tab: Dashboard -->
						<div id="tab-dashboard" class="dwpn-tab-content active">
							<div class="dwpn-dashboard-grid">
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php echo __( 'Active Filter Rules', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val">
										<?php 
										$rules_count = 0;
										if ( 'enable' !== $user_role ) $rules_count++;
										if ( $block_core ) $rules_count++;
										if ( $block_plugins ) $rules_count++;
										if ( $block_themes ) $rules_count++;
										$rules_count += count( $blocked_types );
										$rules_count += count( $muted_plugins );
										echo $rules_count;
										?>
									</div>
									<div class="dwpn-stat-desc"><?php echo __( 'Total custom rules active to disable your dashboard alerts.', 'disable-wp-notification' ); ?></div>
								</div>
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php echo __( 'Inbox Notifications', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val"><?php echo $total_blocked_now; ?></div>
									<div class="dwpn-stat-desc"><?php echo __( 'Unread alerts stored in your Notification Center.', 'disable-wp-notification' ); ?></div>
								</div>
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php echo __( 'Global Status', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val">
										<?php if ( 'enable' === $user_role ) : ?>
											<span class="dwpn-status-inactive"><?php echo __( 'Disabled', 'disable-wp-notification' ); ?></span>
										<?php else : ?>
											<span class="dwpn-status-active"><?php echo __( 'Active', 'disable-wp-notification' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="dwpn-stat-desc"><?php echo __( 'Active state of notification filtering on your dashboard.', 'disable-wp-notification' ); ?></div>
								</div>
							</div>

							<div class="dwpn-card mt-20">
								<h3><?php echo __( 'Welcome to Disable WP Notification!', 'disable-wp-notification' ); ?></h3>
								<p><?php echo __( 'This plugin automatically collects and disables administrative alerts, keeping them out of your main workspace. You can access all your disabled updates at any time by clicking the bell icon in the top navigation bar.', 'disable-wp-notification' ); ?></p>
								<p><strong><?php echo __( 'Quick Checklist to get started:', 'disable-wp-notification' ); ?></strong></p>
								<ul class="dwpn-checklist">
									<li><?php echo __( 'Configure visibility under <strong>General Settings</strong> to choose who should have notifications disabled.', 'disable-wp-notification' ); ?></li>
									<li><?php echo __( 'Customize filters under <strong>Granular Filters</strong> to manage system updates (WordPress core, plugins, or themes).', 'disable-wp-notification' ); ?></li>
								</ul>
							</div>
						</div>

						<!-- Tab: General -->
						<div id="tab-general" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php echo __( 'Notification Settings', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php echo __( 'Choose who sees administrative notifications and how they are displayed.', 'disable-wp-notification' ); ?></p>
								
								<div class="dwpn-form-group">
									<label class="dwpn-label"><?php echo __( 'Choose Notification Mode', 'disable-wp-notification' ); ?></label>
									
									<div class="dwpn-radio-option">
										<input type="radio" id="role-enable" name="disable_notifications[user_role]" value="enable" <?php checked( $user_role, 'enable' ); ?>>
										<label for="role-enable">
											<strong><?php echo __( 'Show All Notifications', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Show all alerts normally on the screen.', 'disable-wp-notification' ); ?></span>
										</label>
									</div>

									<div class="dwpn-radio-option">
										<input type="radio" id="role-all" name="disable_notifications[user_role]" value="all" <?php checked( $user_role, 'all' ); ?>>
										<label for="role-all">
											<strong><?php echo __( 'Disable Notifications for All Users', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Disable all administrative alerts and collect them into the Notification Center for everyone, including administrators.', 'disable-wp-notification' ); ?></span>
										</label>
									</div>

									<div class="dwpn-radio-option">
										<input type="radio" id="role-without-admin" name="disable_notifications[user_role]" value="without-admin" <?php checked( $user_role, 'without-admin' ); ?>>
										<label for="role-without-admin">
											<strong><?php echo __( 'Disable for All Users Except Administrators (Recommended)', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Keep notices visible to administrators while disabling notifications for all other user roles (Editors, Authors, etc.).', 'disable-wp-notification' ); ?></span>
										</label>
									</div>
								</div>

								<div class="dwpn-divider"></div>

								<div class="dwpn-form-group">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[hide_bell]" value="1" <?php checked( $hide_bell, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php echo __( 'Hide Navigation Bar Bell Icon', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Hide the top navigation bar bell icon and drawer for a completely clean view.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>
							</div>
							
							<div class="dwpn-submit-wrapper">
								<?php submit_button( __( 'Save Settings', 'disable-wp-notification' ), 'primary', 'submit', false ); ?>
							</div>
						</div>

						<!-- Tab: Filters -->
						<div id="tab-filters" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php echo __( 'System Reminders & Updates', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php echo __( 'Disable core updates and plugin/theme reminders.', 'disable-wp-notification' ); ?></p>

								<div class="dwpn-form-group">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_core]" value="1" <?php checked( $block_core, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php echo __( 'Disable WordPress Core Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Keep WordPress core version update alerts disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>

								<div class="dwpn-form-group mt-15">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_plugins]" value="1" <?php checked( $block_plugins, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php echo __( 'Disable Plugin Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Keep plugin update reminders disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>

								<div class="dwpn-form-group mt-15">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_themes]" value="1" <?php checked( $block_themes, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php echo __( 'Disable Theme Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php echo __( 'Keep theme update alerts disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>
							</div>

							<?php /*
							<div class="dwpn-card mt-20">
								<h3><?php echo __( 'Filter by Alert Priority', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php echo __( 'Choose which types of alerts should be disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></p>

								<div class="dwpn-checkbox-grid">
									<label class="dwpn-checkbox-card">
										<input type="checkbox" name="disable_notifications[blocked_types][]" value="success" <?php checked( in_array( 'success', $blocked_types ) ); ?>>
										<span class="dwpn-checkbox-label">
											<strong><?php echo __( 'Successful Task Messages', 'disable-wp-notification' ); ?></strong>
											<span class="dwpn-badge success"><?php echo __( 'success', 'disable-wp-notification' ); ?></span>
										</span>
									</label>

									<label class="dwpn-checkbox-card">
										<input type="checkbox" name="disable_notifications[blocked_types][]" value="info" <?php checked( in_array( 'info', $blocked_types ) ); ?>>
										<span class="dwpn-checkbox-label">
											<strong><?php echo __( 'Informational Updates', 'disable-wp-notification' ); ?></strong>
											<span class="dwpn-badge info"><?php echo __( 'info', 'disable-wp-notification' ); ?></span>
										</span>
									</label>

									<label class="dwpn-checkbox-card">
										<input type="checkbox" name="disable_notifications[blocked_types][]" value="warning" <?php checked( in_array( 'warning', $blocked_types ) ); ?>>
										<span class="dwpn-checkbox-label">
											<strong><?php echo __( 'Important Warnings', 'disable-wp-notification' ); ?></strong>
											<span class="dwpn-badge warning"><?php echo __( 'warning', 'disable-wp-notification' ); ?></span>
										</span>
									</label>

									<label class="dwpn-checkbox-card">
										<input type="checkbox" name="disable_notifications[blocked_types][]" value="error" <?php checked( in_array( 'error', $blocked_types ) ); ?>>
										<span class="dwpn-checkbox-label">
											<strong><?php echo __( 'Critical System Messages', 'disable-wp-notification' ); ?></strong>
											<span class="dwpn-badge error"><?php echo __( 'critical', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>
								<p class="dwpn-note"><em><?php echo __( 'Note: Disabling critical messages is not recommended, as it might delay your response to security or database warnings.', 'disable-wp-notification' ); ?></em></p>
							</div>
							*/ ?>

							<div class="dwpn-submit-wrapper">
								<?php submit_button( __( 'Save Settings', 'disable-wp-notification' ), 'primary', 'submit', false ); ?>
							</div>
						</div>

						<!-- Tab: Plugins -->
						<?php /*
						<div id="tab-plugins" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php echo __( 'Disable Notices by Specific Plugins', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php echo __( 'Choose which active plugins should have their dashboard alerts moved to the Notification Center.', 'disable-wp-notification' ); ?></p>
								
								<?php if ( empty( $plugin_list ) ) : ?>
									<p><em><?php echo __( 'No active plugins detected.', 'disable-wp-notification' ); ?></em></p>
								<?php else : ?>
									<div class="dwpn-plugins-grid">
										<?php foreach ( $plugin_list as $slug => $name ) : ?>
											<label class="dwpn-plugin-item">
												<input type="checkbox" name="disable_notifications[muted_plugins][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $muted_plugins ) ); ?>>
												<span class="dwpn-plugin-name"><?php echo esc_html( $name ); ?></span>
												<span class="dwpn-plugin-slug">(<?php echo esc_html( $slug ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>

							<div class="dwpn-submit-wrapper">
								<?php submit_button( __( 'Save Settings', 'disable-wp-notification' ), 'primary', 'submit', false ); ?>
							</div>
						</div>
						*/ ?>

						<!-- Tab: History -->
						<div id="tab-history" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php echo __( 'Disabled Alerts History', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php echo __( 'Review all the alerts currently held in your Notification Center.', 'disable-wp-notification' ); ?></p>

								<?php if ( empty( $blocked_history ) ) : ?>
									<div class="dwpn-empty-state">
										<p><?php echo __( 'No blocked notices currently cached.', 'disable-wp-notification' ); ?></p>
									</div>
								<?php else : ?>
									<div class="dwpn-history-list">
										<?php foreach ( $blocked_history as $hash => $notice ) : 
											$time_diff = human_time_diff( $notice['time'], time() ) . ' ' . __( 'ago', 'disable-wp-notification' );
											?>
											<div class="dwpn-history-item">
												<div class="dwpn-history-meta">
													<strong><?php echo esc_html( $notice['source_name'] ); ?></strong>
													<span><?php echo esc_html( $notice['type'] ); ?></span>
													<span><?php echo $time_diff; ?></span>
													<span class="dwpn-history-url"><?php echo esc_html( $notice['url'] ); ?></span>
												</div>
												<div class="dwpn-history-content">
													<?php 
													$clean_html = str_replace(
														array( 'class="notice ', 'class=\'notice ', 'class="notice"', 'class=\'notice\'' ),
														array( 'class="dwpn-rendered-notice ', 'class=\'dwpn-rendered-notice ', 'class="dwpn-rendered-notice"', 'class=\'dwpn-rendered-notice\'' ),
														$notice['html']
													);
													echo wp_kses_post( $clean_html ); 
													?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>

							<div class="dwpn-card mt-20">
								<h3><?php echo __( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php 
									$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
									if ( ! is_array( $dismissed_notices ) ) {
										$dismissed_notices = array();
									}
									$total_dismissed = count( $dismissed_notices );
									echo sprintf( __( 'You have cleared %d notifications from the history.', 'disable-wp-notification' ), $total_dismissed ); 
								?></p>
								<?php if ( $total_dismissed > 0 ) : ?>
									<form method="post" action="">
										<?php wp_nonce_field( 'dwpn_reset_dismissed_nonce', 'dwpn_reset_nonce' ); ?>
										<button type="submit" name="dwpn_reset_dismissed" class="button button-secondary">
											<?php echo __( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?>
										</button>
									</form>
								<?php else : ?>
									<button class="button" disabled><?php echo __( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<!-- Tab: Support & Feedback -->
						<div id="tab-coffee" class="dwpn-tab-content">
							<div class="dwpn-support-grid">
								<div class="dwpn-card dwpn-support-card">
									<h3><?php echo __( 'Love This Plugin?', 'disable-wp-notification' ); ?></h3>
									<p class="dwpn-card-desc"><?php echo __( 'Your feedback helps us grow! If Disable WP Notification makes your dashboard cleaner and your workflow smoother, please take a moment to leave a 5-star rating on WordPress.org.', 'disable-wp-notification' ); ?></p>
									<div class="dwpn-support-btn-wrapper">
										<a href="https://wordpress.org/support/plugin/disable-wp-notification/reviews/#new-post" target="_blank" class="button button-primary button-hero dwpn-btn-review">
											<?php echo __( 'Leave a 5-Star Review', 'disable-wp-notification' ); ?>
										</a>
									</div>
								</div>

								<div class="dwpn-card dwpn-support-card">
									<h3><?php echo __( 'Need Help or Have a Question?', 'disable-wp-notification' ); ?></h3>
									<p class="dwpn-card-desc"><?php echo __( 'If you encounter any issues or have questions about how to use the plugin, please create a ticket on our official WordPress.org support forum. Our team is happy to assist you.', 'disable-wp-notification' ); ?></p>
									<div class="dwpn-support-btn-wrapper">
										<a href="https://wordpress.org/support/plugin/disable-wp-notification/" target="_blank" class="button button-primary button-hero dwpn-btn-support">
											<?php echo __( 'Submit a Support Ticket', 'disable-wp-notification' ); ?>
										</a>
									</div>
								</div>
							</div>
						</div>
						</div>

					</form>
				</div>
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
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/disable-wp-notification-admin.css', array(), $this->version, 'all' );

		// Fallback CSS rules for absolute notice hiding when blocking is active
		$options = get_option( 'disable_notifications', array() );
		$user_role_setting = isset( $options['user_role'] ) ? $options['user_role'] : '';

		$is_settings_page = ( isset( $_GET['page'] ) && 'disable-wp-notification' === $_GET['page'] );

		if ( 'enable' === $user_role_setting && ! $is_settings_page ) {
			return;
		}

		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			$CurentUserRoles = (array) $user->roles;

			$should_hide_globally = false;
			if ( 'all' === $user_role_setting ) {
				$should_hide_globally = true;
			} elseif ( 'without-admin' === $user_role_setting && ! in_array( 'administrator', $CurentUserRoles ) ) {
				$should_hide_globally = true;
			} elseif ( $is_settings_page && 'enable' !== $user_role_setting ) {
				$should_hide_globally = true;
			}

			if ( $should_hide_globally ) {
				$is_action_request = (
					$_SERVER['REQUEST_METHOD'] === 'POST' ||
					isset( $_GET['settings-updated'] ) ||
					isset( $_GET['message'] ) ||
					( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'success', 'updated', 'edit' ) ) )
				);

				if ( ! $is_action_request ) {
					// Hide ALL notices on standard page loads
					?>
					<style type="text/css">
					body.wp-admin:not(.theme-editor-php) .notice,
					body.wp-admin:not(.theme-editor-php) .update-nag,
					body.wp-admin:not(.theme-editor-php) .updated,
					body.wp-admin:not(.theme-editor-php) .e-conversion-banner--ready,
					body.wp-admin:not(.theme-editor-php) #adminmenu .awaiting-mod, 
					body.wp-admin:not(.theme-editor-php) #adminmenu .update-plugins,
					body.wp-admin:not(.theme-editor-php) #message.woocommerce-message,
					body.wp-admin:not(.theme-editor-php) .plugin-update.colspanchange,
					body.wp-admin:not(.theme-editor-php) .fs-notice,
					body.wp-admin:not(.theme-editor-php) .elementor-message,
					body.wp-admin:not(.theme-editor-php) [class*="notice"],
					body.wp-admin:not(.theme-editor-php) [class*="message"]
					{ display: none !important; }
					
					/* Always override to display them inside the Notification Center drawer & history content */
					body.wp-admin #dwpn-drawer .notice,
					body.wp-admin #dwpn-drawer .updated,
					body.wp-admin #dwpn-drawer .e-conversion-banner--ready,
					body.wp-admin #dwpn-drawer .update-nag,
					body.wp-admin #dwpn-drawer #message.woocommerce-message,
					body.wp-admin #dwpn-drawer [class*="notice"],
					body.wp-admin #dwpn-drawer [class*="message"],
					body.wp-admin .dwpn-history-content .notice,
					body.wp-admin .dwpn-history-content .updated,
					body.wp-admin .dwpn-history-content .e-conversion-banner--ready,
					body.wp-admin .dwpn-history-content .update-nag,
					body.wp-admin .dwpn-history-content #message.woocommerce-message,
					body.wp-admin .dwpn-history-content [class*="notice"],
					body.wp-admin .dwpn-history-content [class*="message"]
					{
						display: block !important;
					}
					</style>
					<?php
				} else {
					// On action/update requests, only hide non-success/non-error system notices
					?>
					<style type="text/css">
					body.wp-admin:not(.theme-editor-php) .notice:not(.notice-success):not(.updated):not(.notice-error):not(.error),
					body.wp-admin:not(.theme-editor-php) .updated:not(.notice-success):not(.notice-error):not(.error),
					body.wp-admin:not(.theme-editor-php) .e-conversion-banner--ready,
					body.wp-admin:not(.theme-editor-php) .update-nag,
					body.wp-admin:not(.theme-editor-php) #message.woocommerce-message:not(.notice-success):not(.updated):not(.notice-error):not(.error),
					body.wp-admin:not(.theme-editor-php) .plugin-update.colspanchange,
					body.wp-admin:not(.theme-editor-php) .fs-notice,
					body.wp-admin:not(.theme-editor-php) .elementor-message:not(.notice-success):not(.updated):not(.notice-error):not(.error),
					body.wp-admin:not(.theme-editor-php) [class*="notice"]:not(.notice-success):not(.updated):not(.notice-error):not(.error),
					body.wp-admin:not(.theme-editor-php) [class*="message"]:not(.notice-success):not(.updated):not(.notice-error):not(.error)
					{ display: none !important; }
					
					/* Always override to display them inside the Notification Center drawer & history content */
					body.wp-admin #dwpn-drawer .notice,
					body.wp-admin #dwpn-drawer .updated,
					body.wp-admin #dwpn-drawer .e-conversion-banner--ready,
					body.wp-admin #dwpn-drawer .update-nag,
					body.wp-admin #dwpn-drawer #message.woocommerce-message,
					body.wp-admin #dwpn-drawer [class*="notice"],
					body.wp-admin #dwpn-drawer [class*="message"],
					body.wp-admin .dwpn-history-content .notice,
					body.wp-admin .dwpn-history-content .updated,
					body.wp-admin .dwpn-history-content .e-conversion-banner--ready,
					body.wp-admin .dwpn-history-content .update-nag,
					body.wp-admin .dwpn-history-content #message.woocommerce-message,
					body.wp-admin .dwpn-history-content [class*="notice"],
					body.wp-admin .dwpn-history-content [class*="message"]
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
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/disable-wp-notification-admin.js', array( 'jquery' ), $this->version, false );
		wp_localize_script( $this->plugin_name, 'dwpn_ajax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'dwpn_ajax_nonce' )
		) );
	}
	
	/**
	 * Add option page settings link.
	 *
	 * @since    1.0.0
	 * @param    array     $links
	 * @return   array
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="options-general.php?page=disable-wp-notification">' . __( 'Settings', 'disable-wp-notification' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}