<?php

/**
 * Plugin Name: WooCommerce Product License Manager
 * Plugin URI: https://wppluginzone.com/woocommerce-product-license-manager
 * Description: Sell and manage licenses for WooCommerce downloadable products
 * Version: 1.0.0
 * Author: wppluginzone
 * Author URI: https://wppluginzone.com/
 * Text Domain: wc-product-license
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p>' . esc_html__('WooCommerce Product License Manager requires WooCommerce to be installed and active.', 'wc-product-license') . '</p></div>';
    });
    return;
}

register_activation_hook(__FILE__, 'your_plugin_activation_function');

function your_plugin_activation_function()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'wc_product_licenses';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            product_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            status varchar(20) NOT NULL,
            sites_allowed int(11) NOT NULL DEFAULT 1,
            sites_active int(11) NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            purchased_at datetime NOT NULL,
            purchased_price decimal(10,2) NOT NULL DEFAULT 0,
            active_sites longtext,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key)
        ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function wc_product_license_get_plugin_status()
{
    $status = get_option('wc_product_license_status', '');

    if ($status === '') {
        $status = get_option('plugincywc_product_license_status', '');
    }

    return is_string($status) ? strtolower($status) : '';
}

function wc_product_license_get_plugin_expiry()
{
    $expiry = get_option('wc_product_license_expiry', '');

    if ($expiry === '') {
        $expiry = get_option('plugincywc_product_license_expiry', '');
    }

    // Older builds used "yes" as a flag, not a date.
    if ($expiry === 'yes') {
        return '';
    }

    return is_string($expiry) ? $expiry : '';
}

function wc_product_license_is_active()
{
    if (wc_product_license_get_plugin_status() !== 'active') {
        return false;
    }

    $license_expiry = wc_product_license_get_plugin_expiry();
    if ($license_expiry === '') {
        return true;
    }

    $expiry_timestamp = strtotime($license_expiry);
    if ($expiry_timestamp === false) {
        return true;
    }

    return $expiry_timestamp >= current_time('timestamp');
}

function wc_product_license_is_unlimited_sites($sites_allowed)
{
    return (int) $sites_allowed <= 0;
}

function wc_product_license_normalize_sites_allowed($sites_allowed, $is_unlimited = false)
{
    if ($is_unlimited) {
        return 0;
    }

    return max(1, absint($sites_allowed));
}

function wc_product_license_get_activation_limit_text($sites_allowed)
{
    if (wc_product_license_is_unlimited_sites($sites_allowed)) {
        return __('Unlimited', 'wc-product-license');
    }

    return number_format_i18n(max(1, absint($sites_allowed)));
}

function wc_product_license_get_activation_usage_text($sites_active, $sites_allowed)
{
    return sprintf(
        /* translators: 1: current active site count, 2: activation limit */
        __('%1$s / %2$s', 'wc-product-license'),
        number_format_i18n(max(0, absint($sites_active))),
        wc_product_license_get_activation_limit_text($sites_allowed)
    );
}

function wc_product_license_get_site_count_text($sites_allowed, $context = 'allowed')
{
    if (wc_product_license_is_unlimited_sites($sites_allowed)) {
        switch ($context) {
            case 'sites':
                return __('Unlimited sites', 'wc-product-license');
            case 'activation':
                return __('Unlimited activations', 'wc-product-license');
            case 'allowed':
            default:
                return __('Unlimited site activations', 'wc-product-license');
        }
    }

    $sites_allowed = max(1, absint($sites_allowed));

    switch ($context) {
        case 'sites':
            return sprintf(_n('%d site', '%d sites', $sites_allowed, 'wc-product-license'), $sites_allowed);
        case 'activation':
            return sprintf(_n('%d activation', '%d activations', $sites_allowed, 'wc-product-license'), $sites_allowed);
        case 'allowed':
        default:
            return sprintf(_n('%d site activation', '%d site activations', $sites_allowed, 'wc-product-license'), $sites_allowed);
    }
}

function wc_product_license_normalize_duration_unit($unit)
{
    $allowed_units = ['day', 'month', 'year'];
    return in_array($unit, $allowed_units, true) ? $unit : 'year';
}

function wc_product_license_convert_duration_to_days($duration_value, $duration_unit)
{
    $duration_value = max(1, (int) $duration_value);
    $duration_unit = wc_product_license_normalize_duration_unit($duration_unit);

    switch ($duration_unit) {
        case 'day':
            return $duration_value;
        case 'month':
            return $duration_value * 30;
        case 'year':
        default:
            return $duration_value * 365;
    }
}

function wc_product_license_get_variation_duration_label($variation)
{
    if (!empty($variation['is_lifetime']) || (isset($variation['validity']) && (int) $variation['validity'] <= 0)) {
        return __('Lifetime', 'wc-product-license');
    }

    if (!empty($variation['duration_value'])) {
        $duration_value = max(1, absint($variation['duration_value']));
        $duration_unit = wc_product_license_normalize_duration_unit(isset($variation['duration_unit']) ? (string) $variation['duration_unit'] : 'year');
    } elseif (!empty($variation['validity'])) {
        $duration_value = max(1, absint($variation['validity']));
        $duration_unit = 'day';
    } else {
        $duration_value = 1;
        $duration_unit = wc_product_license_normalize_duration_unit(isset($variation['duration_unit']) ? (string) $variation['duration_unit'] : 'year');
    }

    switch ($duration_unit) {
        case 'day':
            return sprintf(_n('%d day', '%d days', $duration_value, 'wc-product-license'), $duration_value);
        case 'month':
            return sprintf(_n('%d month', '%d months', $duration_value, 'wc-product-license'), $duration_value);
        case 'year':
        default:
            return sprintf(_n('%d year', '%d years', $duration_value, 'wc-product-license'), $duration_value);
    }
}

function wc_product_license_get_variation_price($variation, $product = null)
{
    if (isset($variation['price']) && $variation['price'] !== '') {
        return $variation['price'];
    }

    if ($product instanceof WC_Product) {
        return $product->get_price();
    }

    return '';
}

class WC_Product_License_Manager
{

    public function __construct()
    {

        if (wc_product_license_is_active()) {
            
        // Product editing
        add_filter('woocommerce_product_data_tabs', [$this, 'add_license_product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_license_product_data_panel']);
        add_action('add_meta_boxes_product', [$this, 'add_license_settings_meta_box']);
        add_action('add_meta_boxes_product', [$this, 'add_download_stats_meta_box']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_product_option']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_variations'], 999);
        add_action('woocommerce_admin_process_product_object', [$this, 'sync_product_object_price_with_license_default'], 999);

        // Order management
        add_action('woocommerce_payment_complete', [$this, 'generate_license_keys']);
        add_action('woocommerce_order_status_processing', [$this, 'generate_license_keys']);
        add_action('woocommerce_order_status_completed', [$this, 'generate_license_keys']);
        add_filter('manage_shop_order_posts_columns', [$this, 'add_license_column_to_orders']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'add_license_column_content'], 10, 2);

        // Order details metabox
        add_action('add_meta_boxes', [$this, 'add_license_metabox_to_orders']);

        // My Account section
        add_filter('woocommerce_account_menu_items', [$this, 'add_license_keys_menu_item']);
        add_action('init', [$this, 'add_license_keys_endpoint']);
        add_action('woocommerce_account_license-keys_endpoint', [$this, 'license_keys_content']);

        // // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_api_endpoints']);
        add_action('rest_api_init', [$this, 'register_tracking_api_endpoints']);

        // // AJAX handlers for activation/deactivation
        add_action('wp_ajax_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_deactivate_license', [$this, 'ajax_deactivate_license']);

        // // Register scripts
        add_action('wp_enqueue_scripts', [$this, 'register_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'register_admin_scripts']);

        // // For HPOS compatibility (High-Performance Order Store)
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [$this, 'handle_custom_query_var'], 10, 2);

        // // Add column to orders list table for HPOS
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_license_column_to_orders_table'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'display_license_column_content'], 20, 2);


        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'get_item_data'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_license_package_selection'], 10, 6);
        add_action('wp_loaded', [$this, 'maybe_redirect_license_package_selection'], 5);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'checkout_create_order_line_item'], 10, 4);

        add_filter('woocommerce_product_add_to_cart_url', [$this, 'get_license_product_add_to_cart_url'], 10, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'render_license_loop_add_to_cart_link'], 10, 3);
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'get_license_loop_add_to_cart_text'], 10, 2);

        // hooks for price modification
        add_filter('woocommerce_add_cart_item_data', [$this, 'modify_cart_item_price'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'set_license_variation_price'], 10, 1);
        add_action('woocommerce_grant_product_download_permissions', [$this, 'restrict_order_download_permissions'], 20);
        add_action('woocommerce_single_product_summary', [$this, 'force_display_license_options'], 1);

        // Add new AJAX handlers
        add_action('wp_ajax_deactivate_all_sites', [$this, 'ajax_deactivate_all_sites']);
        add_action('wp_ajax_delete_license', [$this, 'ajax_delete_license']);
        add_action('wp_ajax_get_upgrade_options', [$this, 'ajax_get_upgrade_options']);

        // Add upgrade processing
        add_action('woocommerce_add_to_cart', [$this, 'process_license_upgrade'], 10, 6);
        }else {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>' . esc_html__('Please activate your license.', 'wc-product-license') . '</p></div>';
            });
        }
    }


    public function add_license_product_data_tab($tabs)
    {
        $tabs['wc_license_product_data'] = [
            'label' => __('Licensing', 'wc-product-license'),
            'target' => 'wc_license_product_data',
            'class' => ['show_if_simple'],
            'priority' => 75,
        ];

        return $tabs;
    }

    /**
     * Save license product option
     */
    public function save_license_product_option($product_id)
    {
        $is_license_product = isset($_POST['_is_license_product']) ? 'yes' : 'no';
        update_post_meta($product_id, '_is_license_product', $is_license_product);
    }

    public function render_license_product_data_panel()
    {
        global $post;

        if (!$post) {
            return;
        }

        $product = wc_get_product($post->ID);
        $is_license_product = get_post_meta($post->ID, '_is_license_product', true) === 'yes';
        $license_variations = $this->get_license_variations($post->ID);

        if (empty($license_variations)) {
            $license_variations = [
                0 => $this->get_empty_license_variation($product, 0),
            ];
        }

        $download_count = $product ? count($product->get_downloads()) : 0;

        echo '<div id="wc_license_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="wc-license-product-panel">';

        echo '<div class="wc-license-panel-hero">';
        echo '<div class="wc-license-panel-hero__content">';
        echo '<h3>' . esc_html__('Sell this product with package-based licensing', 'wc-product-license') . '</h3>';
        echo '<p>' . esc_html__('Create polished pricing packages with activation limits and expiry rules. The default package price is synced to the WooCommerce product price so catalog listings stay accurate.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '<div class="wc-license-panel-hero__meta">';
        echo '<span class="wc-license-pill wc-license-pill--neutral">' . $this->get_admin_icon('package', 'wc-license-pill__icon') . '<span class="wc-license-hero-stat" data-license-package-count="' . esc_attr(count($license_variations)) . '">' . sprintf(esc_html(_n('%d package', '%d packages', count($license_variations), 'wc-product-license')), count($license_variations)) . '</span></span>';
        echo '<span class="wc-license-pill wc-license-pill--neutral">' . $this->get_admin_icon('download', 'wc-license-pill__icon') . '<span class="wc-license-hero-stat" data-license-download-count="' . esc_attr($download_count) . '">' . sprintf(esc_html(_n('%d download file', '%d download files', $download_count, 'wc-product-license')), $download_count) . '</span></span>';
        echo '</div>';
        echo '</div>';

        if ($product && !$product->is_downloadable()) {
            echo '<div class="wc-license-panel-notice wc-license-panel-notice--warning">';
            echo $this->get_admin_icon('alert', 'wc-license-panel-notice__icon');
            echo '<div class="wc-license-panel-notice__content">';
            echo '<strong>' . esc_html__('Download delivery still uses WooCommerce.', 'wc-product-license') . '</strong> ';
            echo esc_html__('Enable the product as downloadable in the General tab to deliver files and license access together.', 'wc-product-license');
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="wc-license-panel-notice wc-license-panel-notice--info">';
        echo $this->get_admin_icon('info', 'wc-license-panel-notice__icon');
        echo '<div class="wc-license-panel-notice__content">';
        echo esc_html__('Package pricing, license rules, and file access are managed here. Actual downloadable files remain in WooCommerce\'s native Downloads tab.', 'wc-product-license');
        echo '</div>';
        echo '</div>';

        echo '<div class="wc-license-product-toggle">';
        echo '<label class="wc-license-switch" for="_is_license_product">';
        echo '<input type="checkbox" id="_is_license_product" name="_is_license_product" value="yes" ' . checked($is_license_product, true, false) . ' />';
        echo '<span class="wc-license-switch__slider" aria-hidden="true"></span>';
        echo '<span class="wc-license-switch__label">';
        echo '<strong>' . esc_html__('Require a license key for this product', 'wc-product-license') . '</strong>';
        echo '<small>' . esc_html__('When enabled, buyers choose a package before checkout and each purchase generates or updates license access.', 'wc-product-license') . '</small>';
        echo '</span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wc-license-package-builder' . ($is_license_product ? '' : ' is-disabled') . '" data-license-builder data-product-id="' . esc_attr($post->ID) . '">';
        echo '<div class="wc-license-package-builder__header">';
        echo '<div>';
        echo '<h4>' . esc_html__('Pricing Packages', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Set your plans exactly once. Buyers will see these options on the product page, in the cart, and during upgrades.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '<div class="wc-license-package-builder__actions">';
        echo '<button type="button" class="button button-secondary wc-license-copy-all-packages">' . $this->get_admin_icon('copy', 'wc-license-button__icon') . '<span>' . esc_html__('Copy', 'wc-product-license') . '</span></button>';
        echo '<button type="button" class="button button-secondary wc-license-add-package" id="wc-license-add-package">' . $this->get_admin_icon('plus', 'wc-license-button__icon') . '<span>' . esc_html__('Add Package', 'wc-product-license') . '</span></button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wc-license-package-list" data-license-package-list>';
        foreach ($license_variations as $index => $variation) {
            $this->render_license_variation_row($variation, $index, $post->ID);
        }
        echo '</div>';
        echo '</div>';

        echo '<script type="text/html" id="tmpl-wc-license-package">';
        $this->render_license_variation_row($this->get_empty_license_variation($product, '__INDEX__'), '__INDEX__', $post->ID);
        echo '</script>';

        echo '</div>';
        echo '</div>';
    }

    public function add_license_settings_meta_box()
    {
        add_meta_box(
            'wc_license_settings',
            __('License Settings', 'wc-product-license'),
            [$this, 'render_license_settings_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public function render_license_settings_meta_box($post)
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        $product = wc_get_product($post->ID);
        $is_license_product = get_post_meta($post->ID, '_is_license_product', true) === 'yes';
        $license_variations = $this->get_license_variations($post->ID);
        $license_settings = $this->get_license_product_settings($post->ID);
        $upgrade_paths = $this->get_license_upgrade_paths($post->ID);
        $preset_key_summary = $this->get_preset_key_inventory_summary($post->ID);
        $download_options = $this->get_download_options_from_product($product);
        $stable_version_label = $license_settings['version'] !== ''
            ? sprintf(__('Stable %s', 'wc-product-license'), $license_settings['version'])
            : __('Stable version not set', 'wc-product-license');
        $upgrade_count = count($upgrade_paths);
        $preset_available_count = (int) $preset_key_summary['available'];

        echo '<div id="wc_license_settings_metabox" class="wc-license-settings-metabox' . ($is_license_product ? '' : ' is-disabled') . '" data-license-settings-builder>';
        echo '<div class="wc-license-settings-metabox__intro">';
        echo '<div class="wc-license-settings-metabox__copy">';
        echo '<span class="wc-license-settings-metabox__eyebrow">' . esc_html__('Advanced delivery controls', 'wc-product-license') . '</span>';
        echo '<h3>' . esc_html__('Manage versions, upgrades, and fulfillment rules', 'wc-product-license') . '</h3>';
        echo '<p>' . esc_html__('Configure stable and beta releases, compatibility requirements, readme data, upgrade routes, and preset key inventory here. Pricing packages remain in the Licensing tab.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '<div class="wc-license-settings-metabox__meta">';
        echo '<span class="wc-license-pill">' . $this->get_admin_icon('release', 'wc-license-pill__icon') . '<span>' . esc_html($stable_version_label) . '</span></span>';
        echo '<span class="wc-license-pill">' . $this->get_admin_icon('upgrade', 'wc-license-pill__icon') . '<span>' . sprintf(esc_html(_n('%d upgrade path', '%d upgrade paths', $upgrade_count, 'wc-product-license')), $upgrade_count) . '</span></span>';
        echo '<span class="wc-license-pill">' . $this->get_admin_icon('key', 'wc-license-pill__icon') . '<span>' . sprintf(esc_html(_n('%d preset key ready', '%d preset keys ready', $preset_available_count, 'wc-product-license')), $preset_available_count) . '</span></span>';
        echo '</div>';
        echo '</div>';

        if (!$is_license_product) {
            echo '<div class="wc-license-panel-notice wc-license-panel-notice--info">';
            echo $this->get_admin_icon('info', 'wc-license-panel-notice__icon');
            echo '<div class="wc-license-panel-notice__content">';
            echo '<strong>' . esc_html__('Licensing is currently off for this product.', 'wc-product-license') . '</strong> ';
            echo esc_html__('Enable licensing in the Licensing tab when you want these release and upgrade settings to apply during checkout and API responses.', 'wc-product-license');
            echo '</div>';
            echo '</div>';
        }

        $settings_sections = $this->get_license_editor_sections();
        unset($settings_sections['packages']);

        echo '<div class="wc-license-editor-workspace wc-license-editor-workspace--metabox">';
        $this->render_license_editor_navigation($settings_sections, 'versions');
        echo '<div class="wc-license-editor-sections wc-license-settings-metabox__sections">';
        $this->render_license_versions_section($license_settings, $download_options);
        $this->render_license_beta_section($license_settings, $download_options);
        $this->render_license_requirements_section($license_settings);
        $this->render_license_readme_section($license_settings);
        $this->render_license_upgrades_section($post->ID, $license_variations, $upgrade_paths);
        $this->render_license_preset_keys_section($license_settings, $preset_key_summary);
        echo '</div>';
        echo '</div>';

        echo '<script type="text/html" id="tmpl-wc-license-upgrade-path">';
        $this->render_license_upgrade_path_row($post->ID, '__INDEX__', $this->get_empty_license_upgrade_path('__INDEX__'));
        echo '</script>';

        echo '</div>';
    }

    private function get_admin_icon($icon, $class = '')
    {
        $icons = [
            'package' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 2.75 3.25 6.25 10 9.75l6.75-3.5L10 2.75Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M3.25 6.25v7.5L10 17.25l6.75-3.5v-7.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 9.75v7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'download' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 3.25v8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m6.75 8.75 3.25 3.25 3.25-3.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 15.25h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'copy' => '<svg viewBox="0 0 20 20" fill="none"><rect x="7" y="4" width="9" height="11" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M5.75 12.5h-.5A2.25 2.25 0 0 1 3 10.25v-6A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'plus' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 4.25v11.5M4.25 10h11.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'info' => '<svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6"/><path d="M10 8v4.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="5.75" r=".9" fill="currentColor"/></svg>',
            'alert' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 3.5 17 15.75H3L10 3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 7.25v3.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="13.4" r=".9" fill="currentColor"/></svg>',
            'trash' => '<svg viewBox="0 0 20 20" fill="none"><path d="M4.75 5.75h10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M7.5 5.75V4.5A1.25 1.25 0 0 1 8.75 3.25h2.5A1.25 1.25 0 0 1 12.5 4.5v1.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m6 5.75.65 8.05A2 2 0 0 0 8.64 15.7h2.72a2 2 0 0 0 1.99-1.9L14 5.75" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
            'release' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 3.25 4.25 6.5v7L10 16.75l5.75-3.25v-7L10 3.25Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.5 10 9 11.5l3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'beta' => '<svg viewBox="0 0 20 20" fill="none"><path d="M7 3.5v13M7 3.5h4.25a3 3 0 0 1 0 6H7m0 0h4.75a3 3 0 0 1 0 6H7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'requirements' => '<svg viewBox="0 0 20 20" fill="none"><path d="M6.25 4.25h7.5A1.75 1.75 0 0 1 15.5 6v8A1.75 1.75 0 0 1 13.75 15.75h-7.5A1.75 1.75 0 0 1 4.5 14V6a1.75 1.75 0 0 1 1.75-1.75Z" stroke="currentColor" stroke-width="1.6"/><path d="M7.5 7.5h5M7.5 10h5M7.5 12.5h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'readme' => '<svg viewBox="0 0 20 20" fill="none"><path d="M5.25 4.25h7.5A2.25 2.25 0 0 1 15 6.5v8.25H7.5a2.25 2.25 0 0 0-2.25 2.25V6.5a2.25 2.25 0 0 1 2.25-2.25Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.75 7.5h4.5M7.75 10h4.5M7.75 12.5h3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'upgrade' => '<svg viewBox="0 0 20 20" fill="none"><path d="M10 15.75V4.25" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="m6.5 7.75 3.5-3.5 3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.25 15.75h11.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
            'key' => '<svg viewBox="0 0 20 20" fill="none"><path d="M11.25 11.25a3.75 3.75 0 1 0-2.5-6.55l-5 5V13h3.3v-1.95H9v-1.8h1.95l.3-.3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="11.25" cy="7.5" r=".9" fill="currentColor"/></svg>',
            'chart' => '<svg viewBox="0 0 20 20" fill="none"><path d="M4.25 15.75V10.5M10 15.75V6.25M15.75 15.75V8.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3.25 15.75h13.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        ];

        if (!isset($icons[$icon])) {
            return '';
        }

        $classes = trim('wc-license-icon ' . $class);

        return '<span class="' . esc_attr($classes) . '" aria-hidden="true">' . $icons[$icon] . '</span>';
    }

    private function get_default_license_product_settings()
    {
        return [
            'version' => '',
            'release_download_id' => '',
            'upgrade_notice' => '',
            'changelog' => '',
            'rollout_enabled' => false,
            'rollout_percentage' => 100,
            'rollout_version_gate_enabled' => false,
            'rollout_min_version' => '',
            'beta_enabled' => false,
            'beta_version' => '',
            'beta_download_id' => '',
            'beta_changelog' => '',
            'requirements' => [
                'php' => '',
                'wp' => '',
                'wc' => '',
            ],
            'readme_source' => 'inline',
            'readme_url' => '',
            'readme_meta' => [
                'tested_up_to' => '',
                'stable_tag' => '',
                'contributors' => '',
                'donate_link' => '',
                'license' => '',
                'homepage' => '',
                'added' => '',
                'banner_high' => '',
                'banner_low' => '',
            ],
            'readme_sections' => [
                'description' => '',
                'installation' => '',
                'faq' => '',
                'changelog' => '',
                'remaining_content' => '',
            ],
            'preset_keys' => [],
            'preset_key_fallback' => true,
        ];
    }

    private function normalize_preset_key_pool($preset_keys)
    {
        if (is_string($preset_keys)) {
            $preset_keys = preg_split('/\r\n|\r|\n/', $preset_keys);
        }

        if (!is_array($preset_keys)) {
            return [];
        }

        $normalized = [];

        foreach ($preset_keys as $preset_key) {
            $preset_key = strtoupper(sanitize_text_field(wp_unslash((string) $preset_key)));
            if ($preset_key === '') {
                continue;
            }

            $normalized[] = $preset_key;
        }

        return array_values(array_unique($normalized));
    }

    private function normalize_license_product_settings($settings)
    {
        $defaults = $this->get_default_license_product_settings();
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args($settings, $defaults);
        $settings['requirements'] = wp_parse_args(is_array($settings['requirements']) ? $settings['requirements'] : [], $defaults['requirements']);
        $settings['readme_meta'] = wp_parse_args(is_array($settings['readme_meta']) ? $settings['readme_meta'] : [], $defaults['readme_meta']);
        $settings['readme_sections'] = wp_parse_args(is_array($settings['readme_sections']) ? $settings['readme_sections'] : [], $defaults['readme_sections']);

        $normalized = $defaults;
        $normalized['version'] = sanitize_text_field((string) $settings['version']);
        $normalized['release_download_id'] = sanitize_text_field((string) $settings['release_download_id']);
        $normalized['upgrade_notice'] = sanitize_textarea_field((string) $settings['upgrade_notice']);
        $normalized['changelog'] = sanitize_textarea_field((string) $settings['changelog']);
        $normalized['rollout_enabled'] = !empty($settings['rollout_enabled']);
        $normalized['rollout_percentage'] = max(1, min(100, absint($settings['rollout_percentage'])));
        $normalized['rollout_version_gate_enabled'] = !empty($settings['rollout_version_gate_enabled']);
        $normalized['rollout_min_version'] = sanitize_text_field((string) $settings['rollout_min_version']);
        $normalized['beta_enabled'] = !empty($settings['beta_enabled']);
        $normalized['beta_version'] = sanitize_text_field((string) $settings['beta_version']);
        $normalized['beta_download_id'] = sanitize_text_field((string) $settings['beta_download_id']);
        $normalized['beta_changelog'] = sanitize_textarea_field((string) $settings['beta_changelog']);
        $normalized['requirements']['php'] = sanitize_text_field((string) $settings['requirements']['php']);
        $normalized['requirements']['wp'] = sanitize_text_field((string) $settings['requirements']['wp']);
        $normalized['requirements']['wc'] = sanitize_text_field((string) $settings['requirements']['wc']);
        $normalized['readme_source'] = $settings['readme_source'] === 'url' ? 'url' : 'inline';
        $normalized['readme_url'] = esc_url_raw((string) $settings['readme_url']);
        $normalized['readme_meta']['tested_up_to'] = sanitize_text_field((string) $settings['readme_meta']['tested_up_to']);
        $normalized['readme_meta']['stable_tag'] = sanitize_text_field((string) $settings['readme_meta']['stable_tag']);
        $normalized['readme_meta']['contributors'] = sanitize_text_field((string) $settings['readme_meta']['contributors']);
        $normalized['readme_meta']['donate_link'] = esc_url_raw((string) $settings['readme_meta']['donate_link']);
        $normalized['readme_meta']['license'] = sanitize_text_field((string) $settings['readme_meta']['license']);
        $normalized['readme_meta']['homepage'] = esc_url_raw((string) $settings['readme_meta']['homepage']);
        $normalized['readme_meta']['added'] = sanitize_text_field((string) $settings['readme_meta']['added']);
        $normalized['readme_meta']['banner_high'] = esc_url_raw((string) $settings['readme_meta']['banner_high']);
        $normalized['readme_meta']['banner_low'] = esc_url_raw((string) $settings['readme_meta']['banner_low']);
        $normalized['readme_sections']['description'] = sanitize_textarea_field((string) $settings['readme_sections']['description']);
        $normalized['readme_sections']['installation'] = sanitize_textarea_field((string) $settings['readme_sections']['installation']);
        $normalized['readme_sections']['faq'] = sanitize_textarea_field((string) $settings['readme_sections']['faq']);
        $normalized['readme_sections']['changelog'] = sanitize_textarea_field((string) $settings['readme_sections']['changelog']);
        $normalized['readme_sections']['remaining_content'] = sanitize_textarea_field((string) $settings['readme_sections']['remaining_content']);
        $normalized['preset_keys'] = $this->normalize_preset_key_pool($settings['preset_keys']);
        $normalized['preset_key_fallback'] = !empty($settings['preset_key_fallback']);

        return $normalized;
    }

    private function get_license_product_settings($product_id)
    {
        return $this->normalize_license_product_settings(get_post_meta($product_id, '_wc_license_product_settings', true));
    }

    private function update_license_product_settings($product_id, $settings)
    {
        $normalized_settings = $this->normalize_license_product_settings($settings);
        update_post_meta($product_id, '_wc_license_product_settings', $normalized_settings);

        return $normalized_settings;
    }

    private function get_license_editor_sections()
    {
        return [
            'packages' => [
                'label' => __('Packages', 'wc-product-license'),
                'icon' => 'package',
            ],
            'versions' => [
                'label' => __('Versions', 'wc-product-license'),
                'icon' => 'release',
            ],
            'betas' => [
                'label' => __('Betas', 'wc-product-license'),
                'icon' => 'beta',
            ],
            'requirements' => [
                'label' => __('Requirements', 'wc-product-license'),
                'icon' => 'requirements',
            ],
            'readme' => [
                'label' => __('Readme', 'wc-product-license'),
                'icon' => 'readme',
            ],
            'upgrades' => [
                'label' => __('Upgrades', 'wc-product-license'),
                'icon' => 'upgrade',
            ],
            'preset-keys' => [
                'label' => __('Preset Keys', 'wc-product-license'),
                'icon' => 'key',
            ],
        ];
    }

    private function render_license_editor_navigation($sections = null, $active_section = 'packages')
    {
        echo '<nav class="wc-license-editor-nav" aria-label="' . esc_attr__('Licensing sections', 'wc-product-license') . '">';

        if (!is_array($sections)) {
            $sections = $this->get_license_editor_sections();
        }

        foreach ($sections as $section_key => $section) {
            $active_class = $section_key === $active_section ? ' is-active' : '';
            echo '<button type="button" class="wc-license-editor-nav__item' . esc_attr($active_class) . '" data-license-section-target="' . esc_attr($section_key) . '">';
            echo $this->get_admin_icon($section['icon'], 'wc-license-editor-nav__icon');
            echo '<span class="wc-license-editor-nav__copy">';
            echo '<strong>' . esc_html($section['label']) . '</strong>';
            echo '</span>';
            echo '</button>';
        }

        echo '</nav>';
    }

    private function get_admin_license_product_map($current_product_id = 0, $current_variations = null)
    {
        $product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_is_license_product',
            'meta_value' => 'yes',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if ($current_product_id > 0 && !in_array($current_product_id, $product_ids, true)) {
            $product_ids[] = $current_product_id;
        }

        $product_map = [];

        foreach ($product_ids as $product_id) {
            $product_id = absint($product_id);
            if ($product_id <= 0) {
                continue;
            }

            $title = get_the_title($product_id);
            if ($title === '') {
                $title = sprintf(__('Product #%d', 'wc-product-license'), $product_id);
            }

            $variations = ($current_product_id > 0 && (int) $product_id === (int) $current_product_id && is_array($current_variations))
                ? $current_variations
                : $this->get_license_variations($product_id);

            $packages = [];
            foreach ($variations as $index => $variation) {
                $variation = $this->normalize_license_variation($variation, $index);
                $packages[] = [
                    'value' => (string) $index,
                    'label' => $variation['title'] !== '' ? $variation['title'] : sprintf(__('Package %s', 'wc-product-license'), $index),
                ];
            }

            $product_map[(string) $product_id] = [
                'id' => $product_id,
                'label' => $title,
                'packages' => $packages,
            ];
        }

        return $product_map;
    }

    private function render_release_download_select($field_name, $selected_download_id, $download_options, $placeholder = '')
    {
        echo '<select name="' . esc_attr($field_name) . '">';
        echo '<option value="">' . esc_html($placeholder !== '' ? $placeholder : __('Choose a downloadable file', 'wc-product-license')) . '</option>';

        foreach ($download_options as $download_id => $download) {
            echo '<option value="' . esc_attr($download_id) . '" ' . selected((string) $selected_download_id, (string) $download_id, false) . '>' . esc_html($download['name']) . '</option>';
        }

        echo '</select>';
    }

    private function render_license_versions_section($settings, $download_options)
    {
        echo '<section class="wc-license-editor-section is-active" data-license-editor-section="versions">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Stable channel', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Version management', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Attach the primary update file, version number, release notes, and rollout rules that licensed clients can consume over the API.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo $this->get_admin_icon('release', 'wc-license-settings-card__icon');
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--two">';
        echo '<div class="wc-license-field"><label>' . esc_html__('Current version', 'wc-product-license') . '</label><input type="text" name="license_settings[version]" value="' . esc_attr($settings['version']) . '" placeholder="' . esc_attr__('1.4.0', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Stable download file', 'wc-product-license') . '</label>';
        $this->render_release_download_select('license_settings[release_download_id]', $settings['release_download_id'], $download_options, __('Use the first WooCommerce download', 'wc-product-license'));
        echo '</div>';
        echo '<div class="wc-license-field wc-license-field--full"><label>' . esc_html__('Upgrade notice', 'wc-product-license') . '</label><textarea name="license_settings[upgrade_notice]" rows="3" placeholder="' . esc_attr__('Highlight migration notes or urgent fixes shown before updating.', 'wc-product-license') . '">' . esc_textarea($settings['upgrade_notice']) . '</textarea></div>';
        echo '<div class="wc-license-field wc-license-field--full"><label>' . esc_html__('Changelog', 'wc-product-license') . '</label><textarea name="license_settings[changelog]" rows="6" placeholder="' . esc_attr__("= 1.4.0 =\n* Added staged upgrades and faster sync logic.", 'wc-product-license') . '">' . esc_textarea($settings['changelog']) . '</textarea></div>';
        echo '</div>';

        echo '<div class="wc-license-settings-subcard">';
        echo '<div class="wc-license-settings-subcard__header"><div><h5>' . esc_html__('Release rollout', 'wc-product-license') . '</h5><p>' . esc_html__('Throttle exposure for new stable releases when you do not want every license to see the update at once.', 'wc-product-license') . '</p></div></div>';
        echo '<div class="wc-license-settings-grid wc-license-settings-grid--three">';
        echo '<label class="wc-license-toggle-field"><input type="checkbox" name="license_settings[rollout_enabled]" value="1" ' . checked($settings['rollout_enabled'], true, false) . ' /><span><strong>' . esc_html__('Enable gradual rollout', 'wc-product-license') . '</strong><small>' . esc_html__('Use a percentage-based gate derived from the license key.', 'wc-product-license') . '</small></span></label>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Exposure percentage', 'wc-product-license') . '</label><input type="number" min="1" max="100" step="1" name="license_settings[rollout_percentage]" value="' . esc_attr($settings['rollout_percentage']) . '" /></div>';
        echo '<label class="wc-license-toggle-field"><input type="checkbox" name="license_settings[rollout_version_gate_enabled]" value="1" ' . checked($settings['rollout_version_gate_enabled'], true, false) . ' /><span><strong>' . esc_html__('Limit by installed version', 'wc-product-license') . '</strong><small>' . esc_html__('Only show the release when the client reports a version at or below the threshold.', 'wc-product-license') . '</small></span></label>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Installed version threshold', 'wc-product-license') . '</label><input type="text" name="license_settings[rollout_min_version]" value="' . esc_attr($settings['rollout_min_version']) . '" placeholder="' . esc_attr__('1.3.0', 'wc-product-license') . '" /></div>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        echo '</section>';
    }

    private function render_license_beta_section($settings, $download_options)
    {
        echo '<section class="wc-license-editor-section" data-license-editor-section="betas">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Optional preview channel', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Beta releases', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Ship an opt-in beta build without replacing the stable download. Beta details are returned separately so your client can decide which channel to install.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo $this->get_admin_icon('beta', 'wc-license-settings-card__icon');
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--two">';
        echo '<label class="wc-license-toggle-field wc-license-field--full"><input type="checkbox" name="license_settings[beta_enabled]" value="1" ' . checked($settings['beta_enabled'], true, false) . ' /><span><strong>' . esc_html__('Enable beta channel', 'wc-product-license') . '</strong><small>' . esc_html__('Beta metadata is exposed alongside the stable release for valid licenses.', 'wc-product-license') . '</small></span></label>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Beta version', 'wc-product-license') . '</label><input type="text" name="license_settings[beta_version]" value="' . esc_attr($settings['beta_version']) . '" placeholder="' . esc_attr__('1.5.0-beta.1', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Beta download file', 'wc-product-license') . '</label>';
        $this->render_release_download_select('license_settings[beta_download_id]', $settings['beta_download_id'], $download_options, __('Choose the beta package', 'wc-product-license'));
        echo '</div>';
        echo '<div class="wc-license-field wc-license-field--full"><label>' . esc_html__('Beta changelog', 'wc-product-license') . '</label><textarea name="license_settings[beta_changelog]" rows="6" placeholder="' . esc_attr__("= 1.5.0-beta.1 =\n* Experimental updater checks.\n* Not recommended for production stores.", 'wc-product-license') . '">' . esc_textarea($settings['beta_changelog']) . '</textarea></div>';
        echo '</div>';

        echo '</div>';
        echo '</section>';
    }

    private function render_license_requirements_section($settings)
    {
        echo '<section class="wc-license-editor-section" data-license-editor-section="requirements">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Compatibility guardrails', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Requirements', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('These minimum versions are returned to licensed clients so they can warn before installation or update.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo $this->get_admin_icon('requirements', 'wc-license-settings-card__icon');
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--three">';
        echo '<div class="wc-license-field"><label>' . esc_html__('Minimum PHP', 'wc-product-license') . '</label><input type="text" name="license_settings[requirements][php]" value="' . esc_attr($settings['requirements']['php']) . '" placeholder="' . esc_attr__('8.0', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Minimum WordPress', 'wc-product-license') . '</label><input type="text" name="license_settings[requirements][wp]" value="' . esc_attr($settings['requirements']['wp']) . '" placeholder="' . esc_attr__('6.5', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Minimum WooCommerce', 'wc-product-license') . '</label><input type="text" name="license_settings[requirements][wc]" value="' . esc_attr($settings['requirements']['wc']) . '" placeholder="' . esc_attr__('9.0', 'wc-product-license') . '" /></div>';
        echo '</div>';

        echo '</div>';
        echo '</section>';
    }

    private function render_license_readme_section($settings)
    {
        echo '<section class="wc-license-editor-section" data-license-editor-section="readme">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Release content', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Readme information', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Capture product metadata and section content that can be returned to update clients or internal tooling without relying on an external marketplace.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo $this->get_admin_icon('readme', 'wc-license-settings-card__icon');
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--two">';
        echo '<div class="wc-license-field"><label>' . esc_html__('Readme source', 'wc-product-license') . '</label><select name="license_settings[readme_source]"><option value="inline" ' . selected($settings['readme_source'], 'inline', false) . '>' . esc_html__('Managed here', 'wc-product-license') . '</option><option value="url" ' . selected($settings['readme_source'], 'url', false) . '>' . esc_html__('External URL', 'wc-product-license') . '</option></select></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Readme URL', 'wc-product-license') . '</label><input type="url" name="license_settings[readme_url]" value="' . esc_attr($settings['readme_url']) . '" placeholder="' . esc_attr__('https://example.com/readme.txt', 'wc-product-license') . '" /></div>';
        echo '</div>';

        echo '<div class="wc-license-settings-subcard">';
        echo '<div class="wc-license-settings-subcard__header"><div><h5>' . esc_html__('Readme metadata', 'wc-product-license') . '</h5><p>' . esc_html__('Store the metadata fields buyers and update clients often need alongside the release package.', 'wc-product-license') . '</p></div></div>';
        echo '<div class="wc-license-settings-grid wc-license-settings-grid--three">';
        echo '<div class="wc-license-field"><label>' . esc_html__('Tested up to', 'wc-product-license') . '</label><input type="text" name="license_settings[readme_meta][tested_up_to]" value="' . esc_attr($settings['readme_meta']['tested_up_to']) . '" placeholder="' . esc_attr__('6.8', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Stable tag', 'wc-product-license') . '</label><input type="text" name="license_settings[readme_meta][stable_tag]" value="' . esc_attr($settings['readme_meta']['stable_tag']) . '" placeholder="' . esc_attr__('1.4.0', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Contributors', 'wc-product-license') . '</label><input type="text" name="license_settings[readme_meta][contributors]" value="' . esc_attr($settings['readme_meta']['contributors']) . '" placeholder="' . esc_attr__('plugincy, support-team', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Donate link', 'wc-product-license') . '</label><input type="url" name="license_settings[readme_meta][donate_link]" value="' . esc_attr($settings['readme_meta']['donate_link']) . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('License label', 'wc-product-license') . '</label><input type="text" name="license_settings[readme_meta][license]" value="' . esc_attr($settings['readme_meta']['license']) . '" placeholder="' . esc_attr__('GPL-2.0-or-later', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Homepage', 'wc-product-license') . '</label><input type="url" name="license_settings[readme_meta][homepage]" value="' . esc_attr($settings['readme_meta']['homepage']) . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Initial release date', 'wc-product-license') . '</label><input type="text" name="license_settings[readme_meta][added]" value="' . esc_attr($settings['readme_meta']['added']) . '" placeholder="' . esc_attr__('2025-11-01', 'wc-product-license') . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Banner high URL', 'wc-product-license') . '</label><input type="url" name="license_settings[readme_meta][banner_high]" value="' . esc_attr($settings['readme_meta']['banner_high']) . '" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Banner low URL', 'wc-product-license') . '</label><input type="url" name="license_settings[readme_meta][banner_low]" value="' . esc_attr($settings['readme_meta']['banner_low']) . '" /></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--two">';
        echo '<div class="wc-license-field"><label>' . esc_html__('Description', 'wc-product-license') . '</label><textarea name="license_settings[readme_sections][description]" rows="7">' . esc_textarea($settings['readme_sections']['description']) . '</textarea></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Installation', 'wc-product-license') . '</label><textarea name="license_settings[readme_sections][installation]" rows="7">' . esc_textarea($settings['readme_sections']['installation']) . '</textarea></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('FAQ', 'wc-product-license') . '</label><textarea name="license_settings[readme_sections][faq]" rows="7">' . esc_textarea($settings['readme_sections']['faq']) . '</textarea></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Readme changelog', 'wc-product-license') . '</label><textarea name="license_settings[readme_sections][changelog]" rows="7">' . esc_textarea($settings['readme_sections']['changelog']) . '</textarea></div>';
        echo '<div class="wc-license-field wc-license-field--full"><label>' . esc_html__('Additional content', 'wc-product-license') . '</label><textarea name="license_settings[readme_sections][remaining_content]" rows="5">' . esc_textarea($settings['readme_sections']['remaining_content']) . '</textarea></div>';
        echo '</div>';

        echo '</div>';
        echo '</section>';
    }

    private function get_empty_license_upgrade_path($index = 0)
    {
        return [
            'index' => $index,
            'source_variation_index' => 'any',
            'target_product_id' => 0,
            'target_variation_index' => 'default',
            'prorate' => true,
            'discount' => '',
            'note' => '',
            'position' => is_numeric($index) ? (int) $index : 0,
        ];
    }

    private function normalize_license_upgrade_path($path, $index)
    {
        $path = wp_parse_args(is_array($path) ? $path : [], $this->get_empty_license_upgrade_path($index));

        return [
            'index' => $index,
            'source_variation_index' => isset($path['source_variation_index']) && $path['source_variation_index'] !== '' ? sanitize_text_field((string) $path['source_variation_index']) : 'any',
            'target_product_id' => absint($path['target_product_id']),
            'target_variation_index' => isset($path['target_variation_index']) && $path['target_variation_index'] !== '' ? sanitize_text_field((string) $path['target_variation_index']) : 'default',
            'prorate' => !empty($path['prorate']),
            'discount' => $path['discount'] !== '' ? wc_format_decimal($path['discount']) : '',
            'note' => sanitize_textarea_field((string) $path['note']),
            'position' => absint($path['position']),
        ];
    }

    private function get_license_upgrade_paths($product_id)
    {
        $raw_paths = get_post_meta($product_id, '_wc_license_upgrade_paths', true);
        if (!is_array($raw_paths)) {
            return [];
        }

        $paths = [];
        foreach ($raw_paths as $index => $path) {
            if (!is_array($path)) {
                continue;
            }

            $normalized_path = $this->normalize_license_upgrade_path($path, $index);
            if ($normalized_path['target_product_id'] <= 0) {
                continue;
            }

            $paths[$index] = $normalized_path;
        }

        uasort($paths, static function ($left, $right) {
            return (int) $left['position'] <=> (int) $right['position'];
        });

        return $paths;
    }

    private function render_upgrade_variation_options($product_map, $target_product_id, $selected_variation_index)
    {
        $product_key = (string) absint($target_product_id);
        $packages = isset($product_map[$product_key]['packages']) ? $product_map[$product_key]['packages'] : [];

        echo '<option value="default"' . selected((string) $selected_variation_index, 'default', false) . '>' . esc_html__('Default package', 'wc-product-license') . '</option>';

        foreach ($packages as $package) {
            echo '<option value="' . esc_attr($package['value']) . '" ' . selected((string) $selected_variation_index, (string) $package['value'], false) . '>' . esc_html($package['label']) . '</option>';
        }
    }

    private function render_license_upgrade_path_row($product_id, $index, $path)
    {
        $path = $this->normalize_license_upgrade_path($path, $index);
        $source_variations = $this->get_license_variations($product_id);
        $product_map = $this->get_admin_license_product_map($product_id, $source_variations);

        echo '<div class="wc-license-upgrade-path" data-license-upgrade-path data-upgrade-path-index="' . esc_attr($index) . '">';
        echo '<input type="hidden" class="wc-license-upgrade-position" name="license_upgrade_position[' . esc_attr($index) . ']" value="' . esc_attr($path['position']) . '" />';
        echo '<div class="wc-license-upgrade-path__header">';
        echo '<div class="wc-license-upgrade-path__title">' . $this->get_admin_icon('upgrade', 'wc-license-upgrade-path__icon') . '<strong>' . esc_html__('Upgrade route', 'wc-product-license') . '</strong></div>';
        echo '<button type="button" class="button-link-delete wc-license-remove-upgrade-path">' . esc_html__('Remove', 'wc-product-license') . '</button>';
        echo '</div>';
        echo '<div class="wc-license-settings-grid wc-license-settings-grid--three">';

        echo '<div class="wc-license-field"><label>' . esc_html__('Eligible current package', 'wc-product-license') . '</label><select class="wc-license-upgrade-source-package" name="license_upgrade_source_variation[' . esc_attr($index) . ']">';
        echo '<option value="any"' . selected($path['source_variation_index'], 'any', false) . '>' . esc_html__('Any package', 'wc-product-license') . '</option>';
        foreach ($source_variations as $variation_index => $variation) {
            $variation = $this->normalize_license_variation($variation, $variation_index);
            echo '<option value="' . esc_attr($variation_index) . '" ' . selected((string) $path['source_variation_index'], (string) $variation_index, false) . '>' . esc_html($variation['title'] !== '' ? $variation['title'] : sprintf(__('Package %s', 'wc-product-license'), $variation_index)) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="wc-license-field"><label>' . esc_html__('Target product', 'wc-product-license') . '</label><select class="wc-license-upgrade-product" name="license_upgrade_target_product[' . esc_attr($index) . ']">';
        echo '<option value="">' . esc_html__('Choose a licensed product', 'wc-product-license') . '</option>';
        foreach ($product_map as $available_product_id => $product_data) {
            echo '<option value="' . esc_attr($available_product_id) . '" ' . selected((string) $path['target_product_id'], (string) $available_product_id, false) . '>' . esc_html($product_data['label']) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="wc-license-field"><label>' . esc_html__('Target package', 'wc-product-license') . '</label><select class="wc-license-upgrade-package" name="license_upgrade_target_variation[' . esc_attr($index) . ']">';
        $this->render_upgrade_variation_options($product_map, $path['target_product_id'], $path['target_variation_index']);
        echo '</select></div>';

        echo '<label class="wc-license-toggle-field"><input type="checkbox" name="license_upgrade_prorate[' . esc_attr($index) . ']" value="1" ' . checked($path['prorate'], true, false) . ' /><span><strong>' . esc_html__('Prorate prior spend', 'wc-product-license') . '</strong><small>' . esc_html__('Subtract what the customer already paid before discounting the target package.', 'wc-product-license') . '</small></span></label>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Extra discount (%)', 'wc-product-license') . '</label><input type="number" min="0" max="100" step="0.01" name="license_upgrade_discount[' . esc_attr($index) . ']" value="' . esc_attr($path['discount']) . '" placeholder="0" /></div>';
        echo '<div class="wc-license-field"><label>' . esc_html__('Route note', 'wc-product-license') . '</label><input type="text" name="license_upgrade_note[' . esc_attr($index) . ']" value="' . esc_attr($path['note']) . '" placeholder="' . esc_attr__('Shown in the upgrade selector to explain who this path is for.', 'wc-product-license') . '" /></div>';

        echo '</div>';
        echo '</div>';
    }

    private function render_license_upgrades_section($product_id, $license_variations, $upgrade_paths)
    {
        echo '<section class="wc-license-editor-section" data-license-editor-section="upgrades">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Upgrade and cross-sell flow', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Upgrade paths', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Create curated upgrade routes. Buyers will only see the paths that match the current package they own, and checkout pricing will honor proration and discounts.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '<div class="wc-license-settings-card__actions">';
        echo '<button type="button" class="button button-secondary wc-license-add-upgrade-path">' . $this->get_admin_icon('plus', 'wc-license-button__icon') . '<span>' . esc_html__('Add upgrade path', 'wc-product-license') . '</span></button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wc-license-upgrade-paths" data-license-upgrade-path-list>';
        if (empty($upgrade_paths)) {
            $upgrade_paths = [0 => $this->get_empty_license_upgrade_path(0)];
        }

        foreach ($upgrade_paths as $index => $path) {
            $this->render_license_upgrade_path_row($product_id, $index, $path);
        }
        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    private function normalize_preset_key_assignments($assignments)
    {
        if (!is_array($assignments)) {
            return [];
        }

        $normalized = [];

        foreach ($assignments as $license_key => $assignment) {
            $license_key = strtoupper(sanitize_text_field((string) $license_key));
            if ($license_key === '') {
                continue;
            }

            $assignment = is_array($assignment) ? $assignment : [];
            $normalized[$license_key] = [
                'assigned_at' => isset($assignment['assigned_at']) ? sanitize_text_field((string) $assignment['assigned_at']) : '',
                'order_id' => isset($assignment['order_id']) ? absint($assignment['order_id']) : 0,
                'order_item_id' => isset($assignment['order_item_id']) ? absint($assignment['order_item_id']) : 0,
            ];
        }

        return $normalized;
    }

    private function get_preset_key_inventory_summary($product_id)
    {
        $available_keys = $this->get_available_preset_keys($product_id, true);
        $assigned = $this->normalize_preset_key_assignments(get_post_meta($product_id, '_wc_license_assigned_preset_keys', true));

        return [
            'available' => count($available_keys),
            'assigned' => count($assigned),
            'total' => count($available_keys) + count($assigned),
        ];
    }

    private function render_license_preset_keys_section($settings, $preset_key_summary)
    {
        echo '<section class="wc-license-editor-section" data-license-editor-section="preset-keys">';
        echo '<div class="wc-license-settings-card">';
        echo '<div class="wc-license-settings-card__header">';
        echo '<div class="wc-license-settings-card__copy">';
        echo '<span class="wc-license-settings-card__eyebrow">' . esc_html__('Pre-generated inventory', 'wc-product-license') . '</span>';
        echo '<h4>' . esc_html__('Preset keys', 'wc-product-license') . '</h4>';
        echo '<p>' . esc_html__('Load your own key inventory for this product. New sales will consume these keys first, and you can decide whether checkout falls back to generated keys when the pool is empty.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo $this->get_admin_icon('key', 'wc-license-settings-card__icon');
        echo '</div>';

        echo '<div class="wc-license-settings-stats">';
        echo '<div class="wc-license-settings-stat"><strong>' . esc_html(number_format_i18n($preset_key_summary['available'])) . '</strong><span>' . esc_html__('Available', 'wc-product-license') . '</span></div>';
        echo '<div class="wc-license-settings-stat"><strong>' . esc_html(number_format_i18n($preset_key_summary['assigned'])) . '</strong><span>' . esc_html__('Assigned', 'wc-product-license') . '</span></div>';
        echo '<div class="wc-license-settings-stat"><strong>' . esc_html(number_format_i18n($preset_key_summary['total'])) . '</strong><span>' . esc_html__('Total tracked', 'wc-product-license') . '</span></div>';
        echo '</div>';

        echo '<div class="wc-license-settings-grid wc-license-settings-grid--two">';
        echo '<label class="wc-license-toggle-field wc-license-field--full"><input type="checkbox" name="license_settings[preset_key_fallback]" value="1" ' . checked($settings['preset_key_fallback'], true, false) . ' /><span><strong>' . esc_html__('Fallback to generated keys when the pool is empty', 'wc-product-license') . '</strong><small>' . esc_html__('Disable this to require preset keys for every new sale.', 'wc-product-license') . '</small></span></label>';
        echo '<div class="wc-license-field wc-license-field--full"><label>' . esc_html__('Available preset keys', 'wc-product-license') . '</label><textarea name="license_settings[preset_keys]" rows="12" placeholder="' . esc_attr__("One key per line.\nABC-123-XYZ\nDEF-456-UVW", 'wc-product-license') . '">' . esc_textarea(implode("\n", $settings['preset_keys'])) . '</textarea></div>';
        echo '</div>';

        echo '</div>';
        echo '</section>';
    }

    public function add_download_stats_meta_box()
    {
        add_meta_box(
            'wc_license_download_stats',
            __('Download Stats', 'wc-product-license'),
            [$this, 'render_download_stats_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    private function get_product_download_stats($product_id)
    {
        global $wpdb;

        $stats = [
            'net_sales' => 0,
            'net_revenue' => 0,
            'download_count' => 0,
        ];

        $order_product_lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $order_stats_table = $wpdb->prefix . 'wc_order_stats';
        $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

        $lookup_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_product_lookup));
        $stats_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_stats_table));
        $permissions_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $permissions_table));

        if ($lookup_exists === $order_product_lookup && $stats_exists === $order_stats_table) {
            $product_sales = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(SUM(opl.product_qty), 0) AS net_sales, COALESCE(SUM(opl.product_net_revenue), 0) AS net_revenue
                FROM {$order_product_lookup} opl
                INNER JOIN {$order_stats_table} os ON os.order_id = opl.order_id
                WHERE opl.product_id = %d
                AND os.status IN ('wc-processing', 'wc-completed')",
                $product_id
            ));

            if ($product_sales) {
                $stats['net_sales'] = (int) $product_sales->net_sales;
                $stats['net_revenue'] = (float) $product_sales->net_revenue;
            }
        }

        if ($permissions_exists === $permissions_table) {
            $stats['download_count'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(download_count), 0) FROM {$permissions_table} WHERE product_id = %d",
                $product_id
            ));
        }

        if (($lookup_exists !== $order_product_lookup || $stats_exists !== $order_stats_table) || ((int) $stats['net_sales'] === 0 && (float) $stats['net_revenue'] === 0.0)) {
            $orders = wc_get_orders([
                'limit' => -1,
                'status' => ['processing', 'completed'],
                'return' => 'objects',
            ]);

            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    if ((int) $item->get_product_id() !== (int) $product_id) {
                        continue;
                    }

                    $stats['net_sales'] += (int) $item->get_quantity();
                    $stats['net_revenue'] += (float) $item->get_total();
                }
            }
        }

        return $stats;
    }

    public function render_download_stats_meta_box($post)
    {
        $product_id = is_object($post) && isset($post->ID) ? absint($post->ID) : 0;
        if ($product_id <= 0) {
            return;
        }

        $stats = $this->get_product_download_stats($product_id);

        echo '<div class="wc-license-download-stats">';
        echo '<div class="wc-license-download-stats__item"><span class="wc-license-download-stats__label">' . esc_html__('Net sales', 'wc-product-license') . '</span><strong>' . esc_html(number_format_i18n($stats['net_sales'])) . '</strong></div>';
        echo '<div class="wc-license-download-stats__item"><span class="wc-license-download-stats__label">' . esc_html__('Net revenue', 'wc-product-license') . '</span><strong>' . wp_kses_post(wc_price($stats['net_revenue'])) . '</strong></div>';
        echo '<div class="wc-license-download-stats__item"><span class="wc-license-download-stats__label">' . esc_html__('Delivered downloads', 'wc-product-license') . '</span><strong>' . esc_html(number_format_i18n($stats['download_count'])) . '</strong></div>';
        echo '<p class="wc-license-download-stats__note">' . esc_html__('Sales are pulled from WooCommerce order lookup tables and download delivery counts are pulled from the native permissions log.', 'wc-product-license') . '</p>';
        echo '</div>';
    }

    private function get_empty_license_variation($product = null, $index = 0)
    {
        $default_price = '';
        if ($product instanceof WC_Product) {
            $default_price = $product->get_regular_price() !== '' ? $product->get_regular_price() : $product->get_price();
        }

        return [
            'index' => $index,
            'title' => '',
            'price' => $default_price,
            'sites' => 1,
            'is_unlimited_sites' => false,
            'validity' => 365,
            'duration_value' => 1,
            'duration_unit' => 'year',
            'is_lifetime' => false,
            'download_mode' => 'all',
            'download_ids' => [],
            'recommended' => false,
            'is_default' => false,
            'description' => '',
            'position' => is_numeric($index) ? (int) $index : 0,
        ];
    }

    private function normalize_duration_unit($unit)
    {
        return wc_product_license_normalize_duration_unit($unit);
    }

    private function convert_license_duration_to_days($duration_value, $duration_unit)
    {
        return wc_product_license_convert_duration_to_days($duration_value, $duration_unit);
    }

    private function get_license_variations($product_id)
    {
        $raw_variations = get_post_meta($product_id, '_license_variations', true);
        if (!is_array($raw_variations)) {
            return [];
        }

        $variations = [];
        foreach ($raw_variations as $index => $variation) {
            if (!is_array($variation)) {
                continue;
            }

            $variations[$index] = $this->normalize_license_variation($variation, $index);
        }

        if (empty($variations)) {
            return [];
        }

        $default_index = $this->get_default_license_variation_index($variations);
        if ($default_index === null) {
            $first_index = array_key_first($variations);
            $variations[$first_index]['is_default'] = true;
        }

        return $variations;
    }

    private function normalize_license_variation($variation, $index)
    {
        $variation = wp_parse_args($variation, [
            'title' => '',
            'price' => '',
            'sites' => 1,
            'is_unlimited_sites' => false,
            'validity' => 365,
            'duration_value' => null,
            'duration_unit' => null,
            'is_lifetime' => false,
            'download_mode' => 'all',
            'download_ids' => [],
            'recommended' => false,
            'is_default' => false,
            'description' => '',
            'position' => $index,
        ]);

        $is_lifetime = !empty($variation['is_lifetime']) || (isset($variation['validity']) && (int) $variation['validity'] <= 0);
        $is_unlimited_sites = !empty($variation['is_unlimited_sites']) || (isset($variation['sites']) && (int) $variation['sites'] <= 0);
        $duration_unit = $this->normalize_duration_unit((string) $variation['duration_unit']);

        if (!empty($variation['duration_value'])) {
            $duration_value = max(1, absint($variation['duration_value']));
        } elseif (!$is_lifetime && !empty($variation['validity'])) {
            $duration_value = max(1, absint($variation['validity']));
            $duration_unit = 'day';
        } else {
            $duration_value = 1;
        }

        $validity = $is_lifetime ? 0 : $this->convert_license_duration_to_days($duration_value, $duration_unit);

        return [
            'index' => $index,
            'title' => sanitize_text_field($variation['title']),
            'price' => $variation['price'] !== '' ? wc_format_decimal($variation['price']) : '',
            'sites' => wc_product_license_normalize_sites_allowed($variation['sites'], $is_unlimited_sites),
            'is_unlimited_sites' => $is_unlimited_sites,
            'validity' => $validity,
            'duration_value' => $duration_value,
            'duration_unit' => $duration_unit,
            'is_lifetime' => $is_lifetime,
            'download_mode' => isset($variation['download_mode']) && $variation['download_mode'] === 'selected' ? 'selected' : 'all',
            'download_ids' => $this->sanitize_download_ids($variation['download_ids'] ?? []),
            'recommended' => !empty($variation['recommended']),
            'is_default' => !empty($variation['is_default']),
            'description' => sanitize_textarea_field($variation['description']),
            'position' => absint($variation['position']),
        ];
    }

    private function get_default_license_variation_index($variations)
    {
        foreach ($variations as $index => $variation) {
            if (!empty($variation['is_default'])) {
                return $index;
            }
        }

        return null;
    }

    private function get_default_license_variation($product_id)
    {
        $variations = $this->get_license_variations($product_id);
        if (empty($variations)) {
            return null;
        }

        $default_index = $this->get_default_license_variation_index($variations);
        if ($default_index !== null && isset($variations[$default_index])) {
            return $variations[$default_index];
        }

        return reset($variations);
    }

    private function is_license_product($product_id)
    {
        return $product_id > 0 && get_post_meta($product_id, '_is_license_product', true) === 'yes';
    }

    private function get_license_package_picker_url($product_id, $query_args = [])
    {
        $url = get_permalink($product_id);
        if (!$url) {
            return '';
        }

        if (!empty($query_args)) {
            $url = add_query_arg($query_args, $url);
        }

        return $url . '#wc-license-package-picker';
    }

    private function should_redirect_license_package_selection($product_id)
    {
        if (
            $product_id <= 0
            || !$this->is_license_product($product_id)
            || $this->get_requested_license_variation($product_id)
            || is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
        ) {
            return false;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return false;
        }

        return true;
    }

    private function should_show_license_package_notice($product_id)
    {
        return isset($_GET['wc_license_package_required']) && absint(wp_unslash($_GET['wc_license_package_required'])) === (int) $product_id;
    }

    private function get_license_variation_duration_label($variation)
    {
        return wc_product_license_get_variation_duration_label($variation);
    }

    private function get_license_variation_price($variation, $product = null)
    {
        return wc_product_license_get_variation_price($variation, $product);
    }

    private function sanitize_download_ids($download_ids)
    {
        if (!is_array($download_ids)) {
            return [];
        }

        $sanitized_ids = [];
        foreach ($download_ids as $download_id) {
            $download_id = sanitize_text_field(wp_unslash((string) $download_id));
            if ($download_id === '') {
                continue;
            }

            $sanitized_ids[] = $download_id;
        }

        return array_values(array_unique($sanitized_ids));
    }

    private function get_download_options_from_product($product = null)
    {
        if (!$product instanceof WC_Product) {
            return [];
        }

        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            return [];
        }

        $download_options = [];
        $position = 0;

        foreach ($downloads as $download_id => $download) {
            $position++;

            if ($download instanceof WC_Product_Download) {
                $download_name = $download->get_name();
                $download_url = $download->get_file();
            } else {
                $download_name = is_array($download) && isset($download['name']) ? (string) $download['name'] : '';
                $download_url = is_array($download) && isset($download['file']) ? (string) $download['file'] : '';
            }

            if ($download_name === '' && $download_url !== '') {
                $download_name = wp_basename(wp_parse_url($download_url, PHP_URL_PATH) ?: $download_url);
            }

            if ($download_name === '') {
                $download_name = sprintf(__('Download file %d', 'wc-product-license'), $position);
            }

            $download_options[(string) $download_id] = [
                'id' => (string) $download_id,
                'name' => $download_name,
                'url' => $download_url,
            ];
        }

        return $download_options;
    }

    private function get_download_options_from_request()
    {
        if (!isset($_POST['_wc_file_hashes']) || !is_array($_POST['_wc_file_hashes'])) {
            return [];
        }

        $file_hashes = array_map(
            static function ($value) {
                return sanitize_text_field(wp_unslash((string) $value));
            },
            $_POST['_wc_file_hashes']
        );

        $file_names = isset($_POST['_wc_file_names']) && is_array($_POST['_wc_file_names'])
            ? array_map(
                static function ($value) {
                    return sanitize_text_field(wp_unslash((string) $value));
                },
                $_POST['_wc_file_names']
            )
            : [];

        $file_urls = isset($_POST['_wc_file_urls']) && is_array($_POST['_wc_file_urls'])
            ? array_map(
                static function ($value) {
                    return esc_url_raw(wp_unslash((string) $value));
                },
                $_POST['_wc_file_urls']
            )
            : [];

        $download_options = [];
        $position = 0;

        foreach ($file_hashes as $row_index => $download_id) {
            if ($download_id === '') {
                continue;
            }

            $position++;
            $download_name = isset($file_names[$row_index]) ? trim($file_names[$row_index]) : '';
            $download_url = isset($file_urls[$row_index]) ? trim($file_urls[$row_index]) : '';

            if ($download_name === '' && $download_url === '') {
                continue;
            }

            if ($download_name === '' && $download_url !== '') {
                $download_name = wp_basename(wp_parse_url($download_url, PHP_URL_PATH) ?: $download_url);
            }

            if ($download_name === '') {
                $download_name = sprintf(__('Download file %d', 'wc-product-license'), $position);
            }

            $download_options[$download_id] = [
                'id' => $download_id,
                'name' => $download_name,
                'url' => $download_url,
            ];
        }

        return $download_options;
    }

    private function get_license_variation_download_ids($variation, $product = null, $available_downloads = null)
    {
        if ($available_downloads === null) {
            $available_downloads = $this->get_download_options_from_product($product);
        }

        $available_ids = array_values(array_map('strval', array_keys($available_downloads)));
        if (empty($available_ids)) {
            return [];
        }

        if (count($available_ids) <= 1) {
            return $available_ids;
        }

        $download_mode = isset($variation['download_mode']) && $variation['download_mode'] === 'selected' ? 'selected' : 'all';
        if ($download_mode !== 'selected') {
            return $available_ids;
        }

        $selected_ids = $this->sanitize_download_ids($variation['download_ids'] ?? []);
        $selected_ids = array_values(array_intersect($selected_ids, $available_ids));

        if (empty($selected_ids)) {
            return [reset($available_ids)];
        }

        return [reset($selected_ids)];
    }

    private function get_license_variation_download_summary($variation, $product = null)
    {
        $available_downloads = $this->get_download_options_from_product($product);
        if (empty($available_downloads)) {
            return '';
        }

        $selected_ids = $this->get_license_variation_download_ids($variation, $product, $available_downloads);
        if (empty($selected_ids)) {
            return '';
        }

        $selected_names = [];
        foreach ($selected_ids as $download_id) {
            if (isset($available_downloads[$download_id]['name'])) {
                $selected_names[] = $available_downloads[$download_id]['name'];
            }
        }

        $available_count = count($available_downloads);
        $selected_count = count($selected_names);

        if ($available_count <= 1 && $selected_count === 1) {
            return sprintf(__('Includes %s', 'wc-product-license'), $selected_names[0]);
        }

        if ($selected_count === $available_count) {
            return sprintf(
                _n('Includes all %d download file', 'Includes all %d download files', $available_count, 'wc-product-license'),
                $available_count
            );
        }

        if ($selected_count === 1) {
            return sprintf(__('Includes %s', 'wc-product-license'), $selected_names[0]);
        }

        if ($selected_count > 1 && $selected_count <= 3) {
            return sprintf(__('Includes %s', 'wc-product-license'), implode(', ', $selected_names));
        }

        return sprintf(
            _n('Includes %d selected download file', 'Includes %d selected download files', $selected_count, 'wc-product-license'),
            $selected_count
        );
    }

    private function render_license_variation_download_access($variation, $index, $available_downloads)
    {
        $download_count = count($available_downloads);
        $download_mode = $download_count > 1 && isset($variation['download_mode']) && $variation['download_mode'] === 'selected'
            ? 'selected'
            : 'all';
        $selected_download_ids = $this->get_license_variation_download_ids($variation, null, $available_downloads);

        echo '<div class="wc-license-package-downloads" data-license-package-downloads data-mode="' . esc_attr($download_mode) . '" data-selected-ids="' . esc_attr(wp_json_encode(array_values($selected_download_ids))) . '">';
        echo '<div class="wc-license-package-downloads__header">';
        echo '<div class="wc-license-package-downloads__heading">';
        echo $this->get_admin_icon('download', 'wc-license-package-downloads__icon');
        echo '<div>';
        echo '<strong>' . esc_html__('Download access', 'wc-product-license') . '</strong>';
        echo '<p>' . esc_html__('Choose which WooCommerce downloads buyers receive with this package.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '</div>';

        if ($download_count > 1) {
            echo '<span class="wc-license-package-downloads__count">' . sprintf(esc_html(_n('%d download file', '%d download files', $download_count, 'wc-product-license')), $download_count) . '</span>';
        }

        echo '</div>';

        if ($download_count > 1) {
            echo '<div class="wc-license-package-downloads__mode">';
            echo '<label class="wc-license-segmented-option">';
            echo '<input type="radio" class="wc-license-download-mode" name="license_variation_download_mode[' . esc_attr($index) . ']" value="all" ' . checked($download_mode, 'all', false) . ' />';
            echo '<span>' . esc_html__('All files', 'wc-product-license') . '</span>';
            echo '</label>';
            echo '<label class="wc-license-segmented-option">';
            echo '<input type="radio" class="wc-license-download-mode" name="license_variation_download_mode[' . esc_attr($index) . ']" value="selected" ' . checked($download_mode, 'selected', false) . ' />';
            echo '<span>' . esc_html__('Single file', 'wc-product-license') . '</span>';
            echo '</label>';
            echo '</div>';

            echo '<div class="wc-license-package-downloads__choices' . ($download_mode === 'selected' ? ' is-active' : '') . '">';
            foreach ($available_downloads as $download_id => $download) {
                $download_meta = '';
                if (!empty($download['url'])) {
                    $download_meta = wp_basename(wp_parse_url($download['url'], PHP_URL_PATH) ?: $download['url']);
                }

                echo '<label class="wc-license-package-downloads__choice">';
                echo '<input type="checkbox" class="wc-license-download-choice" name="license_variation_download_ids[' . esc_attr($index) . '][]" value="' . esc_attr($download_id) . '" ' . checked(in_array((string) $download_id, $selected_download_ids, true), true, false) . ' />';
                echo '<span class="wc-license-package-downloads__choice-copy">';
                echo '<span class="wc-license-package-downloads__choice-title">' . esc_html($download['name']) . '</span>';
                if ($download_meta !== '') {
                    echo '<span class="wc-license-package-downloads__choice-meta">' . esc_html($download_meta) . '</span>';
                }
                echo '</span>';
                echo '</label>';
            }
            echo '</div>';
        } else {
            echo '<input type="hidden" class="wc-license-download-mode-hidden" name="license_variation_download_mode[' . esc_attr($index) . ']" value="all" />';

            if ($download_count === 1) {
                echo '<div class="wc-license-package-downloads__empty"><span class="wc-license-package-downloads__empty-text">' . esc_html__('This package will include the product’s single downloadable file automatically.', 'wc-product-license') . '</span></div>';
            } else {
                echo '<div class="wc-license-package-downloads__empty is-warning"><span class="wc-license-package-downloads__empty-text">' . esc_html__('Add files in WooCommerce’s Downloads tab to map file access per package.', 'wc-product-license') . '</span></div>';
            }
        }

        echo '</div>';
    }

    private function sync_product_price_with_default_license_variation($product_id, $variations)
    {
        $default_index = $this->get_default_license_variation_index($variations);
        if ($default_index === null || empty($variations[$default_index]['price'])) {
            return;
        }

        $default_price = wc_format_decimal($variations[$default_index]['price']);
        update_post_meta($product_id, '_regular_price', $default_price);
        update_post_meta($product_id, '_price', $default_price);
    }

    public function sync_product_object_price_with_license_default($product)
    {
        if (!$product instanceof WC_Product || $product->get_type() !== 'simple') {
            return;
        }

        if (!isset($_POST['_is_license_product']) || !isset($_POST['license_variation_title']) || !is_array($_POST['license_variation_title'])) {
            return;
        }

        $default_index = isset($_POST['license_variation_default']) ? wc_clean(wp_unslash($_POST['license_variation_default'])) : null;
        $fallback_price = null;

        foreach ($_POST['license_variation_title'] as $index => $title) {
            $title = sanitize_text_field(wp_unslash($title));
            if ($title === '') {
                continue;
            }

            $price = isset($_POST['license_variation_price'][$index]) ? wc_format_decimal(wp_unslash($_POST['license_variation_price'][$index])) : '';
            if ($price === '') {
                continue;
            }

            if ($fallback_price === null) {
                $fallback_price = $price;
            }

            if ((string) $default_index === (string) $index) {
                $fallback_price = $price;
                break;
            }
        }

        if ($fallback_price === null || $fallback_price === '') {
            return;
        }

        $product->set_regular_price($fallback_price);
        $product->set_price($fallback_price);
        $product->set_sale_price('');
    }

    /**
     * Render a single license variation row
     */
    private function render_license_variation_row($variation, $index, $product_id = 0)
    {
        $variation = $this->normalize_license_variation($variation, $index);
        $product = $product_id ? wc_get_product($product_id) : null;
        $available_downloads = $this->get_download_options_from_product($product);
        $card_classes = 'wc-license-package-card';
        if (!empty($variation['recommended'])) {
            $card_classes .= ' is-recommended';
        }
        if (!empty($variation['is_default'])) {
            $card_classes .= ' is-default';
        }
        if (!empty($variation['is_lifetime'])) {
            $card_classes .= ' is-lifetime';
        }
        if (!empty($variation['is_unlimited_sites'])) {
            $card_classes .= ' is-unlimited-sites';
        }

        $site_input_value = !empty($variation['is_unlimited_sites']) ? 1 : max(1, absint($variation['sites']));

        echo '<div class="' . esc_attr($card_classes) . '" data-license-package data-package-index="' . esc_attr($index) . '">';
        echo '<input type="hidden" class="wc-license-package-position" name="license_variation_position[' . esc_attr($index) . ']" value="' . esc_attr($variation['position']) . '" />';

        echo '<div class="wc-license-package-card__header">';
        echo '<div class="wc-license-package-card__eyebrow">';
        echo '<span class="wc-license-drag-handle" title="' . esc_attr__('Drag to reorder', 'wc-product-license') . '">::</span>';
        echo '<span class="wc-license-package-card__caption">' . $this->get_admin_icon('package', 'wc-license-package-card__caption-icon') . '<span>' . esc_html__('License package', 'wc-product-license') . '</span></span>';
        if (!empty($variation['recommended'])) {
            echo '<span class="wc-license-pill wc-license-pill--accent">' . esc_html__('Recommended', 'wc-product-license') . '</span>';
        }
        if (!empty($variation['is_default'])) {
            echo '<span class="wc-license-pill wc-license-pill--success">' . esc_html__('Default price', 'wc-product-license') . '</span>';
        }
        echo '</div>';
        echo '<div class="wc-license-package-card__actions">';
        echo '<button type="button" class="button button-secondary wc-license-copy-package" data-product-id="' . esc_attr($product_id) . '">' . $this->get_admin_icon('copy', 'wc-license-button__icon') . '<span>' . esc_html__('Copy', 'wc-product-license') . '</span></button>';
        echo '<button type="button" class="button-link-delete wc-license-remove-package">' . $this->get_admin_icon('trash', 'wc-license-button__icon') . '<span>' . esc_html__('Remove', 'wc-product-license') . '</span></button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="wc-license-package-card__grid">';
        echo '<p class="form-field wc-license-field">';
        echo '<label>' . esc_html__('Package name', 'wc-product-license') . '</label>';
        echo '<input type="text" name="license_variation_title[' . esc_attr($index) . ']" value="' . esc_attr($variation['title']) . '" placeholder="' . esc_attr__('Single Site', 'wc-product-license') . '" />';
        echo '</p>';

        echo '<p class="form-field wc-license-field">';
        echo '<label>' . esc_html__('Price', 'wc-product-license') . '</label>';
        echo '<input type="text" name="license_variation_price[' . esc_attr($index) . ']" value="' . esc_attr($variation['price']) . '" placeholder="' . esc_attr__('79.00', 'wc-product-license') . '" />';
        echo '</p>';

        echo '<p class="form-field wc-license-field">';
        echo '<label>' . esc_html__('Site activations', 'wc-product-license') . '</label>';
        echo '<input type="number" min="1" step="1" class="wc-license-sites-input" name="license_variation_sites[' . esc_attr($index) . ']" value="' . esc_attr($site_input_value) . '"' . disabled(!empty($variation['is_unlimited_sites']), true, false) . ' />';
        echo '</p>';

        echo '<p class="form-field wc-license-field">';
        echo '<label>' . esc_html__('Validity', 'wc-product-license') . '</label>';
        echo '<span class="wc-license-duration-controls">';
        echo '<input type="number" min="1" step="1" class="wc-license-duration-value" name="license_variation_duration_value[' . esc_attr($index) . ']" value="' . esc_attr($variation['duration_value']) . '" />';
        echo '<select class="wc-license-duration-unit" name="license_variation_duration_unit[' . esc_attr($index) . ']">';
        foreach (['day' => __('Days', 'wc-product-license'), 'month' => __('Months', 'wc-product-license'), 'year' => __('Years', 'wc-product-license')] as $unit_value => $unit_label) {
            echo '<option value="' . esc_attr($unit_value) . '" ' . selected($variation['duration_unit'], $unit_value, false) . '>' . esc_html($unit_label) . '</option>';
        }
        echo '</select>';
        echo '</span>';
        echo '</p>';
        echo '</div>';

        echo '<div class="wc-license-field wc-license-field--full">';
        $this->render_license_variation_download_access($variation, $index, $available_downloads);
        echo '</div>';

        echo '<div class="wc-license-package-card__toggles">';
        echo '<label><input type="checkbox" class="wc-license-lifetime-toggle" name="license_variation_lifetime[' . esc_attr($index) . ']" value="1" ' . checked(!empty($variation['is_lifetime']), true, false) . ' /> ' . esc_html__('Lifetime license', 'wc-product-license') . '</label>';
        echo '<label><input type="checkbox" class="wc-license-unlimited-sites-toggle" name="license_variation_unlimited_sites[' . esc_attr($index) . ']" value="1" ' . checked(!empty($variation['is_unlimited_sites']), true, false) . ' /> ' . esc_html__('Unlimited site activations', 'wc-product-license') . '</label>';
        echo '<label><input type="checkbox" name="license_variation_recommended[' . esc_attr($index) . ']" value="1" ' . checked(!empty($variation['recommended']), true, false) . ' /> ' . esc_html__('Highlight as recommended', 'wc-product-license') . '</label>';
        echo '<label><input type="radio" name="license_variation_default" value="' . esc_attr($index) . '" ' . checked(!empty($variation['is_default']), true, false) . ' /> ' . esc_html__('Use as default package', 'wc-product-license') . '</label>';
        echo '</div>';

        echo '<p class="form-field wc-license-field wc-license-field--full">';
        echo '<label>' . esc_html__('Package notes', 'wc-product-license') . '</label>';
        echo '<textarea name="license_variation_description[' . esc_attr($index) . ']" rows="3" placeholder="' . esc_attr__("Add a short summary like 'Best for a solo store owner' or list the benefits included in this package.", 'wc-product-license') . '">' . esc_textarea($variation['description']) . '</textarea>';
        echo '</p>';

        echo '</div>';
    }

    private function save_license_settings_from_request($product_id)
    {
        if (!isset($_POST['license_settings']) || !is_array($_POST['license_settings'])) {
            return $this->get_license_product_settings($product_id);
        }

        $settings = $this->update_license_product_settings($product_id, wp_unslash($_POST['license_settings']));
        $assigned = $this->normalize_preset_key_assignments(get_post_meta($product_id, '_wc_license_assigned_preset_keys', true));

        if (!empty($assigned) && !empty($settings['preset_keys'])) {
            $settings['preset_keys'] = array_values(array_diff($settings['preset_keys'], array_keys($assigned)));
            $settings = $this->update_license_product_settings($product_id, $settings);
        }

        return $settings;
    }

    private function save_license_upgrade_paths_from_request($product_id)
    {
        $paths = [];
        $target_products = isset($_POST['license_upgrade_target_product']) && is_array($_POST['license_upgrade_target_product'])
            ? wp_unslash($_POST['license_upgrade_target_product'])
            : [];

        foreach ($target_products as $index => $target_product_id) {
            $path = [
                'source_variation_index' => isset($_POST['license_upgrade_source_variation'][$index]) ? wp_unslash($_POST['license_upgrade_source_variation'][$index]) : 'any',
                'target_product_id' => $target_product_id,
                'target_variation_index' => isset($_POST['license_upgrade_target_variation'][$index]) ? wp_unslash($_POST['license_upgrade_target_variation'][$index]) : 'default',
                'prorate' => isset($_POST['license_upgrade_prorate'][$index]),
                'discount' => isset($_POST['license_upgrade_discount'][$index]) ? wp_unslash($_POST['license_upgrade_discount'][$index]) : '',
                'note' => isset($_POST['license_upgrade_note'][$index]) ? wp_unslash($_POST['license_upgrade_note'][$index]) : '',
                'position' => isset($_POST['license_upgrade_position'][$index]) ? absint($_POST['license_upgrade_position'][$index]) : absint($index),
            ];

            $normalized_path = $this->normalize_license_upgrade_path($path, $index);
            if ($normalized_path['target_product_id'] <= 0) {
                continue;
            }

            $paths[$index] = $normalized_path;
        }

        update_post_meta($product_id, '_wc_license_upgrade_paths', $paths);
        return $paths;
    }

    /**
     * Save license variations
     */
    public function save_license_variations($product_id)
    {
        $variations = [];
        $default_index = isset($_POST['license_variation_default']) ? wc_clean(wp_unslash($_POST['license_variation_default'])) : null;
        $available_downloads = $this->get_download_options_from_request();
        $available_download_ids = array_values(array_keys($available_downloads));

        if (isset($_POST['license_variation_title']) && is_array($_POST['license_variation_title'])) {
            foreach ($_POST['license_variation_title'] as $index => $title) {
                $title = sanitize_text_field(wp_unslash($title));
                if ($title === '') {
                    continue;
                }

                $is_lifetime = isset($_POST['license_variation_lifetime'][$index]);
                $is_unlimited_sites = isset($_POST['license_variation_unlimited_sites'][$index]);
                $duration_value = isset($_POST['license_variation_duration_value'][$index]) ? max(1, absint($_POST['license_variation_duration_value'][$index])) : 1;
                $duration_unit = isset($_POST['license_variation_duration_unit'][$index]) ? $this->normalize_duration_unit(wc_clean(wp_unslash($_POST['license_variation_duration_unit'][$index]))) : 'year';
                $download_mode = isset($_POST['license_variation_download_mode'][$index]) && wc_clean(wp_unslash($_POST['license_variation_download_mode'][$index])) === 'selected'
                    ? 'selected'
                    : 'all';
                $selected_download_ids = isset($_POST['license_variation_download_ids'][$index]) && is_array($_POST['license_variation_download_ids'][$index])
                    ? $this->sanitize_download_ids($_POST['license_variation_download_ids'][$index])
                    : [];
                $selected_download_ids = array_values(array_intersect($selected_download_ids, $available_download_ids));

                if (count($available_download_ids) <= 1) {
                    $download_mode = 'all';
                    $selected_download_ids = $available_download_ids;
                } elseif ($download_mode === 'selected' && empty($selected_download_ids) && !empty($available_download_ids)) {
                    $selected_download_ids = [reset($available_download_ids)];
                } elseif ($download_mode === 'selected' && !empty($selected_download_ids)) {
                    $selected_download_ids = [reset($selected_download_ids)];
                }

                $variations[$index] = [
                    'title' => $title,
                    'price' => isset($_POST['license_variation_price'][$index]) ? wc_format_decimal(wp_unslash($_POST['license_variation_price'][$index])) : '',
                    'sites' => wc_product_license_normalize_sites_allowed(isset($_POST['license_variation_sites'][$index]) ? wp_unslash($_POST['license_variation_sites'][$index]) : 1, $is_unlimited_sites),
                    'is_unlimited_sites' => $is_unlimited_sites,
                    'validity' => $is_lifetime ? 0 : $this->convert_license_duration_to_days($duration_value, $duration_unit),
                    'duration_value' => $duration_value,
                    'duration_unit' => $duration_unit,
                    'is_lifetime' => $is_lifetime,
                    'download_mode' => $download_mode,
                    'download_ids' => $selected_download_ids,
                    'recommended' => isset($_POST['license_variation_recommended'][$index]),
                    'is_default' => (string) $default_index === (string) $index,
                    'description' => isset($_POST['license_variation_description'][$index]) ? sanitize_textarea_field(wp_unslash($_POST['license_variation_description'][$index])) : '',
                    'position' => isset($_POST['license_variation_position'][$index]) ? absint($_POST['license_variation_position'][$index]) : absint($index),
                ];
            }
        }

        if (empty($variations) && isset($_POST['_is_license_product'])) {
            $product = wc_get_product($product_id);
            $variations[0] = $this->get_empty_license_variation($product, 0);
            $variations[0]['title'] = __('Standard License', 'wc-product-license');
            $variations[0]['is_default'] = true;
        }

        if (!empty($variations)) {
            uasort($variations, function ($left, $right) {
                return (int) $left['position'] <=> (int) $right['position'];
            });

            $normalized_variations = [];
            $default_found = false;
            foreach ($variations as $index => $variation) {
                $normalized_variations[$index] = $this->normalize_license_variation($variation, $index);
                if (!empty($normalized_variations[$index]['is_default'])) {
                    $default_found = true;
                }
            }

            if (!$default_found) {
                $first_index = array_key_first($normalized_variations);
                $normalized_variations[$first_index]['is_default'] = true;
            }

            $variations = $normalized_variations;
        }

        update_post_meta($product_id, '_license_variations', $variations);
        $this->save_license_settings_from_request($product_id);
        $this->save_license_upgrade_paths_from_request($product_id);

        if (get_post_meta($product_id, '_is_license_product', true) === 'yes' && !empty($variations)) {
            $this->sync_product_price_with_default_license_variation($product_id, $variations);
        }
    }

    private function get_existing_license_keys($license_keys)
    {
        global $wpdb;

        $license_keys = $this->normalize_preset_key_pool($license_keys);
        if (empty($license_keys)) {
            return [];
        }

        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $placeholders = implode(', ', array_fill(0, count($license_keys), '%s'));
        $query = "SELECT license_key FROM {$table_name} WHERE license_key IN ({$placeholders})";

        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare($query, $license_keys)));
    }

    private function get_available_preset_keys($product_id, $persist = false)
    {
        $settings = $this->get_license_product_settings($product_id);
        $assigned = $this->normalize_preset_key_assignments(get_post_meta($product_id, '_wc_license_assigned_preset_keys', true));
        $existing_keys = array_flip($this->get_existing_license_keys($settings['preset_keys']));
        $available_keys = [];
        $inventory_changed = false;

        foreach ($settings['preset_keys'] as $preset_key) {
            if (isset($assigned[$preset_key]) || isset($existing_keys[$preset_key])) {
                if (!isset($assigned[$preset_key])) {
                    $assigned[$preset_key] = [
                        'assigned_at' => '',
                        'order_id' => 0,
                        'order_item_id' => 0,
                    ];
                }

                $inventory_changed = true;
                continue;
            }

            $available_keys[] = $preset_key;
        }

        if ($persist && ($inventory_changed || count($available_keys) !== count($settings['preset_keys']))) {
            $settings['preset_keys'] = $available_keys;
            $this->update_license_product_settings($product_id, $settings);
            update_post_meta($product_id, '_wc_license_assigned_preset_keys', $assigned);
        }

        return $available_keys;
    }

    private function product_requires_preset_keys($product_id)
    {
        $settings = $this->get_license_product_settings($product_id);
        return !$settings['preset_key_fallback'];
    }

    private function consume_preset_license_key($product_id, $order_id = 0, $order_item_id = 0)
    {
        $available_keys = $this->get_available_preset_keys($product_id, true);
        if (empty($available_keys)) {
            return '';
        }

        $selected_key = array_shift($available_keys);
        $settings = $this->get_license_product_settings($product_id);
        $assigned = $this->normalize_preset_key_assignments(get_post_meta($product_id, '_wc_license_assigned_preset_keys', true));

        $settings['preset_keys'] = $available_keys;
        $this->update_license_product_settings($product_id, $settings);

        $assigned[$selected_key] = [
            'assigned_at' => current_time('mysql'),
            'order_id' => absint($order_id),
            'order_item_id' => absint($order_item_id),
        ];
        update_post_meta($product_id, '_wc_license_assigned_preset_keys', $assigned);

        return $selected_key;
    }

    private function get_license_order_item_context($license)
    {
        if (!$license || empty($license->order_id)) {
            return null;
        }

        $order = wc_get_order($license->order_id);
        if (!$order) {
            return null;
        }

        foreach ($order->get_items() as $item_id => $item) {
            if (wc_get_order_item_meta($item_id, '_license_key', true) !== $license->license_key) {
                continue;
            }

            $variation = $item->get_meta('_selected_license_variation', true);
            if (!empty($variation)) {
                $variation = $this->normalize_license_variation($variation, $variation['index'] ?? 0);
            } else {
                $variation = $this->get_default_license_variation($license->product_id);
            }

            return [
                'order' => $order,
                'item' => $item,
                'item_id' => $item_id,
                'variation' => $variation,
                'license_data' => $item->get_meta('_license_data', true),
            ];
        }

        return null;
    }

    private function calculate_license_expiry_date($selected_variation)
    {
        if (!empty($selected_variation['is_lifetime']) || (isset($selected_variation['validity']) && (int) $selected_variation['validity'] <= 0)) {
            return null;
        }

        $duration_value = isset($selected_variation['duration_value']) ? max(1, absint($selected_variation['duration_value'])) : max(1, absint($selected_variation['validity']));
        $duration_unit = isset($selected_variation['duration_unit']) ? $this->normalize_duration_unit($selected_variation['duration_unit']) : 'day';
        $base_timestamp = current_time('timestamp');

        switch ($duration_unit) {
            case 'month':
                $expires_at = strtotime('+' . $duration_value . ' months', $base_timestamp);
                break;
            case 'year':
                $expires_at = strtotime('+' . $duration_value . ' years', $base_timestamp);
                break;
            case 'day':
            default:
                $expires_at = strtotime('+' . $duration_value . ' days', $base_timestamp);
                break;
        }

        return $expires_at ? gmdate('Y-m-d H:i:s', $expires_at) : null;
    }

    private function build_license_data($license_key, $product_id, $order, $item, $selected_variation, $key_source = 'generated')
    {
        $active_sites = [];
        $purchased_at = current_time('mysql');
        $sites_allowed = wc_product_license_normalize_sites_allowed($selected_variation['sites'] ?? 1, !empty($selected_variation['is_unlimited_sites']));

        return [
            'key' => $license_key,
            'product_id' => $product_id,
            'order_id' => $order->get_id(),
            'user_id' => $order->get_user_id(),
            'status' => 'active',
            'sites_allowed' => $sites_allowed,
            'sites_active' => 0,
            'activation_limit' => $sites_allowed,
            'expires_at' => $this->calculate_license_expiry_date($selected_variation),
            'purchased_at' => $purchased_at,
            'purchased_price' => $item->get_total(),
            'active_sites' => $active_sites,
            'package_name' => $selected_variation['title'],
            'package_data' => $selected_variation,
            'key_source' => $key_source,
        ];
    }

    private function upgrade_existing_license($license_key, $order, $item, $product_id, $selected_variation)
    {
        global $wpdb;

        $existing_license = $this->get_license_by_key($license_key);
        if (!$existing_license) {
            return false;
        }

        $active_sites = maybe_unserialize($existing_license->active_sites) ?: [];
        $sites_allowed = wc_product_license_normalize_sites_allowed($selected_variation['sites'] ?? 1, !empty($selected_variation['is_unlimited_sites']));
        $expires_at = $this->calculate_license_expiry_date($selected_variation);
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        $updated = $wpdb->update(
            $table_name,
            [
                'product_id' => $product_id,
                'order_id' => $order->get_id(),
                'user_id' => $order->get_user_id(),
                'status' => 'active',
                'sites_allowed' => $sites_allowed,
                'sites_active' => count($active_sites),
                'expires_at' => $expires_at,
                'purchased_at' => current_time('mysql'),
                'purchased_price' => $item->get_total(),
                'active_sites' => maybe_serialize($active_sites),
            ],
            ['license_key' => $license_key]
        );

        return $updated !== false;
    }

    /**
     * Generate license keys when order is processed
     */
    public function generate_license_keys($order_id)
    {
        error_log('Generating license keys for order ID: ' . $order_id);
        if (!$order_id) return;

        $order = wc_get_order($order_id);

        if (!$order) {
            error_log('Order not found: ' . $order_id);
            return;
        }

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product || $product->get_type() !== 'simple' || !$product->is_downloadable() || get_post_meta($product_id, '_is_license_product', true) !== 'yes') {
                continue;
            }

            if (wc_get_order_item_meta($item_id, '_license_key', true)) {
                continue;
            }

            // Get selected license variation
            $selected_variation = $item->get_meta('_selected_license_variation', true);

            if (empty($selected_variation)) {
                $selected_variation = $this->get_default_license_variation($product_id);
            }

            if (empty($selected_variation)) {
                $selected_variation = $this->get_empty_license_variation($product, 0);
                $selected_variation['title'] = __('Standard License', 'wc-product-license');
                $selected_variation['is_default'] = true;
            } else {
                $selected_variation = $this->normalize_license_variation($selected_variation, $selected_variation['index'] ?? 0);
            }

            $upgrade_data = $item->get_meta('_license_upgrade', true);
            if (is_array($upgrade_data) && !empty($upgrade_data['license_key'])) {
                $upgraded = $this->upgrade_existing_license($upgrade_data['license_key'], $order, $item, $product_id, $selected_variation);

                if ($upgraded) {
                    wc_add_order_item_meta($item_id, '_license_key', $upgrade_data['license_key']);
                    wc_add_order_item_meta($item_id, '_license_data', $this->build_license_data($upgrade_data['license_key'], $product_id, $order, $item, $selected_variation, 'existing'));
                    wc_add_order_item_meta($item_id, '_license_key_source', 'existing');
                    wc_add_order_item_meta($item_id, '_license_upgrade_applied', 'yes');
                    continue;
                }
            }

            $license_key = $this->consume_preset_license_key($product_id, $order->get_id(), $item_id);
            $key_source = $license_key !== '' ? 'preset' : 'generated';

            if ($license_key === '') {
                if ($this->product_requires_preset_keys($product_id)) {
                    $order->add_order_note(__('Preset license key inventory was empty during fulfillment, so a generated key was issued to avoid leaving the order without access.', 'wc-product-license'));
                }

                $license_key = $this->generate_unique_license_key();
            }

            // Store license details
            $license_data = $this->build_license_data($license_key, $product_id, $order, $item, $selected_variation, $key_source);

            // Save license to order item meta
            wc_add_order_item_meta($item_id, '_license_key', $license_key);
            wc_add_order_item_meta($item_id, '_license_data', $license_data);
            wc_add_order_item_meta($item_id, '_license_key_source', $key_source);

            // Also store in a custom table for better querying
            $this->store_license_in_db($license_data);
        }
    }

    /**
     * Store license in database
     */
    private function store_license_in_db($license_data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_product_licenses';

        // Insert data
        $wpdb->insert(
            $table_name,
            [
                'license_key' => $license_data['key'],
                'product_id' => $license_data['product_id'],
                'order_id' => $license_data['order_id'],
                'user_id' => $license_data['user_id'],
                'status' => $license_data['status'],
                'sites_allowed' => $license_data['sites_allowed'],
                'sites_active' => $license_data['sites_active'],
                'expires_at' => $license_data['expires_at'],
                'purchased_at' => $license_data['purchased_at'],
                'purchased_price' => $license_data['purchased_price'],
                'active_sites' => maybe_serialize($license_data['active_sites'])
            ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Generate a unique license key
     */
    private function generate_unique_license_key()
    {
        global $wpdb;

        $prefix = apply_filters('wc_license_key_prefix', 'WC-');
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $license_key = $prefix;

        // Generate 4 groups of 4 characters separated by dashes
        for ($group = 0; $group < 4; $group++) {
            if ($group > 0) {
                $license_key .= '-';
            }

            for ($i = 0; $i < 4; $i++) {
                $license_key .= $characters[rand(0, strlen($characters) - 1)];
            }
        }

        // Check if key already exists
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE license_key = %s", $license_key));

        if ($exists) {
            // Recursively try another key
            return $this->generate_unique_license_key();
        }

        return $license_key;
    }

    /**
     * Add license column to orders table
     */
    public function add_license_column_to_orders($columns)
    {
        $new_columns = [];

        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;

            if ($column_name === 'order_status') {
                $new_columns['license_keys'] = __('License Keys', 'wc-product-license');
            }
        }

        return $new_columns;
    }

    /**
     * Add license column content to orders table
     */
    public function add_license_column_content($column, $post_id)
    {
        if ($column === 'license_keys') {
            $order = wc_get_order($post_id);
            if (!$order) return;

            $license_keys = [];

            foreach ($order->get_items() as $item_id => $item) {
                $license_key = wc_get_order_item_meta($item_id, '_license_key', true);
                if ($license_key) {
                    $product_name = $item->get_name();
                    $license_keys[] = $product_name . ': ' . $license_key;
                }
            }

            if (!empty($license_keys)) {
                echo implode('<br>', array_map('esc_html', $license_keys));
            } else {
                echo '-';
            }
        }
    }

    /**
     * Add license column to orders table for HPOS
     */
    public function add_license_column_to_orders_table($columns)
    {
        $new_columns = [];

        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;

            if ($column_name === 'order_status') {
                $new_columns['license_keys'] = __('License Keys', 'wc-product-license');
            }
        }

        return $new_columns;
    }

    /**
     * Display license column content for HPOS
     */
    public function display_license_column_content($column, $order)
    {
        if ($column === 'license_keys') {
            if (!is_a($order, 'WC_Order')) return;

            $license_keys = [];

            foreach ($order->get_items() as $item_id => $item) {
                $license_key = wc_get_order_item_meta($item_id, '_license_key', true);
                if ($license_key) {
                    $license_keys[] = $license_key;
                }
            }

            if (!empty($license_keys)) {
                echo implode('<br>', array_map('esc_html', $license_keys));
            } else {
                echo '-';
            }
        }
    }

    /**
     * Add license metabox to order details
     */
    public function add_license_metabox_to_orders()
    {

        $screen = $this->get_order_screen_id();

        add_meta_box(
            'wc_license_keys',
            __('License Keys', 'wc-product-license'),
            array($this, 'render_license_metabox'),
            $screen,
            'side',
            'high'
        );
    }


    private function get_order_screen_id()
    {
        // Check if we're using the HPOS (High-Performance Order Storage)
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            $controller = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class);

            if (
                method_exists($controller, 'custom_orders_table_usage_is_enabled') &&
                $controller->custom_orders_table_usage_is_enabled()
            ) {
                return wc_get_page_screen_id('shop-order');
            }
        }

        return 'shop_order';
    }

    /**
     * Render license metabox content
     */
    public function render_license_metabox($object)
    {
        $order = is_a($object, 'WP_Post') ? wc_get_order($object->ID) : $object;

        if (!$order) {
            return;
        }

        $has_licenses = false;

        foreach ($order->get_items() as $item_id => $item) {
            $license_key = wc_get_order_item_meta($item_id, '_license_key', true);

            if (!$license_key) continue;

            $has_licenses = true;

            // Use get_license_data to fetch license details
            $license_data = $this->get_license_data(['key' => $license_key]);

            if (is_wp_error($license_data)) {
                echo '<p>' . __('Error fetching license data.', 'wc-product-license') . '</p>';
                continue;
            }

            $license_data = $license_data->get_data();

            echo '<p><strong>' . __('Product:', 'wc-product-license') . '</strong> ' . esc_html($license_data['product_name']) . '</p>';
            echo '<p><strong>' . __('License Key:', 'wc-product-license') . '</strong> ' . esc_html($license_data['license_key']) . '</p>';
            echo '<p><strong>' . __('Status:', 'wc-product-license') . '</strong> ' . esc_html(ucfirst($license_data['status'])) . '</p>';
            echo '<p><strong>' . __('Activations:', 'wc-product-license') . '</strong> ' . esc_html($license_data['activation_usage_label'] ?? wc_product_license_get_activation_usage_text($license_data['sites_active'], $license_data['sites_allowed'])) . '</p>';
            echo '<p><strong>' . __('Expires:', 'wc-product-license') . '</strong> ' . esc_html($license_data['expires_at'] ? date_i18n(get_option('date_format'), strtotime($license_data['expires_at'])) : __('Never', 'wc-product-license')) . '</p>';

            if (!empty($license_data['active_sites'])) {
                echo '<p><strong>' . __('Active Sites:', 'wc-product-license') . '</strong></p>';
                echo '<ul>';
                foreach ($license_data['active_sites'] as $site_url => $activation_date) {
                    echo '<li>' . esc_html($site_url) . ' - ' . __('Activated on', 'wc-product-license') . ' ' . date_i18n(get_option('date_format'), strtotime($activation_date)) . '</li>';
                }
                echo '</ul>';
            }

            echo '<hr>';
        }

        if (!$has_licenses) {
            echo '<p>' . __('No license keys found for this order.', 'wc-product-license') . '</p>';
        }
    }

    /**
     * Add License Keys menu item to My Account
     */
    public function add_license_keys_menu_item($items)
    {
        $items['license-keys'] = __('License Keys', 'wc-product-license');
        return $items;
    }

    /**
     * Add license keys endpoint
     */
    public function add_license_keys_endpoint()
    {
        add_rewrite_endpoint('license-keys', EP_ROOT | EP_PAGES);

        // Check if we need to flush rewrite rules
        if (get_option('wc_license_manager_flush_rewrite_rules', false)) {
            flush_rewrite_rules();
            delete_option('wc_license_manager_flush_rewrite_rules');
        }
    }

    /**
     * My Account license keys content
     */
    public function license_keys_content()
    {
        $user_id = get_current_user_id();

        // Retrieve licenses from the database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY purchased_at DESC",
            $user_id
        ));

        if (empty($licenses)) {
            echo '<p>' . __('You have no license keys yet.', 'wc-product-license') . '</p>';
            return;
        }

        // Enqueue frontend script
        wp_enqueue_script('wc-license-manager-frontend');

        // Enqueue custom CSS for styling
        wp_enqueue_style('wc-license-manager-styles', plugin_dir_url(__FILE__) . 'assets/css/license-manager.css');

        echo '<div class="wc-license-manager-keys">';

        foreach ($licenses as $license) {
            $product = wc_get_product($license->product_id);
            $product_name = $product ? $product->get_name() : __('Unknown Product', 'wc-product-license');
            $active_sites = maybe_unserialize($license->active_sites) ?: [];
            $is_expired = $license->expires_at && strtotime($license->expires_at) < time();
            $status_class = $is_expired ? 'expired' : ($license->status === 'active' ? 'active' : 'inactive');
            $expires = $license->expires_at ? date_i18n(get_option('date_format'), strtotime($license->expires_at)) : __('Never', 'wc-product-license');

            echo '<div class="license-key-item" data-license-key="' . esc_attr($license->license_key) . '">';

            // License header with title and dropdown menu
            echo '<div class="license-header">';
            echo '<h4>' . esc_html($product_name) . '</h4>';

            // 3-dot menu
            echo '<div class="license-actions">';
            echo '<div class="dropdown">';
            echo '<button class="dropdown-toggle"><span class="dot"></span><span class="dot"></span><span class="dot"></span></button>';
            echo '<ul class="dropdown-menu">';

            // Delete option (only for expired licenses)
            if ($is_expired) {
                echo '<li><a href="#" class="delete-license" data-license-key="' . esc_attr($license->license_key) . '">' . __('Delete', 'wc-product-license') . '</a></li>';
            }

            // Deactivate All Sites (only if there are active sites)
            if (!empty($active_sites)) {
                echo '<li><a href="#" class="deactivate-all-sites" data-license-key="' . esc_attr($license->license_key) . '">' . __('Deactivate All Sites', 'wc-product-license') . '</a></li>';
            }

            // Upgrade/Downgrade Package
            echo '<li><a href="#" class="upgrade-license" data-license-key="' . esc_attr($license->license_key) . '" data-product-id="' . esc_attr($license->product_id) . '">' . __('Upgrade/Downgrade Package', 'wc-product-license') . '</a></li>';

            echo '</ul>';
            echo '</div>'; // dropdown
            echo '</div>'; // license-actions
            echo '</div>'; // license-header

            echo '<div class="license-details">';
            echo '<p><strong>' . __('License Key:', 'wc-product-license') . '</strong> <code>' . esc_html($license->license_key) . '</code></p>';
            echo '<p><strong>' . __('Status:', 'wc-product-license') . '</strong> <span class="status-' . esc_attr($status_class) . '">' . esc_html(ucfirst($status_class)) . '</span></p>';
            echo '<p class="license-activations"><strong>' . __('Activations:', 'wc-product-license') . '</strong> ' . esc_html(wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed)) . '</p>';
            echo '<p><strong>' . __('Expires:', 'wc-product-license') . '</strong> ' . esc_html($expires) . '</p>';

            if (!empty($active_sites)) {
                echo '<div class="active-sites">';
                echo '<h5>' . __('Active Sites', 'wc-product-license') . '</h5>';
                echo '<ul>';
                foreach ($active_sites as $site_url => $activation_date) {
                    echo '<li>';
                    echo esc_html($site_url);
                    echo ' <span class="activation-date">(' . __('Activated on', 'wc-product-license') . ' ' . date_i18n(get_option('date_format'), strtotime($activation_date)) . ')</span>';
                    echo ' <a href="#" class="deactivate-site" data-license-key="' . esc_attr($license->license_key) . '" data-site-url="' . esc_attr($site_url) . '">' . __('Deactivate', 'wc-product-license') . '</a>';
                    echo '</li>';
                }
                echo '</ul>';
                echo '</div>';
            }
            echo '</div>'; // license-details
            echo '</div>'; // license-key-item
        }

        echo '</div>'; // wc-license-manager-keys

        // Add success/error message container
        echo '<div id="license-action-message" style="display: none;"></div>';
    }

    /**
     * Add AJAX handler for deactivating all sites for a license
     * Add this method to your WC_Product_License_Manager class
     */
    public function ajax_deactivate_all_sites()
    {
        check_ajax_referer('wc-license-manager', 'nonce');

        $license_key = sanitize_text_field($_POST['license_key']);
        $license = $this->get_license_by_key($license_key);

        if (!$license) {
            wp_send_json_error(['message' => __('License key not found.', 'wc-product-license')]);
        }

        if ($license->user_id != get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
        }

        // Clear all active sites
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->update(
            $table_name,
            [
                'sites_active' => 0,
                'active_sites' => maybe_serialize([])
            ],
            ['license_key' => $license_key]
        );

        wp_send_json_success([
            'message' => __('All sites successfully deactivated.', 'wc-product-license'),
            'sites_active' => 0,
            'sites_allowed' => (int) $license->sites_allowed,
            'activation_usage_label' => wc_product_license_get_activation_usage_text(0, $license->sites_allowed)
        ]);
    }

    /**
     * Add AJAX handler for deleting a license
     * Add this method to your WC_Product_License_Manager class
     */
    public function ajax_delete_license()
    {
        check_ajax_referer('wc-license-manager', 'nonce');

        $license_key = sanitize_text_field($_POST['license_key']);
        $license = $this->get_license_by_key($license_key);

        if (!$license) {
            wp_send_json_error(['message' => __('License key not found.', 'wc-product-license')]);
        }

        if ($license->user_id != get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
        }

        // Check if license is expired
        $is_expired = $license->expires_at && strtotime($license->expires_at) < time();
        if (!$is_expired) {
            wp_send_json_error(['message' => __('Only expired licenses can be deleted.', 'wc-product-license')]);
        }

        // Delete the license
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->delete($table_name, ['license_key' => $license_key]);

        wp_send_json_success([
            'message' => __('License successfully deleted.', 'wc-product-license')
        ]);
    }

    private function get_target_upgrade_variation($target_product_id, $target_variation_index)
    {
        $target_variation_index = (string) $target_variation_index;
        $variations = $this->get_license_variations($target_product_id);
        if (empty($variations)) {
            return null;
        }

        if ($target_variation_index === '' || $target_variation_index === 'default') {
            return $this->get_default_license_variation($target_product_id);
        }

        if (!isset($variations[$target_variation_index])) {
            return null;
        }

        return $variations[$target_variation_index];
    }

    private function calculate_upgrade_pricing($license, $target_variation, $target_product, $upgrade_path = [])
    {
        $target_price = (float) $this->get_license_variation_price($target_variation, $target_product);
        $current_price = $license ? (float) $license->purchased_price : 0.0;
        $credit = !empty($upgrade_path['prorate']) ? min($current_price, $target_price) : 0.0;
        $subtotal = max(0, $target_price - $credit);
        $discount_percent = isset($upgrade_path['discount']) && $upgrade_path['discount'] !== '' ? max(0, min(100, (float) $upgrade_path['discount'])) : 0.0;
        $discount_amount = $discount_percent > 0 ? round(($subtotal * $discount_percent) / 100, wc_get_price_decimals()) : 0.0;
        $final_price = max(0, $subtotal - $discount_amount);

        return [
            'target_price' => $target_price,
            'current_price' => $current_price,
            'credit' => $credit,
            'discount_percent' => $discount_percent,
            'discount_amount' => $discount_amount,
            'final_price' => $final_price,
        ];
    }

    private function get_available_upgrade_options($license, $source_product_id)
    {
        $source_product_id = absint($source_product_id);
        if (!$license || $source_product_id <= 0) {
            return [];
        }

        $license_context = $this->get_license_order_item_context($license);
        $current_variation = isset($license_context['variation']) && is_array($license_context['variation']) ? $license_context['variation'] : null;
        $current_variation_index = $current_variation && isset($current_variation['index']) ? (string) $current_variation['index'] : '';
        $options = [];
        $configured_paths = $this->get_license_upgrade_paths($source_product_id);

        foreach ($configured_paths as $path_index => $upgrade_path) {
            if ($upgrade_path['source_variation_index'] !== 'any' && (string) $upgrade_path['source_variation_index'] !== $current_variation_index) {
                continue;
            }

            $target_product = wc_get_product($upgrade_path['target_product_id']);
            $target_variation = $this->get_target_upgrade_variation($upgrade_path['target_product_id'], $upgrade_path['target_variation_index']);
            if (!$target_product || !$target_variation) {
                continue;
            }

            if ((int) $upgrade_path['target_product_id'] === $source_product_id && $current_variation_index !== '' && (string) $target_variation['index'] === $current_variation_index) {
                continue;
            }

            $pricing = $this->calculate_upgrade_pricing($license, $target_variation, $target_product, $upgrade_path);
            $target_label = (int) $upgrade_path['target_product_id'] === $source_product_id
                ? $target_variation['title']
                : sprintf(__('%1$s - %2$s', 'wc-product-license'), $target_product->get_name(), $target_variation['title']);

            $options[] = [
                'token' => 'path:' . $path_index,
                'source_product_id' => $source_product_id,
                'target_product_id' => (int) $upgrade_path['target_product_id'],
                'target_product_name' => $target_product->get_name(),
                'target_variation' => $target_variation,
                'path' => $upgrade_path,
                'title' => $target_label,
                'description' => $upgrade_path['note'] !== '' ? $upgrade_path['note'] : $target_variation['description'],
                'pricing' => $pricing,
            ];
        }

        if (!empty($options)) {
            return $options;
        }

        $fallback_variations = $this->get_license_variations($source_product_id);
        $source_product = wc_get_product($source_product_id);
        foreach ($fallback_variations as $variation_index => $variation) {
            $variation = $this->normalize_license_variation($variation, $variation_index);
            if ($current_variation_index !== '' && (string) $variation['index'] === $current_variation_index) {
                continue;
            }

            $options[] = [
                'token' => 'legacy:' . $variation_index,
                'source_product_id' => $source_product_id,
                'target_product_id' => $source_product_id,
                'target_product_name' => $source_product ? $source_product->get_name() : '',
                'target_variation' => $variation,
                'path' => [
                    'source_variation_index' => 'any',
                    'target_variation_index' => (string) $variation_index,
                    'prorate' => false,
                    'discount' => '',
                    'note' => '',
                ],
                'title' => $variation['title'],
                'description' => $variation['description'],
                'pricing' => $this->calculate_upgrade_pricing($license, $variation, $source_product, []),
            ];
        }

        return $options;
    }

    private function get_requested_upgrade_context($target_product_id)
    {
        if (!isset($_GET['license_upgrade'], $_GET['upgrade_source'], $_GET['upgrade_path'])) {
            return null;
        }

        $license_key = sanitize_text_field(wp_unslash($_GET['license_upgrade']));
        $source_product_id = absint(wp_unslash($_GET['upgrade_source']));
        $path_token = sanitize_text_field(wp_unslash($_GET['upgrade_path']));
        $license = $this->get_license_by_key($license_key);

        if (!$license || !$source_product_id || ($license->user_id > 0 && (int) $license->user_id !== (int) get_current_user_id())) {
            return null;
        }

        foreach ($this->get_available_upgrade_options($license, $source_product_id) as $upgrade_option) {
            if ($upgrade_option['token'] !== $path_token) {
                continue;
            }

            if ((int) $upgrade_option['target_product_id'] !== (int) $target_product_id) {
                continue;
            }

            $upgrade_option['license_key'] = $license_key;
            $upgrade_option['license'] = $license;
            return $upgrade_option;
        }

        return null;
    }

    /**
     * Add AJAX handler for upgrade/downgrade package
     * Add this method to your WC_Product_License_Manager class
     */
    public function ajax_get_upgrade_options()
    {
        check_ajax_referer('wc-license-manager', 'nonce');

        $license_key = sanitize_text_field($_POST['license_key']);
        $product_id = absint($_POST['product_id']);
        $license = $this->get_license_by_key($license_key);

        if (!$license) {
            wp_send_json_error(['message' => __('License key not found.', 'wc-product-license')]);
        }

        if ($license->user_id != get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
        }

        $upgrade_options = $this->get_available_upgrade_options($license, $product_id);
        if (empty($upgrade_options)) {
            wp_send_json_error(['message' => __('No upgrade options available.', 'wc-product-license')]);
        }

        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : __('Unknown Product', 'wc-product-license');

        // Build HTML for upgrade modal
        $html = '<div class="upgrade-options-modal">';
        $html .= '<h3>' . sprintf(__('Upgrade/Downgrade Options for %s', 'wc-product-license'), esc_html($product_name)) . '</h3>';
        $html .= '<p>' . __('Select a package or product to move this license to. Pricing will respect the configured upgrade rules.', 'wc-product-license') . '</p>';
        $html .= '<div class="upgrade-options-list">';

        $default_upgrade_token = '';
        $first_upgrade_option = reset($upgrade_options);
        if (is_array($first_upgrade_option) && isset($first_upgrade_option['token'])) {
            $default_upgrade_token = (string) $first_upgrade_option['token'];
        }

        foreach ($upgrade_options as $upgrade_option) {
            $target_variation = $upgrade_option['target_variation'];
            $target_product_id = (int) $upgrade_option['target_product_id'];
            $upgrade_url = add_query_arg([
                'add-to-cart' => $target_product_id,
                'license_upgrade' => $license_key,
                'upgrade_source' => $product_id,
                'upgrade_path' => $upgrade_option['token'],
                'license_variation' => $target_variation['index'],
            ], wc_get_page_permalink('cart'));
            $pricing = $upgrade_option['pricing'];
            $meta_bits = [
                wc_product_license_get_site_count_text($target_variation['sites'], 'allowed'),
                $this->get_license_variation_duration_label($target_variation),
            ];
            if ($pricing['credit'] > 0) {
                $meta_bits[] = sprintf(__('Credit %s', 'wc-product-license'), wp_strip_all_tags(wc_price($pricing['credit'])));
            }
            if ($pricing['discount_amount'] > 0) {
                $meta_bits[] = sprintf(__('Discount %s', 'wc-product-license'), wp_strip_all_tags(wc_price($pricing['discount_amount'])));
            }

            $html .= '<div class="upgrade-option">';
            $html .= '<label>';
            $html .= '<input type="radio" name="upgrade_path" value="' . esc_attr($upgrade_option['token']) . '" data-cart-url="' . esc_url($upgrade_url) . '"' . checked((string) $upgrade_option['token'], $default_upgrade_token, false) . '>';
            $html .= '<span class="variation-title">' . esc_html($upgrade_option['title']) . '</span>';
            $html .= '<span class="variation-details">' . esc_html(implode(' | ', array_filter($meta_bits))) . '</span>';
            $html .= '<span class="variation-price">' . sprintf(__('You pay %s', 'wc-product-license'), wp_kses_post(wc_price($pricing['final_price']))) . '</span>';
            if (!empty($upgrade_option['description'])) {
                $html .= '<span class="variation-description">' . esc_html($upgrade_option['description']) . '</span>';
            }
            $html .= '</label>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div class="upgrade-actions">';
        $html .= '<a href="#" class="button cancel-upgrade">' . __('Cancel', 'wc-product-license') . '</a> ';
        $html .= '<a href="#" class="button button-primary confirm-upgrade">' . __('Proceed to Checkout', 'wc-product-license') . '</a>';
        $html .= '</div>';
        $html .= '</div>';

        wp_send_json_success([
            'html' => $html
        ]);
    }

    /**
     * Process license upgrade when adding to cart
     * Add this method to your WC_Product_License_Manager class
     */
    public function process_license_upgrade($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        if (isset(WC()->cart->cart_contents[$cart_item_key]['license_upgrade'])) {
            wc_add_notice(__('License upgrade product added to cart. Complete checkout to upgrade your license.', 'wc-product-license'), 'notice');
        }
    }

    /**
     * Register REST API endpoints
     */
    public function register_rest_api_endpoints()
    {
        register_rest_route('wc-license-manager/v1', '/license/(?P<key>[a-zA-Z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_license_data'],
            'permission_callback' => [$this, 'verify_license_api_permission']
        ]);

        register_rest_route('wc-license-manager/v1', '/license/(?P<key>[a-zA-Z0-9-]+)/activate', [
            'methods' => 'POST',
            'callback' => [$this, 'activate_license'],
            'permission_callback' => [$this, 'verify_license_api_permission']
        ]);

        register_rest_route('wc-license-manager/v1', '/license/(?P<key>[a-zA-Z0-9-]+)/deactivate', [
            'methods' => 'POST',
            'callback' => [$this, 'deactivate_license'],
            'permission_callback' => [$this, 'verify_license_api_permission']
        ]);
    }


    /**
     * Register activation/deactivation tracking REST API endpoints
     */
    public function register_tracking_api_endpoints()
    {
        register_rest_route('wc-license-manager/v1', '/tracking/activate', [
            'methods' => 'POST',
            'callback' => [$this, 'track_activation'],
            'permission_callback' => [$this, 'verify_license_api_permission']
        ]);

        register_rest_route('wc-license-manager/v1', '/tracking/deactivate', [
            'methods' => 'POST',
            'callback' => [$this, 'track_deactivation'],
            'permission_callback' => [$this, 'verify_license_api_permission']
        ]);
    }

    /**
     * Verify API permission
     */
    public function verify_license_api_permission()
    {
        return true; // Allow public access, but you might want to add authentication
    }

    private function get_request_value($request, $key, $default = '')
    {
        if ($request instanceof WP_REST_Request) {
            $value = $request->get_param($key);
            return $value !== null ? $value : $default;
        }

        if (is_array($request) && isset($request[$key])) {
            return $request[$key];
        }

        return $default;
    }

    private function get_release_download_payload($product, $download_id)
    {
        $download_options = $this->get_download_options_from_product($product);
        if (empty($download_options)) {
            return [];
        }

        if ($download_id !== '' && isset($download_options[$download_id])) {
            return $download_options[$download_id];
        }

        return reset($download_options);
    }

    private function get_release_rollout_payload($settings, $license_key, $installed_version = '')
    {
        $payload = [
            'enabled' => !empty($settings['rollout_enabled']),
            'percentage' => (int) $settings['rollout_percentage'],
            'version_gate_enabled' => !empty($settings['rollout_version_gate_enabled']),
            'minimum_installed_version' => $settings['rollout_min_version'],
            'eligible' => true,
        ];

        if (!$payload['enabled']) {
            return $payload;
        }

        $bucket = (abs(crc32((string) $license_key)) % 100) + 1;
        $payload['eligible'] = $bucket <= $payload['percentage'];
        $payload['bucket'] = $bucket;

        if ($payload['version_gate_enabled'] && $payload['minimum_installed_version'] !== '' && $installed_version !== '') {
            $payload['eligible'] = $payload['eligible'] && version_compare((string) $installed_version, $payload['minimum_installed_version'], '<=');
        }

        return $payload;
    }

    private function build_release_payload($product, $settings, $channel, $license_key, $installed_version = '')
    {
        $channel = $channel === 'beta' ? 'beta' : 'stable';
        $is_beta = $channel === 'beta';
        $version = $is_beta ? $settings['beta_version'] : $settings['version'];
        $download_id = $is_beta ? $settings['beta_download_id'] : $settings['release_download_id'];
        $changelog = $is_beta ? $settings['beta_changelog'] : $settings['changelog'];
        $download = $this->get_release_download_payload($product, $download_id);
        $rollout = $is_beta
            ? ['enabled' => false, 'percentage' => 100, 'version_gate_enabled' => false, 'minimum_installed_version' => '', 'eligible' => !empty($settings['beta_enabled'])]
            : $this->get_release_rollout_payload($settings, $license_key, $installed_version);
        $enabled = $is_beta ? !empty($settings['beta_enabled']) : true;
        $available = $enabled && $version !== '' && !empty($download) && !empty($rollout['eligible']);
        $update_available = $available;

        if ($installed_version !== '' && $version !== '') {
            $update_available = version_compare($version, (string) $installed_version, '>');
        }

        return [
            'channel' => $channel,
            'enabled' => $enabled,
            'available' => $available,
            'update_available' => $update_available,
            'version' => $version,
            'download' => $download,
            'download_url' => isset($download['url']) ? $download['url'] : '',
            'download_name' => isset($download['name']) ? $download['name'] : '',
            'changelog' => $changelog,
            'upgrade_notice' => $is_beta ? '' : $settings['upgrade_notice'],
            'rollout' => $rollout,
        ];
    }

    private function build_readme_payload($settings)
    {
        return [
            'source' => $settings['readme_source'],
            'url' => $settings['readme_url'],
            'meta' => $settings['readme_meta'],
            'sections' => $settings['readme_sections'],
        ];
    }

    /**
     * Get license data via API
     */
    public function get_license_data($request)
    {
        $license_key = sanitize_text_field((string) $this->get_request_value($request, 'key', ''));
        $license = $this->get_license_by_key($license_key);

        if (!$license) {
            return new WP_Error('license_not_found', __('License key not found.', 'wc-product-license'), ['status' => 404]);
        }

        $product = wc_get_product($license->product_id);
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        $sites_active = count($active_sites);
        $sites_allowed = (int) $license->sites_allowed;
        $settings = $this->get_license_product_settings($license->product_id);
        $license_context = $this->get_license_order_item_context($license);
        $selected_variation = isset($license_context['variation']) && is_array($license_context['variation'])
            ? $license_context['variation']
            : $this->get_default_license_variation($license->product_id);
        $installed_version = sanitize_text_field((string) $this->get_request_value($request, 'installed_version', ''));
        $requested_channel = sanitize_text_field((string) $this->get_request_value($request, 'channel', 'stable'));
        $stable_release = $this->build_release_payload($product, $settings, 'stable', $license_key, $installed_version);
        $beta_release = $this->build_release_payload($product, $settings, 'beta', $license_key, $installed_version);
        $active_release = $requested_channel === 'beta' ? $beta_release : $stable_release;
        $allowed_download_ids = $selected_variation ? $this->get_license_variation_download_ids($selected_variation, $product) : [];
        $allowed_downloads = [];

        if ($product) {
            $download_options = $this->get_download_options_from_product($product);
            foreach ($allowed_download_ids as $download_id) {
                if (isset($download_options[$download_id])) {
                    $allowed_downloads[] = $download_options[$download_id];
                }
            }
        }

        return rest_ensure_response([
            'success' => true,
            'license_key' => $license->license_key,
            'status' => $license->status,
            'product_id' => $license->product_id,
            'product_name' => $product ? $product->get_name() : '',
            'sites_allowed' => $sites_allowed,
            'sites_active' => $sites_active,
            'sites_allowed_label' => wc_product_license_get_activation_limit_text($sites_allowed),
            'activation_usage_label' => wc_product_license_get_activation_usage_text($sites_active, $sites_allowed),
            'is_unlimited_sites' => wc_product_license_is_unlimited_sites($sites_allowed),
            'expires_at' => $license->expires_at,
            'active_sites' => $active_sites,
            'package' => [
                'name' => $selected_variation ? $selected_variation['title'] : '',
                'index' => $selected_variation ? $selected_variation['index'] : '',
                'sites_allowed' => $selected_variation ? $selected_variation['sites'] : $sites_allowed,
                'validity' => $selected_variation ? $this->get_license_variation_duration_label($selected_variation) : '',
                'downloads' => $allowed_downloads,
                'downloads_summary' => $selected_variation ? $this->get_license_variation_download_summary($selected_variation, $product) : '',
            ],
            'requirements' => $settings['requirements'],
            'readme' => $this->build_readme_payload($settings),
            'release_channel' => $active_release,
            'releases' => [
                'stable' => $stable_release,
                'beta' => $beta_release,
            ],
        ]);
    }

    public function ajax_activate_license()
    {
        check_ajax_referer('wc-license-manager', 'nonce');

        $license_key = sanitize_text_field($_POST['license_key']);
        $site_url = esc_url_raw($_POST['site_url']);

        $license = $this->get_license_by_key($license_key);
        if (!$license) {
            wp_send_json_error(['message' => __('License key not found.', 'wc-product-license')]);
        }

        if ($license->user_id != get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
        }

        if ($license->status !== 'active') {
            wp_send_json_error(['message' => __('This license key is not active.', 'wc-product-license')]);
        }

        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        if (!wc_product_license_is_unlimited_sites($license->sites_allowed) && count($active_sites) >= (int) $license->sites_allowed) {
            wp_send_json_error(['message' => __('You have reached the maximum number of activations for this license.', 'wc-product-license')]);
        }

        // Check if site is already activated
        if (isset($active_sites[$site_url])) {
            wp_send_json_error(['message' => __('This site is already activated with this license.', 'wc-product-license')]);
        }

        // Add site to active sites
        $active_sites[$site_url] = current_time('mysql');

        // Update license in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->update(
            $table_name,
            [
                'sites_active' => count($active_sites),
                'active_sites' => maybe_serialize($active_sites)
            ],
            ['license_key' => $license_key]
        );

        wp_send_json_success([
            'message' => __('License successfully activated for this site.', 'wc-product-license'),
            'active_sites' => $active_sites,
            'sites_active' => count($active_sites),
            'sites_allowed' => (int) $license->sites_allowed,
            'activation_usage_label' => wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed)
        ]);
    }

    /**
     * AJAX handler for license deactivation
     */
    public function ajax_deactivate_license()
    {
        check_ajax_referer('wc-license-manager', 'nonce');

        $license_key = sanitize_text_field($_POST['license_key']);
        $site_url = esc_url_raw($_POST['site_url']);

        $license = $this->get_license_by_key($license_key);
        if (!$license) {
            wp_send_json_error(['message' => __('License key not found.', 'wc-product-license')]);
        }

        if ($license->user_id != get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key. ', 'wc-product-license')]);
        }

        // Remove site from active sites
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        if (!isset($active_sites[$site_url])) {
            wp_send_json_error(['message' => __('This site is not activated with this license.', 'wc-product-license')]);
        }

        unset($active_sites[$site_url]);

        // Update license in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->update(
            $table_name,
            [
                'sites_active' => count($active_sites),
                'active_sites' => maybe_serialize($active_sites)
            ],
            ['license_key' => $license_key]
        );

        wp_send_json_success([
            'message' => __('License successfully deactivated for this site.', 'wc-product-license'),
            'active_sites' => $active_sites,
            'sites_active' => count($active_sites),
            'sites_allowed' => (int) $license->sites_allowed,
            'activation_usage_label' => wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed)
        ]);
    }


    /**
     * Track site activation data
     */
    public function track_activation($request)
    {
        $params = $request->get_params();

        // Required fields
        $site_url = isset($params['site_url']) ? esc_url_raw($params['site_url']) : '';
        $product_name = isset($params['product_name']) ? sanitize_text_field($params['product_name']) : '';
        $activation_status = isset($params['activation_status']) ? sanitize_text_field($params['activation_status']) : 'yes';
        $is_multisite = isset($params['multisite']) ? sanitize_text_field($params['multisite']) : 'no';
        $wp_version = isset($params['wordpress_version']) ? sanitize_text_field($params['wordpress_version']) : '';
        $php_version = isset($params['php_version']) ? sanitize_text_field($params['php_version']) : '';
        $server_software = isset($params['server_software']) ? sanitize_text_field($params['server_software']) : '';
        $mysql_version = isset($params['mysql_version']) ? sanitize_text_field($params['mysql_version']) : '';

        // Validate required fields
        if (empty($site_url) || $activation_status !== 'yes') {
            return new WP_Error('missing_fields', __('Required fields are missing.', 'wc-product-license'), ['status' => 400]);
        }

        // Store activation data
        $tracking_data = [
            'site_url' => $site_url,
            'product_name' => $product_name,
            'activation_status' => $activation_status,
            'multisite' => $is_multisite,
            'wordpress_version' => $wp_version,
            'php_version' => $php_version,
            'server_software' => $server_software,
            'mysql_version' => $mysql_version,
            'timestamp' => current_time('mysql')
        ];

        // Get existing tracking data
        $existing_data = get_option('wc_license_activation_tracking', []);

        // Add or update site data
        $existing_data[$site_url] = $tracking_data;

        // Save updated tracking data
        update_option('wc_license_activation_tracking', $existing_data);

        return rest_ensure_response([
            'success' => true,
            'message' => __('Activation data recorded successfully.', 'wc-product-license')
        ]);
    }

    /**
     * Track site deactivation data
     */
    public function track_deactivation($request)
    {
        $params = $request->get_params();

        // Required fields
        $site_url = isset($params['site_url']) ? esc_url_raw($params['site_url']) : '';
        $product_name = isset($params['product_name']) ? sanitize_text_field($params['product_name']) : '';
        $activation_status = isset($params['activation_status']) ? sanitize_text_field($params['activation_status']) : 'no';
        $deactivation_reason = isset($params['deactivation_reason']) ? sanitize_text_field($params['deactivation_reason']) : 'without reason';

        // Validate required fields
        if (empty($site_url) || $activation_status !== 'no' || empty($deactivation_reason)) {
            return new WP_Error('missing_fields', __('Required fields are missing.', 'wc-product-license'), ['status' => 400]);
        }

        // Store deactivation data
        $deactivation_data = [
            'site_url' => $site_url,
            'product_name' => $product_name,
            'activation_status' => $activation_status,
            'deactivation_reason' => $deactivation_reason,
            'timestamp' => current_time('mysql')
        ];

        // Get existing deactivation data
        $existing_data = get_option('wc_license_deactivation_tracking', []);

        // Add deactivation record
        $existing_data[$site_url] = $deactivation_data;

        // Save updated tracking data
        update_option('wc_license_deactivation_tracking', $existing_data);

        // Also update the activation tracking to reflect deactivation
        $activation_data = get_option('wc_license_activation_tracking', []);
        if (isset($activation_data[$site_url])) {
            unset($activation_data[$site_url]);
            update_option('wc_license_activation_tracking', $activation_data);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __('Deactivation data recorded successfully.', 'wc-product-license')
        ]);
    }

    /**
     * Register scripts
     */
    public function register_scripts()
    {
        wp_register_style(
            'wc-license-manager-styles',
            plugin_dir_url(__FILE__) . 'assets/css/license-manager.css',
            [],
            '1.0.0'
        );

        wp_register_script(
            'wc-license-manager-frontend',
            plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wc-license-manager-frontend', 'wcLicenseManager', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-license-manager'),
            'i18n' => [
                'activating' => __('Activating...', 'wc-product-license'),
                'deactivating' => __('Deactivating...', 'wc-product-license'),
                'deactivatingAll' => __('Deactivating all sites...', 'wc-product-license'),
                'deleting' => __('Deleting...', 'wc-product-license'),
                'confirmDeactivateAll' => __('Are you sure you want to deactivate all sites?', 'wc-product-license'),
                'confirmDelete' => __('Are you sure you want to delete this license?', 'wc-product-license')
            ]
        ]);

        if (function_exists('is_woocommerce') && is_woocommerce()) {
            wp_enqueue_style('wc-license-manager-styles');
            wp_enqueue_script('wc-license-manager-frontend');
        }
    }

    /**
     * Register admin scripts
     */
    public function register_admin_scripts($hook)
    {
        if ($hook == 'post.php' || $hook == 'post-new.php') {
            $current_product_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
            $current_variations = $current_product_id ? $this->get_license_variations($current_product_id) : [];
            $product_map = $this->get_admin_license_product_map($current_product_id, $current_variations);

            wp_enqueue_style(
                'wc-license-manager-admin',
                plugin_dir_url(__FILE__) . 'assets/css/admin.css',
                [],
                '1.0.0'
            );

            wp_enqueue_script(
                'wc-license-manager-admin',
                plugin_dir_url(__FILE__) . 'assets/js/admin.js',
                ['jquery', 'jquery-ui-sortable'],
                '1.0.0',
                true
            );

            wp_localize_script('wc-license-manager-admin', 'wcLicenseAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'cartUrl' => wc_get_cart_url(),
                'currentProductId' => $current_product_id,
                'licenseProducts' => $product_map,
                'nonce' => wp_create_nonce('wc-license-admin-nonce'),
                'i18n' => [
                    'addPackage' => __('Add package', 'wc-product-license'),
                    'defaultPackageName' => __('New Package', 'wc-product-license'),
                    'removePackage' => __('Remove package', 'wc-product-license'),
                    'packageRequired' => __('At least one package is required for licensed products.', 'wc-product-license'),
                    'copy' => __('Copy', 'wc-product-license'),
                    'copied' => __('Copied', 'wc-product-license'),
                    'close' => __('Close', 'wc-product-license'),
                    'allPackagesCopyTitle' => __('Copy all package shortcode', 'wc-product-license'),
                    'allPackagesCopyDescription' => __('Use this shortcode anywhere to render the full package picker for this product.', 'wc-product-license'),
                    'allPackagesShortcodeLabel' => __('All packages shortcode', 'wc-product-license'),
                    'packageCopyTitle' => __('Copy package shortcode and link', 'wc-product-license'),
                    'packageCopyDescription' => __('Use these snippets to sell this exact package outside the product page.', 'wc-product-license'),
                    'packageShortcodeLabel' => __('Specific package shortcode', 'wc-product-license'),
                    'packageLinkLabel' => __('Package add-to-cart link', 'wc-product-license'),
                    'copyPopupEyebrow' => __('Portable snippets', 'wc-product-license'),
                    'copyPopupFooter' => __('These snippets are ready to use with the current product and package setup.', 'wc-product-license'),
                    'packageSingular' => __('package', 'wc-product-license'),
                    'packagePlural' => __('packages', 'wc-product-license'),
                    'downloadSingular' => __('download file', 'wc-product-license'),
                    'downloadPlural' => __('download files', 'wc-product-license'),
                    'downloadFileFallback' => __('Download file', 'wc-product-license'),
                    'downloadAccessTitle' => __('Download access', 'wc-product-license'),
                    'downloadAccessDescription' => __('Choose which WooCommerce downloads buyers receive with this package.', 'wc-product-license'),
                    'allFiles' => __('All files', 'wc-product-license'),
                    'selectedFiles' => __('Single file', 'wc-product-license'),
                    'singleDownloadAuto' => __('This package will include the product’s single downloadable file automatically.', 'wc-product-license'),
                    'noDownloadsMessage' => __('Add files in WooCommerce’s Downloads tab to map file access per package.', 'wc-product-license'),
                    'copyShortcode' => __('Copy shortcode', 'wc-product-license'),
                    'copyLink' => __('Copy link', 'wc-product-license'),
                    'addUpgradePath' => __('Add upgrade path', 'wc-product-license'),
                    'anyPackage' => __('Any package', 'wc-product-license'),
                    'defaultPackage' => __('Default package', 'wc-product-license'),
                    'chooseLicensedProduct' => __('Choose a licensed product', 'wc-product-license'),
                    'upgradePathTitle' => __('Upgrade route', 'wc-product-license'),
                    'upgradePathDescription' => __('Shown in the upgrade selector to explain who this path is for.', 'wc-product-license'),
                    'sectionPackages' => __('Packages', 'wc-product-license'),
                    'sectionVersions' => __('Versions', 'wc-product-license'),
                    'sectionBetas' => __('Betas', 'wc-product-license'),
                    'sectionRequirements' => __('Requirements', 'wc-product-license'),
                    'sectionReadme' => __('Readme', 'wc-product-license'),
                    'sectionUpgrades' => __('Upgrades', 'wc-product-license'),
                    'sectionPresetKeys' => __('Preset Keys', 'wc-product-license')
                ]
            ]);
        }
    }

    /**
     * Get license by key
     */
    private function get_license_by_key($license_key)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE license_key = %s",
            $license_key
        ));
    }

    /**
     * Activate license via API
     */
    public function activate_license($request)
    {
        $license_key = sanitize_text_field($request['key']);
        $site_url = isset($request['site_url']) ? esc_url_raw($request['site_url']) : '';

        if (empty($site_url)) {
            return new WP_Error('missing_site_url', __('Site URL is required.', 'wc-product-license'), ['status' => 400]);
        }

        $license = $this->get_license_by_key($license_key);
        if (!$license) {
            return new WP_Error('license_not_found', __('License key not found.', 'wc-product-license'), ['status' => 404]);
        }

        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        if (isset($active_sites[$site_url])) {
            return rest_ensure_response([
                'success' => true,
                'message' => __('License successfully activated.', 'wc-product-license'),
                'sites_active' => count($active_sites),
                'sites_allowed' => (int) $license->sites_allowed,
                'sites_allowed_label' => wc_product_license_get_activation_limit_text($license->sites_allowed),
                'activation_usage_label' => wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed),
                'active_sites' => $active_sites
            ]);
        }

        if ($license->status !== 'active') {
            return new WP_Error('license_inactive', __('This license key is not active.', 'wc-product-license'), ['status' => 400]);
        }

        if (!wc_product_license_is_unlimited_sites($license->sites_allowed) && count($active_sites) >= (int) $license->sites_allowed) {
            return new WP_Error('max_activations', __('Maximum activations reached for this license.', 'wc-product-license'), ['status' => 400]);
        }

        // Check if expired
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            return new WP_Error('license_expired', __('This license key has expired.', 'wc-product-license'), ['status' => 400]);
        }

        // Add site to active sites
        $active_sites[$site_url] = current_time('mysql');

        // Update license in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->update(
            $table_name,
            [
                'sites_active' => count($active_sites),
                'active_sites' => maybe_serialize($active_sites)
            ],
            ['license_key' => $license_key]
        );

        return rest_ensure_response([
            'success' => true,
            'message' => __('License successfully activated.', 'wc-product-license'),
            'sites_active' => count($active_sites),
            'sites_allowed' => (int) $license->sites_allowed,
            'sites_allowed_label' => wc_product_license_get_activation_limit_text($license->sites_allowed),
            'activation_usage_label' => wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed),
            'active_sites' => $active_sites
        ]);
    }

    /**
     * Deactivate license via API
     */
    public function deactivate_license($request)
    {
        $license_key = sanitize_text_field($request['key']);
        $site_url = isset($request['site_url']) ? esc_url_raw($request['site_url']) : '';

        if (empty($site_url)) {
            return new WP_Error('missing_site_url', __('Site URL is required.', 'wc-product-license'), ['status' => 400]);
        }

        $license = $this->get_license_by_key($license_key);
        if (!$license) {
            return new WP_Error('license_not_found', __('License key not found.', 'wc-product-license'), ['status' => 404]);
        }

        // Remove site from active sites
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        if (!isset($active_sites[$site_url])) {
            return new WP_Error('site_not_active', __('This site is not activated with this license.', 'wc-product-license'), ['status' => 400]);
        }

        unset($active_sites[$site_url]);

        // Update license in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $wpdb->update(
            $table_name,
            [
                'sites_active' => count($active_sites),
                'active_sites' => maybe_serialize($active_sites)
            ],
            ['license_key' => $license_key]
        );

        return rest_ensure_response([
            'success' => true,
            'message' => __('License successfully deactivated.', 'wc-product-license'),
            'sites_active' => count($active_sites),
            'sites_allowed' => (int) $license->sites_allowed,
            'sites_allowed_label' => wc_product_license_get_activation_limit_text($license->sites_allowed),
            'activation_usage_label' => wc_product_license_get_activation_usage_text(count($active_sites), $license->sites_allowed),
            'active_sites' => $active_sites
        ]);
    }

    /**
     * Add license variation to cart item
     */
    private function get_requested_license_variation_index()
    {
        if (isset($_POST['license_variation'])) {
            return wc_clean(wp_unslash($_POST['license_variation']));
        }

        if (isset($_GET['license_variation'])) {
            return wc_clean(wp_unslash($_GET['license_variation']));
        }

        if (isset($_GET['license_upgrade']) && isset($_GET['upgrade_variation'])) {
            return wc_clean(wp_unslash($_GET['upgrade_variation']));
        }

        return null;
    }

    private function get_requested_license_variation($product_id)
    {
        $selected_variation_index = $this->get_requested_license_variation_index();
        if ($selected_variation_index === null || !$this->is_license_product($product_id)) {
            return null;
        }

        $license_variations = $this->get_license_variations($product_id);
        if (!isset($license_variations[$selected_variation_index])) {
            return null;
        }

        return $license_variations[$selected_variation_index];
    }

    public function maybe_redirect_license_package_selection()
    {
        if (!isset($_REQUEST['add-to-cart'])) {
            return;
        }

        $product_id = apply_filters('woocommerce_add_to_cart_product_id', absint(wp_unslash($_REQUEST['add-to-cart'])));
        if (!$this->should_redirect_license_package_selection($product_id)) {
            return;
        }

        $redirect_url = $this->get_license_package_picker_url($product_id, [
            'wc_license_package_required' => $product_id,
        ]);

        if ($redirect_url === '') {
            return;
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function validate_license_package_selection($passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [])
    {
        $target_product_id = $variation_id ? $variation_id : $product_id;

        if (!$this->is_license_product($target_product_id)) {
            return $passed;
        }

        if ($this->get_requested_license_variation($target_product_id)) {
            if (!$this->product_requires_preset_keys($target_product_id)) {
                return $passed;
            }

            if (!empty($this->get_available_preset_keys($target_product_id, true))) {
                return $passed;
            }

            static $preset_notice_added = false;
            if (!$preset_notice_added) {
                wc_add_notice(__('This licensed product has no preset keys left and automatic key generation is disabled. Add more preset keys before selling it again.', 'wc-product-license'), 'error');
                $preset_notice_added = true;
            }

            return false;
        }

        static $notice_added = false;
        if (!$notice_added) {
            $notice = __('Choose a license package before adding this product to the cart.', 'wc-product-license');
            wc_add_notice($notice, 'error');
            $notice_added = true;
        }

        return false;
    }

    public function get_license_product_add_to_cart_url($url, $product)
    {
        if (!$product instanceof WC_Product || !$this->is_license_product($product->get_id())) {
            return $url;
        }

        $package_picker_url = $this->get_license_package_picker_url($product->get_id());
        return $package_picker_url !== '' ? $package_picker_url : $url;
    }

    public function get_license_loop_add_to_cart_text($text, $product)
    {
        if (!$product instanceof WC_Product || !$this->is_license_product($product->get_id())) {
            return $text;
        }

        return __('Choose Package', 'wc-product-license');
    }

    public function render_license_loop_add_to_cart_link($html, $product, $args)
    {
        if (!$product instanceof WC_Product || !$this->is_license_product($product->get_id())) {
            return $html;
        }

        $package_url = $this->get_license_product_add_to_cart_url(get_permalink($product->get_id()), $product);
        $button_label = $this->get_license_loop_add_to_cart_text(__('Choose Package', 'wc-product-license'), $product);
        $aria_label = sprintf(
            /* translators: %s: product title */
            __('Choose a license package for %s', 'wc-product-license'),
            $product->get_name()
        );

        if (class_exists('WP_HTML_Tag_Processor')) {
            $processor = new WP_HTML_Tag_Processor($html);
            $updated_markup = false;

            while ($processor->next_tag()) {
                $tag_name = $processor->get_tag();
                if (!in_array($tag_name, ['A', 'BUTTON'], true)) {
                    continue;
                }

                $processor->set_attribute('aria-label', $aria_label);
                $processor->set_attribute('data-license-product-url', $package_url);
                $processor->add_class('wc-license-loop-cta');
                $processor->remove_class('ajax_add_to_cart');
                $processor->remove_class('add_to_cart_button');

                if ($tag_name === 'A') {
                    $processor->set_attribute('href', $package_url);
                    $processor->set_attribute('rel', 'nofollow');
                } else {
                    $processor->set_attribute('type', 'button');
                    $processor->remove_attribute('data-wp-on--click');
                }

                $updated_markup = true;
            }

            if ($updated_markup) {
                return $processor->get_updated_html();
            }
        }

        $classes = ['button', 'wc-license-loop-cta'];

        if (!empty($args['class']) && is_string($args['class'])) {
            foreach (preg_split('/\s+/', $args['class']) as $class_name) {
                $class_name = trim($class_name);
                if ($class_name === '' || in_array($class_name, ['ajax_add_to_cart', 'add_to_cart_button'], true)) {
                    continue;
                }

                $classes[] = $class_name;
            }
        }

        $attributes = [
            'href' => $package_url,
            'data-product_id' => $product->get_id(),
            'data-product_sku' => $product->get_sku(),
            'aria-label' => $aria_label,
            'rel' => 'nofollow',
            'class' => implode(' ', array_unique($classes)),
        ];

        return sprintf(
            '<a %1$s>%2$s</a>',
            wc_implode_html_attributes(array_filter($attributes, static function ($value) {
                return $value !== '';
            })),
            esc_html($button_label)
        );
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        $selected_variation = $this->get_requested_license_variation($product_id);
        if ($selected_variation) {
            $cart_item_data['license_variation'] = $selected_variation;
        }

        return $cart_item_data;
    }

    /**
     * Display license variation in cart
     */
    public function get_item_data($item_data, $cart_item)
    {
        if (isset($cart_item['license_variation'])) {
            $variation = $this->normalize_license_variation($cart_item['license_variation'], $cart_item['license_variation']['index'] ?? 0);
            $product = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
            $item_data[] = [
                'key' => __('License', 'wc-product-license'),
                'value' => $variation['title'],
                'display' => ''
            ];

            $item_data[] = [
                'key' => __('Access', 'wc-product-license'),
                'value' => sprintf(
                    /* translators: 1: site-count label, 2: duration label */
                    __('%1$s, %2$s', 'wc-product-license'),
                    wc_product_license_get_site_count_text($variation['sites'], 'allowed'),
                    $this->get_license_variation_duration_label($variation)
                ),
                'display' => ''
            ];

            $download_summary = $this->get_license_variation_download_summary($variation, $product);
            if ($download_summary !== '') {
                $item_data[] = [
                    'key' => __('Downloads', 'wc-product-license'),
                    'value' => $download_summary,
                    'display' => ''
                ];
            }
        }

        if (isset($cart_item['license_upgrade']['pricing'])) {
            $pricing = $cart_item['license_upgrade']['pricing'];
            $item_data[] = [
                'key' => __('Upgrade total', 'wc-product-license'),
                'value' => wp_strip_all_tags(wc_price($pricing['final_price'])),
                'display' => ''
            ];
        }

        return $item_data;
    }

    /**
     * Add license variation to order item
     */
    public function checkout_create_order_line_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['license_variation'])) {
            $item->add_meta_data('_selected_license_variation', $values['license_variation']);
        }

        if (isset($values['license_upgrade'])) {
            $item->add_meta_data('_license_upgrade', $values['license_upgrade']);
        }
    }

    /**
     * Handle custom query var for HPOS
     */
    public function handle_custom_query_var($query_vars, $query)
    {
        if (!empty($query->query_vars['_license_key'])) {
            $query_vars['meta_query'][] = [
                'key' => '_license_key',
                'value' => esc_attr($query->query_vars['_license_key']),
            ];
        }

        return $query_vars;
    }

    public function modify_cart_item_price($cart_item_data, $product_id, $variation_id)
    {
        $selected_variation = $this->get_requested_license_variation($product_id);
        if ($selected_variation) {
            $cart_item_data['original_price'] = get_post_meta($product_id, '_price', true);
            $cart_item_data['license_variation'] = $selected_variation;
        }

        $upgrade_context = $this->get_requested_upgrade_context($product_id);
        if ($upgrade_context) {
            $cart_item_data['license_upgrade'] = [
                'license_key' => $upgrade_context['license_key'],
                'source_product_id' => $upgrade_context['source_product_id'],
                'path_index' => $upgrade_context['token'],
                'target_product_id' => $upgrade_context['target_product_id'],
                'target_variation_index' => $upgrade_context['target_variation']['index'],
                'pricing' => $upgrade_context['pricing'],
                'target_label' => $upgrade_context['title'],
            ];

            if (!isset($cart_item_data['license_variation'])) {
                $cart_item_data['license_variation'] = $upgrade_context['target_variation'];
            }
        }

        return $cart_item_data;
    }

    /**
     * Set custom price based on license variation
     */
    public function set_license_variation_price($cart_object)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart_object->get_cart() as $cart_item) {
            if (isset($cart_item['license_upgrade']['pricing']['final_price'])) {
                $cart_item['data']->set_price($cart_item['license_upgrade']['pricing']['final_price']);
                continue;
            }

            if (isset($cart_item['license_variation']) && $cart_item['license_variation']['price'] !== '') {
                $cart_item['data']->set_price($cart_item['license_variation']['price']);
            }
        }
    }

    public function restrict_order_download_permissions($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $allowed_downloads_by_product = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();
            $product_id = $item->get_product_id();
            $download_product_id = $item->get_variation_id() ? $item->get_variation_id() : $product_id;

            if (!$product || !$product->is_downloadable() || get_post_meta($product_id, '_is_license_product', true) !== 'yes') {
                continue;
            }

            $selected_variation = $item->get_meta('_selected_license_variation', true);
            if (empty($selected_variation)) {
                $selected_variation = $this->get_default_license_variation($product_id);
            }

            if (empty($selected_variation)) {
                continue;
            }

            $selected_variation = $this->normalize_license_variation($selected_variation, $selected_variation['index'] ?? 0);
            $allowed_download_ids = $this->get_license_variation_download_ids($selected_variation, $product);

            if (!isset($allowed_downloads_by_product[$download_product_id])) {
                $allowed_downloads_by_product[$download_product_id] = [];
            }

            $allowed_downloads_by_product[$download_product_id] = array_values(array_unique(array_merge(
                $allowed_downloads_by_product[$download_product_id],
                $allowed_download_ids
            )));
        }

        if (empty($allowed_downloads_by_product)) {
            return;
        }

        global $wpdb;
        $permissions_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

        foreach ($allowed_downloads_by_product as $download_product_id => $allowed_download_ids) {
            if (empty($allowed_download_ids)) {
                $wpdb->delete(
                    $permissions_table,
                    [
                        'order_id' => $order_id,
                        'product_id' => $download_product_id,
                    ],
                    ['%d', '%d']
                );
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($allowed_download_ids), '%s'));
            $query = "DELETE FROM {$permissions_table} WHERE order_id = %d AND product_id = %d AND download_id NOT IN ({$placeholders})";
            $params = array_merge([$order_id, $download_product_id], $allowed_download_ids);
            $wpdb->query($wpdb->prepare($query, $params));
        }
    }

    /**
     * Check if product is a license product and force display of license form
     */
    public function force_display_license_options()
    {
        global $product;

        if (!$product || $product->get_type() !== 'simple' || !$product->is_downloadable()) {
            return;
        }

        if (get_post_meta($product->get_id(), '_is_license_product', true) === 'yes') {
            // Force display license options when the product is a license product
            add_action('woocommerce_before_add_to_cart_button', [$this, 'display_license_options'], 10);

            // Hide default price display and show it after license variations
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
            add_action('woocommerce_before_add_to_cart_button', [$this, 'display_dynamic_price'], 9);

            // Add necessary JS for price updates
            wp_enqueue_script('wc-license-price-updater', plugin_dir_url(__FILE__) . 'assets/js/price-updater.js', ['jquery'], '1.0.0', true);
            wp_enqueue_style('wc-license-manager-styles', plugin_dir_url(__FILE__) . 'assets/css/license-manager.css', [], '1.0.0');

            // Pass variation prices to JS
            $license_variations = $this->get_license_variations($product->get_id());
            if (!empty($license_variations)) {
                $variation_prices = [];
                $formatted_variation_prices = [];
                $default_variation = $this->get_default_license_variation($product->get_id());
                foreach ($license_variations as $index => $variation) {
                    $price = $this->get_license_variation_price($variation, $product);
                    $variation_prices[$index] = $price;
                    $formatted_variation_prices[$index] = wc_price($price);
                }

                wp_localize_script('wc-license-price-updater', 'licenseVariations', [
                    'prices' => $variation_prices,
                    'formattedPrices' => $formatted_variation_prices,
                    'defaultVariation' => $default_variation ? (string) $default_variation['index'] : (string) array_key_first($license_variations),
                ]);
            }
        }
    }

    /**
     * Display dynamic price container for JS updating
     */
    public function display_dynamic_price()
    {
        echo '<div class="price license-product-price wc-license-selected-price">';
        echo '<span class="price-label">' . esc_html__('Selected Package', 'wc-product-license') . '</span> ';
        echo '<span class="dynamic-price"></span>';
        echo '</div>';
    }

    /**
     * Modified version of display_license_options to work with dynamic pricing
     */
    public function display_license_options()
    {
        global $product;

        if (!$product || $product->get_type() !== 'simple' || !$product->is_downloadable() || get_post_meta($product->get_id(), '_is_license_product', true) !== 'yes') {
            return;
        }

        $license_variations = $this->get_license_variations($product->get_id());
        if (empty($license_variations)) {
            return;
        }

        $default_variation = $this->get_default_license_variation($product->get_id());
        $default_index = $default_variation ? $default_variation['index'] : array_key_first($license_variations);

        echo '<div id="wc-license-package-picker" class="license-variations wc-license-option-picker">';
        echo '<div class="wc-license-option-picker__header">';
        if ($this->should_show_license_package_notice($product->get_id())) {
            echo '<div class="wc-license-option-picker__notice" role="alert">';
            echo '<span class="wc-license-option-picker__notice-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none"><path d="M10 3.5 17 15.75H3L10 3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"></path><path d="M10 7.25v3.75" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"></path><circle cx="10" cy="13.4" r=".9" fill="currentColor"></circle></svg></span>';
            echo '<div class="wc-license-option-picker__notice-content">';
            echo '<strong>' . esc_html__('Select a license package to continue.', 'wc-product-license') . '</strong>';
            echo '<p>' . esc_html__('Licensed products require a package selection before they can be added to the cart.', 'wc-product-license') . '</p>';
            echo '</div>';
            echo '</div>';
        }
        echo '<div>';
        echo '<h3>' . esc_html__('Choose Your License Package', 'wc-product-license') . '</h3>';
        echo '<p>' . esc_html__('Each package can include different file access, activation limits, and license terms. Choose the combination that fits your customer.', 'wc-product-license') . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="license-variation-selector wc-license-option-grid">';
        foreach ($license_variations as $index => $variation) {
            $price = $this->get_license_variation_price($variation, $product);
            $is_checked = (string) $index === (string) $default_index;
            $option_classes = 'license-variation-option wc-license-option-card';
            if (!empty($variation['recommended'])) {
                $option_classes .= ' is-recommended';
            }
            if ($is_checked) {
                $option_classes .= ' is-selected';
            }

            echo '<label class="' . esc_attr($option_classes) . '">';
            echo '<input type="radio" name="license_variation" value="' . esc_attr($index) . '" ' . checked($is_checked, true, false) . ' data-price="' . esc_attr($price) . '">';
            echo '<span class="wc-license-option-card__topline">';
            echo '<span class="license-title wc-license-option-card__title">' . esc_html($variation['title']) . '</span>';
            if (!empty($variation['recommended'])) {
                echo '<span class="wc-license-pill wc-license-pill--accent">' . esc_html__('Recommended', 'wc-product-license') . '</span>';
            }
            if ($is_checked) {
                echo '<span class="wc-license-pill wc-license-pill--success">' . esc_html__('Default', 'wc-product-license') . '</span>';
            }
            echo '</span>';
            echo '<span class="wc-license-option-card__price">' . wp_kses_post(wc_price($price)) . '</span>';
            echo '<span class="license-details wc-license-option-card__meta">' . esc_html(sprintf(
                /* translators: 1: site-count label, 2: duration label */
                __('%1$s | %2$s', 'wc-product-license'),
                wc_product_license_get_site_count_text($variation['sites'], 'allowed'),
                $this->get_license_variation_duration_label($variation)
            )) . '</span>';
            $download_summary = $this->get_license_variation_download_summary($variation, $product);
            if ($download_summary !== '') {
                echo '<span class="wc-license-option-card__downloads">' . esc_html($download_summary) . '</span>';
            }
            if (!empty($variation['description'])) {
                echo '<span class="wc-license-option-card__description">' . esc_html($variation['description']) . '</span>';
            }
            echo '</label>';
        }
        echo '</div>';
        echo '</div>';
    }
}

require_once plugin_dir_path(__FILE__) . 'include/admin.php';

function product_license_init()
{
    // Check if license has expired
    $license_expiry = wc_product_license_get_plugin_expiry();
    $current_date = current_time('timestamp');

    // If expiry date exists and has passed
    if ($license_expiry) {
        $expiry_timestamp = strtotime($license_expiry);
    } else {
        $expiry_timestamp = false;
    }

    if ($expiry_timestamp && $expiry_timestamp < $current_date) {
        // Update the license status to expired
        update_option('wc_product_license_status', 'expired');

        if (get_option('plugincywc_product_license_status', '') !== '') {
            update_option('plugincywc_product_license_status', 'expired');
        }

        // You might want to log this change
        error_log('License expired on ' . $license_expiry . '. Status updated to expired.');
    }
    send_tracking_info();
    new WC_Product_License_Manager();
    new WC_Product_License_Admin();
}

add_action('plugins_loaded', 'product_license_init');


// Register activation hook
register_activation_hook(__FILE__, 'wc_product_license_activate');

function wc_product_license_activate()
{
    // Set a flag to flush rewrite rules
    update_option('wc_license_manager_flush_rewrite_rules', true);
}


function send_tracking_info()
{
    // Gather the necessary information
    $site_url = str_replace('http://', '', get_site_url());
    $plugin_name = 'WooCommerce Product License Manager'; // Replace with your actual plugin name
    $is_multisite = is_multisite() ? 'yes' : 'no';
    $wp_version = get_bloginfo('version');
    $php_version = phpversion();
    $server_software = $_SERVER['SERVER_SOFTWARE'];
    $mysql_version = $GLOBALS['wpdb']->db_version();

    // Prepare the data to send
    $data = array(
        'site_url' => $site_url,
        'product_name' => $plugin_name,
        'multisite' => $is_multisite,
        'wordpress_version' => $wp_version,
        'php_version' => $php_version,
        'server_software' => $server_software,
        'mysql_version' => $mysql_version,
    );

    // Send the data via HTTP POST
    $response = wp_remote_post('https://wppluginzone.com/wp-json/wc-license-manager/v1/tracking/activate', array(
        'body' => $data,
        'method' => 'POST',
        'timeout' => 30,
    ));

    // Optional: Check for errors
    if (is_wp_error($response)) {
        error_log('Tracking info send error: ' . $response->get_error_message());
    } else {
        error_log('Tracking info sent successfully.');
    }
}

require_once plugin_dir_path(__FILE__) . 'uninstall.php';

// Initialize the feedback system
function initialize_deactivation_feedback()
{
    new Plugin_Deactivation_Feedback('WooCommerce Product License Manager', 'woocommerce-license-manager/woocommerce-license-manager.php');
}
add_action('plugins_loaded', 'initialize_deactivation_feedback');




/**
 * Shortcode to display license pricing and checkout buttons
 * 
 * [wclicence_price product="152" template="" variation="free" variation_index="1"]
 * 
 * @param array $atts Shortcode attributes
 * @return string Shortcode output
 */
function wc_license_price_shortcode($atts)
{
    // Extract shortcode attributes
    $atts = shortcode_atts(array(
        'product' => 0,
        'template' => '',
        'variation' => '',
        'variation_index' => '',
        'button_text' => __('Buy Now', 'wc-product-license'),
    ), $atts, 'wclicence_price');

    $product_id = absint($atts['product']);
    $template = sanitize_text_field($atts['template']);
    $variation = sanitize_text_field($atts['variation']);
    $variation_index_attr = (string) $atts['variation_index'];
    $button_text = sanitize_text_field($atts['button_text']);

    // Check if product exists and is valid
    $product = wc_get_product($product_id);
    if (!$product || get_post_meta($product_id, '_is_license_product', true) !== 'yes') {
        return '<p class="wc-license-error">' . __('Invalid product or not a license product.', 'wc-product-license') . '</p>';
    }

    // Get license variations
    $license_variations = get_post_meta($product_id, '_license_variations', true);
    if (empty($license_variations)) {
        return '<p class="wc-license-error">' . __('No license variations found for this product.', 'wc-product-license') . '</p>';
    }

    foreach ($license_variations as $index => $license_variation) {
        if (!is_array($license_variation)) {
            unset($license_variations[$index]);
            continue;
        }

        $is_unlimited_sites = !empty($license_variation['is_unlimited_sites']) || (isset($license_variation['sites']) && (int) $license_variation['sites'] <= 0);
        $license_variation['sites'] = wc_product_license_normalize_sites_allowed($license_variation['sites'] ?? 1, $is_unlimited_sites);
        $license_variation['is_unlimited_sites'] = $is_unlimited_sites;
        $license_variations[$index] = $license_variation;
    }

    if (empty($license_variations)) {
        return '<p class="wc-license-error">' . __('No license variations found for this product.', 'wc-product-license') . '</p>';
    }

    // Start output buffer
    ob_start();

    // Enqueue necessary scripts for functionality
    wp_enqueue_style('wc-license-shortcode-style', plugin_dir_url(__FILE__) . 'assets/css/shortcode.css');
    wp_enqueue_script('wc-license-shortcode-script', plugin_dir_url(__FILE__) . 'assets/js/shortcode.js', array('jquery'), '1.0.0', true);

    // If variation is specified, show direct checkout button
    if (!empty($variation) || $variation_index_attr !== '') {
        // Find the variation with the specified title
        $variation_index = null;
        if ($variation_index_attr !== '' && array_key_exists($variation_index_attr, $license_variations)) {
            $variation_index = $variation_index_attr;
        } else {
            foreach ($license_variations as $index => $var) {
                if (strtolower($var['title']) === strtolower($variation)) {
                    $variation_index = $index;
                    break;
                }
            }
        }

        if ($variation_index !== null) {
            $license_var = $license_variations[$variation_index];
            $checkout_url = add_query_arg(array(
                'add-to-cart' => $product_id,
                'license_variation' => $variation_index,
            ), wc_get_cart_url());

            echo '<div class="wc-license-direct-checkout">';
            echo '<h3>' . esc_html($product->get_name()) . ' - ' . esc_html($license_var['title']) . '</h3>';
            echo '<div class="wc-license-price">' . wc_price(wc_product_license_get_variation_price($license_var, $product)) . '</div>';
            echo '<div class="wc-license-features">';
            echo '<span class="wc-license-sites">' . esc_html(wc_product_license_get_site_count_text($license_var['sites'], 'sites')) . '</span>';
            echo '<span class="wc-license-validity">' . esc_html(wc_product_license_get_variation_duration_label($license_var)) . '</span>';
            echo '</div>';
            echo '<a href="' . esc_url($checkout_url) . '" class="wc-license-checkout-button">' . $button_text . '</a>';
            echo '</div>';
        } else {
            echo '<p class="wc-license-error">' . __('Specified variation not found.', 'wc-product-license') . '</p>';
        }
    } else {
        // Show all variations based on template
        echo '<div class="wc-license-pricing-table ' . ($template ? 'template-' . esc_attr($template) : 'template-default') . '">';

        switch ($template) {
            case 'cards':
                // Cards template
                echo '<div class="wc-license-cards">';
                foreach ($license_variations as $index => $var) {
                    $checkout_url = add_query_arg(array(
                        'add-to-cart' => $product_id,
                        'license_variation' => $index,
                    ), wc_get_cart_url());

                    echo '<div class="wc-license-card">';
                    echo '<div class="wc-license-card-header">' . esc_html($var['title']) . '</div>';
                    echo '<div class="wc-license-card-price">' . wc_price(wc_product_license_get_variation_price($var, $product)) . '</div>';
                    echo '<div class="wc-license-card-features">';
                    echo '<div class="wc-license-feature"><span class="dashicons dashicons-yes"></span> ' . esc_html(wc_product_license_get_site_count_text($var['sites'], 'sites')) . '</div>';
                    echo '<div class="wc-license-feature"><span class="dashicons dashicons-calendar-alt"></span> ' . esc_html(wc_product_license_get_variation_duration_label($var)) . '</div>';
                    echo '</div>';
                    echo '<a href="' . esc_url($checkout_url) . '" class="wc-license-card-button">' . $button_text . '</a>';
                    echo '</div>';
                }
                echo '</div>';
                break;

            case 'table':
                // Table template
                echo '<table class="wc-license-table">';
                echo '<thead><tr>';
                echo '<th>' . __('License', 'wc-product-license') . '</th>';
                echo '<th>' . __('Sites', 'wc-product-license') . '</th>';
                echo '<th>' . __('Validity', 'wc-product-license') . '</th>';
                echo '<th>' . __('Price', 'wc-product-license') . '</th>';
                echo '<th></th>';
                echo '</tr></thead>';
                echo '<tbody>';
                foreach ($license_variations as $index => $var) {
                    $checkout_url = add_query_arg(array(
                        'add-to-cart' => $product_id,
                        'license_variation' => $index,
                    ), wc_get_cart_url());

                    echo '<tr>';
                    echo '<td>' . esc_html($var['title']) . '</td>';
                    echo '<td>' . esc_html(wc_product_license_get_site_count_text($var['sites'], 'sites')) . '</td>';
                    echo '<td>' . esc_html(wc_product_license_get_variation_duration_label($var)) . '</td>';
                    echo '<td>' . wc_price(wc_product_license_get_variation_price($var, $product)) . '</td>';
                    echo '<td><a href="' . esc_url($checkout_url) . '" class="wc-license-table-button">' . $button_text . '</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                break;

            case 'toggle':
                // Toggle selector
                echo '<div class="wc-license-toggle">';
                echo '<div class="wc-license-toggle-selector">';
                foreach ($license_variations as $index => $var) {
                    echo '<div class="wc-license-toggle-option" data-index="' . esc_attr($index) . '">' . esc_html($var['title']) . '</div>';
                }
                echo '</div>';

                echo '<div class="wc-license-toggle-content">';
                foreach ($license_variations as $index => $var) {
                    $checkout_url = add_query_arg(array(
                        'add-to-cart' => $product_id,
                        'license_variation' => $index,
                    ), wc_get_cart_url());

                    echo '<div class="wc-license-toggle-panel" data-index="' . esc_attr($index) . '"' . ($index === array_key_first($license_variations) ? ' style="display:block"' : '') . '>';
                    echo '<div class="wc-license-toggle-price">' . wc_price(wc_product_license_get_variation_price($var, $product)) . '</div>';
                    echo '<div class="wc-license-toggle-details">';
                    echo '<div class="wc-license-toggle-sites">' . esc_html(wc_product_license_get_site_count_text($var['sites'], 'sites')) . '</div>';
                    echo '<div class="wc-license-toggle-validity">' . esc_html(wc_product_license_get_variation_duration_label($var)) . '</div>';
                    echo '</div>';
                    echo '<a href="' . esc_url($checkout_url) . '" class="wc-license-toggle-button">' . $button_text . '</a>';
                    echo '</div>';
                }
                echo '</div>'; // .wc-license-toggle-content
                echo '</div>'; // .wc-license-toggle

                // Add toggle JavaScript
                echo '<script>
                    jQuery(document).ready(function($) {
                        $(".wc-license-toggle-option").on("click", function() {
                            var index = $(this).data("index");
                            $(".wc-license-toggle-option").removeClass("active");
                            $(this).addClass("active");
                            $(".wc-license-toggle-panel").hide();
                            $(".wc-license-toggle-panel[data-index=" + index + "]").show();
                        });
                        $(".wc-license-toggle-option:first").addClass("active");
                    });
                </script>';
                break;

            case 'checklist':
                // Checklist template
                echo '<div class="wc-license-checklist">';
                echo '<form class="wc-license-checklist-form" data-product-id="' . esc_attr($product_id) . '">';
                foreach ($license_variations as $index => $var) {
                    $isFirst = ($index === array_key_first($license_variations));
                    echo '<div class="wc-license-checklist-item' . ($isFirst ? ' active' : '') . '" data-index="' . esc_attr($index) . '">';
                    echo '<div class="wc-license-checkbox">';
                    echo '<input type="radio" id="license-option-' . esc_attr($index) . '" name="license_variation" value="' . esc_attr($index) . '"' . ($isFirst ? ' checked' : '') . '>';
                    echo '<label for="license-option-' . esc_attr($index) . '"></label>';
                    echo '</div>';

                    echo '<div class="wc-license-checklist-content">';
                    echo '<div class="wc-license-checklist-header">';
                    echo '<span class="wc-license-checklist-title">' . esc_html($var['title']) . '</span>';
                    echo '<span class="wc-license-checklist-price">' . wc_price(wc_product_license_get_variation_price($var, $product)) . '</span>';
                    echo '</div>';

                    echo '<div class="wc-license-checklist-details">';
                    echo '<span class="wc-license-sites-badge">' . esc_html(wc_product_license_get_site_count_text($var['sites'], 'allowed')) . '</span>';
                    echo '<span class="wc-license-validity-badge">' . esc_html(wc_product_license_get_variation_duration_label($var)) . '</span>';
                    echo '</div>';
                    echo '</div>'; // .wc-license-checklist-content
                    echo '</div>'; // .wc-license-checklist-item
                }

                echo '<div class="wc-license-checklist-actions">';
                echo '<button type="submit" class="wc-license-checklist-button">' . $button_text . '</button>';
                echo '</div>';
                echo '</form>';

                // Add JavaScript for checklist interactions
                echo '<script>
                    jQuery(document).ready(function($) {
                        $(".wc-license-checklist-item").on("click", function() {
                            var index = $(this).data("index");
                            $(".wc-license-checklist-item").removeClass("active");
                            $(this).addClass("active");
                            $("#license-option-" + index).prop("checked", true);
                        });
                        
                        $(".wc-license-checklist-form").on("submit", function(e) {
                            e.preventDefault();
                            var productId = $(this).data("product-id");
                            var variation = $("input[name=license_variation]:checked").val();
                            window.location.href = "' . esc_url(wc_get_cart_url()) . '?add-to-cart=" + productId + "&license_variation=" + variation;
                        });
                    });
                    </script>';
                echo '</div>'; // .wc-license-checklist
                break;

            default:
                // Default template - simple radio buttons
                echo '<div class="wc-license-default">';
                echo '<h3>' . esc_html($product->get_name()) . ' ' . __('License Options', 'wc-product-license') . '</h3>';
                echo '<form class="wc-license-form" data-product-id="' . esc_attr($product_id) . '">';

                foreach ($license_variations as $index => $var) {
                    echo '<div class="wc-license-option">';
                    echo '<label>';
                    echo '<input type="radio" name="license_variation" value="' . esc_attr($index) . '" ' . ($index === array_key_first($license_variations) ? 'checked' : '') . '>';
                    echo '<span class="wc-license-option-title">' . esc_html($var['title']) . '</span>';
                    echo '<span class="wc-license-option-price">' . wc_price(wc_product_license_get_variation_price($var, $product)) . '</span>';
                    echo '<span class="wc-license-option-details">' . esc_html(sprintf(
                        __('%1$s, %2$s', 'wc-product-license'),
                        wc_product_license_get_site_count_text($var['sites'], 'sites'),
                        wc_product_license_get_variation_duration_label($var)
                    )) . '</span>';
                    echo '</label>';
                    echo '</div>';
                }

                echo '<button type="submit" class="wc-license-submit">' . __('Checkout', 'wc-product-license') . '</button>';
                echo '</form>';

                // Add JavaScript for form submission
                echo '<script>
                    jQuery(document).ready(function($) {
                        $(".wc-license-form").on("submit", function(e) {
                            e.preventDefault();
                            var productId = $(this).data("product-id");
                            var variation = $("input[name=license_variation]:checked").val();
                            
                            window.location.href = "' . esc_url(wc_get_checkout_url()) . '?add-to-cart=" + productId + "&license_variation=" + variation;
                        });
                    });
                </script>';
                echo '</div>'; // .wc-license-default
                break;
        }

        echo '</div>'; // .wc-license-pricing-table
    }

    // Return the output
    return ob_get_clean();
}

// Register shortcode
add_shortcode('wclicence_price', 'wc_license_price_shortcode');

/**
 * Add CSS to customize shortcode appearance
 */
function wc_license_shortcode_styles()
{
?>
    <style>
        /* Base styles for all templates */
        .wc-license-pricing-table {
            margin: 20px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        .wc-license-checkout-button,
        .wc-license-card-button,
        .wc-license-table-button,
        .wc-license-toggle-button,
        .wc-license-submit {
            display: inline-block;
            padding: 10px 20px;
            background-color: #0073aa;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .wc-license-checkout-button:hover,
        .wc-license-card-button:hover,
        .wc-license-table-button:hover,
        .wc-license-toggle-button:hover,
        .wc-license-submit:hover {
            background-color: #006291;
        }

        /* Direct checkout style */
        .wc-license-direct-checkout {
            text-align: center;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            max-width: 300px;
            margin: 0 auto;
        }

        .wc-license-price {
            font-size: 24px;
            font-weight: bold;
            margin: 15px 0;
        }

        .wc-license-features {
            margin-bottom: 20px;
        }

        .wc-license-sites,
        .wc-license-validity {
            display: block;
            margin: 5px 0;
        }

        /* Cards template */
        .wc-license-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }

        .wc-license-card {
            width: 250px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .wc-license-card-header {
            background-color: #f5f5f5;
            padding: 15px 10px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
        }

        .wc-license-card-price {
            font-size: 24px;
            text-align: center;
            padding: 20px 10px;
            font-weight: bold;
        }

        .wc-license-card-features {
            padding: 0 20px 20px;
        }

        .wc-license-feature {
            margin: 10px 0;
        }

        .wc-license-card-button {
            display: block;
            margin: 0 20px 20px;
        }

        /* Table template */
        .wc-license-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .wc-license-table th,
        .wc-license-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .wc-license-table th {
            background-color: #f5f5f5;
        }

        /* Toggle template */
        .wc-license-toggle {
            max-width: 600px;
            margin: 0 auto;
        }

        .wc-license-toggle-selector {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }

        .wc-license-toggle-option {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            margin-bottom: -1px;
        }

        .wc-license-toggle-option.active {
            border-color: #ddd;
            border-bottom-color: white;
            background-color: white;
            font-weight: bold;
        }

        .wc-license-toggle-panel {
            display: none;
            padding: 20px;
            text-align: center;
        }

        .wc-license-toggle-price {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .wc-license-toggle-details {
            margin-bottom: 30px;
        }

        /* Default template */
        .wc-license-option {
            border: 1px solid #ddd;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 4px;
        }

        .wc-license-option label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .wc-license-option input[type="radio"] {
            margin-right: 15px;
        }

        .wc-license-option-title {
            font-weight: bold;
            flex: 1;
        }

        .wc-license-option-price {
            font-weight: bold;
            font-size: 18px;
            margin-right: 15px;
        }

        .wc-license-option-details {
            color: #777;
            font-size: 14px;
        }

        .wc-license-submit {
            margin-top: 15px;
            width: 100%;
        }

        /* Error messages */
        .wc-license-error {
            color: #d63638;
            padding: 10px;
            border-left: 4px solid #d63638;
            background-color: #fcf0f1;
        }

        /* Checklist template */
        .wc-license-checklist {
            max-width: 700px;
            margin: 0 auto;
            font-size: 16px;
        }

        .wc-license-checklist h3 {
            margin-bottom: 25px;
            text-align: center;
            font-size: 24px;
        }

        .wc-license-checklist-item {
            display: flex;
            align-items: center;
            border: 2px solid #eaeaea;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .wc-license-checklist-item:hover {
            border-color: #c3c3c3;
            background-color: #fafafa;
        }

        .wc-license-checklist-item.active {
            border-color: #0073aa;
            background-color: #f0f7fb;
        }

        .wc-license-checkbox {
            margin-right: 20px;
            position: relative;
        }

        .wc-license-checkbox input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .wc-license-checkbox label {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 2px solid #aaa;
            border-radius: 50%;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .wc-license-checklist-item:hover .wc-license-checkbox label {
            border-color: #666;
        }

        .wc-license-checklist-item.active .wc-license-checkbox label {
            border-color: #0073aa;
            background-color: #fff;
        }

        .wc-license-checklist-item.active .wc-license-checkbox label:after {
            content: "";
            position: absolute;
            width: 12px;
            height: 12px;
            background-color: #0073aa;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .wc-license-checklist-content {
            flex: 1;
        }

        .wc-license-checklist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .wc-license-checklist-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .wc-license-checklist-price {
            font-size: 20px;
            font-weight: 700;
            color: #0073aa;
        }

        .wc-license-checklist-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .wc-license-sites-badge,
        .wc-license-validity-badge {
            display: inline-block;
            background-color: #e9f5fb;
            color: #0073aa;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .wc-license-validity-badge {
            background-color: #f0f7e6;
            color: #5c8b2e;
        }

        .wc-license-checklist-actions {
            margin-top: 25px;
            text-align: center;
        }

        .wc-license-checklist-button {
            display: inline-block;
            padding: 12px 30px;
            background-color: #0073aa;
            color: white;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .wc-license-checklist-button:hover {
            background-color: #005d8c;
        }
    </style>
<?php
}
add_action('wp_head', 'wc_license_shortcode_styles');
