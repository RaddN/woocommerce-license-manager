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
            'user'          => __('User', 'wc-product-license'),
            'status'        => __('Status', 'wc-product-license'),
            'sites'         => __('Sites', 'wc-product-license'),
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
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wc-license-keys&action=edit&license_id=' . $item['id']),
                __('Edit', 'wc-product-license')
            ),
            'delete' => sprintf(
                '<a href="#" class="delete-license" data-id="%s">%s</a>',
                $item['id'],
                __('Delete', 'wc-product-license')
            ),
            'view' => sprintf(
            '<a href="#" class="view-license" data-id="%d" data-key="%s" data-product="%s" data-user="%s" data-status="%s" data-expires="%s" data-sites-allowed="%d" data-sites-active="%d" data-sites ="%d">%s</a>',
            $item['id'],
            esc_attr($item['license_key']),
            esc_attr(get_the_title($item['product_id'])),
            esc_attr(get_user_by('id', $item['user_id'])->display_name),
            esc_attr($item['status']),
            esc_attr($item['expires_at']),
            esc_attr($item['sites_allowed']),
            esc_attr($item['sites_active']),
            esc_attr($item['active_sites']),
            __('View', 'wc-product-license')
        ),
        ];

        return sprintf(
            '<strong>%1$s</strong> %2$s',
            $item['license_key'],
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
        $user = get_user_by('id', $item['user_id']);
        return $user ? sprintf(
            '<a href="%s">%s</a>',
            admin_url('user-edit.php?user_id=' . $user->ID),
            esc_html($user->display_name . ' (' . $user->user_email . ')')
        ) : __('Unknown', 'wc-product-license');
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

        $class = isset($status_classes[$item['status']]) ? $status_classes[$item['status']] : '';
        $label = isset($status_labels[$item['status']]) ? $status_labels[$item['status']] : $item['status'];

        return sprintf('<span class="%s">%s</span>', $class, $label);
    }

    /**
     * Sites column
     */
    public function column_sites($item)
    {
        return sprintf(
            '%d / %d',
            $item['sites_active'],
            $item['sites_allowed']
        );
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

        // Status-dependent buttons
        if ($item['status'] === 'active') {
            $actions .= sprintf(
                '<a href="#" class="button deactivate-license" data-id="%d">%s</a>',
                $item['id'],
                __('Deactivate', 'wc-product-license')
            );
        } else {
            $actions .= sprintf(
                '<a href="#" class="button activate-license" data-id="%d">%s</a>',
                $item['id'],
                __('Activate', 'wc-product-license')
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
        $total_items = (int)$wpdb->get_var("SELECT COUNT(id) FROM $table_name");

        // Handle search
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $where = '';

        if (!empty($search)) {
            $where = $wpdb->prepare(
                "WHERE license_key LIKE %s OR id = %d",
                '%' . $wpdb->esc_like($search) . '%',
                is_numeric($search) ? (int)$search : 0
            );
        }

        // Handle sorting
        $orderby = !empty($_REQUEST['orderby']) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'id';
        $order = !empty($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';

        // Get data
        $sql = "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $this->items = $wpdb->get_results(
            $wpdb->prepare($sql, $per_page, ($current_page - 1) * $per_page),
            ARRAY_A
        );

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
