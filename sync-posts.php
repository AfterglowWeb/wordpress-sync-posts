<?php
/**
 * Plugin Name: Sync Posts
 * Description: Syncs posts from another WordPress site using REST API. Syncs posts, media, and taxonomies. Downloads media and create featured images. Supports custom post types and taxonomies. Sync from a custom date or all posts.
 * Version: 1.0.5
 * Author: Cédric Moris Kelly
 * Author URI: http://moriskelly.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: sync-posts
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncPosts {
    private static $instance = null;
    
    private $options;
    
    private $extensions = [];
    
    private function __construct() {
        $this->options = get_option('sync_posts_settings', [
            'source_url' => '',
            'post_types' => ['post'],
            'api_endpoint' => 'wp/v2',
            'items_per_request' => 10,
            'date_limit' => '',
        ]);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_sync_posts_start', [$this, 'ajax_sync_posts']);

        add_filter('rest_attachment_query', function($args, $request) {
			return $args;
		}, 10, 2);
        add_action('rest_api_init', function (): void {
			register_rest_route('sync-posts/v1', '/public-media/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => [__CLASS__, 'get_public_media'],
				'permission_callback' => '__return_true', // Allow public access
				'args' => [
					'id' => [
						'validate_callback' => function($param) {
							return is_numeric($param);
						}
					]
				]
			]);

			register_rest_field('attachment', 'public_access', array(
				'get_callback' => function() { return true; },
				'schema' => array('type' => 'boolean'),
			));

		});
        do_action('sync_posts_init', $this);

    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    
    public function add_admin_menu() {
        add_management_page(
            __('Sync Posts', 'sync-posts'),
            __('Sync Posts', 'sync-posts'),
            'manage_options',
            'sync-posts',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings() {
        register_setting('sync_posts_settings_group', 'sync_posts_settings');
        
        add_settings_section(
            'sync_posts_main_section',
            __('Sync Settings', 'sync-posts'),
            [$this, 'settings_section_callback'],
            'sync-posts'
        );
        
        add_settings_field(
            'source_url',
            __('Source Site URL', 'sync-posts'),
            [$this, 'source_url_callback'],
            'sync-posts',
            'sync_posts_main_section'
        );
        
        add_settings_field(
            'post_types',
            __('Post Types', 'sync-posts'),
            [$this, 'post_types_callback'],
            'sync-posts',
            'sync_posts_main_section'
        );
        
        add_settings_field(
            'api_endpoint',
            __('API Endpoint', 'sync-posts'),
            [$this, 'api_endpoint_callback'],
            'sync-posts',
            'sync_posts_main_section'
        );
        
        add_settings_field(
            'items_per_request',
            __('Items Per Request', 'sync-posts'),
            [$this, 'items_per_request_callback'],
            'sync-posts',
            'sync_posts_main_section'
        );

        add_settings_field(
            'date_limit',
            __('Date Limit', 'sync-posts'),
            [$this, 'date_limit_callback'],
            'sync-posts',
            'sync_posts_main_section'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>' . __('Configure the source WordPress site to sync posts from.', 'sync-posts') . '</p>';
    }
    
    public function source_url_callback() {
        $value = isset($this->options['source_url']) ? $this->options['source_url'] : '';
        echo '<input type="url" id="source_url" name="sync_posts_settings[source_url]" value="' .esc_url($value) . '" class="regular-text" placeholder="https://example.com" />';
        echo '<p class="description">' . __('The URL of the WordPress site to sync from. Do not include trailing slash.', 'sync-posts') . '</p>';
    }
    
    public function post_types_callback() {
        $post_types = isset($this->options['post_types']) ? $this->options['post_types'] : ['post', 'page'];
        
        $available_post_types = get_post_types(['public' => true], 'objects');
        foreach ($available_post_types as $post_type) {
            $checked = in_array($post_type->name, $post_types) ? 'checked' : '';
            echo '<label><input type="checkbox" name="sync_posts_settings[post_types][]" value="' . esc_attr($post_type->name) . '" ' . $checked . ' /> ' . esc_html($post_type->label) . '</label><br />';
        }
    }

    public function api_endpoint_callback() {
        $value = isset($this->options['api_endpoint']) ? $this->options['api_endpoint'] : 'wp/v2';
        echo '<input type="text" id="api_endpoint" name="sync_posts_settings[api_endpoint]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The REST API endpoint. Default is "wp/v2".', 'sync-posts') . '</p>';
    }
    
    public function items_per_request_callback() {
        $value = isset($this->options['items_per_request']) ? $this->options['items_per_request'] : 10;
        echo '<input type="number" id="items_per_request" name="sync_posts_settings[items_per_request]" value="' . esc_attr($value) . '" class="small-text" min="1" max="100" />';
        echo '<p class="description">' . __('Number of items to fetch per request. Maximum 100.', 'sync-posts') . '</p>';
    }

    public function date_limit_callback() {
        $value = isset($this->options['date_limit']) ? $this->options['date_limit'] : '';
        echo '<input type="date" id="date_limit" name="sync_posts_settings[date_limit]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Only sync posts published after this date. Leave empty to sync all posts.', 'sync-posts') . '</p>';
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('tools_page_sync-posts' !== $hook) {
            return;
        }
        
        wp_enqueue_script('sync-posts-admin', plugin_dir_url(__FILE__) . 'sync-posts-admin.js', ['jquery'], filemtime(plugin_dir_path(__FILE__) . 'sync-posts-admin.js'), true);
        
        wp_localize_script('sync-posts-admin', 'syncPostsData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sync_posts_nonce'),
            'i18n' => [
                'syncing' => __('Syncing...', 'sync-posts'),
                'success' => __('Sync completed successfully!', 'sync-posts'),
                'error' => __('Error during sync: ', 'sync-posts'),
            ],
        ]);
    }
    
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('sync_posts_settings_group');
                do_settings_sections('sync-posts');
                submit_button(__('Save Settings', 'sync-posts'));
                ?>
            </form>
            
            <hr />
            
            <h2><?php _e('Start Sync', 'sync-posts'); ?></h2>
            <p><?php _e('Click the button below to start syncing posts from the source site.', 'sync-posts'); ?></p>
            
            <div id="sync-posts-status"></div>
            <div id="sync-posts-progress" style="display:none; margin-top: 10px;">
                <div id="sync-posts-progress-bar" style="background-color: #f0f0f0; border-radius: 3px; height: 20px;">
                    <div id="sync-posts-progress-bar-inner" style="background-color: #0073aa; height: 20px; width: 0%; border-radius: 3px;"></div>
                </div>
                <p id="sync-posts-progress-text">0%</p>
            </div>
            
            <button type="button" id="sync-posts-button" class="button button-primary"><?php _e('Start Sync', 'sync-posts'); ?></button>
        </div>
        <?php
    }
    
    public function ajax_sync_posts() {
        try {
            check_ajax_referer('sync_posts_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'sync-posts')]);
            }
            
            error_log('Starting post sync process');
            
            $post_types = isset($this->options['post_types']) ? $this->options['post_types'] : [];
            if (empty($post_types)) {
                wp_send_json_error(['message' => __('No post types configured for syncing.', 'sync-posts')]);
            }
            
            $post_type_index = isset($_POST['post_type_index']) ? intval($_POST['post_type_index']) : 0;
            if ($post_type_index >= count($post_types)) {
                $post_type_index = 0;
            }
            
            $post_type = $post_types[$post_type_index];
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            
            $source_url = trailingslashit($this->options['source_url']);
            $api_endpoint = $this->options['api_endpoint'];
            $per_page = intval($this->options['items_per_request']);
            
            $request_url = $source_url . 'wp-json/' . $api_endpoint . '/' . $post_type . 's';
            
            $query_args = [
                'per_page' => $per_page,
                'page' => $page,
                '_embed' => 'true',
            ];  
            
            if (!empty($this->options['date_limit'])) {
                $date_obj = new DateTime($this->options['date_limit']);
                if ($date_obj) {
                    $query_args['after'] = $date_obj->format('Y-m-d\T00:00:00');
                }
            }
            
            $request_url = add_query_arg($query_args, $request_url);
            
            error_log('Request URL: ' . $request_url);
            
            $response = wp_remote_get($request_url);
            
            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => $response->get_error_message(),
                ]);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                wp_send_json_error([
                    'message' => sprintf(__('Error fetching data. Status code: %d', 'sync-posts'), $response_code),
                ]);
            }
            
            $body = wp_remote_retrieve_body($response);
            $posts = json_decode($body, true);
            
            if (!is_array($posts)) {
                wp_send_json_error([
                    'message' => __('Invalid response from the source site.', 'sync-posts'),
                ]);
            }
            
            $total_posts = wp_remote_retrieve_header($response, 'X-WP-Total');
            $total_pages = wp_remote_retrieve_header($response, 'X-WP-TotalPages');
            
            $total_pages = intval($total_pages);
            if ($total_pages <= 0) {
                $total_pages = 1;
            }
            
            $synced_count = 0;
            $errors = [];
            
            foreach ($posts as $post) {
                $original_modified_date = isset($post['modified']) ? $post['modified'] : '';
                $original_modified_date_gmt = isset($post['modified_gmt']) ? $post['modified_gmt'] : '';
                
                $post_data = [
                    'post_title' => isset($post['title']['rendered']) ? $post['title']['rendered'] : '',
                    'post_content' => isset($post['content']['rendered']) ? $post['content']['rendered'] : '',
                    'post_excerpt' => isset($post['excerpt']['rendered']) ? $post['excerpt']['rendered'] : '',
                    'post_status' => 'draft',
                    'post_type' => $post_type,
                    'post_date' => isset($post['date']) ? $post['date'] : '',
                    'post_date_gmt' => isset($post['date_gmt']) ? $post['date_gmt'] : '',
                    'meta_input' => [
                        '_sync_posts_source_id' => $post['id'],
                        '_sync_posts_source_url' => $source_url,
                        '_sync_posts_last_sync' => current_time('mysql'),
                        '_sync_posts_original_modified' => $original_modified_date,
                    ],
                ];
                
                // Check if post already exists
                $existing_posts = get_posts([
                    'post_type' => $post_type,
                    'meta_key' => '_sync_posts_source_id',
                    'meta_value' => $post['id'],
                    'posts_per_page' => 1,
                ]);
                
                if (!empty($existing_posts)) {
                    $post_data['ID'] = $existing_posts[0]->ID;
                    $post_id = wp_update_post($post_data);
                } else {
                    $post_id = wp_insert_post($post_data);
                }
                
                if (is_wp_error($post_id)) {
                    $errors[] = $post_id->get_error_message();
                } else {
                    $synced_count++;
                    
                    if (!empty($post['featured_media'])) {
                        $this->sync_featured_image($post['featured_media'], $post_id, $source_url, $api_endpoint);
                    }
                    
                    $this->sync_taxonomies($post, $post_id, $post_type);
                    
                    if (!empty($original_modified_date)) {
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->posts,
                            [
                                'post_modified' => $original_modified_date,
                                'post_modified_gmt' => $original_modified_date_gmt,
                            ],
                            ['ID' => $post_id]
                        );
                    }
                    
                    $api_data = [
                        'source_url' => $source_url,
                        'api_endpoint' => $api_endpoint,
                        'post_type' => $post_type
                    ];
                    $this->process_extensions($post_id, $post, $api_data);
                    
                    apply_filters('sync_posts_after_post_sync', $post_id, $post, $api_data);
                }
            }
            
            $is_done = intval($page) >= intval($total_pages);
            
            wp_send_json_success([
                'page' => $page,
                'total_pages' => $total_pages,
                'total_posts' => $total_posts,
                'synced_count' => $synced_count,
                'errors' => $errors,
                'is_done' => $is_done,
                'post_type' => $post_type,
                'post_types' => $this->options['post_types'],
                'current_post_type_index' => array_search($post_type, $this->options['post_types']),
                'progress' => ($total_pages > 0) ? ($page / $total_pages) * 100 : 100, // Prevent division by zero
            ]);
        } catch (Exception $e) {
            error_log('Sync Posts Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Register a sync extension
     * 
     * @param string $name Extension name
     * @param callable $callback Function to call after post sync
     * @return void
     */
    public function register_extension($name, $callback) {
        if (is_callable($callback)) {
            $this->extensions[$name] = $callback;
        }
    }
    
    /**
     * Process post with registered extensions
     * 
     * @param int $post_id The post ID
     * @param array $remote_post The remote post data
     * @param array $api_data API connection data
     * @return void
     */
    private function process_extensions($post_id, $remote_post, $api_data) {
        foreach ($this->extensions as $name => $callback) {
            call_user_func($callback, $post_id, $remote_post, $api_data);
        }
    }
    
    private function sync_featured_image($media_id, $post_id, $source_url, $api_endpoint) {
        $request_url = $source_url . 'wp-json/sync-posts/public-media/' . $media_id;
        $response = wp_remote_get($request_url);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $media = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($media['source_url'])) {
            return false;
        }
        
        $image_url = $media['source_url'];
        $source_media_modified = isset($media['modified']) ? $media['modified'] : null;
        $upload = $this->download_and_attach_image($image_url, $post_id, $media_id, $source_media_modified);
        
        if ($upload && !is_wp_error($upload)) {
            set_post_thumbnail($post_id, $upload);
            return true;
        }
        
        return false;
    }

    private function download_and_attach_image($image_url, $post_id, $source_media_id, $source_media_modified = null) {
        global $wpdb;

        $existing_images = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_key' => '_sync_posts_source_media_id',
            'meta_value' => $source_media_id,
        ]);
        
        if (!empty($existing_images)) {
            return $existing_images[0]->ID;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $temp_file = download_url($image_url);
        
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $file_name = basename($image_url);
        $file_array = [
            'name' => $file_name,
            'tmp_name' => $temp_file,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        // Clean up the temporary file
        if (is_file($temp_file)) {
            @unlink($temp_file);
        }
        
        if (!is_wp_error($attachment_id)) {
            update_post_meta($attachment_id, '_sync_posts_source_media_id', $source_media_id);
            
            // Also preserve the original modification date for attachments if available
            if (!empty($source_media_modified)) {
                $wpdb->update(
                    $wpdb->posts,
                    ['post_modified' => $source_media_modified, 'post_modified_gmt' => get_gmt_from_date($source_media_modified)],
                    ['ID' => $attachment_id]
                );
            }
            
            return $attachment_id;
        }
        
        return $attachment_id;
    }
    
    private function sync_taxonomies($post, $post_id, $post_type) {
        if (empty($post['_embedded']['wp:term'])) {
            return;
        }
        
        $embedded_terms = $post['_embedded']['wp:term'];
        
        foreach ($embedded_terms as $terms) {
            foreach ($terms as $term) {
                $taxonomy = $term['taxonomy'];
                
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                
                $existing_term = get_term_by('name', $term['name'], $taxonomy);
                
                if ($existing_term) {
                    $term_id = $existing_term->term_id;
                } else {
                    $new_term = wp_insert_term($term['name'], $taxonomy);
                    if (is_wp_error($new_term)) {
                        continue;
                    }
                    $term_id = $new_term['term_id'];
                }
                
                wp_set_object_terms($post_id, $term_id, $taxonomy, true);
            }
        }

        $categories = wp_get_post_categories($post_id, array('fields' => 'all_with_object_id'));

        if (!is_a($categories, 'WP_Error')) {
            foreach ($categories as $category) {
                if(is_a($category, 'WP_Term')) {
                    if($category->term_id == 1 || $category->slug == 'uncategorized') {
                        wp_remove_object_terms($post_id, $category->term_id, 'category');
                    }
                }
            }
        }

    }

    /**
	 * Handler for the public media endpoint to be used on source site
	 * Returns only URL and alt text for a given media ID
	 */
	public static function get_public_media(\WP_REST_Request $request): \WP_REST_Response {
		$media_id = (int) $request->get_param('id');
		
		// Check if the attachment exists
		$attachment = get_post($media_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return new \WP_REST_Response(
				[
					'status'  => 'error',
					'message' => 'Media not found',
				],
				404
			);
		}
		
		// Get media data
		$full_src = wp_get_attachment_image_src($media_id, 'full');
		$alt_text = get_post_meta($media_id, '_wp_attachment_image_alt', true);
		$title = get_the_title($media_id);
		
		if (!$full_src) {
			return new \WP_REST_Response(
				[
					'status'  => 'error',
					'message' => 'Unable to retrieve media URL',
				],
				500
			);
		}
		
		// Return only the necessary information
		$data = [
			'id' => $media_id,
			'source_url' => $full_src[0],
			'width' => $full_src[1],
			'height' => $full_src[2],
			'alt' => $alt_text,
			'title' => $title,
		];
		
		return new \WP_REST_Response($data, 200);
	}
}

/**
 * Plugin activation hook
 */
function sync_posts_activate() {
    // Initialize default settings
    $default_settings = [
        'source_url' => '',
        'post_types' => ['post'],
        'api_endpoint' => 'wp/v2',
        'items_per_request' => 10,
        'date_limit' => '',
    ];
    
    if (!get_option('sync_posts_settings')) {
        add_option('sync_posts_settings', $default_settings);
    }
}
register_activation_hook(__FILE__, 'sync_posts_activate');

if (file_exists(plugin_dir_path(__FILE__) . 'meta-fields.php')) {
    require_once plugin_dir_path(__FILE__) . 'meta-fields.php';
} else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>' . 
             __('Sync Posts plugin error: meta-fields.php is missing.', 'sync-posts') . 
             '</p></div>';
    });
    error_log('Sync Posts plugin error: meta-fields.php is missing');
}

try {
    $sync_posts_instance = SyncPosts::get_instance();
    add_action('sync_posts_init', function($plugin) {}, 10);
} catch (Exception $e) {
    error_log('Sync Posts initialization error: ' . $e->getMessage());
    add_action('admin_notices', function() use ($e) {
        echo '<div class="notice notice-error"><p>' . 
             __('Sync Posts plugin initialization error: ', 'sync-posts') . esc_html($e->getMessage()) . 
             '</p></div>';
    });
}
