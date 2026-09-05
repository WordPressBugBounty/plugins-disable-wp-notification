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
		if ( current_user_can( 'manage_options' ) ) {
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

		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}
		$active_plugins = array_unique( $active_plugins );

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
						$full_path = WP_PLUGIN_DIR . '/' . $plugin_path;
						if ( file_exists( $full_path ) ) {
							$plugin_data = get_plugin_data( $full_path, false, false );
							$name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $target_slug ) );
							return array( 'slug' => $target_slug, 'name' => $name );
						}
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
				$full_path = WP_PLUGIN_DIR . '/' . $plugin_path;
				if ( file_exists( $full_path ) ) {
					$plugin_data = get_plugin_data( $full_path, false, false );
					$name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $slug ) );
					return array( 'slug' => $slug, 'name' => $name );
				}
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
		if ( is_array( $dismissed_notices ) && in_array( $hash, $dismissed_notices, true ) ) {
			return true;
		}

		// Only exclusion is when user updates the page (e.g. settings saved, post updated, etc.)
		$is_action_request = (
			( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) ||
			isset( $_GET['settings-updated'] ) ||
			isset( $_GET['message'] ) ||
			( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'success', 'updated', 'edit' ), true ) )
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
			if ( in_array( $source['slug'], $muted_plugins, true ) ) {
				return true;
			}
		}

		// 3. Check level rules
		$blocked_types = isset( $options['blocked_types'] ) ? $options['blocked_types'] : array();
		if ( in_array( $type, $blocked_types, true ) ) {
			return true;
		}

		// 4. Check global fallback settings
		$user_role_setting = isset( $options['user_role'] ) ? $options['user_role'] : '';
		$should_block = false;
		if ( 'all' === $user_role_setting ) {
			$should_block = true;
		}
		if ( 'without-admin' === $user_role_setting ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				$should_block = true;
			}
		}

		return apply_filters( 'dwpn_should_block_notice', $should_block, $notice_html, $source, $type, $is_update );
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
			'source_slug' => isset( $source['slug'] ) ? sanitize_text_field( $source['slug'] ) : 'other',
			'source_name' => isset( $source['name'] ) ? sanitize_text_field( $source['name'] ) : 'Other / System',
			'type'        => sanitize_text_field( $type ),
			'time'        => time(),
			'url'         => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
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
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized user.', 'disable-wp-notification' ) ) );
		}

		$hash = isset( $_POST['hash'] ) ? sanitize_key( $_POST['hash'] ) : '';
		if ( empty( $hash ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid notice ID.', 'disable-wp-notification' ) ) );
		}

		$current_user_id = get_current_user_id();
		$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
		if ( ! is_array( $dismissed_notices ) ) {
			$dismissed_notices = array();
		}

		if ( ! in_array( $hash, $dismissed_notices, true ) ) {
			$dismissed_notices[] = $hash;
			update_user_meta( $current_user_id, 'dwpn_dismissed_notices', $dismissed_notices );
		}

		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$active_notices = get_transient( $transient_key );
		if ( is_array( $active_notices ) && isset( $active_notices[$hash] ) ) {
			unset( $active_notices[$hash] );
			set_transient( $transient_key, $active_notices, 24 * HOUR_IN_SECONDS );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'Notice dismissed.', 'disable-wp-notification' ) ) );
	}

	/**
	 * AJAX handler to clear all notices.
	 *
	 * @since    4.0
	 */
	public function ajax_clear_all_notices() {
		check_ajax_referer( 'dwpn_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized user.', 'disable-wp-notification' ) ) );
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
				if ( ! in_array( $hash, $dismissed_notices, true ) ) {
					$dismissed_notices[] = $hash;
				}
			}
			update_user_meta( $current_user_id, 'dwpn_dismissed_notices', $dismissed_notices );
		}

		delete_transient( $transient_key );
		wp_send_json_success( array( 'message' => esc_html__( 'All notices cleared.', 'disable-wp-notification' ) ) );
	}

	/**
	 * Add Bell Icon with Badge Count in WP Admin Bar.
	 *
	 * @since    4.0
	 */
	public function add_admin_bar_bell( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$blocked_notices = get_transient( $transient_key );
		$count = is_array( $blocked_notices ) ? count( $blocked_notices ) : 0;

		$badge = '';
		$class = 'dwpn-bell-trigger';
		if ( $count > 0 ) {
			$badge = '<span class="dwpn-bell-badge">' . (int) $count . '</span>';
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
		if ( ! empty( $this->new_blocked_notices ) ) {
			foreach ( $this->new_blocked_notices as $hash => $notice ) {
				$blocked_notices[ $hash ] = $notice;
			}
		}

		uasort( $blocked_notices, function( $a, $b ) {
			$time_a = isset( $a['time'] ) && is_numeric( $a['time'] ) ? (int) $a['time'] : 0;
			$time_b = isset( $b['time'] ) && is_numeric( $b['time'] ) ? (int) $b['time'] : 0;
			return $time_b <=> $time_a;
		} );

		$nonce = wp_create_nonce( 'dwpn_ajax_nonce' );
		?>
		<div id="dwpn-drawer-overlay" class="dwpn-drawer-overlay"></div>
		<div id="dwpn-drawer" class="dwpn-drawer" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="dwpn-drawer-header">
				<h3>
					<?php esc_html_e( 'Notification Center', 'disable-wp-notification' ); ?>
					<span class="dwpn-count-indicator"><?php echo count( $blocked_notices ); ?></span>
				</h3>
				<div class="dwpn-drawer-actions">
					<?php if ( ! empty( $blocked_notices ) ) : ?>
						<button id="dwpn-clear-all" class="dwpn-btn-clear"><?php esc_html_e( 'Clear All', 'disable-wp-notification' ); ?></button>
					<?php endif; ?>
					<button id="dwpn-drawer-close" class="dwpn-btn-close">&times;</button>
				</div>
			</div>
			
			<div class="dwpn-drawer-body">
				<?php if ( empty( $blocked_notices ) ) : ?>
					<div class="dwpn-no-notifications">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="dwpn-empty-icon"><circle cx="12" cy="12" r="10"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>
						<p><?php esc_html_e( 'All clear! No blocked notifications.', 'disable-wp-notification' ); ?></p>
					</div>
				<?php else : ?>
					<div class="dwpn-notice-list">
						<?php foreach ( $blocked_notices as $hash => $notice ) : 
							$notice_time = isset( $notice['time'] ) && is_numeric( $notice['time'] ) ? (int) $notice['time'] : time();
							/* translators: %s: Human-readable time difference */
							$time_diff = sprintf( esc_html__( '%s ago', 'disable-wp-notification' ), human_time_diff( $notice_time, time() ) );
							$source_name = isset( $notice['source_name'] ) ? esc_html( $notice['source_name'] ) : esc_html__( 'System', 'disable-wp-notification' );
							$notice_type = isset( $notice['type'] ) ? esc_attr( $notice['type'] ) : 'info';
							$type_class = 'dwpn-type-' . $notice_type;
							?>
							<div class="dwpn-notice-item <?php echo $type_class; ?>" data-hash="<?php echo esc_attr( $hash ); ?>">
								<div class="dwpn-notice-meta">
									<span class="dwpn-notice-source"><?php echo $source_name; ?></span>
									<span class="dwpn-notice-time"><?php echo esc_html( $time_diff ); ?></span>
								</div>
								<div class="dwpn-notice-content">
									<?php 
									$notice_html_content = isset( $notice['html'] ) ? $notice['html'] : '';
									$clean_html = preg_replace( '/\b(notice|updated|error|update-nag)\b/', 'dwpn-rendered-notice', $notice_html_content );
									echo wp_kses_post( $clean_html ); 
									?>
								</div>
								<div class="dwpn-notice-footer">
									<button class="dwpn-action-dismiss" data-hash="<?php echo esc_attr( $hash ); ?>">
										<?php esc_html_e( 'Dismiss', 'disable-wp-notification' ); ?>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="dwpn-drawer-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=disable-wp-notification' ) ); ?>" class="dwpn-btn-settings">
					<?php esc_html_e( 'Go to Settings', 'disable-wp-notification' ); ?>
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'disable-wp-notification' ) );
		}

		$settings_message = '';

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
			$savedOptions = apply_filters( 'dwpn_pre_save_settings', $savedOptions, $post_data );
			
			update_option( 'disable_notifications', $savedOptions );
			$settings_message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'disable-wp-notification' ) . '</p></div>';
		}

		if ( isset( $_POST['dwpn_reset_dismissed'] ) ) {
			check_admin_referer( 'dwpn_save_settings_nonce', 'dwpn_nonce' );
			$current_user_id = get_current_user_id();
			delete_user_meta( $current_user_id, 'dwpn_dismissed_notices' );
			$settings_message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cleared alerts history has been restored successfully. All alerts will show again when triggered.', 'disable-wp-notification' ) . '</p></div>';
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
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}
		$active_plugins = array_unique( $active_plugins );

		$plugin_list = array();
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $active_plugins as $plugin_path ) {
			$slug = dirname( $plugin_path );
			if ( '.' === $slug || empty( $slug ) || 'disable-wp-notification' === $slug ) {
				continue;
			}
			$full_path = WP_PLUGIN_DIR . '/' . $plugin_path;
			if ( file_exists( $full_path ) ) {
				$plugin_data = get_plugin_data( $full_path, false, false );
				$plugin_list[$slug] = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : ucwords( str_replace( '-', ' ', $slug ) );
			}
		}
		asort( $plugin_list );

		// Fetch blocked notices history for stats
		$current_user_id = get_current_user_id();
		$transient_key = 'dwpn_blocked_' . $current_user_id;
		$blocked_history = get_transient( $transient_key );
		if ( ! is_array( $blocked_history ) ) {
			$blocked_history = array();
		}
		if ( ! empty( $this->new_blocked_notices ) ) {
			foreach ( $this->new_blocked_notices as $hash => $notice ) {
				$blocked_history[ $hash ] = $notice;
			}
		}
		uasort( $blocked_history, function( $a, $b ) {
			$time_a = isset( $a['time'] ) && is_numeric( $a['time'] ) ? (int) $a['time'] : 0;
			$time_b = isset( $b['time'] ) && is_numeric( $b['time'] ) ? (int) $b['time'] : 0;
			return $time_b <=> $time_a;
		} );
		$total_blocked_now = count( $blocked_history );
		?>
		<div id="dwpn-settings-page" class="wrap">
			<?php if ( ! empty( $settings_message ) ) : ?>
				<?php echo wp_kses_post( $settings_message ); ?>
			<?php endif; ?>
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
					<span class="dwpn-brand-text"><?php esc_html_e( 'Disable WP Notification', 'disable-wp-notification' ); ?> <span class="dwpn-version-badge">v4.3</span></span>
				</div>
				<p class="dwpn-tagline"><?php esc_html_e( 'Keep your WordPress dashboard clean and focused. Automatically disable cluttering administrative alerts and collect them into a central, easy-to-read Notification Center.', 'disable-wp-notification' ); ?></p>
			</div>

			<div class="dwpn-settings-container">
				<div class="dwpn-sidebar">
					<ul class="dwpn-tabs">
						<li class="dwpn-tab active" data-tab="tab-dashboard">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><rect width="7" height="9" x="3" y="3" rx="1"></rect><rect width="7" height="5" x="14" y="3" rx="1"></rect><rect width="7" height="9" x="14" y="12" rx="1"></rect><rect width="7" height="5" x="3" y="16" rx="1"></rect></svg>
							<?php esc_html_e( 'Dashboard', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-general">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
							<?php esc_html_e( 'General Settings', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-filters">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
							<?php esc_html_e( 'Granular Filters', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-history">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path></svg>
							<?php esc_html_e( 'Blocked History', 'disable-wp-notification' ); ?>
						</li>
						<li class="dwpn-tab" data-tab="tab-coffee">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dwpn-tab-icon"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="4"></circle><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"></line><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"></line><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"></line><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"></line></svg>
							<?php esc_html_e( 'Support & Feedback', 'disable-wp-notification' ); ?>
						</li>
						<?php do_action( 'dwpn_settings_tabs' ); ?>
					</ul>
				</div>

				<div class="dwpn-main">
					<form method="post" action="">
						<?php wp_nonce_field( 'dwpn_save_settings_nonce', 'dwpn_nonce' ); ?>

						<!-- Tab: Dashboard -->
						<div id="tab-dashboard" class="dwpn-tab-content active">
							<div class="dwpn-dashboard-grid">
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php esc_html_e( 'Active Filter Rules', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val">
										<?php 
										$rules_count = 0;
										if ( 'enable' !== $user_role ) $rules_count++;
										if ( $block_core ) $rules_count++;
										if ( $block_plugins ) $rules_count++;
										if ( $block_themes ) $rules_count++;
										$rules_count += count( $blocked_types );
										$rules_count += count( $muted_plugins );
										echo (int) $rules_count;
										?>
									</div>
									<div class="dwpn-stat-desc"><?php esc_html_e( 'Total custom rules active to disable your dashboard alerts.', 'disable-wp-notification' ); ?></div>
								</div>
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php esc_html_e( 'Inbox Notifications', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val"><?php echo (int) $total_blocked_now; ?></div>
									<div class="dwpn-stat-desc"><?php esc_html_e( 'Unread alerts stored in your Notification Center.', 'disable-wp-notification' ); ?></div>
								</div>
								<div class="dwpn-stat-card">
									<div class="dwpn-stat-title"><?php esc_html_e( 'Global Status', 'disable-wp-notification' ); ?></div>
									<div class="dwpn-stat-val">
										<?php if ( 'enable' === $user_role ) : ?>
											<span class="dwpn-status-inactive"><?php esc_html_e( 'Disabled', 'disable-wp-notification' ); ?></span>
										<?php else : ?>
											<span class="dwpn-status-active"><?php esc_html_e( 'Active', 'disable-wp-notification' ); ?></span>
										<?php endif; ?>
									</div>
									<div class="dwpn-stat-desc"><?php esc_html_e( 'Active state of notification filtering on your dashboard.', 'disable-wp-notification' ); ?></div>
								</div>
							</div>

							<div class="dwpn-card mt-20">
								<h3><?php esc_html_e( 'Welcome to Disable WP Notification!', 'disable-wp-notification' ); ?></h3>
								<p><?php esc_html_e( 'This plugin automatically collects and disables administrative alerts, keeping them out of your main workspace. You can access all your disabled updates at any time by clicking the bell icon in the top navigation bar.', 'disable-wp-notification' ); ?></p>
								<p><strong><?php esc_html_e( 'Quick Checklist to get started:', 'disable-wp-notification' ); ?></strong></p>
								<ul class="dwpn-checklist">
									<li><?php echo wp_kses_post( __( 'Configure visibility under <strong>General Settings</strong> to choose who should have notifications disabled.', 'disable-wp-notification' ) ); ?></li>
									<li><?php echo wp_kses_post( __( 'Customize filters under <strong>Granular Filters</strong> to manage system updates (WordPress core, plugins, or themes).', 'disable-wp-notification' ) ); ?></li>
								</ul>
							</div>
						</div>

						<!-- Tab: General -->
						<div id="tab-general" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php esc_html_e( 'Notification Settings', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php esc_html_e( 'Choose who sees administrative notifications and how they are displayed.', 'disable-wp-notification' ); ?></p>
								
								<div class="dwpn-form-group">
									<label class="dwpn-label"><?php esc_html_e( 'Choose Notification Mode', 'disable-wp-notification' ); ?></label>
									
									<div class="dwpn-radio-option">
										<input type="radio" id="role-enable" name="disable_notifications[user_role]" value="enable" <?php checked( $user_role, 'enable' ); ?>>
										<label for="role-enable">
											<strong><?php esc_html_e( 'Show All Notifications', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Show all alerts normally on the screen.', 'disable-wp-notification' ); ?></span>
										</label>
									</div>

									<div class="dwpn-radio-option">
										<input type="radio" id="role-all" name="disable_notifications[user_role]" value="all" <?php checked( $user_role, 'all' ); ?>>
										<label for="role-all">
											<strong><?php esc_html_e( 'Disable Notifications for All Users', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Disable all administrative alerts and collect them into the Notification Center for everyone, including administrators.', 'disable-wp-notification' ); ?></span>
										</label>
									</div>

									<div class="dwpn-radio-option">
										<input type="radio" id="role-without-admin" name="disable_notifications[user_role]" value="without-admin" <?php checked( $user_role, 'without-admin' ); ?>>
										<label for="role-without-admin">
											<strong><?php esc_html_e( 'Disable for All Users Except Administrators (Recommended)', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Keep notices visible to administrators while disabling notifications for all other user roles (Editors, Authors, etc.).', 'disable-wp-notification' ); ?></span>
										</label>
									</div>
								</div>

								<div class="dwpn-divider"></div>

								<div class="dwpn-form-group">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[hide_bell]" value="1" <?php checked( $hide_bell, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php esc_html_e( 'Hide Navigation Bar Bell Icon', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Hide the top navigation bar bell icon and drawer for a completely clean view.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>
							</div>
							
							<div class="dwpn-submit-wrapper">
								<?php submit_button( esc_html__( 'Save Settings', 'disable-wp-notification' ), 'primary', 'submit', false ); ?>
							</div>
						</div>

						<!-- Tab: Filters -->
						<div id="tab-filters" class="dwpn-tab-content">
							<div class="dwpn-card">
								<h3><?php esc_html_e( 'System Reminders & Updates', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php esc_html_e( 'Disable core updates and plugin/theme reminders.', 'disable-wp-notification' ); ?></p>

								<div class="dwpn-form-group">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_core]" value="1" <?php checked( $block_core, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php esc_html_e( 'Disable WordPress Core Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Keep WordPress core version update alerts disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>

								<div class="dwpn-form-group mt-15">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_plugins]" value="1" <?php checked( $block_plugins, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php esc_html_e( 'Disable Plugin Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Keep plugin update reminders disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>

								<div class="dwpn-form-group mt-15">
									<label class="dwpn-toggle-label">
										<input type="checkbox" name="disable_notifications[block_themes]" value="1" <?php checked( $block_themes, 1 ); ?>>
										<span class="dwpn-toggle-slider"></span>
										<span class="dwpn-toggle-text">
											<strong><?php esc_html_e( 'Disable Theme Update Reminders', 'disable-wp-notification' ); ?></strong>
											<span><?php esc_html_e( 'Keep theme update alerts disabled and collected in the Notification Center.', 'disable-wp-notification' ); ?></span>
										</span>
									</label>
								</div>
							</div>

							<div class="dwpn-submit-wrapper">
								<?php submit_button( esc_html__( 'Save Settings', 'disable-wp-notification' ), 'primary', 'submit', false ); ?>
							</div>
						</div>

						<!-- Tab: History -->
						<div id="tab-history" class="dwpn-tab-content">
							<div class="dwpn-card">
								<div class="dwpn-card-header-with-actions">
									<div>
										<h3><?php esc_html_e( 'Disabled Alerts History', 'disable-wp-notification' ); ?></h3>
										<p class="dwpn-card-desc"><?php esc_html_e( 'Review all the alerts currently held in your Notification Center.', 'disable-wp-notification' ); ?></p>
									</div>
									<?php if ( ! empty( $blocked_history ) ) : ?>
										<button type="button" id="dwpn-clear-all-history" class="dwpn-btn-clear-all-history button button-secondary">
											<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
											<?php esc_html_e( 'Clear All Alerts', 'disable-wp-notification' ); ?>
										</button>
									<?php endif; ?>
								</div>

								<?php if ( empty( $blocked_history ) ) : ?>
									<div class="dwpn-empty-state">
										<p><?php esc_html_e( 'No blocked notices currently cached.', 'disable-wp-notification' ); ?></p>
									</div>
								<?php else : ?>
									<div class="dwpn-history-list">
										<?php foreach ( $blocked_history as $hash => $notice ) : 
											$notice_time = isset( $notice['time'] ) && is_numeric( $notice['time'] ) ? (int) $notice['time'] : time();
											/* translators: %s: Human-readable time difference */
											$time_diff = sprintf( esc_html__( '%s ago', 'disable-wp-notification' ), human_time_diff( $notice_time, time() ) );
											$source_name = isset( $notice['source_name'] ) ? esc_html( $notice['source_name'] ) : esc_html__( 'System', 'disable-wp-notification' );
											$notice_type = isset( $notice['type'] ) ? esc_html( $notice['type'] ) : 'info';
											$notice_url = isset( $notice['url'] ) ? esc_html( $notice['url'] ) : '';
											?>
											<div class="dwpn-history-item" data-hash="<?php echo esc_attr( $hash ); ?>">
												<div class="dwpn-history-meta">
													<div class="dwpn-history-meta-left">
														<strong><?php echo $source_name; ?></strong>
														<span class="dwpn-badge <?php echo esc_attr( $notice_type ); ?>"><?php echo esc_html( $notice_type ); ?></span>
														<span><?php echo esc_html( $time_diff ); ?></span>
														<span class="dwpn-history-url"><?php echo $notice_url; ?></span>
													</div>
													<div class="dwpn-history-meta-right">
														<button type="button" class="dwpn-action-dismiss dwpn-btn-history-dismiss" data-hash="<?php echo esc_attr( $hash ); ?>" title="<?php esc_attr_e( 'Dismiss and clear this alert', 'disable-wp-notification' ); ?>">
															<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
															<?php esc_html_e( 'Dismiss Alert', 'disable-wp-notification' ); ?>
														</button>
													</div>
												</div>
												<div class="dwpn-history-content">
													<?php 
													$notice_html_content = isset( $notice['html'] ) ? $notice['html'] : '';
													$clean_html = preg_replace( '/\b(notice|updated|error|update-nag)\b/', 'dwpn-rendered-notice', $notice_html_content );
													echo wp_kses_post( $clean_html ); 
													?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>

							<div class="dwpn-card mt-20">
								<h3><?php esc_html_e( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?></h3>
								<p class="dwpn-card-desc"><?php 
									$dismissed_notices = get_user_meta( $current_user_id, 'dwpn_dismissed_notices', true );
									if ( ! is_array( $dismissed_notices ) ) {
										$dismissed_notices = array();
									}
									$total_dismissed = count( $dismissed_notices );
									echo esc_html( sprintf( 
										/* translators: %d: Number of notifications cleared */
										_n( 'You have cleared %d notification from the history.', 'You have cleared %d notifications from the history.', $total_dismissed, 'disable-wp-notification' ), 
										$total_dismissed 
									) ); 
								?></p>
								<?php if ( $total_dismissed > 0 ) : ?>
									<button type="submit" name="dwpn_reset_dismissed" value="1" class="button button-secondary">
										<?php esc_html_e( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?>
									</button>
								<?php else : ?>
									<button class="button" disabled><?php esc_html_e( 'Restore Cleared Alerts', 'disable-wp-notification' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<!-- Tab: Support & Feedback -->
						<div id="tab-coffee" class="dwpn-tab-content">
							<div class="dwpn-support-grid">
								<div class="dwpn-card dwpn-support-card">
									<h3><?php esc_html_e( 'Love This Plugin?', 'disable-wp-notification' ); ?></h3>
									<p class="dwpn-card-desc"><?php esc_html_e( 'Your feedback helps us grow! If Disable WP Notification makes your dashboard cleaner and your workflow smoother, please take a moment to leave a 5-star rating on WordPress.org.', 'disable-wp-notification' ); ?></p>
									<div class="dwpn-support-btn-wrapper">
										<a href="https://wordpress.org/support/plugin/disable-wp-notification/reviews/#new-post" target="_blank" class="button button-primary button-hero dwpn-btn-review">
											<?php esc_html_e( 'Leave a 5-Star Review', 'disable-wp-notification' ); ?>
										</a>
									</div>
								</div>

								<div class="dwpn-card dwpn-support-card">
									<h3><?php esc_html_e( 'Need Help or Have a Question?', 'disable-wp-notification' ); ?></h3>
									<p class="dwpn-card-desc"><?php esc_html_e( 'If you encounter any issues or have questions about how to use the plugin, please create a ticket on our official WordPress.org support forum. Our team is happy to assist you.', 'disable-wp-notification' ); ?></p>
									<div class="dwpn-support-btn-wrapper">
										<a href="https://wordpress.org/support/plugin/disable-wp-notification/" target="_blank" class="button button-primary button-hero dwpn-btn-support">
											<?php esc_html_e( 'Submit a Support Ticket', 'disable-wp-notification' ); ?>
										</a>
									</div>
								</div>
							</div>
						</div>
						
						<?php do_action( 'dwpn_settings_tab_contents', $options ); ?>

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

		$is_admin_user = current_user_can( 'manage_options' );

		$should_hide_globally = false;
		if ( 'all' === $user_role_setting ) {
			$should_hide_globally = true;
		} elseif ( 'without-admin' === $user_role_setting && ! $is_admin_user ) {
			$should_hide_globally = true;
		} elseif ( $is_settings_page && 'enable' !== $user_role_setting ) {
			$should_hide_globally = true;
		}

		if ( $should_hide_globally ) {
			$is_action_request = (
				( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) ||
				isset( $_GET['settings-updated'] ) ||
				isset( $_GET['message'] ) ||
				( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'success', 'updated', 'edit' ), true ) )
			);

			if ( ! $is_action_request ) {
				// Hide ALL notices on standard page loads (preserving Drawer, History, Gutenberg snackbars, and site health)
				?>
				<style type="text/css">
				body.wp-admin:not(.theme-editor-php) .notice:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.components-snackbar):not(.components-notice):not(.site-health-progress),
				body.wp-admin:not(.theme-editor-php) .update-nag:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .updated:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.components-snackbar):not(.components-notice),
				body.wp-admin:not(.theme-editor-php) .e-conversion-banner--ready:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) #adminmenu .awaiting-mod, 
				body.wp-admin:not(.theme-editor-php) #adminmenu .update-plugins,
				body.wp-admin:not(.theme-editor-php) #message.woocommerce-message:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .plugin-update.colspanchange:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .fs-notice:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .elementor-message:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) [class*="notice"]:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.components-snackbar):not(.components-notice):not(.site-health-progress):not(.uploader-inline-content):not(.no-upload-message):not(.has-upload-message):not(.upload-message):not(.media-message),
				body.wp-admin:not(.theme-editor-php) [class*="message"]:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.components-snackbar):not(.components-notice):not(.site-health-progress):not(.uploader-inline-content):not(.no-upload-message):not(.has-upload-message):not(.upload-message):not(.media-message)
				{ display: none !important; }
				</style>
				<?php
			} else {
				// On action/update requests, only hide non-success/non-error system notices
				?>
				<style type="text/css">
				body.wp-admin:not(.theme-editor-php) .notice:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.updated):not(.notice-error):not(.error):not(.components-snackbar):not(.components-notice):not(.site-health-progress),
				body.wp-admin:not(.theme-editor-php) .updated:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.notice-error):not(.error):not(.components-snackbar):not(.components-notice),
				body.wp-admin:not(.theme-editor-php) .e-conversion-banner--ready:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .update-nag:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) #message.woocommerce-message:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.updated):not(.notice-error):not(.error),
				body.wp-admin:not(.theme-editor-php) .plugin-update.colspanchange:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .fs-notice:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *),
				body.wp-admin:not(.theme-editor-php) .elementor-message:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.updated):not(.notice-error):not(.error),
				body.wp-admin:not(.theme-editor-php) [class*="notice"]:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.updated):not(.notice-error):not(.error):not(.components-snackbar):not(.components-notice):not(.site-health-progress):not(.uploader-inline-content):not(.no-upload-message):not(.has-upload-message):not(.upload-message):not(.media-message),
				body.wp-admin:not(.theme-editor-php) [class*="message"]:not(#dwpn-drawer *):not(.dwpn-history-content *):not(.dwpn-notice-content *):not(.notice-success):not(.updated):not(.notice-error):not(.error):not(.components-snackbar):not(.components-notice):not(.site-health-progress):not(.uploader-inline-content):not(.no-upload-message):not(.has-upload-message):not(.upload-message):not(.media-message)
				{ display: none !important; }
				</style>
				<?php
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
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=disable-wp-notification' ) ) . '">' . esc_html__( 'Settings', 'disable-wp-notification' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}