jQuery(document).ready(function($) {
    var $panel = $("#wc_license_product_data");
    if (!$panel.length) {
        return;
    }

    var $builder = $panel.find("[data-license-builder]");
    var $packageList = $panel.find("[data-license-package-list]");
    var $packageCountStat = $panel.find("[data-license-package-count]");
    var $downloadCountStat = $panel.find("[data-license-download-count]");
    var template = $("#tmpl-wc-license-package").html() || "";
    var nextIndex = 0;
    var adminConfig = window.wcLicenseAdmin || {};
    var downloadSyncTimer = null;
    var normalizedDownloadIdMap = {};

    function getAdminI18n(key, fallback) {
        if (adminConfig.i18n && adminConfig.i18n[key]) {
            return adminConfig.i18n[key];
        }

        return fallback;
    }

    function getProductId() {
        return String($builder.data("productId") || $("#post_ID").val() || "").trim();
    }

    function buildUrl(baseUrl, params) {
        var url = new URL(baseUrl || "/cart/", window.location.origin);
        Object.keys(params).forEach(function(key) {
            url.searchParams.set(key, params[key]);
        });
        return url.toString();
    }

    function buildPackageShortcode(index) {
        return '[wclicence_price product="' + getProductId() + '" variation_index="' + index + '" button_text="Buy Now"]';
    }

    function buildAllPackagesShortcode() {
        return '[wclicence_price product="' + getProductId() + '" template="cards"]';
    }

    function buildPackageCartLink(index) {
        return buildUrl(adminConfig.cartUrl || "/cart/", {
            "add-to-cart": getProductId(),
            "license_variation": index
        });
    }

    function escapeHtml(value) {
        return $("<div>").text(value || "").html();
    }

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/"/g, "&quot;");
    }

    function getCountLabel(count, singular, plural) {
        return String(count) + " " + (count === 1 ? singular : plural);
    }

    function getFileNameFromUrl(url) {
        if (!url) {
            return "";
        }

        try {
            var parsedUrl = new URL(url, window.location.origin);
            var pathname = parsedUrl.pathname || "";
            return pathname.split("/").filter(Boolean).pop() || "";
        } catch (error) {
            return String(url).split("/").filter(Boolean).pop() || "";
        }
    }

    function createDownloadUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === "function") {
            return window.crypto.randomUUID();
        }

        function segment(length) {
            var value = "";
            while (value.length < length) {
                value += Math.random().toString(16).slice(2);
            }
            return value.slice(0, length);
        }

        return [
            segment(8),
            segment(4),
            "4" + segment(3),
            "a" + segment(3),
            segment(12)
        ].join("-");
    }

    function shouldReplaceDownloadId(value) {
        return !value || value.length > 36 || !/^[A-Za-z0-9-]+$/.test(value);
    }

    function getInlineIcon(name) {
        switch (name) {
            case "layers":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="M10 3.25 3.25 7 10 10.75 16.75 7 10 3.25Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4.75 10.25 10 13.25l5.25-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.75 13.25 10 16.25l5.25-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case "package":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="M10 2.75 3.25 6.25 10 9.75l6.75-3.5L10 2.75Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M3.25 6.25v7.5L10 17.25l6.75-3.5v-7.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 9.75v7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case "download":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="M10 3.25v8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m6.75 8.75 3.25 3.25 3.25-3.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 15.25h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case "code":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="m7.25 6.25-3 3.75 3 3.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m12.75 6.25 3 3.75-3 3.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m11 4.75-2 10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case "link":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="M8.25 11.75 11.75 8.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M7.5 14.75H6.25A3.5 3.5 0 1 1 6.25 7h1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M12.5 5.25h1.25A3.5 3.5 0 1 1 13.75 12h-1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case "copy":
                return '<svg viewBox="0 0 20 20" fill="none"><rect x="7" y="4" width="9" height="11" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M5.75 12.5h-.5A2.25 2.25 0 0 1 3 10.25v-6A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
            case "check":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="m5 10.25 3.25 3.25L15 6.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case "close":
                return '<svg viewBox="0 0 20 20" fill="none"><path d="m5.5 5.5 9 9M14.5 5.5l-9 9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
            default:
                return "";
        }
    }

    function closeCopyModal() {
        $(".wc-license-copy-modal").remove();
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        return new Promise(function(resolve, reject) {
            var $temp = $("<textarea>").val(value).css({
                position: "fixed",
                top: "-9999px",
                left: "-9999px"
            });

            $("body").append($temp);
            $temp[0].focus();
            $temp[0].select();

            try {
                document.execCommand("copy");
                resolve();
            } catch (error) {
                reject(error);
            } finally {
                $temp.remove();
            }
        });
    }

    function openCopyModal(title, description, fields, options) {
        closeCopyModal();

        options = options || {};

        var fieldsHtml = fields.map(function(field, index) {
            return [
                '<div class="wc-license-copy-modal__field">',
                '<div class="wc-license-copy-modal__field-header">',
                '<span class="wc-license-copy-modal__field-icon" aria-hidden="true">' + getInlineIcon(field.icon || "code") + "</span>",
                '<div class="wc-license-copy-modal__field-copy">',
                '<label class="wc-license-copy-modal__label" for="wc-license-copy-field-' + index + '">' + escapeHtml(field.label) + '</label>',
                field.description ? '<p class="wc-license-copy-modal__description">' + escapeHtml(field.description) + "</p>" : "",
                "</div>",
                "</div>",
                '<div class="wc-license-copy-modal__row">',
                '<textarea id="wc-license-copy-field-' + index + '" class="wc-license-copy-modal__textarea" readonly>' + escapeHtml(field.value) + "</textarea>",
                '<button type="button" class="button button-secondary wc-license-copy-modal__copy" data-copy-value="' + escapeHtml(field.value) + '"><span class="wc-license-copy-modal__copy-icon" aria-hidden="true">' + getInlineIcon("copy") + '</span><span class="wc-license-copy-modal__copy-label">' + escapeHtml(field.buttonLabel || getAdminI18n("copy", "Copy")) + "</span></button>",
                "</div>",
                "</div>"
            ].join("");
        }).join("");

        var modalHtml = [
            '<div class="wc-license-modal wc-license-copy-modal" style="display:block;">',
            '<div class="wc-license-modal-content wc-license-copy-modal__content">',
            '<button type="button" class="wc-license-modal-close wc-license-copy-modal__close" aria-label="' + escapeHtml(getAdminI18n("close", "Close")) + '">' + getInlineIcon("close") + "</button>",
            '<div class="wc-license-copy-modal__hero">',
            '<span class="wc-license-copy-modal__hero-icon" aria-hidden="true">' + getInlineIcon(options.heroIcon || "layers") + "</span>",
            '<div class="wc-license-copy-modal__hero-copy">',
            '<span class="wc-license-copy-modal__eyebrow">' + escapeHtml(options.eyebrow || getAdminI18n("copyPopupEyebrow", "Portable snippets")) + "</span>",
            '<h2>' + escapeHtml(title) + "</h2>",
            description ? '<p class="wc-license-copy-modal__intro">' + escapeHtml(description) + "</p>" : "",
            "</div>",
            "</div>",
            '<div class="wc-license-copy-modal__fields">' + fieldsHtml + "</div>",
            '<div class="wc-license-copy-modal__footer"><span class="wc-license-copy-modal__footer-icon" aria-hidden="true">' + getInlineIcon(options.footerIcon || "package") + '</span><p>' + escapeHtml(options.footerText || getAdminI18n("copyPopupFooter", "These snippets are ready to use with the current product and package setup.")) + "</p></div>",
            "</div>",
            "</div>"
        ].join("");

        $("body").append(modalHtml);
    }

    function ensureDownloadRowIds() {
        $("#woocommerce-product-data .downloadable_files tbody tr").each(function() {
            var $hashInput = $(this).find('input[name="_wc_file_hashes[]"]').first();
            if (!$hashInput.length) {
                return;
            }

            var currentValue = $.trim($hashInput.val());
            if (shouldReplaceDownloadId(currentValue)) {
                var newValue = createDownloadUuid();
                if (currentValue) {
                    normalizedDownloadIdMap[currentValue] = newValue;
                }
                $hashInput.val(newValue);
            }
        });
    }

    function getAvailableDownloads() {
        var downloads = [];

        ensureDownloadRowIds();

        $("#woocommerce-product-data .downloadable_files tbody tr").each(function(index) {
            var $row = $(this);
            var $hashInput = $row.find('input[name="_wc_file_hashes[]"]').first();
            var $nameInput = $row.find('input[name="_wc_file_names[]"]').first();
            var $urlInput = $row.find('input[name="_wc_file_urls[]"]').first();

            if (!$hashInput.length) {
                return;
            }

            var id = $.trim($hashInput.val());
            if (!id) {
                return;
            }

            var url = $.trim($urlInput.val());
            if (!$.trim($nameInput.val()) && !url) {
                return;
            }

            var name = $.trim($nameInput.val()) || getFileNameFromUrl(url);
            if (!name) {
                name = getAdminI18n("downloadFileFallback", "Download file") + " " + String(index + 1);
            }

            downloads.push({
                id: id,
                name: name,
                url: url,
                fileName: getFileNameFromUrl(url)
            });
        });

        return downloads;
    }

    function getPackageDownloadState($card, downloads) {
        var index = String($card.data("packageIndex"));
        var $section = $card.find("[data-license-package-downloads]").first();
        var downloadIds = downloads.map(function(download) {
            return download.id;
        });
        var modeFieldName = 'license_variation_download_mode[' + index + ']';
        var choiceFieldName = 'license_variation_download_ids[' + index + '][]';
        var mode = $card.find('input[name="' + modeFieldName + '"]:checked').val() ||
            $card.find('input[name="' + modeFieldName + '"]').first().val() ||
            String($section.attr("data-mode") || "all");
        var selectedIds = $card.find('input[name="' + choiceFieldName + '"]:checked').map(function() {
            return String($(this).val());
        }).get();

        if (!selectedIds.length) {
            var rawSelectedIds = $section.attr("data-selected-ids");
            if (rawSelectedIds) {
                try {
                    selectedIds = JSON.parse(rawSelectedIds);
                } catch (error) {
                    selectedIds = [];
                }
            }
        }

        selectedIds = $.map(selectedIds, function(downloadId) {
            return String(normalizedDownloadIdMap[downloadId] || downloadId);
        });

        selectedIds = $.grep(selectedIds, function(downloadId) {
            return downloadIds.indexOf(String(downloadId)) !== -1;
        });

        if (downloadIds.length <= 1) {
            return {
                mode: "all",
                selectedIds: downloadIds.slice()
            };
        }

        if (!selectedIds.length) {
            selectedIds = mode === "selected" ? [downloadIds[0]] : downloadIds.slice();
        } else if (mode === "selected") {
            selectedIds = [selectedIds[0]];
        }

        return {
            mode: mode === "selected" ? "selected" : "all",
            selectedIds: selectedIds
        };
    }

    function renderDownloadAccessSection(index, downloads, state) {
        var countLabel = getCountLabel(
            downloads.length,
            getAdminI18n("downloadSingular", "download file"),
            getAdminI18n("downloadPlural", "download files")
        );

        if (!downloads.length) {
            return [
                '<div class="wc-license-package-downloads" data-license-package-downloads data-mode="all" data-selected-ids="[]">',
                '<div class="wc-license-package-downloads__header">',
                '<div class="wc-license-package-downloads__heading">',
                '<span class="wc-license-icon wc-license-package-downloads__icon" aria-hidden="true">' + getInlineIcon("download") + "</span>",
                "<div>",
                "<strong>" + escapeHtml(getAdminI18n("downloadAccessTitle", "Download access")) + "</strong>",
                "<p>" + escapeHtml(getAdminI18n("downloadAccessDescription", "Choose which WooCommerce downloads buyers receive with this package.")) + "</p>",
                "</div>",
                "</div>",
                "</div>",
                '<input type="hidden" class="wc-license-download-mode-hidden" name="license_variation_download_mode[' + escapeAttribute(index) + ']" value="all">',
                '<div class="wc-license-package-downloads__empty is-warning"><span class="wc-license-package-downloads__empty-text">' + escapeHtml(getAdminI18n("noDownloadsMessage", "Add files in WooCommerce’s Downloads tab to map file access per package.")) + "</span></div>",
                "</div>"
            ].join("");
        }

        if (downloads.length === 1) {
            return [
                '<div class="wc-license-package-downloads" data-license-package-downloads data-mode="all" data-selected-ids="' + escapeAttribute(JSON.stringify([downloads[0].id])) + '">',
                '<div class="wc-license-package-downloads__header">',
                '<div class="wc-license-package-downloads__heading">',
                '<span class="wc-license-icon wc-license-package-downloads__icon" aria-hidden="true">' + getInlineIcon("download") + "</span>",
                "<div>",
                "<strong>" + escapeHtml(getAdminI18n("downloadAccessTitle", "Download access")) + "</strong>",
                "<p>" + escapeHtml(getAdminI18n("downloadAccessDescription", "Choose which WooCommerce downloads buyers receive with this package.")) + "</p>",
                "</div>",
                "</div>",
                "</div>",
                '<input type="hidden" class="wc-license-download-mode-hidden" name="license_variation_download_mode[' + escapeAttribute(index) + ']" value="all">',
                '<div class="wc-license-package-downloads__empty"><span class="wc-license-package-downloads__empty-text">' + escapeHtml(getAdminI18n("singleDownloadAuto", "This package will include the product’s single downloadable file automatically.")) + "</span></div>",
                "</div>"
            ].join("");
        }

        var choicesHtml = downloads.map(function(download) {
            var checked = state.selectedIds.indexOf(download.id) !== -1;
            var meta = download.fileName ? '<span class="wc-license-package-downloads__choice-meta">' + escapeHtml(download.fileName) + "</span>" : "";

            return [
                '<label class="wc-license-package-downloads__choice">',
                '<input type="checkbox" class="wc-license-download-choice" name="license_variation_download_ids[' + escapeAttribute(index) + '][]" value="' + escapeAttribute(download.id) + '"' + (checked ? " checked" : "") + ">",
                '<span class="wc-license-package-downloads__choice-copy">',
                '<span class="wc-license-package-downloads__choice-title">' + escapeHtml(download.name) + "</span>",
                meta,
                "</span>",
                "</label>"
            ].join("");
        }).join("");

        return [
            '<div class="wc-license-package-downloads" data-license-package-downloads data-mode="' + escapeAttribute(state.mode) + '" data-selected-ids="' + escapeAttribute(JSON.stringify(state.selectedIds)) + '">',
            '<div class="wc-license-package-downloads__header">',
            '<div class="wc-license-package-downloads__heading">',
            '<span class="wc-license-icon wc-license-package-downloads__icon" aria-hidden="true">' + getInlineIcon("download") + "</span>",
            "<div>",
            "<strong>" + escapeHtml(getAdminI18n("downloadAccessTitle", "Download access")) + "</strong>",
            "<p>" + escapeHtml(getAdminI18n("downloadAccessDescription", "Choose which WooCommerce downloads buyers receive with this package.")) + "</p>",
            "</div>",
            "</div>",
            '<span class="wc-license-package-downloads__count">' + escapeHtml(countLabel) + "</span>",
            "</div>",
            '<div class="wc-license-package-downloads__mode">',
            '<label class="wc-license-segmented-option"><input type="radio" class="wc-license-download-mode" name="license_variation_download_mode[' + escapeAttribute(index) + ']" value="all"' + (state.mode === "all" ? " checked" : "") + '><span>' + escapeHtml(getAdminI18n("allFiles", "All files")) + "</span></label>",
            '<label class="wc-license-segmented-option"><input type="radio" class="wc-license-download-mode" name="license_variation_download_mode[' + escapeAttribute(index) + ']" value="selected"' + (state.mode === "selected" ? " checked" : "") + '><span>' + escapeHtml(getAdminI18n("selectedFiles", "Selected files")) + "</span></label>",
            "</div>",
            '<div class="wc-license-package-downloads__choices' + (state.mode === "selected" ? " is-active" : "") + '">',
            choicesHtml,
            "</div>",
            "</div>"
        ].join("");
    }

    function updateHeroStats(downloads) {
        var packageCount = $packageList.find("[data-license-package]").length;
        var availableDownloads = downloads || getAvailableDownloads();
        var downloadCount = availableDownloads.length;

        if ($packageCountStat.length) {
            $packageCountStat.text(getCountLabel(
                packageCount,
                getAdminI18n("packageSingular", "package"),
                getAdminI18n("packagePlural", "packages")
            )).attr("data-license-package-count", packageCount);
        }

        if ($downloadCountStat.length) {
            $downloadCountStat.text(getCountLabel(
                downloadCount,
                getAdminI18n("downloadSingular", "download file"),
                getAdminI18n("downloadPlural", "download files")
            )).attr("data-license-download-count", downloadCount);
        }
    }

    function syncPackageDownloadSections($cards, downloads) {
        var availableDownloads = downloads || getAvailableDownloads();
        var $targetCards = $cards && $cards.length ? $cards : $packageList.find("[data-license-package]");

        $targetCards.each(function() {
            var $card = $(this);
            var index = String($card.data("packageIndex"));
            var state = getPackageDownloadState($card, availableDownloads);
            var html = renderDownloadAccessSection(index, availableDownloads, state);
            var $existingSection = $card.find("[data-license-package-downloads]").first();

            if ($existingSection.length) {
                $existingSection.replaceWith(html);
            }
        });

        updateHeroStats(availableDownloads);
    }

    function scheduleDownloadSync() {
        window.clearTimeout(downloadSyncTimer);
        downloadSyncTimer = window.setTimeout(function() {
            syncPackageDownloadSections(null, getAvailableDownloads());
        }, 60);
    }

    function bindDownloadSync() {
        var downloadsBody = document.querySelector("#woocommerce-product-data .downloadable_files tbody");
        if (!downloadsBody) {
            updateHeroStats([]);
            return;
        }

        $(document).on("input change", '#woocommerce-product-data .downloadable_files input', scheduleDownloadSync);
        $(document).on("click", '#woocommerce-product-data .downloadable_files .insert, #woocommerce-product-data .downloadable_files .delete', function() {
            window.setTimeout(scheduleDownloadSync, 20);
        });
        $("#post").on("submit", ensureDownloadRowIds);

        if (window.MutationObserver) {
            new MutationObserver(scheduleDownloadSync).observe(downloadsBody, {
                childList: true,
                subtree: true
            });
        }

        scheduleDownloadSync();
    }

    $packageList.find("[data-license-package]").each(function() {
        var index = parseInt($(this).attr("data-package-index"), 10);
        if (!isNaN(index)) {
            nextIndex = Math.max(nextIndex, index);
        }
    });

    function syncPackagePositions() {
        $packageList.find("[data-license-package]").each(function(position) {
            $(this).find(".wc-license-package-position").val(position);
        });
    }

    function syncBuilderState() {
        var enabled = $("#_is_license_product").is(":checked");
        $builder.toggleClass("is-disabled", !enabled);
    }

    function syncLifetimeState($scope) {
        var $card = $scope.closest("[data-license-package]");
        if (!$card.length) {
            return;
        }

        var isLifetime = $card.find(".wc-license-lifetime-toggle").is(":checked");
        $card.toggleClass("is-lifetime", isLifetime);
        $card.find(".wc-license-duration-value, .wc-license-duration-unit").prop("disabled", isLifetime);
    }

    function syncUnlimitedSitesState($scope) {
        var $card = $scope.closest("[data-license-package]");
        if (!$card.length) {
            return;
        }

        var isUnlimited = $card.find(".wc-license-unlimited-sites-toggle").is(":checked");
        var $sitesInput = $card.find(".wc-license-sites-input");
        $card.toggleClass("is-unlimited-sites", isUnlimited);
        $sitesInput.prop("disabled", isUnlimited);

        if (!isUnlimited && (!Number($sitesInput.val()) || Number($sitesInput.val()) < 1)) {
            $sitesInput.val(1);
        }
    }

    function ensureDefaultPackage() {
        var $checked = $packageList.find('input[name="license_variation_default"]:checked');
        if ($checked.length) {
            return;
        }

        $packageList.find('input[name="license_variation_default"]').first().prop("checked", true);
    }

    function refreshPackageStates() {
        ensureDefaultPackage();
        syncPackagePositions();

        $packageList.find("[data-license-package]").each(function() {
            var $card = $(this);
            var isDefault = $card.find('input[name="license_variation_default"]').is(":checked");
            var isRecommended = $card.find('input[name^="license_variation_recommended"]').is(":checked");
            $card.toggleClass("is-default", isDefault);
            $card.toggleClass("is-recommended", isRecommended);
            syncLifetimeState($card);
            syncUnlimitedSitesState($card);
        });

        updateHeroStats();
    }

    function addPackageCard() {
        nextIndex += 1;
        var html = template.replace(/__INDEX__/g, String(nextIndex));
        $packageList.append(html);
        var $newCard = $packageList.find("[data-license-package]").last();
        if (!$newCard.find('input[name="license_variation_title[' + nextIndex + ']"]').val()) {
            $newCard.find('input[name="license_variation_title[' + nextIndex + ']"]').val(
                (window.wcLicenseAdmin && wcLicenseAdmin.i18n && wcLicenseAdmin.i18n.defaultPackageName) ? wcLicenseAdmin.i18n.defaultPackageName + " " + ($packageList.find("[data-license-package]").length) : "Package " + ($packageList.find("[data-license-package]").length)
            );
        }
        refreshPackageStates();
        syncPackageDownloadSections($newCard);
    }

    $panel.on("click", ".wc-license-add-package", function(e) {
        e.preventDefault();
        addPackageCard();
    });

    $panel.on("click", ".wc-license-remove-package", function(e) {
        e.preventDefault();

        var $cards = $packageList.find("[data-license-package]");
        if ($cards.length <= 1) {
            if (window.wcLicenseAdmin && wcLicenseAdmin.i18n && wcLicenseAdmin.i18n.packageRequired) {
                window.alert(wcLicenseAdmin.i18n.packageRequired);
            }
            return;
        }

        var $card = $(this).closest("[data-license-package]");
        var wasDefault = $card.find('input[name="license_variation_default"]').is(":checked");
        $card.remove();

        if (wasDefault) {
            $packageList.find('input[name="license_variation_default"]').first().prop("checked", true);
        }

        refreshPackageStates();
        syncPackageDownloadSections();
    });

    $panel.on("click", ".wc-license-copy-package", function(e) {
        e.preventDefault();

        var $card = $(this).closest("[data-license-package]");
        var packageIndex = String($card.data("packageIndex"));
        var packageTitle = $.trim($card.find('input[name^="license_variation_title"]').val()) || getAdminI18n("defaultPackageName", "Package");

        openCopyModal(
            getAdminI18n("packageCopyTitle", "Copy package shortcode and link"),
            packageTitle + ". " + getAdminI18n("packageCopyDescription", "Use these snippets to sell this exact package outside the product page."),
            [
                {
                    label: getAdminI18n("packageShortcodeLabel", "Specific package shortcode"),
                    value: buildPackageShortcode(packageIndex),
                    icon: "code",
                    buttonLabel: getAdminI18n("copyShortcode", "Copy shortcode")
                },
                {
                    label: getAdminI18n("packageLinkLabel", "Package add-to-cart link"),
                    value: buildPackageCartLink(packageIndex),
                    icon: "link",
                    buttonLabel: getAdminI18n("copyLink", "Copy link")
                }
            ],
            {
                heroIcon: "package"
            }
        );
    });

    $panel.on("click", ".wc-license-copy-all-packages", function(e) {
        e.preventDefault();

        openCopyModal(
            getAdminI18n("allPackagesCopyTitle", "Copy all package shortcode"),
            getAdminI18n("allPackagesCopyDescription", "Use this shortcode anywhere to render the full package picker for this product."),
            [
                {
                    label: getAdminI18n("allPackagesShortcodeLabel", "All packages shortcode"),
                    value: buildAllPackagesShortcode(),
                    icon: "layers",
                    buttonLabel: getAdminI18n("copyShortcode", "Copy shortcode")
                }
            ],
            {
                heroIcon: "layers"
            }
        );
    });

    $panel.on("change", "#_is_license_product", syncBuilderState);
    $panel.on("change", ".wc-license-lifetime-toggle", function() {
        syncLifetimeState($(this));
    });
    $panel.on("change", ".wc-license-unlimited-sites-toggle", function() {
        syncUnlimitedSitesState($(this));
    });
    $panel.on("change", ".wc-license-download-mode", function() {
        syncPackageDownloadSections($(this).closest("[data-license-package]"));
    });
    $panel.on("change", ".wc-license-download-choice", function() {
        var $card = $(this).closest("[data-license-package]");
        var $choices = $card.find(".wc-license-download-choice");
        var isSingleMode = $card.find('.wc-license-download-mode:checked').val() === "selected";

        if (isSingleMode && $(this).is(":checked")) {
            $choices.not(this).prop("checked", false);
        }

        if (!$choices.filter(":checked").length) {
            $(this).prop("checked", true);
        }

        syncPackageDownloadSections($card);
    });
    $panel.on("change", 'input[name="license_variation_default"], input[name^="license_variation_recommended"]', refreshPackageStates);

    if ($.fn.sortable) {
        $packageList.sortable({
            items: "[data-license-package]",
            handle: ".wc-license-drag-handle",
            placeholder: "wc-license-package-card wc-license-package-card--placeholder",
            update: syncPackagePositions
        });
    }

    $(document).on("click", ".wc-license-copy-modal", function(e) {
        if ($(e.target).is(".wc-license-copy-modal")) {
            closeCopyModal();
        }
    });

    $(document).on("click", ".wc-license-copy-modal__close", function(e) {
        e.preventDefault();
        closeCopyModal();
    });

    $(document).on("click", ".wc-license-copy-modal__copy", function(e) {
        e.preventDefault();

        var $button = $(this);
        var originalHtml = $button.html();
        var value = $button.attr("data-copy-value") || "";

        copyText(value).then(function() {
            $button.html('<span class="wc-license-copy-modal__copy-icon" aria-hidden="true">' + getInlineIcon("check") + '</span><span class="wc-license-copy-modal__copy-label">' + escapeHtml(getAdminI18n("copied", "Copied")) + "</span>");
            window.setTimeout(function() {
                $button.html(originalHtml);
            }, 1600);
        }).catch(function() {
            window.alert(getAdminI18n("serverError", "Unable to copy right now."));
        });
    });

    $(document).on("keydown", function(e) {
        if (e.key === "Escape") {
            closeCopyModal();
        }
    });

    refreshPackageStates();
    syncBuilderState();
    bindDownloadSync();
});


(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize tabs
        initTabs();
        // Initialize license management actions
        initLicenseActions();
        initManualLicenseFields();
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
            var activationUsage = $(this).data('activation-usage') || (sitesActive + ' / ' + sitesAllowed);
            
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
            detailsTable.append('<tr><th>' + wcLicenseAdmin.i18n.activations + '</th><td>' + activationUsage + '</td></tr>');
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
    
    function initManualLicenseFields() {
        function syncManualSitesField() {
            $('.wc-license-manual-unlimited-toggle').each(function() {
                var $toggle = $(this);
                var target = $toggle.data('target');
                var $input = target ? $(target) : $();

                if (!$input.length) {
                    return;
                }

                var isUnlimited = $toggle.is(':checked');
                $input.prop('disabled', isUnlimited);
                $input.prop('required', !isUnlimited);

                if (!Number($input.val()) || Number($input.val()) < 1) {
                    $input.val(1);
                }
            });
        }

        $(document).on('change', '.wc-license-manual-unlimited-toggle', syncManualSitesField);
        syncManualSitesField();
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
