<?php

if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class WC_License_Customer_List_Table extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'customer',
            'plural'   => 'customers',
            'ajax'     => false,
        ]);
    }

    public function get_columns()
    {
        return [
            'customer' => __('Customer', 'wc-product-license'),
            'licenses' => __('Licenses', 'wc-product-license'),
            'orders' => __('Orders', 'wc-product-license'),
            'revenue' => __('Revenue', 'wc-product-license'),
            'activations' => __('Activations', 'wc-product-license'),
            'last_purchase' => __('Last Purchase', 'wc-product-license'),
            'actions' => __('Actions', 'wc-product-license'),
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'customer' => ['customer', false],
            'licenses' => ['licenses', false],
            'orders' => ['orders', false],
            'revenue' => ['revenue', false],
            'activations' => ['activations', false],
            'last_purchase' => ['last_purchase', true],
        ];
    }

    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? $item[$column_name] : '';
    }

    public function no_items()
    {
        esc_html_e('No customers found.', 'wc-product-license');
    }

    public function prepare_items()
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $customers = array_values(wc_product_license_get_customer_directory());
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $segment = $this->get_segment_filter();

        if ($search !== '') {
            $search_lower = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
            $customers = array_values(array_filter($customers, function ($customer) use ($search, $search_lower) {
                if (is_numeric($search) && in_array((int) $search, array_map('absint', $customer['order_ids']), true)) {
                    return true;
                }

                $haystacks = [
                    (string) ($customer['name'] ?? ''),
                    (string) ($customer['email'] ?? ''),
                    implode(' ', (array) ($customer['emails'] ?? [])),
                ];

                foreach ((array) ($customer['licenses'] ?? []) as $license) {
                    $haystacks[] = (string) $license->license_key;
                }

                $normalized = function_exists('mb_strtolower')
                    ? mb_strtolower(implode(' ', $haystacks))
                    : strtolower(implode(' ', $haystacks));

                return strpos($normalized, $search_lower) !== false;
            }));
        }

        if ($segment !== '') {
            $customers = array_values(array_filter($customers, function ($customer) use ($segment) {
                if ($segment === 'registered') {
                    return ($customer['type'] ?? '') === 'registered';
                }

                if ($segment === 'guest') {
                    return ($customer['type'] ?? '') !== 'registered';
                }

                if ($segment === 'active') {
                    return !empty($customer['active_licenses']);
                }

                if ($segment === 'expiring') {
                    return !empty($customer['expiring_licenses']);
                }

                return true;
            }));
        }

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'last_purchase';
        $order = isset($_REQUEST['order']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['order']))) : 'DESC';
        $order = $order === 'ASC' ? 'ASC' : 'DESC';

        usort($customers, function ($left, $right) use ($orderby, $order) {
            $comparison = 0;

            switch ($orderby) {
                case 'customer':
                    $comparison = strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
                    break;
                case 'licenses':
                    $comparison = (int) ($left['license_count'] ?? 0) <=> (int) ($right['license_count'] ?? 0);
                    break;
                case 'orders':
                    $comparison = (int) ($left['order_count'] ?? 0) <=> (int) ($right['order_count'] ?? 0);
                    break;
                case 'revenue':
                    $comparison = (float) ($left['revenue'] ?? 0) <=> (float) ($right['revenue'] ?? 0);
                    break;
                case 'activations':
                    $comparison = (int) ($left['activations'] ?? 0) <=> (int) ($right['activations'] ?? 0);
                    break;
                case 'last_purchase':
                default:
                    $comparison = strtotime((string) ($left['last_purchase_at'] ?? '')) <=> strtotime((string) ($right['last_purchase_at'] ?? ''));
                    break;
            }

            return $order === 'ASC' ? $comparison : -$comparison;
        });

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = count($customers);
        $offset = ($current_page - 1) * $per_page;
        $this->items = array_slice($customers, $offset, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }

    public function column_customer($item)
    {
        $email = !empty($item['email']) ? $item['email'] : (!empty($item['emails']) ? reset($item['emails']) : '');
        $latest_license_url = !empty($item['latest_license_id'])
            ? admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['latest_license_id']))
            : '';

        $actions = [
            'manage' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($item['manage_url']),
                esc_html__('Manage', 'wc-product-license')
            ),
        ];

        if (!empty($item['user_url'])) {
            $actions['user'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($item['user_url']),
                esc_html__('WordPress User', 'wc-product-license')
            );
        }

        if ($latest_license_url) {
            $actions['license'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($latest_license_url),
                esc_html__('Latest License', 'wc-product-license')
            );
        }

        return sprintf(
            '<strong><a href="%1$s">%2$s</a></strong><span class="wc-license-detail-meta">%3$s</span><span class="wc-license-customer-badge %4$s">%5$s</span>%6$s',
            esc_url($item['manage_url']),
            esc_html($item['name']),
            esc_html($email !== '' ? $email : $item['type_label']),
            esc_attr($this->get_customer_badge_class($item['type'] ?? 'unknown')),
            esc_html($item['type_label']),
            $this->row_actions($actions)
        );
    }

    public function column_licenses($item)
    {
        $active = (int) ($item['active_licenses'] ?? 0);
        $expired = (int) ($item['expired_licenses'] ?? 0);

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span>',
            esc_html(number_format_i18n((int) ($item['license_count'] ?? 0))),
            esc_html(sprintf(__('%1$d active / %2$d expired', 'wc-product-license'), $active, $expired))
        );
    }

    public function column_orders($item)
    {
        $latest_order_id = !empty($item['latest_order_id']) ? absint($item['latest_order_id']) : 0;
        $meta = $latest_order_id > 0
            ? sprintf(__('Latest order #%d', 'wc-product-license'), $latest_order_id)
            : __('No linked Woo order', 'wc-product-license');

        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span>',
            esc_html(number_format_i18n((int) ($item['order_count'] ?? 0))),
            esc_html($meta)
        );
    }

    public function column_revenue($item)
    {
        return wp_kses_post(wc_price((float) ($item['revenue'] ?? 0)));
    }

    public function column_activations($item)
    {
        return sprintf(
            '<strong>%1$s</strong><span class="wc-license-detail-meta">%2$s</span>',
            esc_html(number_format_i18n((int) ($item['activations'] ?? 0))),
            esc_html(sprintf(_n('%d expiring soon', '%d expiring soon', (int) ($item['expiring_licenses'] ?? 0), 'wc-product-license'), (int) ($item['expiring_licenses'] ?? 0)))
        );
    }

    public function column_last_purchase($item)
    {
        if (empty($item['last_purchase_at'])) {
            return esc_html__('Not recorded', 'wc-product-license');
        }

        return esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime((string) $item['last_purchase_at'])));
    }

    public function column_actions($item)
    {
        $buttons = [];
        $buttons[] = sprintf(
            '<a href="%s" class="button button-secondary">%s</a>',
            esc_url($item['manage_url']),
            esc_html__('Manage', 'wc-product-license')
        );

        if (!empty($item['latest_license_id'])) {
            $buttons[] = sprintf(
                '<a href="%s" class="button button-secondary">%s</a>',
                esc_url(admin_url('admin.php?page=wc-license-keys&action=manage&license_id=' . absint($item['latest_license_id']))),
                esc_html__('Latest License', 'wc-product-license')
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

    private function get_segment_filter()
    {
        $segment = isset($_REQUEST['segment']) ? sanitize_key(wp_unslash($_REQUEST['segment'])) : '';
        $allowed = ['registered', 'guest', 'active', 'expiring'];

        return in_array($segment, $allowed, true) ? $segment : '';
    }

    private function get_customer_badge_class($type)
    {
        $classes = [
            'registered' => 'is-registered',
            'guest' => 'is-guest',
            'manual_guest' => 'is-guest',
            'unknown' => 'is-unknown',
        ];

        return isset($classes[$type]) ? $classes[$type] : 'is-unknown';
    }
}
