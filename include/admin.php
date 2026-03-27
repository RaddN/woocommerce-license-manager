<?php

/**
 * Admin functionality for WooCommerce Product License Manager
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('wc_product_license_get_effective_status')) {
    function wc_product_license_get_effective_status($license)
    {
        if (!$license) {
            return 'inactive';
        }

        $status = is_array($license) ? (string) ($license['status'] ?? 'inactive') : (string) ($license->status ?? 'inactive');
        $expires_at = is_array($license) ? (string) ($license['expires_at'] ?? '') : (string) ($license->expires_at ?? '');

        if ($expires_at !== '' && $expires_at !== '0000-00-00 00:00:00') {
            $expiry_timestamp = strtotime($expires_at);
            if ($expiry_timestamp && $expiry_timestamp < current_time('timestamp')) {
                return 'expired';
            }
        }

        if ($status === 'active' || $status === 'expired') {
            return $status;
        }

        return 'inactive';
    }
}

if (!function_exists('wc_product_license_get_cached_user_object')) {
    function wc_product_license_get_cached_user_object($user_id)
    {
        static $cache = [];

        $user_id = absint($user_id);
        if ($user_id < 1) {
            return null;
        }

        if (!array_key_exists($user_id, $cache)) {
            $cache[$user_id] = get_user_by('id', $user_id) ?: null;
        }

        return $cache[$user_id];
    }
}

if (!function_exists('wc_product_license_get_cached_order_object')) {
    function wc_product_license_get_cached_order_object($order_id)
    {
        static $cache = [];

        $order_id = absint($order_id);
        if ($order_id < 1) {
            return null;
        }

        if (!array_key_exists($order_id, $cache)) {
            $cache[$order_id] = wc_get_order($order_id) ?: null;
        }

        return $cache[$order_id];
    }
}

if (!function_exists('wc_product_license_get_customer_manage_url')) {
    function wc_product_license_get_customer_manage_url($customer_key, $args = [])
    {
        $args = wp_parse_args($args, [
            'page' => 'wc-license-customers',
            'action' => 'manage',
            'customer_key' => (string) $customer_key,
        ]);

        return add_query_arg($args, admin_url('admin.php'));
    }
}

if (!function_exists('wc_product_license_get_customer_type_label')) {
    function wc_product_license_get_customer_type_label($type)
    {
        $labels = [
            'registered' => __('Registered account', 'wc-product-license'),
            'guest' => __('Guest checkout', 'wc-product-license'),
            'manual_guest' => __('Manual guest', 'wc-product-license'),
            'unknown' => __('Unknown customer', 'wc-product-license'),
        ];

        return isset($labels[$type]) ? $labels[$type] : __('Unknown customer', 'wc-product-license');
    }
}

if (!function_exists('wc_product_license_get_customer_context_from_license')) {
    function wc_product_license_get_customer_context_from_license($license)
    {
        $license_id = is_array($license) ? absint($license['id'] ?? 0) : absint($license->id ?? 0);
        $user_id = is_array($license) ? absint($license['user_id'] ?? 0) : absint($license->user_id ?? 0);
        $order_id = is_array($license) ? absint($license['order_id'] ?? 0) : absint($license->order_id ?? 0);

        $user = wc_product_license_get_cached_user_object($user_id);
        $order = wc_product_license_get_cached_order_object($order_id);
        $email = '';
        $name = '';
        $company = '';
        $type = 'unknown';
        $user_url = '';

        if ($user) {
            $type = 'registered';
            $email = (string) $user->user_email;
            $name = trim((string) $user->display_name);
            if ($name === '') {
                $name = trim((string) $user->first_name . ' ' . (string) $user->last_name);
            }
            if ($name === '') {
                $name = $email !== '' ? $email : __('Registered customer', 'wc-product-license');
            }
            $user_url = admin_url('user-edit.php?user_id=' . $user->ID);
        } elseif ($order) {
            $type = 'guest';
            $email = sanitize_email($order->get_billing_email());
            $name = trim((string) $order->get_formatted_billing_full_name());
            $company = trim((string) $order->get_billing_company());

            if ($name === '') {
                $name = $company;
            }
            if ($name === '') {
                $name = $email !== '' ? $email : __('Guest checkout', 'wc-product-license');
            }
        } else {
            $type = $user_id > 0 ? 'unknown' : 'manual_guest';
            $name = $user_id > 0 ? __('Unknown customer', 'wc-product-license') : __('Guest checkout', 'wc-product-license');
        }

        if ($user_id > 0 && $user) {
            $key = 'user:' . $user_id;
        } elseif ($email !== '') {
            $key = 'guest:' . strtolower($email);
        } elseif ($order_id > 0) {
            $key = 'order-guest:' . $order_id;
        } else {
            $key = 'guest-license:' . $license_id;
        }

        return [
            'key' => $key,
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'type' => $type,
            'type_label' => wc_product_license_get_customer_type_label($type),
            'user_id' => $user ? (int) $user->ID : 0,
            'user_url' => $user_url,
            'order_id' => $order_id,
            'customer_url' => wc_product_license_get_customer_manage_url($key),
        ];
    }
}

if (!function_exists('wc_product_license_get_customer_directory')) {
    function wc_product_license_get_customer_directory($force_refresh = false)
    {
        static $cache = null;

        if (!$force_refresh && is_array($cache)) {
            return $cache;
        }

        global $wpdb;

        $licenses_table = wc_product_license_get_table_name('licenses');
        $licenses = $wpdb->get_results("SELECT * FROM {$licenses_table} ORDER BY purchased_at DESC, id DESC");
        $licenses = is_array($licenses) ? $licenses : [];
        $expiring_cutoff = strtotime('+30 days', current_time('timestamp'));
        $customers = [];

        foreach ($licenses as $license) {
            $context = wc_product_license_get_customer_context_from_license($license);
            $key = (string) $context['key'];
            $effective_status = wc_product_license_get_effective_status($license);
            $purchased_at = !empty($license->purchased_at) ? strtotime((string) $license->purchased_at) : false;
            $expires_at = !empty($license->expires_at) ? strtotime((string) $license->expires_at) : false;

            if (!isset($customers[$key])) {
                $customers[$key] = [
                    'key' => $key,
                    'name' => $context['name'],
                    'email' => $context['email'],
                    'company' => $context['company'],
                    'type' => $context['type'],
                    'type_label' => $context['type_label'],
                    'user_id' => $context['user_id'],
                    'user_url' => $context['user_url'],
                    'customer_url' => $context['customer_url'],
                    'license_ids' => [],
                    'order_ids' => [],
                    'licenses' => [],
                    'emails' => [],
                    'revenue' => 0.0,
                    'activations' => 0,
                    'license_count' => 0,
                    'active_licenses' => 0,
                    'inactive_licenses' => 0,
                    'expired_licenses' => 0,
                    'expiring_licenses' => 0,
                    'first_purchase_at' => '',
                    'last_purchase_at' => '',
                    'latest_order_id' => 0,
                    'latest_license_id' => 0,
                ];
            }

            $customers[$key]['license_ids'][] = (int) $license->id;
            if (!empty($license->order_id)) {
                $customers[$key]['order_ids'][] = (int) $license->order_id;
            }
            $customers[$key]['licenses'][] = $license;
            $customers[$key]['license_count']++;
            $customers[$key]['revenue'] += (float) $license->purchased_price;
            $customers[$key]['activations'] += (int) $license->sites_active;
            $customers[$key][$effective_status . '_licenses']++;

            if ($context['email'] !== '') {
                $customers[$key]['emails'][] = $context['email'];
            }
            if ($customers[$key]['email'] === '' && $context['email'] !== '') {
                $customers[$key]['email'] = $context['email'];
            }
            if (
                ($customers[$key]['name'] === '' || $customers[$key]['name'] === __('Guest checkout', 'wc-product-license') || $customers[$key]['name'] === __('Unknown customer', 'wc-product-license'))
                && $context['name'] !== ''
            ) {
                $customers[$key]['name'] = $context['name'];
            }
            if ($customers[$key]['company'] === '' && $context['company'] !== '') {
                $customers[$key]['company'] = $context['company'];
            }

            if ($effective_status === 'active' && $expires_at && $expires_at <= $expiring_cutoff) {
                $customers[$key]['expiring_licenses']++;
            }

            if ($purchased_at) {
                if ($customers[$key]['first_purchase_at'] === '' || $purchased_at < strtotime($customers[$key]['first_purchase_at'])) {
                    $customers[$key]['first_purchase_at'] = (string) $license->purchased_at;
                }

                if ($customers[$key]['last_purchase_at'] === '' || $purchased_at > strtotime($customers[$key]['last_purchase_at'])) {
                    $customers[$key]['last_purchase_at'] = (string) $license->purchased_at;
                    $customers[$key]['latest_order_id'] = !empty($license->order_id) ? (int) $license->order_id : 0;
                    $customers[$key]['latest_license_id'] = (int) $license->id;
                }
            }
        }

        foreach ($customers as &$customer) {
            $customer['license_ids'] = array_values(array_unique(array_map('absint', $customer['license_ids'])));
            $customer['order_ids'] = array_values(array_unique(array_filter(array_map('absint', $customer['order_ids']))));
            $customer['emails'] = array_values(array_unique(array_filter(array_map('sanitize_email', $customer['emails']))));
            $customer['order_count'] = count($customer['order_ids']);
            $customer['manage_url'] = wc_product_license_get_customer_manage_url($customer['key']);

            usort($customer['licenses'], static function ($left, $right) {
                return strtotime((string) $right->purchased_at) <=> strtotime((string) $left->purchased_at);
            });
        }
        unset($customer);

        uasort($customers, static function ($left, $right) {
            $left_timestamp = !empty($left['last_purchase_at']) ? strtotime((string) $left['last_purchase_at']) : 0;
            $right_timestamp = !empty($right['last_purchase_at']) ? strtotime((string) $right['last_purchase_at']) : 0;

            if ($left_timestamp === $right_timestamp) {
                return strnatcasecmp((string) $left['name'], (string) $right['name']);
            }

            return $right_timestamp <=> $left_timestamp;
        });

        $cache = $customers;

        return $cache;
    }
}

if (!function_exists('wc_product_license_get_customer_record')) {
    function wc_product_license_get_customer_record($customer_key)
    {
        $customer_key = (string) $customer_key;
        $customers = wc_product_license_get_customer_directory();

        return isset($customers[$customer_key]) ? $customers[$customer_key] : null;
    }
}

if (!function_exists('wc_product_license_get_customer_orders')) {
    function wc_product_license_get_customer_orders($customer)
    {
        $orders = [];
        $order_ids = isset($customer['order_ids']) && is_array($customer['order_ids']) ? $customer['order_ids'] : [];

        foreach ($order_ids as $order_id) {
            $order = wc_product_license_get_cached_order_object($order_id);
            if ($order) {
                $orders[] = $order;
            }
        }

        usort($orders, static function ($left, $right) {
            $left_time = $left && $left->get_date_created() ? $left->get_date_created()->getTimestamp() : 0;
            $right_time = $right && $right->get_date_created() ? $right->get_date_created()->getTimestamp() : 0;

            return $right_time <=> $left_time;
        });

        return $orders;
    }
}

if (!function_exists('wc_product_license_get_customer_activity_logs')) {
    function wc_product_license_get_customer_activity_logs($customer, $limit = 60)
    {
        global $wpdb;

        $license_ids = isset($customer['license_ids']) && is_array($customer['license_ids']) ? array_filter(array_map('absint', $customer['license_ids'])) : [];
        if (empty($license_ids)) {
            return [];
        }

        $limit = max(1, absint($limit));
        $activity_table = wc_product_license_get_table_name('activity');
        $license_sql = implode(',', $license_ids);
        $logs = $wpdb->get_results("SELECT * FROM {$activity_table} WHERE license_id IN ({$license_sql}) ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $logs = is_array($logs) ? $logs : [];

        foreach ($logs as $log) {
            $decoded = !empty($log->details) ? json_decode((string) $log->details, true) : [];
            $log->details_data = is_array($decoded) ? $decoded : [];
        }

        return $logs;
    }
}

if (!function_exists('wc_product_license_get_customer_activity_log_count')) {
    function wc_product_license_get_customer_activity_log_count($customer)
    {
        global $wpdb;

        $license_ids = isset($customer['license_ids']) && is_array($customer['license_ids']) ? array_filter(array_map('absint', $customer['license_ids'])) : [];
        if (empty($license_ids)) {
            return 0;
        }

        $activity_table = wc_product_license_get_table_name('activity');
        $license_sql = implode(',', $license_ids);

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$activity_table} WHERE license_id IN ({$license_sql})");
    }
}

if (!function_exists('wc_product_license_get_site_manage_url')) {
    function wc_product_license_get_site_manage_url($activation_id, $args = [])
    {
        $args = wp_parse_args($args, [
            'page' => 'wc-license-sites',
            'action' => 'manage',
            'activation_id' => absint($activation_id),
        ]);

        return add_query_arg($args, admin_url('admin.php'));
    }
}

if (!function_exists('wc_product_license_get_activation_record')) {
    function wc_product_license_get_activation_record($activation_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . wc_product_license_get_table_name('activations') . ' WHERE id = %d LIMIT 1',
            absint($activation_id)
        ));
    }
}

if (!function_exists('wc_product_license_get_activation_logs')) {
    function wc_product_license_get_activation_logs($activation, $limit = 50)
    {
        global $wpdb;

        $activation = is_numeric($activation) ? wc_product_license_get_activation_record($activation) : $activation;
        if (!$activation || empty($activation->license_id) || empty($activation->site_url)) {
            return [];
        }

        $limit = max(1, absint($limit));
        $activity_table = wc_product_license_get_table_name('activity');
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$activity_table} WHERE license_id = %d AND site_url = %s ORDER BY created_at DESC, id DESC LIMIT %d",
            absint($activation->license_id),
            (string) $activation->site_url,
            $limit
        ));
        $logs = is_array($logs) ? $logs : [];

        foreach ($logs as $log) {
            $decoded = !empty($log->details) ? json_decode((string) $log->details, true) : [];
            $log->details_data = is_array($decoded) ? $decoded : [];
        }

        return $logs;
    }
}

if (!function_exists('wc_product_license_get_activation_log_count')) {
    function wc_product_license_get_activation_log_count($activation)
    {
        global $wpdb;

        $activation = is_numeric($activation) ? wc_product_license_get_activation_record($activation) : $activation;
        if (!$activation || empty($activation->license_id) || empty($activation->site_url)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . wc_product_license_get_table_name('activity') . ' WHERE license_id = %d AND site_url = %s',
            absint($activation->license_id),
            (string) $activation->site_url
        ));
    }
}

if (!function_exists('wc_product_license_get_product_label')) {
    function wc_product_license_get_product_label($product_id, $fallback = '')
    {
        $product_id = absint($product_id);
        if ($product_id < 1) {
            return $fallback !== '' ? (string) $fallback : __('Unknown product', 'wc-product-license');
        }

        $product = wc_get_product($product_id);
        if ($product) {
            return $product->get_name();
        }

        $title = get_the_title($product_id);
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return $fallback !== '' ? (string) $fallback : sprintf(__('Product #%d', 'wc-product-license'), $product_id);
    }
}

if (!function_exists('wc_product_license_get_dashboard_url')) {
    function wc_product_license_get_dashboard_url($args = [])
    {
        return add_query_arg($args, admin_url('admin.php?page=wc-license-dashboard'));
    }
}

if (!function_exists('wc_product_license_get_tracked_product_options')) {
    function wc_product_license_get_tracked_product_options($args = [])
    {
        global $wpdb;

        $args = wp_parse_args($args, [
            'include_empty' => true,
        ]);

        $counts = [];
        $count_rows = $wpdb->get_results(
            'SELECT product_id, COUNT(*) AS total, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) AS inactive FROM ' . wc_product_license_get_table_name('activations') . ' WHERE product_id > 0 GROUP BY product_id',
            ARRAY_A
        );
        $count_rows = is_array($count_rows) ? $count_rows : [];

        foreach ($count_rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            if ($product_id < 1) {
                continue;
            }

            $counts[$product_id] = [
                'total' => absint($row['total'] ?? 0),
                'active' => absint($row['active'] ?? 0),
                'inactive' => absint($row['inactive'] ?? 0),
            ];
        }

        $product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_key' => '_is_license_product',
            'meta_value' => 'yes',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $product_ids = is_array($product_ids) ? $product_ids : [];
        $product_ids = array_values(array_unique(array_filter(array_merge($product_ids, array_keys($counts)))));

        $options = [];

        foreach ($product_ids as $product_id) {
            $product_id = absint($product_id);
            if ($product_id < 1) {
                continue;
            }

            $product_counts = isset($counts[$product_id]) ? $counts[$product_id] : [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
            ];

            if (empty($args['include_empty']) && $product_counts['total'] < 1) {
                continue;
            }

            $label = wc_product_license_get_product_label($product_id);

            $options[$product_id] = [
                'id' => $product_id,
                'name' => $label,
                'total' => (int) $product_counts['total'],
                'active' => (int) $product_counts['active'],
                'inactive' => (int) $product_counts['inactive'],
                'edit_url' => admin_url('post.php?post=' . $product_id . '&action=edit'),
                'dashboard_url' => wc_product_license_get_dashboard_url(['product_id' => $product_id]),
                'sites_url' => add_query_arg(['product_id' => $product_id], admin_url('admin.php?page=wc-license-sites')),
            ];
        }

        uasort($options, static function ($left, $right) {
            return strnatcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $options;
    }
}

class WC_Product_License_Admin
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Add admin menu items
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Add custom actions to the licenses list table
        add_filter('bulk_actions-wc_license_keys', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-wc_license_keys', [$this, 'handle_bulk_actions'], 10, 3);

        // Ajax handlers
        add_action('wp_ajax_wc_license_activate', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_wc_license_deactivate', [$this, 'ajax_deactivate_license']);
        add_action('wp_ajax_wc_license_delete', [$this, 'ajax_delete_license']);

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu()
    {
        // Main License Keys menu
        add_menu_page(
            __('Licenses Manage', 'wc-product-license'),
            __('Licenses Manage', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-dashboard',
            [new WC_License_Analytics(), 'render_analytics_page'],
            'dashicons-lock',
            56 // Position after WooCommerce
        );

        // License Keys submenu
        add_submenu_page(
            'wc-license-dashboard',
            __('Licenses Manage', 'wc-product-license'),
            __('Dashboard', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-dashboard',
        );

        // License Keys submenu
        add_submenu_page(
            'wc-license-dashboard',
            __('All Licenses', 'wc-product-license'),
            __('All Licenses', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-keys',
            [$this, 'render_licenses_page'],
        );

        add_submenu_page(
            'wc-license-dashboard',
            __('Customers', 'wc-product-license'),
            __('Customers', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-customers',
            [$this, 'render_customers_page'],
        );

        add_submenu_page(
            'wc-license-dashboard',
            __('Sites', 'wc-product-license'),
            __('Sites', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-sites',
            [$this, 'render_sites_page'],
        );

        // Add New License submenu
        add_submenu_page(
            'wc-license-dashboard',
            __('Add New License', 'wc-product-license'),
            __('Add New', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-add-new',
            [$this, 'render_add_license_page']
        );

        // Settings submenu
        add_submenu_page(
            'wc-license-dashboard',
            __('License Settings', 'wc-product-license'),
            __('Settings', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'wc-license') === false) {
            return;
        }

        wp_enqueue_style(
            'wc-license-admin-styles',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'wc-license-admin-scripts',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-sortable'],
            '1.0.0',
            true
        );

        wp_localize_script('wc-license-admin-scripts', 'wcLicenseAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-license-admin-nonce'),
            'i18n' => [
                'confirmDelete' => __('Are you sure you want to delete this license?', 'wc-product-license'),
                'confirmDeactivate' => __('Are you sure you want to disable this license?', 'wc-product-license'),
                'confirmActivate' => __('Are you sure you want to enable this license?', 'wc-product-license'),
                'processing' => __('Processing...', 'wc-product-license'),
                'success' => __('Success!', 'wc-product-license'),
                'error' => __('Error:', 'wc-product-license'),
                'serverError' => __('Server error occurred. Please try again.', 'wc-product-license'),
                'licenseDetails' => __('License Details', 'wc-product-license'),
                'licenseKey' => __('License Key', 'wc-product-license'),
                'product' => __('Product', 'wc-product-license'),
                'user' => __('User', 'wc-product-license'),
                'status' => __('Status', 'wc-product-license'),
                'expiresAt' => __('Expires At', 'wc-product-license'),
                'activations' => __('Activations', 'wc-product-license'),
                'sitesAllowed' => __('Sites Allowed', 'wc-product-license'),
                'sitesActive' => __('Sites Active', 'wc-product-license'),
                'activate' => __('Enable', 'wc-product-license'),
                'deactivate' => __('Disable', 'wc-product-license')
            ]
        ]);
    }

    /**
     * Render the licenses page
     */
    public function render_licenses_page()
    {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if (in_array($action, ['edit', 'manage'], true) && isset($_GET['license_id'])) {
            $this->render_license_details_page((int) $_GET['license_id']);
            return;
        }

        // Include the WP_List_Table class if not available
        if (!class_exists('WP_List_Table')) {
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
        }

        // Include our custom license table class
        require_once dirname(__FILE__) . '/class-wc-license-list-table.php';

        // Create an instance of our table class
        $licenses_table = new WC_License_List_Table();

        // Prepare the items for display
        $licenses_table->prepare_items();

        $status_counts = $this->get_license_status_counts();
        $summary = $this->get_license_list_summary();
        $current_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filters = [
            '' => __('All', 'wc-product-license'),
            'active' => __('Active', 'wc-product-license'),
            'inactive' => __('Inactive', 'wc-product-license'),
            'expired' => __('Expired', 'wc-product-license'),
            'expiring' => __('Expiring Soon', 'wc-product-license'),
        ];

?>
        <div class="wrap wc-license-admin-page">
            <div class="wc-license-admin-header">
                <div class="wc-license-admin-header__copy">
                    <h1 class="wp-heading-inline"><?php _e('Licenses', 'wc-product-license'); ?></h1>
                    <p><?php _e('Review license health, open customer records, and manage renewals, activations, and fulfillment from one place.', 'wc-product-license'); ?></p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-license-add-new')); ?>" class="page-title-action"><?php _e('Add New', 'wc-product-license'); ?></a>
                </div>
            </div>

            <?php $this->render_license_admin_notices(); ?>

            <div class="wc-license-admin-summary">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Tracked licenses', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['total'])); ?></strong>
                    <small><?php esc_html_e('All keys currently stored in the license database.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Active', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['active'])); ?></strong>
                    <small><?php esc_html_e('Currently available for activation and update checks.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Expiring soon', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['expiring'])); ?></strong>
                    <small><?php esc_html_e('Licenses reaching expiry within the next 30 days.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Activations in use', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['activations'])); ?></strong>
                    <small><?php esc_html_e('Combined site activations across all licenses.', 'wc-product-license'); ?></small>
                </div>
            </div>

            <ul class="subsubsub wc-license-admin-filters">
                <?php
                $filter_markup = [];
                foreach ($filters as $filter_key => $filter_label) {
                    $count_key = $filter_key === '' ? 'all' : $filter_key;
                    $filter_url = $this->get_license_list_url(array_filter([
                        'status' => $filter_key,
                        's' => $search,
                    ], static function ($value) {
                        return $value !== '';
                    }));
                    $classes = $current_status === $filter_key ? 'current' : '';
                    $filter_markup[] = sprintf(
                        '<li class="%1$s"><a href="%2$s" class="%1$s">%3$s <span class="count">(%4$s)</span></a>',
                        esc_attr($classes),
                        esc_url($filter_url),
                        esc_html($filter_label),
                        esc_html(number_format_i18n(isset($status_counts[$count_key]) ? $status_counts[$count_key] : 0))
                    );
                }
                echo implode(' | </li>', $filter_markup) . '</li>';
                ?>
            </ul>

            <form id="licenses-filter" method="get">
                <input type="hidden" name="page" value="wc-license-keys" />
                <?php if ($current_status !== '') : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                <?php endif; ?>
                <?php
                $licenses_table->search_box(__('Search Licenses', 'wc-product-license'), 'license-search');
                $licenses_table->display();
                ?>
            </form>
        </div>
    <?php
    }

    public function render_customers_page()
    {
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $customer_key = isset($_GET['customer_key']) ? sanitize_text_field(wp_unslash($_GET['customer_key'])) : '';

        if ($action === 'manage' && $customer_key !== '') {
            $this->render_customer_details_page($customer_key);
            return;
        }

        if (!class_exists('WP_List_Table')) {
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
        }

        require_once dirname(__FILE__) . '/class-wc-license-customer-list-table.php';

        $customers_table = new WC_License_Customer_List_Table();
        $customers_table->prepare_items();

        $summary = $this->get_customer_list_summary();
        $segment_counts = $this->get_customer_segment_counts();
        $current_segment = isset($_GET['segment']) ? sanitize_key(wp_unslash($_GET['segment'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filters = [
            '' => __('All', 'wc-product-license'),
            'registered' => __('Registered', 'wc-product-license'),
            'guest' => __('Guest', 'wc-product-license'),
            'active' => __('Active Licenses', 'wc-product-license'),
            'expiring' => __('Expiring Soon', 'wc-product-license'),
        ];
        ?>
        <div class="wrap wc-license-admin-page">
            <div class="wc-license-admin-header">
                <div class="wc-license-admin-header__copy">
                    <h1 class="wp-heading-inline"><?php esc_html_e('Customers', 'wc-product-license'); ?></h1>
                    <p><?php esc_html_e('Track every buyer tied to licensed products, including guest purchases, linked WooCommerce orders, license revenue, and activation activity.', 'wc-product-license'); ?></p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <a href="<?php echo esc_url($this->get_license_list_url()); ?>" class="button button-secondary"><?php esc_html_e('View Licenses', 'wc-product-license'); ?></a>
                </div>
            </div>

            <div class="wc-license-admin-summary">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Tracked customers', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['total'])); ?></strong>
                    <small><?php esc_html_e('Registered accounts and guest buyers grouped by real purchase history.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Registered accounts', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['registered'])); ?></strong>
                    <small><?php esc_html_e('Customers linked directly to a WordPress user account.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Guest buyers', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['guest'])); ?></strong>
                    <small><?php esc_html_e('Guest records grouped by billing email, so checkout-only customers stay visible.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Tracked license revenue', 'wc-product-license'); ?></span>
                    <strong><?php echo wp_kses_post(wc_price($summary['revenue'])); ?></strong>
                    <small><?php esc_html_e('Summed from fulfilled license purchases to avoid losing licensing value inside mixed Woo orders.', 'wc-product-license'); ?></small>
                </div>
            </div>

            <ul class="subsubsub wc-license-admin-filters">
                <?php
                $filter_markup = [];
                foreach ($filters as $filter_key => $filter_label) {
                    $count_key = $filter_key === '' ? 'all' : $filter_key;
                    $filter_url = $this->get_customer_list_url(array_filter([
                        'segment' => $filter_key,
                        's' => $search,
                    ], static function ($value) {
                        return $value !== '';
                    }));
                    $classes = $current_segment === $filter_key ? 'current' : '';
                    $filter_markup[] = sprintf(
                        '<li class="%1$s"><a href="%2$s" class="%1$s">%3$s <span class="count">(%4$s)</span></a>',
                        esc_attr($classes),
                        esc_url($filter_url),
                        esc_html($filter_label),
                        esc_html(number_format_i18n(isset($segment_counts[$count_key]) ? $segment_counts[$count_key] : 0))
                    );
                }
                echo implode(' | </li>', $filter_markup) . '</li>';
                ?>
            </ul>

            <form id="customers-filter" method="get">
                <input type="hidden" name="page" value="wc-license-customers" />
                <?php if ($current_segment !== '') : ?>
                    <input type="hidden" name="segment" value="<?php echo esc_attr($current_segment); ?>" />
                <?php endif; ?>
                <?php
                $customers_table->search_box(__('Search Customers', 'wc-product-license'), 'customer-search');
                $customers_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private function get_customer_list_summary()
    {
        $customers = wc_product_license_get_customer_directory();
        $summary = [
            'total' => 0,
            'registered' => 0,
            'guest' => 0,
            'revenue' => 0.0,
        ];

        foreach ($customers as $customer) {
            $summary['total']++;
            $summary['revenue'] += isset($customer['revenue']) ? (float) $customer['revenue'] : 0.0;

            if (($customer['type'] ?? '') === 'registered') {
                $summary['registered']++;
            } else {
                $summary['guest']++;
            }
        }

        return $summary;
    }

    private function get_customer_segment_counts()
    {
        $customers = wc_product_license_get_customer_directory();
        $counts = [
            'all' => 0,
            'registered' => 0,
            'guest' => 0,
            'active' => 0,
            'expiring' => 0,
        ];

        foreach ($customers as $customer) {
            $counts['all']++;

            if (($customer['type'] ?? '') === 'registered') {
                $counts['registered']++;
            } else {
                $counts['guest']++;
            }

            if (!empty($customer['active_licenses'])) {
                $counts['active']++;
            }

            if (!empty($customer['expiring_licenses'])) {
                $counts['expiring']++;
            }
        }

        return $counts;
    }

    private function get_customer_list_url($args = [])
    {
        return add_query_arg($args, admin_url('admin.php?page=wc-license-customers'));
    }

    private function get_customer_type_badge($type)
    {
        $classes = [
            'registered' => 'is-registered',
            'guest' => 'is-guest',
            'manual_guest' => 'is-guest',
            'unknown' => 'is-unknown',
        ];

        return sprintf(
            '<span class="wc-license-customer-badge %1$s">%2$s</span>',
            esc_attr(isset($classes[$type]) ? $classes[$type] : 'is-unknown'),
            esc_html(wc_product_license_get_customer_type_label($type))
        );
    }

    private function get_customer_datetime_label($datetime, $fallback = '')
    {
        if (empty($datetime)) {
            return $fallback !== '' ? $fallback : __('Not recorded', 'wc-product-license');
        }

        $timestamp = strtotime((string) $datetime);
        if (!$timestamp) {
            return $fallback !== '' ? $fallback : __('Not recorded', 'wc-product-license');
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function get_customer_order_status_badge($status)
    {
        $status = sanitize_key((string) $status);
        $label = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : ucfirst($status);

        return sprintf(
            '<span class="wc-license-customer-order-status status-%1$s">%2$s</span>',
            esc_attr($status),
            esc_html($label)
        );
    }

    private function render_customer_details_page($customer_key)
    {
        $customer = wc_product_license_get_customer_record($customer_key);
        if (!$customer) {
            wp_die(__('Customer not found.', 'wc-product-license'));
        }

        $licenses = isset($customer['licenses']) && is_array($customer['licenses']) ? $customer['licenses'] : [];
        $orders = wc_product_license_get_customer_orders($customer);
        $activity_logs = wc_product_license_get_customer_activity_logs($customer, 80);
        $activity_count = wc_product_license_get_customer_activity_log_count($customer);
        $latest_order = !empty($orders) ? $orders[0] : null;
        $latest_license = !empty($licenses) ? $licenses[0] : null;
        $customer_email = $customer['email'] !== '' ? $customer['email'] : (!empty($customer['emails']) ? $customer['emails'][0] : '');
        $customer_since = $this->get_customer_datetime_label($customer['first_purchase_at'], __('No purchase date recorded', 'wc-product-license'));
        $last_purchase = $this->get_customer_datetime_label($customer['last_purchase_at'], __('No purchase date recorded', 'wc-product-license'));
        $latest_license_url = $latest_license ? $this->get_license_manage_url($latest_license->id) : '';
        $license_map = [];
        $licenses_by_order = [];
        $status_breakdown = [
            'active' => (int) ($customer['active_licenses'] ?? 0),
            'inactive' => (int) ($customer['inactive_licenses'] ?? 0),
            'expired' => (int) ($customer['expired_licenses'] ?? 0),
            'expiring' => (int) ($customer['expiring_licenses'] ?? 0),
        ];

        foreach ($licenses as $license) {
            $license_map[(int) $license->id] = $license;
            if (!empty($license->order_id)) {
                if (!isset($licenses_by_order[(int) $license->order_id])) {
                    $licenses_by_order[(int) $license->order_id] = [];
                }
                $licenses_by_order[(int) $license->order_id][] = $license;
            }
        }
        ?>
        <div class="wrap wc-license-admin-page wc-license-detail-page wc-license-customer-detail-page">
            <div class="wc-license-detail-header">
                <div class="wc-license-admin-header__copy">
                    <a href="<?php echo esc_url($this->get_customer_list_url()); ?>" class="page-title-action wc-license-detail-header__back"><?php esc_html_e('Back to Customers', 'wc-product-license'); ?></a>
                    <h1><?php echo esc_html(sprintf(__('Customer Details: %s', 'wc-product-license'), $customer['name'])); ?></h1>
                    <p><?php esc_html_e('Review the customer profile, linked licenses, related WooCommerce orders, and the full activity trail from one place.', 'wc-product-license'); ?></p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <?php if ($customer_email !== '') : ?>
                        <a href="<?php echo esc_url('mailto:' . antispambot($customer_email)); ?>" class="button button-secondary"><?php esc_html_e('Email Customer', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($customer['user_url'])) : ?>
                        <a href="<?php echo esc_url($customer['user_url']); ?>" class="button button-secondary"><?php esc_html_e('View WordPress User', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if ($latest_license_url) : ?>
                        <a href="<?php echo esc_url($latest_license_url); ?>" class="button button-secondary"><?php esc_html_e('Latest License', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wc-license-admin-summary wc-license-admin-summary--details">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Tracked licenses', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n((int) $customer['license_count'])); ?></strong>
                    <small><?php echo esc_html(sprintf(_n('%d active license', '%d active licenses', (int) $customer['active_licenses'], 'wc-product-license'), (int) $customer['active_licenses'])); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Orders', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n((int) $customer['order_count'])); ?></strong>
                    <small><?php echo esc_html($last_purchase); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('License revenue', 'wc-product-license'); ?></span>
                    <strong><?php echo wp_kses_post(wc_price((float) $customer['revenue'])); ?></strong>
                    <small><?php esc_html_e('Summed from linked license purchases.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Activations in use', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n((int) $customer['activations'])); ?></strong>
                    <small><?php echo esc_html(sprintf(_n('%d license expiring soon', '%d licenses expiring soon', (int) $customer['expiring_licenses'], 'wc-product-license'), (int) $customer['expiring_licenses'])); ?></small>
                </div>
            </div>

            <div class="wc-license-detail-tabs" data-wc-license-detail-tabs data-license-detail-hash-prefix="customer">
                <div class="wc-license-detail-tabs__nav" role="tablist" aria-label="<?php esc_attr_e('Customer sections', 'wc-product-license'); ?>">
                    <button type="button" class="wc-license-detail-tabs__nav-item is-active" data-license-detail-tab-target="overview" role="tab" aria-selected="true" aria-controls="wc-license-detail-overview">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Overview', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($customer_since); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="profile" role="tab" aria-selected="false" aria-controls="wc-license-detail-profile">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Profile', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($customer['type_label']); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="licenses" role="tab" aria-selected="false" aria-controls="wc-license-detail-licenses">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Licenses', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Every linked key', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n((int) $customer['license_count'])); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="orders" role="tab" aria-selected="false" aria-controls="wc-license-detail-orders">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Orders', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('WooCommerce order links', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n((int) $customer['order_count'])); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="activity" role="tab" aria-selected="false" aria-controls="wc-license-detail-activity">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Activity Log', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Cross-license timeline', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n($activity_count)); ?></span>
                    </button>
                </div>

                <div class="wc-license-detail-tabs__content">
                    <?php $this->render_customer_overview_tab($customer, $orders, $latest_order, $latest_license, $status_breakdown, $customer_since, $last_purchase); ?>
                    <?php $this->render_customer_profile_tab($customer, $latest_order, $customer_email, $customer_since, $last_purchase); ?>
                    <?php $this->render_customer_licenses_tab($licenses); ?>
                    <?php $this->render_customer_orders_tab($orders, $licenses_by_order); ?>
                    <?php $this->render_customer_activity_tab($activity_logs, $license_map); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_customer_overview_tab($customer, $orders, $latest_order, $latest_license, $status_breakdown, $customer_since, $last_purchase)
    {
        $customer_email = $customer['email'] !== '' ? $customer['email'] : (!empty($customer['emails']) ? $customer['emails'][0] : '');
        $latest_order_url = $latest_order ? admin_url('post.php?post=' . $latest_order->get_id() . '&action=edit') : '';
        $latest_license_url = $latest_license ? $this->get_license_manage_url($latest_license->id) : '';
        ?>
        <section id="wc-license-detail-overview" class="wc-license-detail-tab-panel is-active" data-license-detail-tab-panel="overview" role="tabpanel">
            <div class="wc-license-detail-grid">
                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Customer Snapshot', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Commerce profile', 'wc-product-license'); ?></h2>
                        </div>
                        <?php echo wp_kses_post($this->get_customer_type_badge($customer['type'])); ?>
                    </div>
                    <dl class="wc-license-detail-list">
                        <div>
                            <dt><?php esc_html_e('Primary name', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html($customer['name']); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Email', 'wc-product-license'); ?></dt>
                            <dd><?php echo $customer_email !== '' ? esc_html($customer_email) : esc_html__('No billing email recorded', 'wc-product-license'); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Customer since', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html($customer_since); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Last purchase', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html($last_purchase); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Latest order', 'wc-product-license'); ?></dt>
                            <dd><?php echo $latest_order_url ? '<a href="' . esc_url($latest_order_url) . '">#' . esc_html($latest_order->get_id()) . '</a>' : esc_html__('No linked order', 'wc-product-license'); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Latest license', 'wc-product-license'); ?></dt>
                            <dd><?php echo $latest_license_url ? '<a href="' . esc_url($latest_license_url) . '">' . esc_html($latest_license->license_key) . '</a>' : esc_html__('No linked license', 'wc-product-license'); ?></dd>
                        </div>
                    </dl>
                </div>

                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Status Breakdown', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('License health', 'wc-product-license'); ?></h2>
                        </div>
                    </div>
                    <div class="wc-license-detail-callout-group">
                        <div class="wc-license-detail-callout">
                            <strong><?php echo esc_html(number_format_i18n((int) $status_breakdown['active'])); ?></strong>
                            <span><?php esc_html_e('Active', 'wc-product-license'); ?></span>
                        </div>
                        <div class="wc-license-detail-callout">
                            <strong><?php echo esc_html(number_format_i18n((int) $status_breakdown['inactive'])); ?></strong>
                            <span><?php esc_html_e('Inactive', 'wc-product-license'); ?></span>
                        </div>
                        <div class="wc-license-detail-callout">
                            <strong><?php echo esc_html(number_format_i18n((int) $status_breakdown['expired'])); ?></strong>
                            <span><?php esc_html_e('Expired', 'wc-product-license'); ?></span>
                        </div>
                        <div class="wc-license-detail-callout">
                            <strong><?php echo esc_html(number_format_i18n((int) $status_breakdown['expiring'])); ?></strong>
                            <span><?php esc_html_e('Expiring soon', 'wc-product-license'); ?></span>
                        </div>
                    </div>
                    <dl class="wc-license-detail-list">
                        <div>
                            <dt><?php esc_html_e('Activation footprint', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n((int) $customer['activations'])); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Tracked revenue', 'wc-product-license'); ?></dt>
                            <dd><?php echo wp_kses_post(wc_price((float) $customer['revenue'])); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Unique orders', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html(number_format_i18n((int) $customer['order_count'])); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('WordPress account', 'wc-product-license'); ?></dt>
                            <dd><?php echo !empty($customer['user_url']) ? '<a href="' . esc_url($customer['user_url']) . '">' . esc_html__('Open account', 'wc-product-license') . '</a>' : esc_html__('Guest only', 'wc-product-license'); ?></dd>
                        </div>
                    </dl>
                </div>

                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Recent Licenses', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Latest keys', 'wc-product-license'); ?></h2>
                        </div>
                    </div>
                    <?php if (!empty($customer['licenses'])) : ?>
                        <table class="widefat striped wc-license-admin-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('License Key', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Product', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Status', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Actions', 'wc-product-license'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($customer['licenses'], 0, 5) as $license) : ?>
                                    <?php $product = wc_get_product($license->product_id); ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($license->license_key); ?></strong></td>
                                        <td><?php echo $product ? esc_html($product->get_name()) : esc_html__('Unknown product', 'wc-product-license'); ?></td>
                                        <td><?php echo wp_kses_post($this->get_license_status_badge(wc_product_license_get_effective_status($license))); ?></td>
                                        <td><a href="<?php echo esc_url($this->get_license_manage_url($license->id)); ?>" class="button button-secondary"><?php esc_html_e('Manage', 'wc-product-license'); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <div class="wc-license-empty-state">
                            <strong><?php esc_html_e('No licenses found', 'wc-product-license'); ?></strong>
                            <span><?php esc_html_e('This customer record does not currently have any linked license rows.', 'wc-product-license'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Recent Orders', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Purchase history', 'wc-product-license'); ?></h2>
                        </div>
                    </div>
                    <?php if (!empty($orders)) : ?>
                        <table class="widefat striped wc-license-admin-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Order', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Status', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Total', 'wc-product-license'); ?></th>
                                    <th><?php esc_html_e('Date', 'wc-product-license'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($orders, 0, 5) as $order) : ?>
                                    <?php $order_date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : ''; ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>">#<?php echo esc_html($order->get_id()); ?></a></td>
                                        <td><?php echo wp_kses_post($this->get_customer_order_status_badge($order->get_status())); ?></td>
                                        <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                                        <td><?php echo esc_html($this->get_customer_datetime_label($order_date)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <div class="wc-license-empty-state">
                            <strong><?php esc_html_e('No linked orders', 'wc-product-license'); ?></strong>
                            <span><?php esc_html_e('Manual licenses can exist without a WooCommerce order, so this section stays empty until a purchase is connected.', 'wc-product-license'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_customer_profile_tab($customer, $latest_order, $customer_email, $customer_since, $last_purchase)
    {
        $user = !empty($customer['user_id']) ? wc_product_license_get_cached_user_object($customer['user_id']) : null;
        $latest_order_name = '';
        $latest_order_phone = '';
        $latest_order_gateway = '';
        $latest_order_address = '';

        if ($latest_order) {
            $latest_order_name = trim((string) $latest_order->get_formatted_billing_full_name());
            if ($latest_order_name === '') {
                $latest_order_name = trim((string) $latest_order->get_billing_company());
            }
            $latest_order_phone = (string) $latest_order->get_billing_phone();
            $latest_order_gateway = (string) $latest_order->get_payment_method_title();

            $address_parts = array_filter([
                $latest_order->get_billing_address_1(),
                $latest_order->get_billing_address_2(),
                trim($latest_order->get_billing_city() . ', ' . $latest_order->get_billing_state() . ' ' . $latest_order->get_billing_postcode()),
                $latest_order->get_billing_country(),
            ]);
            $latest_order_address = !empty($address_parts) ? implode('<br>', array_map('esc_html', $address_parts)) : '';
        }
        ?>
        <section id="wc-license-detail-profile" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="profile" role="tabpanel" hidden>
            <div class="wc-license-detail-grid">
                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Identity', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Customer profile', 'wc-product-license'); ?></h2>
                        </div>
                    </div>
                    <dl class="wc-license-detail-list">
                        <div>
                            <dt><?php esc_html_e('Display name', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html($customer['name']); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Primary email', 'wc-product-license'); ?></dt>
                            <dd><?php echo $customer_email !== '' ? esc_html($customer_email) : esc_html__('No email recorded', 'wc-product-license'); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Customer type', 'wc-product-license'); ?></dt>
                            <dd><?php echo wp_kses_post($this->get_customer_type_badge($customer['type'])); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Company', 'wc-product-license'); ?></dt>
                            <dd><?php echo !empty($customer['company']) ? esc_html($customer['company']) : esc_html__('No company recorded', 'wc-product-license'); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Additional emails', 'wc-product-license'); ?></dt>
                            <dd><?php echo !empty($customer['emails']) ? esc_html(implode(', ', $customer['emails'])) : esc_html__('No alternate emails recorded', 'wc-product-license'); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Purchase window', 'wc-product-license'); ?></dt>
                            <dd><?php echo esc_html($customer_since . ' - ' . $last_purchase); ?></dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('WordPress account', 'wc-product-license'); ?></dt>
                            <dd>
                                <?php
                                if ($user) {
                                    echo '<a href="' . esc_url($customer['user_url']) . '">' . esc_html($user->display_name) . '</a>';
                                    echo '<span class="wc-license-detail-meta">' . esc_html(implode(', ', array_map('ucfirst', (array) $user->roles))) . '</span>';
                                } else {
                                    esc_html_e('No WordPress account linked', 'wc-product-license');
                                }
                                ?>
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="wc-license-admin-card">
                    <div class="wc-license-admin-card__header">
                        <div>
                            <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Billing Context', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Latest checkout details', 'wc-product-license'); ?></h2>
                        </div>
                    </div>
                    <?php if ($latest_order) : ?>
                        <dl class="wc-license-detail-list">
                            <div>
                                <dt><?php esc_html_e('Billing contact', 'wc-product-license'); ?></dt>
                                <dd><?php echo $latest_order_name !== '' ? esc_html($latest_order_name) : esc_html__('Not recorded', 'wc-product-license'); ?></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Phone', 'wc-product-license'); ?></dt>
                                <dd><?php echo $latest_order_phone !== '' ? esc_html($latest_order_phone) : esc_html__('Not recorded', 'wc-product-license'); ?></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Gateway', 'wc-product-license'); ?></dt>
                                <dd><?php echo $latest_order_gateway !== '' ? esc_html($latest_order_gateway) : esc_html__('Not recorded', 'wc-product-license'); ?></dd>
                            </div>
                            <div>
                                <dt><?php esc_html_e('Billing address', 'wc-product-license'); ?></dt>
                                <dd><?php echo $latest_order_address !== '' ? wp_kses_post($latest_order_address) : esc_html__('No billing address recorded', 'wc-product-license'); ?></dd>
                            </div>
                        </dl>
                    <?php else : ?>
                        <div class="wc-license-empty-state">
                            <strong><?php esc_html_e('No billing profile available', 'wc-product-license'); ?></strong>
                            <span><?php esc_html_e('A billing address appears here once the customer has a linked WooCommerce order.', 'wc-product-license'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_customer_licenses_tab($licenses)
    {
        ?>
        <section id="wc-license-detail-licenses" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="licenses" role="tabpanel" hidden>
            <div class="wc-license-admin-card">
                <div class="wc-license-admin-card__header">
                    <div>
                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Licenses', 'wc-product-license'); ?></span>
                        <h2><?php esc_html_e('All linked license records', 'wc-product-license'); ?></h2>
                    </div>
                </div>
                <p class="wc-license-detail-tab-note"><?php esc_html_e('Every license attached to this customer, including activation usage, purchase links, and direct access to the license manager.', 'wc-product-license'); ?></p>
                <?php if (!empty($licenses)) : ?>
                    <table class="widefat striped wc-license-admin-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('License Key', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Product', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Status', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Activations', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Validity', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Order', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Purchased', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Actions', 'wc-product-license'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($licenses as $license) : ?>
                                <?php
                                $product = wc_get_product($license->product_id);
                                $order_url = !empty($license->order_id) ? admin_url('post.php?post=' . absint($license->order_id) . '&action=edit') : '';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($license->license_key); ?></strong></td>
                                    <td><?php echo $product ? esc_html($product->get_name()) : esc_html__('Unknown product', 'wc-product-license'); ?></td>
                                    <td><?php echo wp_kses_post($this->get_license_status_badge(wc_product_license_get_effective_status($license))); ?></td>
                                    <td><?php echo esc_html(wc_product_license_get_activation_usage_text((int) $license->sites_active, (int) $license->sites_allowed)); ?></td>
                                    <td><?php echo esc_html($this->get_license_expiration_label($license->expires_at)); ?></td>
                                    <td><?php echo $order_url ? '<a href="' . esc_url($order_url) . '">#' . esc_html($license->order_id) . '</a>' : esc_html__('Manual / not linked', 'wc-product-license'); ?></td>
                                    <td><?php echo esc_html($this->get_customer_datetime_label($license->purchased_at)); ?></td>
                                    <td><a href="<?php echo esc_url($this->get_license_manage_url($license->id)); ?>" class="button button-secondary"><?php esc_html_e('Manage', 'wc-product-license'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="wc-license-empty-state">
                        <strong><?php esc_html_e('No license rows found', 'wc-product-license'); ?></strong>
                        <span><?php esc_html_e('The customer exists in the directory, but there are no license rows to display right now.', 'wc-product-license'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function render_customer_orders_tab($orders, $licenses_by_order)
    {
        ?>
        <section id="wc-license-detail-orders" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="orders" role="tabpanel" hidden>
            <div class="wc-license-admin-card">
                <div class="wc-license-admin-card__header">
                    <div>
                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Orders', 'wc-product-license'); ?></span>
                        <h2><?php esc_html_e('WooCommerce order history', 'wc-product-license'); ?></h2>
                    </div>
                </div>
                <p class="wc-license-detail-tab-note"><?php esc_html_e('Related orders are grouped once and enriched with the licensed items fulfilled under each purchase.', 'wc-product-license'); ?></p>
                <?php if (!empty($orders)) : ?>
                    <table class="widefat striped wc-license-admin-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Order', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Status', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Total', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Licensed items', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Date', 'wc-product-license'); ?></th>
                                <th><?php esc_html_e('Actions', 'wc-product-license'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order) : ?>
                                <?php
                                $related_licenses = isset($licenses_by_order[$order->get_id()]) ? $licenses_by_order[$order->get_id()] : [];
                                $licensed_items = [];
                                foreach ($related_licenses as $related_license) {
                                    $product = wc_get_product($related_license->product_id);
                                    $licensed_items[] = $product ? $product->get_name() : __('Unknown product', 'wc-product-license');
                                }
                                $licensed_items = array_values(array_unique(array_filter($licensed_items)));
                                $order_date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '';
                                ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>">#<?php echo esc_html($order->get_id()); ?></a></td>
                                    <td><?php echo wp_kses_post($this->get_customer_order_status_badge($order->get_status())); ?></td>
                                    <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                                    <td><?php echo !empty($licensed_items) ? esc_html(implode(', ', $licensed_items)) : esc_html__('No licensed line items matched', 'wc-product-license'); ?></td>
                                    <td><?php echo esc_html($this->get_customer_datetime_label($order_date)); ?></td>
                                    <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>" class="button button-secondary"><?php esc_html_e('View Order', 'wc-product-license'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="wc-license-empty-state">
                        <strong><?php esc_html_e('No WooCommerce orders linked', 'wc-product-license'); ?></strong>
                        <span><?php esc_html_e('Customer licenses created manually or migrated without order links will not populate this table.', 'wc-product-license'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function render_customer_activity_tab($activity_logs, $license_map)
    {
        ?>
        <section id="wc-license-detail-activity" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="activity" role="tabpanel" hidden>
            <div class="wc-license-admin-card">
                <div class="wc-license-admin-card__header">
                    <div>
                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Activity Log', 'wc-product-license'); ?></span>
                        <h2><?php esc_html_e('Timeline across all owned licenses', 'wc-product-license'); ?></h2>
                    </div>
                </div>
                <p class="wc-license-detail-tab-note"><?php esc_html_e('This consolidates fulfillment, renewals, activations, admin edits, and API events from every license linked to the customer.', 'wc-product-license'); ?></p>
                <?php if (!empty($activity_logs)) : ?>
                    <div class="wc-license-activity-log">
                        <?php foreach ($activity_logs as $log) : ?>
                            <?php
                            $meta_items = $this->get_activity_log_meta_items($log);
                            $related_license = isset($license_map[(int) $log->license_id]) ? $license_map[(int) $log->license_id] : null;
                            $product = $related_license ? wc_get_product($related_license->product_id) : null;
                            ?>
                            <article class="wc-license-activity-log__item">
                                <div class="wc-license-activity-log__top">
                                    <div class="wc-license-activity-log__badges">
                                        <?php echo wp_kses_post($this->get_activity_event_badge($log->event_type)); ?>
                                        <?php echo wp_kses_post($this->get_activity_source_badge($log->source)); ?>
                                    </div>
                                    <time class="wc-license-activity-log__time"><?php echo esc_html($this->get_activity_log_timestamp_label($log->created_at)); ?></time>
                                </div>
                                <p class="wc-license-activity-log__message"><?php echo esc_html($log->message); ?></p>
                                <div class="wc-license-activity-log__meta">
                                    <?php if ($related_license) : ?>
                                        <span class="wc-license-activity-log__meta-item">
                                            <strong><?php esc_html_e('License', 'wc-product-license'); ?></strong>
                                            <a href="<?php echo esc_url($this->get_license_manage_url($related_license->id)); ?>"><?php echo esc_html($related_license->license_key); ?></a>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($product) : ?>
                                        <span class="wc-license-activity-log__meta-item">
                                            <strong><?php esc_html_e('Product', 'wc-product-license'); ?></strong>
                                            <span><?php echo esc_html($product->get_name()); ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <?php foreach ($meta_items as $item) : ?>
                                        <span class="wc-license-activity-log__meta-item">
                                            <strong><?php echo esc_html($item['label']); ?></strong>
                                            <?php if (!empty($item['url'])) : ?>
                                                <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['value']); ?></a>
                                            <?php else : ?>
                                                <span><?php echo esc_html($item['value']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="wc-license-empty-state">
                        <strong><?php esc_html_e('No customer activity recorded yet', 'wc-product-license'); ?></strong>
                        <span><?php esc_html_e('Events will appear here as soon as one of this customer’s licenses is created, renewed, activated, or modified.', 'wc-product-license'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    public function render_sites_page()
    {
        $this->handle_site_admin_request();

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $activation_id = isset($_GET['activation_id']) ? absint($_GET['activation_id']) : 0;

        if ($action === 'manage' && $activation_id > 0) {
            $this->render_site_details_page($activation_id);
            return;
        }

        if (!class_exists('WP_List_Table')) {
            require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
        }

        require_once dirname(__FILE__) . '/class-wc-license-site-list-table.php';

        $sites_table = new WC_License_Site_List_Table();
        $sites_table->prepare_items();

        $current_product_id = $this->get_requested_site_product_id();
        $product_options = wc_product_license_get_tracked_product_options();
        $current_product = $current_product_id > 0
            ? (isset($product_options[$current_product_id]) ? $product_options[$current_product_id] : [
                'id' => $current_product_id,
                'name' => wc_product_license_get_product_label($current_product_id),
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'edit_url' => admin_url('post.php?post=' . $current_product_id . '&action=edit'),
                'dashboard_url' => wc_product_license_get_dashboard_url(['product_id' => $current_product_id]),
                'sites_url' => add_query_arg(['product_id' => $current_product_id], admin_url('admin.php?page=wc-license-sites')),
            ])
            : null;
        $summary = $this->get_site_list_summary($current_product_id);
        $status_counts = $this->get_site_status_counts($current_product_id);
        $filter_options = $this->get_site_filter_options($current_product_id);
        $current_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $current_multisite = isset($_GET['multisite_filter']) ? sanitize_key(wp_unslash($_GET['multisite_filter'])) : '';
        $current_wp_version = isset($_GET['wp_version_filter']) ? sanitize_text_field(wp_unslash($_GET['wp_version_filter'])) : '';
        $current_php_version = isset($_GET['php_version_filter']) ? sanitize_text_field(wp_unslash($_GET['php_version_filter'])) : '';
        $current_theme = isset($_GET['theme_filter']) ? sanitize_text_field(wp_unslash($_GET['theme_filter'])) : '';
        $current_environment = isset($_GET['environment_filter']) ? sanitize_text_field(wp_unslash($_GET['environment_filter'])) : '';
        $current_reason = isset($_GET['reason_filter']) ? sanitize_text_field(wp_unslash($_GET['reason_filter'])) : '';
        $filters = [
            '' => __('All', 'wc-product-license'),
            'active' => __('Active', 'wc-product-license'),
            'inactive' => __('Inactive', 'wc-product-license'),
            'recent' => __('Recent 30 Days', 'wc-product-license'),
        ];
        ?>
        <div class="wrap wc-license-admin-page">
            <div class="wc-license-admin-header">
                <div class="wc-license-admin-header__copy">
                    <h1 class="wp-heading-inline"><?php esc_html_e('Sites', 'wc-product-license'); ?></h1>
                    <p>
                        <?php
                        echo $current_product
                            ? esc_html(sprintf(__('Track every activation for %s, review environment telemetry, and jump from a site to the owning license or customer instantly.', 'wc-product-license'), $current_product['name']))
                            : esc_html__('Track every activated site, review request history, jump into the owning customer and license, and deactivate access from one screen.', 'wc-product-license');
                        ?>
                    </p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <?php if ($current_product) : ?>
                        <a href="<?php echo esc_url($current_product['dashboard_url']); ?>" class="button button-primary"><?php esc_html_e('Product Dashboard', 'wc-product-license'); ?></a>
                        <a href="<?php echo esc_url($this->get_sites_list_url()); ?>" class="button button-secondary"><?php esc_html_e('Clear Scope', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($this->get_license_list_url()); ?>" class="button button-secondary"><?php esc_html_e('View Licenses', 'wc-product-license'); ?></a>
                </div>
            </div>

            <?php $this->render_site_admin_notices(); ?>

            <div class="wc-license-admin-summary">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Tracked site records', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['total'])); ?></strong>
                    <small><?php esc_html_e('Every active and historical site activation retained for licensed products.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Active now', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['active'])); ?></strong>
                    <small><?php esc_html_e('Sites currently consuming a license activation slot.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Inactive history', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['inactive'])); ?></strong>
                    <small><?php esc_html_e('Previously connected sites retained for audits and support follow-up.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Linked licenses', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($summary['licenses'])); ?></strong>
                    <small><?php echo esc_html(sprintf(_n('%d site seen in the last 30 days', '%d sites seen in the last 30 days', $summary['recent'], 'wc-product-license'), $summary['recent'])); ?></small>
                </div>
            </div>

            <ul class="subsubsub wc-license-admin-filters">
                <?php
                $filter_markup = [];
                foreach ($filters as $filter_key => $filter_label) {
                    $count_key = $filter_key === '' ? 'all' : $filter_key;
                    $filter_url = $this->get_sites_list_url(array_filter([
                        'product_id' => $current_product_id,
                        'status' => $filter_key,
                        's' => $search,
                        'multisite_filter' => $current_multisite,
                        'wp_version_filter' => $current_wp_version,
                        'php_version_filter' => $current_php_version,
                        'theme_filter' => $current_theme,
                        'environment_filter' => $current_environment,
                        'reason_filter' => $current_reason,
                    ], static function ($value) {
                        return $value !== '';
                    }));
                    $classes = $current_status === $filter_key ? 'current' : '';
                    $filter_markup[] = sprintf(
                        '<li class="%1$s"><a href="%2$s" class="%1$s">%3$s <span class="count">(%4$s)</span></a>',
                        esc_attr($classes),
                        esc_url($filter_url),
                        esc_html($filter_label),
                        esc_html(number_format_i18n(isset($status_counts[$count_key]) ? $status_counts[$count_key] : 0))
                    );
                }
                echo implode(' | </li>', $filter_markup) . '</li>';
                ?>
            </ul>

            <form id="sites-filter" method="get">
                <input type="hidden" name="page" value="wc-license-sites" />
                <?php if ($current_status !== '') : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                <?php endif; ?>
                <div class="wc-license-admin-toolbar">
                    <div class="wc-license-admin-toolbar__filters">
                        <label>
                            <span><?php esc_html_e('Product', 'wc-product-license'); ?></span>
                            <select name="product_id">
                                <option value=""><?php esc_html_e('All licensed products', 'wc-product-license'); ?></option>
                                <?php foreach ($product_options as $product_option) : ?>
                                    <option value="<?php echo esc_attr($product_option['id']); ?>" <?php selected($current_product_id, (int) $product_option['id']); ?>>
                                        <?php echo esc_html(sprintf('%s (%s)', $product_option['name'], number_format_i18n($product_option['total']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('Mode', 'wc-product-license'); ?></span>
                            <select name="multisite_filter">
                                <option value=""><?php esc_html_e('All installs', 'wc-product-license'); ?></option>
                                <option value="multisite" <?php selected($current_multisite, 'multisite'); ?>><?php esc_html_e('Multisite', 'wc-product-license'); ?></option>
                                <option value="single" <?php selected($current_multisite, 'single'); ?>><?php esc_html_e('Single site', 'wc-product-license'); ?></option>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('WordPress', 'wc-product-license'); ?></span>
                            <select name="wp_version_filter">
                                <option value=""><?php esc_html_e('All versions', 'wc-product-license'); ?></option>
                                <?php foreach ($filter_options['wordpress_versions'] as $value => $count) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_wp_version, $value); ?>><?php echo esc_html(sprintf('%s (%s)', $value, number_format_i18n($count))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('PHP', 'wc-product-license'); ?></span>
                            <select name="php_version_filter">
                                <option value=""><?php esc_html_e('All versions', 'wc-product-license'); ?></option>
                                <?php foreach ($filter_options['php_versions'] as $value => $count) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_php_version, $value); ?>><?php echo esc_html(sprintf('%s (%s)', $value, number_format_i18n($count))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('Theme', 'wc-product-license'); ?></span>
                            <select name="theme_filter">
                                <option value=""><?php esc_html_e('All themes', 'wc-product-license'); ?></option>
                                <?php foreach ($filter_options['themes'] as $value => $count) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_theme, $value); ?>><?php echo esc_html(sprintf('%s (%s)', $value, number_format_i18n($count))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('Environment', 'wc-product-license'); ?></span>
                            <select name="environment_filter">
                                <option value=""><?php esc_html_e('All environments', 'wc-product-license'); ?></option>
                                <?php foreach ($filter_options['environments'] as $value => $count) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_environment, $value); ?>><?php echo esc_html(sprintf('%s (%s)', ucfirst($value), number_format_i18n($count))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('Deactivation', 'wc-product-license'); ?></span>
                            <select name="reason_filter">
                                <option value=""><?php esc_html_e('All reasons', 'wc-product-license'); ?></option>
                                <?php foreach ($filter_options['reasons'] as $value => $count) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_reason, $value); ?>><?php echo esc_html(sprintf('%s (%s)', $value, number_format_i18n($count))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="wc-license-admin-toolbar__actions">
                        <button type="submit" class="button button-secondary"><?php esc_html_e('Apply Filters', 'wc-product-license'); ?></button>
                        <a href="<?php echo esc_url($this->get_sites_list_url()); ?>" class="button button-link-delete"><?php esc_html_e('Reset', 'wc-product-license'); ?></a>
                    </div>
                </div>
                <?php
                $sites_table->search_box(__('Search Sites', 'wc-product-license'), 'site-search');
                $sites_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    private function handle_site_admin_request()
    {
        $current_product_id = $this->get_requested_site_product_id();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wc_license_site_action'])) {
            $activation_id = isset($_POST['activation_id']) ? absint($_POST['activation_id']) : 0;

            if (
                !$activation_id ||
                !isset($_POST['wc_license_site_nonce']) ||
                !wp_verify_nonce(wp_unslash($_POST['wc_license_site_nonce']), 'wc_license_site_' . $activation_id)
            ) {
                add_settings_error('wc_site_messages', 'wc_site_nonce', __('Security check failed. Please refresh and try again.', 'wc-product-license'), 'error');
                return;
            }

            $result = $this->perform_site_admin_action(sanitize_key(wp_unslash($_POST['wc_license_site_action'])), $activation_id);
            if (is_wp_error($result)) {
                add_settings_error('wc_site_messages', $result->get_error_code(), $result->get_error_message(), 'error');
            } else {
                add_settings_error('wc_site_messages', 'wc_site_updated', __('Site record updated successfully.', 'wc-product-license'), 'updated');
            }

            return;
        }

        $site_action = isset($_GET['site_record_action']) ? sanitize_key(wp_unslash($_GET['site_record_action'])) : '';
        $activation_id = isset($_GET['activation_id']) ? absint($_GET['activation_id']) : 0;

        if ($site_action === '' || $activation_id < 1) {
            return;
        }

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(wp_unslash($_GET['_wpnonce']), 'wc_license_site_' . $site_action . '_' . $activation_id)
        ) {
            wp_safe_redirect($this->get_sites_list_url(array_filter([
                'product_id' => $current_product_id,
                'wc_site_error' => 'nonce',
            ])));
            exit;
        }

        $result = $this->perform_site_admin_action($site_action, $activation_id);
        if (is_wp_error($result)) {
            wp_safe_redirect($this->get_sites_list_url(array_filter([
                'product_id' => $current_product_id,
                'wc_site_error' => $result->get_error_code(),
            ])));
            exit;
        }

        wp_safe_redirect($this->get_sites_list_url(array_filter([
            'product_id' => $current_product_id,
            'wc_site_notice' => $site_action,
        ])));
        exit;
    }

    private function perform_site_admin_action($action, $activation_id)
    {
        if ($action === 'deactivate') {
            return $this->deactivate_site_record($activation_id);
        }

        return new WP_Error('unknown_action', __('Unsupported site action.', 'wc-product-license'));
    }

    private function deactivate_site_record($activation_id)
    {
        global $wpdb;

        $activation = wc_product_license_get_activation_record($activation_id);
        if (!$activation) {
            return new WP_Error('site_missing', __('Site record not found.', 'wc-product-license'));
        }

        if ((string) $activation->status !== 'active') {
            return new WP_Error('site_inactive', __('This site record is already inactive.', 'wc-product-license'));
        }

        $license = $this->get_license_record((int) $activation->license_id);
        $timestamp = current_time('mysql');

        if ($license) {
            $active_sites = maybe_unserialize($license->active_sites);
            $active_sites = is_array($active_sites) ? $active_sites : [];

            if (isset($active_sites[$activation->site_url])) {
                unset($active_sites[$activation->site_url]);
            }

            $wpdb->update(
                wc_product_license_get_table_name('licenses'),
                [
                    'sites_active' => count($active_sites),
                    'active_sites' => maybe_serialize($active_sites),
                ],
                ['id' => (int) $license->id],
                ['%d', '%s'],
                ['%d']
            );

            wc_product_license_mark_activation_inactive($license, $activation->site_url, [
                'timestamp' => $timestamp,
            ]);

            wc_product_license_log_event(
                $license,
                'site_deactivated',
                sprintf(__('Site deactivated from the Sites screen: %s', 'wc-product-license'), $activation->site_url),
                [
                    'source' => 'admin',
                    'actor_id' => get_current_user_id(),
                    'site_url' => $activation->site_url,
                    'details' => [
                        'sites_active' => count($active_sites),
                        'sites_allowed' => (int) $license->sites_allowed,
                    ],
                ]
            );

            return true;
        }

        wc_product_license_mark_activation_inactive([
            'id' => (int) $activation->license_id,
            'license_key' => (string) $activation->license_key,
            'product_id' => (int) $activation->product_id,
            'order_id' => (int) $activation->order_id,
            'user_id' => (int) $activation->user_id,
        ], $activation->site_url, [
            'timestamp' => $timestamp,
        ]);

        return true;
    }

    private function get_requested_site_product_id()
    {
        return isset($_REQUEST['product_id']) ? absint(wp_unslash($_REQUEST['product_id'])) : 0;
    }

    private function get_site_list_summary($product_id = 0)
    {
        global $wpdb;

        $query = 'SELECT status, license_id, last_requested_at FROM ' . wc_product_license_get_table_name('activations');
        if ($product_id > 0) {
            $query .= $wpdb->prepare(' WHERE product_id = %d', $product_id);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $summary = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'licenses' => 0,
            'recent' => 0,
        ];
        $license_ids = [];
        $cutoff = strtotime('-30 days', current_time('timestamp'));

        foreach ($rows as $row) {
            $summary['total']++;
            if (($row['status'] ?? '') === 'active') {
                $summary['active']++;
            } else {
                $summary['inactive']++;
            }

            if (!empty($row['license_id'])) {
                $license_ids[] = (int) $row['license_id'];
            }

            $last_requested = !empty($row['last_requested_at']) ? strtotime((string) $row['last_requested_at']) : false;
            if ($last_requested && $last_requested >= $cutoff) {
                $summary['recent']++;
            }
        }

        $summary['licenses'] = count(array_unique(array_filter($license_ids)));

        return $summary;
    }

    private function get_site_status_counts($product_id = 0)
    {
        global $wpdb;

        $query = 'SELECT status, last_requested_at FROM ' . wc_product_license_get_table_name('activations');
        if ($product_id > 0) {
            $query .= $wpdb->prepare(' WHERE product_id = %d', $product_id);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $counts = [
            'all' => 0,
            'active' => 0,
            'inactive' => 0,
            'recent' => 0,
        ];
        $cutoff = strtotime('-30 days', current_time('timestamp'));

        foreach ($rows as $row) {
            $counts['all']++;
            if (($row['status'] ?? '') === 'active') {
                $counts['active']++;
            } else {
                $counts['inactive']++;
            }

            $last_requested = !empty($row['last_requested_at']) ? strtotime((string) $row['last_requested_at']) : false;
            if ($last_requested && $last_requested >= $cutoff) {
                $counts['recent']++;
            }
        }

        return $counts;
    }

    private function get_site_filter_options($product_id = 0)
    {
        global $wpdb;

        $query = 'SELECT multisite, wordpress_version, php_version, active_theme, environment_type, deactivation_reason FROM ' . wc_product_license_get_table_name('activations');
        if ($product_id > 0) {
            $query .= $wpdb->prepare(' WHERE product_id = %d', $product_id);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $options = [
            'wordpress_versions' => [],
            'php_versions' => [],
            'themes' => [],
            'environments' => [],
            'reasons' => [],
        ];

        foreach ($rows as $row) {
            foreach ([
                'wordpress_versions' => (string) ($row['wordpress_version'] ?? ''),
                'php_versions' => (string) ($row['php_version'] ?? ''),
                'themes' => (string) ($row['active_theme'] ?? ''),
                'environments' => (string) ($row['environment_type'] ?? ''),
                'reasons' => (string) ($row['deactivation_reason'] ?? ''),
            ] as $bucket => $value) {
                if ($value === '') {
                    continue;
                }

                if (!isset($options[$bucket][$value])) {
                    $options[$bucket][$value] = 0;
                }

                $options[$bucket][$value]++;
            }
        }

        foreach ($options as $bucket => $values) {
            ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $options[$bucket] = $values;
        }

        return $options;
    }

    private function get_sites_list_url($args = [])
    {
        return add_query_arg($args, admin_url('admin.php?page=wc-license-sites'));
    }

    private function render_site_admin_notices()
    {
        settings_errors('wc_site_messages');

        if (!empty($_GET['wc_site_notice']) && $_GET['wc_site_notice'] === 'deactivate') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Site deactivated successfully.', 'wc-product-license') . '</p></div>';
        }

        if (!empty($_GET['wc_site_error'])) {
            $messages = [
                'nonce' => __('Security check failed. Please try again.', 'wc-product-license'),
                'site_missing' => __('Site record not found.', 'wc-product-license'),
                'site_inactive' => __('This site record is already inactive.', 'wc-product-license'),
                'unknown_action' => __('Unsupported site action.', 'wc-product-license'),
            ];

            if (isset($messages[$_GET['wc_site_error']])) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($messages[$_GET['wc_site_error']]) . '</p></div>';
            }
        }
    }

    private function get_site_status_badge($status)
    {
        $status = sanitize_key((string) $status);
        $label = $status === 'active' ? __('Active', 'wc-product-license') : __('Inactive', 'wc-product-license');

        return sprintf(
            '<span class="wc-license-site-status wc-license-site-status--%1$s">%2$s</span>',
            esc_attr($status),
            esc_html($label)
        );
    }

    private function get_site_host_label($site_url)
    {
        $host = wp_parse_url((string) $site_url, PHP_URL_HOST);
        return $host ? (string) $host : (string) $site_url;
    }

    private function get_site_path_label($site_url)
    {
        $path = wp_parse_url((string) $site_url, PHP_URL_PATH);
        $query = wp_parse_url((string) $site_url, PHP_URL_QUERY);
        $value = $path ? $path : '/';

        if ($query) {
            $value .= '?' . $query;
        }

        return $value;
    }

    private function render_site_details_page($activation_id)
    {
        $activation = wc_product_license_get_activation_record($activation_id);
        if (!$activation) {
            wp_die(__('Site record not found.', 'wc-product-license'));
        }

        $license = $this->get_license_record((int) $activation->license_id);
        $license_context = $license ? $license : [
            'id' => (int) $activation->license_id,
            'license_key' => (string) $activation->license_key,
            'product_id' => (int) $activation->product_id,
            'order_id' => (int) $activation->order_id,
            'user_id' => (int) $activation->user_id,
        ];
        $customer_context = wc_product_license_get_customer_context_from_license($license_context);
        $product = wc_get_product((int) $activation->product_id);
        $order = !empty($activation->order_id) ? wc_get_order((int) $activation->order_id) : null;
        $activity_logs = wc_product_license_get_activation_logs($activation, 50);
        $activity_count = wc_product_license_get_activation_log_count($activation);
        $current_product_id = $this->get_requested_site_product_id();
        $site_scope_product_id = $current_product_id > 0 ? $current_product_id : 0;
        $manage_url = wc_product_license_get_site_manage_url($activation_id, $site_scope_product_id ? ['product_id' => $site_scope_product_id] : []);
        $back_url = $this->get_sites_list_url($site_scope_product_id ? ['product_id' => $site_scope_product_id] : []);
        $license_url = $license ? $this->get_license_manage_url((int) $license->id) : '';
        $order_url = $order ? admin_url('post.php?post=' . $order->get_id() . '&action=edit') : '';
        $product_url = $product ? admin_url('post.php?post=' . $product->get_id() . '&action=edit') : '';
        $product_dashboard_url = (int) $activation->product_id > 0 ? wc_product_license_get_dashboard_url(['product_id' => (int) $activation->product_id]) : '';
        $site_host = $this->get_site_host_label($activation->site_url);
        $site_path = $this->get_site_path_label($activation->site_url);
        $is_openable_url = strpos((string) $activation->site_url, 'http') === 0;
        $activation_meta = wc_product_license_get_activation_meta($activation);
        $activation_plugins = wc_product_license_get_activation_plugins($activation);
        $days_active = wc_product_license_get_activation_days_active($activation);
        $site_label = !empty($activation->site_name) ? (string) $activation->site_name : $site_host;
        $environment_title = trim(implode(' • ', array_filter([
            !empty($activation->wordpress_version) ? 'WordPress ' . $activation->wordpress_version : '',
            !empty($activation->php_version) ? 'PHP ' . $activation->php_version : '',
            !empty($activation->environment_type) ? ucfirst((string) $activation->environment_type) : '',
        ])));
        $product_label = $product ? $product->get_name() : (!empty($activation->product_name) ? (string) $activation->product_name : __('Unknown product', 'wc-product-license'));
        ?>
        <div class="wrap wc-license-admin-page wc-license-detail-page wc-license-site-detail-page">
            <div class="wc-license-detail-header">
                <div class="wc-license-admin-header__copy">
                    <a href="<?php echo esc_url($back_url); ?>" class="page-title-action wc-license-detail-header__back"><?php esc_html_e('Back to Sites', 'wc-product-license'); ?></a>
                    <h1><?php echo esc_html(sprintf(__('Site Details: %s', 'wc-product-license'), $site_label)); ?></h1>
                    <p><?php esc_html_e('Review the site environment, inspect the linked license and customer, and use the telemetry snapshot for support, upgrade, and deactivation decisions.', 'wc-product-license'); ?></p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <?php if ($is_openable_url) : ?>
                        <a href="<?php echo esc_url($activation->site_url); ?>" target="_blank" rel="noreferrer" class="button button-secondary"><?php esc_html_e('Open Site', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if ($product_dashboard_url) : ?>
                        <a href="<?php echo esc_url($product_dashboard_url); ?>" class="button button-secondary"><?php esc_html_e('Product Dashboard', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if ($license_url) : ?>
                        <a href="<?php echo esc_url($license_url); ?>" class="button button-secondary"><?php esc_html_e('View License', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($customer_context['customer_url'])) : ?>
                        <a href="<?php echo esc_url($customer_context['customer_url']); ?>" class="button button-secondary"><?php esc_html_e('View Customer', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php settings_errors('wc_site_messages'); ?>

            <div class="wc-license-admin-summary wc-license-admin-summary--details">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Status', 'wc-product-license'); ?></span>
                    <strong><?php echo wp_kses_post($this->get_site_status_badge($activation->status)); ?></strong>
                    <small><?php echo esc_html($site_path); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Days active', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($days_active)); ?></strong>
                    <small><?php echo esc_html(sprintf(_n('%d request logged', '%d requests logged', (int) $activation->request_count, 'wc-product-license'), (int) $activation->request_count)); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Environment', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(!empty($activation->wordpress_version) ? 'WP ' . $activation->wordpress_version : __('Unknown', 'wc-product-license')); ?></strong>
                    <small><?php echo esc_html(!empty($activation->php_version) ? 'PHP ' . $activation->php_version : __('PHP not reported', 'wc-product-license')); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Theme', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html(!empty($activation->active_theme) ? $activation->active_theme : __('Unknown', 'wc-product-license')); ?></strong>
                    <small><?php echo esc_html(!empty($activation->plugin_version) ? sprintf(__('Plugin v%s', 'wc-product-license'), $activation->plugin_version) : __('Plugin version not reported', 'wc-product-license')); ?></small>
                </div>
            </div>

            <div class="wc-license-detail-tabs" data-wc-license-detail-tabs data-license-detail-hash-prefix="site">
                <div class="wc-license-detail-tabs__nav" role="tablist" aria-label="<?php esc_attr_e('Site sections', 'wc-product-license'); ?>">
                    <button type="button" class="wc-license-detail-tabs__nav-item is-active" data-license-detail-tab-target="overview" role="tab" aria-selected="true" aria-controls="wc-license-detail-overview">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Overview', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($site_host); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="environment" role="tab" aria-selected="false" aria-controls="wc-license-detail-environment">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Environment', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($environment_title !== '' ? $environment_title : __('No snapshot yet', 'wc-product-license')); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="plugins" role="tab" aria-selected="false" aria-controls="wc-license-detail-plugins">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Plugins', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html(sprintf(_n('%d plugin captured', '%d plugins captured', count($activation_plugins), 'wc-product-license'), count($activation_plugins))); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="license" role="tab" aria-selected="false" aria-controls="wc-license-detail-license">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('License', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html((string) $activation->license_key); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="customer" role="tab" aria-selected="false" aria-controls="wc-license-detail-customer">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Customer', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($customer_context['name']); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="activity" role="tab" aria-selected="false" aria-controls="wc-license-detail-activity">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Activity Log', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Site-specific events', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n($activity_count)); ?></span>
                    </button>
                </div>

                <div class="wc-license-detail-tabs__content">
                    <section id="wc-license-detail-overview" class="wc-license-detail-tab-panel is-active" data-license-detail-tab-panel="overview" role="tabpanel">
                        <div class="wc-license-detail-grid">
                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Site record', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Activation overview', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Site URL', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $is_openable_url ? '<a href="' . esc_url($activation->site_url) . '" target="_blank" rel="noreferrer">' . esc_html($activation->site_url) . '</a>' : esc_html($activation->site_url); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Site name', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($site_label); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Host', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($site_host); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Path', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($site_path); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Status', 'wc-product-license'); ?></dt>
                                        <dd><?php echo wp_kses_post($this->get_site_status_badge($activation->status)); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('First request', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($this->get_customer_datetime_label($activation->first_requested_at)); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Last request', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($this->get_customer_datetime_label($activation->last_requested_at)); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Days active', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html(sprintf(_n('%d day', '%d days', $days_active, 'wc-product-license'), $days_active)); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Telemetry source', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html(!empty($activation->telemetry_source) ? ucfirst((string) $activation->telemetry_source) : __('Unknown', 'wc-product-license')); ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Management', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Control access', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <div class="wc-license-detail-callout-group">
                                    <div class="wc-license-detail-callout">
                                        <strong><?php esc_html_e('Requests', 'wc-product-license'); ?></strong>
                                        <span><?php echo esc_html(number_format_i18n((int) $activation->request_count)); ?></span>
                                    </div>
                                    <div class="wc-license-detail-callout">
                                        <strong><?php esc_html_e('Product', 'wc-product-license'); ?></strong>
                                        <span><?php echo esc_html($product_label); ?></span>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Environment', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($environment_title !== '' ? $environment_title : __('No environment data reported yet', 'wc-product-license')); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Deactivation reason', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->deactivation_reason) ? esc_html($activation->deactivation_reason) : esc_html__('No reason recorded', 'wc-product-license'); ?></dd>
                                    </div>
                                </dl>
                                <?php if ((string) $activation->status === 'active') : ?>
                                    <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-detail-form__actions">
                                        <?php wp_nonce_field('wc_license_site_' . $activation_id, 'wc_license_site_nonce'); ?>
                                        <input type="hidden" name="activation_id" value="<?php echo esc_attr($activation_id); ?>" />
                                        <input type="hidden" name="wc_license_site_action" value="deactivate" />
                                        <button type="submit" class="button button-secondary"><?php esc_html_e('Deactivate Site', 'wc-product-license'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <div class="wc-license-empty-state">
                                        <strong><?php esc_html_e('Already inactive', 'wc-product-license'); ?></strong>
                                        <span><?php echo esc_html($this->get_customer_datetime_label($activation->deactivated_at, __('This site record has already been archived.', 'wc-product-license'))); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-environment" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="environment" role="tabpanel" hidden>
                        <div class="wc-license-detail-grid">
                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Environment snapshot', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Platform details', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Home URL', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['home_url']) ? esc_html($activation_meta['home_url']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Environment', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->environment_type) ? esc_html(ucfirst((string) $activation->environment_type)) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Multisite', 'wc-product-license'); ?></dt>
                                        <dd><?php echo (int) $activation->multisite === 1 ? esc_html__('Yes', 'wc-product-license') : esc_html__('No', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('WordPress', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->wordpress_version) ? esc_html($activation->wordpress_version) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('PHP', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->php_version) ? esc_html($activation->php_version) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('MySQL', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->mysql_version) ? esc_html($activation->mysql_version) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Server software', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->server_software) ? esc_html($activation->server_software) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Server OS', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['server_os']) ? esc_html($activation_meta['server_os']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Locale', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['locale']) ? esc_html($activation_meta['locale']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('WordPress locale', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['wordpress_locale']) ? esc_html($activation_meta['wordpress_locale']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Timezone', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['timezone']) ? esc_html($activation_meta['timezone']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Admin email', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['admin_email']) ? esc_html($activation_meta['admin_email']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Last IP', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->last_ip) ? esc_html($activation->last_ip) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Plugin version', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->plugin_version) ? esc_html($activation->plugin_version) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Last telemetry sync', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['last_telemetry_at']) ? esc_html($this->get_customer_datetime_label($activation_meta['last_telemetry_at'])) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('User agent', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['user_agent']) ? esc_html($activation_meta['user_agent']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Theme snapshot', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Active theme and support context', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Active theme', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->active_theme) ? esc_html($activation->active_theme) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Theme version', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation->active_theme_version) ? esc_html($activation->active_theme_version) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Site scope', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['site_scope']) ? esc_html($activation_meta['site_scope']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Site owner', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['site_owner']) ? esc_html($activation_meta['site_owner']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Owner type', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['site_owner_type']) ? esc_html($activation_meta['site_owner_type']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('License channel', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['license_channel']) ? esc_html($activation_meta['license_channel']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Local install', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['is_local']) ? esc_html__('Yes', 'wc-product-license') : esc_html__('No', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Site description', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($activation_meta['site_description']) ? esc_html($activation_meta['site_description']) : esc_html__('Not reported', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Site icon', 'wc-product-license'); ?></dt>
                                        <dd>
                                            <?php if (!empty($activation_meta['site_icon'])) : ?>
                                                <a href="<?php echo esc_url($activation_meta['site_icon']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($activation_meta['site_icon']); ?></a>
                                            <?php else : ?>
                                                <?php esc_html_e('Not reported', 'wc-product-license'); ?>
                                            <?php endif; ?>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-plugins" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="plugins" role="tabpanel" hidden>
                        <div class="wc-license-admin-card">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Plugin inventory', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Installed plugins snapshot', 'wc-product-license'); ?></h2>
                                </div>
                            </div>
                            <?php if (!empty($activation_plugins)) : ?>
                                <div class="wc-license-plugin-grid">
                                    <?php foreach ($activation_plugins as $plugin_item) : ?>
                                        <article class="wc-license-plugin-chip">
                                            <strong><?php echo esc_html($plugin_item['name'] ?? __('Plugin', 'wc-product-license')); ?></strong>
                                            <span><?php echo !empty($plugin_item['version']) ? esc_html('v' . $plugin_item['version']) : esc_html__('Version not reported', 'wc-product-license'); ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div class="wc-license-empty-state">
                                    <strong><?php esc_html_e('No plugin inventory reported yet', 'wc-product-license'); ?></strong>
                                    <span><?php esc_html_e('Tracking-enabled clients can send the installed plugin list so support teams can see integration patterns before troubleshooting.', 'wc-product-license'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section id="wc-license-detail-license" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="license" role="tabpanel" hidden>
                        <div class="wc-license-detail-grid">
                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('License context', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Linked license', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('License key', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $license_url ? '<a href="' . esc_url($license_url) . '">' . esc_html($activation->license_key) . '</a>' : esc_html($activation->license_key); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('License status', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $license ? wp_kses_post($this->get_license_status_badge($this->get_effective_license_status($license))) : esc_html__('License record unavailable', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Product', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $product_url ? '<a href="' . esc_url($product_url) . '">' . esc_html($product_label) . '</a>' : esc_html($product_label); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Order', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $order_url ? '<a href="' . esc_url($order_url) . '">#' . esc_html($order->get_id()) . '</a>' : esc_html__('No linked order', 'wc-product-license'); ?></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-customer" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="customer" role="tabpanel" hidden>
                        <div class="wc-license-detail-grid">
                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Customer context', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Linked customer', 'wc-product-license'); ?></h2>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Customer', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($customer_context['customer_url']) ? '<a href="' . esc_url($customer_context['customer_url']) . '">' . esc_html($customer_context['name']) . '</a>' : esc_html($customer_context['name']); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Email', 'wc-product-license'); ?></dt>
                                        <dd><?php echo !empty($customer_context['email']) ? esc_html($customer_context['email']) : esc_html__('No email recorded', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Type', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($customer_context['type_label']); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Order total', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $order ? wp_kses_post($order->get_formatted_order_total()) : esc_html__('No linked order', 'wc-product-license'); ?></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-activity" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="activity" role="tabpanel" hidden>
                        <div class="wc-license-admin-card">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Activity Log', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Site-specific timeline', 'wc-product-license'); ?></h2>
                                </div>
                            </div>
                            <?php if (!empty($activity_logs)) : ?>
                                <div class="wc-license-activity-log">
                                    <?php foreach ($activity_logs as $log) : ?>
                                        <?php $meta_items = $this->get_activity_log_meta_items($log); ?>
                                        <article class="wc-license-activity-log__item">
                                            <div class="wc-license-activity-log__top">
                                                <div class="wc-license-activity-log__badges">
                                                    <?php echo wp_kses_post($this->get_activity_event_badge((string) $log->event_type)); ?>
                                                    <?php echo wp_kses_post($this->get_activity_source_badge((string) $log->source)); ?>
                                                </div>
                                                <time class="wc-license-activity-log__time"><?php echo esc_html($this->get_activity_log_timestamp_label($log->created_at)); ?></time>
                                            </div>
                                            <p class="wc-license-activity-log__message"><?php echo esc_html($log->message); ?></p>
                                            <?php if (!empty($meta_items)) : ?>
                                                <div class="wc-license-activity-log__meta">
                                                    <?php foreach ($meta_items as $item) : ?>
                                                        <span class="wc-license-activity-log__meta-item">
                                                            <strong><?php echo esc_html($item['label']); ?></strong>
                                                            <?php if (!empty($item['url'])) : ?>
                                                                <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['value']); ?></a>
                                                            <?php else : ?>
                                                                <span><?php echo esc_html($item['value']); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div class="wc-license-empty-state">
                                    <strong><?php esc_html_e('No site activity recorded yet', 'wc-product-license'); ?></strong>
                                    <span><?php esc_html_e('Activation and deactivation events tied to this exact site URL will appear here.', 'wc-product-license'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_license_admin_notices()
    {
        $notice_map = [
            'bulk_activated' => [
                'type' => 'success',
                'message' => _n('%d license activated.', '%d licenses activated.', isset($_GET['bulk_activated']) ? absint($_GET['bulk_activated']) : 0, 'wc-product-license'),
            ],
            'bulk_deactivated' => [
                'type' => 'success',
                'message' => _n('%d license deactivated.', '%d licenses deactivated.', isset($_GET['bulk_deactivated']) ? absint($_GET['bulk_deactivated']) : 0, 'wc-product-license'),
            ],
            'bulk_deleted' => [
                'type' => 'success',
                'message' => _n('%d license deleted.', '%d licenses deleted.', isset($_GET['bulk_deleted']) ? absint($_GET['bulk_deleted']) : 0, 'wc-product-license'),
            ],
        ];

        foreach ($notice_map as $query_key => $notice) {
            if (empty($_GET[$query_key])) {
                continue;
            }

            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message'])
            );
        }

        if (!empty($_GET['wc_license_notice']) && $_GET['wc_license_notice'] === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('License deleted successfully.', 'wc-product-license') . '</p></div>';
        }
    }

    private function get_license_list_summary()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $rows = $wpdb->get_results("SELECT id, status, expires_at, sites_active FROM {$table_name}", ARRAY_A);
        $summary = [
            'total' => 0,
            'active' => 0,
            'expiring' => 0,
            'activations' => 0,
        ];
        $cutoff = strtotime('+30 days', current_time('timestamp'));

        foreach ((array) $rows as $row) {
            $summary['total']++;
            $summary['activations'] += isset($row['sites_active']) ? (int) $row['sites_active'] : 0;

            $effective_status = $this->get_effective_license_status($row);
            if ($effective_status === 'active') {
                $summary['active']++;
            }

            if (!empty($row['expires_at']) && $row['expires_at'] !== '0000-00-00 00:00:00') {
                $expiry_timestamp = strtotime($row['expires_at']);
                if ($effective_status === 'active' && $expiry_timestamp && $expiry_timestamp <= $cutoff) {
                    $summary['expiring']++;
                }
            }
        }

        return $summary;
    }

    private function get_license_status_counts()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_product_licenses';
        $rows = $wpdb->get_results("SELECT id, status, expires_at FROM {$table_name}", ARRAY_A);
        $counts = [
            'all' => 0,
            'active' => 0,
            'inactive' => 0,
            'expired' => 0,
            'expiring' => 0,
        ];
        $cutoff = strtotime('+30 days', current_time('timestamp'));

        foreach ((array) $rows as $row) {
            $counts['all']++;
            $effective_status = $this->get_effective_license_status($row);
            if (isset($counts[$effective_status])) {
                $counts[$effective_status]++;
            }

            if (!empty($row['expires_at']) && $row['expires_at'] !== '0000-00-00 00:00:00') {
                $expiry_timestamp = strtotime($row['expires_at']);
                if ($effective_status === 'active' && $expiry_timestamp && $expiry_timestamp <= $cutoff) {
                    $counts['expiring']++;
                }
            }
        }

        return $counts;
    }

    private function get_license_list_url($args = [])
    {
        return add_query_arg($args, admin_url('admin.php?page=wc-license-keys'));
    }

    private function get_license_manage_url($license_id, $args = [])
    {
        $args = wp_parse_args($args, [
            'page' => 'wc-license-keys',
            'action' => 'manage',
            'license_id' => absint($license_id),
        ]);

        return add_query_arg($args, admin_url('admin.php'));
    }

    private function get_license_record($license_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_product_licenses';

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $license_id));
    }

    private function get_effective_license_status($license)
    {
        return wc_product_license_get_effective_status($license);
    }

    private function get_license_status_badge($status)
    {
        $status_classes = [
            'active' => 'license-status license-active',
            'inactive' => 'license-status license-inactive',
            'expired' => 'license-status license-expired',
        ];
        $status_labels = [
            'active' => __('Active', 'wc-product-license'),
            'inactive' => __('Inactive', 'wc-product-license'),
            'expired' => __('Expired', 'wc-product-license'),
        ];

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr(isset($status_classes[$status]) ? $status_classes[$status] : 'license-status'),
            esc_html(isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status))
        );
    }

    private function get_customer_display_markup($license)
    {
        $context = wc_product_license_get_customer_context_from_license($license);
        $meta = $context['email'] !== '' ? $context['email'] : $context['type_label'];

        return sprintf(
            '<a href="%1$s">%2$s</a><span class="wc-license-detail-meta">%3$s</span>',
            esc_url($context['customer_url']),
            esc_html($context['name']),
            esc_html($meta)
        );
    }

    private function get_license_expiration_label($expires_at)
    {
        if (empty($expires_at) || $expires_at === '0000-00-00 00:00:00') {
            return __('Lifetime', 'wc-product-license');
        }

        $timestamp = strtotime($expires_at);
        if (!$timestamp) {
            return __('Unknown', 'wc-product-license');
        }

        if ($timestamp < current_time('timestamp')) {
            return sprintf(__('Expired on %s', 'wc-product-license'), date_i18n(get_option('date_format'), $timestamp));
        }

        $days_left = ceil(($timestamp - current_time('timestamp')) / DAY_IN_SECONDS);

        return sprintf(
            __('%1$s (%2$s)', 'wc-product-license'),
            date_i18n(get_option('date_format'), $timestamp),
            sprintf(_n('%d day left', '%d days left', $days_left, 'wc-product-license'), $days_left)
        );
    }

    private function get_activity_event_badge($event_type)
    {
        $map = [
            'license_created' => ['label' => __('Created', 'wc-product-license'), 'class' => 'is-created'],
            'license_updated' => ['label' => __('Updated', 'wc-product-license'), 'class' => 'is-updated'],
            'license_upgraded' => ['label' => __('Upgrade', 'wc-product-license'), 'class' => 'is-updated'],
            'status_changed' => ['label' => __('Status', 'wc-product-license'), 'class' => 'is-status'],
            'license_extended' => ['label' => __('Renewal', 'wc-product-license'), 'class' => 'is-renewal'],
            'license_lifetime' => ['label' => __('Lifetime', 'wc-product-license'), 'class' => 'is-renewal'],
            'site_activated' => ['label' => __('Activation', 'wc-product-license'), 'class' => 'is-activation'],
            'site_deactivated' => ['label' => __('Deactivation', 'wc-product-license'), 'class' => 'is-deactivation'],
            'telemetry_synced' => ['label' => __('Telemetry', 'wc-product-license'), 'class' => 'is-updated'],
            'activations_reset' => ['label' => __('Reset', 'wc-product-license'), 'class' => 'is-reset'],
            'license_deleted' => ['label' => __('Deleted', 'wc-product-license'), 'class' => 'is-deleted'],
        ];

        $badge = isset($map[$event_type]) ? $map[$event_type] : [
            'label' => ucwords(str_replace('_', ' ', (string) $event_type)),
            'class' => 'is-default',
        ];

        return sprintf(
            '<span class="wc-license-activity-badge %1$s">%2$s</span>',
            esc_attr($badge['class']),
            esc_html($badge['label'])
        );
    }

    private function get_activity_source_badge($source)
    {
        $map = [
            'admin' => ['label' => __('Admin', 'wc-product-license'), 'class' => 'is-admin'],
            'order' => ['label' => __('Checkout', 'wc-product-license'), 'class' => 'is-order'],
            'api' => ['label' => __('API', 'wc-product-license'), 'class' => 'is-api'],
            'customer_ajax' => ['label' => __('Customer', 'wc-product-license'), 'class' => 'is-customer'],
            'customer' => ['label' => __('Customer', 'wc-product-license'), 'class' => 'is-customer'],
            'system' => ['label' => __('System', 'wc-product-license'), 'class' => 'is-system'],
        ];

        $badge = isset($map[$source]) ? $map[$source] : [
            'label' => ucwords(str_replace('_', ' ', (string) $source)),
            'class' => 'is-system',
        ];

        return sprintf(
            '<span class="wc-license-activity-source %1$s">%2$s</span>',
            esc_attr($badge['class']),
            esc_html($badge['label'])
        );
    }

    private function get_activity_log_meta_items($log)
    {
        $details = isset($log->details_data) && is_array($log->details_data) ? $log->details_data : [];
        $items = [];

        if (!empty($log->actor_name)) {
            $items[] = [
                'label' => __('Actor', 'wc-product-license'),
                'value' => $log->actor_name,
            ];
        }

        if (!empty($log->site_url)) {
            $items[] = [
                'label' => __('Site', 'wc-product-license'),
                'value' => $log->site_url,
                'url' => $log->site_url,
            ];
        }

        if (!empty($details['changed_fields']) && is_array($details['changed_fields'])) {
            $items[] = [
                'label' => __('Updated', 'wc-product-license'),
                'value' => implode(', ', array_map('strval', $details['changed_fields'])),
            ];
        }

        if (!empty($details['previous_status']) || !empty($details['new_status'])) {
            $items[] = [
                'label' => __('Status', 'wc-product-license'),
                'value' => trim(ucfirst((string) ($details['previous_status'] ?? '')) . ' -> ' . ucfirst((string) ($details['new_status'] ?? '')), ' ->'),
            ];
        }

        if (!empty($details['extend_days'])) {
            $items[] = [
                'label' => __('Extension', 'wc-product-license'),
                'value' => sprintf(_n('%d day', '%d days', (int) $details['extend_days'], 'wc-product-license'), (int) $details['extend_days']),
            ];
        }

        if (isset($details['removed_site_count'])) {
            $items[] = [
                'label' => __('Removed sites', 'wc-product-license'),
                'value' => number_format_i18n((int) $details['removed_site_count']),
            ];
        }

        if (!empty($details['order_id'])) {
            $items[] = [
                'label' => __('Order', 'wc-product-license'),
                'value' => '#' . absint($details['order_id']),
                'url' => admin_url('post.php?post=' . absint($details['order_id']) . '&action=edit'),
            ];
        }

        if (!empty($details['reason'])) {
            $items[] = [
                'label' => __('Reason', 'wc-product-license'),
                'value' => $details['reason'],
            ];
        }

        if (!empty($details['environment_type'])) {
            $items[] = [
                'label' => __('Environment', 'wc-product-license'),
                'value' => ucfirst((string) $details['environment_type']),
            ];
        }

        if (!empty($details['theme'])) {
            $items[] = [
                'label' => __('Theme', 'wc-product-license'),
                'value' => $details['theme'],
            ];
        }

        return $items;
    }

    private function get_activity_log_timestamp_label($created_at)
    {
        if (empty($created_at)) {
            return __('Unknown time', 'wc-product-license');
        }

        $timestamp = strtotime((string) $created_at);
        if (!$timestamp) {
            return __('Unknown time', 'wc-product-license');
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function extend_license_expiration($license_id, $days = 0, $set_lifetime = false)
    {
        global $wpdb;

        $license = $this->get_license_record($license_id);
        if (!$license) {
            return false;
        }

        $table_name = $wpdb->prefix . 'wc_product_licenses';

        if ($set_lifetime) {
            return false !== $wpdb->update(
                $table_name,
                ['expires_at' => null],
                ['id' => $license_id],
                ['%s'],
                ['%d']
            );
        }

        $days = absint($days);
        if ($days < 1) {
            return false;
        }

        $current_expiry = !empty($license->expires_at) && $license->expires_at !== '0000-00-00 00:00:00'
            ? strtotime($license->expires_at)
            : false;
        $baseline = $current_expiry && $current_expiry > current_time('timestamp')
            ? $current_expiry
            : current_time('timestamp');
        $new_expiry = date('Y-m-d H:i:s', strtotime('+' . $days . ' days', $baseline));

        return false !== $wpdb->update(
            $table_name,
            ['expires_at' => $new_expiry],
            ['id' => $license_id],
            ['%s'],
            ['%d']
        );
    }

    private function reset_license_sites($license_id, $site_url = '')
    {
        global $wpdb;

        $license = $this->get_license_record($license_id);
        if (!$license) {
            return false;
        }

        $active_sites = maybe_unserialize($license->active_sites);
        $active_sites = is_array($active_sites) ? $active_sites : [];
        $previous_sites = $active_sites;

        if ($site_url !== '') {
            if (!isset($active_sites[$site_url])) {
                return false;
            }

            unset($active_sites[$site_url]);
        } else {
            $active_sites = [];
        }

        $updated = false !== $wpdb->update(
            $wpdb->prefix . 'wc_product_licenses',
            [
                'sites_active' => count($active_sites),
                'active_sites' => maybe_serialize($active_sites),
            ],
            ['id' => $license_id],
            ['%d', '%s'],
            ['%d']
        );

        if ($updated) {
            if ($site_url !== '') {
                wc_product_license_mark_activation_inactive($license, $site_url, [
                    'timestamp' => current_time('mysql'),
                ]);
            } else {
                wc_product_license_mark_all_activations_inactive($license, array_keys($previous_sites), [
                    'timestamp' => current_time('mysql'),
                ]);
            }
        }

        return $updated;
    }

    private function handle_license_details_request($license_id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['wc_license_detail_action'])) {
            return;
        }

        if (
            !isset($_POST['wc_license_detail_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['wc_license_detail_nonce']), 'wc_license_detail_' . $license_id)
        ) {
            add_settings_error('wc_license_messages', 'wc_license_nonce', __('Security check failed. Please refresh and try again.', 'wc-product-license'), 'error');
            return;
        }

        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');
        $detail_action = sanitize_key(wp_unslash($_POST['wc_license_detail_action']));
        $current_license = $this->get_license_record($license_id);

        if (!$current_license) {
            add_settings_error('wc_license_messages', 'wc_license_missing', __('License not found.', 'wc-product-license'), 'error');
            return;
        }

        switch ($detail_action) {
            case 'save_license':
                $this->handle_edit_license_form($license_id);
                break;
            case 'set_status':
                $target_status = sanitize_key(wp_unslash(isset($_POST['target_status']) ? $_POST['target_status'] : 'inactive'));
                if (!in_array($target_status, ['active', 'inactive', 'expired'], true)) {
                    add_settings_error('wc_license_messages', 'wc_license_invalid_status', __('Invalid status selected.', 'wc-product-license'), 'error');
                    return;
                }

                $result = $wpdb->update($table_name, ['status' => $target_status], ['id' => $license_id], ['%s'], ['%d']);
                if ($result !== false) {
                    if ((string) $current_license->status !== $target_status) {
                        wc_product_license_log_event(
                            $current_license,
                            'status_changed',
                            sprintf(__('License status changed from %1$s to %2$s.', 'wc-product-license'), ucfirst((string) $current_license->status), ucfirst($target_status)),
                            [
                                'source' => 'admin',
                                'actor_id' => get_current_user_id(),
                                'details' => [
                                    'previous_status' => (string) $current_license->status,
                                    'new_status' => $target_status,
                                ],
                            ]
                        );
                    }
                    add_settings_error('wc_license_messages', 'wc_license_status_updated', __('License status updated successfully.', 'wc-product-license'), 'updated');
                } else {
                    add_settings_error('wc_license_messages', 'wc_license_status_update_error', __('Unable to update the license status.', 'wc-product-license'), 'error');
                }
                break;
            case 'extend_license':
                $set_lifetime = !empty($_POST['set_lifetime']);
                $extend_days = isset($_POST['extend_days']) ? absint($_POST['extend_days']) : 0;
                if ($this->extend_license_expiration($license_id, $extend_days, $set_lifetime)) {
                    $updated_license = $this->get_license_record($license_id);
                    wc_product_license_log_event(
                        $updated_license ? $updated_license : $current_license,
                        $set_lifetime ? 'license_lifetime' : 'license_extended',
                        $set_lifetime
                            ? __('License converted to lifetime access from the details page.', 'wc-product-license')
                            : sprintf(__('License validity extended by %d days.', 'wc-product-license'), $extend_days),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'details' => [
                                'previous_expires_at' => (string) $current_license->expires_at,
                                'new_expires_at' => $updated_license ? (string) $updated_license->expires_at : '',
                                'extend_days' => $extend_days,
                                'set_lifetime' => $set_lifetime ? 1 : 0,
                            ],
                        ]
                    );
                    add_settings_error('wc_license_messages', 'wc_license_extended', $set_lifetime ? __('License converted to lifetime access.', 'wc-product-license') : __('License validity extended successfully.', 'wc-product-license'), 'updated');
                } else {
                    add_settings_error('wc_license_messages', 'wc_license_extend_error', __('Unable to extend this license.', 'wc-product-license'), 'error');
                }
                break;
            case 'reset_sites':
                if ($this->reset_license_sites($license_id)) {
                    $previous_sites = maybe_unserialize($current_license->active_sites);
                    $previous_sites = is_array($previous_sites) ? $previous_sites : [];
                    wc_product_license_log_event(
                        $current_license,
                        'activations_reset',
                        __('All site activations were cleared from the details page.', 'wc-product-license'),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'details' => [
                                'removed_site_count' => count($previous_sites),
                            ],
                        ]
                    );
                    add_settings_error('wc_license_messages', 'wc_license_sites_reset', __('All site activations were cleared.', 'wc-product-license'), 'updated');
                } else {
                    add_settings_error('wc_license_messages', 'wc_license_sites_reset_error', __('No site activations were updated.', 'wc-product-license'), 'error');
                }
                break;
            case 'deactivate_site':
                $site_url = isset($_POST['site_url']) ? esc_url_raw(wp_unslash($_POST['site_url'])) : '';
                if ($site_url !== '' && $this->reset_license_sites($license_id, $site_url)) {
                    wc_product_license_log_event(
                        $current_license,
                        'site_deactivated',
                        sprintf(__('Site deactivated from the details page: %s', 'wc-product-license'), $site_url),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'site_url' => $site_url,
                        ]
                    );
                    add_settings_error('wc_license_messages', 'wc_license_site_removed', __('Site activation removed successfully.', 'wc-product-license'), 'updated');
                } else {
                    add_settings_error('wc_license_messages', 'wc_license_site_remove_error', __('Unable to remove that site activation.', 'wc-product-license'), 'error');
                }
                break;
            case 'delete_license':
                wc_product_license_log_event(
                    $current_license,
                    'license_deleted',
                    __('License deleted from the details page.', 'wc-product-license'),
                    [
                        'source' => 'admin',
                        'actor_id' => get_current_user_id(),
                    ]
                );
                wc_product_license_delete_activation_records($current_license);
                $wpdb->delete($table_name, ['id' => $license_id], ['%d']);
                wp_safe_redirect($this->get_license_list_url(['wc_license_notice' => 'deleted']));
                exit;
        }
    }

    private function render_license_details_page($license_id)
    {
        $license = $this->get_license_record($license_id);
        if (!$license) {
            wp_die(__('License not found.', 'wc-product-license'));
        }

        $this->handle_license_details_request($license_id);
        $license = $this->get_license_record($license_id);

        $product = wc_get_product($license->product_id);
        $active_sites = maybe_unserialize($license->active_sites);
        $active_sites = is_array($active_sites) ? $active_sites : [];
        $effective_status = $this->get_effective_license_status($license);
        $order_url = !empty($license->order_id) ? admin_url('post.php?post=' . absint($license->order_id) . '&action=edit') : '';
        $product_url = $product ? admin_url('post.php?post=' . $product->get_id() . '&action=edit') : '';
        $manage_url = $this->get_license_manage_url($license_id);
        $customer_context = wc_product_license_get_customer_context_from_license($license);
        $customer_markup = $this->get_customer_display_markup($license);
        $sites_allowed_label = wc_product_license_get_activation_limit_text($license->sites_allowed);
        $activation_usage = wc_product_license_get_activation_usage_text((int) $license->sites_active, (int) $license->sites_allowed);
        $expiry_label = $this->get_license_expiration_label($license->expires_at);
        $activity_logs = wc_product_license_get_activity_logs($license_id, 50);
        $activity_count = wc_product_license_get_activity_log_count($license_id);
        $active_site_count = count($active_sites);
        $purchase_label = !empty($license->purchased_at)
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($license->purchased_at))
            : __('No purchase date recorded', 'wc-product-license');
        $licensed_products = wc_get_products([
            'limit' => -1,
            'type' => 'simple',
            'downloadable' => true,
            'meta_key' => '_is_license_product',
            'meta_value' => 'yes',
        ]);
        $users = get_users(['fields' => ['ID', 'user_email', 'display_name']]);
        ?>
        <div class="wrap wc-license-admin-page wc-license-detail-page">
            <div class="wc-license-detail-header">
                <div class="wc-license-admin-header__copy">
                    <a href="<?php echo esc_url($this->get_license_list_url()); ?>" class="page-title-action wc-license-detail-header__back"><?php esc_html_e('Back to Licenses', 'wc-product-license'); ?></a>
                    <h1><?php echo esc_html(sprintf(__('License Details: %s', 'wc-product-license'), $license->license_key)); ?></h1>
                    <p><?php esc_html_e('Manage customer access, adjust validity, review related commerce records, and resolve activation issues from this screen.', 'wc-product-license'); ?></p>
                </div>
                <div class="wc-license-admin-header__actions">
                    <?php if (!empty($customer_context['customer_url'])) : ?>
                        <a href="<?php echo esc_url($customer_context['customer_url']); ?>" class="button button-secondary"><?php esc_html_e('View Customer', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if ($order_url) : ?>
                        <a href="<?php echo esc_url($order_url); ?>" class="button button-secondary"><?php esc_html_e('View Order', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                    <?php if ($product_url) : ?>
                        <a href="<?php echo esc_url($product_url); ?>" class="button button-secondary"><?php esc_html_e('View Product', 'wc-product-license'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php settings_errors('wc_license_messages'); ?>

            <div class="wc-license-admin-summary wc-license-admin-summary--details">
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Status', 'wc-product-license'); ?></span>
                    <strong><?php echo wp_kses_post($this->get_license_status_badge($effective_status)); ?></strong>
                    <small><?php esc_html_e('Effective status after expiration checks.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Activations', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html($activation_usage); ?></strong>
                    <small><?php echo esc_html($sites_allowed_label); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Expires', 'wc-product-license'); ?></span>
                    <strong><?php echo esc_html($expiry_label); ?></strong>
                    <small><?php esc_html_e('Renew or convert to lifetime access below.', 'wc-product-license'); ?></small>
                </div>
                <div class="wc-license-admin-stat">
                    <span><?php esc_html_e('Purchase value', 'wc-product-license'); ?></span>
                    <strong><?php echo wp_kses_post(wc_price((float) $license->purchased_price)); ?></strong>
                    <small><?php echo esc_html($purchase_label); ?></small>
                </div>
            </div>

            <div class="wc-license-detail-tabs" data-wc-license-detail-tabs>
                <div class="wc-license-detail-tabs__nav" role="tablist" aria-label="<?php esc_attr_e('License sections', 'wc-product-license'); ?>">
                    <button type="button" class="wc-license-detail-tabs__nav-item is-active" data-license-detail-tab-target="overview" role="tab" aria-selected="true" aria-controls="wc-license-detail-overview">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Overview', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Links and quick actions', 'wc-product-license'); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="license" role="tab" aria-selected="false" aria-controls="wc-license-detail-license">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('License', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Core record and assignment', 'wc-product-license'); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="renewals" role="tab" aria-selected="false" aria-controls="wc-license-detail-renewals">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Renewals', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($expiry_label); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="activations" role="tab" aria-selected="false" aria-controls="wc-license-detail-activations">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Activations', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php echo esc_html($activation_usage); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n($active_site_count)); ?></span>
                    </button>
                    <button type="button" class="wc-license-detail-tabs__nav-item" data-license-detail-tab-target="activity" role="tab" aria-selected="false" aria-controls="wc-license-detail-activity">
                        <span class="wc-license-detail-tabs__nav-label"><?php esc_html_e('Activity Log', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-meta"><?php esc_html_e('Timeline of key events', 'wc-product-license'); ?></span>
                        <span class="wc-license-detail-tabs__nav-count"><?php echo esc_html(number_format_i18n($activity_count)); ?></span>
                    </button>
                </div>

                <div class="wc-license-detail-tabs__content">
                    <section id="wc-license-detail-overview" class="wc-license-detail-tab-panel is-active" data-license-detail-tab-panel="overview" role="tabpanel">
                        <div class="wc-license-detail-grid">
                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Related records', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Commerce links', 'wc-product-license'); ?></h2>
                                        <p><?php esc_html_e('Open the connected product, order, and customer records without leaving the license workflow.', 'wc-product-license'); ?></p>
                                    </div>
                                </div>
                                <dl class="wc-license-detail-list">
                                    <div>
                                        <dt><?php esc_html_e('Product', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $product_url && $product ? '<a href="' . esc_url($product_url) . '">' . esc_html($product->get_name()) . '</a>' : esc_html__('Unknown product', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Customer', 'wc-product-license'); ?></dt>
                                        <dd><?php echo wp_kses_post($customer_markup); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Order', 'wc-product-license'); ?></dt>
                                        <dd><?php echo $order_url ? '<a href="' . esc_url($order_url) . '">#' . esc_html($license->order_id) . '</a>' : esc_html__('Manual / not linked', 'wc-product-license'); ?></dd>
                                    </div>
                                    <div>
                                        <dt><?php esc_html_e('Purchased', 'wc-product-license'); ?></dt>
                                        <dd><?php echo esc_html($purchase_label); ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="wc-license-admin-card">
                                <div class="wc-license-admin-card__header">
                                    <div>
                                        <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Quick actions', 'wc-product-license'); ?></span>
                                        <h2><?php esc_html_e('Status controls', 'wc-product-license'); ?></h2>
                                        <p><?php esc_html_e('Change the license state immediately when you need to enable, disable, expire, or remove access.', 'wc-product-license'); ?></p>
                                    </div>
                                </div>
                                <div class="wc-license-quick-actions wc-license-quick-actions--stack">
                                    <?php foreach (['active' => __('Activate', 'wc-product-license'), 'inactive' => __('Deactivate', 'wc-product-license'), 'expired' => __('Mark Expired', 'wc-product-license')] as $status_key => $status_label) : ?>
                                        <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                            <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                            <input type="hidden" name="wc_license_detail_action" value="set_status" />
                                            <input type="hidden" name="target_status" value="<?php echo esc_attr($status_key); ?>" />
                                            <button type="submit" class="button button-secondary"><?php echo esc_html($status_label); ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                    <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                        <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                        <input type="hidden" name="wc_license_detail_action" value="delete_license" />
                                        <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete License', 'wc-product-license'); ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-license" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="license" role="tabpanel" hidden>
                        <div class="wc-license-admin-card">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Core record', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Manage License Details', 'wc-product-license'); ?></h2>
                                    <p><?php esc_html_e('Update the key, attach it to a different product or customer, change status, and adjust sales data without leaving this screen.', 'wc-product-license'); ?></p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-detail-form">
                                <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                <input type="hidden" name="wc_license_detail_action" value="save_license" />
                                <div class="wc-license-detail-field-grid">
                                    <div class="wc-license-detail-field wc-license-detail-field--full">
                                        <label for="license_key"><?php esc_html_e('License Key', 'wc-product-license'); ?></label>
                                        <input type="text" id="license_key" name="license_key" value="<?php echo esc_attr($license->license_key); ?>" class="regular-text" required />
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_product"><?php esc_html_e('Product', 'wc-product-license'); ?></label>
                                        <select id="license_product" name="license_product" required>
                                            <?php foreach ($licensed_products as $available_product) : ?>
                                                <option value="<?php echo esc_attr($available_product->get_id()); ?>" <?php selected($license->product_id, $available_product->get_id()); ?>><?php echo esc_html($available_product->get_name()); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_user"><?php esc_html_e('Customer', 'wc-product-license'); ?></label>
                                        <select id="license_user" name="license_user" required>
                                            <option value="0" <?php selected((int) $license->user_id, 0); ?>><?php esc_html_e('Guest checkout', 'wc-product-license'); ?></option>
                                            <?php foreach ($users as $user) : ?>
                                                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($license->user_id, $user->ID); ?>><?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_status"><?php esc_html_e('Status', 'wc-product-license'); ?></label>
                                        <select id="license_status" name="license_status">
                                            <option value="active" <?php selected($license->status, 'active'); ?>><?php esc_html_e('Active', 'wc-product-license'); ?></option>
                                            <option value="inactive" <?php selected($license->status, 'inactive'); ?>><?php esc_html_e('Inactive', 'wc-product-license'); ?></option>
                                            <option value="expired" <?php selected($license->status, 'expired'); ?>><?php esc_html_e('Expired', 'wc-product-license'); ?></option>
                                        </select>
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_sites"><?php esc_html_e('Sites Allowed', 'wc-product-license'); ?></label>
                                        <input type="number" id="license_sites" name="license_sites" value="<?php echo esc_attr(wc_product_license_is_unlimited_sites($license->sites_allowed) ? 1 : $license->sites_allowed); ?>" min="1" class="small-text" required />
                                        <label class="wc-license-detail-checkbox">
                                            <input type="checkbox" class="wc-license-manual-unlimited-toggle" name="license_unlimited_sites" value="1" data-target="#license_sites" <?php checked(wc_product_license_is_unlimited_sites($license->sites_allowed)); ?> />
                                            <span><?php esc_html_e('Unlimited site activations', 'wc-product-license'); ?></span>
                                        </label>
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_expires"><?php esc_html_e('Expiration Date', 'wc-product-license'); ?></label>
                                        <input type="date" id="license_expires" name="license_expires" value="<?php echo esc_attr(!empty($license->expires_at) && $license->expires_at !== '0000-00-00 00:00:00' ? substr($license->expires_at, 0, 10) : ''); ?>" />
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_order"><?php esc_html_e('Order ID', 'wc-product-license'); ?></label>
                                        <input type="number" id="license_order" name="license_order" value="<?php echo esc_attr((int) $license->order_id); ?>" min="0" />
                                    </div>
                                    <div class="wc-license-detail-field">
                                        <label for="license_price"><?php esc_html_e('Purchase Price', 'wc-product-license'); ?></label>
                                        <input type="number" id="license_price" name="license_price" value="<?php echo esc_attr(wc_format_decimal($license->purchased_price)); ?>" min="0" step="0.01" />
                                    </div>
                                </div>
                                <div class="wc-license-detail-form__actions">
                                    <button type="submit" name="submit_license" class="button button-primary"><?php esc_html_e('Update License', 'wc-product-license'); ?></button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section id="wc-license-detail-renewals" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="renewals" role="tabpanel" hidden>
                        <div class="wc-license-admin-card" id="wc-license-renewal-panel">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Renewal controls', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Extend or convert validity', 'wc-product-license'); ?></h2>
                                    <p><?php esc_html_e('Apply quick extension presets or convert the license to lifetime access. These actions update the stored expiration immediately.', 'wc-product-license'); ?></p>
                                </div>
                            </div>
                            <div class="wc-license-detail-callout">
                                <strong><?php esc_html_e('Current validity', 'wc-product-license'); ?></strong>
                                <span><?php echo esc_html($expiry_label); ?></span>
                            </div>
                            <div class="wc-license-quick-actions">
                                <?php foreach ([30, 90, 365] as $extend_days) : ?>
                                    <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                        <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                        <input type="hidden" name="wc_license_detail_action" value="extend_license" />
                                        <input type="hidden" name="extend_days" value="<?php echo esc_attr($extend_days); ?>" />
                                        <button type="submit" class="button button-secondary"><?php echo esc_html(sprintf(__('Extend %d days', 'wc-product-license'), $extend_days)); ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                    <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                    <input type="hidden" name="wc_license_detail_action" value="extend_license" />
                                    <input type="hidden" name="set_lifetime" value="1" />
                                    <button type="submit" class="button button-secondary"><?php esc_html_e('Convert to Lifetime', 'wc-product-license'); ?></button>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section id="wc-license-detail-activations" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="activations" role="tabpanel" hidden>
                        <div class="wc-license-admin-card">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Site activations', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Manage activations', 'wc-product-license'); ?></h2>
                                    <p><?php esc_html_e('Review every activated site, remove individual activations, or reset the entire activation set if a customer needs a clean slate.', 'wc-product-license'); ?></p>
                                </div>
                                <?php if (!empty($active_sites)) : ?>
                                    <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                        <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                        <input type="hidden" name="wc_license_detail_action" value="reset_sites" />
                                        <button type="submit" class="button button-secondary"><?php esc_html_e('Reset Activations', 'wc-product-license'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="wc-license-detail-callout-group">
                                <div class="wc-license-detail-callout">
                                    <strong><?php esc_html_e('Usage', 'wc-product-license'); ?></strong>
                                    <span><?php echo esc_html($activation_usage); ?></span>
                                </div>
                                <div class="wc-license-detail-callout">
                                    <strong><?php esc_html_e('Limit', 'wc-product-license'); ?></strong>
                                    <span><?php echo esc_html($sites_allowed_label); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($active_sites)) : ?>
                                <table class="widefat striped wc-license-detail-sites">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Site URL', 'wc-product-license'); ?></th>
                                            <th><?php esc_html_e('Activated On', 'wc-product-license'); ?></th>
                                            <th><?php esc_html_e('Actions', 'wc-product-license'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_sites as $site_url => $activation_date) : ?>
                                            <?php
                                            $site_activation_id = 0;
                                            global $wpdb;
                                            $site_activation_id = (int) $wpdb->get_var($wpdb->prepare(
                                                'SELECT id FROM ' . wc_product_license_get_table_name('activations') . ' WHERE license_id = %d AND site_hash = %s LIMIT 1',
                                                $license_id,
                                                wc_product_license_get_activation_site_hash($site_url)
                                            ));
                                            ?>
                                            <tr>
                                                <td><a href="<?php echo esc_url($site_url); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($site_url); ?></a></td>
                                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($activation_date))); ?></td>
                                                <td>
                                                    <div class="license-actions">
                                                        <?php if ($site_activation_id > 0) : ?>
                                                            <a href="<?php echo esc_url(wc_product_license_get_site_manage_url($site_activation_id)); ?>" class="button button-secondary"><?php esc_html_e('Manage', 'wc-product-license'); ?></a>
                                                        <?php endif; ?>
                                                        <form method="post" action="<?php echo esc_url($manage_url); ?>" class="wc-license-inline-form">
                                                            <?php wp_nonce_field('wc_license_detail_' . $license_id, 'wc_license_detail_nonce'); ?>
                                                            <input type="hidden" name="wc_license_detail_action" value="deactivate_site" />
                                                            <input type="hidden" name="site_url" value="<?php echo esc_attr($site_url); ?>" />
                                                            <button type="submit" class="button button-link-delete"><?php esc_html_e('Deactivate', 'wc-product-license'); ?></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else : ?>
                                <div class="wc-license-empty-state">
                                    <strong><?php esc_html_e('No active sites', 'wc-product-license'); ?></strong>
                                    <p><?php esc_html_e('This license has not been activated on any site yet.', 'wc-product-license'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section id="wc-license-detail-activity" class="wc-license-detail-tab-panel" data-license-detail-tab-panel="activity" role="tabpanel" hidden>
                        <div class="wc-license-admin-card">
                            <div class="wc-license-admin-card__header">
                                <div>
                                    <span class="wc-license-admin-card__eyebrow"><?php esc_html_e('Audit history', 'wc-product-license'); ?></span>
                                    <h2><?php esc_html_e('Activity Log', 'wc-product-license'); ?></h2>
                                    <p><?php esc_html_e('Stored events for this license, including admin actions, customer activations, checkout issuance, and API calls.', 'wc-product-license'); ?></p>
                                </div>
                            </div>

                            <p class="wc-license-detail-tab-note">
                                <?php
                                if ($activity_count > count($activity_logs)) {
                                    echo esc_html(sprintf(__('Showing the latest %1$d of %2$d events.', 'wc-product-license'), count($activity_logs), $activity_count));
                                } else {
                                    echo esc_html(sprintf(_n('%d event recorded.', '%d events recorded.', $activity_count, 'wc-product-license'), $activity_count));
                                }
                                ?>
                            </p>

                            <?php if (!empty($activity_logs)) : ?>
                                <div class="wc-license-activity-log">
                                    <?php foreach ($activity_logs as $log) : ?>
                                        <?php $meta_items = $this->get_activity_log_meta_items($log); ?>
                                        <article class="wc-license-activity-log__item">
                                            <div class="wc-license-activity-log__top">
                                                <div class="wc-license-activity-log__badges">
                                                    <?php echo wp_kses_post($this->get_activity_event_badge((string) $log->event_type)); ?>
                                                    <?php echo wp_kses_post($this->get_activity_source_badge((string) $log->source)); ?>
                                                </div>
                                                <time class="wc-license-activity-log__time"><?php echo esc_html($this->get_activity_log_timestamp_label($log->created_at)); ?></time>
                                            </div>
                                            <p class="wc-license-activity-log__message"><?php echo esc_html($log->message); ?></p>
                                            <?php if (!empty($meta_items)) : ?>
                                                <div class="wc-license-activity-log__meta">
                                                    <?php foreach ($meta_items as $meta_item) : ?>
                                                        <span class="wc-license-activity-log__meta-item">
                                                            <strong><?php echo esc_html($meta_item['label']); ?>:</strong>
                                                            <?php if (!empty($meta_item['url'])) : ?>
                                                                <a href="<?php echo esc_url($meta_item['url']); ?>" target="_blank" rel="noreferrer"><?php echo esc_html($meta_item['value']); ?></a>
                                                            <?php else : ?>
                                                                <span><?php echo esc_html($meta_item['value']); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div class="wc-license-empty-state">
                                    <strong><?php esc_html_e('No activity recorded yet', 'wc-product-license'); ?></strong>
                                    <p><?php esc_html_e('New license actions and activations will appear here automatically.', 'wc-product-license'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the add new license page
     */
    public function render_add_license_page()
    {
        // Handle form submission
        if (isset($_POST['submit_license']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'add_new_license')) {
            $this->handle_add_license_form();
        }

        // Get products for dropdown
        $products = wc_get_products([
            'limit' => -1,
            'type' => 'simple',
            'downloadable' => true,
            'meta_key' => '_is_license_product',
            'meta_value' => 'yes'
        ]);

        // Get users for dropdown
        $users = get_users(['fields' => ['ID', 'user_email', 'display_name']]);

    ?>
        <div class="wrap">
            <h1><?php _e('Add New License', 'wc-product-license'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('add_new_license'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="license_product"><?php _e('Product', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_product" name="license_product" required>
                                <option value=""><?php _e('-- Select Product --', 'wc-product-license'); ?></option>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo esc_attr($product->get_id()); ?>"><?php echo esc_html($product->get_name()); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_user"><?php _e('User', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_user" name="license_user" required>
                                <option value=""><?php _e('-- Select User --', 'wc-product-license'); ?></option>
                                <?php foreach ($users as $user) : ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_key"><?php _e('License Key', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="text" id="license_key" name="license_key" class="regular-text" />
                            <p class="description"><?php _e('Leave blank to generate automatically', 'wc-product-license'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_sites"><?php _e('Sites Allowed', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="number" id="license_sites" name="license_sites" value="1" min="1" class="small-text" required />
                            <p>
                                <label>
                                    <input type="checkbox" class="wc-license-manual-unlimited-toggle" name="license_unlimited_sites" value="1" data-target="#license_sites" />
                                    <?php _e('Unlimited site activations', 'wc-product-license'); ?>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_validity"><?php _e('Validity (days)', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="number" id="license_validity" name="license_validity" value="365" min="1" class="small-text" required />
                            <p class="description"><?php _e('Number of days this license will be valid for', 'wc-product-license'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_status"><?php _e('Status', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_status" name="license_status">
                                <option value="active"><?php _e('Active', 'wc-product-license'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'wc-product-license'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit_license" class="button button-primary" value="<?php _e('Add License', 'wc-product-license'); ?>" />
                </p>
            </form>
        </div>
    <?php
    }

    /**
     * Handle add license form submission
     */
    private function handle_add_license_form()
    {
        // Get form data
        $product_id = absint($_POST['license_product']);
        $user_id = absint($_POST['license_user']);
        $license_key = !empty($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : $this->generate_unique_license_key();
        $is_unlimited_sites = isset($_POST['license_unlimited_sites']);
        $sites_allowed = wc_product_license_normalize_sites_allowed(isset($_POST['license_sites']) ? wp_unslash($_POST['license_sites']) : 1, $is_unlimited_sites);
        $validity = absint($_POST['license_validity']);
        $status = sanitize_text_field($_POST['license_status']);

        // Create license data
        $license_data = [
            'key' => $license_key,
            'product_id' => $product_id,
            'order_id' => 0, // Manual creation, no order
            'user_id' => $user_id,
            'status' => $status,
            'sites_allowed' => $sites_allowed,
            'sites_active' => 0,
            'activation_limit' => $sites_allowed,
            'expires_at' => $validity > 0 ? date('Y-m-d H:i:s', strtotime('+' . $validity . ' days')) : null,
            'purchased_at' => current_time('mysql'),
            'purchased_price' => 0.00, // Manual creation, no price
            'active_sites' => []
        ];

        // Store license in database
        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');

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

        $license_id = (int) $wpdb->insert_id;
        if ($license_id > 0) {
            wc_product_license_log_event(
                [
                    'id' => $license_id,
                    'license_key' => $license_data['key'],
                ],
                'license_created',
                __('License created manually from the admin area.', 'wc-product-license'),
                [
                    'source' => 'admin',
                    'actor_id' => get_current_user_id(),
                    'details' => [
                        'product_id' => $product_id,
                        'user_id' => $user_id,
                        'sites_allowed' => $sites_allowed,
                        'expires_at' => (string) $license_data['expires_at'],
                    ],
                ]
            );
        }

        // Redirect to the licenses page
        wp_redirect(admin_url('admin.php?page=wc-license-keys'));
        exit;
    }
    /**
     * Generate a unique license key
     */
    private function generate_unique_license_key()
    {
        $key = strtoupper(wp_generate_password(20, false, false));

        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        // Ensure the key is unique
        $existing_key = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE license_key = %s", $key));

        if ($existing_key > 0) {
            return $this->generate_unique_license_key(); // Recursively generate a new key
        }

        return $key;
    }
    /**
     * Render the edit license page
     */
    public function render_edit_license_page($license_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        // Get license data
        $license = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $license_id));

        if (!$license) {
            wp_die(__('License not found.', 'wc-product-license'));
        }

        // Handle form submission
        if (isset($_POST['submit_license']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'edit_license_' . $license_id)) {
            $this->handle_edit_license_form($license_id);

            // Refresh license data
            $license = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $license_id));
        }

        // Get products for dropdown
        $products = wc_get_products([
            'limit' => -1,
            'type' => 'simple',
            'downloadable' => true,
            'meta_key' => '_is_license_product',
            'meta_value' => 'yes'
        ]);

        // Get users for dropdown
        $users = get_users(['fields' => ['ID', 'user_email', 'display_name']]);

        // Get active sites
        $active_sites = maybe_unserialize($license->active_sites) ?: [];

    ?>
        <div class="wrap">
            <h1><?php _e('Edit License', 'wc-product-license'); ?></h1>

            <?php settings_errors('wc_license_messages'); ?>

            <form method="post" action="">
                <?php wp_nonce_field('edit_license_' . $license_id); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="license_key"><?php _e('License Key', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="text" id="license_key" name="license_key" value="<?php echo esc_attr($license->license_key); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_product"><?php _e('Product', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_product" name="license_product" required>
                                <?php foreach ($products as $product) : ?>
                                    <option value="<?php echo esc_attr($product->get_id()); ?>" <?php selected($license->product_id, $product->get_id()); ?>><?php echo esc_html($product->get_name()); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_user"><?php _e('User', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_user" name="license_user" required>
                                <?php foreach ($users as $user) : ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($license->user_id, $user->ID); ?>><?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_status"><?php _e('Status', 'wc-product-license'); ?></label></th>
                        <td>
                            <select id="license_status" name="license_status">
                                <option value="active" <?php selected($license->status, 'active'); ?>><?php _e('Active', 'wc-product-license'); ?></option>
                                <option value="inactive" <?php selected($license->status, 'inactive'); ?>><?php _e('Inactive', 'wc-product-license'); ?></option>
                                <option value="expired" <?php selected($license->status, 'expired'); ?>><?php _e('Expired', 'wc-product-license'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_sites"><?php _e('Sites Allowed', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="number" id="license_sites" name="license_sites" value="<?php echo esc_attr(wc_product_license_is_unlimited_sites($license->sites_allowed) ? 1 : $license->sites_allowed); ?>" min="1" class="small-text" required />
                            <p>
                                <label>
                                    <input type="checkbox" class="wc-license-manual-unlimited-toggle" name="license_unlimited_sites" value="1" data-target="#license_sites" <?php checked(wc_product_license_is_unlimited_sites($license->sites_allowed)); ?> />
                                    <?php _e('Unlimited site activations', 'wc-product-license'); ?>
                                </label>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="license_expires"><?php _e('Expiration Date', 'wc-product-license'); ?></label></th>
                        <td>
                            <input type="date" id="license_expires" name="license_expires" value="<?php echo esc_attr(substr($license->expires_at, 0, 10)); ?>" class="regular-text" />
                            <p class="description"><?php _e('Leave blank for no expiration', 'wc-product-license'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Active Sites', 'wc-product-license'); ?></th>
                        <td>
                            <?php if (!empty($active_sites)) : ?>
                                <table class="widefat striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Site', 'wc-product-license'); ?></th>
                                            <th><?php _e('Activated On', 'wc-product-license'); ?></th>
                                            <th><?php _e('Actions', 'wc-product-license'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($active_sites as $site_url => $activation_date) : ?>
                                            <tr>
                                                <td><?php echo esc_html($site_url); ?></td>
                                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($activation_date))); ?></td>
                                                <td>
                                                    <button type="button" class="button deactivate-site" data-license="<?php echo esc_attr($license->license_key); ?>" data-site="<?php echo esc_attr($site_url); ?>"><?php _e('Deactivate', 'wc-product-license'); ?></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else : ?>
                                <p><?php _e('No active sites.', 'wc-product-license'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit_license" class="button button-primary" value="<?php _e('Update License', 'wc-product-license'); ?>" />
                </p>
            </form>
        </div>
    <?php
    }

    /**
     * Handle edit license form submission
     */
    private function handle_edit_license_form($license_id)
    {
        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');
        $existing_license = $this->get_license_record($license_id);

        if (!$existing_license) {
            add_settings_error(
                'wc_license_messages',
                'license_missing',
                __('License not found.', 'wc-product-license'),
                'error'
            );
            return;
        }

        // Get form data
        $license_key = sanitize_text_field($_POST['license_key']);
        $product_id = absint($_POST['license_product']);
        $user_id = absint($_POST['license_user']);
        $status = sanitize_text_field($_POST['license_status']);
        $is_unlimited_sites = isset($_POST['license_unlimited_sites']);
        $sites_allowed = wc_product_license_normalize_sites_allowed(isset($_POST['license_sites']) ? wp_unslash($_POST['license_sites']) : 1, $is_unlimited_sites);
        $expires_at = !empty($_POST['license_expires']) ? sanitize_text_field($_POST['license_expires']) . ' 23:59:59' : null;
        $order_id = isset($_POST['license_order']) ? absint($_POST['license_order']) : 0;
        $purchased_price = isset($_POST['license_price']) ? wc_format_decimal(wp_unslash($_POST['license_price'])) : 0;

        $existing_key_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE license_key = %s AND id != %d LIMIT 1",
            $license_key,
            $license_id
        ));

        if ($existing_key_id) {
            add_settings_error(
                'wc_license_messages',
                'license_duplicate_key',
                __('That license key is already in use. Please enter a unique key.', 'wc-product-license'),
                'error'
            );
            return;
        }

        // Update license in database
        $result = $wpdb->update(
            $table_name,
            [
                'license_key' => $license_key,
                'product_id' => $product_id,
                'order_id' => $order_id,
                'user_id' => $user_id,
                'status' => $status,
                'sites_allowed' => $sites_allowed,
                'expires_at' => $expires_at,
                'purchased_price' => $purchased_price,
            ],
            ['id' => $license_id]
        );

        if ($result !== false) {
            wc_product_license_sync_activation_records_with_license([
                'id' => $license_id,
                'license_key' => $license_key,
                'product_id' => $product_id,
                'order_id' => $order_id,
                'user_id' => $user_id,
            ]);

            $changed_fields = [];
            if ((string) $existing_license->license_key !== $license_key) {
                $changed_fields[] = __('license key', 'wc-product-license');
            }
            if ((int) $existing_license->product_id !== $product_id) {
                $changed_fields[] = __('product', 'wc-product-license');
            }
            if ((int) $existing_license->user_id !== $user_id) {
                $changed_fields[] = __('customer', 'wc-product-license');
            }
            if ((string) $existing_license->status !== $status) {
                $changed_fields[] = __('status', 'wc-product-license');
            }
            if ((int) $existing_license->sites_allowed !== (int) $sites_allowed) {
                $changed_fields[] = __('site activations', 'wc-product-license');
            }
            if ((string) $existing_license->expires_at !== (string) $expires_at) {
                $changed_fields[] = __('expiration', 'wc-product-license');
            }
            if ((int) $existing_license->order_id !== $order_id) {
                $changed_fields[] = __('order link', 'wc-product-license');
            }
            if ((float) $existing_license->purchased_price !== (float) $purchased_price) {
                $changed_fields[] = __('purchase price', 'wc-product-license');
            }

            if (!empty($changed_fields)) {
                wc_product_license_log_event(
                    [
                        'id' => $license_id,
                        'license_key' => $license_key,
                    ],
                    'license_updated',
                    sprintf(__('License details updated: %s.', 'wc-product-license'), implode(', ', $changed_fields)),
                    [
                        'source' => 'admin',
                        'actor_id' => get_current_user_id(),
                        'details' => [
                            'changed_fields' => $changed_fields,
                            'previous_status' => (string) $existing_license->status,
                            'new_status' => $status,
                            'previous_expires_at' => (string) $existing_license->expires_at,
                            'new_expires_at' => (string) $expires_at,
                        ],
                    ]
                );
            }
        }

        // Show success message
        add_settings_error(
            'wc_license_messages',
            'license_updated',
            __('License updated successfully.', 'wc-product-license'),
            'updated'
        );
    }

    /**
     * Register bulk actions for the licenses list table
     */
    public function register_bulk_actions($actions)
    {
        $actions['activate'] = __('Activate', 'wc-product-license');
        $actions['deactivate'] = __('Deactivate', 'wc-product-license');
        $actions['delete'] = __('Delete', 'wc-product-license');
        return $actions;
    }

    /**
     * Handle bulk actions for the licenses list table
     */
    public function handle_bulk_actions($redirect_to, $action, $license_ids)
    {
        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');

        if ($action === 'activate') {
            foreach ($license_ids as $license_id) {
                $license = $this->get_license_record($license_id);
                $wpdb->update(
                    $table_name,
                    ['status' => 'active'],
                    ['id' => $license_id]
                );
                if ($license && (string) $license->status !== 'active') {
                    wc_product_license_log_event(
                        $license,
                        'status_changed',
                        __('License enabled from the bulk actions menu.', 'wc-product-license'),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'details' => [
                                'previous_status' => (string) $license->status,
                                'new_status' => 'active',
                            ],
                        ]
                    );
                }
            }
            $redirect_to = add_query_arg('bulk_activated', count($license_ids), $redirect_to);
        } elseif ($action === 'deactivate') {
            foreach ($license_ids as $license_id) {
                $license = $this->get_license_record($license_id);
                $wpdb->update(
                    $table_name,
                    ['status' => 'inactive'],
                    ['id' => $license_id]
                );
                if ($license && (string) $license->status !== 'inactive') {
                    wc_product_license_log_event(
                        $license,
                        'status_changed',
                        __('License disabled from the bulk actions menu.', 'wc-product-license'),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'details' => [
                                'previous_status' => (string) $license->status,
                                'new_status' => 'inactive',
                            ],
                        ]
                    );
                }
            }
            $redirect_to = add_query_arg('bulk_deactivated', count($license_ids), $redirect_to);
        } elseif ($action === 'delete') {
            foreach ($license_ids as $license_id) {
                $license = $this->get_license_record($license_id);
                if ($license) {
                    wc_product_license_log_event(
                        $license,
                        'license_deleted',
                        __('License deleted from the bulk actions menu.', 'wc-product-license'),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                        ]
                    );
                    wc_product_license_delete_activation_records($license);
                }
                $wpdb->delete(
                    $table_name,
                    ['id' => $license_id]
                );
            }
            $redirect_to = add_query_arg('bulk_deleted', count($license_ids), $redirect_to);
        }

        return $redirect_to;
    }

    /**
     * AJAX handler for activating a license
     */
    public function ajax_activate_license()
    {
        check_ajax_referer('wc-license-admin-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'wc-product-license'));
        }

        $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;

        if (!$license_id) {
            wp_send_json_error(__('Invalid license ID.', 'wc-product-license'));
        }

        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');
        $license = $this->get_license_record($license_id);

        $result = $wpdb->update(
            $table_name,
            ['status' => 'active'],
            ['id' => $license_id]
        );

        if ($result !== false) {
            if ($license && (string) $license->status !== 'active') {
                wc_product_license_log_event(
                    $license,
                    'status_changed',
                    __('License enabled from the admin table.', 'wc-product-license'),
                    [
                        'source' => 'admin',
                        'actor_id' => get_current_user_id(),
                        'details' => [
                            'previous_status' => (string) $license->status,
                            'new_status' => 'active',
                        ],
                    ]
                );
            }
            wp_send_json_success([
                'message' => __('License activated successfully.', 'wc-product-license')
            ]);
        } else {
            wp_send_json_error(__('Failed to activate license.', 'wc-product-license'));
        }
    }

    /**
     * AJAX handler for deactivating a license
     */
    public function ajax_deactivate_license()
    {
        check_ajax_referer('wc-license-admin-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'wc-product-license'));
        }

        $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;
        $site_url = isset($_POST['site_url']) ? esc_url_raw($_POST['site_url']) : '';

        if (!$license_id) {
            wp_send_json_error(__('Invalid license ID.', 'wc-product-license'));
        }

        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');

        // If deactivating specific site
        if (!empty($site_url)) {
            $license = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $license_id));
            if (!$license) {
                wp_send_json_error(__('License not found.', 'wc-product-license'));
            }

            $active_sites = maybe_unserialize($license->active_sites) ?: [];
            if (isset($active_sites[$site_url])) {
                unset($active_sites[$site_url]);

                $result = $wpdb->update(
                    $table_name,
                    [
                        'sites_active' => count($active_sites),
                        'active_sites' => maybe_serialize($active_sites)
                    ],
                    ['id' => $license_id]
                );

                if ($result !== false) {
                    wc_product_license_mark_activation_inactive($license, $site_url, [
                        'timestamp' => current_time('mysql'),
                    ]);
                    wc_product_license_log_event(
                        $license,
                        'site_deactivated',
                        sprintf(__('Site deactivated from the admin table: %s', 'wc-product-license'), $site_url),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'site_url' => $site_url,
                        ]
                    );
                    wp_send_json_success([
                        'message' => __('Site deactivated successfully.', 'wc-product-license')
                    ]);
                } else {
                    wp_send_json_error(__('Failed to deactivate site.', 'wc-product-license'));
                }
            } else {
                wp_send_json_error(__('Site not found for this license.', 'wc-product-license'));
            }
        } else {
            // Deactivate entire license
            $license = $this->get_license_record($license_id);
            $result = $wpdb->update(
                $table_name,
                ['status' => 'inactive'],
                ['id' => $license_id]
            );

            if ($result !== false) {
                if ($license && (string) $license->status !== 'inactive') {
                    wc_product_license_log_event(
                        $license,
                        'status_changed',
                        __('License disabled from the admin table.', 'wc-product-license'),
                        [
                            'source' => 'admin',
                            'actor_id' => get_current_user_id(),
                            'details' => [
                                'previous_status' => (string) $license->status,
                                'new_status' => 'inactive',
                            ],
                        ]
                    );
                }
                wp_send_json_success([
                    'message' => __('License deactivated successfully.', 'wc-product-license')
                ]);
            } else {
                wp_send_json_error(__('Failed to deactivate license.', 'wc-product-license'));
            }
        }
    }

    /**
     * AJAX handler for deleting a license
     */
    public function ajax_delete_license()
    {
        check_ajax_referer('wc-license-admin-nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied.', 'wc-product-license'));
        }

        $license_id = isset($_POST['license_id']) ? absint($_POST['license_id']) : 0;

        if (!$license_id) {
            wp_send_json_error(__('Invalid license ID.', 'wc-product-license'));
        }

        global $wpdb;
        $table_name = wc_product_license_get_table_name('licenses');
        $license = $this->get_license_record($license_id);

        if ($license) {
            wc_product_license_log_event(
                $license,
                'license_deleted',
                __('License deleted from the admin table.', 'wc-product-license'),
                [
                    'source' => 'admin',
                    'actor_id' => get_current_user_id(),
                ]
            );
            wc_product_license_delete_activation_records($license);
        }

        $result = $wpdb->delete(
            $table_name,
            ['id' => $license_id]
        );

        if ($result !== false) {
            wp_send_json_success([
                'message' => __('License deleted successfully.', 'wc-product-license')
            ]);
        } else {
            wp_send_json_error(__('Failed to delete license.', 'wc-product-license'));
        }
    }

    /** 
     * Render the settings page 
     */
    public function render_settings_page()
    {
        // Check if settings were saved
        $settings_saved = false;
        if (isset($_POST['submit_settings']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'wc_license_settings')) {
            $this->save_settings();
            $settings_saved = true;
        }

        // Handle license activation/deactivation
        $license_status = '';
        $license_message = '';

        if (isset($_POST['wc_license_activate']) && isset($_POST['wc_license_key']) && wp_verify_nonce($_POST['_wpnonce'], 'wc_license_settings')) {
            $license_key = sanitize_text_field($_POST['wc_license_key']);
            $site_url = preg_replace('/^(https?:\/\/)?(www\.)?/', '', get_site_url());

            // Activation request
            $response = wp_remote_post(
                add_query_arg(
                    array('site_url' => $site_url),
                    "https://wppluginzone.com/wp-json/wc-license-manager/v1/license/{$license_key}/activate"
                ),
                array(
                    'timeout' => 30,
                    'sslverify' => false,
                )
            );

            if (is_wp_error($response)) {
                $license_status = 'error';
                $license_message = $response->get_error_message();
            } else {
                $result = json_decode(wp_remote_retrieve_body($response), true);

                error_log(print_r($response, true)); // Debugging line

                if (!empty($result['success']) && $result['success'] === true) {
                    update_option('wc_product_license_key', $license_key);
                    update_option('wc_product_license_status', 'active');
                    update_option('wc_product_license_expiry', $result['expiry_date'] ?? null);
                    $license_status = 'success';
                    $license_message = __('License activated successfully!', 'wc-product-license');
                } else {
                    $license_status = 'error';
                    $license_message = !empty($result['message']) ? $result['message'] : __('License activation failed. Please try again.', 'wc-product-license');
                }
            }
        } elseif (isset($_POST['wc_license_deactivate']) && wp_verify_nonce($_POST['_wpnonce'], 'wc_license_settings')) {
            $license_key = get_option('wc_product_license_key', '');
            if (!empty($license_key)) {
                $site_url = preg_replace('/^(https?:\/\/)?(www\.)?/', '', get_site_url());

                // Deactivation request
                $response = wp_remote_post(
                    add_query_arg(
                        array('site_url' => $site_url),
                        "https://wppluginzone.com/wp-json/wc-license-manager/v1/license/{$license_key}/deactivate"
                    ),
                    array(
                        'timeout' => 30,
                        'sslverify' => false,
                    )
                );

                if (is_wp_error($response)) {
                    $license_status = 'error';
                    $license_message = $response->get_error_message();
                } else {
                    $result = json_decode(wp_remote_retrieve_body($response), true);

                    if (!empty($result['success']) && $result['success'] === true) {
                        update_option('wc_product_license_status', 'inactive');
                        $license_status = 'success';
                        $license_message = __('License deactivated successfully!', 'wc-product-license');
                    } else {
                        update_option('wc_product_license_status', 'inactive');
                        $license_status = 'success';
                        $license_message = __('License deactivated successfully!', 'wc-product-license');
                    }
                }
            } else {
                $license_status = 'error';
                $license_message = __('No license key found to deactivate.', 'wc-product-license');
            }
        }

        // Get current license status
        $current_license_key = get_option('wc_product_license_key', '');
        $current_license_status = get_option('wc_product_license_status', 'inactive');

        // Get current settings
        $settings = get_option('wc_product_license_settings', [
            'license_key_prefix' => '',
            'license_key_length' => 16,
            'license_renewal_discount' => 0,
            'license_expiry_notification' => 7,
            'email_templates' => [
                'purchase' => [
                    'subject' => __('Your License Key for {product_name}', 'wc-product-license'),
                    'content' => __("Hello {customer_name},\n\nThank you for your purchase. Your license key for {product_name} is: {license_key}\n\nYou can manage your licenses from your account page.\n\nRegards,\n{site_name}", 'wc-product-license')
                ],
                'expiry_reminder' => [
                    'subject' => __('Your License for {product_name} is About to Expire', 'wc-product-license'),
                    'content' => __("Hello {customer_name},\n\nYour license key for {product_name} will expire on {expiry_date}.\n\nTo continue using the product, please renew your license.\n\nRegards,\n{site_name}", 'wc-product-license')
                ],
                'expired' => [
                    'subject' => __('Your License for {product_name} Has Expired', 'wc-product-license'),
                    'content' => __("Hello {customer_name},\n\nYour license key for {product_name} expired on {expiry_date}.\n\nTo continue using the product, please renew your license.\n\nRegards,\n{site_name}", 'wc-product-license')
                ]
            ],
            'api_settings' => [
                'enable_api' => 'yes',
                'require_https' => 'yes',
                'throttle_limit' => 10,
                'debug_mode' => 'no'
            ]
        ]);
    ?>
        <div class="wrap">
            <h1><?php _e('License Settings', 'wc-product-license'); ?></h1>
            <?php if ($settings_saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Settings saved successfully.', 'wc-product-license'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($license_message)): ?>
                <div class="notice notice-<?php echo $license_status === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($license_message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('wc_license_settings'); ?>
                <div class="wc-license-settings-tabs">
                    <div class="nav-tab-wrapper">
                        <a href="#general-settings" class="nav-tab nav-tab-active"><?php _e('General', 'wc-product-license'); ?></a>
                        <a href="#email-templates" class="nav-tab"><?php _e('Email Templates', 'wc-product-license'); ?></a>
                        <a href="#api-settings" class="nav-tab"><?php _e('API Settings', 'wc-product-license'); ?></a>
                        <a href="#license-activation" class="nav-tab"><?php _e('License Activation', 'wc-product-license'); ?></a>
                    </div>

                    <!-- License Activation -->
                    <div id="license-activation" class="tab-content">
                        <div class="wc-license-activation-container">
                            <div class="wc-license-status-box">
                                <h2><?php _e('License Status', 'wc-product-license'); ?></h2>

                                <?php if ($current_license_status === 'active'): ?>
                                    <div class="license-status-active">
                                        <span class="dashicons dashicons-yes"></span>
                                        <p><?php _e('Your license is active', 'wc-product-license'); ?></p>
                                    </div>
                                    <p class="license-key-display">
                                        <?php echo sprintf(__('License Key: %s', 'wc-product-license'), esc_html($current_license_key)); ?>
                                    </p>
                                    <p>
                                        <input type="submit" name="wc_license_deactivate" class="button button-secondary" value="<?php _e('Deactivate License', 'wc-product-license'); ?>" />
                                    </p>
                                <?php else: ?>
                                    <div class="license-status-inactive">
                                        <span class="dashicons dashicons-warning"></span>
                                        <p><?php _e('Your license is not active', 'wc-product-license'); ?></p>
                                    </div>
                                    <p class="license-info">
                                        <?php _e('Please enter your license key below to activate the pro features.', 'wc-product-license'); ?>
                                    </p>
                                    <p>
                                        <label for="wc_license_key"><?php _e('License Key', 'wc-product-license'); ?></label>
                                        <input type="text" id="wc_license_key" name="wc_license_key" value="<?php echo esc_attr($current_license_key); ?>" class="regular-text" placeholder="<?php _e('Enter your license key', 'wc-product-license'); ?>" required />
                                    </p>
                                    <p>
                                        <input type="submit" name="wc_license_activate" class="button button-primary" value="<?php _e('Activate License', 'wc-product-license'); ?>" />
                                    </p>
                                <?php endif; ?>
                            </div>

                            <?php $this->render_license_information(); ?>
                        </div>
                    </div>

                    <!-- General Settings -->
                    <div id="general-settings" class="tab-content active">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="license_key_prefix"><?php _e('License Key Prefix', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="license_key_prefix" name="settings[license_key_prefix]" value="<?php echo esc_attr($settings['license_key_prefix']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Optional prefix for generated license keys.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_key_length"><?php _e('License Key Length', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_key_length" name="settings[license_key_length]" value="<?php echo esc_attr($settings['license_key_length']); ?>" min="8" max="32" class="small-text">
                                    <p class="description"><?php _e('Length of generated license keys (excluding prefix).', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_renewal_discount"><?php _e('Renewal Discount (%)', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_renewal_discount" name="settings[license_renewal_discount]" value="<?php echo esc_attr($settings['license_renewal_discount']); ?>" min="0" max="100" class="small-text">
                                    <p class="description"><?php _e('Discount percentage for license renewals.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_expiry_notification"><?php _e('Expiry Notification (days)', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_expiry_notification" name="settings[license_expiry_notification]" value="<?php echo esc_attr($settings['license_expiry_notification']); ?>" min="1" max="90" class="small-text">
                                    <p class="description"><?php _e('Days before expiration to send notification emails.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Email Templates -->
                    <div id="email-templates" class="tab-content">
                        <!-- Email template content remains the same -->
                        <h2><?php _e('Purchase Email', 'wc-product-license'); ?></h2>
                        <p><?php _e('Email sent to customers after purchasing a licensed product.', 'wc-product-license'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="email_purchase_subject"><?php _e('Subject', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="email_purchase_subject" name="settings[email_templates][purchase][subject]" value="<?php echo esc_attr($settings['email_templates']['purchase']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_purchase_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_purchase_content" name="settings[email_templates][purchase][content]" rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['purchase']['content']); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available variables: {customer_name}, {product_name}, {license_key}, {expiry_date}, {site_name}', 'wc-product-license'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <h2><?php _e('Expiry Reminder Email', 'wc-product-license'); ?></h2>
                        <p><?php _e('Email sent to customers before their license expires.', 'wc-product-license'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="email_expiry_reminder_subject"><?php _e('Subject', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="email_expiry_reminder_subject" name="settings[email_templates][expiry_reminder][subject]" value="<?php echo esc_attr($settings['email_templates']['expiry_reminder']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_expiry_reminder_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_expiry_reminder_content" name="settings[email_templates][expiry_reminder][content]" rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['expiry_reminder']['content']); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available variables: {customer_name}, {product_name}, {license_key}, {expiry_date}, {site_name}', 'wc-product-license'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <h2><?php _e('Expired License Email', 'wc-product-license'); ?></h2>
                        <p><?php _e('Email sent to customers after their license expires.', 'wc-product-license'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="email_expired_subject"><?php _e('Subject', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="email_expired_subject" name="settings[email_templates][expired][subject]" value="<?php echo esc_attr($settings['email_templates']['expired']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_expired_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_expired_content" name="settings[email_templates][expired][content]" rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['expired']['content']); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available variables: {customer_name}, {product_name}, {license_key}, {expiry_date}, {site_name}', 'wc-product-license'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- API Settings -->
                    <div id="api-settings" class="tab-content">
                        <!-- API settings content remains the same -->
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="enable_api"><?php _e('Enable API', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="enable_api" name="settings[api_settings][enable_api]" value="yes" <?php checked($settings['api_settings']['enable_api'], 'yes'); ?>>
                                        <?php _e('Enable license verification API', 'wc-product-license'); ?>
                                    </label>
                                    <p class="description"><?php _e('Allow products to verify licenses via the API.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="require_https"><?php _e('Require HTTPS', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="require_https" name="settings[api_settings][require_https]" value="yes" <?php checked($settings['api_settings']['require_https'], 'yes'); ?>>
                                        <?php _e('Require secure connections for API requests', 'wc-product-license'); ?>
                                    </label>
                                    <p class="description"><?php _e('Only allow API requests over HTTPS connections.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="throttle_limit"><?php _e('Request Limit', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="throttle_limit" name="settings[api_settings][throttle_limit]" value="<?php echo esc_attr($settings['api_settings']['throttle_limit']); ?>" min="1" max="100" class="small-text">
                                    <p class="description"><?php _e('Maximum API requests allowed per minute per IP address.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="debug_mode"><?php _e('Debug Mode', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="debug_mode" name="settings[api_settings][debug_mode]" value="yes" <?php checked($settings['api_settings']['debug_mode'], 'yes'); ?>>
                                        <?php _e('Enable debug logging', 'wc-product-license'); ?>
                                    </label>
                                    <p class="description"><?php _e('Log API requests and responses for troubleshooting.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <h2><?php _e('API Documentation', 'wc-product-license'); ?></h2>
                        <div class="wc-license-api-docs">
                            <p><?php _e('Use the following endpoints to verify and manage licenses programmatically:', 'wc-product-license'); ?></p>
                            <h4><?php _e('Get License info', 'wc-product-license'); ?></h4>
                            <code>GET <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}'); ?></code>
                            <p><?php _e('Parameters: license_key. The response includes package data, release metadata, and expanded site_records telemetry for every known install on that license.', 'wc-product-license'); ?></p>
                            <h4><?php _e('Activate License', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}/activate'); ?></code>
                            <p><?php _e('Parameters: site_url plus optional telemetry such as site_name, product_name, multisite, wordpress_version, php_version, mysql_version, server_software, server_os, active_theme, active_theme_version, plugin_version, installed_plugins, environment_type, locale, wordpress_locale, timezone, admin_email, site_icon, site_description, site_scope, site_owner, site_owner_type, license_channel, and site_meta. Responses now include the normalized site_record payload.', 'wc-product-license'); ?></p>
                            <h4><?php _e('Deactivate License', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}/deactivate'); ?></code>
                            <p><?php _e('Parameters: site_url plus the same optional telemetry fields and deactivation_reason / deactivation_note. Responses now include the archived site_record payload.', 'wc-product-license'); ?></p>
                            <h4><?php _e('Store User info & Activity', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/tracking/activate'); ?></code>
                            <p><?php _e('Parameters: site_url, activation_status, optional license_key or license_id, and the same telemetry payload used by the activation endpoint.', 'wc-product-license'); ?></p>
                            <h4><?php _e('Store Feed back on uninstall/remove', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/tracking/deactivate'); ?></code>
                            <p><?php _e('Parameters: site_url, activation_status=no, optional license_key or license_id, deactivation_reason, deactivation_note, and any telemetry fields you want to preserve on the archived site record. The API returns site_record_id plus the expanded site_record object.', 'wc-product-license'); ?></p>
                        </div>
                    </div>
                </div>
                <p class="submit">
                    <input type="submit" name="submit_settings" class="button button-primary" value="<?php _e('Save Settings', 'wc-product-license'); ?>">
                </p>
            </form>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Tab navigation
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
            });
        </script>
        <style type="text/css">
            .wc-license-settings-tabs .tab-content {
                display: none;
                padding: 20px 0;
            }

            .wc-license-settings-tabs .tab-content.active {
                display: block;
            }

            .wc-license-api-docs {
                background: #f9f9f9;
                padding: 15px;
                border: 1px solid #e5e5e5;
                border-radius: 3px;
            }

            .wc-license-api-docs code {
                display: block;
                padding: 10px;
                margin: 10px 0;
                background: #f1f1f1;
            }

            .license-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                color: #fff;
                font-size: 12px;
                font-weight: 500;
            }

            .license-active {
                background-color: #7ad03a;
            }

            .license-inactive {
                background-color: #999;
            }

            .license-expired {
                background-color: #a00;
            }

            /* License Activation Tab Styles */
            .wc-license-activation-container {
                background: #fff;
                border: 1px solid #e5e5e5;
                padding: 20px;
                margin-bottom: 20px;
            }

            .wc-license-status-box {
                margin-bottom: 20px;
                padding-bottom: 20px;
                border-bottom: 1px solid #eee;
            }

            .license-status-active {
                display: flex;
                align-items: center;
                color: #46b450;
                font-weight: bold;
            }

            .license-status-inactive {
                display: flex;
                align-items: center;
                color: #dc3232;
                font-weight: bold;
            }

            .license-status-active .dashicons,
            .license-status-inactive .dashicons {
                font-size: 30px;
                margin-right: 10px;
            }

            .license-key-display {
                background: #f9f9f9;
                padding: 10px;
                border-left: 4px solid #46b450;
                margin-bottom: 15px;
            }

            .wc-license-info-box {
                background: #f9f9f9;
                border-left: 4px solid #00a0d2;
                padding: 15px;
                margin-top: 20px;
            }
        </style>
        <?php
    }

    /**
     * Render the license information section
     */
    public function render_license_information()
    {
        $current_license_key = get_option('wc_product_license_key', '');
        $current_license_status = get_option('wc_product_license_status', 'inactive');

        // Only fetch license information if we have an active license
        if ($current_license_status === 'active' && !empty($current_license_key)) {
            // Make API request to get license details
            $response = wp_remote_get(
                "https://wppluginzone.com/wp-json/wc-license-manager/v1/license/{$current_license_key}/",
                array(
                    'timeout' => 30,
                    'sslverify' => false,
                )
            );

            if (!is_wp_error($response)) {
                $license_data = json_decode(wp_remote_retrieve_body($response), true);

                if (!empty($license_data) && isset($license_data['success']) && $license_data['success'] === true) {
                    // License data found, display detailed information
        ?>
                    <div class="wc-license-info">
                        <h3><?php _e('License Information', 'wc-product-license'); ?></h3>
                        <table class="widefat striped">
                            <tr>
                                <th><?php _e('License Key', 'wc-product-license'); ?></th>
                                <td><?php echo esc_html($license_data['license_key']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Status', 'wc-product-license'); ?></th>
                                <td>
                                    <span class="license-status license-<?php echo esc_attr($license_data['status']); ?>">
                                        <?php echo esc_html(ucfirst($license_data['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Product', 'wc-product-license'); ?></th>
                                <td><?php echo esc_html($license_data['product_name']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Sites Allowed', 'wc-product-license'); ?></th>
                                <td><?php echo esc_html($license_data['sites_allowed']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Sites Active', 'wc-product-license'); ?></th>
                                <td><?php echo esc_html($license_data['sites_active']); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Expires', 'wc-product-license'); ?></th>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($license_data['expires_at']))); ?></td>
                            </tr>
                        </table>
                    </div>
                <?php
                } else {
                    // API returned success: false or empty data
                ?>
                    <div class="wc-license-info-error">
                        <p><?php _e('Could not fetch license information. Please try again or contact support.', 'wc-product-license'); ?></p>
                    </div>
                <?php
                }
            } else {
                // API request failed
                ?>
                <div class="wc-license-info-error">
                    <p><?php _e('Failed to connect to the license server. Please try again later.', 'wc-product-license'); ?></p>
                </div>
            <?php
            }
        } else {
            // No active license found
            ?>
            <div class="wc-license-no-license">
                <div class="wc-license-info-box">
                    <h3><?php _e('No Active License', 'wc-product-license'); ?></h3>
                    <p><?php _e('You don\'t have an active license for this plugin.', 'wc-product-license'); ?></p>
                    <p><?php _e('Premium features are not available without a license key.', 'wc-product-license'); ?></p>
                    <p><?php printf(__('Please <a href="%s" target="_blank">purchase a license</a> to unlock all features.', 'wc-product-license'), 'https://raihandevzone.com'); ?></p>
                </div>
            </div>
<?php
        }
    }
    /**
     * Save settings
     */
    private function save_settings()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Sanitize input
        $settings = isset($_POST['settings']) ? $this->sanitize_settings($_POST['settings']) : [];

        // Update option
        update_option('wc_product_license_settings', $settings);
    }

    /**
     * Sanitize settings
     */
    private function sanitize_settings($input)
    {
        $sanitized = [];

        // General settings
        $sanitized['license_key_prefix'] = isset($input['license_key_prefix']) ? sanitize_text_field($input['license_key_prefix']) : '';
        $sanitized['license_key_length'] = isset($input['license_key_length']) ? absint($input['license_key_length']) : 16;
        $sanitized['license_renewal_discount'] = isset($input['license_renewal_discount']) ? absint($input['license_renewal_discount']) : 0;
        $sanitized['license_expiry_notification'] = isset($input['license_expiry_notification']) ? absint($input['license_expiry_notification']) : 7;

        // Email templates
        $sanitized['email_templates'] = [
            'purchase' => [
                'subject' => isset($input['email_templates']['purchase']['subject']) ?
                    sanitize_text_field($input['email_templates']['purchase']['subject']) : '',
                'content' => isset($input['email_templates']['purchase']['content']) ?
                    wp_kses_post($input['email_templates']['purchase']['content']) : ''
            ],
            'expiry_reminder' => [
                'subject' => isset($input['email_templates']['expiry_reminder']['subject']) ?
                    sanitize_text_field($input['email_templates']['expiry_reminder']['subject']) : '',
                'content' => isset($input['email_templates']['expiry_reminder']['content']) ?
                    wp_kses_post($input['email_templates']['expiry_reminder']['content']) : ''
            ],
            'expired' => [
                'subject' => isset($input['email_templates']['expired']['subject']) ?
                    sanitize_text_field($input['email_templates']['expired']['subject']) : '',
                'content' => isset($input['email_templates']['expired']['content']) ?
                    wp_kses_post($input['email_templates']['expired']['content']) : ''
            ]
        ];

        // API settings
        $sanitized['api_settings'] = [
            'enable_api' => isset($input['api_settings']['enable_api']) ? 'yes' : 'no',
            'require_https' => isset($input['api_settings']['require_https']) ? 'yes' : 'no',
            'throttle_limit' => isset($input['api_settings']['throttle_limit']) ? absint($input['api_settings']['throttle_limit']) : 10,
            'debug_mode' => isset($input['api_settings']['debug_mode']) ? 'yes' : 'no'
        ];

        return $sanitized;
    }

    /**
     * Generate unique license key
     */
    // private function generate_unique_license_key()
    // {
    //     global $wpdb;
    //     $table_name = $wpdb->prefix . 'wc_product_licenses';

    //     // Get settings
    //     $settings = get_option('wc_product_license_settings', [
    //         'license_key_prefix' => '',
    //         'license_key_length' => 16
    //     ]);

    //     $prefix = $settings['license_key_prefix'];
    //     $length = max(8, min(32, $settings['license_key_length']));

    //     do {
    //         // Generate random key
    //         $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    //         $characters_length = strlen($characters);
    //         $random_key = '';

    //         for ($i = 0; $i < $length; $i++) {
    //             $random_key .= $characters[rand(0, $characters_length - 1)];
    //         }

    //         // Format with dashes for readability
    //         $chunks = str_split($random_key, 4);
    //         $license_key = $prefix . implode('-', $chunks);

    //         // Check if key already exists
    //         $exists = $wpdb->get_var($wpdb->prepare(
    //             "SELECT COUNT(*) FROM $table_name WHERE license_key = %s",
    //             $license_key
    //         ));
    //     } while ($exists > 0);

    //     return $license_key;
    // }
    /**
     * Render the admin page
     */
}
require_once dirname(__FILE__) . '/analytics.php';
