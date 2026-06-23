(function( $ ) {
	'use strict';

	$(document).ready(function() {

		/* ========================================== */
		/* SETTINGS PAGE INTERACTIVE TABS             */
		/* ========================================== */
		$('.dwpn-tab').on('click', function() {
			var tabId = $(this).data('tab');
			
			// Toggle active class on tabs
			$('.dwpn-tab').removeClass('active');
			$(this).addClass('active');
			
			// Toggle active class on content sections
			$('.dwpn-tab-content').removeClass('active');
			$('#' + tabId).addClass('active');

			// Store last active tab in local storage to keep state across reloads
			if (window.localStorage) {
				localStorage.setItem('dwpn_active_tab', tabId);
			}
		});

		// Restore last active tab on page load
		if (window.localStorage) {
			var activeTab = localStorage.getItem('dwpn_active_tab');
			if (activeTab && $('#' + activeTab).length) {
				$('.dwpn-tab[data-tab="' + activeTab + '"]').trigger('click');
			}
		}

		/* ========================================== */
		/* NOTIFICATION DRAWER TOGGLE                 */
		/* ========================================== */
		// Open drawer when clicking the bell icon in admin bar
		$(document).on('click', '.dwpn-bell-trigger, #wp-adminbar-dwpn-notifications-bell a', function(e) {
			e.preventDefault();
			$('#dwpn-drawer').addClass('open');
			$('#dwpn-drawer-overlay').addClass('open');
		});

		// Close drawer
		function closeDrawer() {
			$('#dwpn-drawer').removeClass('open');
			$('#dwpn-drawer-overlay').removeClass('open');
		}

		$(document).on('click', '#dwpn-drawer-close, #dwpn-drawer-overlay', function(e) {
			e.preventDefault();
			closeDrawer();
		});

		// Close on Escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				closeDrawer();
			}
		});

		/* ========================================== */
		/* AJAX ACTIONS: DISMISS AND CLEAR            */
		/* ========================================== */
		// Helper to update badge counts dynamically
		function updateBadgeCounts(newCount) {
			// Update admin bar badge
			var $badge = $('.dwpn-bell-badge');
			if (newCount > 0) {
				if ($badge.length) {
					$badge.text(newCount);
				} else {
					$('.dwpn-bell-container').append('<span class="dwpn-bell-badge">' + newCount + '</span>');
				}
				$('.dwpn-bell-trigger').addClass('has-notifications');
			} else {
				$badge.remove();
				$('.dwpn-bell-trigger').removeClass('has-notifications');
			}

			// Update drawer header indicator count
			$('.dwpn-count-indicator').text(newCount);

			// If count is 0, show empty state inside drawer
			if (newCount === 0) {
				$('#dwpn-clear-all').fadeOut();
				$('.dwpn-drawer-body').html(
					'<div class="dwpn-no-notifications">' +
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="dwpn-empty-icon"><circle cx="12" cy="12" r="10"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>' +
					'<p>All clear! No blocked notifications.</p>' +
					'</div>'
				);
			}
		}

		// Dismiss single notice
		$(document).on('click', '.dwpn-action-dismiss', function(e) {
			e.preventDefault();
			
			var $btn = $(this);
			var hash = $btn.data('hash');
			var $item = $btn.closest('.dwpn-notice-item');
			var nonce = $('#dwpn-drawer').data('nonce');

			$btn.prop('disabled', true).text('Dismissing...');

			$.ajax({
				url: dwpn_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'disable_wp_notification_dismiss',
					hash: hash,
					nonce: nonce
				},
				success: function(response) {
					if (response.success) {
						$item.css('transform', 'translateX(100px)').css('opacity', 0);
						setTimeout(function() {
							$item.remove();
							var newCount = $('.dwpn-notice-item').length;
							updateBadgeCounts(newCount);
						}, 300);
					} else {
						alert('Error: ' + (response.data.message || 'Could not dismiss notice.'));
						$btn.prop('disabled', false).text('Dismiss');
					}
				},
				error: function() {
					alert('Connection error. Could not dismiss notice.');
					$btn.prop('disabled', false).text('Dismiss');
				}
			});
		});

		// Clear all notices
		$(document).on('click', '#dwpn-clear-all', function(e) {
			e.preventDefault();

			if (!confirm('Are you sure you want to dismiss all active notices permanently?')) {
				return;
			}

			var $btn = $(this);
			var nonce = $('#dwpn-drawer').data('nonce');

			$btn.prop('disabled', true).text('Clearing...');

			$.ajax({
				url: dwpn_ajax.ajax_url,
				type: 'POST',
				data: {
					action: 'disable_wp_notification_clear_all',
					nonce: nonce
				},
				success: function(response) {
					if (response.success) {
						$('.dwpn-notice-item').css('transform', 'translateX(100px)').css('opacity', 0);
						setTimeout(function() {
							$('.dwpn-notice-item').remove();
							updateBadgeCounts(0);
						}, 300);
					} else {
						alert('Error: ' + (response.data.message || 'Could not clear notices.'));
						$btn.prop('disabled', false).text('Clear All');
					}
				},
				error: function() {
					alert('Connection error. Could not clear notices.');
					$btn.prop('disabled', false).text('Clear All');
				}
			});
		});

	});

})( jQuery );
