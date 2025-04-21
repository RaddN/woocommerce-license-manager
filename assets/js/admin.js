jQuery(document).ready(function($) {
    var isLicenseProduct = $("#_is_license_product").is(":checked");
    $(".license_variations_panel").toggle(isLicenseProduct);
    
    $("#_is_license_product").on("change", function() {
        $(".license_variations_panel").toggle($(this).is(":checked"));
    });
    
    // Show only for downloadable products
    $("#_downloadable").on("change", function() {
        if (!$(this).is(":checked")) {
            $("#_is_license_product").prop("checked", false).trigger("change");
        }
    });
});


(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize tabs
        initTabs();
        // Initialize license management actions
        initLicenseActions();
    });

    /**
     * Initialize tab navigation
     */
    function initTabs() {
        $('.wc-license-settings-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            // Update active tab
            $('.wc-license-settings-tabs .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show active content
            var target = $(this).attr('href');
            $('.wc-license-settings-tabs .tab-content').removeClass('active');
            $(target).addClass('active');
        });
    }

    /**
     * Initialize license management actions
     */
    function initLicenseActions() {
        // View license details
        $('.view-license').on('click', function(e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            var licenseId = $(this).data('id');
            var licenseKey = $(this).data('key');
            var productName = $(this).data('product');
            var userName = $(this).data('user');
            var status = $(this).data('status');
            var expiresAt = $(this).data('expires');
            var sitesAllowed = $(this).data('sites-allowed');
            var sitesActive = $(this).data('sites-active');
            
            // Create modal with license details
            var modal = $('<div class="wc-license-modal"></div>');
            var modalContent = $('<div class="wc-license-modal-content"></div>');
            
            modalContent.append('<span class="wc-license-modal-close">&times;</span>');
            modalContent.append('<h2>' + wcLicenseAdmin.i18n.licenseDetails + '</h2>');
            
            var detailsTable = $('<table class="widefat"></table>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.licenseKey + '</th><td>' + licenseKey + '</td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.product + '</th><td>' + productName + '</td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.user + '</th><td>' + userName + '</td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.status + '</th><td><span class="license-status license-' + status + '">' + status + '</span></td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.expiresAt + '</th><td>' + expiresAt + '</td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.sitesAllowed + '</th><td>' + sitesAllowed + '</td></tr>');
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.sitesActive + '</th><td>' + sitesActive + '</td></tr>');
            
            modalContent.append(detailsTable);
            modal.append(modalContent);
            $('body').append(modal);
            
            modal.fadeIn(300);
            
            // Close modal on X click
            $('.wc-license-modal-close').on('click', function() {
                modal.fadeOut(300, function() {
                    modal.remove();
                });
            });
            
            // Close modal when clicking outside of it
            $(window).on('click', function(event) {
                if ($(event.target).is(modal)) {
                    modal.fadeOut(300, function() {
                        modal.remove();
                    });
                }
            });
        });
        
        // Activate license
        $('.activate-license').on('click', function(e) {
            e.preventDefault();
            var licenseId = $(this).data('id');
            var $row = $(this).closest('tr');
            
            if (confirm(wcLicenseAdmin.i18n.confirmActivate)) {
                var $spinner = $('<span class="spinner is-active"></span>');
                $(this).after($spinner);
                $(this).hide();
                
                $.ajax({
                    url: wcLicenseAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wc_license_activate',
                        license_id: licenseId,
                        nonce: wcLicenseAdmin.nonce
                    },
                    success: function(response) {
                        $spinner.remove();
                        if (response.success) {
                            // Update row status
                            $row.find('.column-status').html('<span class="license-status license-active">active</span>');
                            // Replace activate button with deactivate
                            var $deactivateBtn = $('<a href="#" class="button deactivate-license" data-id="' + licenseId + '">' + wcLicenseAdmin.i18n.deactivate + '</a>');
                            $row.find('.activate-license').replaceWith($deactivateBtn);
                            
                            // Initialize the new deactivate button
                            $deactivateBtn.on('click', function(e) {
                                e.preventDefault();
                                deactivateLicense($(this));
                            });
                            
                            alert(response.data.message);
                        } else {
                            alert(wcLicenseAdmin.i18n.error + ' ' + response.data);
                            $row.find('.activate-license').show();
                        }
                    },
                    error: function() {
                        $spinner.remove();
                        $row.find('.activate-license').show();
                        alert(wcLicenseAdmin.i18n.error + ' ' + wcLicenseAdmin.i18n.serverError);
                    }
                });
            }
        });
        
        // Deactivate license
        $('.deactivate-license').on('click', function(e) {
            e.preventDefault();
            deactivateLicense($(this));
        });
        
        // Deactivate a specific site
        $('.deactivate-site').on('click', function(e) {
            e.preventDefault();
            var licenseKey = $(this).data('license');
            var siteUrl = $(this).data('site');
            var $row = $(this).closest('tr');
            
            if (confirm(wcLicenseAdmin.i18n.confirmDeactivate)) {
                var $spinner = $('<span class="spinner is-active"></span>');
                $(this).after($spinner);
                $(this).hide();
                
                $.ajax({
                    url: wcLicenseAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wc_license_deactivate',
                        license_id: $row.data('license-id'),
                        site_url: siteUrl,
                        nonce: wcLicenseAdmin.nonce
                    },
                    success: function(response) {
                        $spinner.remove();
                        if (response.success) {
                            // Remove row from table
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                // Update active sites count
                                var $sitesActive = $('.sites-active-count');
                                if ($sitesActive.length) {
                                    var count = parseInt($sitesActive.text()) - 1;
                                    $sitesActive.text(count);
                                }
                            });
                            alert(response.data.message);
                        } else {
                            alert(wcLicenseAdmin.i18n.error + ' ' + response.data);
                            $row.find('.deactivate-site').show();
                        }
                    },
                    error: function() {
                        $spinner.remove();
                        $row.find('.deactivate-site').show();
                        alert(wcLicenseAdmin.i18n.error + ' ' + wcLicenseAdmin.i18n.serverError);
                    }
                });
            }
        });
        
        // Delete license
        $('.delete-license').on('click', function(e) {
            e.preventDefault();
            var licenseId = $(this).data('id');
            var $row = $(this).closest('tr');
            
            if (confirm(wcLicenseAdmin.i18n.confirmDelete)) {
                var $spinner = $('<span class="spinner is-active"></span>');
                $(this).after($spinner);
                $(this).hide();
                
                $.ajax({
                    url: wcLicenseAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'wc_license_delete',
                        license_id: licenseId,
                        nonce: wcLicenseAdmin.nonce
                    },
                    success: function(response) {
                        $spinner.remove();
                        if (response.success) {
                            // Remove row from table
                            $row.fadeOut(300, function() {
                                $(this).remove();
                            });
                            alert(response.data.message);
                        } else {
                            alert(wcLicenseAdmin.i18n.error + ' ' + response.data);
                            $row.find('.delete-license').show();
                        }
                    },
                    error: function() {
                        $spinner.remove();
                        $row.find('.delete-license').show();
                        alert(wcLicenseAdmin.i18n.error + ' ' + wcLicenseAdmin.i18n.serverError);
                    }
                });
            }
        });
    }
    
    /**
     * Deactivate license helper function
     */
    function deactivateLicense($button) {
        var licenseId = $button.data('id');
        var $row = $button.closest('tr');
        
        if (confirm(wcLicenseAdmin.i18n.confirmDeactivate)) {
            var $spinner = $('<span class="spinner is-active"></span>');
            $button.after($spinner);
            $button.hide();
            
            $.ajax({
                url: wcLicenseAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_license_deactivate',
                    license_id: licenseId,
                    nonce: wcLicenseAdmin.nonce
                },
                success: function(response) {
                    $spinner.remove();
                    if (response.success) {
                        // Update row status
                        $row.find('.column-status').html('<span class="license-status license-inactive">inactive</span>');
                        // Replace deactivate button with activate
                        var $activateBtn = $('<a href="#" class="button activate-license" data-id="' + licenseId + '">' + wcLicenseAdmin.i18n.activate + '</a>');
                        $button.replaceWith($activateBtn);
                        
                        // Initialize the new activate button
                        $activateBtn.on('click', function(e) {
                            e.preventDefault();
                            activateLicense($(this));
                        });
                        
                        alert(response.data.message);
                    } else {
                        alert(wcLicenseAdmin.i18n.error + ' ' + response.data);
                        $button.show();
                    }
                },
                error: function() {
                    $spinner.remove();
                    $button.show();
                    alert(wcLicenseAdmin.i18n.error + ' ' + wcLicenseAdmin.i18n.serverError);
                }
            });
        }
    }
    
    /**
     * Activate license helper function 
     */
    function activateLicense($button) {
        var licenseId = $button.data('id');
        var $row = $button.closest('tr');
        
        if (confirm(wcLicenseAdmin.i18n.confirmActivate)) {
            var $spinner = $('<span class="spinner is-active"></span>');
            $button.after($spinner);
            $button.hide();
            
            $.ajax({
                url: wcLicenseAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_license_activate',
                    license_id: licenseId,
                    nonce: wcLicenseAdmin.nonce
                },
                success: function(response) {
                    $spinner.remove();
                    if (response.success) {
                        // Update row status
                        $row.find('.column-status').html('<span class="license-status license-active">active</span>');
                        // Replace activate button with deactivate
                        var $deactivateBtn = $('<a href="#" class="button deactivate-license" data-id="' + licenseId + '">' + wcLicenseAdmin.i18n.deactivate + '</a>');
                        $button.replaceWith($deactivateBtn);
                        
                        // Initialize the new deactivate button
                        $deactivateBtn.on('click', function(e) {
                            e.preventDefault();
                            deactivateLicense($(this));
                        });
                        
                        alert(response.data.message);
                    } else {
                        alert(wcLicenseAdmin.i18n.error + ' ' + response.data);
                        $button.show();
                    }
                },
                error: function() {
                    $spinner.remove();
                    $button.show();
                    alert(wcLicenseAdmin.i18n.error + ' ' + wcLicenseAdmin.i18n.serverError);
                }
            });
        }
    }

})(jQuery);