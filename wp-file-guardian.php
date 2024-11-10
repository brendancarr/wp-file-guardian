<?php
/*
Plugin Name: WP File Guardian
Plugin URI: https://infinus.ca
Description: Monitors and restores WordPress core files integrity on a scheduled basis
Version: 1.5
Author: Brendan @ Infinus
License: GPL v2 or later
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

//MARK: Define Class
class WP_File_Guardian {
    private static $instance = null;
    private $plugin_slug = 'wp-file-guardian';
    private $options_group = 'wp_file_guardian_options';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    //MARK: constructor
    private function __construct() {
        // Initialize hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('wp_file_guardian_check', array($this, 'perform_integrity_check'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    //MARK: activate
    public function activate() {
        // Default options
        $default_options = array(
            'check_core' => 1,
            'check_plugins' => 1,
            'schedule' => 'daily',
            'email_recipient' => get_option('admin_email'),
            'email_subject' => '[{site_name}] File Integrity Check Report',
            'email_template' => "File Integrity Check Report for {site_name}\n\nModified Files:\n{modified_files}\n\nUnknown Files:\n{unknown_files}\n\nRestored Files:\n{restored_files}",
            'wp_file_guardian_last_check' => "Never"
        );
        
        add_option($this->options_group, $default_options);
        
        // Schedule the integrity check
        if (!wp_next_scheduled('wp_file_guardian_check')) {
            wp_schedule_event(time(), 'daily', 'wp_file_guardian_check');
        }
    }

    //MARK: deactivate
    public function deactivate() {
        wp_clear_scheduled_hook('wp_file_guardian_check');
    }
    
    //MARK: add_admin_menu
    public function add_admin_menu() {
        add_menu_page(
            'WP File Guardian',        // Page title
            'File Guardian',          // Menu title
            'manage_options',         // Capability
            $this->plugin_slug,       // Menu slug
            array($this, 'render_dashboard_page'), // Callback
            'dashicons-shield',       // Icon
            80                        // Position
        );

        add_submenu_page(
            $this->plugin_slug,       // Parent slug
            'File Guardian Settings', // Page title
            'Settings',               // Menu title
            'manage_options',         // Capability
            $this->plugin_slug . '_settings', // Menu slug
            array($this, 'render_settings_page') // Callback
        );
    }

    
    //MARK: add_settings_link
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=' . $this->plugin_slug),
            __('Settings', 'wp-file-guardian')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
    
    //MARK: init_settings
    public function init_settings() {
        register_setting($this->options_group, $this->options_group);
        
        // General Settings
        add_settings_section(
            'general_section',
            'General Settings',
            null,
            $this->plugin_slug
        );
        
        add_settings_field(
            'check_core',
            'Check Core Files',
            array($this, 'render_checkbox_field'),
            $this->plugin_slug,
            'general_section',
            array('field' => 'check_core')
        );
        
        add_settings_field(
            'check_plugins',
            'Check Plugin Files',
            array($this, 'render_checkbox_field'),
            $this->plugin_slug,
            'general_section',
            array('field' => 'check_plugins')
        );
        
        add_settings_field(
            'schedule',
            'Check Schedule',
            array($this, 'render_schedule_field'),
            $this->plugin_slug,
            'general_section'
        );
        
        // Email Settings
        add_settings_section(
            'email_section',
            'Email Notification Settings',
            null,
            $this->plugin_slug
        );
        
        add_settings_field(
            'email_recipient',
            'Email Recipient',
            array($this, 'render_text_field'),
            $this->plugin_slug,
            'email_section',
            array('field' => 'email_recipient')
        );
        
        add_settings_field(
            'email_subject',
            'Email Subject',
            array($this, 'render_text_field'),
            $this->plugin_slug,
            'email_section',
            array('field' => 'email_subject')
        );
        
        add_settings_field(
            'email_template',
            'Email Template',
            array($this, 'render_textarea_field'),
            $this->plugin_slug,
            'email_section',
            array('field' => 'email_template')
        );
    }
    
    //MARK: render_dashboard_page
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option($this->options_group);
        $unknown_files = $this->get_unknown_files();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-shield" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
                WP File Guardian
            </h1>
            <p>WP File Guardian monitors and restores WordPress core files integrity on a scheduled basis.</p>

            <form method="post" action="">
                <?php wp_nonce_field('wp_file_guardian_scan_now', 'wp_file_guardian_scan_nonce'); ?>
                <input type="submit" name="wp_file_guardian_scan_now" class="button button-primary" value="Scan Now">
            </form>

            <?php
            if (isset($_POST['wp_file_guardian_scan_now']) && check_admin_referer('wp_file_guardian_scan_now', 'wp_file_guardian_scan_nonce')) {
                $this->perform_integrity_check();
                echo '<div class="notice notice-success"><p>Scan completed successfully.</p></div>';
            }
            ?>
            
            <h2>Current Settings</h2>
            <ul>
                <li><strong>Check Schedule:</strong> <?php echo ucfirst($options['schedule']); ?> </li>
                <li><strong>Next Check:</strong> <?php echo $this->get_next_scheduled_time(); ?></li>
                <li><strong>Email Recipient:</strong> <?php echo esc_html($options['email_recipient']); ?></li>
            </ul>

            <h2>Unknown Files</h2>
            <?php if (empty($unknown_files)): ?>
                <p>No unknown files detected in your WordPress directory.</p>
            <?php else: ?>
                <p>Found <?php echo count($unknown_files); ?> unknown file(s) in your WordPress directory.</p>
                <p>Visit the <a href="<?php echo admin_url('admin.php?page=' . $this->plugin_slug . '&tab=unknown_files'); ?>">Unknown Files</a> tab for more details.</p>
            <?php endif; ?>
            <h2>Last Results</h2>
            <?php
            $last_check = get_option('wp_file_guardian_last_check');
            if ($last_check && !empty($last_check)):
                $modified_files = $last_check['modified_files'];
                $unknown_files = $last_check['unknown_files'];
                $restored_files = $last_check['restored_files'];
                $total_files = count($modified_files) + count($unknown_files) + count($restored_files);
                echo '<h4>Modified Files</h4>';
                if (!is_array($modified_files)) echo '<p>No unknown files found.</p>';
                foreach ($modified_files as $file) {
                    echo '<p> • '. $file. '</p>';
                }
                
                
                echo '<h4>Unknown Files</h4>';
                if (!is_array($unknown_files)) echo '<p>No unknown files found.</p>';
                foreach ($unknown_files as $file) {
                    echo '<p> • '. $file. '</p>';
                }
                echo '<h4>Restored Files</h4>';
                if (!is_array($restored_files)) echo '<p>No unknown files found.</p>';
                foreach ($restored_files as $file) {
                    echo '<p> • '. $file. '</p>';
                }
                
                
                ?>
            <?php endif;?>
        </div>
        <?php
    }

    //MARK: get_next_scheduled_time
    public function get_next_scheduled_time() {
        $next_scheduled = wp_next_scheduled('wp_file_guardian_check');
        if ($next_scheduled) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled);
        }
        return 'Not scheduled';
    }
    

    //MARK: render_settings_page
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
    
        // Handle file deletion
        if (isset($_POST['delete_files']) && isset($_POST['files'])) {
            check_admin_referer('delete_unknown_files');
            $this->delete_selected_files($_POST['files']);
        }
    
        // Get current tab
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        $options = get_option($this->options_group);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-shield" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
                WP File Guardian
            </h1>
            
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo $this->plugin_slug; ?>&tab=general" 
                   class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic" style="margin-right: 5px;"></span>
                    General
                </a>
                <a href="?page=<?php echo $this->plugin_slug; ?>&tab=email" 
                   class="nav-tab <?php echo $current_tab === 'email' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-email" style="margin-right: 5px;"></span>
                    Email Settings
                </a>
                <a href="?page=<?php echo $this->plugin_slug; ?>&tab=unknown_files" 
                   class="nav-tab <?php echo $current_tab === 'unknown_files' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-warning" style="margin-right: 5px;"></span>
                    Unknown Files
                </a>
            </nav>
    
            <div class="tab-content">
                <?php settings_errors($this->plugin_slug); ?>
     <?php 
    //MARK: general tab
    ?>
                <?php if ($current_tab === 'general'): ?>
                    <div class="card">
                        <form action="options.php" method="post">
                            <?php settings_fields($this->options_group); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Check Core Files</th>
                                    <td>
                                        <?php $this->render_checkbox_field(array('field' => 'check_core')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Check Plugin Files</th>
                                    <td>
                                        <?php $this->render_checkbox_field(array('field' => 'check_plugins')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Check Schedule</th>
                                    <td>
                                        <?php $this->render_schedule_field(); ?>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Save Settings'); ?>
                        </form>
                    </div>
    <?php 
    //MARK: email tab
    ?>
                <?php elseif ($current_tab === 'email'): ?>
                    <div class="card">
                        <form action="options.php" method="post">
                            <?php settings_fields($this->options_group); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Email Recipient</th>
                                    <td>
                                        <?php $this->render_text_field(array('field' => 'email_recipient')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Email Subject</th>
                                    <td>
                                        <?php $this->render_text_field(array('field' => 'email_subject')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Email Template</th>
                                    <td>
                                        <?php $this->render_textarea_field(array('field' => 'email_template')); ?>
                                        <p class="description">Available placeholders: {site_name}, {modified_files}, {unknown_files}, {restored_files}</p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Save Settings'); ?>
                        </form>
                    </div>
                    <?php elseif ($current_tab === 'unknown_files'): ?>
    <?php 
    //MARK: unknown files tab
    ?>
                <?php
                    // Add thickbox
                    add_thickbox();
                ?>
                    <div class="unknown-files-manager">
                        <h3>Unknown Files</h3>
                        <p>Real-time scan of file integrity and unknown files in your WordPress directory. 
                            Does not require external verification, and uses a recursive directory
                            iterator to cross-reference against checksums from WordPress.org.</p>
                        <?php
                        $unknown_files = $this->get_unknown_files();
                        if (!empty($unknown_files)):
                        ?>
                            <form method="post" action="">
                                <?php wp_nonce_field('delete_unknown_files'); ?>
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <input type="submit" name="delete_files" class="button action" value="Delete Selected">
                                        <input type="submit" name="delete_all" class="button action" value="Delete All">
                                    </div>
                                </div>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <td class="manage-column column-cb check-column">
                                                <input type="checkbox" id="cb-select-all-1">
                                            </td>
                                            <th>File Path</th>
                                            <th>Size</th>
                                            <th>Last Modified</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($unknown_files as $file): 
                                            $file_type = $this->get_file_type($file['path']);
                                            $is_image = $this->is_image_file($file['path']);
                                        ?>
                                        <tr>
                                            <th scope="row" class="check-column">
                                                <input type="checkbox" name="files[]" value="<?php echo esc_attr($file['path']); ?>">
                                            </th>
                                            <td class="file-path">
                                                <?php 
                                                // Show appropriate icon based on file type
                                                if ($is_image) {
                                                    echo '<span class="dashicons dashicons-format-image file-type-icon"></span>';
                                                } elseif (strpos($file_type, 'text/') === 0) {
                                                    echo '<span class="dashicons dashicons-text file-type-icon"></span>';
                                                } else {
                                                    echo '<span class="dashicons dashicons-media-default file-type-icon"></span>';
                                                }
                                                echo esc_html($file['path']); 
                                                ?>
                                            </td>
                                            <td><?php echo esc_html($file['size']); ?></td>
                                            <td><?php echo esc_html($file['modified']); ?></td>
                                            <td>
                                                <a href="#TB_inline?width=<?php echo $is_image ? '800' : '600'; ?>&height=<?php echo $is_image ? '600' : '550'; ?>&inlineId=file-content-<?php echo esc_attr(md5($file['path'])); ?>" 
                                                class="thickbox button button-small <?php echo $is_image ? 'image-preview-link' : ''; ?>">
                                                    <?php echo $is_image ? 'View Image' : 'View Content'; ?>
                                                </a>
                                                <div id="file-content-<?php echo esc_attr(md5($file['path'])); ?>" style="display:none;">
                                                    <div class="file-viewer">
                                                        <h3><?php echo esc_html($file['path']); ?></h3>
                                                        <div class="file-info">
                                                            <p><strong>Size:</strong> <?php echo esc_html($file['size']); ?></p>
                                                            <p><strong>Last Modified:</strong> <?php echo esc_html($file['modified']); ?></p>
                                                            <p><strong>File Type:</strong> <?php echo esc_html($file_type); ?></p>
                                                        </div>
                                                        <div class="file-content">
                                                            <?php 
                                                            $content = $this->get_safe_file_content(ABSPATH . $file['path']);
                                                            if ($this->is_binary_file(ABSPATH . $file['path']) && !$is_image): 
                                                            ?>
                                                                <p class="binary-notice">This appears to be a binary file and cannot be displayed safely.</p>
                                                            <?php else: ?>
                                                                <?php echo $is_image ? $content : '<pre>' . esc_html($content) . '</pre>'; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </form>
                        </div>
                    </div>
            <style>
                .file-viewer {
                    padding: 20px;
                    max-width: 100%;
                }
                .file-viewer h3 {
                    margin-top: 0;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #ddd;
                }
                .file-info {
                    background: #f9f9f9;
                    padding: 10px;
                    margin-bottom: 10px;
                    border: 1px solid #eee;
                }
                .file-content {
                    max-height: 400px;
                    overflow-y: auto;
                    background: #fff;
                    border: 1px solid #ddd;
                }
                .file-content pre {
                    margin: 0;
                    padding: 10px;
                    white-space: pre-wrap;
                    word-wrap: break-word;
                    font-family: monospace;
                }
                .binary-notice {
                    padding: 15px;
                    margin: 0;
                    color: #721c24;
                    background-color: #f8d7da;
                    border: 1px solid #f5c6cb;
                }
                .nav-tab .dashicons {
                    line-height: 24px;
                }
                .card {
                    max-width: none;
                }
                .wp-heading-inline .dashicons {
                    margin-top: -3px;
                }
                .image-preview {
                    text-align: center;
                    padding: 20px;
                    background: #f9f9f9;
                }
                .file-type-icon {
                    vertical-align: middle;
                    margin-right: 5px;
                }
                .file-path {
                    display: flex;
                    align-items: center;
                }
                #TB_window.image-preview-thickbox {
                    max-width: 90%;
                    max-height: 90%;
                }
                #TB_window.image-preview-thickbox #TB_ajaxContent {
                    max-width: 100%;
                    max-height: calc(100% - 30px);
                    width: auto !important;
                    height: auto !important;
                }
            </style>
        <?php else: ?>
            <p>No unknown files detected.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
        </div>
        <?php
    }
    
    //MARK: get_unknown_files
    private function get_unknown_files($list = false) {

        $unknownlist = array();
        $unknown_files = array();
        $wp_root = ABSPATH;
        $checksums = get_core_checksums(get_bloginfo('version'), 'en_US');
        
        // Common WordPress files that are typically customized
        $whitelisted_files = array(
            '.htaccess',           // Apache configuration
            'php.ini',            // PHP configuration
            'wp-config.php',      // WordPress configuration
            'robots.txt',         // Search engine directives
            'favicon.ico',        // Site favicon
            '.user.ini',         // User-specific PHP configuration
            'web.config',        // IIS configuration
            '.well-known',       // SSL and other verification files directory
            'sitemap.xml',       // XML sitemap
            'humans.txt',        // Team credits file
            'error_log',         // Error logging
            'php_errorlog'       // PHP error logging
        );
        
        // Recursive directory iterator
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wp_root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
    
        foreach ($iterator as $file) {
            $file_path = str_replace($wp_root, '', $file->getPathname());
            
            // Skip wp-content directory
            if (strpos($file_path, 'wp-content/') === 0) {
                continue;
            }
            
            // Skip whitelisted files
            $is_whitelisted = false;
            foreach ($whitelisted_files as $whitelisted_file) {
                if (basename($file_path) === $whitelisted_file || strpos($file_path, $whitelisted_file . '/') === 0) {
                    $is_whitelisted = true;
                    break;
                }
            }
            
            if ($is_whitelisted) {
                continue;
            }
            
            // If file is not in core checksums, it's unknown
            if (!isset($checksums[$file_path])) {
                $unknownlist[] = $file_path;
                $unknown_files[] = array(
                    'path' => $file_path,
                    'size' => size_format($file->getSize()),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime())
                );
            }
        }
        if ($list) return $unknownlist;
        return $unknown_files;
    }
    
    //MARK: is_whitelisted_file_type
    private function is_whitelisted_file_type($file_path) {
        // File extensions that are commonly safe
        $safe_extensions = array(
            'log',      // Log files
            'txt',      // Text files
            'html',     // HTML files
            'xml',      // XML files
            'json'      // JSON files
        );
        
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        return in_array($extension, $safe_extensions);
    }

    //MARK: delete_selected_files
    private function delete_selected_files($files) {
        if (!current_user_can('manage_options')) {
            return;
        }
    
        $deleted = array();
        $failed = array();
    
        foreach ($files as $file) {
            $full_path = ABSPATH . $file;
            
            // Basic security check
            if (strpos(realpath($full_path), realpath(ABSPATH)) !== 0) {
                continue;
            }
            
            if (file_exists($full_path) && unlink($full_path)) {
                $deleted[] = $file;
            } else {
                $failed[] = $file;
            }
        }
    
        if (!empty($deleted)) {
            add_settings_error(
                $this->plugin_slug,
                'files_deleted',
                sprintf('Successfully deleted %d file(s).', count($deleted)),
                'updated'
            );
        }
    
        if (!empty($failed)) {
            add_settings_error(
                $this->plugin_slug,
                'files_delete_failed',
                sprintf('Failed to delete %d file(s).', count($failed)),
                'error'
            );
        }
    }
    
    //MARK: get_file_type
    private function get_file_type($file_path) {
        $file_info = wp_check_filetype($file_path);
        return !empty($file_info['type']) ? $file_info['type'] : 'Unknown';
    }
    
    //MARK: is_image_file
    private function is_image_file($file_path) {
        $mime_type = $this->get_file_type($file_path);
        return strpos($mime_type, 'image/') === 0;
    }

    //MARK: is_binary_file
    private function is_binary_file($file_path) {
        if (!file_exists($file_path)) {
            return true;
        }

        if ($this->is_image_file($file_path)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
    
        // List of text mime types
        $text_mimes = array(
            'text/plain',
            'text/html',
            'text/xml',
            'text/x-php',
            'application/javascript',
            'application/x-httpd-php',
            'application/json',
            'application/xml',
            'application/x-yaml'
        );
    
        foreach ($text_mimes as $text_mime) {
            if (strpos($mime_type, $text_mime) !== false) {
                return false;
            }
        }
    
        return true;
    }
    
    
    //MARK: get_safe_file_content
    private function get_safe_file_content($file_path) {
        if (!file_exists($file_path)) {
            return '';
        }

        // Handle image files
        if ($this->is_image_file($file_path)) {
            // Get the file URL relative to the WordPress root
            $file_url = str_replace(ABSPATH, site_url('/'), $file_path);
            return '<div class="image-preview"><img src="' . esc_url($file_url) . '" alt="Image preview" style="max-width: 100%; height: auto;"></div>';
        }

        // Handle binary files
        if ($this->is_binary_file($file_path)) {
            return '';
        }

        // Limit file size to prevent memory issues (1MB)
        if (filesize($file_path) > 1048576) {
            return 'File is too large to display (max size: 1MB)';
        }

        $content = file_get_contents($file_path);
        
        // Ensure content is UTF-8 encoded
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ASCII,UTF-8,ISO-8859-1');
        }
        
        return $content;
    }

    //MARK: render_checkbox_field
    public function render_checkbox_field($args) {
        $options = get_option($this->options_group);
        $field = $args['field'];
        ?>
        <input type="checkbox" 
               id="<?php echo esc_attr($field); ?>"
               name="<?php echo esc_attr($this->options_group . '[' . $field . ']'); ?>"
               value="1"
               <?php checked(1, isset($options[$field]) ? $options[$field] : 0); ?>>
        <?php
    }
    
    //MARK: render_text_field
    public function render_text_field($args) {
        $options = get_option($this->options_group);
        $field = $args['field'];
        ?>
        <input type="text" 
               class="regular-text"
               id="<?php echo esc_attr($field); ?>"
               name="<?php echo esc_attr($this->options_group . '[' . $field . ']'); ?>"
               value="<?php echo esc_attr(isset($options[$field]) ? $options[$field] : ''); ?>">
        <?php
    }
    
    //MARK: render_textarea_field
    public function render_textarea_field($args) {
        $options = get_option($this->options_group);
        $field = $args['field'];
        ?>
        <textarea class="large-text code"
                  rows="10"
                  id="<?php echo esc_attr($field); ?>"
                  name="<?php echo esc_attr($this->options_group . '[' . $field . ']'); ?>"
        ><?php echo esc_textarea(isset($options[$field]) ? $options[$field] : ''); ?></textarea>
        <?php
    }
    
    //MARK: render_schedule_field
    public function render_schedule_field() {
        $options = get_option($this->options_group);
        $schedules = array(
            'hourly' => 'Every Hour',
            'twicedaily' => 'Twice Daily',
            'daily' => 'Once Daily',
            'weekly' => 'Weekly'
        );
        ?>
        <select name="<?php echo esc_attr($this->options_group . '[schedule]'); ?>">
            <?php foreach ($schedules as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>"
                        <?php selected($key, isset($options['schedule']) ? $options['schedule'] : 'daily'); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    
    //MARK: perform_integrity_check
    public function perform_integrity_check() {
        $options = get_option($this->options_group);
        $modified_files = array();
        $unknown_files = array();
        $restored_files = array();
        
        if ($options['check_core']) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/misc.php');
            
            // Get WordPress checksums
            $checksums = get_core_checksums(get_bloginfo('version'), 'en_US');
            
            if ($checksums) {
                foreach ($checksums as $file => $checksum) {
                    $file_path = ABSPATH . $file;
                    
                    if (file_exists($file_path)) {
                        $current_checksum = md5_file($file_path);
                        
                        if ($current_checksum !== $checksum) {
                            $modified_files[] = $file;
                            
                            // Restore file from WordPress core
                            $restore_url = 'https://raw.githubusercontent.com/WordPress/WordPress/master/' . $file;
                            $restored_content = wp_remote_get($restore_url);
                            
                            if (!is_wp_error($restored_content) && wp_remote_retrieve_response_code($restored_content) === 200) {
                                file_put_contents($file_path, wp_remote_retrieve_body($restored_content));
                                $restored_files[] = $file;
                            }
                        }
                    }
                }
            }
        }
        $unknown_files = $this->get_unknown_files(true);
        // Send email notification
        if (!empty($modified_files) || !empty($unknown_files) || !empty($restored_files)) {
            // Store last results
            $last_check_results = array(
                'modified_files' => $modified_files,
                'unknown_files' => $unknown_files,
                'restored_files' => $restored_files,
                'check_time' => current_time('mysql')
            );
            update_option('wp_file_guardian_last_check', $last_check_results);
    
            $this->send_notification_email($modified_files, $unknown_files, $restored_files);
        } else {
            $last_check_results = array(
                'modified_files' => 0,
                'unknown_files' => 0,
                'restored_files' => 0,
                'check_time' => current_time('mysql')
            );
            // No changes found, do nothing
            update_option('wp_file_guardian_last_check', $last_check_results);
        }
    }
    
    
    //MARK: send_notification_email
    private function send_notification_email($modified_files, $unknown_files, $restored_files) {
        $options = get_option($this->options_group);
        
        $placeholders = array(
            '{site_name}' => get_bloginfo('name'),
            '{modified_files}' => implode("\n", $modified_files),
            '{unknown_files}' => implode("\n", $unknown_files),
            '{restored_files}' => implode("\n", $restored_files)
        );
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $options['email_subject']);
        $message = str_replace(array_keys($placeholders), array_values($placeholders), $options['email_template']);
        
        wp_mail($options['email_recipient'], $subject, $message);
    }
}

// Initialize the plugin
WP_File_Guardian::get_instance();