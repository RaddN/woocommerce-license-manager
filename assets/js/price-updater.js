jQuery(document).ready(function($) {
    function updatePrice() {
        var selectedVariation = $("input[name=\'license_variation\']:checked");
        if (!selectedVariation.length) {
            selectedVariation = $("input[name=\'license_variation\']").first().prop("checked", true);
        }

        var variationIndex = selectedVariation.val();
        if (licenseVariations.formattedPrices && licenseVariations.formattedPrices[variationIndex]) {
            $(".dynamic-price").html(licenseVariations.formattedPrices[variationIndex]);
        }

        $(".wc-license-option-card").removeClass("is-selected");
        selectedVariation.closest(".wc-license-option-card").addClass("is-selected");
    }

    $("input[name=\'license_variation\']").on("change", updatePrice);

    if (licenseVariations.defaultVariation && $("input[name=\'license_variation\'][value='" + licenseVariations.defaultVariation + "']").length) {
        $("input[name=\'license_variation\'][value='" + licenseVariations.defaultVariation + "']").prop("checked", true);
    }

    updatePrice();
});
