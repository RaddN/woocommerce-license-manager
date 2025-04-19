<?php
/**
 * Admin functionality for WooCommerce Product License Manager
 */

if (!defined('ABSPATH')) exit;

class WC_Product_License_Admin {
    /**
     * Constructor
     */
    public function __construct() {
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
    public function add_admin_menu() {
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
    public function enqueue_admin_assets($hook) {
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
    public function render_licenses_page() {
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
    public function render_add_license_page() {
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
    private function handle_add_license_form() {
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
    private function generate_unique_license_key() {
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
    public function render_edit_license_page($license_id) {
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
    private function handle_edit_license_form($license_id) {
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
    public function register_bulk_actions($actions) {
        $actions['activate'] = __('Activate', 'wc-product-license');
        $actions['deactivate'] = __('Deactivate', 'wc-product-license');
        $actions['delete'] = __('Delete', 'wc-product-license');
        return $actions;
    }
    
    /**
     * Handle bulk actions for the licenses list table
     */
    public function handle_bulk_actions($redirect_to, $action, $license_ids) {
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
    public function ajax_activate_license() {
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
    public function ajax_deactivate_license() {
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
    public function ajax_delete_license() {
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
}