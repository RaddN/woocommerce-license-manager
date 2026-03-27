<?php

/**
 * License List Table Class
 * 
 * Handles the display of licenses in a WP_List_Table
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class WC_License_List_Table extends WP_List_Table
{
    private function get_user_display_label($user_id)
    {
        $user = get_user_by('id', $user_id);
        if ($user) {
            return $user->display_name;
        }

        return $user_id ? __('Unknown', 'wc-product-license') : __('Guest checkout', 'wc-product-license');
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'license',
            'plural'   => 'licenses',
            'ajax'     => false
        ]);
    }

    /**
     * Get table columns
     */
    public function get_columns()
    {
        $columns = [
            'cb'            => '<input type="checkbox" />',
            'license_key'   => __('License Key', 'wc-product-license'),
            'product'       => __('Product', 'wc-product-license'),
            'user'          => __('Customer', 'wc-product-license'),
            'status'        => __('Status', 'wc-product-license'),
            'sites'         => __('Activations', 'wc-product-license'),
            'expires'       => __('Expires', 'wc-product-license'),
            'purchased'     => __('Purchased', 'wc-product-license'),
            'actions'       => __('Actions', 'wc-product-license')
        ];

        return $columns;
    }

    /**
     * Get sortable columns
     */
    public function get_sortable_columns()
    {
        $sortable_columns = [
            'license_key'   => ['license_key', false],
            'product'       => ['product_id', false],
            'user'          => ['user_id', false],
            'status'        => ['status', false],
            'expires'       => ['expires_at', false],
            'purchased'     => ['purchased_at', false]
        ];

        return $sortable_columns;
    }

    /**
     * Column default
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'license_key':
            case 'product':
            case 'user':
            case 'status':
            case 'sites':
            case 'expires':
            case 'purchased':
                return $item[$column_name];
            default:
                return print_r($item, true); // Show the whole array for troubleshooting purposes
        }
    }

    /**
     * Checkbox column
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="licenses[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * License key column
     */
    public function column_license_key($item)
    {
        $manage_url = admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['id']));
        $extend_url = $manage_url . '#wc-license-renewal-panel';
        $effective_status = $this->get_effective_status($item);
        $status_action = $effective_status === 'active' ? 'deactivate' : 'activate';
        $status_label = $effective_status === 'active'
            ? __('Disable', 'wc-product-license')
            : __('Enable', 'wc-product-license');

        $actions = [
            'manage' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($manage_url),
                __('Manage', 'wc-product-license')
            ),
            'extend' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($extend_url),
                __('Extend', 'wc-product-license')
            ),
            'status' => sprintf(
                '<a href="#" class="%s" data-id="%d">%s</a>',
                esc_attr($status_action . '-license'),
                absint($item['id']),
                esc_html($status_label)
            ),
            'delete' => sprintf(
                '<a href="#" class="delete-license" data-id="%s">%s</a>',
                $item['id'],
                __('Delete', 'wc-product-license')
            ),
        ];

        return sprintf(
            '<strong><a href="%1$s">%2$s</a></strong> %3$s',
            esc_url($manage_url),
            esc_html($item['license_key']),
            $this->row_actions($actions)
        );
    }

    /**
     * Product column
     */
    public function column_product($item)
    {
        $product = wc_get_product($item['product_id']);
        return $product ? esc_html($product->get_name()) : __('Unknown', 'wc-product-license');
    }

    /**
     * User column
     */
    public function column_user($item)
    {
        $context = wc_product_license_get_customer_context_from_license($item);
        $meta = $context['email'] !== '' ? $context['email'] : $context['type_label'];

        return sprintf(
            '<a href="%1$s">%2$s</a><span class="wc-license-detail-meta">%3$s</span>',
            esc_url($context['customer_url']),
            esc_html($context['name']),
            esc_html($meta)
        );
    }

    /**
     * Status column
     */
    public function column_status($item)
    {
        $status_classes = [
            'active'   => 'license-status license-active',
            'inactive' => 'license-status license-inactive',
            'expired'  => 'license-status license-expired'
        ];

        $status_labels = [
            'active'   => __('Active', 'wc-product-license'),
            'inactive' => __('Inactive', 'wc-product-license'),
            'expired'  => __('Expired', 'wc-product-license')
        ];

        $status = $this->get_effective_status($item);
        $class = isset($status_classes[$status]) ? $status_classes[$status] : '';
        $label = isset($status_labels[$status]) ? $status_labels[$status] : $status;

        return sprintf('<span class="%s">%s</span>', $class, $label);
    }

    /**
     * Sites column
     */
    public function column_sites($item)
    {
        return wc_product_license_get_activation_usage_text($item['sites_active'], $item['sites_allowed']);
    }

    /**
     * Expires column
     */
    public function column_expires($item)
    {
        if (empty($item['expires_at']) || $item['expires_at'] === '0000-00-00 00:00:00') {
            return __('Never', 'wc-product-license');
        }

        $expires = strtotime($item['expires_at']);
        $now = current_time('timestamp');

        if ($expires < $now) {
            return sprintf(
                '<span class="license-expired">%s</span>',
                date_i18n(get_option('date_format'), $expires)
            );
        } else {
            $days_left = ceil(($expires - $now) / DAY_IN_SECONDS);
            return sprintf(
                '%s <span class="days-left">(%s)</span>',
                date_i18n(get_option('date_format'), $expires),
                sprintf(_n('%d day left', '%d days left', $days_left, 'wc-product-license'), $days_left)
            );
        }
    }

    /**
     * Purchased column
     */
    public function column_purchased($item)
    {
        if (empty($item['purchased_at']) || $item['purchased_at'] === '0000-00-00 00:00:00') {
            return __('N/A', 'wc-product-license');
        }

        $order_link = '';
        if (!empty($item['order_id'])) {
            $order_link = sprintf(
                ' (<a href="%s">#%s</a>)',
                admin_url('post.php?post=' . absint($item['order_id']) . '&action=edit'),
                $item['order_id']
            );
        }

        return date_i18n(get_option('date_format'), strtotime($item['purchased_at'])) . $order_link;
    }
    /**
     * Render the actions column
     *
     * @param array $item The current item
     * @return string The column output
     */
    public function column_actions($item)
    {
        $actions = '<div class="license-actions">';
        $actions .= sprintf(
            '<a href="%s" class="button button-secondary">%s</a>',
            esc_url(admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['id']))),
            esc_html__('Manage', 'wc-product-license')
        );

        // Status-dependent buttons
        if ($this->get_effective_status($item) === 'active') {
            $actions .= sprintf(
                '<a href="#" class="button deactivate-license" data-id="%d">%s</a>',
                $item['id'],
                __('Disable', 'wc-product-license')
            );
        } else {
            $actions .= sprintf(
                '<a href="#" class="button activate-license" data-id="%d">%s</a>',
                $item['id'],
                __('Enable', 'wc-product-license')
            );
        }

        $actions .= '</div>';

        return $actions;
    }
    /**
     * Get bulk actions
     */
    public function get_bulk_actions()
    {
        $actions = [
            'activate'   => __('Activate', 'wc-product-license'),
            'deactivate' => __('Deactivate', 'wc-product-license'),
            'delete'     => __('Delete', 'wc-product-license')
        ];

        return $actions;
    }

    /**
     * Process bulk actions
     */
    protected function process_bulk_action()
    {
        // This is handled by the main admin class
    }

    /**
     * Prepare items for display
     */
    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_licenses';

        // Column headers
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns()
        ];

        // Process bulk actions
        $this->process_bulk_action();

        // Build query
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $status_filter = $this->get_status_filter();
        $expiring_cutoff = strtotime('+30 days', current_time('timestamp'));

        $items = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);
        $items = is_array($items) ? $items : [];

        if ($search !== '') {
            $search_lower = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
            $items = array_values(array_filter($items, function ($item) use ($search, $search_lower) {
                $license_key = isset($item['license_key']) ? (string) $item['license_key'] : '';
                $license_key = function_exists('mb_strtolower') ? mb_strtolower($license_key) : strtolower($license_key);

                if (is_numeric($search) && (int) $item['id'] === (int) $search) {
                    return true;
                }

                return strpos($license_key, $search_lower) !== false;
            }));
        }

        if ($status_filter !== '') {
            $items = array_values(array_filter($items, function ($item) use ($status_filter, $expiring_cutoff) {
                $effective_status = $this->get_effective_status($item);

                if ($status_filter === 'expiring') {
                    if ($effective_status !== 'active') {
                        return false;
                    }

                    $expires_at = isset($item['expires_at']) ? (string) $item['expires_at'] : '';
                    if ($expires_at === '' || $expires_at === '0000-00-00 00:00:00') {
                        return false;
                    }

                    $expiry_timestamp = strtotime($expires_at);

                    return $expiry_timestamp && $expiry_timestamp <= $expiring_cutoff;
                }

                return $effective_status === $status_filter;
            }));
        }

        $total_items = count($items);

        $allowed_orderby = ['id', 'license_key', 'product_id', 'user_id', 'status', 'expires_at', 'purchased_at'];
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'id';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'id';
        }

        $order = !empty($_REQUEST['order']) ? strtoupper(sanitize_text_field($_REQUEST['order'])) : 'DESC';
        $order = $order === 'ASC' ? 'ASC' : 'DESC';

        usort($items, function ($left, $right) use ($orderby, $order) {
            if ($orderby === 'status') {
                $left_value = $this->get_effective_status($left);
                $right_value = $this->get_effective_status($right);
            } else {
                $left_value = isset($left[$orderby]) ? $left[$orderby] : '';
                $right_value = isset($right[$orderby]) ? $right[$orderby] : '';
            }

            if (in_array($orderby, ['id', 'product_id', 'user_id'], true)) {
                $comparison = (int) $left_value <=> (int) $right_value;
            } elseif (in_array($orderby, ['expires_at', 'purchased_at'], true)) {
                $comparison = strtotime((string) $left_value) <=> strtotime((string) $right_value);
            } else {
                $comparison = strnatcasecmp((string) $left_value, (string) $right_value);
            }

            return $order === 'ASC' ? $comparison : -$comparison;
        });

        $offset = ($current_page - 1) * $per_page;
        $this->items = array_slice($items, $offset, $per_page);

        // Setup pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Extra table navigation
     */
    public function extra_tablenav($which)
    {
        if ($which === 'top') {
            // Add extra filters here if needed
        }
    }

    /**
     * Message to show if no licenses
     */
    public function no_items()
    {
        _e('No licenses found.', 'wc-product-license');
    }

    /**
     * Generate the table navigation
     */
    protected function display_tablenav($which)
    {
?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php if ($which == 'top'): ?>
                <div class="alignleft actions bulkactions">
                    <?php $this->bulk_actions(); ?>
                </div>
            <?php endif; ?>

            <?php $this->extra_tablenav($which); ?>
            <?php $this->pagination($which); ?>
            <br class="clear" />
        </div>
<?php
    }

    private function get_status_filter()
    {
        $status = isset($_REQUEST['status']) ? sanitize_key(wp_unslash($_REQUEST['status'])) : '';
        $allowed = ['active', 'inactive', 'expired', 'expiring'];

        return in_array($status, $allowed, true) ? $status : '';
    }

    private function get_effective_status($item)
    {
        $status = isset($item['status']) ? (string) $item['status'] : 'inactive';
        $expires_at = isset($item['expires_at']) ? (string) $item['expires_at'] : '';

        if ($expires_at !== '' && $expires_at !== '0000-00-00 00:00:00' && strtotime($expires_at) < current_time('timestamp')) {
            return 'expired';
        }

        if ($status === 'active' || $status === 'expired') {
            return $status;
        }

        return 'inactive';
    }

    /**
     * Generate license key
     */
    private function generate_license_key()
    {
        $length = 16;
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters_length = strlen($characters);
        $license_key = '';

        for ($i = 0; $i < $length; $i++) {
            $license_key .= $characters[rand(0, $characters_length - 1)];
        }

        // Add dashes for readability
        $license_key = substr($license_key, 0, 4) . '-' .
            substr($license_key, 4, 4) . '-' .
            substr($license_key, 8, 4) . '-' .
            substr($license_key, 12, 4);

        return $license_key;
    }
}
