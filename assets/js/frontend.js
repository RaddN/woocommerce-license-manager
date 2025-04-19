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