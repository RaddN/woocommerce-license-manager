<?php

class WC_License_Analytics
{
    public function render_analytics_page()
    {
        wp_enqueue_script('chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js', [], '3.9.1', true);
        wp_enqueue_style('wc-license-analytics-css', plugin_dir_url(__FILE__) . '../assets/css/analytics.css', [], '1.0.0');
        wp_enqueue_script('wc-license-analytics-js', plugin_dir_url(__FILE__) . '../assets/js/analytics.js', ['jquery', 'chart-js'], '1.0.0', true);

        $product_id = $this->get_requested_product_id();
        $current_tab = $this->get_requested_tab();
        $dashboard = $this->build_dashboard_data($product_id);

        wp_localize_script('wc-license-analytics-js', 'wcLicenseAnalytics', [
            'charts' => $dashboard['charts'],
        ]);
        ?>
        <div class="wrap wc-license-dashboard">
            <div class="wc-license-dashboard__hero">
                <div class="wc-license-dashboard__hero-copy">
                    <span class="wc-license-dashboard__scope">
                        <?php
                        echo $dashboard['scope']['id'] > 0
                            ? esc_html(sprintf(__('Product scope: %s', 'wc-product-license'), $dashboard['scope']['name']))
                            : esc_html__('Global telemetry view', 'wc-product-license');
                        ?>
                    </span>
                    <h1><?php echo esc_html($dashboard['scope']['id'] > 0 ? __('Product Dashboard', 'wc-product-license') : __('Dashboard', 'wc-product-license')); ?></h1>
                    <p><?php echo esc_html($dashboard['scope']['description']); ?></p>
                </div>

                <div class="wc-license-dashboard__hero-tools">
                    <form method="get" class="wc-license-dashboard__hero-form">
                        <input type="hidden" name="page" value="wc-license-dashboard" />
                        <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>" />
                        <label for="wc-license-dashboard-product"><?php esc_html_e('Product scope', 'wc-product-license'); ?></label>
                        <div class="wc-license-dashboard__hero-form-row">
                            <select id="wc-license-dashboard-product" name="product_id">
                                <option value=""><?php esc_html_e('All licensed products', 'wc-product-license'); ?></option>
                                <?php foreach ($dashboard['product_options'] as $product_option) : ?>
                                    <option value="<?php echo esc_attr($product_option['id']); ?>" <?php selected($product_id, (int) $product_option['id']); ?>>
                                        <?php echo esc_html(sprintf('%s (%s)', $product_option['name'], number_format_i18n($product_option['total']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'wc-product-license'); ?></button>
                        </div>
                    </form>

                    <div class="wc-license-dashboard__hero-actions">
                        <?php if ($dashboard['scope']['id'] > 0 && !empty($dashboard['scope']['edit_url'])) : ?>
                            <a href="<?php echo esc_url($dashboard['scope']['edit_url']); ?>" class="button button-secondary"><?php esc_html_e('Edit Product', 'wc-product-license'); ?></a>
                        <?php endif; ?>
                        <?php if ($dashboard['scope']['id'] > 0) : ?>
                            <a href="<?php echo esc_url(wc_product_license_get_dashboard_url()); ?>" class="button button-secondary"><?php esc_html_e('Clear Scope', 'wc-product-license'); ?></a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($dashboard['sites_url']); ?>" class="button button-primary"><?php esc_html_e('Open Sites', 'wc-product-license'); ?></a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-license-keys')); ?>" class="button button-secondary"><?php esc_html_e('Open Licenses', 'wc-product-license'); ?></a>
                    </div>
                </div>
            </div>

            <div class="wc-license-dashboard__tabs" role="tablist" aria-label="<?php esc_attr_e('Dashboard sections', 'wc-product-license'); ?>">
                <?php foreach ($dashboard['tabs'] as $tab_key => $tab_data) : ?>
                    <a
                        href="<?php echo esc_url($tab_data['url']); ?>"
                        class="wc-license-dashboard__tab <?php echo $current_tab === $tab_key ? 'is-active' : ''; ?>"
                        role="tab"
                        aria-selected="<?php echo $current_tab === $tab_key ? 'true' : 'false'; ?>"
                    >
                        <span class="wc-license-dashboard__tab-label"><?php echo esc_html($tab_data['label']); ?></span>
                        <span class="wc-license-dashboard__tab-meta"><?php echo esc_html($tab_data['meta']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="wc-license-dashboard__stats">
                <?php foreach ($dashboard['summary'] as $card) : ?>
                    <article class="wc-license-dashboard__stat-card">
                        <span class="wc-license-dashboard__stat-label"><?php echo esc_html($card['label']); ?></span>
                        <strong><?php echo esc_html($card['value']); ?></strong>
                        <small><?php echo esc_html($card['description']); ?></small>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($current_tab === 'sites') : ?>
                <section class="wc-license-dashboard__panel">
                    <div class="wc-license-dashboard__panel-header">
                        <div>
                            <span class="wc-license-dashboard__panel-eyebrow"><?php esc_html_e('Sites', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Latest site records', 'wc-product-license'); ?></h2>
                        </div>
                        <small><?php echo esc_html($dashboard['sites_tab_note']); ?></small>
                    </div>
                    <?php $this->render_site_cards($dashboard['sites_digest'], __('No site telemetry recorded for this scope yet.', 'wc-product-license')); ?>
                </section>
            <?php elseif ($current_tab === 'deactivations') : ?>
                <section class="wc-license-dashboard__panel">
                    <div class="wc-license-dashboard__panel-header">
                        <div>
                            <span class="wc-license-dashboard__panel-eyebrow"><?php esc_html_e('Deactivations', 'wc-product-license'); ?></span>
                            <h2><?php esc_html_e('Recent deactivation feedback', 'wc-product-license'); ?></h2>
                        </div>
                        <small><?php echo esc_html($dashboard['deactivations_tab_note']); ?></small>
                    </div>
                    <?php $this->render_deactivation_cards($dashboard['deactivations_digest'], __('No deactivation telemetry recorded for this scope yet.', 'wc-product-license')); ?>
                </section>
            <?php else : ?>
                <div class="wc-license-dashboard__charts">
                    <?php foreach ($dashboard['chart_panels'] as $panel) : ?>
                        <section class="wc-license-dashboard__panel">
                            <div class="wc-license-dashboard__panel-header">
                                <div>
                                    <span class="wc-license-dashboard__panel-eyebrow"><?php echo esc_html($panel['eyebrow']); ?></span>
                                    <h2><?php echo esc_html($panel['title']); ?></h2>
                                </div>
                            </div>
                            <div class="wc-license-dashboard__chart-wrap">
                                <canvas id="<?php echo esc_attr($panel['canvas_id']); ?>"></canvas>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="wc-license-dashboard__grid">
                    <section class="wc-license-dashboard__panel">
                        <div class="wc-license-dashboard__panel-header">
                            <div>
                                <span class="wc-license-dashboard__panel-eyebrow"><?php esc_html_e('Recent sites', 'wc-product-license'); ?></span>
                                <h2><?php esc_html_e('Latest check-ins', 'wc-product-license'); ?></h2>
                            </div>
                        </div>
                        <?php if (!empty($dashboard['recent_sites'])) : ?>
                            <div class="wc-license-dashboard__list">
                                <?php foreach ($dashboard['recent_sites'] as $site) : ?>
                                    <article class="wc-license-dashboard__list-item">
                                        <div>
                                            <strong><a href="<?php echo esc_url($site['manage_url']); ?>"><?php echo esc_html($site['label']); ?></a></strong>
                                            <span><?php echo esc_html($site['meta']); ?></span>
                                        </div>
                                        <div class="wc-license-dashboard__list-aside">
                                            <span class="wc-license-dashboard__status wc-license-dashboard__status--<?php echo esc_attr($site['status']); ?>"><?php echo esc_html($site['status_label']); ?></span>
                                            <small><?php echo esc_html($site['last_seen']); ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="wc-license-dashboard__empty"><?php esc_html_e('No site telemetry recorded yet.', 'wc-product-license'); ?></div>
                        <?php endif; ?>
                    </section>

                    <section class="wc-license-dashboard__panel">
                        <div class="wc-license-dashboard__panel-header">
                            <div>
                                <span class="wc-license-dashboard__panel-eyebrow"><?php esc_html_e('Deactivations', 'wc-product-license'); ?></span>
                                <h2><?php esc_html_e('Recent deactivation feedback', 'wc-product-license'); ?></h2>
                            </div>
                        </div>
                        <?php if (!empty($dashboard['recent_deactivations'])) : ?>
                            <div class="wc-license-dashboard__list">
                                <?php foreach ($dashboard['recent_deactivations'] as $site) : ?>
                                    <article class="wc-license-dashboard__list-item">
                                        <div>
                                            <strong><a href="<?php echo esc_url($site['manage_url']); ?>"><?php echo esc_html($site['label']); ?></a></strong>
                                            <span><?php echo esc_html($site['reason']); ?></span>
                                        </div>
                                        <div class="wc-license-dashboard__list-aside">
                                            <small><?php echo esc_html($site['last_seen']); ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="wc-license-dashboard__empty"><?php esc_html_e('No deactivation feedback recorded yet.', 'wc-product-license'); ?></div>
                        <?php endif; ?>
                    </section>

                    <section class="wc-license-dashboard__panel">
                        <div class="wc-license-dashboard__panel-header">
                            <div>
                                <span class="wc-license-dashboard__panel-eyebrow"><?php esc_html_e('Compatibility', 'wc-product-license'); ?></span>
                                <h2><?php esc_html_e('Adoption mix and compatibility signals', 'wc-product-license'); ?></h2>
                            </div>
                        </div>
                        <div class="wc-license-dashboard__stack">
                            <?php foreach ($dashboard['breakdowns'] as $breakdown) : ?>
                                <div class="wc-license-dashboard__stack-card">
                                    <h3><?php echo esc_html($breakdown['title']); ?></h3>
                                    <?php if (!empty($breakdown['items'])) : ?>
                                        <ul>
                                            <?php foreach ($breakdown['items'] as $item) : ?>
                                                <li>
                                                    <span><?php echo esc_html($item['label']); ?></span>
                                                    <strong><?php echo esc_html($item['value']); ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else : ?>
                                        <p><?php esc_html_e('No data yet.', 'wc-product-license'); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_dashboard_data($product_id = 0)
    {
        global $wpdb;

        $product_id = absint($product_id);
        $activations_table = wc_product_license_get_table_name('activations');
        $product_options = wc_product_license_get_tracked_product_options();
        $scope = $this->get_dashboard_scope($product_id, $product_options);
        $query = 'SELECT * FROM ' . $activations_table;
        if ($product_id > 0) {
            $query .= $wpdb->prepare(' WHERE product_id = %d', $product_id);
        }
        $query .= ' ORDER BY last_requested_at DESC, id DESC';

        $rows = $wpdb->get_results($query);
        $rows = is_array($rows) ? $rows : [];
        $now = current_time('timestamp');
        $new_30_cutoff = strtotime('-30 days', $now);
        $trend_map = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = date_i18n('Y-m-d', strtotime("-{$i} days", $now));
            $trend_map[$day] = 0;
        }

        $status_counts = ['Active' => 0, 'Inactive' => 0];
        $multisite_counts = ['Multisite' => 0, 'Single Site' => 0];
        $wp_versions = [];
        $php_versions = [];
        $theme_counts = [];
        $plugin_versions = [];
        $deactivation_reasons = [];
        $mysql_versions = [];
        $server_software = [];
        $plugin_inventory = [];
        $product_breakdown = [];
        $site_scope_counts = [];
        $owner_type_counts = [];
        $license_channel_counts = [];
        $unique_license_ids = [];
        $unique_product_ids = [];
        $summary = [
            'total' => count($rows),
            'active' => 0,
            'inactive' => 0,
            'new_30' => 0,
        ];
        $recent_sites = [];
        $recent_deactivations = [];

        foreach ($rows as $row) {
            $meta = wc_product_license_get_activation_meta($row);
            $plugins = wc_product_license_get_activation_plugins($row);
            $status = (string) $row->status === 'active' ? 'active' : 'inactive';
            $summary[$status]++;
            $status_counts[$status === 'active' ? 'Active' : 'Inactive']++;
            $multisite_counts[(int) $row->multisite === 1 ? 'Multisite' : 'Single Site']++;

            if (!empty($row->license_id)) {
                $unique_license_ids[] = (int) $row->license_id;
            }
            if (!empty($row->product_id)) {
                $unique_product_ids[] = (int) $row->product_id;
            }

            $first_requested = !empty($row->first_requested_at) ? strtotime((string) $row->first_requested_at) : false;
            if ($first_requested && $first_requested >= $new_30_cutoff) {
                $summary['new_30']++;
                $trend_key = date_i18n('Y-m-d', $first_requested);
                if (isset($trend_map[$trend_key])) {
                    $trend_map[$trend_key]++;
                }
            }

            $product_name = !empty($row->product_name)
                ? (string) $row->product_name
                : wc_product_license_get_product_label((int) $row->product_id, __('Telemetry-only record', 'wc-product-license'));

            foreach ([
                'wp_versions' => !empty($row->wordpress_version) ? (string) $row->wordpress_version : '',
                'php_versions' => !empty($row->php_version) ? (string) $row->php_version : '',
                'theme_counts' => !empty($row->active_theme) ? (string) $row->active_theme : '',
                'plugin_versions' => !empty($row->plugin_version) ? (string) $row->plugin_version : '',
                'deactivation_reasons' => !empty($row->deactivation_reason) ? (string) $row->deactivation_reason : '',
                'mysql_versions' => !empty($row->mysql_version) ? (string) $row->mysql_version : '',
                'server_software' => !empty($row->server_software) ? preg_replace('/\/.*/', '', (string) $row->server_software) : '',
                'product_breakdown' => $product_name,
                'site_scope_counts' => !empty($meta['site_scope']) ? $this->format_scope_label((string) $meta['site_scope']) : '',
                'owner_type_counts' => !empty($meta['site_owner_type']) ? $this->format_scope_label((string) $meta['site_owner_type']) : '',
                'license_channel_counts' => !empty($meta['license_channel']) ? $this->format_scope_label((string) $meta['license_channel']) : '',
            ] as $bucket => $value) {
                if ($value === '') {
                    continue;
                }

                if (!isset($$bucket[$value])) {
                    $$bucket[$value] = 0;
                }

                $$bucket[$value]++;
            }

            foreach ($plugins as $plugin) {
                $name = !empty($plugin['name']) ? (string) $plugin['name'] : '';
                if ($name === '') {
                    continue;
                }

                if (!isset($plugin_inventory[$name])) {
                    $plugin_inventory[$name] = 0;
                }

                $plugin_inventory[$name]++;
            }

            $site_url = (string) $row->site_url;
            $site_label = !empty($row->site_name) ? (string) $row->site_name : $this->get_site_host_label($site_url);
            $site_scope = !empty($meta['site_scope']) ? $this->format_scope_label((string) $meta['site_scope']) : '';
            $owner_type = !empty($meta['site_owner_type']) ? $this->format_scope_label((string) $meta['site_owner_type']) : '';
            $environment_bits = array_filter([
                !empty($row->wordpress_version) ? 'WP ' . $row->wordpress_version : '',
                !empty($row->php_version) ? 'PHP ' . $row->php_version : '',
                !empty($row->environment_type) ? ucfirst((string) $row->environment_type) : '',
                (int) $row->multisite === 1 ? __('Multisite', 'wc-product-license') : __('Single site', 'wc-product-license'),
            ]);
            $detail_bits = array_filter([
                $product_id > 0 ? '' : $product_name,
                !empty($row->active_theme) ? $row->active_theme : '',
                !empty($row->plugin_version) ? sprintf(__('Plugin v%s', 'wc-product-license'), $row->plugin_version) : '',
                $site_scope,
                $owner_type,
            ]);
            $manage_url = wc_product_license_get_site_manage_url((int) $row->id, $product_id > 0 ? ['product_id' => $product_id] : []);

            $recent_sites[] = [
                'label' => $site_label,
                'url' => $site_url,
                'meta' => implode(' / ', $environment_bits),
                'detail' => implode(' / ', $detail_bits),
                'status' => $status,
                'status_label' => $status === 'active' ? __('Active', 'wc-product-license') : __('Inactive', 'wc-product-license'),
                'last_seen' => $this->format_datetime((string) $row->last_requested_at),
                'first_seen' => $this->format_datetime((string) $row->first_requested_at),
                'days_active' => wc_product_license_get_activation_days_active($row),
                'requests' => absint($row->request_count),
                'manage_url' => $manage_url,
                'open_url' => strpos($site_url, 'http') === 0 ? $site_url : '',
            ];

            if ($status === 'inactive') {
                $recent_deactivations[] = [
                    'label' => $site_label,
                    'url' => $site_url,
                    'reason' => !empty($row->deactivation_reason) ? (string) $row->deactivation_reason : __('No reason recorded', 'wc-product-license'),
                    'note' => !empty($meta['deactivation_note']) ? (string) $meta['deactivation_note'] : '',
                    'detail' => implode(' / ', $detail_bits),
                    'environment' => implode(' / ', $environment_bits),
                    'last_seen' => $this->format_datetime(!empty($row->deactivated_at) ? (string) $row->deactivated_at : (string) $row->last_requested_at),
                    'manage_url' => $manage_url,
                ];
            }
        }

        arsort($wp_versions);
        arsort($php_versions);
        arsort($theme_counts);
        arsort($plugin_versions);
        arsort($deactivation_reasons);
        arsort($mysql_versions);
        arsort($server_software);
        arsort($plugin_inventory);
        arsort($product_breakdown);
        arsort($site_scope_counts);
        arsort($owner_type_counts);
        arsort($license_channel_counts);

        $linked_licenses = count(array_unique(array_filter($unique_license_ids)));
        $products_tracked = count(array_unique(array_filter($unique_product_ids)));
        $sites_page_url = add_query_arg(array_filter([
            'product_id' => $product_id,
        ]), admin_url('admin.php?page=wc-license-sites'));

        return [
            'scope' => $scope,
            'product_options' => $product_options,
            'sites_url' => $sites_page_url,
            'tabs' => $this->get_dashboard_tabs($product_id, $summary),
            'summary' => $this->get_summary_cards($product_id, $summary, $linked_licenses, $products_tracked),
            'charts' => [
                'wc-license-chart-status' => $this->build_chart_config('doughnut', array_keys($status_counts), array_values($status_counts), ['#1a9b5f', '#0f172a']),
                'wc-license-chart-trend' => $this->build_chart_config('bar', array_keys($trend_map), array_values($trend_map), ['#2563eb']),
                'wc-license-chart-multisite' => $this->build_chart_config('doughnut', array_keys($multisite_counts), array_values($multisite_counts), ['#0ea5e9', '#f59e0b']),
                'wc-license-chart-wp' => $this->build_chart_config('bar', array_keys(array_slice($wp_versions, 0, 8, true)), array_values(array_slice($wp_versions, 0, 8, true)), ['#14b8a6']),
                'wc-license-chart-php' => $this->build_chart_config('bar', array_keys(array_slice($php_versions, 0, 8, true)), array_values(array_slice($php_versions, 0, 8, true)), ['#8b5cf6']),
                'wc-license-chart-themes' => $this->build_chart_config('bar', array_keys(array_slice($theme_counts, 0, 8, true)), array_values(array_slice($theme_counts, 0, 8, true)), ['#f97316']),
                'wc-license-chart-plugin-version' => $this->build_chart_config('bar', array_keys(array_slice($plugin_versions, 0, 8, true)), array_values(array_slice($plugin_versions, 0, 8, true)), ['#ef4444']),
                'wc-license-chart-reasons' => $this->build_chart_config('bar', array_keys(array_slice($deactivation_reasons, 0, 8, true)), array_values(array_slice($deactivation_reasons, 0, 8, true)), ['#64748b']),
            ],
            'chart_panels' => $this->get_chart_panels(),
            'recent_sites' => array_slice($recent_sites, 0, 8),
            'recent_deactivations' => array_slice($recent_deactivations, 0, 8),
            'sites_digest' => array_slice($recent_sites, 0, 24),
            'deactivations_digest' => array_slice($recent_deactivations, 0, 24),
            'sites_tab_note' => sprintf(__('Showing the latest %1$d site records. Open Sites for the full filterable table.', 'wc-product-license'), min(24, $summary['total'])),
            'deactivations_tab_note' => sprintf(__('Showing the latest %1$d archived site records. Open Sites for full filtering and actions.', 'wc-product-license'), min(24, $summary['inactive'])),
            'breakdowns' => $this->get_breakdowns($product_id, $product_breakdown, $site_scope_counts, $owner_type_counts, $license_channel_counts, $plugin_inventory, $mysql_versions, $server_software),
        ];
    }

    private function render_site_cards($sites, $empty_message)
    {
        if (empty($sites)) {
            echo '<div class="wc-license-dashboard__empty">' . esc_html($empty_message) . '</div>';
            return;
        }

        echo '<div class="wc-license-dashboard__site-grid">';
        foreach ($sites as $site) {
            echo '<article class="wc-license-dashboard__site-card">';
            echo '<div class="wc-license-dashboard__site-card-top">';
            echo '<div>';
            echo '<strong><a href="' . esc_url($site['manage_url']) . '">' . esc_html($site['label']) . '</a></strong>';
            echo '<span>' . esc_html($site['url']) . '</span>';
            echo '</div>';
            echo '<span class="wc-license-dashboard__status wc-license-dashboard__status--' . esc_attr($site['status']) . '">' . esc_html($site['status_label']) . '</span>';
            echo '</div>';

            if ($site['meta'] !== '') {
                echo '<p class="wc-license-dashboard__site-copy">' . esc_html($site['meta']) . '</p>';
            }

            if ($site['detail'] !== '') {
                echo '<p class="wc-license-dashboard__site-copy wc-license-dashboard__site-copy--muted">' . esc_html($site['detail']) . '</p>';
            }

            echo '<div class="wc-license-dashboard__site-stats">';
            echo '<div><small>' . esc_html__('First seen', 'wc-product-license') . '</small><strong>' . esc_html($site['first_seen']) . '</strong></div>';
            echo '<div><small>' . esc_html__('Last seen', 'wc-product-license') . '</small><strong>' . esc_html($site['last_seen']) . '</strong></div>';
            echo '<div><small>' . esc_html__('Days active', 'wc-product-license') . '</small><strong>' . esc_html(number_format_i18n($site['days_active'])) . '</strong></div>';
            echo '<div><small>' . esc_html__('Requests', 'wc-product-license') . '</small><strong>' . esc_html(number_format_i18n($site['requests'])) . '</strong></div>';
            echo '</div>';

            echo '<div class="wc-license-dashboard__site-actions">';
            echo '<a href="' . esc_url($site['manage_url']) . '" class="button button-primary">' . esc_html__('Details', 'wc-product-license') . '</a>';
            if (!empty($site['open_url'])) {
                echo '<a href="' . esc_url($site['open_url']) . '" target="_blank" rel="noreferrer" class="button button-secondary">' . esc_html__('Open Site', 'wc-product-license') . '</a>';
            }
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
    }

    private function render_deactivation_cards($sites, $empty_message)
    {
        if (empty($sites)) {
            echo '<div class="wc-license-dashboard__empty">' . esc_html($empty_message) . '</div>';
            return;
        }

        echo '<div class="wc-license-dashboard__site-grid">';
        foreach ($sites as $site) {
            echo '<article class="wc-license-dashboard__site-card wc-license-dashboard__site-card--inactive">';
            echo '<div class="wc-license-dashboard__site-card-top">';
            echo '<div>';
            echo '<strong><a href="' . esc_url($site['manage_url']) . '">' . esc_html($site['label']) . '</a></strong>';
            echo '<span>' . esc_html($site['url']) . '</span>';
            echo '</div>';
            echo '<span class="wc-license-dashboard__status wc-license-dashboard__status--inactive">' . esc_html__('Inactive', 'wc-product-license') . '</span>';
            echo '</div>';

            echo '<p class="wc-license-dashboard__site-copy"><strong>' . esc_html__('Reason:', 'wc-product-license') . '</strong> ' . esc_html($site['reason']) . '</p>';
            if ($site['note'] !== '') {
                echo '<p class="wc-license-dashboard__site-copy wc-license-dashboard__site-copy--muted">' . esc_html($site['note']) . '</p>';
            }
            if ($site['environment'] !== '') {
                echo '<p class="wc-license-dashboard__site-copy wc-license-dashboard__site-copy--muted">' . esc_html($site['environment']) . '</p>';
            }
            if ($site['detail'] !== '') {
                echo '<p class="wc-license-dashboard__site-copy wc-license-dashboard__site-copy--muted">' . esc_html($site['detail']) . '</p>';
            }

            echo '<div class="wc-license-dashboard__site-actions">';
            echo '<span class="wc-license-dashboard__site-timestamp">' . esc_html($site['last_seen']) . '</span>';
            echo '<a href="' . esc_url($site['manage_url']) . '" class="button button-secondary">' . esc_html__('Details', 'wc-product-license') . '</a>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
    }

    private function get_requested_product_id()
    {
        return isset($_GET['product_id']) ? absint(wp_unslash($_GET['product_id'])) : 0;
    }

    private function get_requested_tab()
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $allowed = ['overview', 'sites', 'deactivations'];

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function get_dashboard_scope($product_id, $product_options)
    {
        if ($product_id > 0) {
            $product_option = isset($product_options[$product_id]) ? $product_options[$product_id] : null;
            $name = $product_option ? $product_option['name'] : wc_product_license_get_product_label($product_id);

            return [
                'id' => $product_id,
                'name' => $name,
                'description' => sprintf(__('Inspect install telemetry, environment drift, deactivation feedback, and compatibility signals captured for %s.', 'wc-product-license'), $name),
                'edit_url' => $product_option ? $product_option['edit_url'] : admin_url('post.php?post=' . $product_id . '&action=edit'),
            ];
        }

        return [
            'id' => 0,
            'name' => __('All licensed products', 'wc-product-license'),
            'description' => __('Monitor live installations, environment trends, deactivation feedback, and compatibility signals collected from every licensed product in the catalog.', 'wc-product-license'),
            'edit_url' => '',
        ];
    }

    private function get_dashboard_tabs($product_id, $summary)
    {
        return [
            'overview' => [
                'label' => __('Overview', 'wc-product-license'),
                'meta' => __('Charts and compatibility mix', 'wc-product-license'),
                'url' => wc_product_license_get_dashboard_url(array_filter([
                    'product_id' => $product_id,
                    'tab' => 'overview',
                ])),
            ],
            'sites' => [
                'label' => __('Sites', 'wc-product-license'),
                'meta' => sprintf(_n('%d site record', '%d site records', $summary['total'], 'wc-product-license'), $summary['total']),
                'url' => wc_product_license_get_dashboard_url(array_filter([
                    'product_id' => $product_id,
                    'tab' => 'sites',
                ])),
            ],
            'deactivations' => [
                'label' => __('Deactivations', 'wc-product-license'),
                'meta' => sprintf(_n('%d archived install', '%d archived installs', $summary['inactive'], 'wc-product-license'), $summary['inactive']),
                'url' => wc_product_license_get_dashboard_url(array_filter([
                    'product_id' => $product_id,
                    'tab' => 'deactivations',
                ])),
            ],
        ];
    }

    private function get_summary_cards($product_id, $summary, $linked_licenses, $products_tracked)
    {
        return [
            [
                'label' => __('Tracked sites', 'wc-product-license'),
                'value' => number_format_i18n($summary['total']),
                'description' => $product_id > 0
                    ? __('Every site record captured for the selected product.', 'wc-product-license')
                    : __('Every active and historical site record stored in telemetry.', 'wc-product-license'),
            ],
            [
                'label' => __('Active installs', 'wc-product-license'),
                'value' => number_format_i18n($summary['active']),
                'description' => __('Sites currently consuming a live activation.', 'wc-product-license'),
            ],
            [
                'label' => $product_id > 0 ? __('Linked licenses', 'wc-product-license') : __('Products tracked', 'wc-product-license'),
                'value' => number_format_i18n($product_id > 0 ? $linked_licenses : $products_tracked),
                'description' => $product_id > 0
                    ? __('Unique license keys tied to the scoped product telemetry.', 'wc-product-license')
                    : __('Licensed products currently represented in telemetry.', 'wc-product-license'),
            ],
            [
                'label' => $product_id > 0 ? __('Deactivations', 'wc-product-license') : __('New in 30 days', 'wc-product-license'),
                'value' => number_format_i18n($product_id > 0 ? $summary['inactive'] : $summary['new_30']),
                'description' => $product_id > 0
                    ? __('Archived or deactivated sites retained for support history.', 'wc-product-license')
                    : __('Fresh installations first seen in the last 30 days.', 'wc-product-license'),
            ],
        ];
    }

    private function get_chart_panels()
    {
        return [
            ['canvas_id' => 'wc-license-chart-status', 'eyebrow' => __('Adoption', 'wc-product-license'), 'title' => __('Active vs inactive installs', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-trend', 'eyebrow' => __('Momentum', 'wc-product-license'), 'title' => __('New installs in the last 30 days', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-multisite', 'eyebrow' => __('Topology', 'wc-product-license'), 'title' => __('Multisite vs single site', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-wp', 'eyebrow' => __('Platform', 'wc-product-license'), 'title' => __('Top WordPress versions', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-php', 'eyebrow' => __('Runtime', 'wc-product-license'), 'title' => __('Top PHP versions', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-themes', 'eyebrow' => __('Frontend', 'wc-product-license'), 'title' => __('Most active themes', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-plugin-version', 'eyebrow' => __('Rollout', 'wc-product-license'), 'title' => __('Installed plugin versions', 'wc-product-license')],
            ['canvas_id' => 'wc-license-chart-reasons', 'eyebrow' => __('Churn', 'wc-product-license'), 'title' => __('Deactivation reasons', 'wc-product-license')],
        ];
    }

    private function get_breakdowns($product_id, $product_breakdown, $site_scope_counts, $owner_type_counts, $license_channel_counts, $plugin_inventory, $mysql_versions, $server_software)
    {
        return [
            [
                'title' => $product_id > 0 ? __('Site scopes', 'wc-product-license') : __('Top licensed products', 'wc-product-license'),
                'items' => $this->format_breakdown_items($product_id > 0 ? $site_scope_counts : $product_breakdown),
            ],
            [
                'title' => __('Owner types', 'wc-product-license'),
                'items' => $this->format_breakdown_items($owner_type_counts),
            ],
            [
                'title' => __('License channels', 'wc-product-license'),
                'items' => $this->format_breakdown_items($license_channel_counts),
            ],
            [
                'title' => __('Top plugins used alongside', 'wc-product-license'),
                'items' => $this->format_breakdown_items($plugin_inventory),
            ],
            [
                'title' => __('MySQL versions', 'wc-product-license'),
                'items' => $this->format_breakdown_items($mysql_versions),
            ],
            [
                'title' => __('Server software', 'wc-product-license'),
                'items' => $this->format_breakdown_items($server_software),
            ],
        ];
    }

    private function build_chart_config($type, $labels, $data, $colors)
    {
        return [
            'type' => $type,
            'data' => [
                'labels' => array_values($labels),
                'datasets' => [[
                    'label' => '',
                    'data' => array_values($data),
                    'backgroundColor' => count($colors) === 1 ? array_fill(0, count($data), $colors[0]) : array_values($colors),
                    'borderRadius' => $type === 'bar' ? 10 : 0,
                    'maxBarThickness' => 28,
                ]],
            ],
        ];
    }

    private function format_breakdown_items($items)
    {
        if (!is_array($items)) {
            return [];
        }

        $items = array_slice($items, 0, 8, true);
        $formatted = [];

        foreach ($items as $label => $value) {
            $formatted[] = [
                'label' => (string) $label,
                'value' => number_format_i18n((int) $value),
            ];
        }

        return $formatted;
    }

    private function format_scope_label($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function get_site_host_label($site_url)
    {
        $host = wp_parse_url((string) $site_url, PHP_URL_HOST);

        return $host ? (string) $host : (string) $site_url;
    }

    private function format_datetime($datetime)
    {
        if (empty($datetime)) {
            return __('Not recorded', 'wc-product-license');
        }

        $timestamp = strtotime((string) $datetime);
        if (!$timestamp) {
            return __('Not recorded', 'wc-product-license');
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
