<?php
/**
 * Plugin Name: MadExtra Citations Directory
 * Plugin URI: https://directory.madextraseo.com
 * Description: Citation profile management with granular permissions, CSV import/export, REST endpoints, and searchable public directory pages.
 * Version: 0.6.1
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
        const PROFILE_SHORTCODE = 'mec_public_profile';
        const CAPS_OPTION = 'mec_caps_version';
        const CAPS_VERSION = '1.2.0';
        const PUBLIC_SUBMIT_SHORTCODE = 'mec_public_submit_form';
        const LOGO_MAX_BYTES = 2097152;
        const STRIPE_OPTION = 'mec_stripe_settings_v1';
        const STRIPE_WEBHOOK_TOLERANCE = 300;

        public static function bootstrap()
        {
            add_action('init', array(__CLASS__, 'register_content_types'));
            add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
            add_action('save_post_' . self::CPT, array(__CLASS__, 'save_profile_meta'));

            add_filter('manage_edit-' . self::CPT . '_columns', array(__CLASS__, 'register_admin_columns'));
            add_action('manage_' . self::CPT . '_posts_custom_column', array(__CLASS__, 'render_admin_columns'), 10, 2);
            add_action('restrict_manage_posts', array(__CLASS__, 'render_admin_filters'));
            add_filter('parse_query', array(__CLASS__, 'apply_admin_filters'));
            add_filter('post_row_actions', array(__CLASS__, 'filter_admin_row_actions'), 10, 2);

            add_action('admin_menu', array(__CLASS__, 'register_tools_submenu'));
            add_action('admin_menu', array(__CLASS__, 'register_premium_queue_submenu'));
            add_action('admin_menu', array(__CLASS__, 'register_stripe_submenu'));
            add_action('admin_post_mec_export_csv', array(__CLASS__, 'handle_export_request'));
            add_action('admin_post_mec_import_csv', array(__CLASS__, 'handle_import_request'));
            add_action('admin_post_mec_premium_profile_action', array(__CLASS__, 'handle_premium_profile_action'));
            add_action('admin_post_mec_bulk_premium_profile_action', array(__CLASS__, 'handle_bulk_premium_profile_action'));
            add_action('admin_post_mec_save_stripe_settings', array(__CLASS__, 'handle_save_stripe_settings'));
            add_action('admin_post_mec_public_submit', array(__CLASS__, 'handle_public_submit_request'));
            add_action('admin_post_nopriv_mec_public_submit', array(__CLASS__, 'handle_public_submit_request'));
            add_action('admin_post_mec_set_featured_slot', array(__CLASS__, 'handle_set_featured_slot'));
            add_action('admin_post_mec_generate_profile_page', array(__CLASS__, 'handle_generate_profile_page'));
            add_action('admin_init', array(__CLASS__, 'maybe_redirect_legacy_tools_page'));
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
            add_action('admin_notices', array(__CLASS__, 'render_admin_notice'));
            add_action('admin_notices', array(__CLASS__, 'render_capability_debug_notice'));
            add_action('admin_init', array(__CLASS__, 'maybe_sync_capabilities'));
            add_filter('map_meta_cap', array(__CLASS__, 'map_citation_caps'), 99999, 4);
            add_filter('user_has_cap', array(__CLASS__, 'grant_admin_fallback_caps'), 99999, 4);

            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
            add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_directory_shortcode'));
            add_shortcode(self::PROFILE_SHORTCODE, array(__CLASS__, 'render_public_profile_shortcode'));
            add_shortcode(self::PUBLIC_SUBMIT_SHORTCODE, array(__CLASS__, 'render_public_submit_form_shortcode'));
        }

        public static function activate()
        {
            self::register_content_types();
            self::register_roles_and_capabilities();
            update_option(self::CAPS_OPTION, self::CAPS_VERSION, false);
            self::maybe_seed_stripe_settings();
            self::maybe_create_directory_page();
            self::maybe_create_public_submit_page();
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
            self::maybe_seed_stripe_settings();
            self::maybe_create_public_submit_page();
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

        private static function maybe_create_public_submit_page()
        {
            $existing = get_page_by_path('submit-citation');
            if ($existing) {
                return;
            }

            wp_insert_post(
                array(
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_title'   => 'Submit Citation',
                    'post_name'    => 'submit-citation',
                    'post_content' => '[' . self::PUBLIC_SUBMIT_SHORTCODE . ']',
                )
            );
        }

        private static function default_stripe_settings()
        {
            return array(
                'default_payment_link_url' => '',
                'webhook_signing_secret' => '',
                'auto_publish_paid_profiles' => '1',
                'auto_generate_public_page' => '1',
                'auto_upgrade_to_premium' => '1',
            );
        }

        private static function get_stripe_settings()
        {
            $settings = get_option(self::STRIPE_OPTION, array());
            if (!is_array($settings)) {
                $settings = array();
            }
            return wp_parse_args($settings, self::default_stripe_settings());
        }

        private static function maybe_seed_stripe_settings()
        {
            if (!get_option(self::STRIPE_OPTION, null)) {
                update_option(self::STRIPE_OPTION, self::default_stripe_settings(), false);
            }
        }

        public static function enqueue_admin_assets($hook)
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || !isset($screen->post_type) || self::CPT !== $screen->post_type) {
                return;
            }

            wp_enqueue_media();
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
                'self_serve_enabled'  => array('label' => __('Self-Serve CTA Enabled', 'madextra-citations'), 'required' => false),
                'self_serve_cta_label' => array('label' => __('Self-Serve Button Label', 'madextra-citations'), 'required' => false),
                'self_serve_cta_url'  => array('label' => __('Stripe Payment Link / Claim URL', 'madextra-citations'), 'required' => false),
                'self_serve_price_text' => array('label' => __('Self-Serve Price Text', 'madextra-citations'), 'required' => false),
                'public_profile_page_id' => array('label' => __('Public Profile Page ID', 'madextra-citations'), 'required' => false),
                'is_premium'         => array('label' => __('Premium Profile', 'madextra-citations'), 'required' => false),
                'service_areas'      => array('label' => __('Service Areas', 'madextra-citations'), 'required' => false),
                'faq_items'          => array('label' => __('FAQ Items', 'madextra-citations'), 'required' => false),
                'social_links'       => array('label' => __('Social Links', 'madextra-citations'), 'required' => false),
                'gallery_media_ids'  => array('label' => __('Gallery Media IDs', 'madextra-citations'), 'required' => false),
                'primary_cta_label'  => array('label' => __('Primary CTA Label', 'madextra-citations'), 'required' => false),
                'primary_cta_url'    => array('label' => __('Primary CTA URL', 'madextra-citations'), 'required' => false),
                'secondary_cta_label' => array('label' => __('Secondary CTA Label', 'madextra-citations'), 'required' => false),
                'secondary_cta_url'  => array('label' => __('Secondary CTA URL', 'madextra-citations'), 'required' => false),
                'premium_hero_text'  => array('label' => __('Premium Hero Text', 'madextra-citations'), 'required' => false),
                'premium_subheadline' => array('label' => __('Premium Subheadline', 'madextra-citations'), 'required' => false),
                'extended_about_copy' => array('label' => __('Extended About Copy', 'madextra-citations'), 'required' => false),
                'services_summary'   => array('label' => __('Services Summary', 'madextra-citations'), 'required' => false),
                'service_cards'      => array('label' => __('Service Cards', 'madextra-citations'), 'required' => false),
                'premium_badge_text' => array('label' => __('Premium Badge / Trust Text', 'madextra-citations'), 'required' => false),
                'premium_page_mode'  => array('label' => __('Premium Page Mode', 'madextra-citations'), 'required' => false),
                'premium_page_status' => array('label' => __('Premium Page Status', 'madextra-citations'), 'required' => false),
                'premium_last_generated_at' => array('label' => __('Premium Last Generated', 'madextra-citations'), 'required' => false),
                'premium_layout_template_key' => array('label' => __('Premium Layout Template Key', 'madextra-citations'), 'required' => false),
                'premium_manual_override' => array('label' => __('Premium Manual Override', 'madextra-citations'), 'required' => false),
                'premium_notes'      => array('label' => __('Premium Notes (Admin Only)', 'madextra-citations'), 'required' => false),
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
                        <?php
                        $logo_id = (int) $values['business_logo_id'];
                        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '';
                        ?>
                        <div class="mec-logo-picker">
                            <div class="mec-logo-preview">
                                <?php if ($logo_url) : ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" alt="">
                                <?php else : ?>
                                    <span><?php esc_html_e('No logo selected', 'madextra-citations'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button type="button" class="button mec-select-logo"><?php esc_html_e('Choose Logo', 'madextra-citations'); ?></button>
                                <button type="button" class="button mec-remove-logo"><?php esc_html_e('Remove Logo', 'madextra-citations'); ?></button>
                            </div>
                        </div>
                        <input type="number" class="small-text" id="mec_business_logo_id" name="mec[business_logo_id]" min="0" step="1" value="<?php echo esc_attr($values['business_logo_id']); ?>">
                        <p class="description"><?php esc_html_e('Choose from the Media Library or paste an attachment ID.', 'madextra-citations'); ?></p>
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
                    <th scope="row"><?php esc_html_e('Public Profile Page', 'madextra-citations'); ?></th>
                    <td>
                        <?php $public_page_id = (int) $values['public_profile_page_id']; ?>
                        <?php if ($public_page_id > 0) : ?>
                            <p>
                                <a class="button" href="<?php echo esc_url(get_edit_post_link($public_page_id, '')); ?>"><?php esc_html_e('Edit Public Page', 'madextra-citations'); ?></a>
                                <a class="button" href="<?php echo esc_url(get_permalink($public_page_id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View Public Page', 'madextra-citations'); ?></a>
                                <a class="button button-primary" href="<?php echo esc_url(self::generate_profile_page_url($post->ID)); ?>"><?php esc_html_e('Refresh Public Page', 'madextra-citations'); ?></a>
                            </p>
                            <input type="hidden" name="mec[public_profile_page_id]" value="<?php echo esc_attr((string) $public_page_id); ?>">
                        <?php else : ?>
                            <p>
                                <a class="button button-primary" href="<?php echo esc_url(self::generate_profile_page_url($post->ID)); ?>"><?php esc_html_e('Generate Public Page', 'madextra-citations'); ?></a>
                            </p>
                            <input type="hidden" name="mec[public_profile_page_id]" value="0">
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Creates a normal WordPress page using a shortcode block so it can be opened and customized in Elementor.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Self-Serve Monetization', 'madextra-citations'); ?></th>
                    <td>
                        <?php
                        $claim_status = get_post_meta($post->ID, self::META_PREFIX . 'self_serve_payment_status', true);
                        $claim_email = get_post_meta($post->ID, self::META_PREFIX . 'self_serve_claim_email', true);
                        $claim_date = get_post_meta($post->ID, self::META_PREFIX . 'self_serve_claimed_at', true);
                        ?>
                        <p><label><input type="checkbox" id="mec_self_serve_enabled" name="mec[self_serve_enabled]" value="1" <?php checked($values['self_serve_enabled'], '1'); ?>> <?php esc_html_e('Gate the self-serve claim / upgrade flow behind Stripe', 'madextra-citations'); ?></label></p>
                        <p><input type="text" class="regular-text" id="mec_self_serve_cta_label" name="mec[self_serve_cta_label]" value="<?php echo esc_attr($values['self_serve_cta_label']); ?>" placeholder="<?php esc_attr_e('Claim This Profile', 'madextra-citations'); ?>"></p>
                        <p><input type="url" class="large-text code" id="mec_self_serve_cta_url" name="mec[self_serve_cta_url]" value="<?php echo esc_attr($values['self_serve_cta_url']); ?>" placeholder="<?php esc_attr_e('https://buy.stripe.com/...', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" id="mec_self_serve_price_text" name="mec[self_serve_price_text]" value="<?php echo esc_attr($values['self_serve_price_text']); ?>" placeholder="<?php esc_attr_e('From $49/month', 'madextra-citations'); ?>"></p>
                        <p class="description">
                            <?php
                            echo esc_html(
                                sprintf(
                                    __('Payment status: %1$s | Claimed email: %2$s | Claimed at: %3$s', 'madextra-citations'),
                                    $claim_status ? $claim_status : __('not paid', 'madextra-citations'),
                                    $claim_email ? $claim_email : __('n/a', 'madextra-citations'),
                                    $claim_date ? $claim_date : __('n/a', 'madextra-citations')
                                )
                            );
                            ?>
                        </p>
                        <p class="description"><?php esc_html_e('Paste a Stripe Payment Link here. The public profile page will show a paywall-style claim / upgrade section without exposing the source directory.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Premium Profile', 'madextra-citations'); ?></th>
                    <td>
                        <p><label><input type="checkbox" name="mec[is_premium]" value="1" <?php checked($values['is_premium'], '1'); ?>> <?php esc_html_e('Enable premium profile mode for this business', 'madextra-citations'); ?></label></p>
                        <p><label><input type="checkbox" name="mec[premium_manual_override]" value="1" <?php checked($values['premium_manual_override'], '1'); ?>> <?php esc_html_e('Manual premium override', 'madextra-citations'); ?></label></p>
                        <p><input type="text" class="regular-text" name="mec[premium_badge_text]" value="<?php echo esc_attr($values['premium_badge_text']); ?>" placeholder="<?php esc_attr_e('Featured Local Business', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" name="mec[premium_layout_template_key]" value="<?php echo esc_attr($values['premium_layout_template_key']); ?>" placeholder="<?php esc_attr_e('premium-default', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" name="mec[premium_page_mode]" value="<?php echo esc_attr($values['premium_page_mode']); ?>" placeholder="<?php esc_attr_e('premium', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" name="mec[premium_page_status]" value="<?php echo esc_attr($values['premium_page_status']); ?>" placeholder="<?php esc_attr_e('active', 'madextra-citations'); ?>"></p>
                        <p class="description"><?php esc_html_e('Premium upgrades the same public page with richer business sections and extra marketing content.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Premium Content', 'madextra-citations'); ?></th>
                    <td>
                        <p><input type="text" class="large-text" name="mec[premium_hero_text]" value="<?php echo esc_attr($values['premium_hero_text']); ?>" placeholder="<?php esc_attr_e('Premium hero headline', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="large-text" name="mec[premium_subheadline]" value="<?php echo esc_attr($values['premium_subheadline']); ?>" placeholder="<?php esc_attr_e('Premium subheadline', 'madextra-citations'); ?>"></p>
                        <p><textarea class="large-text" rows="5" name="mec[extended_about_copy]" placeholder="<?php esc_attr_e('Extended about copy', 'madextra-citations'); ?>"><?php echo esc_textarea($values['extended_about_copy']); ?></textarea></p>
                        <p><textarea class="large-text" rows="4" name="mec[services_summary]" placeholder="<?php esc_attr_e('Services summary', 'madextra-citations'); ?>"><?php echo esc_textarea($values['services_summary']); ?></textarea></p>
                        <p><textarea class="large-text" rows="5" name="mec[service_areas]" placeholder="<?php esc_attr_e('Service areas, one per line', 'madextra-citations'); ?>"><?php echo esc_textarea($values['service_areas']); ?></textarea></p>
                        <p><textarea class="large-text" rows="5" name="mec[service_cards]" placeholder="<?php esc_attr_e('Service cards as Title|Description, one per line', 'madextra-citations'); ?>"><?php echo esc_textarea($values['service_cards']); ?></textarea></p>
                        <p><textarea class="large-text" rows="5" name="mec[faq_items]" placeholder="<?php esc_attr_e('FAQ items as Question|Answer, one per line', 'madextra-citations'); ?>"><?php echo esc_textarea($values['faq_items']); ?></textarea></p>
                        <p><textarea class="large-text" rows="4" name="mec[social_links]" placeholder="<?php esc_attr_e('Social links as Label|URL, one per line', 'madextra-citations'); ?>"><?php echo esc_textarea($values['social_links']); ?></textarea></p>
                        <p><input type="text" class="large-text" name="mec[gallery_media_ids]" value="<?php echo esc_attr($values['gallery_media_ids']); ?>" placeholder="<?php esc_attr_e('Gallery media IDs comma-separated', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" name="mec[primary_cta_label]" value="<?php echo esc_attr($values['primary_cta_label']); ?>" placeholder="<?php esc_attr_e('Primary CTA label', 'madextra-citations'); ?>"> <input type="url" class="large-text code" name="mec[primary_cta_url]" value="<?php echo esc_attr($values['primary_cta_url']); ?>" placeholder="<?php esc_attr_e('Primary CTA URL', 'madextra-citations'); ?>"></p>
                        <p><input type="text" class="regular-text" name="mec[secondary_cta_label]" value="<?php echo esc_attr($values['secondary_cta_label']); ?>" placeholder="<?php esc_attr_e('Secondary CTA label', 'madextra-citations'); ?>"> <input type="url" class="large-text code" name="mec[secondary_cta_url]" value="<?php echo esc_attr($values['secondary_cta_url']); ?>" placeholder="<?php esc_attr_e('Secondary CTA URL', 'madextra-citations'); ?>"></p>
                        <p><textarea id="mec_premium_notes" name="mec[premium_notes]" rows="4" class="large-text" placeholder="<?php esc_attr_e('Premium notes (admin only)', 'madextra-citations'); ?>"><?php echo esc_textarea($values['premium_notes']); ?></textarea></p>
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
                .mec-logo-picker { display:flex; gap:12px; align-items:center; margin-bottom:8px; }
                .mec-logo-preview { width:72px; height:72px; border:1px solid #cbd5e1; border-radius:8px; display:grid; place-items:center; background:#f8fafc; overflow:hidden; color:#64748b; font-size:11px; text-align:center; }
                .mec-logo-preview img { width:100%; height:100%; object-fit:cover; display:block; }
            </style>
            <script>
                (function($) {
                    if (!$ || typeof wp === 'undefined' || !wp.media) return;
                    const picker = $('.mec-logo-picker');
                    const input = $('#mec_business_logo_id');
                    const preview = picker.find('.mec-logo-preview');
                    let frame;

                    picker.on('click', '.mec-select-logo', function(event) {
                        event.preventDefault();
                        if (frame) {
                            frame.open();
                            return;
                        }

                        frame = wp.media({
                            title: <?php echo wp_json_encode(__('Choose Business Logo', 'madextra-citations')); ?>,
                            button: { text: <?php echo wp_json_encode(__('Use this logo', 'madextra-citations')); ?> },
                            multiple: false,
                            library: { type: 'image' }
                        });

                        frame.on('select', function() {
                            const attachment = frame.state().get('selection').first().toJSON();
                            input.val(attachment.id || '');
                            const url = (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url);
                            preview.html('<img src="' + url + '" alt="">');
                        });

                        frame.open();
                    });

                    picker.on('click', '.mec-remove-logo', function(event) {
                        event.preventDefault();
                        input.val('0');
                        preview.html('<span>' + <?php echo wp_json_encode(__('No logo selected', 'madextra-citations')); ?> + '</span>');
                    });
                })(window.jQuery);
            </script>
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

            $duplicate_validation = self::validate_duplicate_profile($post_id, $clean);
            if (is_wp_error($duplicate_validation)) {
                self::queue_notice($duplicate_validation->get_error_message(), 'error');
                return;
            }

            foreach ($clean as $key => $value) {
                update_post_meta($post_id, self::META_PREFIX . $key, $value);
            }

            if (!empty(self::admin_business_title($clean))) {
                wp_update_post(
                    array(
                        'ID'         => $post_id,
                        'post_title' => self::admin_business_title($clean),
                    )
                );
            }

            wp_set_object_terms($post_id, $market_ids, self::TAX_MARKET, false);
            wp_set_object_terms($post_id, $service_ids, self::TAX_SERVICE, false);

            update_post_meta($post_id, self::META_PREFIX . 'unique_key', self::build_unique_key($clean, $market_ids, $service_ids));

            if ((int) get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true) > 0) {
                self::generate_public_profile_page($post_id);
            }
        }

        public static function admin_business_title(array $clean)
        {
            $business_name = isset($clean['nap_business_name']) ? trim((string) $clean['nap_business_name']) : '';
            if ('' !== $business_name) {
                return $business_name;
            }

            return isset($clean['directory_name']) ? (string) $clean['directory_name'] : '';
        }

        private static function normalize_match_text($value)
        {
            $value = strtolower(trim((string) $value));
            $value = preg_replace('/https?:\/\//', '', $value);
            $value = preg_replace('/www\./', '', $value);
            $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);
            $value = preg_replace('/\s+/', ' ', $value);
            return trim((string) $value);
        }

        private static function normalize_phone($value)
        {
            return preg_replace('/\D+/', '', (string) $value);
        }

        private static function normalize_website($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '';
            }
            $parts = wp_parse_url($value);
            if (!is_array($parts)) {
                return self::normalize_match_text($value);
            }
            $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
            $host = preg_replace('/^www\./', '', $host);
            $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
            return trim($host . $path);
        }

        private static function normalized_duplicate_signals(array $clean)
        {
            $address = self::compose_full_address($clean);
            if ('' === trim($address)) {
                $address = isset($clean['nap_address']) ? (string) $clean['nap_address'] : '';
            }

            return array(
                'name' => self::normalize_match_text(isset($clean['nap_business_name']) ? $clean['nap_business_name'] : ''),
                'phone' => self::normalize_phone(isset($clean['nap_phone']) ? $clean['nap_phone'] : ''),
                'address' => self::normalize_match_text($address),
                'website' => self::normalize_website(isset($clean['business_website_url']) ? $clean['business_website_url'] : ''),
            );
        }

        private static function collect_duplicate_candidate_ids($exclude_post_id = 0)
        {
            $args = array(
                'post_type' => self::CPT,
                'post_status' => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'title',
                'order' => 'ASC',
            );
            if ($exclude_post_id > 0) {
                $args['post__not_in'] = array((int) $exclude_post_id);
            }
            $query = new WP_Query($args);
            return array_map('intval', (array) $query->posts);
        }

        private static function duplicate_match_for_profile($exclude_post_id, array $clean)
        {
            $target = self::normalized_duplicate_signals($clean);
            $candidate_ids = self::collect_duplicate_candidate_ids((int) $exclude_post_id);

            foreach ($candidate_ids as $candidate_id) {
                $candidate_profile = self::profile_to_public_data($candidate_id);
                $candidate_clean = array(
                    'nap_business_name' => isset($candidate_profile['nap_business_name']) ? $candidate_profile['nap_business_name'] : '',
                    'nap_phone' => isset($candidate_profile['nap_phone']) ? $candidate_profile['nap_phone'] : '',
                    'nap_address' => isset($candidate_profile['nap_address']) ? $candidate_profile['nap_address'] : '',
                    'address_street' => isset($candidate_profile['address_street']) ? $candidate_profile['address_street'] : '',
                    'address_city' => isset($candidate_profile['address_city']) ? $candidate_profile['address_city'] : '',
                    'address_state' => isset($candidate_profile['address_state']) ? $candidate_profile['address_state'] : '',
                    'address_zip' => isset($candidate_profile['address_zip']) ? $candidate_profile['address_zip'] : '',
                    'business_website_url' => isset($candidate_profile['business_website_url']) ? $candidate_profile['business_website_url'] : '',
                );
                $candidate = self::normalized_duplicate_signals($candidate_clean);
                $matches = 0;
                foreach (array('name', 'phone', 'address', 'website') as $key) {
                    if ('' === $target[$key] || '' === $candidate[$key]) {
                        continue;
                    }
                    if ((string) $target[$key] === (string) $candidate[$key]) {
                        $matches++;
                    }
                }
                if ($matches >= 2) {
                    return array(
                        'post_id' => (int) $candidate_id,
                        'title' => get_the_title($candidate_id),
                    );
                }
            }

            return null;
        }

        public static function validate_duplicate_profile($exclude_post_id, array $clean)
        {
            $match = self::duplicate_match_for_profile((int) $exclude_post_id, $clean);
            if (!$match) {
                return true;
            }

            return new WP_Error(
                'duplicate_profile',
                sprintf(
                    __('Duplicate profile detected. Matching business: %s.', 'madextra-citations'),
                    isset($match['title']) ? $match['title'] : __('Existing profile', 'madextra-citations')
                ),
                $match
            );
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
                'self_serve_enabled'  => !empty($payload['self_serve_enabled']) ? '1' : '0',
                'self_serve_cta_label' => isset($payload['self_serve_cta_label']) ? sanitize_text_field($payload['self_serve_cta_label']) : '',
                'self_serve_cta_url'  => isset($payload['self_serve_cta_url']) ? esc_url_raw($payload['self_serve_cta_url']) : '',
                'self_serve_price_text' => isset($payload['self_serve_price_text']) ? sanitize_text_field($payload['self_serve_price_text']) : '',
                'public_profile_page_id' => isset($payload['public_profile_page_id']) ? (string) max(0, (int) $payload['public_profile_page_id']) : '0',
                'is_premium'         => !empty($payload['is_premium']) ? '1' : '0',
                'service_areas'      => isset($payload['service_areas']) ? sanitize_textarea_field($payload['service_areas']) : '',
                'faq_items'          => isset($payload['faq_items']) ? sanitize_textarea_field($payload['faq_items']) : '',
                'social_links'       => isset($payload['social_links']) ? sanitize_textarea_field($payload['social_links']) : '',
                'gallery_media_ids'  => isset($payload['gallery_media_ids']) ? sanitize_text_field($payload['gallery_media_ids']) : '',
                'primary_cta_label'  => isset($payload['primary_cta_label']) ? sanitize_text_field($payload['primary_cta_label']) : '',
                'primary_cta_url'    => isset($payload['primary_cta_url']) ? esc_url_raw($payload['primary_cta_url']) : '',
                'secondary_cta_label' => isset($payload['secondary_cta_label']) ? sanitize_text_field($payload['secondary_cta_label']) : '',
                'secondary_cta_url'  => isset($payload['secondary_cta_url']) ? esc_url_raw($payload['secondary_cta_url']) : '',
                'premium_hero_text'  => isset($payload['premium_hero_text']) ? sanitize_text_field($payload['premium_hero_text']) : '',
                'premium_subheadline' => isset($payload['premium_subheadline']) ? sanitize_text_field($payload['premium_subheadline']) : '',
                'extended_about_copy' => isset($payload['extended_about_copy']) ? sanitize_textarea_field($payload['extended_about_copy']) : '',
                'services_summary'   => isset($payload['services_summary']) ? sanitize_textarea_field($payload['services_summary']) : '',
                'service_cards'      => isset($payload['service_cards']) ? sanitize_textarea_field($payload['service_cards']) : '',
                'premium_badge_text' => isset($payload['premium_badge_text']) ? sanitize_text_field($payload['premium_badge_text']) : '',
                'premium_page_mode'  => isset($payload['premium_page_mode']) ? sanitize_key($payload['premium_page_mode']) : '',
                'premium_page_status' => isset($payload['premium_page_status']) ? sanitize_key($payload['premium_page_status']) : '',
                'premium_last_generated_at' => isset($payload['premium_last_generated_at']) ? sanitize_text_field($payload['premium_last_generated_at']) : '',
                'premium_layout_template_key' => isset($payload['premium_layout_template_key']) ? sanitize_key($payload['premium_layout_template_key']) : 'premium-default',
                'premium_manual_override' => !empty($payload['premium_manual_override']) ? '1' : '0',
                'premium_notes'      => isset($payload['premium_notes']) ? sanitize_textarea_field($payload['premium_notes']) : '',
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

        private static function split_multiline_items($raw)
        {
            $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
            if (!is_array($lines)) {
                return array();
            }

            $items = array();
            foreach ($lines as $line) {
                $parts = preg_split('/\s*,\s*/', (string) $line);
                if (!is_array($parts)) {
                    $parts = array($line);
                }
                foreach ($parts as $part) {
                    $part = trim((string) $part);
                    if ('' !== $part) {
                        $items[] = $part;
                    }
                }
            }

            return array_values(array_unique($items));
        }

        private static function split_pipe_rows($raw, $expected_parts = 2)
        {
            $rows = preg_split('/\r\n|\r|\n/', (string) $raw);
            if (!is_array($rows)) {
                return array();
            }

            $items = array();
            foreach ($rows as $row) {
                $row = trim((string) $row);
                if ('' === $row) {
                    continue;
                }

                $parts = array_map('trim', explode('|', $row));
                if (!$parts) {
                    continue;
                }

                while (count($parts) < $expected_parts) {
                    $parts[] = '';
                }

                $items[] = array_slice($parts, 0, $expected_parts);
            }

            return $items;
        }

        private static function parse_gallery_media_ids($raw)
        {
            $ids = preg_split('/[\s,]+/', (string) $raw);
            if (!is_array($ids)) {
                return array();
            }

            $items = array();
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $items[] = $id;
                }
            }

            return array_values(array_unique($items));
        }

        private static function profile_primary_name(array $profile)
        {
            return !empty($profile['nap_business_name']) ? (string) $profile['nap_business_name'] : (string) $profile['title'];
        }

        private static function public_profile_page_content($post_id)
        {
            $profile = self::profile_to_public_data($post_id);
            $primary_name = self::profile_primary_name($profile);
            $services = !empty($profile['services']) ? implode(', ', (array) $profile['services']) : __('Local business profile', 'madextra-citations');
            $markets = !empty($profile['markets']) ? implode(', ', (array) $profile['markets']) : '';
            $is_premium = !empty($profile['is_premium']) && '1' === (string) $profile['is_premium'];
            $headline = $is_premium && !empty($profile['premium_hero_text']) ? $profile['premium_hero_text'] : $primary_name;
            $subheadline = $is_premium && !empty($profile['premium_subheadline']) ? $profile['premium_subheadline'] : trim($services . ($markets ? ' | ' . $markets : ''));
            $about_prompt = $is_premium
                ? sprintf(__('Use Elementor to add a richer sales page for %s, including custom sections, media, testimonials, and conversion CTAs.', 'madextra-citations'), $primary_name)
                : sprintf(__('Use Elementor to customize this business profile for %s with stronger local copy, trust signals, and contact blocks.', 'madextra-citations'), $primary_name);

            return implode(
                "\n\n",
                array(
                    '<!-- Generated by MadExtra Citations. Open this page in Elementor and replace or expand the starter sections below. -->',
                    '<section class="mec-starter-shell">',
                    '<h1>' . esc_html($headline) . '</h1>',
                    ($subheadline ? '<p>' . esc_html($subheadline) . '</p>' : ''),
                    '</section>',
                    '<section class="mec-starter-shell">',
                    '<h2>' . esc_html__('Live Business Profile', 'madextra-citations') . '</h2>',
                    '<p>' . esc_html__('This section stays connected to the citation profile data. Keep it, move it, or style around it in Elementor.', 'madextra-citations') . '</p>',
                    '[' . self::PROFILE_SHORTCODE . ' id="' . (int) $post_id . '"]',
                    '</section>',
                    '<section class="mec-starter-shell">',
                    '<h2>' . esc_html(sprintf(__('About %s', 'madextra-citations'), $primary_name)) . '</h2>',
                    '<p>' . esc_html($about_prompt) . '</p>',
                    '</section>',
                    '<section class="mec-starter-shell">',
                    '<h2>' . esc_html__('Custom CTA Section', 'madextra-citations') . '</h2>',
                    '<p>' . esc_html__('Add booking buttons, forms, maps, reviews, FAQs, and any city-specific content here.', 'madextra-citations') . '</p>',
                    '</section>',
                )
            );
        }

        private static function apply_elementor_page_defaults($page_id)
        {
            $page_id = (int) $page_id;
            if ($page_id <= 0) {
                return;
            }

            if (!defined('ELEMENTOR_VERSION') && !did_action('elementor/loaded')) {
                return;
            }

            update_post_meta($page_id, '_elementor_edit_mode', 'builder');
            update_post_meta($page_id, '_elementor_template_type', 'wp-page');
            if (defined('ELEMENTOR_VERSION')) {
                update_post_meta($page_id, '_elementor_version', ELEMENTOR_VERSION);
            }

            $templates = wp_get_theme()->get_page_templates(null, 'page');
            if (in_array('elementor_canvas', $templates, true)) {
                update_post_meta($page_id, '_wp_page_template', 'elementor_canvas');
            } elseif (in_array('elementor_header_footer', $templates, true)) {
                update_post_meta($page_id, '_wp_page_template', 'elementor_header_footer');
            }
        }

        private static function generate_public_profile_page($post_id)
        {
            $profile = self::profile_to_public_data($post_id);
            if (!$profile) {
                return new WP_Error('invalid_profile', __('Profile not found.', 'madextra-citations'));
            }

            $existing_page_id = (int) get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true);
            $page_title = !empty($profile['nap_business_name']) ? $profile['nap_business_name'] : get_the_title($post_id);
            $market_suffix = !empty($profile['markets']) ? ' ' . $profile['markets'][0] : '';
            $page_slug = sanitize_title($page_title . $market_suffix);

            if (!$existing_page_id && $page_slug) {
                $existing = get_page_by_path($page_slug);
                if ($existing instanceof WP_Post) {
                    $existing_page_id = (int) $existing->ID;
                }
            }

            $default_content = self::public_profile_page_content($post_id);
            $page_args = array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => $page_title,
                'post_name' => $page_slug,
            );

            if ($existing_page_id > 0) {
                $existing_page = get_post($existing_page_id);
                $page_args['ID'] = $existing_page_id;
                if ($existing_page instanceof WP_Post) {
                    $existing_content = trim((string) $existing_page->post_content);
                    if ('' === $existing_content || false !== strpos($existing_content, '[' . self::PROFILE_SHORTCODE)) {
                        $page_args['post_content'] = $default_content;
                    }
                }
                $result = wp_update_post($page_args, true);
            } else {
                $page_args['post_content'] = $default_content;
                $result = wp_insert_post($page_args, true);
            }

            if (is_wp_error($result)) {
                return $result;
            }

            update_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', (string) (int) $result);
            self::apply_elementor_page_defaults((int) $result);
            if ('1' === (string) get_post_meta($post_id, self::META_PREFIX . 'is_premium', true)) {
                update_post_meta($post_id, self::META_PREFIX . 'premium_last_generated_at', current_time('mysql'));
                if (!get_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', true)) {
                    update_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', 'premium');
                }
                if (!get_post_meta($post_id, self::META_PREFIX . 'premium_page_status', true)) {
                    update_post_meta($post_id, self::META_PREFIX . 'premium_page_status', 'active');
                }
            }

            return (int) $result;
        }

        public static function register_admin_columns($columns)
        {
            $columns['title'] = __('Business', 'madextra-citations');
            unset($columns['date']);
            $columns['directory_name'] = __('Directory', 'madextra-citations');
            $columns['public_profile_page'] = __('Public Page', 'madextra-citations');
            $columns['premium_status'] = __('Premium', 'madextra-citations');
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

            if ('public_profile_page' === $column) {
                $page_id = (int) get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true);
                if ($page_id > 0) {
                    echo '<a href="' . esc_url(get_edit_post_link($page_id, '')) . '">' . esc_html(get_the_title($page_id)) . '</a>';
                    echo '<div><a href="' . esc_url(get_permalink($page_id)) . '" target="_blank" rel="noopener">' . esc_html__('View', 'madextra-citations') . '</a></div>';
                } else {
                    echo '&mdash;';
                }
                return;
            }

            if ('premium_status' === $column) {
                $premium = '1' === (string) get_post_meta($post_id, self::META_PREFIX . 'is_premium', true);
                $mode = get_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', true);
                echo $premium ? esc_html__('Yes', 'madextra-citations') : esc_html__('No', 'madextra-citations');
                if ($mode) {
                    echo '<div>' . esc_html($mode) . '</div>';
                }
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
                if ('1' === $featured) {
                    echo esc_html(sprintf(__('Yes, slot %d', 'madextra-citations'), max(1, $order)));
                } else {
                    echo esc_html__('No', 'madextra-citations');
                }

                if (current_user_can('manage_citation_profiles')) {
                    $actions = array();
                    foreach (array(1, 2, 3) as $slot) {
                        $actions[] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url(self::featured_slot_url($post_id, $slot)),
                            esc_html(sprintf(__('Set %d', 'madextra-citations'), $slot))
                        );
                    }
                    $actions[] = sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(self::featured_slot_url($post_id, 0)),
                        esc_html__('Remove', 'madextra-citations')
                    );
                    echo '<div class="row-actions" style="position:static;visibility:visible;">' . wp_kses_post(implode(' | ', $actions)) . '</div>';
                }
            }
        }

        public static function filter_admin_row_actions($actions, $post)
        {
            if (!$post instanceof WP_Post || self::CPT !== $post->post_type || !current_user_can('manage_citation_profiles')) {
                return $actions;
            }

            $actions['feature_slot_1'] = '<a href="' . esc_url(self::featured_slot_url($post->ID, 1)) . '">' . esc_html__('Feature 1', 'madextra-citations') . '</a>';
            $actions['feature_slot_2'] = '<a href="' . esc_url(self::featured_slot_url($post->ID, 2)) . '">' . esc_html__('Feature 2', 'madextra-citations') . '</a>';
            $actions['feature_slot_3'] = '<a href="' . esc_url(self::featured_slot_url($post->ID, 3)) . '">' . esc_html__('Feature 3', 'madextra-citations') . '</a>';
            $actions['feature_remove'] = '<a href="' . esc_url(self::featured_slot_url($post->ID, 0)) . '">' . esc_html__('Remove Featured', 'madextra-citations') . '</a>';
            $actions['generate_public_page'] = '<a href="' . esc_url(self::generate_profile_page_url($post->ID)) . '">' . esc_html__('Generate Public Page', 'madextra-citations') . '</a>';
            $actions['upgrade_premium'] = '<a href="' . esc_url(self::premium_action_url($post->ID, 'upgrade')) . '">' . esc_html__('Upgrade To Premium', 'madextra-citations') . '</a>';
            $actions['downgrade_premium'] = '<a href="' . esc_url(self::premium_action_url($post->ID, 'downgrade')) . '">' . esc_html__('Downgrade Premium', 'madextra-citations') . '</a>';

            return $actions;
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

        public static function register_stripe_submenu()
        {
            add_submenu_page(
                'edit.php?post_type=' . self::CPT,
                __('Stripe Settings', 'madextra-citations'),
                __('Stripe Settings', 'madextra-citations'),
                'read',
                'mec-stripe-settings',
                array(__CLASS__, 'render_stripe_settings_page')
            );
        }

        public static function register_premium_queue_submenu()
        {
            add_submenu_page(
                'edit.php?post_type=' . self::CPT,
                __('Premium Queue', 'madextra-citations'),
                __('Premium Queue', 'madextra-citations'),
                'read',
                'mec-premium-queue',
                array(__CLASS__, 'render_premium_queue_page')
            );
        }

        private static function premium_action_url($post_id, $task)
        {
            return wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'mec_premium_profile_action',
                        'post_id' => (int) $post_id,
                        'task' => sanitize_key($task),
                    ),
                    admin_url('admin-post.php')
                ),
                'mec_premium_profile_action_' . (int) $post_id . '_' . sanitize_key($task)
            );
        }

        private static function premium_global_action_url($task)
        {
            return wp_nonce_url(
                add_query_arg(
                    array(
                        'action' => 'mec_premium_profile_action',
                        'task' => sanitize_key($task),
                    ),
                    admin_url('admin-post.php')
                ),
                'mec_premium_profile_global_' . sanitize_key($task)
            );
        }

        private static function premium_queue_redirect_url()
        {
            return add_query_arg(
                array(
                    'post_type' => self::CPT,
                    'page' => 'mec-premium-queue',
                ),
                admin_url('edit.php')
            );
        }

        private static function execute_premium_profile_task($post_id, $task)
        {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || self::CPT !== get_post_type($post_id)) {
                return new WP_Error('invalid_citation_profile', __('Invalid citation profile.', 'madextra-citations'));
            }

            if ('generate' === $task) {
                return self::generate_public_profile_page($post_id);
            }

            if ('upgrade' === $task) {
                update_post_meta($post_id, self::META_PREFIX . 'is_premium', '1');
                update_post_meta($post_id, self::META_PREFIX . 'premium_manual_override', '1');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', 'premium');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_status', 'active');
                return self::generate_public_profile_page($post_id);
            }

            if ('regenerate' === $task) {
                $page_id = self::generate_public_profile_page($post_id);
                if (!is_wp_error($page_id)) {
                    update_post_meta($post_id, self::META_PREFIX . 'premium_last_generated_at', current_time('mysql'));
                }
                return $page_id;
            }

            if ('downgrade' === $task) {
                update_post_meta($post_id, self::META_PREFIX . 'is_premium', '0');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', 'standard');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_status', 'inactive');
                return $post_id;
            }

            return new WP_Error('invalid_premium_task', __('Invalid premium task.', 'madextra-citations'));
        }

        private static function run_premium_global_task($task)
        {
            $task = sanitize_key((string) $task);
            $post_ids = array();

            if ('generate_missing' === $task) {
                $query = new WP_Query(
                    array(
                        'post_type' => self::CPT,
                        'post_status' => array('publish', 'draft', 'pending', 'private'),
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                        'meta_query' => array(
                            'relation' => 'OR',
                            array(
                                'key' => self::META_PREFIX . 'public_profile_page_id',
                                'compare' => 'NOT EXISTS',
                            ),
                            array(
                                'key' => self::META_PREFIX . 'public_profile_page_id',
                                'value' => '',
                            ),
                            array(
                                'key' => self::META_PREFIX . 'public_profile_page_id',
                                'value' => '0',
                            ),
                        ),
                    )
                );
                $post_ids = array_map('intval', (array) $query->posts);
                $task = 'generate';
            } elseif ('regenerate_premium_all' === $task) {
                $query = new WP_Query(
                    array(
                        'post_type' => self::CPT,
                        'post_status' => array('publish', 'draft', 'pending', 'private'),
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                        'meta_query' => array(
                            array(
                                'key' => self::META_PREFIX . 'is_premium',
                                'value' => '1',
                            ),
                        ),
                    )
                );
                $post_ids = array_map('intval', (array) $query->posts);
                $task = 'regenerate';
            } else {
                return new WP_Error('invalid_global_task', __('Invalid premium bulk task.', 'madextra-citations'));
            }

            return self::run_premium_bulk_task($post_ids, $task);
        }

        private static function run_premium_bulk_task(array $post_ids, $task)
        {
            $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
            $task = sanitize_key((string) $task);
            $success = 0;
            $errors = array();

            foreach ($post_ids as $post_id) {
                $result = self::execute_premium_profile_task($post_id, $task);
                if (is_wp_error($result)) {
                    $errors[] = $result->get_error_message();
                    continue;
                }
                $success++;
            }

            return array(
                'count' => $success,
                'errors' => $errors,
                'total' => count($post_ids),
                'task' => $task,
            );
        }

        private static function elementor_edit_url($page_id)
        {
            $page_id = (int) $page_id;
            if ($page_id <= 0) {
                return '';
            }
            return add_query_arg(
                array(
                    'post' => $page_id,
                    'action' => 'elementor',
                ),
                admin_url('post.php')
            );
        }

        public static function render_premium_queue_page()
        {
            if (!self::can_manage_premium_queue()) {
                wp_die(esc_html__('You do not have permission to view this page.', 'madextra-citations'));
            }

            $query = new WP_Query(
                array(
                    'post_type' => self::CPT,
                    'post_status' => array('publish', 'draft', 'pending', 'private'),
                    'posts_per_page' => 200,
                    'orderby' => 'title',
                    'order' => 'ASC',
                )
            );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Premium Queue', 'madextra-citations'); ?></h1>
                <p><?php esc_html_e('Manage premium profile pages, Stripe claims, and Elementor-ready business pages.', 'madextra-citations'); ?></p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(self::premium_global_action_url('generate_missing')); ?>"><?php esc_html_e('Generate Missing Pages', 'madextra-citations'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url(self::premium_global_action_url('regenerate_premium_all')); ?>"><?php esc_html_e('Regenerate All Premium Pages', 'madextra-citations'); ?></a>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mec_bulk_premium_profile_action', 'mec_bulk_premium_profile_nonce'); ?>
                    <input type="hidden" name="action" value="mec_bulk_premium_profile_action">
                    <div style="display:flex;gap:10px;align-items:center;margin:0 0 12px;">
                        <select name="bulk_task">
                            <option value=""><?php esc_html_e('Bulk Actions', 'madextra-citations'); ?></option>
                            <option value="generate"><?php esc_html_e('Generate Public Pages', 'madextra-citations'); ?></option>
                            <option value="upgrade"><?php esc_html_e('Upgrade To Premium + Generate', 'madextra-citations'); ?></option>
                            <option value="regenerate"><?php esc_html_e('Regenerate Premium Layouts', 'madextra-citations'); ?></option>
                            <option value="downgrade"><?php esc_html_e('Downgrade Premium', 'madextra-citations'); ?></option>
                        </select>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'madextra-citations'); ?></button>
                    </div>
                    <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><input type="checkbox" class="mec-bulk-toggle" aria-label="<?php esc_attr_e('Select all profiles', 'madextra-citations'); ?>"></th>
                        <th><?php esc_html_e('Business', 'madextra-citations'); ?></th>
                        <th><?php esc_html_e('Premium', 'madextra-citations'); ?></th>
                        <th><?php esc_html_e('Public Page', 'madextra-citations'); ?></th>
                        <th><?php esc_html_e('Claim Status', 'madextra-citations'); ?></th>
                        <th><?php esc_html_e('Featured', 'madextra-citations'); ?></th>
                        <th><?php esc_html_e('Actions', 'madextra-citations'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$query->have_posts()) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No profiles found.', 'madextra-citations'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($query->posts as $profile_post) : ?>
                            <?php
                            $post_id = (int) $profile_post->ID;
                            $page_id = (int) get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true);
                            $is_premium = '1' === (string) get_post_meta($post_id, self::META_PREFIX . 'is_premium', true);
                            $claim_status = get_post_meta($post_id, self::META_PREFIX . 'self_serve_payment_status', true);
                            $featured = '1' === (string) get_post_meta($post_id, self::META_PREFIX . 'is_featured', true);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="profile_ids[]" value="<?php echo esc_attr((string) $post_id); ?>" class="mec-profile-checkbox"></td>
                                <td>
                                    <strong><?php echo esc_html(get_the_title($post_id)); ?></strong>
                                    <div><a href="<?php echo esc_url(get_edit_post_link($post_id, '')); ?>"><?php esc_html_e('Edit profile', 'madextra-citations'); ?></a></div>
                                </td>
                                <td><?php echo $is_premium ? esc_html__('Yes', 'madextra-citations') : esc_html__('No', 'madextra-citations'); ?></td>
                                <td>
                                    <?php if ($page_id > 0) : ?>
                                        <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title($page_id)); ?></a>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($claim_status ? $claim_status : __('unpaid', 'madextra-citations')); ?></td>
                                <td><?php echo $featured ? esc_html__('Yes', 'madextra-citations') : esc_html__('No', 'madextra-citations'); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(self::premium_action_url($post_id, 'generate')); ?>"><?php esc_html_e('Generate Public Page', 'madextra-citations'); ?></a> |
                                    <a href="<?php echo esc_url(self::premium_action_url($post_id, 'upgrade')); ?>"><?php esc_html_e('Upgrade To Premium', 'madextra-citations'); ?></a> |
                                    <a href="<?php echo esc_url(self::premium_action_url($post_id, 'regenerate')); ?>"><?php esc_html_e('Regenerate Premium Layout', 'madextra-citations'); ?></a> |
                                    <a href="<?php echo esc_url(self::premium_action_url($post_id, 'downgrade')); ?>"><?php esc_html_e('Downgrade Premium', 'madextra-citations'); ?></a>
                                    <?php if ($page_id > 0) : ?> |
                                        <a href="<?php echo esc_url(self::elementor_edit_url($page_id)); ?>"><?php esc_html_e('Open In Elementor', 'madextra-citations'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    </table>
                </form>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var toggle = document.querySelector('.mec-bulk-toggle');
                        if (!toggle) {
                            return;
                        }
                        toggle.addEventListener('change', function () {
                            document.querySelectorAll('.mec-profile-checkbox').forEach(function (checkbox) {
                                checkbox.checked = !!toggle.checked;
                            });
                        });
                    });
                </script>
            </div>
            <?php
        }

        public static function handle_premium_profile_action()
        {
            if (!self::can_manage_premium_queue()) {
                wp_die(esc_html__('You do not have permission to manage premium profiles.', 'madextra-citations'));
            }

            $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
            $task = isset($_GET['task']) ? sanitize_key(wp_unslash($_GET['task'])) : '';
            if (in_array($task, array('generate_missing', 'regenerate_premium_all'), true)) {
                check_admin_referer('mec_premium_profile_global_' . $task);
                $summary = self::run_premium_global_task($task);
                if (is_wp_error($summary)) {
                    self::queue_notice($summary->get_error_message(), 'error');
                } else {
                    $message = sprintf(
                        __('Completed %1$s for %2$d of %3$d profiles.', 'madextra-citations'),
                        str_replace('_', ' ', $summary['task']),
                        (int) $summary['count'],
                        (int) $summary['total']
                    );
                    self::queue_notice($message, !empty($summary['errors']) ? 'warning' : 'success');
                }
                wp_safe_redirect(self::premium_queue_redirect_url());
                exit;
            }

            if (!$post_id || self::CPT !== get_post_type($post_id)) {
                wp_die(esc_html__('Invalid citation profile.', 'madextra-citations'));
            }
            check_admin_referer('mec_premium_profile_action_' . $post_id . '_' . $task);

            $result = self::execute_premium_profile_task($post_id, $task);
            if (is_wp_error($result)) {
                self::queue_notice($result->get_error_message(), 'error');
            } elseif ('generate' === $task) {
                self::queue_notice(__('Public profile page generated.', 'madextra-citations'), 'success');
            } elseif ('upgrade' === $task) {
                self::queue_notice(__('Profile upgraded to premium.', 'madextra-citations'), 'success');
            } elseif ('regenerate' === $task) {
                self::queue_notice(__('Premium layout regenerated.', 'madextra-citations'), 'success');
            } elseif ('downgrade' === $task) {
                self::queue_notice(__('Profile downgraded from premium.', 'madextra-citations'), 'success');
            }

            wp_safe_redirect(self::premium_queue_redirect_url());
            exit;
        }

        public static function handle_bulk_premium_profile_action()
        {
            if (!self::can_manage_premium_queue()) {
                wp_die(esc_html__('You do not have permission to manage premium profiles.', 'madextra-citations'));
            }

            if (!isset($_POST['mec_bulk_premium_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mec_bulk_premium_profile_nonce'])), 'mec_bulk_premium_profile_action')) {
                wp_die(esc_html__('Invalid request.', 'madextra-citations'));
            }

            $task = isset($_POST['bulk_task']) ? sanitize_key(wp_unslash($_POST['bulk_task'])) : '';
            $post_ids = isset($_POST['profile_ids']) ? array_map('intval', (array) wp_unslash($_POST['profile_ids'])) : array();
            if (!$task) {
                self::queue_notice(__('Choose a bulk premium action first.', 'madextra-citations'), 'warning');
                wp_safe_redirect(self::premium_queue_redirect_url());
                exit;
            }

            if (!$post_ids) {
                self::queue_notice(__('Select at least one profile first.', 'madextra-citations'), 'warning');
                wp_safe_redirect(self::premium_queue_redirect_url());
                exit;
            }

            $summary = self::run_premium_bulk_task($post_ids, $task);
            $message = sprintf(
                __('Completed %1$s for %2$d of %3$d selected profiles.', 'madextra-citations'),
                str_replace('_', ' ', $summary['task']),
                (int) $summary['count'],
                (int) $summary['total']
            );
            self::queue_notice($message, !empty($summary['errors']) ? 'warning' : 'success');

            wp_safe_redirect(self::premium_queue_redirect_url());
            exit;
        }

        public static function render_stripe_settings_page()
        {
            if (!self::can_manage_stripe_settings()) {
                wp_die(esc_html__('You do not have permission to view this page.', 'madextra-citations'));
            }

            $settings = self::get_stripe_settings();
            $webhook_url = rest_url('madextra-citations/v1/stripe/webhook');
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Stripe Settings', 'madextra-citations'); ?></h1>
                <p><?php esc_html_e('Use Stripe Payment Links for self-serve profile claims and upgrades.', 'madextra-citations'); ?></p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mec_save_stripe_settings">
                    <?php wp_nonce_field('mec_save_stripe_settings', 'mec_stripe_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mec_default_payment_link_url"><?php esc_html_e('Default Stripe Payment Link', 'madextra-citations'); ?></label></th>
                            <td>
                                <input type="url" class="large-text code" id="mec_default_payment_link_url" name="mec_stripe[default_payment_link_url]" value="<?php echo esc_attr($settings['default_payment_link_url']); ?>" placeholder="https://buy.stripe.com/...">
                                <p class="description"><?php esc_html_e('Used when a profile does not have its own Stripe payment link.', 'madextra-citations'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mec_webhook_signing_secret"><?php esc_html_e('Webhook Signing Secret', 'madextra-citations'); ?></label></th>
                            <td>
                                <input type="text" class="large-text code" id="mec_webhook_signing_secret" name="mec_stripe[webhook_signing_secret]" value="<?php echo esc_attr($settings['webhook_signing_secret']); ?>" placeholder="whsec_...">
                                <p class="description"><?php esc_html_e('Create a Stripe webhook endpoint and paste the signing secret here.', 'madextra-citations'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Automation', 'madextra-citations'); ?></th>
                            <td>
                                <label><input type="checkbox" name="mec_stripe[auto_publish_paid_profiles]" value="1" <?php checked($settings['auto_publish_paid_profiles'], '1'); ?>> <?php esc_html_e('Automatically mark paid self-serve profiles as Live', 'madextra-citations'); ?></label>
                                <br>
                                <label><input type="checkbox" name="mec_stripe[auto_generate_public_page]" value="1" <?php checked($settings['auto_generate_public_page'], '1'); ?>> <?php esc_html_e('Automatically create/update the public profile page after payment', 'madextra-citations'); ?></label>
                                <br>
                                <label><input type="checkbox" name="mec_stripe[auto_upgrade_to_premium]" value="1" <?php checked($settings['auto_upgrade_to_premium'], '1'); ?>> <?php esc_html_e('Automatically upgrade paid self-serve profiles to premium', 'madextra-citations'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Webhook URL', 'madextra-citations'); ?></th>
                            <td>
                                <code><?php echo esc_html($webhook_url); ?></code>
                                <p class="description"><?php esc_html_e('Add this URL in Stripe and listen for checkout.session.completed and checkout.session.async_payment_succeeded.', 'madextra-citations'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save Stripe Settings', 'madextra-citations')); ?>
                </form>
            </div>
            <?php
        }

        public static function handle_save_stripe_settings()
        {
            if (!self::can_manage_stripe_settings()) {
                wp_die(esc_html__('You do not have permission to manage Stripe settings.', 'madextra-citations'));
            }

            if (!isset($_POST['mec_stripe_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mec_stripe_nonce'])), 'mec_save_stripe_settings')) {
                wp_die(esc_html__('Invalid request.', 'madextra-citations'));
            }

            $payload = isset($_POST['mec_stripe']) ? (array) wp_unslash($_POST['mec_stripe']) : array();
            $settings = array(
                'default_payment_link_url' => isset($payload['default_payment_link_url']) ? esc_url_raw($payload['default_payment_link_url']) : '',
                'webhook_signing_secret' => isset($payload['webhook_signing_secret']) ? sanitize_text_field($payload['webhook_signing_secret']) : '',
                'auto_publish_paid_profiles' => !empty($payload['auto_publish_paid_profiles']) ? '1' : '0',
                'auto_generate_public_page' => !empty($payload['auto_generate_public_page']) ? '1' : '0',
                'auto_upgrade_to_premium' => !empty($payload['auto_upgrade_to_premium']) ? '1' : '0',
            );

            update_option(self::STRIPE_OPTION, wp_parse_args($settings, self::default_stripe_settings()), false);
            self::queue_notice(__('Stripe settings saved.', 'madextra-citations'), 'success');
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'post_type' => self::CPT,
                        'page' => 'mec-stripe-settings',
                    ),
                    admin_url('edit.php')
                )
            );
            exit;
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

            $duplicate_validation = self::validate_duplicate_profile($existing, $clean);
            if (is_wp_error($duplicate_validation) && !$existing) {
                return $duplicate_validation;
            }

            $featured_validation = self::validate_featured_slot($existing, $clean, $market_ids);
            if (is_wp_error($featured_validation)) {
                return $featured_validation;
            }

            $post_data = array(
                'post_type'   => self::CPT,
                'post_status' => 'publish',
                'post_title'  => self::admin_business_title($clean),
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
            if (!empty($clean['self_serve_cta_url']) && !wp_http_validate_url($clean['self_serve_cta_url'])) {
                return new WP_Error('invalid_self_serve_cta_url', __('Invalid Stripe payment link / claim URL.', 'madextra-citations'));
            }
            if (!empty($clean['primary_cta_url']) && !wp_http_validate_url($clean['primary_cta_url'])) {
                return new WP_Error('invalid_primary_cta_url', __('Invalid primary CTA URL.', 'madextra-citations'));
            }
            if (!empty($clean['secondary_cta_url']) && !wp_http_validate_url($clean['secondary_cta_url'])) {
                return new WP_Error('invalid_secondary_cta_url', __('Invalid secondary CTA URL.', 'madextra-citations'));
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

        private static function featured_slot_url($post_id, $slot)
        {
            return wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'mec_set_featured_slot',
                        'post_id' => (int) $post_id,
                        'slot'    => (int) $slot,
                    ),
                    admin_url('admin-post.php')
                ),
                'mec_set_featured_slot_' . (int) $post_id . '_' . (int) $slot
            );
        }

        private static function generate_profile_page_url($post_id)
        {
            return wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'mec_generate_profile_page',
                        'post_id' => (int) $post_id,
                    ),
                    admin_url('admin-post.php')
                ),
                'mec_generate_profile_page_' . (int) $post_id
            );
        }

        public static function handle_set_featured_slot()
        {
            if (!current_user_can('manage_citation_profiles')) {
                wp_die(esc_html__('You do not have permission to update featured slots.', 'madextra-citations'));
            }

            $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
            $slot = isset($_GET['slot']) ? (int) $_GET['slot'] : 0;
            $slot = min(3, max(0, $slot));

            if (!$post_id || self::CPT !== get_post_type($post_id)) {
                wp_die(esc_html__('Invalid citation profile.', 'madextra-citations'));
            }

            check_admin_referer('mec_set_featured_slot_' . $post_id . '_' . $slot);

            $market_ids = wp_get_object_terms($post_id, self::TAX_MARKET, array('fields' => 'ids'));
            if (is_wp_error($market_ids)) {
                $market_ids = array();
            }
            $market_ids = array_values(array_filter(array_map('intval', (array) $market_ids)));

            $clean = array(
                'is_featured' => $slot > 0 ? '1' : '0',
                'featured_order' => (string) $slot,
            );

            $validation = self::validate_featured_slot($post_id, $clean, $market_ids);
            if (is_wp_error($validation)) {
                self::queue_notice($validation->get_error_message(), 'error');
            } else {
                update_post_meta($post_id, self::META_PREFIX . 'is_featured', $slot > 0 ? '1' : '0');
                update_post_meta($post_id, self::META_PREFIX . 'featured_order', (string) $slot);
                self::queue_notice(
                    $slot > 0
                        ? sprintf(__('Featured slot updated to %d.', 'madextra-citations'), $slot)
                        : __('Featured status removed.', 'madextra-citations'),
                    'success'
                );
            }

            wp_safe_redirect(
                add_query_arg(
                    array(
                        'post_type' => self::CPT,
                    ),
                    admin_url('edit.php')
                )
            );
            exit;
        }

        public static function handle_generate_profile_page()
        {
            if (!current_user_can('manage_citation_profiles')) {
                wp_die(esc_html__('You do not have permission to generate profile pages.', 'madextra-citations'));
            }

            $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
            if (!$post_id || self::CPT !== get_post_type($post_id)) {
                wp_die(esc_html__('Invalid citation profile.', 'madextra-citations'));
            }

            check_admin_referer('mec_generate_profile_page_' . $post_id);

            $page_id = self::generate_public_profile_page($post_id);
            if (is_wp_error($page_id)) {
                self::queue_notice($page_id->get_error_message(), 'error');
            } else {
                self::queue_notice(__('Public profile page generated.', 'madextra-citations'), 'success');
            }

            wp_safe_redirect(
                add_query_arg(
                    array(
                        'post_type' => self::CPT,
                    ),
                    admin_url('edit.php')
                )
            );
            exit;
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

        private static function can_manage_stripe_settings()
        {
            return self::has_admin_fallback()
                || current_user_can('manage_citation_settings')
                || current_user_can('manage_citation_profiles');
        }

        private static function can_manage_premium_queue()
        {
            return self::has_admin_fallback()
                || current_user_can('manage_citation_profiles')
                || current_user_can('manage_citation_settings');
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
                'self_serve_enabled',
                'self_serve_cta_label',
                'self_serve_cta_url',
                'self_serve_price_text',
                'public_profile_page_id',
                'is_premium',
                'service_areas',
                'faq_items',
                'social_links',
                'gallery_media_ids',
                'primary_cta_label',
                'primary_cta_url',
                'secondary_cta_label',
                'secondary_cta_url',
                'premium_hero_text',
                'premium_subheadline',
                'extended_about_copy',
                'services_summary',
                'service_cards',
                'premium_badge_text',
                'premium_page_mode',
                'premium_page_status',
                'premium_last_generated_at',
                'premium_layout_template_key',
                'premium_manual_override',
                'premium_notes',
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
                    get_post_meta($post_id, self::META_PREFIX . 'self_serve_enabled', true),
                    get_post_meta($post_id, self::META_PREFIX . 'self_serve_cta_label', true),
                    get_post_meta($post_id, self::META_PREFIX . 'self_serve_cta_url', true),
                    get_post_meta($post_id, self::META_PREFIX . 'self_serve_price_text', true),
                    get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true),
                    get_post_meta($post_id, self::META_PREFIX . 'is_premium', true),
                    get_post_meta($post_id, self::META_PREFIX . 'service_areas', true),
                    get_post_meta($post_id, self::META_PREFIX . 'faq_items', true),
                    get_post_meta($post_id, self::META_PREFIX . 'social_links', true),
                    get_post_meta($post_id, self::META_PREFIX . 'gallery_media_ids', true),
                    get_post_meta($post_id, self::META_PREFIX . 'primary_cta_label', true),
                    get_post_meta($post_id, self::META_PREFIX . 'primary_cta_url', true),
                    get_post_meta($post_id, self::META_PREFIX . 'secondary_cta_label', true),
                    get_post_meta($post_id, self::META_PREFIX . 'secondary_cta_url', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_hero_text', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_subheadline', true),
                    get_post_meta($post_id, self::META_PREFIX . 'extended_about_copy', true),
                    get_post_meta($post_id, self::META_PREFIX . 'services_summary', true),
                    get_post_meta($post_id, self::META_PREFIX . 'service_cards', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_badge_text', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_page_status', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_last_generated_at', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_layout_template_key', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_manual_override', true),
                    get_post_meta($post_id, self::META_PREFIX . 'premium_notes', true),
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

            register_rest_route(
                'madextra-citations/v1',
                '/stripe/webhook',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array(__CLASS__, 'rest_handle_stripe_webhook'),
                    'permission_callback' => '__return_true',
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

        private static function extract_profile_id_from_reference($reference)
        {
            $reference = sanitize_text_field((string) $reference);
            if (preg_match('/mec[_-]profile[_-](\d+)/i', $reference, $matches)) {
                return (int) $matches[1];
            }
            return 0;
        }

        private static function build_self_serve_payment_url(array $profile)
        {
            $base_url = '';
            if (!empty($profile['self_serve_cta_url'])) {
                $base_url = $profile['self_serve_cta_url'];
            } else {
                $settings = self::get_stripe_settings();
                if (!empty($settings['default_payment_link_url'])) {
                    $base_url = $settings['default_payment_link_url'];
                }
            }

            if (!$base_url || !wp_http_validate_url($base_url)) {
                return '';
            }

            $args = array(
                'client_reference_id' => 'mec_profile_' . (int) $profile['id'],
            );
            if (!empty($profile['business_email'])) {
                $args['prefilled_email'] = $profile['business_email'];
            }

            return add_query_arg($args, $base_url);
        }

        private static function verify_stripe_webhook_signature($payload, $signature_header, $secret)
        {
            $payload = (string) $payload;
            $signature_header = (string) $signature_header;
            $secret = (string) $secret;
            if ('' === $payload || '' === $signature_header || '' === $secret) {
                return false;
            }

            $parts = array();
            foreach (explode(',', $signature_header) as $fragment) {
                $fragment = trim($fragment);
                if (false === strpos($fragment, '=')) {
                    continue;
                }
                list($key, $value) = array_map('trim', explode('=', $fragment, 2));
                if (!isset($parts[$key])) {
                    $parts[$key] = array();
                }
                $parts[$key][] = $value;
            }

            $timestamp = isset($parts['t'][0]) ? (int) $parts['t'][0] : 0;
            $signatures = isset($parts['v1']) ? (array) $parts['v1'] : array();
            if ($timestamp <= 0 || !$signatures) {
                return false;
            }

            if (abs(time() - $timestamp) > self::STRIPE_WEBHOOK_TOLERANCE) {
                return false;
            }

            $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
            foreach ($signatures as $candidate) {
                if (hash_equals($expected, (string) $candidate)) {
                    return true;
                }
            }

            return false;
        }

        private static function apply_paid_profile_claim($post_id, array $session)
        {
            if ($post_id <= 0 || self::CPT !== get_post_type($post_id)) {
                return new WP_Error('invalid_profile', __('Invalid citation profile.', 'madextra-citations'));
            }

            $settings = self::get_stripe_settings();
            $existing_session = get_post_meta($post_id, self::META_PREFIX . 'self_serve_checkout_session_id', true);
            $session_id = isset($session['id']) ? sanitize_text_field((string) $session['id']) : '';
            if ($session_id && $existing_session === $session_id) {
                return true;
            }

            $email = '';
            if (!empty($session['customer_details']['email'])) {
                $email = sanitize_email($session['customer_details']['email']);
            } elseif (!empty($session['customer_email'])) {
                $email = sanitize_email($session['customer_email']);
            }

            update_post_meta($post_id, self::META_PREFIX . 'self_serve_payment_status', 'paid');
            update_post_meta($post_id, self::META_PREFIX . 'self_serve_claimed_at', current_time('mysql'));
            update_post_meta($post_id, self::META_PREFIX . 'self_serve_checkout_session_id', $session_id);
            if ($email) {
                update_post_meta($post_id, self::META_PREFIX . 'self_serve_claim_email', $email);
            }

            if ('1' === (string) $settings['auto_publish_paid_profiles']) {
                update_post_meta($post_id, self::META_PREFIX . 'status', 'live');
            }

            if ('1' === (string) $settings['auto_upgrade_to_premium']) {
                update_post_meta($post_id, self::META_PREFIX . 'is_premium', '1');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', 'premium');
                update_post_meta($post_id, self::META_PREFIX . 'premium_page_status', 'active');
                update_post_meta($post_id, self::META_PREFIX . 'premium_manual_override', '0');
            }

            if ('1' === (string) $settings['auto_generate_public_page']) {
                self::generate_public_profile_page($post_id);
            }

            return true;
        }

        public static function rest_handle_stripe_webhook(WP_REST_Request $request)
        {
            $payload = $request->get_body();
            $signature_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_STRIPE_SIGNATURE'])) : '';
            $settings = self::get_stripe_settings();
            $secret = isset($settings['webhook_signing_secret']) ? (string) $settings['webhook_signing_secret'] : '';

            if ('' === $secret) {
                return new WP_REST_Response(array('received' => false, 'message' => 'Webhook secret is not configured.'), 400);
            }

            if (!self::verify_stripe_webhook_signature($payload, $signature_header, $secret)) {
                return new WP_REST_Response(array('received' => false, 'message' => 'Invalid Stripe signature.'), 400);
            }

            $event = json_decode($payload, true);
            if (!is_array($event) || empty($event['type'])) {
                return new WP_REST_Response(array('received' => false, 'message' => 'Invalid event payload.'), 400);
            }

            $type = sanitize_text_field((string) $event['type']);
            $object = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : array();

            if (in_array($type, array('checkout.session.completed', 'checkout.session.async_payment_succeeded'), true)) {
                $profile_id = self::extract_profile_id_from_reference(isset($object['client_reference_id']) ? $object['client_reference_id'] : '');
                if ($profile_id > 0) {
                    $result = self::apply_paid_profile_claim($profile_id, $object);
                    if (is_wp_error($result)) {
                        return new WP_REST_Response(array('received' => false, 'message' => $result->get_error_message()), 400);
                    }
                }
            } elseif ('checkout.session.async_payment_failed' === $type) {
                $profile_id = self::extract_profile_id_from_reference(isset($object['client_reference_id']) ? $object['client_reference_id'] : '');
                if ($profile_id > 0) {
                    update_post_meta($profile_id, self::META_PREFIX . 'self_serve_payment_status', 'failed');
                }
            }

            return new WP_REST_Response(array('received' => true), 200);
        }

        public static function render_public_submit_form_shortcode($atts)
        {
            $markets = get_terms(array('taxonomy' => self::TAX_MARKET, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $services = get_terms(array('taxonomy' => self::TAX_SERVICE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
            $current_url = home_url($request_uri);
            $notice = isset($_GET['mec_submit']) ? sanitize_key(wp_unslash($_GET['mec_submit'])) : '';
            $match_profile_id = isset($_GET['mec_match']) ? (int) $_GET['mec_match'] : 0;
            $match_profile = $match_profile_id > 0 ? self::profile_to_public_data($match_profile_id) : array();

            ob_start();
            ?>
            <div class="mec-public-submit-wrap">
                <?php if ('success' === $notice) : ?>
                    <div class="mec-submit-note mec-submit-note-success"><?php esc_html_e('Thanks. Your profile was submitted for review.', 'madextra-citations'); ?></div>
                <?php elseif ('duplicate' === $notice) : ?>
                    <div class="mec-submit-note mec-submit-note-error">
                        <?php esc_html_e('That business already exists in the directory.', 'madextra-citations'); ?>
                        <?php if (!empty($match_profile['public_profile_page_url'])) : ?>
                            <a href="<?php echo esc_url($match_profile['public_profile_page_url']); ?>"><?php esc_html_e('View existing profile', 'madextra-citations'); ?></a>
                        <?php endif; ?>
                    </div>
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
            if (!is_wp_error($validation)) {
                $validation = self::validate_duplicate_profile(0, $clean);
            }
            if (is_wp_error($validation)) {
                $submit_status = 'duplicate_profile' === $validation->get_error_code() ? 'duplicate' : 'error';
                $match = $validation->get_error_data();
                if ('duplicate' === $submit_status && !empty($match['post_id'])) {
                    $redirect = add_query_arg('mec_match', (int) $match['post_id'], $redirect);
                }
                wp_safe_redirect(add_query_arg('mec_submit', $submit_status, $redirect));
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
                    'post_title'  => self::admin_business_title($clean),
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

        public static function render_public_profile_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'id' => 0,
                ),
                $atts,
                self::PROFILE_SHORTCODE
            );

            $post_id = (int) $atts['id'];
            if ($post_id <= 0 || self::CPT !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
                return '<p>' . esc_html__('Profile not found.', 'madextra-citations') . '</p>';
            }

            $profile = self::profile_to_public_data($post_id);
            $services = !empty($profile['services']) ? implode(', ', (array) $profile['services']) : '';
            $markets = !empty($profile['markets']) ? implode(', ', (array) $profile['markets']) : '';
            $payment_url = self::build_self_serve_payment_url($profile);
            $cta_enabled = !empty($profile['self_serve_enabled']) && '1' === (string) $profile['self_serve_enabled'] && !empty($payment_url);
            $cta_label = !empty($profile['self_serve_cta_label']) ? $profile['self_serve_cta_label'] : __('Claim This Profile', 'madextra-citations');
            $primary_name = self::profile_primary_name($profile);
            $is_premium = !empty($profile['is_premium']) && '1' === (string) $profile['is_premium'];
            $hero_title = $is_premium && !empty($profile['premium_hero_text']) ? $profile['premium_hero_text'] : $primary_name;
            $hero_subheadline = $is_premium && !empty($profile['premium_subheadline']) ? $profile['premium_subheadline'] : $services;
            $service_areas = self::split_multiline_items(isset($profile['service_areas']) ? $profile['service_areas'] : '');
            $faq_items = self::split_pipe_rows(isset($profile['faq_items']) ? $profile['faq_items'] : '', 2);
            $social_links = self::split_pipe_rows(isset($profile['social_links']) ? $profile['social_links'] : '', 2);
            $service_cards = self::split_pipe_rows(isset($profile['service_cards']) ? $profile['service_cards'] : '', 2);
            $gallery_ids = self::parse_gallery_media_ids(isset($profile['gallery_media_ids']) ? $profile['gallery_media_ids'] : '');
            $primary_cta_label = !empty($profile['primary_cta_label']) ? $profile['primary_cta_label'] : __('Contact This Business', 'madextra-citations');
            $secondary_cta_label = !empty($profile['secondary_cta_label']) ? $profile['secondary_cta_label'] : __('Visit Website', 'madextra-citations');

            ob_start();
            ?>
            <div class="mec-public-profile">
                <section class="mec-public-hero">
                    <div class="mec-public-brand">
                        <?php if (!empty($profile['business_logo_url'])) : ?>
                            <img src="<?php echo esc_url($profile['business_logo_url']); ?>" alt="<?php echo esc_attr($primary_name); ?>">
                        <?php else : ?>
                            <span class="mec-public-logo-fallback"><?php echo esc_html(strtoupper(substr($primary_name, 0, 1))); ?></span>
                        <?php endif; ?>
                        <div>
                            <?php if ($is_premium && !empty($profile['premium_badge_text'])) : ?><p class="mec-public-badge"><?php echo esc_html($profile['premium_badge_text']); ?></p><?php endif; ?>
                            <h2><?php echo esc_html($hero_title); ?></h2>
                            <?php if ($hero_subheadline) : ?><p class="mec-public-subheadline"><?php echo esc_html($hero_subheadline); ?></p><?php endif; ?>
                            <div class="mec-public-meta">
                                <?php if ($services && $hero_subheadline !== $services) : ?><span><?php echo esc_html($services); ?></span><?php endif; ?>
                                <?php if ($markets) : ?><span><?php echo esc_html($markets); ?></span><?php endif; ?>
                                <?php if (!empty($profile['premium_page_status']) && $is_premium) : ?><span><?php echo esc_html(ucwords(str_replace('_', ' ', $profile['premium_page_status']))); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mec-public-actions">
                        <?php if ($is_premium && !empty($profile['primary_cta_url'])) : ?><a class="mec-public-button" href="<?php echo esc_url($profile['primary_cta_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($primary_cta_label); ?></a><?php endif; ?>
                        <?php if ($is_premium && !empty($profile['secondary_cta_url'])) : ?><a class="mec-public-button mec-public-button-secondary" href="<?php echo esc_url($profile['secondary_cta_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($secondary_cta_label); ?></a><?php endif; ?>
                        <?php if ((!$is_premium || empty($profile['secondary_cta_url'])) && !empty($profile['business_website_url'])) : ?><a class="mec-public-button mec-public-button-secondary" href="<?php echo esc_url($profile['business_website_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Visit Website', 'madextra-citations'); ?></a><?php endif; ?>
                        <?php if ($cta_enabled) : ?><a class="mec-public-button" href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener sponsored"><?php echo esc_html($cta_label); ?></a><?php endif; ?>
                    </div>
                </section>

                <section class="mec-public-grid">
                    <article class="mec-public-card">
                        <h3><?php esc_html_e('Business Details', 'madextra-citations'); ?></h3>
                        <?php if (!empty($profile['display_address'])) : ?><p><strong><?php esc_html_e('Address:', 'madextra-citations'); ?></strong><br><?php echo nl2br(esc_html($profile['display_address'])); ?></p><?php endif; ?>
                        <?php if (!empty($profile['nap_phone'])) : ?><p><strong><?php esc_html_e('Phone:', 'madextra-citations'); ?></strong> <?php echo esc_html($profile['nap_phone']); ?></p><?php endif; ?>
                        <?php if (!empty($profile['business_email'])) : ?><p><strong><?php esc_html_e('Email:', 'madextra-citations'); ?></strong> <a href="mailto:<?php echo esc_attr($profile['business_email']); ?>"><?php echo esc_html($profile['business_email']); ?></a></p><?php endif; ?>
                        <?php if (!empty($profile['business_hours'])) : ?><p><strong><?php esc_html_e('Hours:', 'madextra-citations'); ?></strong><br><?php echo nl2br(esc_html($profile['business_hours'])); ?></p><?php endif; ?>
                    </article>

                    <article class="mec-public-card">
                        <h3><?php esc_html_e('About', 'madextra-citations'); ?></h3>
                        <?php if (!empty($profile['business_description'])) : ?><p><?php echo esc_html($profile['business_description']); ?></p><?php endif; ?>
                        <?php if ($is_premium && !empty($profile['extended_about_copy'])) : ?><p><?php echo nl2br(esc_html($profile['extended_about_copy'])); ?></p><?php endif; ?>
                        <?php if (!empty($profile['public_notes'])) : ?><p><?php echo esc_html($profile['public_notes']); ?></p><?php endif; ?>
                        <?php if (!empty($profile['last_verified_date'])) : ?><p><strong><?php esc_html_e('Last Verified:', 'madextra-citations'); ?></strong> <?php echo esc_html($profile['last_verified_date']); ?></p><?php endif; ?>
                    </article>
                </section>

                <?php if ($is_premium && (!empty($profile['services_summary']) || $service_cards)) : ?>
                    <section class="mec-public-card">
                        <h3><?php esc_html_e('Services', 'madextra-citations'); ?></h3>
                        <?php if (!empty($profile['services_summary'])) : ?><p><?php echo nl2br(esc_html($profile['services_summary'])); ?></p><?php endif; ?>
                        <?php if ($service_cards) : ?>
                            <div class="mec-public-service-cards">
                                <?php foreach ($service_cards as $service_card) : ?>
                                    <article class="mec-public-mini-card">
                                        <h4><?php echo esc_html($service_card[0]); ?></h4>
                                        <?php if (!empty($service_card[1])) : ?><p><?php echo esc_html($service_card[1]); ?></p><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($is_premium && ($service_areas || $social_links)) : ?>
                    <section class="mec-public-grid">
                        <?php if ($service_areas) : ?>
                            <article class="mec-public-card">
                                <h3><?php esc_html_e('Service Areas', 'madextra-citations'); ?></h3>
                                <ul class="mec-public-list">
                                    <?php foreach ($service_areas as $service_area) : ?>
                                        <li><?php echo esc_html($service_area); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </article>
                        <?php endif; ?>
                        <?php if ($social_links) : ?>
                            <article class="mec-public-card">
                                <h3><?php esc_html_e('Connect', 'madextra-citations'); ?></h3>
                                <ul class="mec-public-list">
                                    <?php foreach ($social_links as $social_link) : ?>
                                        <?php if (empty($social_link[1])) { continue; } ?>
                                        <li><a href="<?php echo esc_url($social_link[1]); ?>" target="_blank" rel="noopener"><?php echo esc_html($social_link[0] ? $social_link[0] : $social_link[1]); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </article>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($is_premium && $gallery_ids) : ?>
                    <section class="mec-public-card">
                        <h3><?php esc_html_e('Gallery', 'madextra-citations'); ?></h3>
                        <div class="mec-public-gallery">
                            <?php foreach ($gallery_ids as $gallery_id) : ?>
                                <?php $gallery_url = wp_get_attachment_image_url($gallery_id, 'large'); ?>
                                <?php if (!$gallery_url) { continue; } ?>
                                <img src="<?php echo esc_url($gallery_url); ?>" alt="<?php echo esc_attr($primary_name); ?>">
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($is_premium && $faq_items) : ?>
                    <section class="mec-public-card">
                        <h3><?php esc_html_e('Frequently Asked Questions', 'madextra-citations'); ?></h3>
                        <div class="mec-public-faq">
                            <?php foreach ($faq_items as $faq_item) : ?>
                                <details>
                                    <summary><?php echo esc_html($faq_item[0]); ?></summary>
                                    <?php if (!empty($faq_item[1])) : ?><p><?php echo esc_html($faq_item[1]); ?></p><?php endif; ?>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($cta_enabled) : ?>
                    <section class="mec-paywall-card">
                        <div>
                            <h3><?php esc_html_e('Claim Or Upgrade This Profile', 'madextra-citations'); ?></h3>
                            <p><?php esc_html_e('This self-service profile is gated behind Stripe checkout.', 'madextra-citations'); ?></p>
                            <?php if (!empty($profile['self_serve_price_text'])) : ?><p class="mec-paywall-price"><?php echo esc_html($profile['self_serve_price_text']); ?></p><?php endif; ?>
                        </div>
                        <a class="mec-public-button" href="<?php echo esc_url($payment_url); ?>" target="_blank" rel="noopener sponsored"><?php echo esc_html($cta_label); ?></a>
                    </section>
                <?php endif; ?>
            </div>
            <style>
                .mec-public-profile { display:grid; gap:18px; }
                .mec-public-hero, .mec-public-card, .mec-paywall-card { background:#fff; border:1px solid #d8e1f0; border-radius:14px; padding:18px; }
                .mec-public-hero { display:flex; gap:16px; justify-content:space-between; align-items:flex-start; }
                .mec-public-brand { display:flex; gap:14px; align-items:center; }
                .mec-public-brand img, .mec-public-logo-fallback { width:78px; height:78px; border-radius:16px; object-fit:cover; flex:0 0 auto; }
                .mec-public-logo-fallback { display:grid; place-items:center; background:#e8eefb; color:#27426f; font-size:28px; font-weight:700; }
                .mec-public-brand h2 { margin:0 0 6px; }
                .mec-public-badge { margin:0 0 8px; display:inline-flex; padding:5px 10px; border-radius:999px; background:#edf3ff; color:#15346e; font-size:.78rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
                .mec-public-subheadline { margin:0 0 8px; color:#3e526f; }
                .mec-public-meta { display:flex; gap:10px; flex-wrap:wrap; color:#556987; }
                .mec-public-actions { display:flex; gap:10px; flex-wrap:wrap; }
                .mec-public-button { display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:999px; background:#1b4dd8; color:#fff; font-weight:700; text-decoration:none; }
                .mec-public-button-secondary { background:#eef4ff; color:#1b4dd8; }
                .mec-public-grid { display:grid; gap:16px; grid-template-columns:repeat(2, minmax(0, 1fr)); }
                .mec-public-card h3, .mec-paywall-card h3 { margin-top:0; }
                .mec-public-service-cards, .mec-public-gallery { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); }
                .mec-public-mini-card { border:1px solid #d8e1f0; border-radius:12px; padding:14px; background:#fafcff; }
                .mec-public-mini-card h4 { margin:0 0 8px; }
                .mec-public-list { margin:0; padding-left:18px; }
                .mec-public-gallery img { width:100%; height:220px; object-fit:cover; border-radius:12px; }
                .mec-public-faq details { border-top:1px solid #e1e7f0; padding:12px 0; }
                .mec-public-faq details:first-child { border-top:none; padding-top:0; }
                .mec-public-faq summary { cursor:pointer; font-weight:700; }
                .mec-paywall-card { display:flex; gap:16px; align-items:center; justify-content:space-between; background:linear-gradient(135deg,#f7fbff,#edf3ff); }
                .mec-paywall-price { font-size:1.1rem; font-weight:700; color:#15346e; }
                @media (max-width: 900px) {
                    .mec-public-hero, .mec-paywall-card { flex-direction:column; align-items:flex-start; }
                    .mec-public-grid { grid-template-columns:1fr; }
                }
            </style>
            <?php
            return ob_get_clean();
        }

        private static function profile_to_public_data($post_id)
        {
            $markets = wp_get_object_terms($post_id, self::TAX_MARKET, array('fields' => 'names'));
            $services = wp_get_object_terms($post_id, self::TAX_SERVICE, array('fields' => 'names'));
            $logo_id = (int) get_post_meta($post_id, self::META_PREFIX . 'business_logo_id', true);
            $public_page_id = (int) get_post_meta($post_id, self::META_PREFIX . 'public_profile_page_id', true);
            $profile = array(
                'id'                   => (int) $post_id,
                'title'                => get_the_title($post_id),
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
                'self_serve_enabled'   => get_post_meta($post_id, self::META_PREFIX . 'self_serve_enabled', true),
                'self_serve_cta_label' => get_post_meta($post_id, self::META_PREFIX . 'self_serve_cta_label', true),
                'self_serve_cta_url'   => get_post_meta($post_id, self::META_PREFIX . 'self_serve_cta_url', true),
                'self_serve_price_text' => get_post_meta($post_id, self::META_PREFIX . 'self_serve_price_text', true),
                'public_profile_page_id' => (string) $public_page_id,
                'public_profile_page_url' => $public_page_id ? get_permalink($public_page_id) : '',
                'is_premium'           => get_post_meta($post_id, self::META_PREFIX . 'is_premium', true),
                'service_areas'        => get_post_meta($post_id, self::META_PREFIX . 'service_areas', true),
                'faq_items'            => get_post_meta($post_id, self::META_PREFIX . 'faq_items', true),
                'social_links'         => get_post_meta($post_id, self::META_PREFIX . 'social_links', true),
                'gallery_media_ids'    => get_post_meta($post_id, self::META_PREFIX . 'gallery_media_ids', true),
                'primary_cta_label'    => get_post_meta($post_id, self::META_PREFIX . 'primary_cta_label', true),
                'primary_cta_url'      => get_post_meta($post_id, self::META_PREFIX . 'primary_cta_url', true),
                'secondary_cta_label'  => get_post_meta($post_id, self::META_PREFIX . 'secondary_cta_label', true),
                'secondary_cta_url'    => get_post_meta($post_id, self::META_PREFIX . 'secondary_cta_url', true),
                'premium_hero_text'    => get_post_meta($post_id, self::META_PREFIX . 'premium_hero_text', true),
                'premium_subheadline'  => get_post_meta($post_id, self::META_PREFIX . 'premium_subheadline', true),
                'extended_about_copy'  => get_post_meta($post_id, self::META_PREFIX . 'extended_about_copy', true),
                'services_summary'     => get_post_meta($post_id, self::META_PREFIX . 'services_summary', true),
                'service_cards'        => get_post_meta($post_id, self::META_PREFIX . 'service_cards', true),
                'premium_badge_text'   => get_post_meta($post_id, self::META_PREFIX . 'premium_badge_text', true),
                'premium_page_mode'    => get_post_meta($post_id, self::META_PREFIX . 'premium_page_mode', true),
                'premium_page_status'  => get_post_meta($post_id, self::META_PREFIX . 'premium_page_status', true),
                'premium_last_generated_at' => get_post_meta($post_id, self::META_PREFIX . 'premium_last_generated_at', true),
                'premium_layout_template_key' => get_post_meta($post_id, self::META_PREFIX . 'premium_layout_template_key', true),
                'premium_manual_override' => get_post_meta($post_id, self::META_PREFIX . 'premium_manual_override', true),
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
                        isset($profile['nap_business_name']) ? $profile['nap_business_name'] : '',
                        $services,
                        $status_label,
                        isset($profile['public_notes']) ? $profile['public_notes'] : '',
                        isset($profile['business_description']) ? $profile['business_description'] : '',
                        isset($profile['business_hours']) ? $profile['business_hours'] : '',
                        isset($profile['business_website_url']) ? $profile['business_website_url'] : '',
                        isset($profile['business_email']) ? $profile['business_email'] : '',
                        !empty($profile['markets']) ? implode(', ', (array) $profile['markets']) : '',
                        isset($profile['display_address']) ? $profile['display_address'] : '',
                        isset($profile['nap_address']) ? $profile['nap_address'] : '',
                        isset($profile['nap_phone']) ? $profile['nap_phone'] : '',
                    )
                )
            );
        }

        private static function normalize_directory_group_by($group_by)
        {
            $group_by = sanitize_key((string) $group_by);
            if (!in_array($group_by, array('none', 'market', 'service', 'alpha'), true)) {
                $group_by = 'none';
            }
            return $group_by;
        }

        private static function profile_market_display(array $profile)
        {
            return !empty($profile['markets']) ? implode(', ', (array) $profile['markets']) : __('Unassigned Market', 'madextra-citations');
        }

        private static function sort_directory_profiles(array &$profiles)
        {
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

                    return strcasecmp(
                        (string) (!empty($a['nap_business_name']) ? $a['nap_business_name'] : $a['title']),
                        (string) (!empty($b['nap_business_name']) ? $b['nap_business_name'] : $b['title'])
                    );
                }
            );
        }

        private static function directory_group_labels(array $profile, $group_by)
        {
            switch ($group_by) {
                case 'market':
                    return !empty($profile['markets']) ? array_values((array) $profile['markets']) : array(__('Unassigned Market', 'madextra-citations'));
                case 'service':
                    return !empty($profile['services']) ? array_values((array) $profile['services']) : array(__('Unassigned Service', 'madextra-citations'));
                case 'alpha':
                    $name = !empty($profile['nap_business_name']) ? (string) $profile['nap_business_name'] : (string) $profile['title'];
                    $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
                    $first = strtoupper((string) $first);
                    if (!preg_match('/[A-Z]/', $first)) {
                        $first = '#';
                    }
                    return array($first);
                case 'none':
                default:
                    return array('all');
            }
        }

        private static function build_directory_groups(array $profiles, $group_by)
        {
            $groups = array();
            foreach ($profiles as $profile) {
                foreach (self::directory_group_labels($profile, $group_by) as $group_label) {
                    $group_key = sanitize_title($group_label);
                    if ('none' === $group_by) {
                        $group_key = 'all';
                        $group_label = __('All Profiles', 'madextra-citations');
                    }
                    if (!isset($groups[$group_key])) {
                        $groups[$group_key] = array(
                            'label' => $group_label,
                            'profiles' => array(),
                        );
                    }
                    $groups[$group_key]['profiles'][] = $profile;
                }
            }

            uasort(
                $groups,
                static function ($a, $b) {
                    return strcasecmp((string) $a['label'], (string) $b['label']);
                }
            );

            return $groups;
        }

        public static function render_directory_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'show_filters' => 'yes',
                    'limit'        => 5000,
                    'per_page'     => '',
                    'per_city'     => 25,
                    'group_by'     => 'none',
                    'show_market_filter' => 'no',
                ),
                $atts,
                self::SHORTCODE
            );

            $group_by = self::normalize_directory_group_by($atts['group_by']);
            $per_page = '' !== (string) $atts['per_page'] ? (int) $atts['per_page'] : (int) $atts['per_city'];
            $per_page = min(100, max(1, $per_page));
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

            $profiles = array();
            $all_services = array();
            $all_statuses = array();
            $all_markets = array();
            $status_options = self::status_options();

            foreach ($query->posts as $post) {
                $post_id = $post->ID;
                $profile = self::profile_to_public_data($post_id);
                $profiles[] = $profile;

                foreach ($profile['services'] as $service) {
                    $all_services[$service] = $service;
                }
                foreach ($profile['markets'] as $market_name) {
                    $all_markets[$market_name] = $market_name;
                }

                if (!empty($profile['status'])) {
                    $all_statuses[$profile['status']] = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                }
            }

            self::sort_directory_profiles($profiles);
            $groups = self::build_directory_groups($profiles, $group_by);
            ksort($all_services);
            ksort($all_statuses);
            ksort($all_markets);

            $instance_id = 'mec-dir-' . wp_rand(1000, 99999);
            $show_group_headings = 'none' !== $group_by;
            $show_market_column = 'market' !== $group_by;
            $show_market_filter = 'yes' === strtolower((string) $atts['show_market_filter']) || '1' === (string) $atts['show_market_filter'];

            ob_start();
            ?>
            <div id="<?php echo esc_attr($instance_id); ?>" class="mec-directory-wrap">
                <?php if ('yes' === $atts['show_filters']) : ?>
                    <div class="mec-directory-controls">
                        <input type="search" class="mec-search" placeholder="<?php esc_attr_e('Search business name, directory, city, notes, or NAP...', 'madextra-citations'); ?>">
                        <select class="mec-service-filter">
                            <option value=""><?php esc_html_e('All Services', 'madextra-citations'); ?></option>
                            <?php foreach ($all_services as $service) : ?>
                                <option value="<?php echo esc_attr(strtolower($service)); ?>"><?php echo esc_html($service); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($show_market_filter) : ?>
                            <select class="mec-market-filter">
                                <option value=""><?php esc_html_e('All Cities', 'madextra-citations'); ?></option>
                                <?php foreach ($all_markets as $market_name) : ?>
                                    <option value="<?php echo esc_attr(strtolower($market_name)); ?>"><?php echo esc_html($market_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <select class="mec-status-filter">
                            <option value=""><?php esc_html_e('All Statuses', 'madextra-citations'); ?></option>
                            <?php foreach ($all_statuses as $status_key => $status_label) : ?>
                                <option value="<?php echo esc_attr(strtolower($status_key)); ?>"><?php echo esc_html($status_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php foreach ($groups as $group_key => $group) : ?>
                    <?php
                    $group_profiles = isset($group['profiles']) ? $group['profiles'] : array();
                    $group_label = isset($group['label']) ? $group['label'] : __('All Profiles', 'madextra-citations');
                    $featured_profiles = array();
                    $featured_ids = array();
                    foreach ($group_profiles as $profile) {
                        if (!empty($profile['is_featured']) && '1' === (string) $profile['is_featured']) {
                            if ('market' === $group_by && count($featured_profiles) >= 3) {
                                continue;
                            }
                            $featured_profiles[] = $profile;
                            $featured_ids[(int) $profile['id']] = true;
                        }
                    }

                    $regular_profiles = array();
                    foreach ($group_profiles as $profile) {
                        if (!isset($featured_ids[(int) $profile['id']])) {
                            $regular_profiles[] = $profile;
                        }
                    }
                    $total_pages = max(1, (int) ceil(count($regular_profiles) / $per_page));
                    ?>
                    <section class="mec-directory-group <?php echo $show_group_headings ? 'mec-directory-group-headed' : 'mec-directory-group-flat'; ?>" data-group="<?php echo esc_attr($group_key); ?>">
                        <div class="mec-market-heading" <?php if (!$show_group_headings) : ?>style="display:none;"<?php endif; ?>>
                            <h3><?php echo esc_html($group_label); ?></h3>
                            <span><?php echo esc_html(sprintf(_n('%d profile', '%d profiles', count($group_profiles), 'madextra-citations'), count($group_profiles))); ?></span>
                        </div>
                        <?php if (!$show_group_headings) : ?>
                            <div class="mec-directory-summary">
                                <strong><?php esc_html_e('All Profiles', 'madextra-citations'); ?></strong>
                                <span><?php echo esc_html(sprintf(_n('%d profile', '%d profiles', count($group_profiles), 'madextra-citations'), count($group_profiles))); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($featured_profiles) : ?>
                            <div class="mec-featured-grid">
                                <?php foreach ($featured_profiles as $profile) : ?>
                                    <?php
                                    $services = $profile['services'] ? implode(', ', $profile['services']) : '-';
                                    $markets_display = self::profile_market_display($profile);
                                    $status_key = strtolower((string) $profile['status']);
                                    $status_label = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                                    $search_blob = self::profile_search_blob($profile, $services, $status_label);
                                    ?>
                                    <article
                                        class="mec-featured-card"
                                        data-mec-item="1"
                                        data-featured="1"
                                        data-service="<?php echo esc_attr(strtolower($services)); ?>"
                                        data-market="<?php echo esc_attr(strtolower($markets_display)); ?>"
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
                                                <strong><?php echo esc_html($profile['nap_business_name'] ? $profile['nap_business_name'] : $profile['title']); ?></strong>
                                                <span><?php echo esc_html($services); ?></span>
                                            </div>
                                        </div>
                                        <div class="mec-badge-row">
                                            <span class="mec-city-badge"><?php echo esc_html($markets_display); ?></span>
                                            <?php if (!empty($status_label)) : ?><span class="mec-status-badge"><?php echo esc_html($status_label); ?></span><?php endif; ?>
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
                                            <?php if (!empty($profile['public_profile_page_url'])) : ?><a href="<?php echo esc_url($profile['public_profile_page_url']); ?>"><?php esc_html_e('Profile', 'madextra-citations'); ?></a><?php endif; ?>
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
                                    <th><?php esc_html_e('Service', 'madextra-citations'); ?></th>
                                    <?php if ($show_market_column) : ?><th><?php esc_html_e('City', 'madextra-citations'); ?></th><?php endif; ?>
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
                                    $markets_display = self::profile_market_display($profile);
                                    $status_key = strtolower((string) $profile['status']);
                                    $status_label = isset($status_options[$profile['status']]) ? $status_options[$profile['status']] : $profile['status'];
                                    $search_blob = self::profile_search_blob($profile, $services, $status_label);
                                    $page_number = (int) floor($index / $per_page) + 1;
                                    ?>
                                    <tr
                                        data-mec-item="1"
                                        data-regular="1"
                                        data-page="<?php echo esc_attr((string) $page_number); ?>"
                                        data-service="<?php echo esc_attr(strtolower($services)); ?>"
                                        data-market="<?php echo esc_attr(strtolower($markets_display)); ?>"
                                        data-status="<?php echo esc_attr($status_key); ?>"
                                        data-search="<?php echo esc_attr($search_blob); ?>"
                                    >
                                        <td>
                                            <div class="mec-business-cell">
                                                <?php if (!empty($profile['business_logo_url'])) : ?>
                                                    <img src="<?php echo esc_url($profile['business_logo_url']); ?>" alt="<?php echo esc_attr($profile['nap_business_name']); ?>">
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?php echo esc_html($profile['nap_business_name'] ? $profile['nap_business_name'] : $profile['title']); ?></strong>
                                                    <?php if (!empty($profile['display_address'])) : ?><span><?php echo nl2br(esc_html($profile['display_address'])); ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html($services); ?></td>
                                        <?php if ($show_market_column) : ?><td><?php echo esc_html($markets_display); ?></td><?php endif; ?>
                                        <td><?php echo esc_html($status_label ? $status_label : '-'); ?></td>
                                        <td><?php echo esc_html($profile['last_verified_date'] ? $profile['last_verified_date'] : '-'); ?></td>
                                        <td>
                                            <?php if (!empty($profile['business_website_url'])) : ?>
                                                <a href="<?php echo esc_url($profile['business_website_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Website', 'madextra-citations'); ?></a>
                                            <?php endif; ?>
                                            <?php if (!empty($profile['public_profile_page_url'])) : ?>
                                                <a href="<?php echo esc_url($profile['public_profile_page_url']); ?>"><?php esc_html_e('Profile', 'madextra-citations'); ?></a>
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
                        <?php if (count($regular_profiles) > $per_page) : ?>
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
                .mec-directory-controls { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
                .mec-directory-controls input, .mec-directory-controls select { width: 100%; padding: 10px; border: 1px solid #cfd7ea; border-radius: 8px; font: inherit; }
                .mec-directory-group { border: 1px solid #dbe2f3; border-radius: 10px; overflow: hidden; background: #fff; }
                .mec-directory-summary { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0; padding: 12px 14px; background:#f8fbff; border-bottom:1px solid #edf1fb; }
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
                .mec-badge-row { display:flex; gap:8px; flex-wrap:wrap; }
                .mec-city-badge, .mec-status-badge { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:.78rem; font-weight:700; }
                .mec-city-badge { background:#eaf2ff; color:#2450a6; }
                .mec-status-badge { background:#eef8f1; color:#207449; }
                .mec-contact-lines { display:grid; gap:4px; color:#42536f; font-size:.9rem; }
                .mec-link-row { display:flex; gap:8px; flex-wrap:wrap; }
                .mec-link-row a, .mec-table a { font-weight:700; }
                .mec-table-wrap { overflow-x: auto; }
                .mec-table { width: 100%; border-collapse: collapse; min-width: 1120px; }
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
                    .mec-directory-summary { align-items:flex-start; flex-direction:column; }
                }
            </style>

            <script>
                (function() {
                    const root = document.getElementById(<?php echo wp_json_encode($instance_id); ?>);
                    if (!root) return;

                    const searchInput = root.querySelector('.mec-search');
                    const serviceFilter = root.querySelector('.mec-service-filter');
                    const marketFilter = root.querySelector('.mec-market-filter');
                    const statusFilter = root.querySelector('.mec-status-filter');
                    const groups = Array.from(root.querySelectorAll('.mec-directory-group'));

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
                        const market = marketFilter ? marketFilter.value.toLowerCase() : '';
                        const status = statusFilter ? statusFilter.value.toLowerCase() : '';
                        const filtering = !!(q || service || market || status);

                        groups.forEach((group) => {
                            const items = Array.from(group.querySelectorAll('[data-mec-item]'));
                            const currentPage = parseInt(group.dataset.currentPage || '1', 10);
                            const pagination = group.querySelector('.mec-group-pagination');
                            const tableWrap = group.querySelector('[data-regular-table]');
                            let visibleCount = 0;
                            let visibleRegularCount = 0;

                            items.forEach((item) => {
                                const rowService = (item.getAttribute('data-service') || '').toLowerCase();
                                const rowMarket = (item.getAttribute('data-market') || '').toLowerCase();
                                const rowStatus = (item.getAttribute('data-status') || '').toLowerCase();
                                const rowSearch = (item.getAttribute('data-search') || '').toLowerCase();
                                const itemPage = parseInt(item.getAttribute('data-page') || '1', 10);
                                const isRegular = item.getAttribute('data-regular') === '1';

                                const matchSearch = !q || rowSearch.indexOf(q) !== -1;
                                const matchService = !service || rowService.indexOf(service) !== -1;
                                const matchMarket = !market || rowMarket.indexOf(market) !== -1;
                                const matchStatus = !status || rowStatus === status;

                                let show = matchSearch && matchService && matchMarket && matchStatus;
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
                    if (marketFilter) marketFilter.addEventListener('change', applyFilters);
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
