jQuery(document).ready(function($) {
    $(".license-activation-form").on("submit", function(e) {
        e.preventDefault();
        var form = $(this);
        var licenseKey = form.data("license-key");
        var siteUrl = form.find("input[name=\'site_url\']").val();
        var submitButton = form.find("button[type=\'submit\']");
        
        submitButton.text(wcLicenseManager.i18n.activating);
        submitButton.prop("disabled", true);
        
        $.ajax({
            url: wcLicenseManager.ajaxUrl,
            type: "POST",
            data: {
                action: "activate_license",
                nonce: wcLicenseManager.nonce,
                license_key: licenseKey,
                site_url: siteUrl
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                    submitButton.text("Activate");
                    submitButton.prop("disabled", false);
                }
            },
            error: function() {
                alert("Error activating license. Please try again.");
                submitButton.text("Activate");
                submitButton.prop("disabled", false);
            }
        });
    });
    
    $(".deactivate-site").on("click", function(e) {
        e.preventDefault();
        var link = $(this);
        var licenseKey = link.data("license-key");
        var siteUrl = link.data("site-url");
        
        if (!confirm("Are you sure you want to deactivate this site?")) {
            return;
        }
        
        link.text(wcLicenseManager.i18n.deactivating);
        
        $.ajax({
            url: wcLicenseManager.ajaxUrl,
            type: "POST",
            data: {
                action: "deactivate_license",
                nonce: wcLicenseManager.nonce,
                license_key: licenseKey,
                site_url: siteUrl
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                    link.text("Deactivate");
                }
            },
            error: function() {
                alert("Error deactivating license. Please try again.");
                link.text("Deactivate");
            }
        });
    });
});



/**
 * Frontend JavaScript for license manager
 * Save this as assets/js/frontend.js
 */
jQuery(document).ready(function($) {
    // Toggle dropdown menu
    $(document).on('click', '.dropdown-toggle', function(e) {
        e.preventDefault();
        $(this).siblings('.dropdown-menu').toggleClass('active');
        
        // Close other open dropdowns
        $('.dropdown-menu').not($(this).siblings('.dropdown-menu')).removeClass('active');
    });
    
    // Close dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.dropdown').length) {
            $('.dropdown-menu').removeClass('active');
        }
    });
    
    // Handle site deactivation
    $(document).on('click', '.deactivate-site', function(e) {
        e.preventDefault();
        
        const licenseKey = $(this).data('license-key');
        const siteUrl = $(this).data('site-url');
        const $listItem = $(this).closest('li');
        
        $listItem.find('a').text(wcLicenseManager.i18n.deactivating);
        
        $.ajax({
            url: wcLicenseManager.ajaxUrl,
            type: 'POST',
            data: {
                action: 'deactivate_license',
                license_key: licenseKey,
                site_url: siteUrl,
                nonce: wcLicenseManager.nonce
            },
            success: function(response) {
                if (response.success) {
                    $listItem.slideUp(300, function() {
                        $(this).remove();
                        
                        // Update activations count
                        const activeSites = Object.keys(response.data.active_sites).length;
                        const $licenseItem = $('.license-key-item:has([data-license-key="' + licenseKey + '"])');
                        const $activations = $licenseItem.find('.license-details p:contains("Activations:")');
                        const sitesAllowed = $activations.text().split('/')[1];
                        $activations.html('<strong>Activations:</strong> ' + activeSites + '/' + sitesAllowed);
                        
                        // Show success message
                        showMessage(response.data.message, 'success');
                        
                        // If no more sites, remove the active sites section
                        if (activeSites === 0) {
                            $licenseItem.find('.active-sites').slideUp(300);
                            // Remove deactivate all option from dropdown
                            $licenseItem.find('.deactivate-all-sites').parent().remove();
                        }
                    });
                } else {
                    $listItem.find('a').text('Deactivate');
                    showMessage(response.data.message, 'error');
                }
            },
            error: function() {
                $listItem.find('a').text('Deactivate');
                showMessage('An error occurred. Please try again.', 'error');
            }
        });
    });
    
    // Handle deactivate all sites
    $(document).on('click', '.deactivate-all-sites', function(e) {
        e.preventDefault();
        
        const licenseKey = $(this).data('license-key');
        const $licenseItem = $('.license-key-item:has([data-license-key="' + licenseKey + '"])');
        const $dropdownMenu = $(this).closest('.dropdown-menu');
        
        // Close dropdown
        $dropdownMenu.removeClass('active');
        
        // Confirm deactivation
        if (confirm(wcLicenseManager.i18n.confirmDeactivateAll)) {
            // Show loading state
            $licenseItem.find('.active-sites').addClass('loading');
            
            $.ajax({
                url: wcLicenseManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'deactivate_all_sites',
                    license_key: licenseKey,
                    nonce: wcLicenseManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Remove active sites section
                        $licenseItem.find('.active-sites').slideUp(300, function() {
                            $(this).remove();
                        });
                        
                        // Update activations count
                        const $activations = $licenseItem.find('.license-details p:contains("Activations:")');
                        const sitesAllowed = $activations.text().split('/')[1];
                        $activations.html('<strong>Activations:</strong> 0/' + sitesAllowed);
                        
                        // Remove deactivate all option from dropdown
                        $licenseItem.find('.deactivate-all-sites').parent().remove();
                        
                        // Show success message
                        showMessage(response.data.message, 'success');
                    } else {
                        $licenseItem.find('.active-sites').removeClass('loading');
                        showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    $licenseItem.find('.active-sites').removeClass('loading');
                    showMessage('An error occurred. Please try again.', 'error');
                }
            });
        }
    });
    
    // Handle delete license
    $(document).on('click', '.delete-license', function(e) {
        e.preventDefault();
        
        const licenseKey = $(this).data('license-key');
        const $licenseItem = $('.license-key-item:has([data-license-key="' + licenseKey + '"])');
        const $dropdownMenu = $(this).closest('.dropdown-menu');
        
        // Close dropdown
        $dropdownMenu.removeClass('active');
        
        // Confirm deletion
        if (confirm(wcLicenseManager.i18n.confirmDelete)) {
            // Show loading state
            $licenseItem.addClass('deleting');
            
            $.ajax({
                url: wcLicenseManager.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'delete_license',
                    license_key: licenseKey,
                    nonce: wcLicenseManager.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Remove license item
                        $licenseItem.slideUp(300, function() {
                            $(this).remove();
                            
                            // If no more licenses, show message
                            if ($('.license-key-item').length === 0) {
                                $('.wc-license-manager-keys').html('<p>You have no license keys yet.</p>');
                            }
                            
                            // Show success message
                            showMessage(response.data.message, 'success');
                        });
                    } else {
                        $licenseItem.removeClass('deleting');
                        showMessage(response.data.message, 'error');
                    }
                },
                error: function() {
                    $licenseItem.removeClass('deleting');
                    showMessage('An error occurred. Please try again.', 'error');
                }
            });
        }
    });
    
    // Handle upgrade/downgrade
    $(document).on('click', '.upgrade-license', function(e) {
        e.preventDefault();
        
        const licenseKey = $(this).data('license-key');
        const productId = $(this).data('product-id');
        const $dropdownMenu = $(this).closest('.dropdown-menu');
        
        // Close dropdown
        $dropdownMenu.removeClass('active');
        
        // Get upgrade options
        $.ajax({
            url: wcLicenseManager.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_upgrade_options',
                license_key: licenseKey,
                product_id: productId,
                nonce: wcLicenseManager.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Create modal overlay
                    const $modal = $('<div class="wc-license-modal-overlay"></div>');
                    $modal.html(response.data.html);
                    $('body').append($modal);
                    
                    // Show modal
                    setTimeout(function() {
                        $modal.addClass('active');
                    }, 10);
                    
                    // Handle cancel
                    $modal.on('click', '.cancel-upgrade', function(e) {
                        e.preventDefault();
                        closeModal($modal);
                    });
                    
                    // Handle click outside modal
                    $modal.on('click', function(e) {
                        if ($(e.target).hasClass('wc-license-modal-overlay')) {
                            closeModal($modal);
                        }
                    });
                    
                    // Handle proceed to checkout
                    $modal.on('click', '.confirm-upgrade', function(e) {
                        e.preventDefault();
                        
                        const selectedVariation = $modal.find('input[name="upgrade_variation"]:checked').val();
                        if (!selectedVariation) {
                            alert('Please select a package option.');
                            return;
                        }
                        
                        const baseUrl = $(this).data('base-url');
                        window.location.href = baseUrl + '&upgrade_variation=' + selectedVariation;
                    });
                } else {
                    showMessage(response.data.message, 'error');
                }
            },
            error: function() {
                showMessage('An error occurred. Please try again.', 'error');
            }
        });
    });
    
    // Helper function to close modal
    function closeModal($modal) {
        $modal.removeClass('active');
        setTimeout(function() {
            $modal.remove();
        }, 300);
    }
    
    // Helper function to show messages
    function showMessage(message, type) {
        const $messageContainer = $('#license-action-message');
        $messageContainer.removeClass('success error').addClass(type);
        $messageContainer.html(message).slideDown();
        
        // Auto hide after 5 seconds
        setTimeout(function() {
            $messageContainer.slideUp();
        }, 5000);
    }
});