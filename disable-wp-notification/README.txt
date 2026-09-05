=== Disable WP Notification ===
Contributors: sourabh.asct
Plugin Name: Disable WP Notification
Plugin URI: https://sourabhagrawal.com/disable-wp-notification
Donate link: https://wordpress.org/support/plugin/disable-wp-notification/reviews/#new-post
Tags: disable notifications, block notices, clean dashboard, disable plugin updates, remove admin notices
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Clean your WordPress dashboard. Hide annoying admin notices, theme ads, and update alerts for your clients worldwide. Light, fast, and multi-site ready.

== Description ==

Is your WordPress admin screen cluttered with third-party plugin advertisements, core updates, and banner notices? 

**Disable WP Notification** is the ultimate dashboard cleaner for freelance developers, digital agencies, and website owners. It intercepts intrusive administrative notices and collects them into a quiet, professional **Notification Center (Bell Icon)** and **Disabled Alerts History**.

Keep your client dashboards 100% clean, fast, and distraction-free without missing important system updates.

### Why Digital Agencies & Website Owners Choose Us:
* **Zero Screen Clutter**: Block aggressive plugin sales banners, upsells, and review requests instantly.
* **Smart Notification Center**: Stores blocked notices safely in a top admin bar sliding drawer without breaking core site functionality.
* **Disabled Alerts History**: Review all captured alerts anytime in the settings panel with individual **Dismiss** and **Clear All** options.
* **Role-Based Visibility**: Disable notices for non-admin roles (Editors, Authors, Shop Managers) while keeping them visible for Administrators.
* **System Update Filters**: Selectively disable WordPress Core, Plugin, or Theme update reminders.
* **Block Editor & Site Health Protection**: Preserves Gutenberg snackbars and WordPress Site Health indicators.
* **Lightweight & Fast**: Built with efficient server-side notice buffering and transient caching for zero screen flicker.
* **Automatic Migration**: Seamlessly imports and maps settings from older versions.

### Three Simple Control Modes:
1. **Show All Notifications**: Display all administrative alerts normally.
2. **Disable for All Users**: Collect all notices into the Notification Center for everyone, including administrators.
3. **Disable for All Users Except Administrators (Recommended)**: Keep notices visible to administrators while cleaning the dashboard for all other user roles.

---

== Installation ==

### Easy Dashboard Installation
1. Go to your WordPress Dashboard > **Plugins** > **Add New**.
2. Search for **Disable WP Notification**.
3. Click **Install Now** and then **Activate**.
4. Navigate to **Settings** > **Disable Notices** (or **Disable Notices** in the main sidebar) to configure your preferences.

### Manual Installation
1. Download the plugin zip file from WordPress.org.
2. Go to **Plugins** > **Add New** > **Upload Plugin**.
3. Upload the zip file and click **Activate**.
4. Go to **Disable Notices** in your sidebar to manage options.

---

== Frequently Asked Questions ==

= How does the Notification Center work? =
When a plugin or WordPress core triggers an administrative notice, our plugin intercepts the output buffer before it renders on the page. The notice is safely stored in a temporary list. You can view them at any time by clicking the bell icon in your top navigation bar or visiting the Blocked History tab.

= Can I dismiss individual alerts? =
Yes! You can dismiss individual alerts directly from the Notification Center drawer or from the **Blocked History** tab by clicking the "Dismiss Alert" button. You can also clear all alerts in one click.

= What happens if I want to restore cleared alerts? =
Under the **Blocked History** tab, you can use the **Restore Cleared Alerts** button to reset your dismissed alerts list so that active notices will show again when triggered.

= Will I miss critical updates if I turn this on? =
No. You can easily view all blocked updates inside the Notification Center drawer or in the **Blocked History** tab. You can also update plugins and themes normally from the **Dashboard > Updates** screen.

= Does this plugin affect my website performance? =
Not at all. It is highly optimized, runs completely on the server side using transient memory caching, and eliminates heavy banner layouts, making your WordPress dashboard faster and smoother.

= Is this plugin compatible with the Block Editor (Gutenberg)? =
Yes. The plugin explicitly protects Gutenberg editor snackbars, block notices, media library uploaders, and Site Health indicators so all publishing workflows remain fully functional.

= Is this plugin completely free? =
Yes, it is 100% free! If you love the plugin, please take a moment to leave us a 5-star review on WordPress.org to support ongoing development.

= How do I request support? =
You can open a support ticket directly from the **Support & Feedback** tab inside the plugin settings page.

---

== Screenshots ==

1. **Dashboard Overview**: Overview showing active rules count, inbox alerts count, and status checklist.
2. **General Settings**: Intuitive toggle switches for role-based blocking and core/plugin/theme update reminders.
3. **Granular Filters**: Granular filters for plugin, theme, core, and custom notifications.
4. **Disabled Alerts History**: Full history log of all captured alerts with source metadata, individual dismiss buttons, and bulk clear options.
5. **Notification Center Drawer**: Slide-out tray displaying blocked alerts with source badges, timestamps, and dismiss triggers.
---

== Changelog ==

= 4.3 =
* Added individual **Dismiss Alert** action to notice cards in the **Disabled Alerts History** tab.
* Added **Clear All Alerts** bulk action to the Disabled Alerts History section.
* Fixed notice content body visibility.
* Updated compatibility for WordPress 7.1+ / 6.x and PHP 8.1 - PHP 8.4.
* Added exclusion rules protecting Gutenberg block snackbars and Site Health status indicators.
* Fixed HTML5 compliance.

= 4.2 =
* Fixed critical compatibility issue where WordPress media library/uploader buttons and drag-and-drop modal were hidden on Classic Editor.
* Optimized sliding drawer performance and rendering.

= 4.1 =
* Improved compatibility and notice detection for popular third-party plugins.
* Fixed horizontal notices alignment and floating issues on the settings panel and admin screens.
* Added conditional blocking to ensure notices are completely hidden on standard page loads while safely allowing system status updates to display when actions/settings are saved.

= 4.0 =
* Major upgrade: Added server-side output buffer notice interception (zero screen flicker).
* Added premium Notification Center (Bell Icon) in the WordPress Admin Bar.
* Added right slide-out panel drawer to review and dismiss blocked notifications.
* Added granular filters (disable by core/theme/plugin update categories).
* Redesigned administrative settings panel with a modern responsive UI.
* Added automatic settings migration from older versions.

= 3.4 =
* Compatible up to WordPress 6.9.1.
* Security updates.

= 3.3 =
* Compatible up to WordPress 6.7.1.

= 3.2 =
* Compatible up to WordPress 6.4.2.
* Minor bug fixes.

= 3.1 =
* Compatible up to WordPress 6.2.2.

= 3.0 =
* Compatible up to WordPress 6.0.

= 2.0 =
* The stable version
* Fixed conflict with default wp theme editor

= 1.0.3 =
* Improve the security

= 1.0.2 =
* Improve the functionality
* Fix the bug

= 1.0.1 =
* Fix the bug

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 4.3 =
Recommended update: Adds individual alert dismissal in Disabled Alerts History, fixes notice body visibility, and adds full WordPress 7.1 and PHP 8.4 compatibility.