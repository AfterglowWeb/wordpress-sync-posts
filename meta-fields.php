<?php
/**
 * ACF Fields Sync Extension with Simple Payment Integration
 */

defined('ABSPATH') || exit;

define('SYNC_POSTS_STRIPE_CHECKOUT_URL', esc_url(get_option('sync_posts_stripe_checkout_url')));

class SyncPostsAcfExtension {
    private static $instance = null;
    
    private $license_status;
    
    private function __construct() {
        $this->license_status = get_option('sync_posts_acf_license', [
            'status' => 'inactive',
            'token' => '',
            'site_id' => $this->get_site_identifier(),
            'last_verified' => ''
        ]);
        
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_license_page'], 20);
        add_action('admin_notices', [$this, 'license_notice']);
        add_action('admin_init', [$this, 'handle_license_activation']);
        add_action('init', [$this, 'maybe_verify_license']);
        add_action('init', function() {
            add_filter('sync_posts_after_post_sync', [$this, 'sync_acf_fields'], 10, 3);
        });
    }
    
    private function get_site_identifier() {
        $site_url = get_site_url();
        return md5($site_url . 'sync_posts_acf_extension');
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register_settings() {
        register_setting('sync_posts_license_settings_group', 'sync_posts_acf_license');
        
        add_settings_section(
            'sync_posts_license_section',
            __('License Management', 'sync-posts'),
            [$this, 'license_section_callback'],
            'sync-posts-license'
        );
    }
    
    public function add_license_page() {
        add_submenu_page(
            'tools.php',
            __('Sync Posts License', 'sync-posts'),
            __('Sync Posts License', 'sync-posts'),
            'manage_options',
            'sync-posts-license',
            [$this, 'render_license_page']
        );
    }
    
    public function license_section_callback() {
        echo '<p>' . __('Manage your ACF synchronization license.', 'sync-posts') . '</p>';
    }
    
    public function render_license_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $this->handle_stripe_return();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if ($this->is_license_active()): ?>
                <div class="notice notice-success">
                    <p><?php _e('Your ACF Sync license is active.', 'sync-posts'); ?></p>
                </div>
                
                <h2><?php _e('License Information', 'sync-posts'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Status', 'sync-posts'); ?></th>
                        <td><?php echo $this->license_status['status']; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Site ID', 'sync-posts'); ?></th>
                        <td><?php echo $this->license_status['site_id']; ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Last Verified', 'sync-posts'); ?></th>
                        <td>
                            <?php 
                            if (!empty($this->license_status['last_verified'])) {
                                echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($this->license_status['last_verified']));
                            } else {
                                _e('Not yet verified', 'sync-posts');
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <form method="post" action="">
                    <?php wp_nonce_field('sync_posts_deactivate_license'); ?>
                    <input type="hidden" name="action" value="sync_posts_deactivate_license">
                    <p>
                        <input type="submit" name="submit" class="button button-secondary" value="<?php _e('Deactivate License', 'sync-posts'); ?>">
                    </p>
                </form>
                
            <?php else: ?>
                <div class="notice notice-warning">
                    <p><?php _e('Your ACF Sync license is inactive. Please purchase a license to enable ACF field synchronization.', 'sync-posts'); ?></p>
                </div>
                
                <h2><?php _e('Purchase License', 'sync-posts'); ?></h2>
                <p><?php _e('ACF field synchronization is a premium feature. Purchase a license to enable this functionality.', 'sync-posts'); ?></p>
                
                <a href="<?php // echo esc_url(SYNC_POSTS_STRIPE_CHECKOUT_URL); ?>" class="button button-primary" target="_blank">
                    <?php _e('Purchase License', 'sync-posts'); ?>
                </a>
                <p class="description"><?php _e('After completing the purchase, you will receive a license key via email.', 'sync-posts'); ?></p>
                
                <h2><?php _e('Enter License Key', 'sync-posts'); ?></h2>
                <p><?php _e('If you already purchased a license, enter your license key below:', 'sync-posts'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('sync_posts_activate_license'); ?>
                    <input type="hidden" name="action" value="sync_posts_activate_license">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('License Key:', 'sync-posts'); ?></th>
                            <td>
                                <input type="text" name="license_key" class="regular-text" required>
                                <p class="description"><?php _e('Enter the license key you received after purchase.', 'sync-posts'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="submit" class="button button-primary" value="<?php _e('Activate License', 'sync-posts'); ?>">
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function handle_stripe_return() {
        if (!isset($_GET['stripe_return'])) {
            return;
        }
        
        $return_status = $_GET['stripe_return'];
        
        if ($return_status === 'success') {
            add_settings_error(
                'sync_posts_license',
                'payment_success',
                __('Payment successful! Please check your email for the license key.', 'sync-posts'),
                'success'
            );
        } elseif ($return_status === 'cancel') {
            add_settings_error(
                'sync_posts_license',
                'payment_cancelled',
                __('Payment process was cancelled.', 'sync-posts'),
                'error'
            );
        }
    }
    
    public function maybe_verify_license() {
        if ($this->license_status['status'] !== 'active') {
            return;
        }
        
        $last_verified = $this->license_status['last_verified'] ? strtotime($this->license_status['last_verified']) : 0;
        $one_day_ago = time() - (24 * 60 * 60);
        
        if ($last_verified < $one_day_ago) {
            $this->verify_license_with_server();
        }
    }
    
    private function verify_license_with_server() {
        
        $this->license_status['last_verified'] = current_time('mysql');

        $new_token = $this->generate_license_token();
        $this->license_status['token'] = $new_token;
        
        update_option('sync_posts_acf_license', $this->license_status);
    }
    
    private function generate_license_token() {
        return wp_generate_password(32, false);
    }
    
    public function handle_license_activation() {
        if (!isset($_POST['action']) || $_POST['action'] !== 'sync_posts_activate_license') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'sync-posts'));
        }
        
        check_admin_referer('sync_posts_activate_license');
        
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            add_settings_error(
                'sync_posts_license',
                'invalid_license',
                __('Please enter a license key.', 'sync-posts'),
                'error'
            );
            return;
        }
        
        if (strlen($license_key) < 20) {
            add_settings_error(
                'sync_posts_license',
                'invalid_license',
                __('Invalid license key format.', 'sync-posts'),
                'error'
            );
            return;
        }
        
        $this->license_status = [
            'status' => 'active',
            'token' => $this->generate_license_token(),
            'site_id' => $this->get_site_identifier(),
            'last_verified' => current_time('mysql')
        ];
        
        update_option('sync_posts_acf_license', $this->license_status);
        
        add_settings_error(
            'sync_posts_license',
            'license_activated',
            __('License activated successfully!', 'sync-posts'),
            'success'
        );
    }
    
    public function license_notice() {
        $screen = get_current_screen();
        
        if (!$screen || (strpos($screen->id, 'sync-posts') === false && $screen->parent_base !== 'tools')) {
            return;
        }
        
        if (!$this->is_license_active()) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php _e('ACF field synchronization requires a license. ', 'sync-posts'); ?>
                    <a href="<?php echo admin_url('tools.php?page=sync-posts-license'); ?>"><?php _e('Purchase a license', 'sync-posts'); ?></a>
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-success">
                <p>
                    <?php _e('Your ACF Sync license is active.', 'sync-posts'); ?>
                </p>
            </div>
            <?php
        }
    }
    
    public function is_license_active() {
        if ($this->license_status['status'] !== 'active') {
            return false;
        }
        
        return true;
    }
    
    public function sync_acf_fields($post_id, $remote_post, $api_data) {
        if (!function_exists('update_field') || !class_exists('ACF')) {
            return;
        }
        
        if (!$this->is_license_active()) {
            return;
        }
        
        if (!isset($remote_post['acf'])) {
            $source_url = trailingslashit($api_data['source_url']);
            $api_endpoint = $api_data['api_endpoint'];
            $post_type = $api_data['post_type'];
            
            $request_url = $source_url . 'wp-json/' . $api_endpoint . '/' . $post_type . 's/' . $remote_post['id'];
            $request_url = add_query_arg(['acf' => 1], $request_url);
            
            $response = wp_remote_get($request_url);
            
            if (is_wp_error($response)) {
                return;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                return;
            }
            
            $body = wp_remote_retrieve_body($response);
            $post_data = json_decode($body, true);
            
            if (!isset($post_data['acf']) || !is_array($post_data['acf'])) {
                return;
            }
            
            $acf_fields = $post_data['acf'];
        } else {
            $acf_fields = $remote_post['acf'];
        }
        
        foreach ($acf_fields as $field_key => $field_value) {
            update_field($field_key, $field_value, $post_id);
        }
        
        update_post_meta($post_id, '_sync_posts_acf_synced', 'yes');
        update_post_meta($post_id, '_sync_posts_acf_sync_date', current_time('mysql'));
    }
}


function sync_posts_acf_extension_activate() {
}
register_activation_hook(__FILE__, 'sync_posts_acf_extension_activate');

add_action('sync_posts_init', function($main_plugin) {
    if (class_exists('ACF')) {
        $extension = SyncPostsAcfExtension::get_instance();
        
        $main_plugin->register_extension('acf', [$extension, 'sync_acf_fields']);
    }
}, 20);
