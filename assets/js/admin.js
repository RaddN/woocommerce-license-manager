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