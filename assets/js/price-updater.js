jQuery(document).ready(function($) {
    // Set initial price based on the selected option
    function updatePrice() {
        var selectedVariation = $("input[name=\'license_variation\']:checked");
        var variationIndex = selectedVariation.val();
        var price = licenseVariations.prices[variationIndex];
        
        if (price) {
            // Format price with currency symbol
            var formattedPrice = "";
            
            if (licenseVariations.priceFormat.indexOf("%1$s") < licenseVariations.priceFormat.indexOf("%2$s")) {
                formattedPrice = licenseVariations.priceFormat.replace("%1$s", licenseVariations.currencySymbol).replace("%2$s", price);
            } else {
                formattedPrice = licenseVariations.priceFormat.replace("%2$s", price).replace("%1$s", licenseVariations.currencySymbol);
            }
            
            $(".dynamic-price").html(formattedPrice);
        }
    }
    
    // Update price when option changes
    $("input[name=\'license_variation\']").on("change", updatePrice);
    
    // Set initial price
    updatePrice();
});