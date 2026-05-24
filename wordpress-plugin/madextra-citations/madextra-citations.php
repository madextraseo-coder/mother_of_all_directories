<?php
/**
 * Plugin Name: MadExtra Citations Directory
 * Plugin URI: https://directory.madextraseo.com
 * Description: Citation profile management with granular permissions, CSV import/export, REST endpoints, and a public grouped directory via shortcode.
 * Version: 0.3.0
 * Author: Mad Extra SEO
 * Author URI: https://madextraseo.com
 * License: GPL-2.0-or-later
 * Text Domain: madextra-citations
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('mec_builder_bootstrap_error_handler')) {
    function mec_builder_bootstrap_error_handler($message)
    {
        if (!is_string($message) || '' === $message) {
            return;
        }

        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            error_log('[MadExtra Citations] Builder bootstrap issue: ' . $message);
        } else {
            error_log('[MadExtra Citations] Builder bootstrap issue: ' . $message);
        }

        if (is_admin()) {
            add_action('admin_notices', static function () use ($message) {
                if (!current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-error"><p><strong>MadExtra Citations:</strong> Builder module failed to load. ';
                echo esc_html($message);
                echo '</p></div>';
            });
        }
    }
}

if (!class_exists('MadExtra_Citations_Plugin')) {
    final class MadExtra_Citations_Plugin
    {
        const CPT = 'citation_profile';
        const TAX_MARKET = 'citation_market';
        const TAX_SERVICE = 'citation_service';
        const META_PREFIX = '_mec_';
        const NONCE_META = 'mec_profile_meta_nonce';
        const NONCE_EXPORT = 'mec_export_nonce';
        const NONCE_IMPORT = 'mec_import_nonce';
        const NONCE_PUBLIC_SUBMIT = 'mec_public_submit_nonce';
        const NOTICE_TRANSIENT = 'mec_admin_notice';
        const SHORTCODE = 'madextra_citations_directory';
        const CAPS_OPTION = 'mec_caps_version';
        const CAPS_VERSION = '1.1.0';
        const PUBLIC_SUBMIT_SHORTCODE = 'mec_public_submit_form';
        const LOGO_MAX_BYTES = 2097152;

        public static function bootstrap()
        {
            add_action('init', array(__CLASS__, 'register_content_types'));
            add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
            add_action('save_post_' . self::CPT, array(__CLASS__, 'save_profile_meta'));

            add_filter('manage_edit-' . self::CPT . '_columns', array(__CLASS__, 'register_admin_columns'));
            add_action('manage_' . self::CPT . '_posts_custom_column', array(__CLASS__, 'render_admin_columns'), 10, 2);
            add_action('restrict_manage_posts', array(__CLASS__, 'render_admin_filters'));
            add_filter('parse_query', array(__CLASS__, 'apply_admin_filters'));

            add_action('admin_menu', array(__CLASS__, 'register_tools_submenu'));
            add_action('admin_post_mec_export_csv', array(__CLASS__, 'handle_export_request'));
            add_action('admin_post_mec_import_csv', array(__CLASS__, 'handle_import_request'));
            add_action('admin_post_mec_public_submit', array(__CLASS__, 'handle_public_submit_request'));
            add_action('admin_post_nopriv_mec_public_submit', array(__CLASS__, 'handle_public_submit_request'));
            add_action('admin_init', array(__CLASS__, 'maybe_redirect_legacy_tools_page'));
            add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
            add_action('admin_notices', array(__CLASS__, 'render_capability_debug_notice'));
            add_action('admin_init', array(__CLASS__, 'maybe_sync_capabilities'));
            add_filter('map_meta_cap', array(__CLASS__, 'map_citation_caps'), 99999, 4);
            add_filter('user_has_cap', array(__CLASS__, 'grant_admin_fallback_caps'), 99999, 4);

            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_directory_shortcode'));
            add_shortcode(self::PUBLIC_SUBMIT_SHORTCODE, array(__CLASS__, 'render_public_submit_form_shortcode'));
        }

        public static function activate()
        {
            self::register_content_types();
            self::register_roles_and_capabilities();
            update_option(self::CAPS_OPTION, self::CAPS_VERSION, false);
            self::maybe_create_directory_page();
            flush_rewrite_rules();
        }

        public static function deactivate()
        {
            flush_rewrite_rules();
        }

        public static function maybe_sync_capabilities()
        {
            $version = get_option(self::CAPS_OPTION, '');
            if (self::CAPS_VERSION === (string) $version) {
                return;
            }

            self::register_roles_and_capabilities();
            update_option(self::CAPS_OPTION, self::CAPS_VERSION, false);
        }

        public static function grant_admin_fallback_caps($allcaps, $caps, $args, $user)
        {
            $roles = isset($user->roles) && is_array($user->roles) ? $user->roles : array();
            $is_citation_role = in_array('citation_admin', $roles, true) || in_array('citation_manager', $roles, true);
            $is_trusted_admin_user = !empty($allcaps['manage_options']) || !empty($allcaps['edit_posts']);
            $is_logged_in_user = !empty($allcaps['read']);

            if (!empty($allcaps['manage_citation_profiles'])) {
                $allcaps['create_citation_profiles'] = true;
                $allcaps['edit_citation_profiles'] = true;
                $allcaps['delete_citation_profiles'] = true;
                $allcaps['publish_citation_profiles'] = true;
                $allcaps['import_citation_profiles'] = true;
                $allcaps['export_citation_profiles'] = true;
                $allcaps['manage_citation_settings'] = true;
            }

            $requested_is_citation = false;
            foreach ((array) $caps as $cap_name) {
                if (is_string($cap_name) && false !== strpos($cap_name, 'citation_')) {
                    $requested_is_citation = true;
                    break;
                }
            }
            if (!$requested_is_citation && isset($args[0]) && is_string($args[0]) && false !== strpos($args[0], 'citation_')) {
                $requested_is_citation = true;
            }
            if (
                !$requested_is_citation &&
                is_admin() &&
                isset($_GET['post_type']) &&
                self::CPT === sanitize_key(wp_unslash($_GET['post_type']))
            ) {
                $requested_is_citation = true;
            }

            if (!$is_citation_role && !$is_trusted_admin_user && !($is_logged_in_user && $requested_is_citation)) {
                return $allcaps;
            }

            $must_have_caps = array_merge(
                self::action_capabilities(),
                array_values(self::cpt_capabilities()),
                array(
                    'manage_citation_settings',
                    'manage_terms',
                    'edit_terms',
                    'delete_terms',
                    'assign_terms',
                )
            );

            foreach (array_unique($must_have_caps) as $cap) {
                $allcaps[$cap] = true;
            }

            return $allcaps;
        }

        public static function map_citation_caps($caps, $cap, $user_id, $args)
        {
            $citation_caps = array(
                'edit_citation_profiles',
                'create_citation_profiles',
                'delete_citation_profiles',
                'publish_citation_profiles',
                'read_private_citation_profiles',
                'edit_private_citation_profiles',
                'edit_published_citation_profiles',
                'edit_others_citation_profiles',
                'delete_private_citation_profiles',
                'delete_published_citation_profiles',
                'delete_others_citation_profiles',
                'edit_citation_profile',
                'delete_citation_profile',
                'read_citation_profile',
            );

            if (in_array($cap, $citation_caps, true)) {
                return array('manage_citation_profiles');
            }

            return $caps;
        }

        public static function register_content_types()
        {
            self::register_taxonomies();
            self::register_post_type();
        }

        private static function register_post_type()
        {
            $labels = array(
                'name'                  => __('Citation Profiles', 'madextra-citations'),
                'singular_name'         => __('Citation Profile', 'madextra-citations'),
                'menu_name'             => __('Citation Profiles', 'madextra-citations'),
                'name_admin_bar'        => __('Citation Profile', 'madextra-citations'),
                'add_new'               => __('Add New', 'madextra-citations'),
                'add_new_item'          => __('Add New Citation Profile', 'madextra-citations'),
                'edit_item'             => __('Edit Citation Profile', 'madextra-citations'),
                'new_item'              => __('New Citation Profile', 'madextra-citations'),
                'view_item'             => __('View Citation Profile', 'madextra-citations'),
                'search_items'          => __('Search Citation Profiles', 'madextra-citations'),
                'not_found'             => __('No citation profiles found.', 'madextra-citations'),
                'not_found_in_trash'    => __('No citation profiles found in Trash.', 'madextra-citations'),
                'all_items'             => __('All Citation Profiles', 'madextra-citations'),
                'archives'              => __('Citation Profile Archives', 'madextra-citations'),
                'attributes'            => __('Citation Profile Attributes', 'madextra-citations'),
            );

            register_post_type(
                self::CPT,
                array(
                    'labels'              => $labels,
                    'public'              => false,
                    'show_ui'             => true,
                    'show_in_menu'        => true,
                    'show_in_rest'        => true,
                    'rest_base'           => 'citation-profiles',
                    'menu_icon'           => 'dashicons-location-alt',
                    'supports'            => array('title'),
                    'has_archive'         => false,
                    'hierarchical'        => false,
                    'map_meta_cap'        => true,
                    'capabilities'        => self::cpt_capabilities(),
                    'rewrite'             => false,
                    'exclude_from_search' => true,
                )
            );
        }

        private static function register_taxonomies()
        {
            register_taxonomy(
                self::TAX_MARKET,
                array(self::CPT),
                array(
                    'label'             => __('Markets', 'madextra-citations'),
                    'public'            => false,
                    'show_ui'           => true,
                    'show_admin_column' => true,
                    'show_in_rest'      => true,
                    'hierarchical'      => true,
                    'rewrite'           => false,
                    'capabilities'      => array(
                        'manage_terms' => 'manage_citation_settings',
                        'edit_terms'   => 'manage_citation_settings',
                        'delete_terms' => 'manage_citation_settings',
                        'assign_terms' => 'manage_citation_profiles',
                    ),
                )
            );

            register_taxonomy(
                self::TAX_SERVICE,
                array(self::CPT),
                array(
                    'label'             => __('Services', 'madextra-citations'),
                    'public'            => false,
                    'show_ui'           => true,
                    'show_admin_column' => true,
                    'show_in_rest'      => true,
                    'hierarchical'      => false,
                    'rewrite'           => false,
                    'capabilities'      => array(
                        'manage_terms' => 'manage_citation_settings',
                        'edit_terms'   => 'manage_citation_settings',
                        'delete_terms' => 'manage_citation_settings',
                        'assign_terms' => 'manage_citation_profiles',
                    ),
                )
            );
        }

        private static function action_capabilities()
        {
            return array(
                'manage_citation_profiles',
                'create_citation_profiles',
                'edit_citation_profiles',
                'delete_citation_profiles',
                'publish_citation_profiles',
                'import_citation_profiles',
                'export_citation_profiles',
                'manage_citation_settings',
                'manage_citation_builder',
                'manage_citation_templates',
                'manage_citation_queries',
                'manage_citation_forms',
                'manage_citation_relations',
                'submit_citation_profiles',
            );
        }

        private static function cpt_capabilities()
        {
            return array(
                // Keep admin access tied to one guaranteed capability to prevent
                // host-specific role/cap collisions on wp-admin post-type screens.
                'edit_post'              => 'manage_citation_profiles',
                'read_post'              => 'manage_citation_profiles',
                'delete_post'            => 'manage_citation_profiles',
                'edit_posts'             => 'manage_citation_profiles',
                'edit_others_posts'      => 'manage_citation_profiles',
                'publish_posts'          => 'manage_citation_profiles',
                'read_private_posts'     => 'manage_citation_profiles',
                'delete_posts'           => 'manage_citation_profiles',
                'delete_private_posts'   => 'manage_citation_profiles',
                'delete_published_posts' => 'manage_citation_profiles',
                'delete_others_posts'    => 'manage_citation_profiles',
                'edit_private_posts'     => 'manage_citation_profiles',
                'edit_published_posts'   => 'manage_citation_profiles',
                'create_posts'           => 'manage_citation_profiles',
            );
        }

        private static function register_roles_and_capabilities()
        {
            $manager = get_role('citation_manager');
            if (!$manager) {
                add_role('citation_manager', __('Citation Manager', 'madextra-citations'), array('read' => true));
                $manager = get_role('citation_manager');
            }

            $admin = get_role('citation_admin');
            if (!$admin) {
                add_role('citation_admin', __('Citation Admin', 'madextra-citations'), array('read' => true, 'upload_files' => true));
                $admin = get_role('citation_admin');
            }

            $administrator = get_role('administrator');

            $manager_caps = array(
                'manage_citation_profiles',
                'create_citation_profiles',
                'edit_citation_profiles',
                'delete_citation_profiles',
                'publish_citation_profiles',
                'import_citation_profiles',
                'export_citation_profiles',
            );

            $admin_caps = self::action_capabilities();

            $cpt_caps = array_values(self::cpt_capabilities());

            if ($manager) {
                foreach (array_unique(array_merge($manager_caps, $cpt_caps)) as $cap) {
                    $manager->add_cap($cap);
                }
                $manager->add_cap('read');
            }

            if ($admin) {
                foreach (array_unique(array_merge($admin_caps, $cpt_caps)) as $cap) {
                    $admin->add_cap($cap);
                }
                $admin->add_cap('read');
                $admin->add_cap('upload_files');
            }

            if ($administrator) {
                foreach (array_unique(array_merge($admin_caps, $cpt_caps)) as $cap) {
                    $administrator->add_cap($cap);
                }
            }

            // Ensure any true admin-style role (including custom host roles)
            // receives citation capabilities without requiring dual-role assignment.
            $roles_object = wp_roles();
            if ($roles_object instanceof WP_Roles && !empty($roles_object->roles)) {
                foreach ($roles_object->roles as $role_slug => $role_data) {
                    $has_manage_options = !empty($role_data['capabilities']['manage_options']);
                    if (!$has_manage_options) {
                        continue;
                    }

                    $role = get_role($role_slug);
                    if (!$role) {
                        continue;
                    }

                    foreach (array_unique(array_merge($admin_caps, $cpt_caps)) as $cap) {
                        $role->add_cap($cap);
                    }
                }
            }
        }

        private static function maybe_create_directory_page()
        {
            $existing = get_page_by_path('citations');
            if ($existing) {
                return;
            }

            wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => 'Citations',
                    'post_name'    => 'citations',
                    'post_content' => '[' . self::SHORTCODE . ']',
                )
            );
        }

        private static function status_options()
        {
            return array(
                'live'        => __('Live', 'madextra-citations'),
                'pending'     => __('Pending', 'madextra-citations'),
                'in_progress' => __('In Progress', 'madextra-citations'),
                'needs_fix'   => __('Needs Fix', 'madextra-citations'),
                'suspended'   => __('Suspended', 'madextra-citations'),
            );
        }

        private static function field_definitions()
        {
            return array(
                'directory_name'      => array('label' => __('Directory Name', 'madextra-citations'), 'required' => true),
                'listing_url'         => array('label' => __('Listing URL', 'madextra-citations'), 'required' => true),
                'status'              => array('label' => __('Status', 'madextra-citations'), 'required' => true),
                'last_verified_date'  => array('label' => __('Last Verified Date', 'madextra-citations'), 'required' => false),
                'public_notes'        => array('label' => __('Public Notes', 'madextra-citations'), 'required' => false),
                'nap_business_name'   => array('label' => __('NAP Business Name', 'madextra-citations'), 'required' => true),
                'nap_address'         => array('label' => __('NAP Address', 'madextra-citations'), 'required' => true),
                'nap_phone'           => array('label' => __('NAP Phone', 'madextra-citations'), 'required' => true),
                'business_website_url' => array('label' => __('Business Website URL', 'madextra-citations'), 'required' => false),
                'business_logo_id'    => array('label' => __('Business Logo Attachment ID', 'madextra-citations'), 'required' => false),
                'business_email'      => array('label' => __('Business Email', 'madextra-citations'), 'required' => false),
                'business_description' => array('label' => __('Business Description', 'madextra-citations'), 'required' => false),
                'business_hours'      => array('label' => __('Business Hours', 'madextra-citations'), 'required' => false),
                'address_street'      => array('label' => __('Street Address', 'madextra-citations'), 'required' => false),
                'address_city'        => array('label' => __('City', 'madextra-citations'), 'required' => false),
                'address_state'       => array('label' => __('State', 'madextra-citations'), 'required' => false),
                'address_zip'         => array('label' => __('ZIP Code', 'madextra-citations'), 'required' => false),
                'internal_notes'      => array('label' => __('Internal Notes (Admin Only)', 'madextra-citations'), 'required' => false),
                'is_featured'         => array('label' => __('Featured', 'madextra-citations'), 'required' => false),
                'featured_order'      => array('label' => __('Featured Slot', 'madextra-citations'), 'required' => false),
            );
        }

        public static function register_meta_boxes()
        {
            add_meta_box(
                'mec_profile_details',
                __('Citation Profile Details', 'madextra-citations'),
                array(__CLASS__, 'render_meta_box'),
                self::CPT,
                'normal',
                'high'
            );
        }

        public static function render_meta_box($post)
        {
            wp_nonce_field(self::NONCE_META, self::NONCE_META);

            $fields = self::field_definitions();
            $values = array();
            foreach ($fields as $key => $meta) {
                $values[$key] = get_post_meta($post->ID, self::META_PREFIX . $key, true);
            }

            $market_terms = wp_get_object_terms($post->ID, self::TAX_MARKET, array('fields' => 'ids'));
            $service_terms = wp_get_object_terms($post->ID, self::TAX_SERVICE, array('fields' => 'ids'));
            $market_term_ids = array_map('intval', is_array($market_terms) ? $market_terms : array());
            $service_term_ids = array_map('intval', is_array($service_terms) ? $service_terms : array());
            $all_markets = get_terms(
                array(
                    'taxonomy'   => self::TAX_MARKET,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                )
            );
            $all_services = get_terms(
                array(
                    'taxonomy'   => self::TAX_SERVICE,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                )
            );
            ?>
            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row"><label for="mec_directory_name"><?php echo esc_html($fields['directory_name']['label']); ?></label></th>
                    <td><input type="text" class="regular-text" id="mec_directory_name" name="mec[directory_name]" value="<?php echo esc_attr($values['directory_name']); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_listing_url"><?php echo esc_html($fields['listing_url']['label']); ?></label></th>
                    <td><input type="url" class="large-text code" id="mec_listing_url" name="mec[listing_url]" value="<?php echo esc_attr($values['listing_url']); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_status"><?php echo esc_html($fields['status']['label']); ?></label></th>
                    <td>
                        <select id="mec_status" name="mec[status]">
                            <?php foreach (self::status_options() as $status_key => $status_label) : ?>
                                <option value="<?php echo esc_attr($status_key); ?>" <?php selected($values['status'], $status_key); ?>><?php echo esc_html($status_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_last_verified_date"><?php echo esc_html($fields['last_verified_date']['label']); ?></label></th>
                    <td><input type="date" id="mec_last_verified_date" name="mec[last_verified_date]" value="<?php echo esc_attr($values['last_verified_date']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_public_notes"><?php echo esc_html($fields['public_notes']['label']); ?></label></th>
                    <td><textarea id="mec_public_notes" name="mec[public_notes]" rows="4" class="large-text"><?php echo esc_textarea($values['public_notes']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_nap_business_name"><?php echo esc_html($fields['nap_business_name']['label']); ?></label></th>
                    <td><input type="text" class="regular-text" id="mec_nap_business_name" name="mec[nap_business_name]" value="<?php echo esc_attr($values['nap_business_name']); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_nap_address"><?php echo esc_html($fields['nap_address']['label']); ?></label></th>
                    <td><textarea id="mec_nap_address" name="mec[nap_address]" rows="3" class="large-text" required><?php echo esc_textarea($values['nap_address']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_nap_phone"><?php echo esc_html($fields['nap_phone']['label']); ?></label></th>
                    <td><input type="text" class="regular-text" id="mec_nap_phone" name="mec[nap_phone]" value="<?php echo esc_attr($values['nap_phone']); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_business_website_url"><?php echo esc_html($fields['business_website_url']['label']); ?></label></th>
                    <td><input type="url" class="large-text code" id="mec_business_website_url" name="mec[business_website_url]" value="<?php echo esc_attr($values['business_website_url']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_business_logo_id"><?php echo esc_html($fields['business_logo_id']['label']); ?></label></th>
                    <td>
                        <input type="number" class="small-text" id="mec_business_logo_id" name="mec[business_logo_id]" min="0" step="1" value="<?php echo esc_attr($values['business_logo_id']); ?>">
                        <p class="description"><?php esc_html_e('Upload the logo in Media Library and paste the attachment ID here.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_business_email"><?php echo esc_html($fields['business_email']['label']); ?></label></th>
                    <td><input type="email" class="regular-text" id="mec_business_email" name="mec[business_email]" value="<?php echo esc_attr($values['business_email']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_business_description"><?php echo esc_html($fields['business_description']['label']); ?></label></th>
                    <td><textarea id="mec_business_description" name="mec[business_description]" rows="4" class="large-text"><?php echo esc_textarea($values['business_description']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_business_hours"><?php echo esc_html($fields['business_hours']['label']); ?></label></th>
                    <td><textarea id="mec_business_hours" name="mec[business_hours]" rows="4" class="large-text" placeholder="<?php esc_attr_e('Monday-Friday 9am-5pm', 'madextra-citations'); ?>"><?php echo esc_textarea($values['business_hours']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Structured Address', 'madextra-citations'); ?></th>
                    <td>
                        <p><input type="text" class="regular-text" id="mec_address_street" name="mec[address_street]" value="<?php echo esc_attr($values['address_street']); ?>" placeholder="<?php echo esc_attr($fields['address_street']['label']); ?>"></p>
                        <p>
                            <input type="text" class="regular-text" id="mec_address_city" name="mec[address_city]" value="<?php echo esc_attr($values['address_city']); ?>" placeholder="<?php echo esc_attr($fields['address_city']['label']); ?>">
                            <input type="text" class="small-text" id="mec_address_state" name="mec[address_state]" value="<?php echo esc_attr($values['address_state']); ?>" placeholder="<?php echo esc_attr($fields['address_state']['label']); ?>">
                            <input type="text" class="small-text" id="mec_address_zip" name="mec[address_zip]" value="<?php echo esc_attr($values['address_zip']); ?>" placeholder="<?php echo esc_attr($fields['address_zip']['label']); ?>">
                        </p>
                        <p class="description"><?php esc_html_e('Used for cleaner public display. The legacy NAP address remains supported.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_internal_notes"><?php echo esc_html($fields['internal_notes']['label']); ?></label></th>
                    <td><textarea id="mec_internal_notes" name="mec[internal_notes]" rows="4" class="large-text"><?php echo esc_textarea($values['internal_notes']); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_featured_order"><?php echo esc_html($fields['featured_order']['label']); ?></label></th>
                    <td>
                        <select id="mec_featured_order" name="mec[featured_order]">
                            <option value="0" <?php selected((int) $values['featured_order'], 0); ?>><?php esc_html_e('Not featured', 'madextra-citations'); ?></option>
                            <option value="1" <?php selected((int) $values['featured_order'], 1); ?>><?php esc_html_e('Featured position 1', 'madextra-citations'); ?></option>
                            <option value="2" <?php selected((int) $values['featured_order'], 2); ?>><?php esc_html_e('Featured position 2', 'madextra-citations'); ?></option>
                            <option value="3" <?php selected((int) $values['featured_order'], 3); ?>><?php esc_html_e('Featured position 3', 'madextra-citations'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Each city can have up to three featured profiles.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_markets"><?php esc_html_e('Markets', 'madextra-citations'); ?></label></th>
                    <td>
                        <select name="mec_markets[]" id="mec_markets" multiple>
                            <?php if (!is_wp_error($all_markets)) : ?>
                                <?php foreach ($all_markets as $term) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array((int) $term->term_id, $market_term_ids, true)); ?>>
                                        <?php echo esc_html($term->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Assign one or more market terms.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_services"><?php esc_html_e('Services', 'madextra-citations'); ?></label></th>
                    <td>
                        <select name="mec_services[]" id="mec_services" multiple>
                            <?php if (!is_wp_error($all_services)) : ?>
                                <?php foreach ($all_services as $term) : ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected(in_array((int) $term->term_id, $service_term_ids, true)); ?>>
                                        <?php echo esc_html($term->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Assign one or more service terms.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                </tbody>
            </table>
            <style>
                #mec_markets, #mec_services { min-height: 140px; min-width: 280px; }
            </style>
            <?php
        }

        public static function save_profile_meta($post_id)
        {
            if (!isset($_POST[self::NONCE_META]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_META])), self::NONCE_META)) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $payload = isset($_POST['mec']) ? (array) wp_unslash($_POST['mec']) : array();
            if (!$payload) {
                return;
            }

            $clean = self::sanitize_profile_payload($payload);
            $validation = self::validate_required_profile_data($clean);
            if (is_wp_error($validation)) {
                self::queue_notice($validation->get_error_message(), 'error');
                return;
            }

            $market_ids = isset($_POST['mec_markets']) ? array_map('intval', (array) wp_unslash($_POST['mec_markets'])) : array();
            $service_ids = isset($_POST['mec_services']) ? array_map('intval', (array) wp_unslash($_POST['mec_services'])) : array();
            $market_ids = array_values(array_filter($market_ids));
            $service_ids = array_values(array_filter($service_ids));

            $featured_validation = self::validate_featured_slot($post_id, $clean, $market_ids);
            if (is_wp_error($featured_validation)) {
                self::queue_notice($featured_validation->get_error_message(), 'error');
                return;
            }

            foreach ($clean as $key => $value) {
                update_post_meta($post_id, self::META_PREFIX . $key, $value);
            }

            if (empty(get_the_title($post_id)) && !empty($clean['directory_name'])) {
                wp_update_post(
                    array(
                        'ID'         => $post_id,
                        'post_title' => $clean['directory_name'],
                    )
                );
            }

            wp_set_object_terms($post_id, $market_ids, self::TAX_MARKET, false);
            wp_set_object_terms($post_id, $service_ids, self::TAX_SERVICE, false);

            update_post_meta($post_id, self::META_PREFIX . 'unique_key', self::build_unique_key($clean, $market_ids, $service_ids));
        }

        private static function sanitize_profile_payload(array $payload)
        {
            $status = isset($payload['status']) ? sanitize_key($payload['status']) : 'pending';
            $status = array_key_exists($status, self::status_options()) ? $status : 'pending';

            $date = isset($payload['last_verified_date']) ? sanitize_text_field($payload['last_verified_date']) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = '';
            }

            $featured_order = isset($payload['featured_order']) ? (int) $payload['featured_order'] : 0;
            $featured_order = min(3, max(0, $featured_order));
            $is_featured = $featured_order > 0 || !empty($payload['is_featured']) ? '1' : '0';
            if ('0' === $is_featured) {
                $featured_order = 0;
            } elseif (0 === $featured_order) {
                $featured_order = 1;
            }

            $clean = array(
                'directory_name'      => isset($payload['directory_name']) ? sanitize_text_field($payload['directory_name']) : '',
                'listing_url'         => isset($payload['listing_url']) ? esc_url_raw($payload['listing_url']) : '',
                'status'              => $status,
                'last_verified_date'  => $date,
                'public_notes'        => isset($payload['public_notes']) ? sanitize_textarea_field($payload['public_notes']) : '',
                'nap_business_name'   => isset($payload['nap_business_name']) ? sanitize_text_field($payload['nap_business_name']) : '',
                'nap_address'         => isset($payload['nap_address']) ? sanitize_textarea_field($payload['nap_address']) : '',
                'nap_phone'           => isset($payload['nap_phone']) ? sanitize_text_field($payload['nap_phone']) : '',
                'business_website_url' => isset($payload['business_website_url']) ? esc_url_raw($payload['business_website_url']) : '',
                'business_logo_id'    => isset($payload['business_logo_id']) ? (string) max(0, (int) $payload['business_logo_id']) : '0',
                'business_email'      => isset($payload['business_email']) ? sanitize_email($payload['business_email']) : '',
                'business_description' => isset($payload['business_description']) ? sanitize_textarea_field($payload['business_description']) : '',
                'business_hours'      => isset($payload['business_hours']) ? sanitize_textarea_field($payload['business_hours']) : '',
                'address_street'      => isset($payload['address_street']) ? sanitize_text_field($payload['address_street']) : '',
                'address_city'        => isset($payload['address_city']) ? sanitize_text_field($payload['address_city']) : '',
                'address_state'       => isset($payload['address_state']) ? sanitize_text_field($payload['address_state']) : '',
                'address_zip'         => isset($payload['address_zip']) ? sanitize_text_field($payload['address_zip']) : '',
                'internal_notes'      => isset($payload['internal_notes']) ? sanitize_textarea_field($payload['internal_notes']) : '',
                'is_featured'         => $is_featured,
                'featured_order'      => (string) $featured_order,
            );

            if (empty($clean['nap_address'])) {
                $clean['nap_address'] = self::compose_full_address($clean);
            }

            return $clean;
        }

        private static function compose_full_address(array $clean)
        {
            $line_one = isset($clean['address_street']) ? trim((string) $clean['address_street']) : '';
            $city = isset($clean['address_city']) ? trim((string) $clean['address_city']) : '';
            $state = isset($clean['address_state']) ? trim((string) $clean['address_state']) : '';
            $zip = isset($clean['address_zip']) ? trim((string) $clean['address_zip']) : '';

            $city_state = trim(implode(', ', array_filter(array($city, $state))));
            $city_state_zip = trim(implode(' ', array_filter(array($city_state, $zip))));

            return trim(implode("\n", array_filter(array($line_one, $city_state_zip))));
        }

        private static function display_address(array $profile)
        {
            $address = self::compose_full_address($profile);
            if ('' !== $address) {
                return $address;
            }

            return isset($profile['nap_address']) ? (string) $profile['nap_address'] : '';
        }

        public static function register_admin_columns($columns)
        {
            unset($columns['date']);

            $columns['directory_name'] = __('Directory', 'madextra-citations');
            $columns['status'] = __('Status', 'madextra-citations');
            $columns['market'] = __('Market', 'madextra-citations');
            $columns['service'] = __('Service', 'madextra-citations');
            $columns['last_verified_date'] = __('Last Verified', 'madextra-citations');
            $columns['is_featured'] = __('Featured', 'madextra-citations');
            $columns['date'] = __('Published', 'madextra-citations');

            return $columns;
        }

        public static function render_admin_columns($column, $post_id)
        {
            if ('directory_name' === $column) {
                echo esc_html(get_post_meta($post_id, self::META_PREFIX . 'directory_name', true));
                return;
            }

            if ('status' === $column) {
                $status = get_post_meta($post_id, self::META_PREFIX . 'status', true);
                $options = self::status_options();
                echo isset($options[$status]) ? esc_html($options[$status]) : esc_html($status);
                return;
            }

            if ('market' === $column) {
                $terms = wp_get_object_terms($post_id, self::TAX_MARKET, array('fields' => 'names'));
                echo $terms && !is_wp_error($terms) ? esc_html(implode(', ', $terms)) : '&mdash;';
                return;
            }

            if ('service' === $column) {
                $terms = wp_get_object_terms($post_id, self::TAX_SERVICE, array('fields' => 'names'));
                echo $terms && !is_wp_error($terms) ? esc_html(implode(', ', $terms)) : '&mdash;';
                return;
            }

            if ('last_verified_date' === $column) {
                $date = get_post_meta($post_id, self::META_PREFIX . 'last_verified_date', true);
                echo $date ? esc_html($date) : '&mdash;';
                return;
            }

            if ('is_featured' === $column) {
                $featured = get_post_meta($post_id, self::META_PREFIX . 'is_featured', true);
                $order = (int) get_post_meta($post_id, self::META_PREFIX . 'featured_order', true);
                echo '1' === $featured ? esc_html(sprintf(__('Yes, slot %d', 'madextra-citations'), max(1, $order))) : esc_html__('No', 'madextra-citations');
            }
        }

        public static function render_admin_filters($post_type)
        {
            if (self::CPT !== $post_type) {
                return;
            }

            $selected_status = isset($_GET['mec_status_filter']) ? sanitize_key(wp_unslash($_GET['mec_status_filter'])) : '';

            wp_dropdown_categories(
                array(
                    'show_option_all' => __('All Markets', 'madextra-citations'),
                    'taxonomy'        => self::TAX_MARKET,
                    'name'            => self::TAX_MARKET,
                    'orderby'         => 'name',
                    'selected'        => isset($_GET[self::TAX_MARKET]) ? (int) $_GET[self::TAX_MARKET] : '',
                    'show_count'      => true,
                    'hide_empty'      => false,
                )
            );

            wp_dropdown_categories(
                array(
                    'show_option_all' => __('All Services', 'madextra-citations'),
                    'taxonomy'        => self::TAX_SERVICE,
                    'name'            => self::TAX_SERVICE,
                    'orderby'         => 'name',
                    'selected'        => isset($_GET[self::TAX_SERVICE]) ? (int) $_GET[self::TAX_SERVICE] : '',
                    'show_count'      => true,
                    'hide_empty'      => false,
                )
            );

            echo '<select name="mec_status_filter">';
            echo '<option value="">' . esc_html__('All Statuses', 'madextra-citations') . '</option>';
            foreach (self::status_options() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($selected_status, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }

        public static function apply_admin_filters($query)
        {
            if (!is_admin() || !$query->is_main_query()) {
                return $query;
            }

            if (self::CPT !== $query->get('post_type')) {
                return $query;
            }

            $status = isset($_GET['mec_status_filter']) ? sanitize_key(wp_unslash($_GET['mec_status_filter'])) : '';
            if ($status) {
                $query->set(
                    'meta_query',
                    array(
                        array(
                            'key'   => self::META_PREFIX . 'status',
                            'value' => $status,
                        ),
                    )
                );
            }

            return $query;
        }

        public static function register_tools_submenu()
        {
            add_submenu_page(
                'edit.php?post_type=' . self::CPT,
                __('Citation CSV Tools', 'madextra-citations'),
                __('CSV Tools', 'madextra-citations'),
                'read',
                'mec-csv-tools',
                array(__CLASS__, 'render_tools_page')
            );
        }

        public static function render_tools_page()
        {
            if (!self::can_access_tools_page()) {
                wp_die(esc_html__('You do not have permission to view this page.', 'madextra-citations'));
            }

            $markets = get_terms(array('taxonomy' => self::TAX_MARKET, 'hide_empty' => false));
            $services = get_terms(array('taxonomy' => self::TAX_SERVICE, 'hide_empty' => false));
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Citation CSV Tools', 'madextra-citations'); ?></h1>
                <p><?php esc_html_e('Bulk import or export citation profiles.', 'madextra-citations'); ?></p>

                <hr>
                <h2><?php esc_html_e('Export Profiles', 'madextra-citations'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_EXPORT, self::NONCE_EXPORT); ?>
                    <input type="hidden" name="action" value="mec_export_csv">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mec_export_market"><?php esc_html_e('Market Filter', 'madextra-citations'); ?></label></th>
                            <td>
                                <select id="mec_export_market" name="market_id">
                                    <option value=""><?php esc_html_e('All markets', 'madextra-citations'); ?></option>
                                    <?php if (!is_wp_error($markets)) : foreach ($markets as $term) : ?>
                                        <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mec_export_service"><?php esc_html_e('Service Filter', 'madextra-citations'); ?></label></th>
                            <td>
                                <select id="mec_export_service" name="service_id">
                                    <option value=""><?php esc_html_e('All services', 'madextra-citations'); ?></option>
                                    <?php if (!is_wp_error($services)) : foreach ($services as $term) : ?>
                                        <option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mec_export_status"><?php esc_html_e('Status Filter', 'madextra-citations'); ?></label></th>
                            <td>
                                <select id="mec_export_status" name="status">
                                    <option value=""><?php esc_html_e('All statuses', 'madextra-citations'); ?></option>
                                    <?php foreach (self::status_options() as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Export CSV', 'madextra-citations')); ?>
                </form>

                <hr>
                <h2><?php esc_html_e('Import Profiles', 'madextra-citations'); ?></h2>
                <p><?php echo esc_html(sprintf(__('Accepted CSV columns: %s.', 'madextra-citations'), implode(', ', self::csv_headers()))); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field(self::NONCE_IMPORT, self::NONCE_IMPORT); ?>
                    <input type="hidden" name="action" value="mec_import_csv">
                    <input type="file" name="mec_import_file" accept=".csv,text/csv" required>
                    <?php submit_button(__('Import CSV', 'madextra-citations')); ?>
                </form>
            </div>
            <?php
        }

        public static function handle_export_request()
        {
            if (!self::can_export_profiles()) {
                wp_die(esc_html__('You do not have permission to export citation profiles.', 'madextra-citations'));
            }

            check_admin_referer(self::NONCE_EXPORT, self::NONCE_EXPORT);

            $filters = array(
                'market_id'  => isset($_POST['market_id']) ? (int) $_POST['market_id'] : 0,
                'service_id' => isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0,
                'status'     => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '',
            );

            $rows = self::collect_profile_rows($filters);
            $filename = 'citation-profiles-' . gmdate('Ymd-His') . '.csv';

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);

            $out = fopen('php://output', 'w');
            fputcsv($out, self::csv_headers());

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
            exit;
        }

        public static function handle_import_request()
        {
            if (!self::can_import_profiles()) {
                wp_die(esc_html__('You do not have permission to import citation profiles.', 'madextra-citations'));
            }

            check_admin_referer(self::NONCE_IMPORT, self::NONCE_IMPORT);

            if (empty($_FILES['mec_import_file']['tmp_name'])) {
                self::queue_notice(__('Import failed: no CSV file uploaded.', 'madextra-citations'), 'error');
                self::redirect_tools_page();
            }

            $tmp_name = sanitize_text_field(wp_unslash($_FILES['mec_import_file']['tmp_name']));
            $handle = fopen($tmp_name, 'r');

            if (!$handle) {
                self::queue_notice(__('Import failed: unable to read file.', 'madextra-citations'), 'error');
                self::redirect_tools_page();
            }

            $header = fgetcsv($handle);
            if (!$header || !is_array($header)) {
                fclose($handle);
                self::queue_notice(__('Import failed: CSV is empty or missing headers.', 'madextra-citations'), 'error');
                self::redirect_tools_page();
            }

            $normalized_headers = array_map(array(__CLASS__, 'normalize_header_key'), $header);

            $processed = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = array();

            while (($row = fgetcsv($handle)) !== false) {
                $processed++;
                $assoc = array();
                foreach ($normalized_headers as $index => $key) {
                    $assoc[$key] = isset($row[$index]) ? $row[$index] : '';
                }

                $result = self::upsert_profile_from_row($assoc);
                if (is_wp_error($result)) {
                    $skipped++;
                    $errors[] = sprintf(
                        /* translators: %1$d row number, %2$s error text */
                        __('Row %1$d skipped: %2$s', 'madextra-citations'),
                        $processed + 1,
                        $result->get_error_message()
                    );
                    continue;
                }

                if ('created' === $result['action']) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            fclose($handle);

            $summary = sprintf(
                /* translators: %1$d processed, %2$d created, %3$d updated, %4$d skipped */
                __('CSV import complete. Processed: %1$d, Created: %2$d, Updated: %3$d, Skipped: %4$d.', 'madextra-citations'),
                $processed,
                $created,
                $updated,
                $skipped
            );

            if ($errors) {
                $summary .= ' ' . implode(' ', array_slice($errors, 0, 6));
            }

            self::queue_notice($summary, $errors ? 'warning' : 'success');
            self::redirect_tools_page();
        }

        private static function upsert_profile_from_row(array $row)
        {
            $mapped = self::map_row_keys($row);
            $clean = self::sanitize_profile_payload($mapped);
            $validation = self::validate_required_profile_data($clean);

            if (is_wp_error($validation)) {
                return $validation;
            }

            $market_names = self::csv_list_to_array(isset($mapped['market']) ? $mapped['market'] : '');
            $service_names = self::csv_list_to_array(isset($mapped['service']) ? $mapped['service'] : '');

            $market_ids = self::ensure_terms(self::TAX_MARKET, $market_names);
            $service_ids = self::ensure_terms(self::TAX_SERVICE, $service_names);

            $unique_key = self::build_unique_key($clean, $market_ids, $service_ids);
            $existing = self::find_profile_by_unique_key($unique_key);

            $featured_validation = self::validate_featured_slot($existing, $clean, $market_ids);
            if (is_wp_error($featured_validation)) {
                return $featured_validation;
            }

            $post_data = array(
                'post_type'   => self::CPT,
                'post_status' => 'publish',
                'post_title'  => $clean['directory_name'],
            );

            $action = 'created';
            if ($existing) {
                $post_data['ID'] = $existing;
                wp_update_post($post_data);
                $post_id = $existing;
                $action = 'updated';
            } else {
                $post_id = wp_insert_post($post_data, true);
                if (is_wp_error($post_id)) {
                    return $post_id;
                }
            }

            foreach ($clean as $key => $value) {
                update_post_meta($post_id, self::META_PREFIX . $key, $value);
            }
            update_post_meta($post_id, self::META_PREFIX . 'unique_key', $unique_key);

            wp_set_object_terms($post_id, $market_ids, self::TAX_MARKET, false);
            wp_set_object_terms($post_id, $service_ids, self::TAX_SERVICE, false);

            return array(
                'action'  => $action,
                'post_id' => $post_id,
            );
        }

        private static function validate_required_profile_data(array $clean)
        {
            if (empty($clean['directory_name'])) {
                return new WP_Error('missing_directory_name', __('Missing required field: directory_name.', 'madextra-citations'));
            }
            if (empty($clean['listing_url'])) {
                return new WP_Error('missing_listing_url', __('Missing required field: listing_url.', 'madextra-citations'));
            }
            if (!wp_http_validate_url($clean['listing_url'])) {
                return new WP_Error('invalid_listing_url', __('Invalid listing_url. Please provide a valid absolute URL.', 'madextra-citations'));
            }
            if (!empty($clean['business_website_url']) && !wp_http_validate_url($clean['business_website_url'])) {
                return new WP_Error('invalid_business_website_url', __('Invalid business_website_url. Please provide a valid absolute URL.', 'madextra-citations'));
            }
            if (!empty($clean['business_email']) && !is_email($clean['business_email'])) {
                return new WP_Error('invalid_business_email', __('Invalid business_email. Please provide a valid email address.', 'madextra-citations'));
            }
            if (empty($clean['nap_business_name'])) {
                return new WP_Error('missing_nap_business_name', __('Missing required field: nap_business_name.', 'madextra-citations'));
            }
            if (empty($clean['nap_address'])) {
                return new WP_Error('missing_nap_address', __('Missing required field: nap_address.', 'madextra-citations'));
            }
            if (empty($clean['nap_phone'])) {
                return new WP_Error('missing_nap_phone', __('Missing required field: nap_phone.', 'madextra-citations'));
            }

            return true;
        }

        public static function validate_featured_slot($post_id, array $clean, array $market_ids)
        {
            if (empty($clean['is_featured']) || '1' !== (string) $clean['is_featured']) {
                return true;
            }

            $market_ids = array_values(array_unique(array_filter(array_map('intval', $market_ids))));
            if (!$market_ids) {
                return new WP_Error('featured_market_required', __('Featured profiles must be assigned to at least one city/market.', 'madextra-citations'));
            }

            $featured_order = isset($clean['featured_order']) ? (int) $clean['featured_order'] : 1;
            $featured_order = min(3, max(1, $featured_order));

            foreach ($market_ids as $market_id) {
                $term = get_term($market_id, self::TAX_MARKET);
                $market_name = (!is_wp_error($term) && $term && !empty($term->name)) ? $term->name : __('this city', 'madextra-citations');

                $base_query = array(
                    'post_type'      => self::CPT,
                    'post_status'    => array('publish', 'draft', 'pending', 'private'),
                    'posts_per_page' => 4,
                    'fields'         => 'ids',
                    'post__not_in'   => $post_id ? array((int) $post_id) : array(),
                    'tax_query'      => array(
                        array(
                            'taxonomy' => self::TAX_MARKET,
                            'field'    => 'term_id',
                            'terms'    => (int) $market_id,
                        ),
                    ),
                    'meta_query'     => array(
                        array(
                            'key'   => self::META_PREFIX . 'is_featured',
                            'value' => '1',
                        ),
                    ),
                );

                $count_query = new WP_Query($base_query);
                if (count($count_query->posts) >= 3) {
                    return new WP_Error(
                        'featured_city_limit',
                        sprintf(
                            /* translators: %s city/market name */
                            __('%s already has 3 featured profiles. Remove one before adding another.', 'madextra-citations'),
                            $market_name
                        )
                    );
                }

                $slot_query_args = $base_query;
                $slot_query_args['posts_per_page'] = 1;
                $slot_query_args['meta_query'][] = array(
                    'key'   => self::META_PREFIX . 'featured_order',
                    'value' => (string) $featured_order,
                );
                $slot_query = new WP_Query($slot_query_args);
                if (!empty($slot_query->posts)) {
                    return new WP_Error(
                        'featured_slot_taken',
                        sprintf(
                            /* translators: %1$d featured slot number, %2$s city/market name */
                            __('Featured position %1$d is already used in %2$s.', 'madextra-citations'),
                            $featured_order,
                            $market_name
                        )
                    );
                }
            }

            return true;
        }

        private static function map_row_keys(array $row)
        {
            $result = array();
            foreach ($row as $key => $value) {
                $norm = self::normalize_header_key($key);
                $result[$norm] = is_string($value) ? trim($value) : $value;
            }

            $aliases = array(
                'directory'       => 'directory_name',
                'url'             => 'listing_url',
                'listing'         => 'listing_url',
                'verified_date'   => 'last_verified_date',
                'notes'           => 'public_notes',
                'business_name'   => 'nap_business_name',
                'address'         => 'nap_address',
                'phone'           => 'nap_phone',
                'website'         => 'business_website_url',
                'business_url'    => 'business_website_url',
                'logo'            => 'business_logo_id',
                'logo_id'         => 'business_logo_id',
                'email'           => 'business_email',
                'description'     => 'business_description',
                'hours'           => 'business_hours',
                'street'          => 'address_street',
                'city'            => 'address_city',
                'state'           => 'address_state',
                'zip'             => 'address_zip',
                'zipcode'         => 'address_zip',
                'featured'        => 'is_featured',
                'featured_slot'   => 'featured_order',
                'markets'         => 'market',
                'services'        => 'service',
            );

            foreach ($aliases as $old => $new) {
                if (!isset($result[$new]) && isset($result[$old])) {
                    $result[$new] = $result[$old];
                }
            }

            return $result;
        }

        private static function normalize_header_key($key)
        {
            $key = strtolower((string) $key);
            $key = str_replace(array('-', ' '), '_', $key);
            $key = preg_replace('/[^a-z0-9_]/', '', $key);
            return trim($key, '_');
        }

        private static function csv_list_to_array($value)
        {
            if (!is_string($value) || '' === trim($value)) {
                return array();
            }

            $parts = preg_split('/[|,]/', $value);
            $parts = array_map('trim', $parts);
            $parts = array_filter($parts);
            return array_values(array_unique($parts));
        }

        private static function ensure_terms($taxonomy, array $term_names)
        {
            if (!$term_names) {
                return array();
            }

            $ids = array();
            foreach ($term_names as $name) {
                $existing = term_exists($name, $taxonomy);
                if ($existing && !is_wp_error($existing)) {
                    $ids[] = (int) (is_array($existing) ? $existing['term_id'] : $existing);
                    continue;
                }

                $created = wp_insert_term($name, $taxonomy);
                if (!is_wp_error($created) && isset($created['term_id'])) {
                    $ids[] = (int) $created['term_id'];
                }
            }

            return array_values(array_unique(array_filter($ids)));
        }

        private static function find_profile_by_unique_key($unique_key)
        {
            $query = new WP_Query(
                array(
                    'post_type'      => self::CPT,
                    'post_status'    => array('publish', 'draft', 'pending', 'private'),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'   => self::META_PREFIX . 'unique_key',
                            'value' => $unique_key,
                        ),
                    ),
                )
            );

            if (!empty($query->posts[0])) {
                return (int) $query->posts[0];
            }

            return 0;
        }

        private static function build_unique_key(array $clean, array $market_ids, array $service_ids)
        {
            sort($market_ids);
            sort($service_ids);

            return md5(
                strtolower(trim($clean['directory_name'])) . '|' .
                strtolower(trim($clean['listing_url'])) . '|' .
                implode('-', $market_ids) . '|' .
                implode('-', $service_ids)
            );
        }

        private static function queue_notice($message, $type = 'success')
        {
            set_transient(
                self::NOTICE_TRANSIENT,
                array(
                    'message' => $message,
                    'type'    => $type,
                ),
                120
            );
        }

        public static function render_admin_notice()
        {
            $notice = get_transient(self::NOTICE_TRANSIENT);
            if (!$notice || empty($notice['message'])) {
                return;
            }

            delete_transient(self::NOTICE_TRANSIENT);

            $type = isset($notice['type']) ? sanitize_html_class($notice['type']) : 'success';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        public static function render_capability_debug_notice()
        {
            if (!is_admin()) {
                return;
            }

            if (!isset($_GET['mec_caps_debug']) || '1' !== sanitize_text_field(wp_unslash($_GET['mec_caps_debug']))) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            $post_type_object = get_post_type_object(self::CPT);
            $runtime_edit_posts_cap = $post_type_object && isset($post_type_object->cap->edit_posts)
                ? (string) $post_type_object->cap->edit_posts
                : '';
            $runtime_create_posts_cap = $post_type_object && isset($post_type_object->cap->create_posts)
                ? (string) $post_type_object->cap->create_posts
                : '';

            $checks = array(
                'create_citation_profiles' => current_user_can('create_citation_profiles'),
                'edit_citation_profiles'   => current_user_can('edit_citation_profiles'),
                'publish_citation_profiles' => current_user_can('publish_citation_profiles'),
                'manage_citation_profiles' => current_user_can('manage_citation_profiles'),
                'manage_citation_builder' => current_user_can('manage_citation_builder'),
                'edit_posts' => current_user_can('edit_posts'),
                'manage_options' => current_user_can('manage_options'),
                'runtime_cpt_edit_posts' => $runtime_edit_posts_cap ? current_user_can($runtime_edit_posts_cap) : false,
                'runtime_cpt_create_posts' => $runtime_create_posts_cap ? current_user_can($runtime_create_posts_cap) : false,
            );

            $parts = array();
            foreach ($checks as $cap => $has_cap) {
                $parts[] = $cap . ': ' . ($has_cap ? 'yes' : 'no');
            }

            $add_new_url = admin_url('post-new.php?post_type=' . self::CPT);
            echo '<div class="notice notice-info"><p><strong>MadExtra Citations Debug:</strong> ';
            echo esc_html(implode(' | ', $parts));
            echo ' | <a href="' . esc_url($add_new_url) . '">Open Add New Citation Profile</a>';
            echo '</p></div>';
        }

        public static function redirect_tools_page()
        {
            $url = add_query_arg(
                array(
                    'post_type' => self::CPT,
                    'page'      => 'mec-csv-tools',
                ),
                admin_url('edit.php')
            );

            wp_safe_redirect($url);
            exit;
        }

        public static function maybe_redirect_legacy_tools_page()
        {
            if (!is_admin() || !current_user_can('read')) {
                return;
            }

            $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            if ('mec-csv-tools-builder' !== $page) {
                return;
            }

            self::redirect_tools_page();
        }

        private static function has_admin_fallback()
        {
            return current_user_can('manage_options') || current_user_can('manage_network_options');
        }

        private static function can_access_tools_page()
        {
            return self::has_admin_fallback()
                || current_user_can('manage_citation_profiles')
                || current_user_can('import_citation_profiles')
                || current_user_can('export_citation_profiles')
                || current_user_can('manage_citation_builder');
        }

        private static function can_import_profiles()
        {
            return self::has_admin_fallback()
                || current_user_can('import_citation_profiles')
                || current_user_can('manage_citation_profiles');
        }

        private static function can_export_profiles()
        {
            return self::has_admin_fallback()
                || current_user_can('export_citation_profiles')
                || current_user_can('manage_citation_profiles');
        }

        private static function csv_headers()
        {
            return array(
                'directory_name',
                'listing_url',
                'status',
                'last_verified_date',
                'public_notes',
                'nap_business_name',
                'nap_address',
                'nap_phone',
                'business_website_url',
                'business_logo_id',
                'business_email',
                'business_description',
                'business_hours',
                'address_street',
                'address_city',
                'address_state',
                'address_zip',
                'internal_notes',
                'is_featured',
                'featured_order',
                'market',
                'service',
            );
        }

        private static function collect_profile_rows(array $filters = array())
        {
            $query_args = array(
                'post_type'      => self::CPT,
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            );

            $tax_query = array();
            if (!empty($filters['market_id'])) {
                $tax_query[] = array(
                    'taxonomy' => self::TAX_MARKET,
                    'field'    => 'term_id',
                    'terms'    => (int) $filters['market_id'],
                );
            }
            if (!empty($filters['service_id'])) {
                $tax_query[] = array(
                    'taxonomy' => self::TAX_SERVICE,
                    'field'    => 'term_id',
                    'terms'    => (int) $filters['service_id'],
                );
            }
            if ($tax_query) {
                $query_args['tax_query'] = $tax_query;
            }

            if (!empty($filters['status'])) {
                $query_args['meta_query'] = array(
                    array(
                        'key'   => self::META_PREFIX . 'status',
                        'value' => sanitize_key($filters['status']),
                    ),
                );
            }

            $query = new WP_Query($query_args);
            $rows = array();

            foreach ($query->posts as $post) {
                $post_id = $post->ID;
                $markets = wp_get_object_terms($post_id, self::TAX_MARKET, array('fields' => 'names'));
                $services = wp_get_object_terms($post_id, self::TAX_SERVICE, array('fields' => 'names'));

                $rows[] = array(
                    get_post_meta($post_id, self::META_PREFIX . 'directory_name', true),
                    get_post_meta($post_id, self::META_PREFIX . 'listing_url', true),
                    get_post_meta($post_id, self::META_PREFIX . 'status', true),
                    get_post_meta($post_id, self::META_PREFIX . 'last_verified_date', true),
                    get_post_meta($post_id, self::META_PREFIX . 'public_notes', true),
                    get_post_meta($post_id, self::META_PREFIX . 'nap_business_name', true),
                    get_post_meta($post_id, self::META_PREFIX . 'nap_address', true),
                    get_post_meta($post_id, self::META_PREFIX . 'nap_phone', true),
                    get_post_meta($post_id, self::META_PREFIX . 'business_website_url', true),
                    get_post_meta($post_id, self::META_PREFIX . 'business_logo_id', true),
                    get_post_meta($post_id, self::META_PREFIX . 'business_email', true),
                    get_post_meta($post_id, self::META_PREFIX . 'business_description', true),
                    get_post_meta($post_id, self::META_PREFIX . 'business_hours', true),
                    get_post_meta($post_id, self::META_PREFIX . 'address_street', true),
                    get_post_meta($post_id, self::META_PREFIX . 'address_city', true),
                    get_post_meta($post_id, self::META_PREFIX . 'address_state', true),
                    get_post_meta($post_id, self::META_PREFIX . 'address_zip', true),
                    get_post_meta($post_id, self::META_PREFIX . 'internal_notes', true),
                    get_post_meta($post_id, self::META_PREFIX . 'is_featured', true),
                    get_post_meta($post_id, self::META_PREFIX . 'featured_order', true),
                    !is_wp_error($markets) ? implode('|', $markets) : '',
                    !is_wp_error($services) ? implode('|', $services) : '',
                );
            }

            return $rows;
        }

        public static function register_rest_routes()
        {
            register_rest_route(
                'madextra-citations/v1',
                '/profiles',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(__CLASS__, 'rest_get_profiles'),
                    'permission_callback' => '__return_true',
                )
            );

            register_rest_route(
                'madextra-citations/v1',
                '/import',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array(__CLASS__, 'rest_import_profiles'),
                    'permission_callback' => function () {
                        return self::can_import_profiles();
                    },
                )
            );

            register_rest_route(
                'madextra-citations/v1',
                '/export',
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array(__CLASS__, 'rest_export_profiles'),
                    'permission_callback' => function () {
                        return self::can_export_profiles();
                    },
                )
            );
        }

        public static function rest_get_profiles(WP_REST_Request $request)
        {
            $raw_limit = $request->get_param('limit');
            $limit = (null === $raw_limit || '' === $raw_limit) ? 50 : (int) $raw_limit;
            $limit = min(200, max(1, $limit));

            $filters = array(
                'status'  => sanitize_key((string) $request->get_param('status')),
                'market'  => sanitize_text_field((string) $request->get_param('market')),
                'service' => sanitize_text_field((string) $request->get_param('service')),
                'search'  => sanitize_text_field((string) $request->get_param('search')),
                'page'    => max(1, (int) $request->get_param('page')),
                'limit'   => $limit,
            );

            $query_args = array(
                'post_type'      => self::CPT,
                'post_status'    => 'publish',
                'posts_per_page' => $filters['limit'],
                'paged'          => $filters['page'],
                'orderby'        => 'title',
                'order'          => 'ASC',
            );

            $tax_query = array();
            if ($filters['market']) {
                $tax_query[] = array(
                    'taxonomy' => self::TAX_MARKET,
                    'field'    => 'slug',
                    'terms'    => sanitize_title($filters['market']),
                );
            }
            if ($filters['service']) {
                $tax_query[] = array(
                    'taxonomy' => self::TAX_SERVICE,
                    'field'    => 'slug',
                    'terms'    => sanitize_title($filters['service']),
                );
            }
            if ($tax_query) {
                $query_args['tax_query'] = $tax_query;
            }

            if ($filters['status']) {
                $query_args['meta_query'] = array(
                    array(
                        'key'   => self::META_PREFIX . 'status',
                        'value' => $filters['status'],
                    ),
                );
            }

            if ($filters['search']) {
                $query_args['s'] = $filters['search'];
            }

            $query = new WP_Query($query_args);
            $items = array();

            foreach ($query->posts as $post) {
                $items[] = self::profile_to_public_data($post->ID);
            }

            return rest_ensure_response(
                array(
                    'items'      => $items,
                    'total'      => (int) $query->found_posts,
                    'totalPages' => (int) $query->max_num_pages,
                    'page'       => $filters['page'],
                )
            );
        }

        public static function rest_import_profiles(WP_REST_Request $request)
        {
            $rows = $request->get_param('rows');
            if (!is_array($rows)) {
                return new WP_Error('invalid_rows', __('rows must be an array.', 'madextra-citations'), array('status' => 400));
            }

            $processed = 0;
            $created = 0;
            $updated = 0;
            $errors = array();

            foreach ($rows as $row) {
                $processed++;
                if (!is_array($row)) {
                    $errors[] = sprintf(__('Row %d is not an object.', 'madextra-citations'), $processed);
                    continue;
                }

                $result = self::upsert_profile_from_row($row);
                if (is_wp_error($result)) {
                    $errors[] = sprintf(__('Row %1$d failed: %2$s', 'madextra-citations'), $processed, $result->get_error_message());
                    continue;
                }

                if ('created' === $result['action']) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            return rest_ensure_response(
                array(
                    'processed' => $processed,
                    'created'   => $created,
                    'updated'   => $updated,
                    'errors'    => $errors,
                )
            );
        }

        public static function rest_export_profiles(WP_REST_Request $request)
        {
            $filters = array(
                'status' => sanitize_key((string) $request->get_param('status')),
            );

            $rows = self::collect_profile_rows($filters);
            $headers = self::csv_headers();

            $items = array();
            foreach ($rows as $row) {
                $items[] = array_combine($headers, $row);
            }

            return rest_ensure_response(
                array(
                    'count' => count($items),
                    'items' => $items,
                )
            );
        }

        public static function render_public_submit_form_shortcode($atts)
        {
            $markets = get_terms(array('taxonomy' => self::TAX_MARKET, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $services = get_terms(array('taxonomy' => self::TAX_SERVICE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
            $current_url = home_url($request_uri);
            $notice = isset($_GET['mec_submit']) ? sanitize_key(wp_unslash($_GET['mec_submit'])) : '';

            ob_start();
            ?>
            <div class="mec-public-submit-wrap">
                <?php if ('success' === $notice) : ?>
                    <div class="mec-submit-note mec-submit-note-success"><?php esc_html_e('Thanks. Your profile was submitted for review.', 'madextra-citations'); ?></div>
                <?php elseif ('error' === $notice) : ?>
                    <div class="mec-submit-note mec-submit-note-error"><?php esc_html_e('Submission failed. Check the required fields and try again.', 'madextra-citations'); ?></div>
                <?php endif; ?>

                <form class="mec-public-submit-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="mec_public_submit">
                    <input type="hidden" name="mec_redirect" value="<?php echo esc_attr($current_url); ?>">
                    <input type="text" name="mec_company" value="" tabindex="-1" autocomplete="off" class="mec-hp-field" aria-hidden="true">
                    <?php wp_nonce_field('mec_public_submit', self::NONCE_PUBLIC_SUBMIT); ?>

                    <label><?php esc_html_e('Business Name', 'madextra-citations'); ?></label>
                    <input type="text" name="mec[nap_business_name]" required>

                    <label><?php esc_html_e('Business Website', 'madextra-citations'); ?></label>
                    <input type="url" name="mec[business_website_url]" required>

                    <label><?php esc_html_e('Phone', 'madextra-citations'); ?></label>
                    <input type="text" name="mec[nap_phone]" required>

                    <label><?php esc_html_e('Email', 'madextra-citations'); ?></label>
                    <input type="email" name="mec[business_email]">

                    <label><?php esc_html_e('Street Address', 'madextra-citations'); ?></label>
                    <input type="text" name="mec[address_street]" required>

                    <div class="mec-submit-grid">
                        <div>
                            <label><?php esc_html_e('City', 'madextra-citations'); ?></label>
                            <input type="text" name="mec[address_city]" required>
                        </div>
                        <div>
                            <label><?php esc_html_e('State', 'madextra-citations'); ?></label>
                            <input type="text" name="mec[address_state]" maxlength="24" required>
                        </div>
                        <div>
                            <label><?php esc_html_e('ZIP', 'madextra-citations'); ?></label>
                            <input type="text" name="mec[address_zip]" required>
                        </div>
                    </div>

                    <label><?php esc_html_e('Directory City/Market', 'madextra-citations'); ?></label>
                    <select name="mec_market_id">
                        <option value=""><?php esc_html_e('Choose a city', 'madextra-citations'); ?></option>
                        <?php if (!is_wp_error($markets)) : foreach ($markets as $term) : ?>
                            <option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                    <input type="text" name="mec_market_text" placeholder="<?php esc_attr_e('Or type a new city', 'madextra-citations'); ?>">

                    <label><?php esc_html_e('Service', 'madextra-citations'); ?></label>
                    <select name="mec_service_id">
                        <option value=""><?php esc_html_e('Choose a service', 'madextra-citations'); ?></option>
                        <?php if (!is_wp_error($services)) : foreach ($services as $term) : ?>
                            <option value="<?php echo esc_attr((string) $term->term_id); ?>"><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                    <input type="text" name="mec_service_text" placeholder="<?php esc_attr_e('Or type a new service', 'madextra-citations'); ?>">

                    <label><?php esc_html_e('Business Description', 'madextra-citations'); ?></label>
                    <textarea name="mec[business_description]" rows="4"></textarea>

                    <label><?php esc_html_e('Business Hours', 'madextra-citations'); ?></label>
                    <textarea name="mec[business_hours]" rows="4" placeholder="<?php esc_attr_e('Monday-Friday 9am-5pm', 'madextra-citations'); ?>"></textarea>

                    <label><?php esc_html_e('Logo', 'madextra-citations'); ?></label>
                    <input type="file" name="mec_logo" accept="image/*">
                    <p class="mec-submit-help"><?php esc_html_e('Image files only. Maximum size: 2MB.', 'madextra-citations'); ?></p>

                    <button type="submit"><?php esc_html_e('Submit Profile', 'madextra-citations'); ?></button>
                </form>
            </div>
            <style>
                .mec-public-submit-wrap { display:grid; gap:14px; }
                .mec-submit-note { padding:10px 12px; border-radius:8px; font-weight:700; }
                .mec-submit-note-success { background:#ecfdf3; color:#0a6b38; border:1px solid #a7f3d0; }
                .mec-submit-note-error { background:#fff5f5; color:#a4001d; border:1px solid #fecaca; }
                .mec-public-submit-form { display:grid; gap:10px; }
                .mec-public-submit-form input, .mec-public-submit-form select, .mec-public-submit-form textarea { width:100%; border:1px solid #ccd8ec; border-radius:8px; padding:10px; font:inherit; }
                .mec-submit-grid { display:grid; grid-template-columns:1.3fr .7fr .8fr; gap:10px; }
                .mec-submit-help { margin:0; color:#66748a; font-size:.9rem; }
                .mec-public-submit-form button { border:0; border-radius:8px; background:#1b4dd8; color:#fff; padding:11px 14px; font-weight:700; cursor:pointer; }
                .mec-hp-field { position:absolute !important; left:-9999px !important; width:1px !important; height:1px !important; opacity:0 !important; }
                @media (max-width: 760px) { .mec-submit-grid { grid-template-columns:1fr; } }
            </style>
            <?php
            return ob_get_clean();
        }

        public static function handle_public_submit_request()
        {
            $redirect = isset($_POST['mec_redirect']) ? esc_url_raw(wp_unslash($_POST['mec_redirect'])) : home_url('/citations/');
            $redirect = wp_validate_redirect($redirect, home_url('/citations/'));

            if (!empty($_POST['mec_company'])) {
                wp_safe_redirect(add_query_arg('mec_submit', 'success', $redirect));
                exit;
            }

            if (!isset($_POST[self::NONCE_PUBLIC_SUBMIT]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_PUBLIC_SUBMIT])), 'mec_public_submit')) {
                wp_safe_redirect(add_query_arg('mec_submit', 'error', $redirect));
                exit;
            }

            $payload = isset($_POST['mec']) ? (array) wp_unslash($_POST['mec']) : array();
            $business_name = isset($payload['nap_business_name']) ? sanitize_text_field($payload['nap_business_name']) : '';
            $website = isset($payload['business_website_url']) ? esc_url_raw($payload['business_website_url']) : '';

            $payload['directory_name'] = $business_name;
            $payload['listing_url'] = $website;
            $payload['status'] = 'pending';
            $payload['public_notes'] = '';
            $payload['internal_notes'] = '';
            $payload['is_featured'] = '0';
            $payload['featured_order'] = '0';

            $logo_id = self::handle_public_logo_upload();
            if (is_wp_error($logo_id)) {
                wp_safe_redirect(add_query_arg('mec_submit', 'error', $redirect));
                exit;
            }
            if ($logo_id) {
                $payload['business_logo_id'] = (string) $logo_id;
            }

            $clean = self::sanitize_profile_payload($payload);
            $validation = self::validate_required_profile_data($clean);
            if (is_wp_error($validation)) {
                wp_safe_redirect(add_query_arg('mec_submit', 'error', $redirect));
                exit;
            }

            $market_ids = self::resolve_public_term_ids(self::TAX_MARKET, 'mec_market_id', 'mec_market_text');
            if (!$market_ids && !empty($clean['address_city'])) {
                $market_ids = self::ensure_terms(self::TAX_MARKET, array($clean['address_city']));
            }
            if (!$market_ids) {
                wp_safe_redirect(add_query_arg('mec_submit', 'error', $redirect));
                exit;
            }
            $service_ids = self::resolve_public_term_ids(self::TAX_SERVICE, 'mec_service_id', 'mec_service_text');

            $post_id = wp_insert_post(
                array(
                    'post_type'   => self::CPT,
                    'post_status' => 'pending',
                    'post_title'  => $clean['directory_name'],
                ),
                true
            );

            if (is_wp_error($post_id)) {
                wp_safe_redirect(add_query_arg('mec_submit', 'error', $redirect));
                exit;
            }

            foreach ($clean as $key => $value) {
                update_post_meta($post_id, self::META_PREFIX . $key, $value);
            }

            wp_set_object_terms($post_id, $market_ids, self::TAX_MARKET, false);
            wp_set_object_terms($post_id, $service_ids, self::TAX_SERVICE, false);
            update_post_meta($post_id, self::META_PREFIX . 'unique_key', self::build_unique_key($clean, $market_ids, $service_ids));

            wp_safe_redirect(add_query_arg('mec_submit', 'success', $redirect));
            exit;
        }

        private static function resolve_public_term_ids($taxonomy, $id_key, $text_key)
        {
            $ids = array();
            $term_id = isset($_POST[$id_key]) ? (int) $_POST[$id_key] : 0;
            if ($term_id > 0 && term_exists($term_id, $taxonomy)) {
                $ids[] = $term_id;
            }

            $term_name = isset($_POST[$text_key]) ? sanitize_text_field(wp_unslash($_POST[$text_key])) : '';
            if ($term_name) {
                $ids = array_merge($ids, self::ensure_terms($taxonomy, array($term_name)));
            }

            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        private static function handle_public_logo_upload()
        {
            if (empty($_FILES['mec_logo']) || !isset($_FILES['mec_logo']['error']) || UPLOAD_ERR_NO_FILE === (int) $_FILES['mec_logo']['error']) {
                return 0;
            }

            if (UPLOAD_ERR_OK !== (int) $_FILES['mec_logo']['error']) {
                return new WP_Error('logo_upload_error', __('Logo upload failed.', 'madextra-citations'));
            }

            if (!empty($_FILES['mec_logo']['size']) && (int) $_FILES['mec_logo']['size'] > self::LOGO_MAX_BYTES) {
                return new WP_Error('logo_too_large', __('Logo must be 2MB or smaller.', 'madextra-citations'));
            }

            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('media_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $file = $_FILES['mec_logo'];
            $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
            if (empty($checked['type']) || 0 !== strpos($checked['type'], 'image/')) {
                return new WP_Error('invalid_logo_type', __('Logo must be an image file.', 'madextra-citations'));
            }

            $attachment_id = media_handle_upload('mec_logo', 0);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            return (int) $attachment_id;
        }

        private static function profile_to_public_data($post_id)
        {
            $markets = wp_get_object_terms($post_id, self::TAX_MARKET, array('fields' => 'names'));
            $services = wp_get_object_terms($post_id, self::TAX_SERVICE, array('fields' => 'names'));
            $logo_id = (int) get_post_meta($post_id, self::META_PREFIX . 'business_logo_id', true);
            $profile = array(
                'id'                   => (int) $post_id,
                'title'                => get_the_title($post_id),
                'directory_name'       => get_post_meta($post_id, self::META_PREFIX . 'directory_name', true),
                'listing_url'          => get_post_meta($post_id, self::META_PREFIX . 'listing_url', true),
                'status'               => get_post_meta($post_id, self::META_PREFIX . 'status', true),
                'last_verified_date'   => get_post_meta($post_id, self::META_PREFIX . 'last_verified_date', true),
                'public_notes'         => get_post_meta($post_id, self::META_PREFIX . 'public_notes', true),
                'nap_business_name'    => get_post_meta($post_id, self::META_PREFIX . 'nap_business_name', true),
                'nap_address'          => get_post_meta($post_id, self::META_PREFIX . 'nap_address', true),
                'nap_phone'            => get_post_meta($post_id, self::META_PREFIX . 'nap_phone', true),
                'business_website_url' => get_post_meta($post_id, self::META_PREFIX . 'business_website_url', true),
                'business_logo_id'     => (string) $logo_id,
                'business_logo_url'    => $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '',
                'business_email'       => get_post_meta($post_id, self::META_PREFIX . 'business_email', true),
                'business_description' => get_post_meta($post_id, self::META_PREFIX . 'business_description', true),
                'business_hours'       => get_post_meta($post_id, self::META_PREFIX . 'business_hours', true),
                'address_street'       => get_post_meta($post_id, self::META_PREFIX . 'address_street', true),
                'address_city'         => get_post_meta($post_id, self::META_PREFIX . 'address_city', true),
                'address_state'        => get_post_meta($post_id, self::META_PREFIX . 'address_state', true),
                'address_zip'          => get_post_meta($post_id, self::META_PREFIX . 'address_zip', true),
                'is_featured'          => get_post_meta($post_id, self::META_PREFIX . 'is_featured', true),
                'featured_order'       => get_post_meta($post_id, self::META_PREFIX . 'featured_order', true),
                'markets'              => !is_wp_error($markets) ? array_values($markets) : array(),
                'services'             => !is_wp_error($services) ? array_values($services) : array(),
            );

            $profile['display_address'] = self::display_address($profile);

            return $profile;
        }

        private static function profile_search_blob(array $profile, $services = '', $status_label = '')
        {
            return strtolower(
                implode(
                    ' ',
                    array(
                        isset($profile['directory_name']) ? $profile['directory_name'] : '',
                        isset($profile['nap_business_name']) ? $profile['nap_business_name'] : '',
                        $services,
                        $status_label,
                        isset($profile['public_notes']) ? $profile['public_notes'] : '',
                        isset($profile['business_description']) ? $profile['business_description'] : '',
                        isset($profile['business_hours']) ? $profile['business_hours'] : '',
                        isset($profile['business_website_url']) ? $profile['business_website_url'] : '',
                        isset($profile['business_email']) ? $profile['business_email'] : '',
                        isset($profile['display_address']) ? $profile['display_address'] : '',
                        isset($profile['nap_address']) ? $profile['nap_address'] : '',
                        isset($profile['nap_phone']) ? $profile['nap_phone'] : '',
                    )
                )
            );
        }

        public static function render_directory_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'show_filters' => 'yes',
                    'limit'        => 5000,
                    'per_city'     => 25,
                ),
                $atts,
                self::SHORTCODE
            );

            $per_city = min(100, max(1, (int) $atts['per_city']));
            $query = new WP_Query(
                array(
                    'post_type'      => self::CPT,
                    'post_status'    => 'publish',
                    'posts_per_page' => (int) $atts['limit'],
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                )
            );

            if (!$query->have_posts()) {
                return '<p>' . esc_html__('No citation profiles found.', 'madextra-citations') . '</p>';
            }

            $groups = array();
            $all_services = array();
            $all_statuses = array();
            $status_options = self::status_options();

            foreach ($query->posts as $post) {
                $post_id = $post->ID;
                $profile = self::profile_to_public_data($post_id);

                $markets = $profile['markets'];
                if (!$markets) {
                    $markets = array(__('Unassigned Market', 'madextra-citations'));
                }

                foreach ($markets as $market_name) {
                    if (!isset($groups[$market_name])) {
                        $groups[$market_name] = array();
                    }
                    $groups[$market_name][] = $profile;
                }

                foreach ($profile['services'] as $service) {
                    $all_services[$service] = $service;
                }

                if (!empty($profile['status'])) {
                    $all_statuses[$profile['status']] = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                }
            }

            ksort($groups);
            ksort($all_services);
            ksort($all_statuses);

            $instance_id = 'mec-dir-' . wp_rand(1000, 99999);

            ob_start();
            ?>
            <div id="<?php echo esc_attr($instance_id); ?>" class="mec-directory-wrap">
                <?php if ('yes' === $atts['show_filters']) : ?>
                    <div class="mec-directory-controls">
                        <input type="search" class="mec-search" placeholder="<?php esc_attr_e('Search directory, notes, or NAP...', 'madextra-citations'); ?>">
                        <select class="mec-service-filter">
                            <option value=""><?php esc_html_e('All Services', 'madextra-citations'); ?></option>
                            <?php foreach ($all_services as $service) : ?>
                                <option value="<?php echo esc_attr(strtolower($service)); ?>"><?php echo esc_html($service); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="mec-status-filter">
                            <option value=""><?php esc_html_e('All Statuses', 'madextra-citations'); ?></option>
                            <?php foreach ($all_statuses as $status_key => $status_label) : ?>
                                <option value="<?php echo esc_attr(strtolower($status_key)); ?>"><?php echo esc_html($status_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php foreach ($groups as $market_name => $profiles) : ?>
                    <?php
                    usort(
                        $profiles,
                        static function ($a, $b) {
                            $a_featured = !empty($a['is_featured']) && '1' === (string) $a['is_featured'];
                            $b_featured = !empty($b['is_featured']) && '1' === (string) $b['is_featured'];
                            if ($a_featured !== $b_featured) {
                                return $a_featured ? -1 : 1;
                            }

                            $a_order = isset($a['featured_order']) ? (int) $a['featured_order'] : 0;
                            $b_order = isset($b['featured_order']) ? (int) $b['featured_order'] : 0;
                            if ($a_order !== $b_order) {
                                return $a_order <=> $b_order;
                            }

                            return strcasecmp((string) $a['nap_business_name'], (string) $b['nap_business_name']);
                        }
                    );
                    $featured_profiles = array();
                    $featured_ids = array();
                    foreach ($profiles as $profile) {
                        if (!empty($profile['is_featured']) && '1' === (string) $profile['is_featured'] && count($featured_profiles) < 3) {
                            $featured_profiles[] = $profile;
                            $featured_ids[(int) $profile['id']] = true;
                        }
                    }

                    $regular_profiles = array();
                    foreach ($profiles as $profile) {
                        if (!isset($featured_ids[(int) $profile['id']])) {
                            $regular_profiles[] = $profile;
                        }
                    }
                    $total_pages = max(1, (int) ceil(count($regular_profiles) / $per_city));
                    ?>
                    <section class="mec-market-group" data-market="<?php echo esc_attr(strtolower($market_name)); ?>">
                        <div class="mec-market-heading">
                            <h3><?php echo esc_html($market_name); ?></h3>
                            <span><?php echo esc_html(sprintf(_n('%d profile', '%d profiles', count($profiles), 'madextra-citations'), count($profiles))); ?></span>
                        </div>

                        <?php if ($featured_profiles) : ?>
                            <div class="mec-featured-grid">
                                <?php foreach ($featured_profiles as $profile) : ?>
                                    <?php
                                    $services = $profile['services'] ? implode(', ', $profile['services']) : '-';
                                    $status_key = strtolower((string) $profile['status']);
                                    $status_label = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                                    $search_blob = self::profile_search_blob($profile, $services, $status_label);
                                    ?>
                                    <article
                                        class="mec-featured-card"
                                        data-mec-item="1"
                                        data-featured="1"
                                        data-service="<?php echo esc_attr(strtolower($services)); ?>"
                                        data-status="<?php echo esc_attr($status_key); ?>"
                                        data-search="<?php echo esc_attr($search_blob); ?>"
                                    >
                                        <div class="mec-featured-top">
                                            <?php if (!empty($profile['business_logo_url'])) : ?>
                                                <img src="<?php echo esc_url($profile['business_logo_url']); ?>" alt="<?php echo esc_attr($profile['nap_business_name']); ?>">
                                            <?php else : ?>
                                                <span class="mec-logo-fallback"><?php echo esc_html(strtoupper(substr((string) $profile['nap_business_name'], 0, 1))); ?></span>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo esc_html($profile['nap_business_name'] ? $profile['nap_business_name'] : $profile['directory_name']); ?></strong>
                                                <span><?php echo esc_html($services); ?></span>
                                            </div>
                                        </div>
                                        <?php if (!empty($profile['business_description'])) : ?>
                                            <p><?php echo esc_html($profile['business_description']); ?></p>
                                        <?php endif; ?>
                                        <div class="mec-contact-lines">
                                            <?php if (!empty($profile['display_address'])) : ?><span><?php echo nl2br(esc_html($profile['display_address'])); ?></span><?php endif; ?>
                                            <?php if (!empty($profile['nap_phone'])) : ?><span><?php echo esc_html($profile['nap_phone']); ?></span><?php endif; ?>
                                            <?php if (!empty($profile['business_hours'])) : ?><span><?php echo nl2br(esc_html($profile['business_hours'])); ?></span><?php endif; ?>
                                        </div>
                                        <div class="mec-link-row">
                                            <?php if (!empty($profile['business_website_url'])) : ?><a href="<?php echo esc_url($profile['business_website_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Website', 'madextra-citations'); ?></a><?php endif; ?>
                                            <?php if (!empty($profile['listing_url'])) : ?><a href="<?php echo esc_url($profile['listing_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Citation', 'madextra-citations'); ?></a><?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mec-table-wrap" data-regular-table>
                            <table class="mec-table">
                                <thead>
                                <tr>
                                    <th><?php esc_html_e('Business', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Directory', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Service', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Status', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Last Verified', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Links', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Contact', 'madextra-citations'); ?></th>
                                    <th><?php esc_html_e('Public Notes', 'madextra-citations'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($regular_profiles as $index => $profile) : ?>
                                    <?php
                                    $services = $profile['services'] ? implode(', ', $profile['services']) : '-';
                                    $status_key = strtolower((string) $profile['status']);
                                    $status_label = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                                    $search_blob = self::profile_search_blob($profile, $services, $status_label);
                                    $page_number = (int) floor($index / $per_city) + 1;
                                    ?>
                                    <tr
                                        data-mec-item="1"
                                        data-regular="1"
                                        data-page="<?php echo esc_attr((string) $page_number); ?>"
                                        data-service="<?php echo esc_attr(strtolower($services)); ?>"
                                        data-status="<?php echo esc_attr($status_key); ?>"
                                        data-search="<?php echo esc_attr($search_blob); ?>"
                                    >
                                        <td>
                                            <div class="mec-business-cell">
                                                <?php if (!empty($profile['business_logo_url'])) : ?>
                                                    <img src="<?php echo esc_url($profile['business_logo_url']); ?>" alt="<?php echo esc_attr($profile['nap_business_name']); ?>">
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?php echo esc_html($profile['nap_business_name'] ? $profile['nap_business_name'] : $profile['directory_name']); ?></strong>
                                                    <?php if (!empty($profile['display_address'])) : ?><span><?php echo nl2br(esc_html($profile['display_address'])); ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($profile['directory_name']); ?></td>
                                        <td><?php echo esc_html($services); ?></td>
                                        <td><?php echo esc_html($status_label ? $status_label : '-'); ?></td>
                                        <td><?php echo esc_html($profile['last_verified_date'] ? $profile['last_verified_date'] : '-'); ?></td>
                                        <td>
                                            <?php if (!empty($profile['business_website_url'])) : ?>
                                                <a href="<?php echo esc_url($profile['business_website_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Website', 'madextra-citations'); ?></a>
                                            <?php endif; ?>
                                            <?php if (!empty($profile['listing_url'])) : ?>
                                                <a href="<?php echo esc_url($profile['listing_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Citation', 'madextra-citations'); ?></a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($profile['nap_phone'])) : ?><div><?php echo esc_html($profile['nap_phone']); ?></div><?php endif; ?>
                                            <?php if (!empty($profile['business_email'])) : ?><div><a href="mailto:<?php echo esc_attr($profile['business_email']); ?>"><?php echo esc_html($profile['business_email']); ?></a></div><?php endif; ?>
                                            <?php if (!empty($profile['business_hours'])) : ?><div><?php echo nl2br(esc_html($profile['business_hours'])); ?></div><?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($profile['public_notes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($regular_profiles) > $per_city) : ?>
                            <nav class="mec-group-pagination" data-total-pages="<?php echo esc_attr((string) $total_pages); ?>">
                                <button type="button" class="mec-page-prev"><?php esc_html_e('Previous', 'madextra-citations'); ?></button>
                                <span><?php esc_html_e('Page', 'madextra-citations'); ?> <strong class="mec-current-page">1</strong> <?php esc_html_e('of', 'madextra-citations'); ?> <?php echo esc_html((string) $total_pages); ?></span>
                                <button type="button" class="mec-page-next"><?php esc_html_e('Next', 'madextra-citations'); ?></button>
                            </nav>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <style>
                .mec-directory-wrap { display: grid; gap: 18px; }
                .mec-directory-controls { display: grid; gap: 10px; grid-template-columns: minmax(220px,1.4fr) minmax(160px,.8fr) minmax(160px,.8fr); }
                .mec-directory-controls input, .mec-directory-controls select { width: 100%; padding: 10px; border: 1px solid #cfd7ea; border-radius: 8px; font: inherit; }
                .mec-market-group { border: 1px solid #dbe2f3; border-radius: 10px; overflow: hidden; background: #fff; }
                .mec-market-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin: 0; padding: 12px 14px; background: #f4f7ff; border-bottom: 1px solid #dbe2f3; }
                .mec-market-heading h3 { margin:0; font-size:1rem; }
                .mec-market-heading span { color:#566987; font-size:.9rem; }
                .mec-featured-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; padding:14px; border-bottom:1px solid #edf1fb; }
                .mec-featured-card { border:1px solid #cbd8ef; border-radius:8px; padding:12px; display:grid; gap:10px; background:#fbfdff; }
                .mec-featured-top, .mec-business-cell { display:flex; gap:10px; align-items:flex-start; }
                .mec-featured-top img, .mec-business-cell img, .mec-logo-fallback { width:44px; height:44px; border-radius:8px; object-fit:cover; flex:0 0 auto; }
                .mec-logo-fallback { display:grid; place-items:center; background:#e8eefb; color:#27426f; font-weight:700; }
                .mec-featured-top strong, .mec-business-cell strong { display:block; color:#17253f; }
                .mec-featured-top span, .mec-business-cell span { display:block; color:#5d6f8d; font-size:.88rem; }
                .mec-contact-lines { display:grid; gap:4px; color:#42536f; font-size:.9rem; }
                .mec-link-row { display:flex; gap:8px; flex-wrap:wrap; }
                .mec-link-row a, .mec-table a { font-weight:700; }
                .mec-table-wrap { overflow-x: auto; }
                .mec-table { width: 100%; border-collapse: collapse; min-width: 1040px; }
                .mec-table th, .mec-table td { padding: 10px 12px; border-bottom: 1px solid #edf1fb; text-align: left; vertical-align: top; }
                .mec-table th { background: #fafcff; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; color: #33486b; }
                .mec-table td { font-size: 0.93rem; }
                .mec-table tr:last-child td { border-bottom: 0; }
                .mec-group-pagination { display:flex; gap:10px; align-items:center; justify-content:flex-end; padding:12px 14px; border-top:1px solid #edf1fb; }
                .mec-group-pagination button { border:1px solid #c9d5eb; border-radius:7px; padding:7px 10px; background:#fff; cursor:pointer; }
                .mec-group-pagination button:disabled { opacity:.45; cursor:not-allowed; }
                @media (max-width: 800px) {
                    .mec-directory-controls { grid-template-columns: 1fr; }
                    .mec-market-heading { align-items:flex-start; flex-direction:column; }
                }
            </style>

            <script>
                (function() {
                    const root = document.getElementById(<?php echo wp_json_encode($instance_id); ?>);
                    if (!root) return;

                    const searchInput = root.querySelector('.mec-search');
                    const serviceFilter = root.querySelector('.mec-service-filter');
                    const statusFilter = root.querySelector('.mec-status-filter');
                    const groups = Array.from(root.querySelectorAll('.mec-market-group'));

                    function updateGroupPage(group, nextPage) {
                        const pagination = group.querySelector('.mec-group-pagination');
                        const totalPages = parseInt(pagination ? (pagination.dataset.totalPages || '1') : '1', 10);
                        const page = Math.max(1, Math.min(totalPages, nextPage));
                        group.dataset.currentPage = String(page);
                        applyFilters();
                    }

                    function applyFilters() {
                        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
                        const service = serviceFilter ? serviceFilter.value.toLowerCase() : '';
                        const status = statusFilter ? statusFilter.value.toLowerCase() : '';
                        const filtering = !!(q || service || status);

                        groups.forEach((group) => {
                            const items = Array.from(group.querySelectorAll('[data-mec-item]'));
                            const currentPage = parseInt(group.dataset.currentPage || '1', 10);
                            const pagination = group.querySelector('.mec-group-pagination');
                            const tableWrap = group.querySelector('[data-regular-table]');
                            let visibleCount = 0;
                            let visibleRegularCount = 0;

                            items.forEach((item) => {
                                const rowService = (item.getAttribute('data-service') || '').toLowerCase();
                                const rowStatus = (item.getAttribute('data-status') || '').toLowerCase();
                                const rowSearch = (item.getAttribute('data-search') || '').toLowerCase();
                                const itemPage = parseInt(item.getAttribute('data-page') || '1', 10);
                                const isRegular = item.getAttribute('data-regular') === '1';

                                const matchSearch = !q || rowSearch.indexOf(q) !== -1;
                                const matchService = !service || rowService.indexOf(service) !== -1;
                                const matchStatus = !status || rowStatus === status;

                                let show = matchSearch && matchService && matchStatus;
                                if (!filtering && isRegular) {
                                    show = show && itemPage === currentPage;
                                }

                                item.style.display = show ? '' : 'none';
                                if (show) {
                                    visibleCount++;
                                    if (isRegular) visibleRegularCount++;
                                }
                            });

                            group.style.display = visibleCount > 0 ? '' : 'none';
                            if (tableWrap) tableWrap.style.display = visibleRegularCount > 0 ? '' : 'none';
                            if (pagination) {
                                pagination.style.display = filtering ? 'none' : '';
                                const currentLabel = pagination.querySelector('.mec-current-page');
                                const prev = pagination.querySelector('.mec-page-prev');
                                const next = pagination.querySelector('.mec-page-next');
                                const totalPages = parseInt(pagination.dataset.totalPages || '1', 10);
                                if (currentLabel) currentLabel.textContent = String(currentPage);
                                if (prev) prev.disabled = currentPage <= 1;
                                if (next) next.disabled = currentPage >= totalPages;
                            }
                        });
                    }

                    if (searchInput) searchInput.addEventListener('input', applyFilters);
                    if (serviceFilter) serviceFilter.addEventListener('change', applyFilters);
                    if (statusFilter) statusFilter.addEventListener('change', applyFilters);
                    groups.forEach((group) => {
                        group.dataset.currentPage = '1';
                        const prev = group.querySelector('.mec-page-prev');
                        const next = group.querySelector('.mec-page-next');
                        if (prev) prev.addEventListener('click', () => updateGroupPage(group, parseInt(group.dataset.currentPage || '1', 10) - 1));
                        if (next) next.addEventListener('click', () => updateGroupPage(group, parseInt(group.dataset.currentPage || '1', 10) + 1));
                    });
                    applyFilters();
                })();
            </script>
            <?php
            return ob_get_clean();
        }
    }
}

$mec_builder_file = __DIR__ . '/includes/class-mec-builder.php';
if (file_exists($mec_builder_file)) {
    try {
        require_once $mec_builder_file;
    } catch (Throwable $e) {
        mec_builder_bootstrap_error_handler($e->getMessage());
    }
}

MadExtra_Citations_Plugin::bootstrap();
if (class_exists('MadExtra_Citations_Builder')) {
    try {
        MadExtra_Citations_Builder::bootstrap();
    } catch (Throwable $e) {
        mec_builder_bootstrap_error_handler($e->getMessage());
    }
}

register_activation_hook(__FILE__, array('MadExtra_Citations_Plugin', 'activate'));
if (class_exists('MadExtra_Citations_Builder')) {
    register_activation_hook(__FILE__, array('MadExtra_Citations_Builder', 'activate'));
}
register_deactivation_hook(__FILE__, array('MadExtra_Citations_Plugin', 'deactivate'));
