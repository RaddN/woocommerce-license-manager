<?php
/**
 * Plugin Deactivation Feedback
 * 
 * Adds a popup to collect feedback when the plugin is deactivated
 * and sends the data to a remote endpoint.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Plugin_Deactivation_Feedback {
    
    private $plugin_name;
    private $plugin_slug;
    
    public function __construct($plugin_name, $plugin_slug) {
        $this->plugin_name = $plugin_name;
        $this->plugin_slug = $plugin_slug;
        
        // Hook into the deactivation process
        add_action('admin_footer', array($this, 'deactivation_modal'));
        add_action('wp_ajax_plugin_deactivation_feedback', array($this, 'submit_feedback'));
    }
    
    /**
     * Add the modal HTML and JS to collect feedback
     */
    public function deactivation_modal() {
        // Only load on plugins page
        $current_screen = get_current_screen();
        if ($current_screen->id !== 'plugins') {
            return;
        }
        
        // Plugin data for the current page
        $plugin_file = $this->plugin_slug;
        ?>
        <div id="plugin-deactivation-feedback-modal" class="plugin-deactivation-feedback-modal" style="display:none;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><?php echo esc_html__('Why are you deactivating ' . $this->plugin_name . '?', 'plugin-domain'); ?></h3>
                    </div>
                    <div class="modal-body">
                        <form id="plugin-deactivation-feedback-form">
                            <div class="form-group">
                                <label>
                                    <input type="radio" name="deactivation_reason" value="temporary_deactivation">
                                    <?php esc_html_e('I\'m temporarily deactivating', 'plugin-domain'); ?>
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="radio" name="deactivation_reason" value="found_better_plugin">
                                    <?php esc_html_e('I found a better plugin', 'plugin-domain'); ?>
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="radio" name="deactivation_reason" value="not_working">
                                    <?php esc_html_e('The plugin didn\'t work as expected', 'plugin-domain'); ?>
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="radio" name="deactivation_reason" value="other">
                                    <?php esc_html_e('Other reason', 'plugin-domain'); ?>
                                </label>
                                <textarea name="deactivation_reason_other" placeholder="<?php esc_attr_e('Please specify', 'plugin-domain'); ?>" style="display:none; width:100%; margin-top:5px;"></textarea>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button id="plugin-deactivation-submit-form" class="button button-primary">
                            <?php esc_html_e('Submit & Deactivate', 'plugin-domain'); ?>
                        </button>
                        <button id="plugin-deactivation-skip" class="button">
                            <?php esc_html_e('Skip & Deactivate', 'plugin-domain'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .plugin-deactivation-feedback-modal {
                position: fixed;
                z-index: 100000;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
            }
            .plugin-deactivation-feedback-modal .modal-dialog {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 500px;
                max-width: 90%;
                background: #fff;
                border-radius: 4px;
            }
            .plugin-deactivation-feedback-modal .modal-content {
                padding: 20px;
            }
            .plugin-deactivation-feedback-modal .modal-header {
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            .plugin-deactivation-feedback-modal .modal-footer {
                border-top: 1px solid #eee;
                padding-top: 15px;
                margin-top: 15px;
                text-align: right;
            }
            .plugin-deactivation-feedback-modal .form-group {
                margin-bottom: 10px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Store the reference to the deactivation link
            var deactivationUrl = '';
            var pluginSlug = '<?php echo esc_js($this->plugin_slug); ?>';
            var pluginBaseFile = pluginSlug.split('/')[0];
            
            // Fix: Use a more reliable selector for the deactivation link
            // This will work even if WordPress changes its HTML structure slightly
            $('tr.deactivate').each(function() {
                var row = $(this);
                if (row.data('plugin') === pluginSlug || 
                    row.find('.plugin-title strong').text().indexOf('<?php echo esc_js($this->plugin_name); ?>') >= 0 ||
                    row.data('slug') === pluginBaseFile) {
                    
                    // Found the right plugin row
                    row.find('.deactivate a').on('click', function(e) {
                        e.preventDefault();
                        deactivationUrl = $(this).attr('href');
                        $('#plugin-deactivation-feedback-modal').show();
                    });
                }
            });
            
            // Alternate method - attach to all deactivation links and check if it's our plugin
            $(document).on('click', '.wp-list-table .deactivate a', function(e) {
                var $row = $(this).closest('tr');
                var rowPlugin = $row.data('plugin');
                
                // Check if this is our plugin's deactivation link
                if (rowPlugin === pluginSlug || 
                    $row.find('.plugin-title strong').text().indexOf('<?php echo esc_js($this->plugin_name); ?>') >= 0 ||
                    $row.data('slug') === pluginBaseFile) {
                    e.preventDefault();
                    deactivationUrl = $(this).attr('href');
                    $('#plugin-deactivation-feedback-modal').show();
                }
            });
            
            // Handle "other" reason toggle
            $('input[name="deactivation_reason"]').on('change', function() {
                if ($(this).val() === 'other') {
                    $('textarea[name="deactivation_reason_other"]').show();
                } else {
                    $('textarea[name="deactivation_reason_other"]').hide();
                }
            });
            
            // Handle form submission
            $('#plugin-deactivation-submit-form').on('click', function() {
                // Get the selected reason
                var reason = $('input[name="deactivation_reason"]:checked').val();
                var otherReason = $('textarea[name="deactivation_reason_other"]').val();
                
                // Use a default reason if none selected
                if (!reason) {
                    reason = 'no_reason_given';
                }
                
                // If "other" is selected but no text is entered, use a default message
                if (reason === 'other' && !otherReason) {
                    otherReason = 'No specific reason provided';
                }
                
                // Prepare the data to send
                var data = {
                    action: 'plugin_deactivation_feedback',
                    plugin_slug: pluginSlug,
                    reason: reason,
                    other_reason: otherReason,
                    nonce: '<?php echo wp_create_nonce('plugin_deactivation_feedback'); ?>'
                };
                
                // Send feedback via AJAX
                $.post(ajaxurl, data, function() {
                    // Redirect to the deactivation URL after the feedback is submitted
                    if (deactivationUrl) {
                        window.location.href = deactivationUrl;
                    }
                });
            });
            
            // Handle skip button
            $('#plugin-deactivation-skip').on('click', function() {
                // Just redirect to the deactivation URL
                if (deactivationUrl) {
                    window.location.href = deactivationUrl;
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Process the feedback submission via AJAX
     */
    public function submit_feedback() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'plugin_deactivation_feedback')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Get reason data
        $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
        $other_reason = isset($_POST['other_reason']) ? sanitize_textarea_field($_POST['other_reason']) : '';
        
        // Format the final reason text
        $deactivation_reason = $reason;
        if ($reason === 'other' && !empty($other_reason)) {
            $deactivation_reason = $other_reason;
        }
        
        // Prepare data to send
        $data = array(
            'site_url' => site_url(),
            'product_name' => $this->plugin_name,
            'deactivation_reason' => $deactivation_reason
        );
        
        // Send the data to your API endpoint
        $response = wp_remote_post(
            'https://wppluginzone.com/wp-json/wc-license-manager/v1/tracking/deactivate',
            array(
                'method' => 'POST',
                'timeout' => 30,
                'sslverify' => false,
                'body' => $data,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded'
                )
            )
        );
        
        // Log errors for debugging (optional)
        if (is_wp_error($response)) {
            error_log('Deactivation feedback error: ' . $response->get_error_message());
        }
        
        // Send success response regardless of remote API response
        wp_send_json_success('Feedback submitted');
        exit;
    }
}

/**
 * Usage Example:
 * 
 * // Initialize the feedback system in your main plugin file
 * function initialize_deactivation_feedback() {
 *     new Plugin_Deactivation_Feedback('WooCommerce Product License Manager', 'your-plugin-folder/your-plugin-main-file.php');
 * }
 * add_action('plugins_loaded', 'initialize_deactivation_feedback');
 */