<?php

/**
 * Plugin Name: WooCommerce Product License Manager
 * Plugin URI: https://example.com/wc-product-license-manager
 * Description: Sell and manage licenses for WooCommerce downloadable products
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
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
class WC_Product_License_Manager
{

    public function __construct()
    {
        // Product editing metabox
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_license_option_to_products']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_product_option']);

        // License variations
        add_action('woocommerce_product_options_downloads', [$this, 'add_license_variations_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_variations']);

        // Order management
        add_action('woocommerce_checkout_order_processed', [$this, 'generate_license_keys'], 10, 3);
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
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'checkout_create_order_line_item'], 10, 4);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'display_license_options']);


        // hooks for price modification
        add_filter('woocommerce_add_cart_item_data', [$this, 'modify_cart_item_price'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'set_license_variation_price'], 10, 1);
        add_action('woocommerce_single_product_summary', [$this, 'force_display_license_options'], 1);
    }

    /**
     * Add license checkbox to product general options
     */
    public function add_license_option_to_products()
    {
        global $post;

        echo '<div class="options_group show_if_downloadable">';

        woocommerce_wp_checkbox([
            'id' => '_is_license_product',
            'label' => __('Requires License Key', 'wc-product-license'),
            'description' => __('Enable if this downloadable product requires a license key', 'wc-product-license')
        ]);

        echo '</div>';
    }

    /**
     * Save license product option
     */
    public function save_license_product_option($product_id)
    {
        $is_license_product = isset($_POST['_is_license_product']) ? 'yes' : 'no';
        update_post_meta($product_id, '_is_license_product', $is_license_product);
    }

    /**
     * Add license variations fields to downloadable products
     */
    public function add_license_variations_fields()
    {
        global $post;

        $license_variations = get_post_meta($post->ID, '_license_variations', true);
        if (!is_array($license_variations)) {
            $license_variations = [];
        }

        echo '<div class="license_variations_panel show_if_downloadable">';
        echo '<h4>' . __('License variations', 'wc-product-license') . '</h4>';
        echo '<div class="license_variations_wrapper">';
        echo '<table class="widefat">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Title', 'wc-product-license') . '</th>';
        echo '<th>' . __('Price', 'wc-product-license') . '</th>';
        echo '<th>' . __('Sites Allowed', 'wc-product-license') . '</th>';
        echo '<th>' . __('Validity (days)', 'wc-product-license') . '</th>';
        echo '<th>' . __('Actions', 'wc-product-license') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody id="license_variations_container">';

        if (!empty($license_variations)) {
            foreach ($license_variations as $index => $variation) {
                $this->render_license_variation_row($variation, $index);
            }
        }

        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr>';
        echo '<td colspan="5">';
        echo '<button type="button" class="button add_license_variation">' . __('Add License Variation', 'wc-product-license') . '</button>';
        echo '</td>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        // Template for new rows
        echo '<script type="text/html" id="tmpl-license-variation-row">';
        $this->render_license_variation_row([
            'title' => '',
            'price' => '',
            'sites' => '1',
            'validity' => '365'
        ], '{{data.index}}');
        echo '</script>';

        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                var index = ' . max(array_keys($license_variations) ?: [0]) . ';
                
                $(".add_license_variation").on("click", function() {
                    index++;
                    var template = wp.template("license-variation-row");
                    $("#license_variations_container").append(template({index: index}));
                });
                
                $(document).on("click", ".remove_license_variation", function() {
                    $(this).closest("tr").remove();
                });
            });
        </script>';
    }

    /**
     * Render a single license variation row
     */
    private function render_license_variation_row($variation, $index)
    {
        echo '<tr>';
        echo '<td><input type="text" name="license_variation_title[' . esc_attr($index) . ']" value="' . esc_attr($variation['title']) . '" placeholder="' . __('e.g. Single Site', 'wc-product-license') . '" /></td>';
        echo '<td><input type="text" name="license_variation_price[' . esc_attr($index) . ']" value="' . esc_attr($variation['price']) . '" placeholder="' . __('e.g. 59.99', 'wc-product-license') . '" /></td>';
        echo '<td><input type="number" name="license_variation_sites[' . esc_attr($index) . ']" value="' . esc_attr($variation['sites']) . '" min="1" /></td>';
        echo '<td><input type="number" name="license_variation_validity[' . esc_attr($index) . ']" value="' . esc_attr($variation['validity']) . '" min="1" /></td>';
        echo '<td><button type="button" class="button remove_license_variation">' . __('Remove', 'wc-product-license') . '</button></td>';
        echo '</tr>';
    }

    /**
     * Save license variations
     */
    public function save_license_variations($product_id)
    {
        $variations = [];

        if (isset($_POST['license_variation_title']) && is_array($_POST['license_variation_title'])) {
            foreach ($_POST['license_variation_title'] as $index => $title) {
                if (empty($title)) continue;

                $variations[$index] = [
                    'title' => sanitize_text_field($title),
                    'price' => isset($_POST['license_variation_price'][$index]) ? wc_format_decimal($_POST['license_variation_price'][$index]) : '',
                    'sites' => isset($_POST['license_variation_sites'][$index]) ? absint($_POST['license_variation_sites'][$index]) : 1,
                    'validity' => isset($_POST['license_variation_validity'][$index]) ? absint($_POST['license_variation_validity'][$index]) : 365,
                ];
            }
        }

        update_post_meta($product_id, '_license_variations', $variations);
    }

    /**
     * Generate license keys when order is processed
     */
    public function generate_license_keys($order_id, $posted_data, $order)
    {
        if (!$order) return;

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);

            if (!$product || $product->get_type() !== 'simple' || !$product->is_downloadable() || get_post_meta($product_id, '_is_license_product', true) !== 'yes') {
                continue;
            }

            // Get selected license variation
            $selected_variation = $item->get_meta('_selected_license_variation');

            if (empty($selected_variation)) {
                // If not set, get the first variation or use default values
                $variations = get_post_meta($product_id, '_license_variations', true);
                $selected_variation = !empty($variations) ? reset($variations) : [
                    'sites' => 1,
                    'validity' => 365
                ];
            }

            // Generate license key
            $license_key = $this->generate_unique_license_key();

            // Store license details
            $license_data = [
                'key' => $license_key,
                'product_id' => $product_id,
                'order_id' => $order_id,
                'user_id' => $order->get_user_id(),
                'status' => 'active',
                'sites_allowed' => $selected_variation['sites'],
                'sites_active' => 0,
                'activation_limit' => $selected_variation['sites'],
                'expires_at' => $selected_variation['validity'] > 0 ? date('Y-m-d H:i:s', strtotime('+' . $selected_variation['validity'] . ' days')) : null,
                'purchased_at' => current_time('mysql'),
                'purchased_price' => $item->get_total(),
                'active_sites' => []
            ];

            // Save license to order item meta
            wc_add_order_item_meta($item_id, '_license_key', $license_key);
            wc_add_order_item_meta($item_id, '_license_data', $license_data);

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

        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
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
                echo '—';
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
                echo '—';
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
            echo '<p><strong>' . __('Activations:', 'wc-product-license') . '</strong> ' . esc_html($license_data['sites_active'] . '/' . $license_data['sites_allowed']) . '</p>';
            echo '<p><strong>' . __('Expires:', 'wc-product-license') . '</strong> ' . esc_html($license_data['expires_at'] ? date_i18n(get_option('date_format'), strtotime($license_data['expires_at'])) : __('Never', 'wc-product-license')) . '</p>';

            if (!empty($license_data['active_sites'])) {
                echo '<p><strong>' . __('Active Sites:', 'wc-product-license') . '</strong></p>';
                echo '<ul>';
                foreach ($license_data['active_sites'] as $site_url => $activation_date) {
                    echo '<li>' . esc_html($site_url) . ' — ' . __('Activated on', 'wc-product-license') . ' ' . date_i18n(get_option('date_format'), strtotime($activation_date)) . '</li>';
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
    public function add_license_keys_endpoint() {
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

        // Enqueue frontend script and localize data
        wp_enqueue_script('wc-license-manager-frontend');
        wp_localize_script('wc-license-manager-frontend', 'wcLicenseManager', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-license-manager'),
            'i18n' => [
                'activating' => __('Activating...', 'wc-product-license'),
                'deactivating' => __('Deactivating...', 'wc-product-license')
            ]
        ]);

        // Enqueue custom CSS for styling
        wp_enqueue_style('wc-license-manager-styles', plugin_dir_url(__FILE__) . 'assets/css/license-manager.css');

        echo '<div class="wc-license-manager-keys">';

        foreach ($licenses as $license) {
            $product = wc_get_product($license->product_id);
            $product_name = $product ? $product->get_name() : __('Unknown Product', 'wc-product-license');
            $active_sites = maybe_unserialize($license->active_sites) ?: [];
            $status_class = $license->status === 'active' ? 'active' : 'inactive';
            $expires = $license->expires_at
                ? date_i18n(get_option('date_format'), strtotime($license->expires_at))
                : __('Never', 'wc-product-license');

            echo '<div class="license-key-item">';
            echo '<h4>' . esc_html($product_name) . '</h4>';
            echo '<div class="license-details">';
            echo '<p><strong>' . __('License Key:', 'wc-product-license') . '</strong> <code>' . esc_html($license->license_key) . '</code></p>';
            echo '<p><strong>' . __('Status:', 'wc-product-license') . '</strong> <span class="status-' . esc_attr($status_class) . '">' . esc_html(ucfirst($license->status)) . '</span></p>';
            echo '<p><strong>' . __('Activations:', 'wc-product-license') . '</strong> ' . esc_html($license->sites_active . '/' . $license->sites_allowed) . '</p>';
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

            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
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
     * Verify API permission
     */
    public function verify_license_api_permission()
    {
        return true; // Allow public access, but you might want to add authentication
    }

    /**
     * Get license data via API
     */
    public function get_license_data($request)
    {
        $license_key = sanitize_text_field($request['key']);
        $license = $this->get_license_by_key($license_key);

        if (!$license) {
            return new WP_Error('license_not_found', __('License key not found.', 'wc-product-license'), ['status' => 404]);
        }

        $product = wc_get_product($license->product_id);

        return rest_ensure_response([
            'success' => true,
            'license_key' => $license->license_key,
            'status' => $license->status,
            'product_id' => $license->product_id,
            'product_name' => $product ? $product->get_name() : '',
            'sites_allowed' => (int) $license->sites_allowed,
            'sites_active' => (int) $license->sites_active,
            'expires_at' => $license->expires_at,
            'active_sites' => maybe_unserialize($license->active_sites) ?: []
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

        if ($license->user_id !== get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
        }

        if ($license->status !== 'active') {
            wp_send_json_error(['message' => __('This license key is not active.', 'wc-product-license')]);
        }

        if ($license->sites_active >= $license->sites_allowed) {
            wp_send_json_error(['message' => __('You have reached the maximum number of activations for this license.', 'wc-product-license')]);
        }

        // Check if site is already activated
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
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
            'active_sites' => $active_sites
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

        if ($license->user_id !== get_current_user_id()) {
            wp_send_json_error(['message' => __('You do not own this license key.', 'wc-product-license')]);
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
            'active_sites' => $active_sites
        ]);
    }

    /**
     * Register scripts
     */
    public function register_scripts()
    {
        wp_register_script(
            'wc-license-manager-frontend',
            plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Register admin scripts
     */
    public function register_admin_scripts($hook)
    {
        if ($hook == 'post.php' || $hook == 'post-new.php') {
            wp_enqueue_script(
                'wc-license-manager-admin',
                plugin_dir_url(__FILE__) . 'assets/js/admin.js',
                ['jquery'],
                '1.0.0',
                true
            );
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
        // Check if site is already activated
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
        if (isset($active_sites[$site_url])) {
            return rest_ensure_response([
                'success' => true,
                'message' => __('License successfully activated.', 'wc-product-license'),
                'sites_active' => count($active_sites),
                'sites_allowed' => (int) $license->sites_allowed,
                'active_sites' => $active_sites
            ]);
        }
        if (!$license) {
            return new WP_Error('license_not_found', __('License key not found.', 'wc-product-license'), ['status' => 404]);
        }

        if ($license->status !== 'active') {
            return new WP_Error('license_inactive', __('This license key is not active.', 'wc-product-license'), ['status' => 400]);
        }

        if ($license->sites_active >= $license->sites_allowed) {
            return new WP_Error('max_activations', __('Maximum activations reached for this license.', 'wc-product-license'), ['status' => 400]);
        }

        // Check if expired
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            return new WP_Error('license_expired', __('This license key has expired.', 'wc-product-license'), ['status' => 400]);
        }

        // Add site to active sites
        $active_sites = maybe_unserialize($license->active_sites) ?: [];
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
            'active_sites' => $active_sites
        ]);
    }

    /**
     * Add license variation to cart item
     */
    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        if (isset($_POST['license_variation']) && get_post_meta($product_id, '_is_license_product', true) === 'yes') {
            $license_variations = get_post_meta($product_id, '_license_variations', true);
            $selected_variation_index = absint($_POST['license_variation']);

            if (isset($license_variations[$selected_variation_index])) {
                $cart_item_data['license_variation'] = $license_variations[$selected_variation_index];
            }
        }

        return $cart_item_data;
    }

    /**
     * Display license variation in cart
     */
    public function get_item_data($item_data, $cart_item)
    {
        if (isset($cart_item['license_variation'])) {
            $item_data[] = [
                'key' => __('License', 'wc-product-license'),
                'value' => $cart_item['license_variation']['title'],
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

    public function modify_cart_item_price($cart_item_data, $product_id, $variation_id) {
        if (isset($_POST['license_variation']) && get_post_meta($product_id, '_is_license_product', true) === 'yes') {
            $license_variations = get_post_meta($product_id, '_license_variations', true);
            $selected_variation_index = absint($_POST['license_variation']);
            
            if (isset($license_variations[$selected_variation_index]) && !empty($license_variations[$selected_variation_index]['price'])) {
                // Store original price to restore it later if needed
                $cart_item_data['original_price'] = get_post_meta($product_id, '_price', true);
                
                // Add license variation info
                $cart_item_data['license_variation'] = $license_variations[$selected_variation_index];
            }
        }
        
        return $cart_item_data;
    }
    
    /**
     * Set custom price based on license variation
     */
    public function set_license_variation_price($cart_object) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
    
        foreach ($cart_object->get_cart() as $cart_item) {
            if (isset($cart_item['license_variation']) && !empty($cart_item['license_variation']['price'])) {
                $cart_item['data']->set_price($cart_item['license_variation']['price']);
            }
        }
    }
    
    /**
     * Check if product is a license product and force display of license form
     */
    public function force_display_license_options() {
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
            
            // Pass variation prices to JS
            $license_variations = get_post_meta($product->get_id(), '_license_variations', true);
            if (!empty($license_variations)) {
                $variation_prices = [];
                foreach ($license_variations as $index => $variation) {
                    $variation_prices[$index] = !empty($variation['price']) ? $variation['price'] : $product->get_price();
                }
                
                wp_localize_script('wc-license-price-updater', 'licenseVariations', [
                    'prices' => $variation_prices,
                    'currencySymbol' => get_woocommerce_currency_symbol(),
                    'priceFormat' => get_woocommerce_price_format()
                ]);
            }
        }
    }
    
    /**
     * Display dynamic price container for JS updating
     */
    public function display_dynamic_price() {
        global $product;
        echo '<div class="price license-product-price">';
        echo '<span class="price-label">' . __('Price:', 'wc-product-license') . '</span> ';
        echo '<span class="dynamic-price"></span>';
        echo '</div>';
    }
    
    /**
     * Modified version of display_license_options to work with dynamic pricing
     */
    public function display_license_options() {
        global $product;
        
        if (!$product || $product->get_type() !== 'simple' || !$product->is_downloadable() || get_post_meta($product->get_id(), '_is_license_product', true) !== 'yes') {
            return;
        }
        
        $license_variations = get_post_meta($product->get_id(), '_license_variations', true);
        if (empty($license_variations)) {
            return;
        }
        
        echo '<div class="license-variations">';
        echo '<h3>' . __('License Options', 'wc-product-license') . '</h3>';
        
        echo '<div class="license-variation-selector">';
        foreach ($license_variations as $index => $variation) {
            $price = !empty($variation['price']) ? $variation['price'] : $product->get_price();
            
            echo '<div class="license-variation-option">';
            echo '<label>';
            echo '<input type="radio" name="license_variation" value="' . esc_attr($index) . '" ' . ($index === array_key_first($license_variations) ? 'checked' : '') . ' data-price="' . esc_attr($price) . '">';
            echo '<span class="license-title">' . esc_html($variation['title']) . '</span>';
            echo '<span class="license-details">' . sprintf(
                __('%d sites allowed, valid for %d days', 'wc-product-license'),
                $variation['sites'],
                $variation['validity']
            ) . '</span>';
            echo '</label>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
}

require_once plugin_dir_path(__FILE__) . 'include/admin.php';

function product_license_init()
{
    new WC_Product_License_Manager();
    new WC_Product_License_Admin();
}

add_action('plugins_loaded', 'product_license_init');


// Register activation hook
register_activation_hook(__FILE__, 'wc_product_license_activate');

function wc_product_license_activate() {
    // Set a flag to flush rewrite rules
    update_option('wc_license_manager_flush_rewrite_rules', true);
}