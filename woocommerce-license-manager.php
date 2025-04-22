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
class WC_Product_License_Manager
{

    public function __construct()
    {

        if(get_option('plugincywc_product_license_expiry') == 'yes') {
            
        // Product editing metabox
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_license_option_to_products']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_product_option']);

        // License variations
        add_action('woocommerce_product_options_downloads', [$this, 'add_license_variations_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_license_variations']);

        // Order management
        // add_action('woocommerce_checkout_order_processed', [$this, 'generate_license_keys'], 10, 3);
        add_action('woocommerce_thankyou', array($this, 'generate_license_keys'), 10, 2);
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
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'checkout_create_order_line_item'], 10, 4);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'display_license_options']);


        // hooks for price modification
        add_filter('woocommerce_add_cart_item_data', [$this, 'modify_cart_item_price'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'set_license_variation_price'], 10, 1);
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

        // Enqueue frontend script and localize data
        wp_enqueue_script('wc-license-manager-frontend');
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

            echo '<div class="license-key-item">';

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
            'message' => __('All sites successfully deactivated.', 'wc-product-license')
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

        // Get license variations for this product
        $license_variations = get_post_meta($product_id, '_license_variations', true);
        if (empty($license_variations)) {
            wp_send_json_error(['message' => __('No upgrade options available.', 'wc-product-license')]);
        }

        // Get product info
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : __('Unknown Product', 'wc-product-license');

        // Prepare upgrade URL to add to cart with package change
        $upgrade_url = wc_get_page_permalink('cart') . '?add-to-cart=' . $product_id . '&license_upgrade=' . $license_key;

        // Build HTML for upgrade modal
        $html = '<div class="upgrade-options-modal">';
        $html .= '<h3>' . sprintf(__('Upgrade/Downgrade Options for %s', 'wc-product-license'), esc_html($product_name)) . '</h3>';
        $html .= '<p>' . __('Select a package to upgrade or downgrade to:', 'wc-product-license') . '</p>';
        $html .= '<div class="upgrade-options-list">';

        foreach ($license_variations as $index => $variation) {
            $html .= '<div class="upgrade-option">';
            $html .= '<label>';
            $html .= '<input type="radio" name="upgrade_variation" value="' . esc_attr($index) . '">';
            $html .= '<span class="variation-title">' . esc_html($variation['title']) . '</span>';
            $html .= '<span class="variation-details">' . sprintf(
                __('%d sites allowed, valid for %d days - %s', 'wc-product-license'),
                $variation['sites'],
                $variation['validity'],
                wc_price($variation['price'])
            ) . '</span>';
            $html .= '</label>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div class="upgrade-actions">';
        $html .= '<a href="#" class="button cancel-upgrade">' . __('Cancel', 'wc-product-license') . '</a> ';
        $html .= '<a href="#" class="button button-primary confirm-upgrade" data-base-url="' . esc_url($upgrade_url) . '">' . __('Proceed to Checkout', 'wc-product-license') . '</a>';
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
        if (isset($_GET['license_upgrade']) && isset($_GET['upgrade_variation'])) {
            $license_key = sanitize_text_field($_GET['license_upgrade']);
            $variation_index = absint($_GET['upgrade_variation']);

            // Store upgrade info in cart item
            WC()->cart->cart_contents[$cart_item_key]['license_upgrade'] = [
                'license_key' => $license_key,
                'variation_index' => $variation_index
            ];

            // Add notice
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

        if ($license->user_id != get_current_user_id()) {
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
            'active_sites' => $active_sites
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
        // Check for POST data (standard product form)
        if (isset($_POST['license_variation']) && get_post_meta($product_id, '_is_license_product', true) === 'yes') {
            $license_variations = get_post_meta($product_id, '_license_variations', true);
            $selected_variation_index = absint($_POST['license_variation']);
            if (isset($license_variations[$selected_variation_index])) {
                $cart_item_data['license_variation'] = $license_variations[$selected_variation_index];
            }
        }

        // Check for GET data (from shortcode or direct links)
        elseif (isset($_GET['license_variation']) && get_post_meta($product_id, '_is_license_product', true) === 'yes') {
            $license_variations = get_post_meta($product_id, '_license_variations', true);
            $selected_variation_index = absint($_GET['license_variation']);
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

    public function modify_cart_item_price($cart_item_data, $product_id, $variation_id)
    {
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
    public function set_license_variation_price($cart_object)
    {
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
    public function display_dynamic_price()
    {
        global $product;
        echo '<div class="price license-product-price">';
        echo '<span class="price-label">' . __('Price:', 'wc-product-license') . '</span> ';
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
    // Check if license has expired
    $license_expiry = get_option('plugincywc_product_license_expiry');
    $current_date = current_time('timestamp');

    // If expiry date exists and has passed
    if ($license_expiry && strtotime($license_expiry) < $current_date) {
        // Update the license status to expired
        update_option('plugincywc_product_license_status', 'expired');

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
    $plugin_name = 'Your Plugin Name'; // Replace with your actual plugin name
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
 * [wclicence_price product="152" template="" variation="free"]
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
        'button_text' => __('Buy Now', 'wc-product-license'),
    ), $atts, 'wclicence_price');

    $product_id = absint($atts['product']);
    $template = sanitize_text_field($atts['template']);
    $variation = sanitize_text_field($atts['variation']);
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

    // Start output buffer
    ob_start();

    // Enqueue necessary scripts for functionality
    wp_enqueue_style('wc-license-shortcode-style', plugin_dir_url(__FILE__) . 'assets/css/shortcode.css');
    wp_enqueue_script('wc-license-shortcode-script', plugin_dir_url(__FILE__) . 'assets/js/shortcode.js', array('jquery'), '1.0.0', true);

    // If variation is specified, show direct checkout button
    if (!empty($variation)) {
        // Find the variation with the specified title
        $variation_index = null;
        foreach ($license_variations as $index => $var) {
            if (strtolower($var['title']) === strtolower($variation)) {
                $variation_index = $index;
                break;
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
            echo '<div class="wc-license-price">' . wc_price($license_var['price']) . '</div>';
            echo '<div class="wc-license-features">';
            echo '<span class="wc-license-sites">' . sprintf(__('%d sites', 'wc-product-license'), $license_var['sites']) . '</span>';
            echo '<span class="wc-license-validity">' . sprintf(__('%d days', 'wc-product-license'), $license_var['validity']) . '</span>';
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
                    echo '<div class="wc-license-card-price">' . wc_price($var['price']) . '</div>';
                    echo '<div class="wc-license-card-features">';
                    echo '<div class="wc-license-feature"><span class="dashicons dashicons-yes"></span> ' . sprintf(__('%d sites', 'wc-product-license'), $var['sites']) . '</div>';
                    echo '<div class="wc-license-feature"><span class="dashicons dashicons-calendar-alt"></span> ' . sprintf(__('%d days', 'wc-product-license'), $var['validity']) . '</div>';
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
                    echo '<td>' . esc_html($var['sites']) . '</td>';
                    echo '<td>' . sprintf(__('%d days', 'wc-product-license'), $var['validity']) . '</td>';
                    echo '<td>' . wc_price($var['price']) . '</td>';
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
                    echo '<div class="wc-license-toggle-price">' . wc_price($var['price']) . '</div>';
                    echo '<div class="wc-license-toggle-details">';
                    echo '<div class="wc-license-toggle-sites">' . sprintf(__('%d sites', 'wc-product-license'), $var['sites']) . '</div>';
                    echo '<div class="wc-license-toggle-validity">' . sprintf(__('%d days license', 'wc-product-license'), $var['validity']) . '</div>';
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
                    echo '<span class="wc-license-checklist-price">' . wc_price($var['price']) . '</span>';
                    echo '</div>';

                    echo '<div class="wc-license-checklist-details">';
                    echo '<span class="wc-license-sites-badge">' . sprintf(__('%d sites allowed', 'wc-product-license'), $var['sites']) . '</span>';
                    echo '<span class="wc-license-validity-badge">' . sprintf(__('%d days validity', 'wc-product-license'), $var['validity']) . '</span>';
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
                    echo '<span class="wc-license-option-price">' . wc_price($var['price']) . '</span>';
                    echo '<span class="wc-license-option-details">' . sprintf(__('%d sites, %d days', 'wc-product-license'), $var['sites'], $var['validity']) . '</span>';
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
