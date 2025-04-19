<?php

/**
 * Admin functionality for WooCommerce Product License Manager
 */

if (!defined('ABSPATH')) exit;

class WC_Product_License_Admin
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Add admin menu items
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Register settings
        // add_action('admin_init', [$this, 'register_settings']);

        // Add custom actions to the licenses list table
        add_filter('bulk_actions-wc_license_keys', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-wc_license_keys', [$this, 'handle_bulk_actions'], 10, 3);

        // Ajax handlers
        add_action('wp_ajax_wc_license_activate', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_wc_license_deactivate', [$this, 'ajax_deactivate_license']);
        add_action('wp_ajax_wc_license_delete', [$this, 'ajax_delete_license']);
        // add_action('wp_ajax_wc_license_edit', [$this, 'ajax_edit_license']);

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
            __('License Keys', 'wc-product-license'),
            __('License Keys', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-keys',
            [$this, 'render_licenses_page'],
            'dashicons-lock',
            56 // Position after WooCommerce
        );

        // License Keys submenu
        add_submenu_page(
            'wc-license-keys',
            __('License Keys', 'wc-product-license'),
            __('All Licenses', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-keys'
        );

        // Add New License submenu
        add_submenu_page(
            'wc-license-keys',
            __('Add New License', 'wc-product-license'),
            __('Add New', 'wc-product-license'),
            'manage_woocommerce',
            'wc-license-add-new',
            [$this, 'render_add_license_page']
        );

        // Settings submenu
        add_submenu_page(
            'wc-license-keys',
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
        $admin_hooks = [
            'toplevel_page_wc-license-keys',
            'license-keys_page_wc-license-add-new',
            'license-keys_page_wc-license-settings'
        ];

        if (in_array($hook, $admin_hooks)) {
            wp_enqueue_style(
                'wc-license-admin-styles',
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css',
                [],
                '1.0.0'
            );

            wp_enqueue_script(
                'wc-license-admin-scripts',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
                ['jquery'],
                '1.0.0',
                true
            );

            wp_localize_script('wc-license-admin-scripts', 'wcLicenseAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc-license-admin-nonce'),
                'i18n' => [
                    'confirmDelete' => __('Are you sure you want to delete this license?', 'wc-product-license'),
                    'confirmDeactivate' => __('Are you sure you want to deactivate this license?', 'wc-product-license'),
                    'confirmActivate' => __('Are you sure you want to activate this license?', 'wc-product-license'),
                    'processing' => __('Processing...', 'wc-product-license'),
                    'success' => __('Success!', 'wc-product-license'),
                    'error' => __('Error:', 'wc-product-license')
                ]
            ]);
        }
    }

    /**
     * Render the licenses page
     */
    public function render_licenses_page()
    {
        // Check if we're editing a license
        if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['license_id'])) {
            $this->render_edit_license_page((int)$_GET['license_id']);
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

?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('License Keys', 'wc-product-license'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wc-license-add-new'); ?>" class="page-title-action"><?php _e('Add New', 'wc-product-license'); ?></a>

            <form id="licenses-filter" method="get">
                <input type="hidden" name="page" value="wc-license-keys" />
                <?php
                $licenses_table->search_box(__('Search Licenses', 'wc-product-license'), 'license-search');
                $licenses_table->display();
                ?>
            </form>
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
        $sites_allowed = absint($_POST['license_sites']);
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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

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
                            <input type="number" id="license_sites" name="license_sites" value="<?php echo esc_attr($license->sites_allowed); ?>" min="1" class="small-text" required />
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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        // Get form data
        $license_key = sanitize_text_field($_POST['license_key']);
        $product_id = absint($_POST['license_product']);
        $user_id = absint($_POST['license_user']);
        $status = sanitize_text_field($_POST['license_status']);
        $sites_allowed = absint($_POST['license_sites']);
        $expires_at = !empty($_POST['license_expires']) ? sanitize_text_field($_POST['license_expires']) . ' 23:59:59' : null;

        // Update license in database
        $wpdb->update(
            $table_name,
            [
                'license_key' => $license_key,
                'product_id' => $product_id,
                'user_id' => $user_id,
                'status' => $status,
                'sites_allowed' => $sites_allowed,
                'expires_at' => $expires_at
            ],
            ['id' => $license_id]
        );

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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        if ($action === 'activate') {
            foreach ($license_ids as $license_id) {
                $wpdb->update(
                    $table_name,
                    ['status' => 'active'],
                    ['id' => $license_id]
                );
            }
            $redirect_to = add_query_arg('bulk_activated', count($license_ids), $redirect_to);
        } elseif ($action === 'deactivate') {
            foreach ($license_ids as $license_id) {
                $wpdb->update(
                    $table_name,
                    ['status' => 'inactive'],
                    ['id' => $license_id]
                );
            }
            $redirect_to = add_query_arg('bulk_deactivated', count($license_ids), $redirect_to);
        } elseif ($action === 'delete') {
            foreach ($license_ids as $license_id) {
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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        $result = $wpdb->update(
            $table_name,
            ['status' => 'active'],
            ['id' => $license_id]
        );

        if ($result !== false) {
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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

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
            $result = $wpdb->update(
                $table_name,
                ['status' => 'inactive'],
                ['id' => $license_id]
            );

            if ($result !== false) {
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
        $table_name = $wpdb->prefix . 'wc_product_licenses';

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

            <form method="post" action="">
                <?php wp_nonce_field('wc_license_settings'); ?>

                <div class="wc-license-settings-tabs">
                    <div class="nav-tab-wrapper">
                        <a href="#general-settings" class="nav-tab nav-tab-active"><?php _e('General', 'wc-product-license'); ?></a>
                        <a href="#email-templates" class="nav-tab"><?php _e('Email Templates', 'wc-product-license'); ?></a>
                        <a href="#api-settings" class="nav-tab"><?php _e('API Settings', 'wc-product-license'); ?></a>
                    </div>

                    <!-- General Settings -->
                    <div id="general-settings" class="tab-content active">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="license_key_prefix"><?php _e('License Key Prefix', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="license_key_prefix" name="settings[license_key_prefix]"
                                        value="<?php echo esc_attr($settings['license_key_prefix']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Optional prefix for generated license keys.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_key_length"><?php _e('License Key Length', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_key_length" name="settings[license_key_length]"
                                        value="<?php echo esc_attr($settings['license_key_length']); ?>"
                                        min="8" max="32" class="small-text">
                                    <p class="description"><?php _e('Length of generated license keys (excluding prefix).', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_renewal_discount"><?php _e('Renewal Discount (%)', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_renewal_discount" name="settings[license_renewal_discount]"
                                        value="<?php echo esc_attr($settings['license_renewal_discount']); ?>"
                                        min="0" max="100" class="small-text">
                                    <p class="description"><?php _e('Discount percentage for license renewals.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="license_expiry_notification"><?php _e('Expiry Notification (days)', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="license_expiry_notification" name="settings[license_expiry_notification]"
                                        value="<?php echo esc_attr($settings['license_expiry_notification']); ?>"
                                        min="1" max="90" class="small-text">
                                    <p class="description"><?php _e('Days before expiration to send notification emails.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Email Templates -->
                    <div id="email-templates" class="tab-content">
                        <h2><?php _e('Purchase Email', 'wc-product-license'); ?></h2>
                        <p><?php _e('Email sent to customers after purchasing a licensed product.', 'wc-product-license'); ?></p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="email_purchase_subject"><?php _e('Subject', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="email_purchase_subject" name="settings[email_templates][purchase][subject]"
                                        value="<?php echo esc_attr($settings['email_templates']['purchase']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_purchase_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_purchase_content" name="settings[email_templates][purchase][content]"
                                        rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['purchase']['content']); ?></textarea>
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
                                    <input type="text" id="email_expiry_reminder_subject" name="settings[email_templates][expiry_reminder][subject]"
                                        value="<?php echo esc_attr($settings['email_templates']['expiry_reminder']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_expiry_reminder_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_expiry_reminder_content" name="settings[email_templates][expiry_reminder][content]"
                                        rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['expiry_reminder']['content']); ?></textarea>
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
                                    <input type="text" id="email_expired_subject" name="settings[email_templates][expired][subject]"
                                        value="<?php echo esc_attr($settings['email_templates']['expired']['subject']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_expired_content"><?php _e('Content', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <textarea id="email_expired_content" name="settings[email_templates][expired][content]"
                                        rows="10" class="large-text"><?php echo esc_textarea($settings['email_templates']['expired']['content']); ?></textarea>
                                    <p class="description">
                                        <?php _e('Available variables: {customer_name}, {product_name}, {license_key}, {expiry_date}, {site_name}', 'wc-product-license'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- API Settings -->
                    <div id="api-settings" class="tab-content">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="enable_api"><?php _e('Enable API', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="enable_api" name="settings[api_settings][enable_api]"
                                            value="yes" <?php checked($settings['api_settings']['enable_api'], 'yes'); ?>>
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
                                        <input type="checkbox" id="require_https" name="settings[api_settings][require_https]"
                                            value="yes" <?php checked($settings['api_settings']['require_https'], 'yes'); ?>>
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
                                    <input type="number" id="throttle_limit" name="settings[api_settings][throttle_limit]"
                                        value="<?php echo esc_attr($settings['api_settings']['throttle_limit']); ?>"
                                        min="1" max="100" class="small-text">
                                    <p class="description"><?php _e('Maximum API requests allowed per minute per IP address.', 'wc-product-license'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="debug_mode"><?php _e('Debug Mode', 'wc-product-license'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="debug_mode" name="settings[api_settings][debug_mode]"
                                            value="yes" <?php checked($settings['api_settings']['debug_mode'], 'yes'); ?>>
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
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}'); ?></code>
                            <p><?php _e('Parameters: license_key'); ?></p>
                            
                            <h4><?php _e('Verify License', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('wp-json/wc-license/v1/verify'); ?></code>
                            <p><?php _e('Parameters: license_key, product_id, instance, domain', 'wc-product-license'); ?></p>

                            <h4><?php _e('Activate License', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}/activate'); ?></code>
                            <p><?php _e('Parameters: license_key'); ?></p>

                            <h4><?php _e('Deactivate License', 'wc-product-license'); ?></h4>
                            <code>POST <?php echo site_url('/wp-json/wc-license-manager/v1/license/{key}/deactivate'); ?></code>
                            <p><?php _e('Parameters: license_key'); ?></p>
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
        </style>
<?php
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
}
