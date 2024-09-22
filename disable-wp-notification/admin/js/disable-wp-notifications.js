(function( $ ) {
	jQuery(document).ready(function(){
		jQuery("#adminmenu .update-plugins").replaceWith('');
		jQuery("body.wp-admin .plugin-update.colspanchange").replaceWith('');
		jQuery("body.wp-admin .notice.elementor-message.elementor-message-dismissed").replaceWith('');
		jQuery("body.wp-admin:not(.theme-editor-php) .notice:not(.updated):not(.new-application-password-notice):not(#message)").replaceWith('');
	});
})( jQuery );