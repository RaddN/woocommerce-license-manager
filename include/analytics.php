<?php

/**
 * Render the license analytics page
 */
class WC_License_Analytics
{


    /**
     * Add the analytics page to the admin menu
     */
    public function render_analytics_page()
    {
        // Enqueue required scripts
        wp_enqueue_script('chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js', [], '3.9.1', true);
        wp_enqueue_style('wc-license-analytics-css', plugin_dir_url(__FILE__) . '../assets/css/analytics.css', [], '1.0.0');
        wp_enqueue_script('wc-license-analytics-js', plugin_dir_url(__FILE__) . '../assets/js/analytics.js', ['jquery', 'chart-js'], '1.0.0', true);

        // Get activation tracking data
        $activation_data = get_option('wc_license_activation_tracking', []);
        $deactivation_data = get_option('wc_license_deactivation_tracking', []);

        // Calculate metrics
        $total_installed = count($activation_data) + count($deactivation_data);
        $currently_active = count($activation_data);
        $deactivated = count($deactivation_data);
        $deactivation_ratio = $total_installed > 0 ? round(($deactivated / $total_installed) * 100, 2) : 0;

        // Prepare chart data
        $chart_data = $this->prepare_analytics_chart_data($activation_data, $deactivation_data);

        // Localize script with chart data
        wp_localize_script('wc-license-analytics-js', 'wcLicenseAnalytics', $chart_data);

        // Start output buffer
        ob_start();
?>
        <div class="wrap wc-license-analytics">
            <h1 style="margin-bottom: .5em;"><?php _e('Overview', 'wc-product-license'); ?></h1>

            <div class="wc-license-analytics-overview">
                <div class="analytics-card total-installed">
                    <div class="card-content">
                        <h2><?php echo esc_html($total_installed); ?></h2>
                        <p><?php _e('Total Installed', 'wc-product-license'); ?></p>
                    </div>
                    <div class="overview-item-icon"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="112.001" height="40" viewBox="0 0 112.001 40"><defs><linearGradient id="svg-linear-left" x1="0.5" x2="0.5" y2="1" gradientUnits="objectBoundingBox"><stop offset="0" stop-color="#5ee2a0"></stop><stop offset="1" stop-color="#5ee2a0" stop-opacity="0.502"></stop></linearGradient></defs><path d="M98,119V101h6v18Zm-9,0V89h8v30Zm-9,0V99h8v20Zm-9,0V106h8v13Zm-9,0V94h8v25Zm-9,0V84h8v35Zm-9,0V94h8v25Zm-9,0V99h8v20Zm-9,0V79h8v40Zm-9,0V89h8v30Zm-7,0V101h6v18Zm-9,0V89H9v30Zm-9,0V99H0v20Z" transform="translate(8 -79)" fill="url(#svg-linear-left)"></path></svg></div>
                </div>
                <div class="analytics-card currently-active">
                    <div class="card-content">
                        <h2><?php echo esc_html($currently_active); ?></h2>
                        <p><?php _e('Currently Active', 'wc-product-license'); ?></p>
                    </div>
                    <div class="overview-item-icon"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="112" height="40" viewBox="0 0 112 40"><defs><linearGradient id="svg-linear-middle" x1="0.5" x2="0.5" y2="1" gradientUnits="objectBoundingBox"><stop offset="0" stop-color="#f56565"></stop><stop offset="1" stop-color="#fff5f5"></stop></linearGradient></defs><path d="M98,119V101h6v18Zm-9,0V89h8v30Zm-9,0V99h8v20Zm-9,0V106h8v13Zm-9,0V94h8v25Zm-9,0V84h8v35Zm-9,0V94h8v25Zm-9,0V99h8v20Zm-9,0V79h8v40Zm-9,0V89h8v30Zm-7,0V101h6v18Zm-9,0V89H9v30Zm-9,0V99H0v20Z" transform="translate(8 -79)" fill="url(#svg-linear-middle)"></path></svg></div>
                </div>
                <div class="analytics-card deactivated-ratio">
                    <div class="card-content">
                        <h2><?php echo esc_html($deactivation_ratio); ?>%</h2>
                        <p><?php _e('Deactivation Ratio', 'wc-product-license'); ?></p>
                    </div>
                    <div class="overview-item-icon"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="112" height="40" viewBox="0 0 112 40"><defs><linearGradient id="svg-linear-right" x1="0.5" x2="0.5" y2="1" gradientUnits="objectBoundingBox"><stop offset="0" stop-color="#a3a0fb"></stop><stop offset="1" stop-color="#a3a0fb" stop-opacity="0.502"></stop></linearGradient></defs><path d="M98,119V101h6v18Zm-9,0V89h8v30Zm-9,0V99h8v20Zm-9,0V106h8v13Zm-9,0V94h8v25Zm-9,0V84h8v35Zm-9,0V94h8v25Zm-9,0V99h8v20Zm-9,0V79h8v40Zm-9,0V89h8v30Zm-7,0V101h6v18Zm-9,0V89H9v30Zm-9,0V99H0v20Z" transform="translate(8 -79)" fill="url(#svg-linear-right)"></path></svg></div>
                </div>
            </div>

            <div class="wc-license-analytics-charts">
                <!-- Active vs Deactive & Multisite charts -->
                <div class="chart-row">
                    <div class="chart-container">
                        <h3><?php _e('Active vs Deactivated', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="active-deactive-chart"></canvas>
                        </div>
                    </div>
                    <div class="chart-container">
                        <h3><?php _e('Multisite vs Single Site', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="multisite-chart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- WordPress & MySQL version charts -->
                <div class="chart-row">
                    <div class="chart-container">
                        <h3><?php _e('WordPress Versions', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="wordpress-versions-chart"></canvas>
                        </div>
                    </div>
                    <div class="chart-container">
                        <h3><?php _e('MySQL Versions', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="mysql-versions-chart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- PHP & Server Software charts -->
                <div class="chart-row">
                    <div class="chart-container">
                        <h3><?php _e('PHP Versions', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="php-versions-chart"></canvas>
                        </div>
                    </div>
                    <div class="chart-container">
                        <h3><?php _e('Server Software', 'wc-product-license'); ?></h3>
                        <div class="chart-wrapper">
                            <canvas id="server-software-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Deactivation Reasons -->
            <div class="wc-license-deactivation-reasons">
                <h3><?php _e('Recent Deactivation Reasons', 'wc-product-license'); ?></h3>
                <?php if (!empty($deactivation_data)): ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Site URL', 'wc-product-license'); ?></th>
                                <th><?php _e('Reason', 'wc-product-license'); ?></th>
                                <th><?php _e('Date', 'wc-product-license'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sort deactivation data by timestamp, newest first
                            usort($deactivation_data, function ($a, $b) {
                                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
                            });

                            // Show only the latest 20 reasons
                            $latest_reasons = array_slice($deactivation_data, 0, 20);

                            foreach ($latest_reasons as $data):
                            ?>
                                <tr>
                                    <td><?php echo esc_html($data['site_url']); ?></td>
                                    <td><?php echo esc_html($data['deactivation_reason']); ?></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($data['timestamp']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No deactivation data available yet.', 'wc-product-license'); ?></p>
                <?php endif; ?>
            </div>
        </div>
<?php
        echo ob_get_clean();
    }

    /**
     * Prepare chart data for analytics
     */
    private function prepare_analytics_chart_data($activation_data, $deactivation_data)
    {
        // Active vs Deactive
        $active_count = count($activation_data);
        $deactive_count = count($deactivation_data);

        // Multisite vs Single Site
        $multisite_count = 0;
        $singlesite_count = 0;
        foreach ($activation_data as $data) {
            if (isset($data['multisite']) && $data['multisite'] === 'yes') {
                $multisite_count++;
            } else {
                $singlesite_count++;
            }
        }

        // WordPress Versions
        $wp_versions = [];
        foreach ($activation_data as $data) {
            if (!empty($data['wordpress_version'])) {
                $version = $this->get_major_version($data['wordpress_version']);
                if (!isset($wp_versions[$version])) {
                    $wp_versions[$version] = 0;
                }
                $wp_versions[$version]++;
            }
        }

        // MySQL Versions
        $mysql_versions = [];
        foreach ($activation_data as $data) {
            if (!empty($data['mysql_version'])) {
                $version = $this->get_major_version($data['mysql_version']);
                if (!isset($mysql_versions[$version])) {
                    $mysql_versions[$version] = 0;
                }
                $mysql_versions[$version]++;
            }
        }

        // PHP Versions
        $php_versions = [];
        foreach ($activation_data as $data) {
            if (!empty($data['php_version'])) {
                $version = $this->get_major_version($data['php_version']);
                if (!isset($php_versions[$version])) {
                    $php_versions[$version] = 0;
                }
                $php_versions[$version]++;
            }
        }

        // Server Software
        $server_software = [];
        foreach ($activation_data as $data) {
            if (!empty($data['server_software'])) {
                $software = $this->get_server_software_name($data['server_software']);
                if (!isset($server_software[$software])) {
                    $server_software[$software] = 0;
                }
                $server_software[$software]++;
            }
        }

        return [
            'activeVsDeactive' => [
                'labels' => [__('Active', 'wc-product-license'), __('Deactivated', 'wc-product-license')],
                'data' => [$active_count, $deactive_count],
                'backgroundColor' => ['#4CAF50', '#F44336']
            ],
            'multisite' => [
                'labels' => [__('Multisite', 'wc-product-license'), __('Single Site', 'wc-product-license')],
                'data' => [$multisite_count, $singlesite_count],
                'backgroundColor' => ['#2196F3', '#FFC107']
            ],
            'wordpressVersions' => [
                'labels' => array_keys($wp_versions),
                'data' => array_values($wp_versions),
                'backgroundColor' => $this->generate_colors(count($wp_versions))
            ],
            'mysqlVersions' => [
                'labels' => array_keys($mysql_versions),
                'data' => array_values($mysql_versions),
                'backgroundColor' => $this->generate_colors(count($mysql_versions))
            ],
            'phpVersions' => [
                'labels' => array_keys($php_versions),
                'data' => array_values($php_versions),
                'backgroundColor' => $this->generate_colors(count($php_versions))
            ],
            'serverSoftware' => [
                'labels' => array_keys($server_software),
                'data' => array_values($server_software),
                'backgroundColor' => $this->generate_colors(count($server_software))
            ]
        ];
    }

    /**
     * Extract major version from version string
     */
    private function get_major_version($version)
    {
        // Extract major version (e.g., "5.8.1" becomes "5.8")
        preg_match('/^(\d+\.\d+)/', $version, $matches);
        return isset($matches[1]) ? $matches[1] : $version;
    }

    /**
     * Extract server software name from server signature
     */
    private function get_server_software_name($server)
    {
        // Extract server name (e.g., "Apache/2.4.41 (Ubuntu)" becomes "Apache")
        preg_match('/^([a-zA-Z]+)\//', $server, $matches);
        return isset($matches[1]) ? $matches[1] : $server;
    }

    /**
     * Generate an array of colors for charts
     */
    private function generate_colors($count)
    {
        $colors = [
            '#4CAF50',
            '#F44336',
            '#2196F3',
            '#FFC107',
            '#9C27B0',
            '#00BCD4',
            '#FF9800',
            '#795548',
            '#607D8B',
            '#3F51B5',
            '#8BC34A',
            '#FF5722',
            '#CDDC39',
            '#009688',
            '#E91E63'
        ];

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = $colors[$i % count($colors)];
        }

        return $result;
    }
}
