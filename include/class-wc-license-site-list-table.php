<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class WC_License_Site_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'site_activation',
            'plural'   => 'site_activations',
            'ajax'     => false,
        ]);
    }

    public function get_columns()
    {
        return [
            'site' => __('Site', 'wc-product-license'),
            'status' => __('Status', 'wc-product-license'),
            'license' => __('License', 'wc-product-license'),
            'customer' => __('Customer', 'wc-product-license'),
            'activated' => __('Activated', 'wc-product-license'),
            'days_active' => __('Days Active', 'wc-product-license'),
            'environment' => __('Environment', 'wc-product-license'),
            'theme' => __('Theme', 'wc-product-license'),
            'actions' => __('Actions', 'wc-product-license'),
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'site' => ['site_url', false],
            'status' => ['status', false],
            'license' => ['license_key', false],
            'activated' => ['first_requested_at', true],
            'theme' => ['active_theme', false],
        ];
    }

    public function no_items()
    {
        esc_html_e('No site activations found.', 'wc-product-license');
    }

    public function prepare_items()
    {
        global $wpdb;

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $items = $wpdb->get_results('SELECT * FROM ' . wc_product_license_get_table_name('activations'), ARRAY_A);
        $items = is_array($items) ? $items : [];
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $status_filter = $this->get_status_filter();
        $recent_cutoff = strtotime('-30 days', current_time('timestamp'));
        $product_filter = $this->get_current_product_filter();
        $multisite_filter = isset($_REQUEST['multisite_filter']) ? sanitize_key(wp_unslash($_REQUEST['multisite_filter'])) : '';
        $wp_filter = isset($_REQUEST['wp_version_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['wp_version_filter'])) : '';
        $php_filter = isset($_REQUEST['php_version_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['php_version_filter'])) : '';
        $theme_filter = isset($_REQUEST['theme_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['theme_filter'])) : '';
        $environment_filter = isset($_REQUEST['environment_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['environment_filter'])) : '';
        $reason_filter = isset($_REQUEST['reason_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['reason_filter'])) : '';

        if ($product_filter > 0) {
            $items = array_values(array_filter($items, static function ($item) use ($product_filter) {
                return (int) ($item['product_id'] ?? 0) === $product_filter;
            }));
        }

        if ($search !== '') {
            $search_lower = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
            $items = array_values(array_filter($items, function ($item) use ($search_lower) {
                $product_name = !empty($item['product_name']) ? (string) $item['product_name'] : '';
                if ($product_name === '' && !empty($item['product_id'])) {
                    $product = wc_get_product((int) ($item['product_id'] ?? 0));
                    $product_name = $product ? $product->get_name() : '';
                }

                $customer_context = wc_product_license_get_customer_context_from_license([
                    'id' => (int) ($item['license_id'] ?? 0),
                    'license_key' => (string) ($item['license_key'] ?? ''),
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'order_id' => (int) ($item['order_id'] ?? 0),
                    'user_id' => (int) ($item['user_id'] ?? 0),
                ]);
                $plugins = array_map(static function ($plugin) {
                    return $plugin['name'] ?? '';
                }, wc_product_license_get_activation_plugins($item));

                $haystack = implode(' ', [
                    (string) ($item['site_url'] ?? ''),
                    (string) ($item['site_name'] ?? ''),
                    (string) ($item['license_key'] ?? ''),
                    $product_name,
                    (string) ($customer_context['name'] ?? ''),
                    (string) ($customer_context['email'] ?? ''),
                    (string) ($item['wordpress_version'] ?? ''),
                    (string) ($item['php_version'] ?? ''),
                    (string) ($item['active_theme'] ?? ''),
                    (string) ($item['environment_type'] ?? ''),
                    (string) ($item['deactivation_reason'] ?? ''),
                    implode(' ', $plugins),
                ]);

                $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);

                return strpos($haystack, $search_lower) !== false;
            }));
        }

        if ($status_filter !== '') {
            $items = array_values(array_filter($items, function ($item) use ($status_filter, $recent_cutoff) {
                if ($status_filter === 'recent') {
                    $last_requested = !empty($item['last_requested_at']) ? strtotime((string) $item['last_requested_at']) : false;
                    return $last_requested && $last_requested >= $recent_cutoff;
                }

                return (string) ($item['status'] ?? 'inactive') === $status_filter;
            }));
        }

        if ($multisite_filter !== '') {
            $items = array_values(array_filter($items, function ($item) use ($multisite_filter) {
                $is_multisite = (int) ($item['multisite'] ?? 0) === 1;
                return $multisite_filter === 'multisite' ? $is_multisite : !$is_multisite;
            }));
        }

        foreach ([
            'wordpress_version' => $wp_filter,
            'php_version' => $php_filter,
            'active_theme' => $theme_filter,
            'environment_type' => $environment_filter,
            'deactivation_reason' => $reason_filter,
        ] as $key => $value) {
            if ($value === '') {
                continue;
            }

            $items = array_values(array_filter($items, static function ($item) use ($key, $value) {
                return (string) ($item[$key] ?? '') === $value;
            }));
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'last_requested_at';
        $order = isset($_REQUEST['order']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['order']))) : 'DESC';
        $order = $order === 'ASC' ? 'ASC' : 'DESC';

        usort($items, function ($left, $right) use ($orderby, $order) {
            $left_value = $left[$orderby] ?? '';
            $right_value = $right[$orderby] ?? '';

            if ($orderby === 'days_active') {
                $comparison = wc_product_license_get_activation_days_active($left) <=> wc_product_license_get_activation_days_active($right);
            } elseif (in_array($orderby, ['activated', 'first_requested_at', 'last_requested_at'], true)) {
                $comparison = strtotime((string) $left_value) <=> strtotime((string) $right_value);
            } else {
                $comparison = strnatcasecmp((string) $left_value, (string) $right_value);
            }

            return $order === 'ASC' ? $comparison : -$comparison;
        });

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($items);
        $offset = ($current_page - 1) * $per_page;
        $this->items = array_slice($items, $offset, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? $item[$column_name] : '';
    }

    public function column_site($item)
    {
        $site_url = (string) ($item['site_url'] ?? '');
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);
        $site_path = wp_parse_url($site_url, PHP_URL_PATH);
        $manage_args = [];
        $product_filter = $this->get_current_product_filter();
        if ($product_filter > 0) {
            $manage_args['product_id'] = $product_filter;
        }
        $manage_url = wc_product_license_get_site_manage_url((int) $item['id'], $manage_args);
        $display_name = !empty($item['site_name']) ? (string) $item['site_name'] : ($site_host ? $site_host : $site_url);
        $plugins = wc_product_license_get_activation_plugins($item);

        $actions = [
            'manage' => sprintf('<a href="%s">%s</a>', esc_url($manage_url), esc_html__('Manage', 'wc-product-license')),
        ];

        if ((string) ($item['status'] ?? '') === 'active') {
            $actions['deactivate'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    add_query_arg([
                        'page' => 'wc-license-sites',
                        'site_record_action' => 'deactivate',
                        'activation_id' => (int) $item['id'],
                    ], admin_url('admin.php')),
                    'wc_license_site_deactivate_' . (int) $item['id']
                )),
                esc_html__('Deactivate', 'wc-product-license')
            );
        }

        $meta_bits = array_filter([
            $site_host ? $site_host : '',
            $site_path ? $site_path : '',
            !empty($item['site_url']) ? $item['site_url'] : '',
            !empty($plugins) ? sprintf(_n('%d plugin snapshot', '%d plugin snapshot', count($plugins), 'wc-product-license'), count($plugins)) : '',
        ]);

        return sprintf(
            '<strong><a href="%1$s">%2$s</a></strong><span class="wc-license-detail-meta">%3$s</span>%4$s',
            esc_url($manage_url),
            esc_html($display_name),
            esc_html(implode(' • ', array_unique($meta_bits))),
            $this->row_actions($actions)
        );
    }

    public function column_status($item)
    {
        $status = (string) ($item['status'] ?? 'inactive');
        $label = $status === 'active' ? __('Active', 'wc-product-license') : __('Inactive', 'wc-product-license');
        $reason = !empty($item['deactivation_reason']) && $status === 'inactive'
            ? '<span class="wc-license-detail-meta">' . esc_html($item['deactivation_reason']) . '</span>'
            : '';

        return sprintf(
            '<span class="wc-license-site-status wc-license-site-status--%1$s">%2$s</span>%3$s',
            esc_attr($status),
            esc_html($label),
            $reason
        );
    }

    public function column_license($item)
    {
        $license_url = !empty($item['license_id'])
            ? admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['license_id']))
            : '';
        $license = !empty($item['license_id']) ? $this->get_license_record((int) $item['license_id']) : null;
        $product_name = !empty($item['product_name']) ? (string) $item['product_name'] : __('Telemetry-only record', 'wc-product-license');
        $activation_usage = $license ? wc_product_license_get_activation_usage_text((int) $license->sites_active, (int) $license->sites_allowed) : __('No linked license', 'wc-product-license');
        $license_key = !empty($item['license_key']) ? (string) $item['license_key'] : __('Unlinked', 'wc-product-license');

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span><span class="wc-license-detail-meta">%3$s</span>',
            $license_url ? '<a href="' . esc_url($license_url) . '">' . esc_html($license_key) . '</a>' : esc_html($license_key),
            esc_html($product_name),
            esc_html($activation_usage)
        );
    }

    public function column_customer($item)
    {
        $context = wc_product_license_get_customer_context_from_license([
            'id' => (int) ($item['license_id'] ?? 0),
            'license_key' => (string) ($item['license_key'] ?? ''),
            'product_id' => (int) ($item['product_id'] ?? 0),
            'order_id' => (int) ($item['order_id'] ?? 0),
            'user_id' => (int) ($item['user_id'] ?? 0),
        ]);

        if (empty($context['customer_url'])) {
            return esc_html__('No customer linked', 'wc-product-license');
        }

        return sprintf(
            '<a href="%1$s">%2$s</a><span class="wc-license-detail-meta">%3$s</span>',
            esc_url($context['customer_url']),
            esc_html($context['name']),
            esc_html($context['email'] !== '' ? $context['email'] : $context['type_label'])
        );
    }

    public function column_activated($item)
    {
        $value = $this->format_datetime($item['first_requested_at'] ?? '');
        $last_seen = $this->format_datetime($item['last_requested_at'] ?? '');

        return $value . '<span class="wc-license-detail-meta">' . esc_html(sprintf(__('Last seen %s', 'wc-product-license'), wp_strip_all_tags($last_seen))) . '</span>';
    }

    public function column_days_active($item)
    {
        $days = wc_product_license_get_activation_days_active($item);
        $requests = absint($item['request_count'] ?? 0);

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span>',
            esc_html(sprintf(_n('%d day', '%d days', $days, 'wc-product-license'), $days)),
            esc_html(sprintf(_n('%d request logged', '%d requests logged', $requests, 'wc-product-license'), $requests))
        );
    }

    public function column_environment($item)
    {
        $lines = array_filter([
            !empty($item['wordpress_version']) ? 'WordPress ' . $item['wordpress_version'] : '',
            !empty($item['php_version']) ? 'PHP ' . $item['php_version'] : '',
            !empty($item['mysql_version']) ? 'MySQL ' . $item['mysql_version'] : '',
            !empty($item['environment_type']) ? ucfirst((string) $item['environment_type']) : '',
            (int) ($item['multisite'] ?? 0) === 1 ? __('Multisite', 'wc-product-license') : __('Single site', 'wc-product-license'),
        ]);

        if (empty($lines)) {
            return esc_html__('Not reported yet', 'wc-product-license');
        }

        $primary = array_shift($lines);
        $secondary = !empty($lines) ? implode(' • ', $lines) : __('No extra environment details', 'wc-product-license');

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span>',
            esc_html($primary),
            esc_html($secondary)
        );
    }

    public function column_theme($item)
    {
        $theme = !empty($item['active_theme']) ? (string) $item['active_theme'] : __('Not reported', 'wc-product-license');
        $theme_version = !empty($item['active_theme_version']) ? 'v' . (string) $item['active_theme_version'] : '';
        $plugin_version = !empty($item['plugin_version']) ? __('Plugin', 'wc-product-license') . ' v' . (string) $item['plugin_version'] : '';
        $ip = !empty($item['last_ip']) ? __('IP', 'wc-product-license') . ' ' . (string) $item['last_ip'] : '';

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span><span class="wc-license-detail-meta">%3$s</span>',
            esc_html($theme),
            esc_html(trim($theme_version)),
            esc_html(trim(implode(' • ', array_filter([$plugin_version, $ip]))))
        );
    }

    public function column_actions($item)
    {
        $product_filter = $this->get_current_product_filter();
        $manage_args = $product_filter > 0 ? ['product_id' => $product_filter] : [];
        $buttons = [];
        $buttons[] = sprintf(
            '<a href="%s" class="button button-secondary">%s</a>',
            esc_url(wc_product_license_get_site_manage_url((int) $item['id'], $manage_args)),
            esc_html__('Manage', 'wc-product-license')
        );

        if (!empty($item['license_id'])) {
            $buttons[] = sprintf(
                '<a href="%s" class="button button-secondary">%s</a>',
                esc_url(admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['license_id']))),
                esc_html__('License', 'wc-product-license')
            );
        }

        if ((string) ($item['status'] ?? '') === 'active') {
            $buttons[] = sprintf(
                '<a href="%s" class="button button-secondary">%s</a>',
                esc_url(wp_nonce_url(
                    add_query_arg(array_filter([
                        'page' => 'wc-license-sites',
                        'site_record_action' => 'deactivate',
                        'activation_id' => (int) $item['id'],
                        'product_id' => $product_filter,
                    ]), admin_url('admin.php')),
                    'wc_license_site_deactivate_' . (int) $item['id']
                )),
                esc_html__('Deactivate', 'wc-product-license')
            );
        }

        return '<div class="license-actions">' . implode('', $buttons) . '</div>';
    }

    protected function display_tablenav($which)
    {
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php $this->pagination($which); ?>
            <br class="clear" />
        </div>
        <?php
    }

    private function get_status_filter()
    {
        $status = isset($_REQUEST['status']) ? sanitize_key(wp_unslash($_REQUEST['status'])) : '';
        $allowed = ['active', 'inactive', 'recent'];

        return in_array($status, $allowed, true) ? $status : '';
    }

    private function get_current_product_filter()
    {
        return isset($_REQUEST['product_id']) ? absint(wp_unslash($_REQUEST['product_id'])) : 0;
    }

    private function get_license_record($license_id)
    {
        static $cache = [];

        $license_id = absint($license_id);
        if ($license_id < 1) {
            return null;
        }

        if (!array_key_exists($license_id, $cache)) {
            global $wpdb;
            $cache[$license_id] = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . wc_product_license_get_table_name('licenses') . ' WHERE id = %d LIMIT 1',
                $license_id
            ));
        }

        return $cache[$license_id];
    }

    private function format_datetime($datetime)
    {
        if (empty($datetime)) {
            return esc_html__('Not recorded', 'wc-product-license');
        }

        $timestamp = strtotime((string) $datetime);
        if (!$timestamp) {
            return esc_html__('Not recorded', 'wc-product-license');
        }

        return esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp));
    }
}
