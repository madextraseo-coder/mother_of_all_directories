<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MadExtra_Citations_Builder')) {
    final class MadExtra_Citations_Builder
    {
        const STORE_OPTION = 'mec_builder_store_v1';
        const CAPS_OPTION = 'mec_builder_caps_version';
        const CAPS_VERSION = '1.2.0';

        const NONCE_BUILDER = 'mec_builder_nonce';
        const NONCE_DASHBOARD = 'mec_dashboard_nonce';
        const NONCE_DYNAMIC_META = 'mec_dynamic_meta_nonce';
        const ADMIN_PAGE_SLUG = 'mec-builder';
        const REST_NS = 'madextra-citations/v1';

        const META_DYNAMIC_PREFIX = '_mec_dyn_';
        const META_RELATION_PREFIX = '_mec_rel_';

        public static function bootstrap()
        {
            add_action('admin_init', array(__CLASS__, 'maybe_sync_capabilities'));
            add_action('init', array(__CLASS__, 'maybe_seed_defaults'), 25);
            add_action('admin_menu', array(__CLASS__, 'register_builder_page'));
            add_action('admin_post_mec_builder_save', array(__CLASS__, 'handle_builder_save'));
            add_action('admin_post_mec_builder_delete', array(__CLASS__, 'handle_builder_delete'));
            add_action('admin_post_mec_builder_generate_pages', array(__CLASS__, 'handle_builder_generate_pages'));

            add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));

            add_action('add_meta_boxes', array(__CLASS__, 'register_dynamic_meta_boxes'));
            add_action('save_post_' . MadExtra_Citations_Plugin::CPT, array(__CLASS__, 'save_dynamic_profile_meta'), 30);

            add_action(MadExtra_Citations_Plugin::TAX_MARKET . '_add_form_fields', array(__CLASS__, 'render_market_term_add_fields'));
            add_action(MadExtra_Citations_Plugin::TAX_MARKET . '_edit_form_fields', array(__CLASS__, 'render_market_term_edit_fields'));
            add_action('created_' . MadExtra_Citations_Plugin::TAX_MARKET, array(__CLASS__, 'save_market_term_fields'));
            add_action('edited_' . MadExtra_Citations_Plugin::TAX_MARKET, array(__CLASS__, 'save_market_term_fields'));

            add_action(MadExtra_Citations_Plugin::TAX_SERVICE . '_add_form_fields', array(__CLASS__, 'render_service_term_add_fields'));
            add_action(MadExtra_Citations_Plugin::TAX_SERVICE . '_edit_form_fields', array(__CLASS__, 'render_service_term_edit_fields'));
            add_action('created_' . MadExtra_Citations_Plugin::TAX_SERVICE, array(__CLASS__, 'save_service_term_fields'));
            add_action('edited_' . MadExtra_Citations_Plugin::TAX_SERVICE, array(__CLASS__, 'save_service_term_fields'));

            add_action('show_user_profile', array(__CLASS__, 'render_user_fields'));
            add_action('edit_user_profile', array(__CLASS__, 'render_user_fields'));
            add_action('personal_options_update', array(__CLASS__, 'save_user_fields'));
            add_action('edit_user_profile_update', array(__CLASS__, 'save_user_fields'));

            add_action('admin_post_mec_profile_save', array(__CLASS__, 'handle_dashboard_profile_save'));
            add_action('admin_post_mec_profile_delete', array(__CLASS__, 'handle_dashboard_profile_delete'));

            add_shortcode('mec_listing', array(__CLASS__, 'render_listing_shortcode'));
            add_shortcode('mec_filters', array(__CLASS__, 'render_filters_shortcode'));
            add_shortcode('mec_profile_dashboard', array(__CLASS__, 'render_profile_dashboard_shortcode'));

            add_action('elementor/widgets/register', array(__CLASS__, 'register_elementor_widgets'));
        }

        public static function activate()
        {
            try {
                self::maybe_sync_capabilities();
                self::maybe_seed_defaults();
            } catch (Throwable $e) {
                error_log('[MadExtra Citations] Builder activate error: ' . $e->getMessage());
            }
        }

        public static function maybe_sync_capabilities()
        {
            try {
                $version = get_option(self::CAPS_OPTION, '');
                if (self::CAPS_VERSION === (string) $version) {
                    return;
                }

                $manager_caps = array(
                    'manage_citation_builder',
                    'manage_citation_templates',
                    'manage_citation_queries',
                    'manage_citation_forms',
                    'manage_citation_pages',
                    'submit_citation_profiles',
                );

                $admin_caps = array_merge(
                    $manager_caps,
                    array(
                        'manage_citation_relations',
                    )
                );

                $roles = array(
                    'citation_manager' => $manager_caps,
                    'citation_admin' => $admin_caps,
                    'administrator' => $admin_caps,
                );

                foreach ($roles as $role_slug => $caps) {
                    $role = get_role($role_slug);
                    if (!$role) {
                        continue;
                    }
                    foreach ($caps as $cap) {
                        $role->add_cap($cap);
                    }
                }

                // Also grant builder caps to any custom admin-like role that has
                // manage_options, so users do not need both admin + citation roles.
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

                        foreach ($admin_caps as $cap) {
                            $role->add_cap($cap);
                        }
                    }
                }

                update_option(self::CAPS_OPTION, self::CAPS_VERSION, false);
            } catch (Throwable $e) {
                error_log('[MadExtra Citations] Builder cap sync error: ' . $e->getMessage());
            }
        }

        public static function maybe_seed_defaults()
        {
            $store = self::get_store();
            $changed = false;
            if (empty($store['templates'])) {
                $store['templates']['default-table'] = array(
                    'id' => 'default-table',
                    'label' => 'Default Table Template',
                    'description' => 'Default table rendering for citation profiles.',
                    'style' => 'table',
                    'columns' => array('nap_business_name', 'services', 'business_website_url', 'nap_phone', 'display_address', 'public_notes'),
                    'show_filters' => '1',
                    'visibility_rules' => '',
                    'updated_at' => current_time('mysql'),
                );
                $changed = true;
            }

            if (empty($store['queries'])) {
                $store['queries']['all-profiles'] = array(
                    'id' => 'all-profiles',
                    'label' => 'All Published Profiles',
                    'description' => 'Default query for public listing pages.',
                    'status_values' => array('live', 'pending', 'in_progress', 'needs_fix', 'suspended'),
                    'market_values' => array(),
                    'service_values' => array(),
                    'featured_only' => '0',
                    'search_default' => '',
                    'date_from' => '',
                    'date_to' => '',
                    'meta_key' => '',
                    'meta_compare' => '=',
                    'meta_value' => '',
                    'relation_key' => '',
                    'relation_targets' => array(),
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'per_page' => 25,
                    'updated_at' => current_time('mysql'),
                );
                $changed = true;
            }

            if (empty($store['forms'])) {
                $store['forms']['default-dashboard'] = array(
                    'id' => 'default-dashboard',
                    'label' => 'Default Dashboard Form',
                    'description' => 'Frontend create/edit dashboard form.',
                    'allowed_fields' => array(
                        'directory_name',
                        'listing_url',
                        'status',
                        'last_verified_date',
                        'public_notes',
                        'nap_business_name',
                        'nap_address',
                        'nap_phone',
                        'business_website_url',
                        'business_email',
                        'business_description',
                        'business_hours',
                        'address_street',
                        'address_city',
                        'address_state',
                        'address_zip',
                        'is_featured',
                        'featured_order',
                    ),
                    'allow_create' => '1',
                    'allow_edit' => '1',
                    'visibility_rules' => '',
                    'updated_at' => current_time('mysql'),
                );
                $changed = true;
            }

            if (empty($store['pages'])) {
                $store['pages']['all-citations'] = array(
                    'id' => 'all-citations',
                    'label' => 'All Directory Listings',
                    'description' => 'Flat searchable directory page without market-first grouping.',
                    'page_title' => 'All Directory Listings',
                    'page_slug' => 'all-citations',
                    'page_status' => 'publish',
                    'parent_page_id' => 0,
                    'template_id' => 'default-table',
                    'query_id' => 'all-profiles',
                    'intro_text' => 'Search all citation profiles from one page.',
                    'generated_page_id' => 0,
                    'updated_at' => current_time('mysql'),
                );
                $changed = true;
            }

            if ($changed) {
                update_option(self::STORE_OPTION, $store, false);
            }

            if (!empty($store['templates']['default-table']['columns']) && is_array($store['templates']['default-table']['columns'])) {
                $legacy_columns = array('nap_business_name', 'services', 'business_website_url', 'nap_phone', 'display_address', 'listing_url');
                if ($store['templates']['default-table']['columns'] === $legacy_columns) {
                    $store['templates']['default-table']['columns'] = array('nap_business_name', 'services', 'business_website_url', 'nap_phone', 'display_address', 'public_notes');
                    $store['templates']['default-table']['updated_at'] = current_time('mysql');
                    update_option(self::STORE_OPTION, $store, false);
                }
            }
        }

        private static function empty_store()
        {
            return array(
                'field_groups' => array(),
                'templates' => array(),
                'queries' => array(),
                'forms' => array(),
                'pages' => array(),
                'relations' => array(),
            );
        }

        private static function get_store()
        {
            $store = get_option(self::STORE_OPTION, array());
            if (!is_array($store)) {
                $store = array();
            }
            return wp_parse_args($store, self::empty_store());
        }

        private static function update_store(array $store)
        {
            update_option(self::STORE_OPTION, wp_parse_args($store, self::empty_store()), false);
        }

        private static function entity_map()
        {
            return array(
                'field_groups' => array(
                    'label' => __('Field Groups', 'madextra-citations'),
                    'cap' => 'manage_citation_builder',
                ),
                'templates' => array(
                    'label' => __('Listing Templates', 'madextra-citations'),
                    'cap' => 'manage_citation_templates',
                ),
                'queries' => array(
                    'label' => __('Query Presets', 'madextra-citations'),
                    'cap' => 'manage_citation_queries',
                ),
                'forms' => array(
                    'label' => __('Form Presets', 'madextra-citations'),
                    'cap' => 'manage_citation_forms',
                ),
                'pages' => array(
                    'label' => __('Directory Pages', 'madextra-citations'),
                    'cap' => 'manage_citation_pages',
                ),
                'relations' => array(
                    'label' => __('Relations', 'madextra-citations'),
                    'cap' => 'manage_citation_relations',
                ),
            );
        }

        private static function get_entity_type($candidate)
        {
            $candidate = sanitize_key((string) $candidate);
            $map = self::entity_map();
            return isset($map[$candidate]) ? $candidate : 'field_groups';
        }

        private static function get_entities($type)
        {
            $store = self::get_store();
            return isset($store[$type]) && is_array($store[$type]) ? $store[$type] : array();
        }

        private static function get_entity($type, $id)
        {
            $entities = self::get_entities($type);
            return isset($entities[$id]) ? $entities[$id] : null;
        }

        private static function save_entity($type, array $entity)
        {
            $store = self::get_store();
            if (!isset($store[$type]) || !is_array($store[$type])) {
                $store[$type] = array();
            }
            $store[$type][$entity['id']] = $entity;
            self::update_store($store);
        }

        private static function delete_entity($type, $id)
        {
            $store = self::get_store();
            if (isset($store[$type][$id])) {
                unset($store[$type][$id]);
                self::update_store($store);
            }
        }

        private static function ensure_list_values($raw)
        {
            if (is_array($raw)) {
                $parts = array();
                foreach ($raw as $item) {
                    if (is_scalar($item)) {
                        $value = trim((string) $item);
                        if ('' !== $value) {
                            $parts[] = $value;
                        }
                    }
                }
                return array_values(array_unique($parts));
            }

            $parts = preg_split('/[\r\n,|]+/', (string) $raw);
            $parts = array_map('trim', is_array($parts) ? $parts : array());
            $parts = array_filter($parts, static function ($value) {
                return '' !== $value;
            });
            return array_values(array_unique($parts));
        }

        private static function csv_for_list(array $values)
        {
            return implode(', ', array_values(array_filter(array_map('trim', $values))));
        }

        private static function sanitize_date($value)
        {
            $value = sanitize_text_field((string) $value);
            if ('' === $value) {
                return '';
            }
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        }

        private static function sanitize_visibility_json($value)
        {
            $value = trim((string) $value);
            if ('' === $value) {
                return '';
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? wp_json_encode($decoded) : '';
        }

        private static function sanitize_entity($type, array $payload)
        {
            $id = isset($payload['id']) ? sanitize_key($payload['id']) : '';
            $label = isset($payload['label']) ? sanitize_text_field($payload['label']) : '';
            $description = isset($payload['description']) ? sanitize_textarea_field($payload['description']) : '';

            if ('' === $id) {
                $id = sanitize_key($label);
            }
            if ('' === $id) {
                $id = strtolower(wp_generate_password(10, false, false));
            }

            $entity = array(
                'id' => $id,
                'label' => $label ? $label : ucfirst(str_replace('-', ' ', $id)),
                'description' => $description,
                'updated_at' => current_time('mysql'),
            );

            if ('field_groups' === $type) {
                $field_type = isset($payload['field_type']) ? sanitize_key($payload['field_type']) : 'text';
                $allowed_field_types = array('text', 'url', 'textarea', 'select', 'date', 'number', 'checkbox');
                if (!in_array($field_type, $allowed_field_types, true)) {
                    $field_type = 'text';
                }
                $target = isset($payload['target']) ? sanitize_key($payload['target']) : MadExtra_Citations_Plugin::CPT;
                $allowed_targets = array(
                    MadExtra_Citations_Plugin::CPT,
                    MadExtra_Citations_Plugin::TAX_MARKET,
                    MadExtra_Citations_Plugin::TAX_SERVICE,
                    'user',
                );
                if (!in_array($target, $allowed_targets, true)) {
                    $target = MadExtra_Citations_Plugin::CPT;
                }
                $field_key = isset($payload['field_key']) ? sanitize_key($payload['field_key']) : '';
                if ('' === $field_key) {
                    $field_key = $id;
                }
                $entity['target'] = $target;
                $entity['field_key'] = $field_key;
                $entity['field_label'] = isset($payload['field_label']) ? sanitize_text_field($payload['field_label']) : $entity['label'];
                $entity['field_type'] = $field_type;
                $entity['required'] = !empty($payload['required']) ? '1' : '0';
                $entity['options'] = self::ensure_list_values(isset($payload['options']) ? $payload['options'] : '');
                $entity['help_text'] = isset($payload['help_text']) ? sanitize_text_field($payload['help_text']) : '';
                $entity['visibility_rules'] = self::sanitize_visibility_json(isset($payload['visibility_rules']) ? $payload['visibility_rules'] : '');
                return $entity;
            }

            if ('templates' === $type) {
                $style = isset($payload['style']) ? sanitize_key($payload['style']) : 'table';
                if (!in_array($style, array('table', 'cards'), true)) {
                    $style = 'table';
                }
                $columns = self::ensure_list_values(isset($payload['columns']) ? $payload['columns'] : '');
                if (!$columns) {
                    $columns = array('nap_business_name', 'services', 'status', 'last_verified_date', 'business_website_url', 'public_notes');
                }
                $entity['style'] = $style;
                $entity['columns'] = array_map('sanitize_key', $columns);
                $entity['show_filters'] = !empty($payload['show_filters']) ? '1' : '0';
                $entity['visibility_rules'] = self::sanitize_visibility_json(isset($payload['visibility_rules']) ? $payload['visibility_rules'] : '');
                return $entity;
            }

            if ('queries' === $type) {
                $orderby = isset($payload['orderby']) ? sanitize_key($payload['orderby']) : 'title';
                $allowed_orderby = array('title', 'date', 'meta_value', 'meta_value_num');
                if (!in_array($orderby, $allowed_orderby, true)) {
                    $orderby = 'title';
                }
                $order = strtoupper(sanitize_text_field(isset($payload['order']) ? $payload['order'] : 'ASC'));
                if (!in_array($order, array('ASC', 'DESC'), true)) {
                    $order = 'ASC';
                }
                $compare = strtoupper(sanitize_text_field(isset($payload['meta_compare']) ? $payload['meta_compare'] : '='));
                $allowed_compare = array('=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', '>', '>=', '<', '<=');
                if (!in_array($compare, $allowed_compare, true)) {
                    $compare = '=';
                }
                $entity['status_values'] = array_map('sanitize_key', self::ensure_list_values(isset($payload['status_values']) ? $payload['status_values'] : ''));
                $entity['market_values'] = array_map('sanitize_title', self::ensure_list_values(isset($payload['market_values']) ? $payload['market_values'] : ''));
                $entity['service_values'] = array_map('sanitize_title', self::ensure_list_values(isset($payload['service_values']) ? $payload['service_values'] : ''));
                $entity['featured_only'] = !empty($payload['featured_only']) ? '1' : '0';
                $entity['search_default'] = isset($payload['search_default']) ? sanitize_text_field($payload['search_default']) : '';
                $entity['date_from'] = self::sanitize_date(isset($payload['date_from']) ? $payload['date_from'] : '');
                $entity['date_to'] = self::sanitize_date(isset($payload['date_to']) ? $payload['date_to'] : '');
                $entity['meta_key'] = isset($payload['meta_key']) ? sanitize_key($payload['meta_key']) : '';
                $entity['meta_compare'] = $compare;
                $entity['meta_value'] = isset($payload['meta_value']) ? sanitize_text_field($payload['meta_value']) : '';
                $entity['relation_key'] = isset($payload['relation_key']) ? sanitize_key($payload['relation_key']) : '';
                $entity['relation_targets'] = array_map('intval', self::ensure_list_values(isset($payload['relation_targets']) ? $payload['relation_targets'] : ''));
                $entity['orderby'] = $orderby;
                $entity['order'] = $order;
                $entity['per_page'] = min(200, max(1, (int) (isset($payload['per_page']) ? $payload['per_page'] : 25)));
                return $entity;
            }

            if ('forms' === $type) {
                $fields = self::ensure_list_values(isset($payload['allowed_fields']) ? $payload['allowed_fields'] : '');
                $entity['allowed_fields'] = array_map('sanitize_key', $fields);
                $entity['allow_create'] = !empty($payload['allow_create']) ? '1' : '0';
                $entity['allow_edit'] = !empty($payload['allow_edit']) ? '1' : '0';
                $entity['visibility_rules'] = self::sanitize_visibility_json(isset($payload['visibility_rules']) ? $payload['visibility_rules'] : '');
                return $entity;
            }

            if ('pages' === $type) {
                $page_status = isset($payload['page_status']) ? sanitize_key($payload['page_status']) : 'publish';
                if (!in_array($page_status, array('publish', 'draft', 'private'), true)) {
                    $page_status = 'publish';
                }

                $template_id = isset($payload['template_id']) ? sanitize_key($payload['template_id']) : 'default-table';
                $query_id = isset($payload['query_id']) ? sanitize_key($payload['query_id']) : 'all-profiles';
                $page_title = isset($payload['page_title']) ? sanitize_text_field($payload['page_title']) : $entity['label'];
                $page_slug = isset($payload['page_slug']) ? sanitize_title($payload['page_slug']) : sanitize_title($page_title);
                if ('' === $page_slug) {
                    $page_slug = $id;
                }

                $entity['page_title'] = $page_title ? $page_title : $entity['label'];
                $entity['page_slug'] = $page_slug;
                $entity['page_status'] = $page_status;
                $entity['parent_page_id'] = max(0, (int) (isset($payload['parent_page_id']) ? $payload['parent_page_id'] : 0));
                $entity['template_id'] = $template_id;
                $entity['query_id'] = $query_id;
                $entity['intro_text'] = isset($payload['intro_text']) ? sanitize_textarea_field($payload['intro_text']) : '';
                $entity['generated_page_id'] = max(0, (int) (isset($payload['generated_page_id']) ? $payload['generated_page_id'] : 0));
                return $entity;
            }

            if ('relations' === $type) {
                $relation_type = isset($payload['relation_type']) ? sanitize_key($payload['relation_type']) : 'one_to_many';
                $allowed_relation_types = array('one_to_one', 'one_to_many', 'many_to_many');
                if (!in_array($relation_type, $allowed_relation_types, true)) {
                    $relation_type = 'one_to_many';
                }

                $source_object = isset($payload['source_object']) ? sanitize_key($payload['source_object']) : MadExtra_Citations_Plugin::CPT;
                $target_object = isset($payload['target_object']) ? sanitize_key($payload['target_object']) : MadExtra_Citations_Plugin::CPT;
                $allowed_objects = array(
                    MadExtra_Citations_Plugin::CPT,
                    MadExtra_Citations_Plugin::TAX_MARKET,
                    MadExtra_Citations_Plugin::TAX_SERVICE,
                    'user',
                );
                if (!in_array($source_object, $allowed_objects, true)) {
                    $source_object = MadExtra_Citations_Plugin::CPT;
                }
                if (!in_array($target_object, $allowed_objects, true)) {
                    $target_object = MadExtra_Citations_Plugin::CPT;
                }

                $relation_key = isset($payload['relation_key']) ? sanitize_key($payload['relation_key']) : '';
                if ('' === $relation_key) {
                    $relation_key = $id;
                }
                $entity['relation_key'] = $relation_key;
                $entity['relation_type'] = $relation_type;
                $entity['source_object'] = $source_object;
                $entity['target_object'] = $target_object;
                return $entity;
            }

            return $entity;
        }

        private static function get_type_capability($type)
        {
            $map = self::entity_map();
            return isset($map[$type]['cap']) ? $map[$type]['cap'] : 'manage_citation_builder';
        }

        private static function has_admin_fallback()
        {
            return current_user_can('manage_options') || current_user_can('manage_network_options');
        }

        private static function can_manage_builder()
        {
            return self::has_admin_fallback() || current_user_can('manage_citation_builder');
        }

        private static function can_manage_entity($type)
        {
            return self::has_admin_fallback() || current_user_can(self::get_type_capability($type));
        }

        private static function ensure_entity_permission_or_die($type)
        {
            if (!self::can_manage_entity($type)) {
                wp_die(esc_html__('You do not have permission for this builder action.', 'madextra-citations'));
            }
        }

        public static function register_builder_page()
        {
            add_submenu_page(
                'edit.php?post_type=' . MadExtra_Citations_Plugin::CPT,
                __('Directory Builder', 'madextra-citations'),
                __('Directory Builder', 'madextra-citations'),
                'read',
                self::ADMIN_PAGE_SLUG,
                array(__CLASS__, 'render_builder_page')
            );
        }

        public static function render_builder_page()
        {
            if (!self::can_manage_builder()) {
                wp_die(esc_html__('You do not have permission to access this page.', 'madextra-citations'));
            }

            $type = self::get_entity_type(isset($_GET['type']) ? wp_unslash($_GET['type']) : 'field_groups');
            $edit_id = isset($_GET['edit']) ? sanitize_key(wp_unslash($_GET['edit'])) : '';
            $entity = $edit_id ? self::get_entity($type, $edit_id) : null;
            $map = self::entity_map();
            $entities = self::get_entities($type);
            ksort($entities);

            $notice = isset($_GET['mec_notice']) ? sanitize_text_field(wp_unslash($_GET['mec_notice'])) : '';
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Directory Builder', 'madextra-citations'); ?></h1>
                <p><?php esc_html_e('Manage field groups, templates, queries, forms, directory pages, and relations visually from wp-admin.', 'madextra-citations'); ?></p>
                <?php if ('saved' === $notice) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Builder item saved.', 'madextra-citations'); ?></p></div>
                <?php elseif ('deleted' === $notice) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Builder item deleted.', 'madextra-citations'); ?></p></div>
                <?php elseif ('generated' === $notice) : ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Directory page generation complete.', 'madextra-citations'); ?></p></div>
                <?php elseif ('error' === $notice) : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Builder action failed. Check your input and try again.', 'madextra-citations'); ?></p></div>
                <?php endif; ?>

                <h2 class="nav-tab-wrapper">
                    <?php foreach ($map as $tab_key => $meta) : ?>
                        <?php
                        $url = add_query_arg(
                            array(
                                'post_type' => MadExtra_Citations_Plugin::CPT,
                                'page' => self::ADMIN_PAGE_SLUG,
                                'type' => $tab_key,
                            ),
                            admin_url('edit.php')
                        );
                        $active = $tab_key === $type ? ' nav-tab-active' : '';
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo esc_attr($active); ?>"><?php echo esc_html($meta['label']); ?></a>
                    <?php endforeach; ?>
                </h2>

                <div style="display:grid;grid-template-columns:1.35fr 1fr;gap:22px;margin-top:16px;">
                    <div>
                        <h2><?php echo esc_html($map[$type]['label']); ?></h2>
                        <?php if ('pages' === $type && $entities) : ?>
                            <p>
                                <a class="button button-primary" href="<?php echo esc_url(self::builder_generate_url('')); ?>"><?php esc_html_e('Create/Update All Directory Pages', 'madextra-citations'); ?></a>
                            </p>
                        <?php endif; ?>
                        <table class="widefat striped">
                            <thead>
                            <tr>
                                <th><?php esc_html_e('ID', 'madextra-citations'); ?></th>
                                <th><?php esc_html_e('Label', 'madextra-citations'); ?></th>
                                <th><?php esc_html_e('Updated', 'madextra-citations'); ?></th>
                                <th><?php esc_html_e('Actions', 'madextra-citations'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$entities) : ?>
                                <tr><td colspan="4"><?php esc_html_e('No items yet.', 'madextra-citations'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($entities as $row_id => $row) : ?>
                                    <?php
                                    $edit_url = add_query_arg(
                                        array(
                                            'post_type' => MadExtra_Citations_Plugin::CPT,
                                            'page' => self::ADMIN_PAGE_SLUG,
                                            'type' => $type,
                                            'edit' => $row_id,
                                        ),
                                        admin_url('edit.php')
                                    );
                                    $delete_url = wp_nonce_url(
                                        add_query_arg(
                                            array(
                                                'action' => 'mec_builder_delete',
                                                'type' => $type,
                                                'id' => $row_id,
                                            ),
                                            admin_url('admin-post.php')
                                        ),
                                        'mec_builder_delete_' . $type . '_' . $row_id
                                    );
                                    $generate_url = '';
                                    if ('pages' === $type) {
                                        $generate_url = self::builder_generate_url($row_id);
                                    }
                                    ?>
                                    <tr>
                                        <td><code><?php echo esc_html($row_id); ?></code></td>
                                        <td><?php echo esc_html(isset($row['label']) ? $row['label'] : $row_id); ?></td>
                                        <td><?php echo esc_html(isset($row['updated_at']) ? $row['updated_at'] : '-'); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'madextra-citations'); ?></a>
                                            <?php if ($generate_url) : ?> | <a href="<?php echo esc_url($generate_url); ?>"><?php esc_html_e('Generate Page', 'madextra-citations'); ?></a><?php endif; ?>
                                            | <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this item?', 'madextra-citations')); ?>');"><?php esc_html_e('Delete', 'madextra-citations'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h2><?php echo $entity ? esc_html__('Edit Item', 'madextra-citations') : esc_html__('Add Item', 'madextra-citations'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="mec_builder_save">
                            <input type="hidden" name="type" value="<?php echo esc_attr($type); ?>">
                            <?php wp_nonce_field('mec_builder_save_' . $type, self::NONCE_BUILDER); ?>
                            <table class="form-table" role="presentation">
                                <tbody>
                                <tr>
                                    <th scope="row"><label for="mec_item_id"><?php esc_html_e('ID (slug)', 'madextra-citations'); ?></label></th>
                                    <td><input type="text" id="mec_item_id" class="regular-text" name="item[id]" value="<?php echo esc_attr($entity && isset($entity['id']) ? $entity['id'] : ''); ?>" required></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mec_item_label"><?php esc_html_e('Label', 'madextra-citations'); ?></label></th>
                                    <td><input type="text" id="mec_item_label" class="regular-text" name="item[label]" value="<?php echo esc_attr($entity && isset($entity['label']) ? $entity['label'] : ''); ?>" required></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="mec_item_description"><?php esc_html_e('Description', 'madextra-citations'); ?></label></th>
                                    <td><textarea id="mec_item_description" name="item[description]" class="large-text" rows="2"><?php echo esc_textarea($entity && isset($entity['description']) ? $entity['description'] : ''); ?></textarea></td>
                                </tr>
                                <?php self::render_entity_editor_fields($type, $entity); ?>
                                </tbody>
                            </table>
                            <?php submit_button($entity ? __('Update Item', 'madextra-citations') : __('Create Item', 'madextra-citations')); ?>
                        </form>
                    </div>
                </div>
            </div>
            <?php
        }

        private static function render_entity_editor_fields($type, $entity)
        {
            if ('field_groups' === $type) {
                $target = $entity && isset($entity['target']) ? $entity['target'] : MadExtra_Citations_Plugin::CPT;
                $field_type = $entity && isset($entity['field_type']) ? $entity['field_type'] : 'text';
                ?>
                <tr>
                    <th scope="row"><label for="mec_field_target"><?php esc_html_e('Target Object', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_field_target" name="item[target]">
                            <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::CPT); ?>" <?php selected($target, MadExtra_Citations_Plugin::CPT); ?>><?php esc_html_e('Directory Profile', 'madextra-citations'); ?></option>
                            <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::TAX_MARKET); ?>" <?php selected($target, MadExtra_Citations_Plugin::TAX_MARKET); ?>><?php esc_html_e('Markets', 'madextra-citations'); ?></option>
                            <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::TAX_SERVICE); ?>" <?php selected($target, MadExtra_Citations_Plugin::TAX_SERVICE); ?>><?php esc_html_e('Services', 'madextra-citations'); ?></option>
                            <option value="user" <?php selected($target, 'user'); ?>><?php esc_html_e('Users', 'madextra-citations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_key"><?php esc_html_e('Field Key', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_field_key" class="regular-text" name="item[field_key]" value="<?php echo esc_attr($entity && isset($entity['field_key']) ? $entity['field_key'] : ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_label"><?php esc_html_e('Field Label', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_field_label" class="regular-text" name="item[field_label]" value="<?php echo esc_attr($entity && isset($entity['field_label']) ? $entity['field_label'] : ''); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_type"><?php esc_html_e('Field Type', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_field_type" name="item[field_type]">
                            <?php foreach (array('text', 'url', 'textarea', 'select', 'date', 'number', 'checkbox') as $f_type) : ?>
                                <option value="<?php echo esc_attr($f_type); ?>" <?php selected($field_type, $f_type); ?>><?php echo esc_html(ucfirst($f_type)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_options"><?php esc_html_e('Select Options', 'madextra-citations'); ?></label></th>
                    <td>
                        <input type="text" id="mec_field_options" class="large-text" name="item[options]" value="<?php echo esc_attr($entity && !empty($entity['options']) ? self::csv_for_list($entity['options']) : ''); ?>">
                        <p class="description"><?php esc_html_e('Comma-separated options. Used only for select fields.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_help"><?php esc_html_e('Help Text', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_field_help" class="large-text" name="item[help_text]" value="<?php echo esc_attr($entity && isset($entity['help_text']) ? $entity['help_text'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Required', 'madextra-citations'); ?></th>
                    <td><label><input type="checkbox" name="item[required]" value="1" <?php checked($entity && !empty($entity['required'])); ?>> <?php esc_html_e('Require this field', 'madextra-citations'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_field_visibility"><?php esc_html_e('Visibility Rules JSON', 'madextra-citations'); ?></label></th>
                    <td>
                        <textarea id="mec_field_visibility" class="large-text code" rows="3" name="item[visibility_rules]"><?php echo esc_textarea($entity && isset($entity['visibility_rules']) ? $entity['visibility_rules'] : ''); ?></textarea>
                        <p class="description"><?php esc_html_e('Optional JSON array of rules: [{"field":"status","operator":"eq","value":"live"}]', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <?php
                return;
            }

            if ('templates' === $type) {
                $style = $entity && isset($entity['style']) ? $entity['style'] : 'table';
                ?>
                <tr>
                    <th scope="row"><label for="mec_tpl_style"><?php esc_html_e('Template Style', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_tpl_style" name="item[style]">
                            <option value="table" <?php selected($style, 'table'); ?>><?php esc_html_e('Table', 'madextra-citations'); ?></option>
                            <option value="cards" <?php selected($style, 'cards'); ?>><?php esc_html_e('Cards', 'madextra-citations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_tpl_columns"><?php esc_html_e('Columns/Fields', 'madextra-citations'); ?></label></th>
                    <td>
                        <input type="text" id="mec_tpl_columns" class="large-text" name="item[columns]" value="<?php echo esc_attr($entity && !empty($entity['columns']) ? self::csv_for_list($entity['columns']) : 'nap_business_name, services, business_website_url, nap_phone, display_address, public_notes'); ?>">
                        <p class="description"><?php esc_html_e('Comma-separated field keys used in rendering.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Show Filters', 'madextra-citations'); ?></th>
                    <td><label><input type="checkbox" name="item[show_filters]" value="1" <?php checked($entity && !empty($entity['show_filters'])); ?>> <?php esc_html_e('Render filter controls above listing', 'madextra-citations'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_tpl_visibility"><?php esc_html_e('Visibility Rules JSON', 'madextra-citations'); ?></label></th>
                    <td><textarea id="mec_tpl_visibility" class="large-text code" rows="3" name="item[visibility_rules]"><?php echo esc_textarea($entity && isset($entity['visibility_rules']) ? $entity['visibility_rules'] : ''); ?></textarea></td>
                </tr>
                <?php
                return;
            }

            if ('queries' === $type) {
                ?>
                <tr>
                    <th scope="row"><label for="mec_q_status"><?php esc_html_e('Statuses', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_status" class="large-text" name="item[status_values]" value="<?php echo esc_attr($entity && !empty($entity['status_values']) ? self::csv_for_list($entity['status_values']) : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_market"><?php esc_html_e('Market Slugs', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_market" class="large-text" name="item[market_values]" value="<?php echo esc_attr($entity && !empty($entity['market_values']) ? self::csv_for_list($entity['market_values']) : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_service"><?php esc_html_e('Service Slugs', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_service" class="large-text" name="item[service_values]" value="<?php echo esc_attr($entity && !empty($entity['service_values']) ? self::csv_for_list($entity['service_values']) : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Featured Only', 'madextra-citations'); ?></th>
                    <td><label><input type="checkbox" name="item[featured_only]" value="1" <?php checked($entity && !empty($entity['featured_only'])); ?>> <?php esc_html_e('Only include featured profiles', 'madextra-citations'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_search"><?php esc_html_e('Default Search Term', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_search" class="regular-text" name="item[search_default]" value="<?php echo esc_attr($entity && isset($entity['search_default']) ? $entity['search_default'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_date_from"><?php esc_html_e('Date From', 'madextra-citations'); ?></label></th>
                    <td><input type="date" id="mec_q_date_from" name="item[date_from]" value="<?php echo esc_attr($entity && isset($entity['date_from']) ? $entity['date_from'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_date_to"><?php esc_html_e('Date To', 'madextra-citations'); ?></label></th>
                    <td><input type="date" id="mec_q_date_to" name="item[date_to]" value="<?php echo esc_attr($entity && isset($entity['date_to']) ? $entity['date_to'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_meta_key"><?php esc_html_e('Meta Key', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_meta_key" class="regular-text" name="item[meta_key]" value="<?php echo esc_attr($entity && isset($entity['meta_key']) ? $entity['meta_key'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_meta_compare"><?php esc_html_e('Meta Compare', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_meta_compare" class="regular-text" name="item[meta_compare]" value="<?php echo esc_attr($entity && isset($entity['meta_compare']) ? $entity['meta_compare'] : '='); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_meta_value"><?php esc_html_e('Meta Value', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_meta_value" class="regular-text" name="item[meta_value]" value="<?php echo esc_attr($entity && isset($entity['meta_value']) ? $entity['meta_value'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_rel_key"><?php esc_html_e('Relation Key', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_rel_key" class="regular-text" name="item[relation_key]" value="<?php echo esc_attr($entity && isset($entity['relation_key']) ? $entity['relation_key'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_rel_targets"><?php esc_html_e('Relation Target IDs', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_rel_targets" class="regular-text" name="item[relation_targets]" value="<?php echo esc_attr($entity && !empty($entity['relation_targets']) ? self::csv_for_list(array_map('strval', $entity['relation_targets'])) : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_orderby"><?php esc_html_e('Order By', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_orderby" class="regular-text" name="item[orderby]" value="<?php echo esc_attr($entity && isset($entity['orderby']) ? $entity['orderby'] : 'title'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_order"><?php esc_html_e('Order', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_q_order" class="regular-text" name="item[order]" value="<?php echo esc_attr($entity && isset($entity['order']) ? $entity['order'] : 'ASC'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_q_per_page"><?php esc_html_e('Per Page', 'madextra-citations'); ?></label></th>
                    <td><input type="number" id="mec_q_per_page" min="1" max="200" name="item[per_page]" value="<?php echo esc_attr($entity && isset($entity['per_page']) ? (int) $entity['per_page'] : 25); ?>"></td>
                </tr>
                <?php
                return;
            }

            if ('forms' === $type) {
                ?>
                <tr>
                    <th scope="row"><label for="mec_form_allowed"><?php esc_html_e('Allowed Fields', 'madextra-citations'); ?></label></th>
                    <td>
                        <input type="text" id="mec_form_allowed" class="large-text" name="item[allowed_fields]" value="<?php echo esc_attr($entity && !empty($entity['allowed_fields']) ? self::csv_for_list($entity['allowed_fields']) : 'directory_name, listing_url, status, last_verified_date, public_notes, nap_business_name, nap_address, nap_phone, business_website_url, business_email, business_description, business_hours, address_street, address_city, address_state, address_zip, is_featured, featured_order'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Create', 'madextra-citations'); ?></th>
                    <td><label><input type="checkbox" name="item[allow_create]" value="1" <?php checked($entity && !empty($entity['allow_create'])); ?>> <?php esc_html_e('Allow profile creation', 'madextra-citations'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Edit', 'madextra-citations'); ?></th>
                    <td><label><input type="checkbox" name="item[allow_edit]" value="1" <?php checked($entity && !empty($entity['allow_edit'])); ?>> <?php esc_html_e('Allow profile editing', 'madextra-citations'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_form_visibility"><?php esc_html_e('Visibility Rules JSON', 'madextra-citations'); ?></label></th>
                    <td><textarea id="mec_form_visibility" class="large-text code" rows="3" name="item[visibility_rules]"><?php echo esc_textarea($entity && isset($entity['visibility_rules']) ? $entity['visibility_rules'] : ''); ?></textarea></td>
                </tr>
                <?php
                return;
            }

            if ('pages' === $type) {
                $template_id = $entity && isset($entity['template_id']) ? $entity['template_id'] : 'default-table';
                $query_id = $entity && isset($entity['query_id']) ? $entity['query_id'] : 'all-profiles';
                $page_title = $entity && isset($entity['page_title']) ? $entity['page_title'] : '';
                $page_slug = $entity && isset($entity['page_slug']) ? $entity['page_slug'] : '';
                $page_status = $entity && isset($entity['page_status']) ? $entity['page_status'] : 'publish';
                $parent_page_id = $entity && isset($entity['parent_page_id']) ? (int) $entity['parent_page_id'] : 0;
                $generated_page_id = $entity && isset($entity['generated_page_id']) ? (int) $entity['generated_page_id'] : 0;
                $templates = self::get_entities('templates');
                $queries = self::get_entities('queries');
                $pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'ASC'));
                ?>
                <tr>
                    <th scope="row"><label for="mec_page_title"><?php esc_html_e('WordPress Page Title', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_page_title" class="large-text" name="item[page_title]" value="<?php echo esc_attr($page_title); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_page_slug"><?php esc_html_e('Page Slug', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_page_slug" class="regular-text" name="item[page_slug]" value="<?php echo esc_attr($page_slug); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_page_status"><?php esc_html_e('Page Status', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_page_status" name="item[page_status]">
                            <option value="publish" <?php selected($page_status, 'publish'); ?>><?php esc_html_e('Publish', 'madextra-citations'); ?></option>
                            <option value="draft" <?php selected($page_status, 'draft'); ?>><?php esc_html_e('Draft', 'madextra-citations'); ?></option>
                            <option value="private" <?php selected($page_status, 'private'); ?>><?php esc_html_e('Private', 'madextra-citations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_parent_page_id"><?php esc_html_e('Parent Page', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_parent_page_id" name="item[parent_page_id]">
                            <option value="0"><?php esc_html_e('No parent', 'madextra-citations'); ?></option>
                            <?php foreach ($pages as $page) : ?>
                                <option value="<?php echo esc_attr((string) $page->ID); ?>" <?php selected($parent_page_id, (int) $page->ID); ?>><?php echo esc_html($page->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_page_template_id"><?php esc_html_e('Listing Template', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_page_template_id" name="item[template_id]">
                            <?php foreach ($templates as $row_id => $row) : ?>
                                <option value="<?php echo esc_attr($row_id); ?>" <?php selected($template_id, $row_id); ?>><?php echo esc_html(isset($row['label']) ? $row['label'] : $row_id); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_page_query_id"><?php esc_html_e('Query Preset', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_page_query_id" name="item[query_id]">
                            <?php foreach ($queries as $row_id => $row) : ?>
                                <option value="<?php echo esc_attr($row_id); ?>" <?php selected($query_id, $row_id); ?>><?php echo esc_html(isset($row['label']) ? $row['label'] : $row_id); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_page_intro"><?php esc_html_e('Intro Text', 'madextra-citations'); ?></label></th>
                    <td>
                        <textarea id="mec_page_intro" class="large-text" rows="4" name="item[intro_text]"><?php echo esc_textarea($entity && isset($entity['intro_text']) ? $entity['intro_text'] : ''); ?></textarea>
                        <p class="description"><?php esc_html_e('Shown above the filters and listing on generated pages.', 'madextra-citations'); ?></p>
                    </td>
                </tr>
                <?php if ($generated_page_id) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Generated Page', 'madextra-citations'); ?></th>
                        <td>
                            <input type="hidden" name="item[generated_page_id]" value="<?php echo esc_attr((string) $generated_page_id); ?>">
                            <a href="<?php echo esc_url(get_edit_post_link($generated_page_id, '')); ?>"><?php echo esc_html(get_the_title($generated_page_id)); ?></a>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php
                return;
            }

            if ('relations' === $type) {
                $relation_type = $entity && isset($entity['relation_type']) ? $entity['relation_type'] : 'one_to_many';
                $source_object = $entity && isset($entity['source_object']) ? $entity['source_object'] : MadExtra_Citations_Plugin::CPT;
                $target_object = $entity && isset($entity['target_object']) ? $entity['target_object'] : MadExtra_Citations_Plugin::CPT;
                ?>
                <tr>
                    <th scope="row"><label for="mec_rel_key"><?php esc_html_e('Relation Key', 'madextra-citations'); ?></label></th>
                    <td><input type="text" id="mec_rel_key" class="regular-text" name="item[relation_key]" value="<?php echo esc_attr($entity && isset($entity['relation_key']) ? $entity['relation_key'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_rel_type"><?php esc_html_e('Relation Type', 'madextra-citations'); ?></label></th>
                    <td>
                        <select id="mec_rel_type" name="item[relation_type]">
                            <option value="one_to_one" <?php selected($relation_type, 'one_to_one'); ?>><?php esc_html_e('One to One', 'madextra-citations'); ?></option>
                            <option value="one_to_many" <?php selected($relation_type, 'one_to_many'); ?>><?php esc_html_e('One to Many', 'madextra-citations'); ?></option>
                            <option value="many_to_many" <?php selected($relation_type, 'many_to_many'); ?>><?php esc_html_e('Many to Many', 'madextra-citations'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_rel_source"><?php esc_html_e('Source Object', 'madextra-citations'); ?></label></th>
                    <td><?php self::render_object_select('item[source_object]', 'mec_rel_source', $source_object); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mec_rel_target"><?php esc_html_e('Target Object', 'madextra-citations'); ?></label></th>
                    <td><?php self::render_object_select('item[target_object]', 'mec_rel_target', $target_object); ?></td>
                </tr>
                <?php
            }
        }

        private static function render_object_select($name, $id, $selected)
        {
            ?>
            <select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>">
                <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::CPT); ?>" <?php selected($selected, MadExtra_Citations_Plugin::CPT); ?>><?php esc_html_e('Directory Profile', 'madextra-citations'); ?></option>
                <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::TAX_MARKET); ?>" <?php selected($selected, MadExtra_Citations_Plugin::TAX_MARKET); ?>><?php esc_html_e('Market Terms', 'madextra-citations'); ?></option>
                <option value="<?php echo esc_attr(MadExtra_Citations_Plugin::TAX_SERVICE); ?>" <?php selected($selected, MadExtra_Citations_Plugin::TAX_SERVICE); ?>><?php esc_html_e('Service Terms', 'madextra-citations'); ?></option>
                <option value="user" <?php selected($selected, 'user'); ?>><?php esc_html_e('Users', 'madextra-citations'); ?></option>
            </select>
            <?php
        }

        public static function handle_builder_save()
        {
            $type = self::get_entity_type(isset($_POST['type']) ? wp_unslash($_POST['type']) : '');
            self::ensure_entity_permission_or_die($type);

            if (!isset($_POST[self::NONCE_BUILDER]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_BUILDER])), 'mec_builder_save_' . $type)) {
                wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'error')));
                exit;
            }

            $item = isset($_POST['item']) ? (array) wp_unslash($_POST['item']) : array();
            $entity = self::sanitize_entity($type, $item);
            self::save_entity($type, $entity);

            wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'saved', 'edit' => $entity['id'])));
            exit;
        }

        public static function handle_builder_delete()
        {
            $type = self::get_entity_type(isset($_GET['type']) ? wp_unslash($_GET['type']) : '');
            $id = isset($_GET['id']) ? sanitize_key(wp_unslash($_GET['id'])) : '';
            self::ensure_entity_permission_or_die($type);

            if ('' === $id || !wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', 'mec_builder_delete_' . $type . '_' . $id)) {
                wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'error')));
                exit;
            }

            self::delete_entity($type, $id);
            wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'deleted')));
            exit;
        }

        public static function handle_builder_generate_pages()
        {
            $type = 'pages';
            self::ensure_entity_permission_or_die($type);

            $scope = isset($_GET['scope']) ? sanitize_key(wp_unslash($_GET['scope'])) : '';
            $id = isset($_GET['id']) ? sanitize_key(wp_unslash($_GET['id'])) : '';
            $nonce_action = 'all' === $scope ? 'mec_builder_generate_pages_all' : 'mec_builder_generate_page_' . $id;
            if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '', $nonce_action)) {
                wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'error')));
                exit;
            }

            $entities = self::get_entities($type);
            if ('all' === $scope) {
                foreach ($entities as $entity_id => $entity) {
                    if (!is_array($entity)) {
                        continue;
                    }
                    $entities[$entity_id] = self::generate_page_from_entity($entity);
                }
            } elseif (isset($entities[$id]) && is_array($entities[$id])) {
                $entities[$id] = self::generate_page_from_entity($entities[$id]);
            }

            $store = self::get_store();
            $store[$type] = $entities;
            self::update_store($store);

            wp_safe_redirect(self::builder_url($type, array('mec_notice' => 'generated')));
            exit;
        }

        private static function builder_url($type, array $extra = array())
        {
            $args = array_merge(
                array(
                    'post_type' => MadExtra_Citations_Plugin::CPT,
                    'page' => self::ADMIN_PAGE_SLUG,
                    'type' => $type,
                ),
                $extra
            );
            return add_query_arg($args, admin_url('edit.php'));
        }

        private static function builder_generate_url($id = '')
        {
            $args = array(
                'action' => 'mec_builder_generate_pages',
            );
            $nonce_action = 'mec_builder_generate_pages_all';
            if ('' !== $id) {
                $args['id'] = $id;
                $nonce_action = 'mec_builder_generate_page_' . $id;
            } else {
                $args['scope'] = 'all';
            }
            return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), $nonce_action);
        }

        private static function generate_page_from_entity(array $entity)
        {
            $post_id = isset($entity['generated_page_id']) ? (int) $entity['generated_page_id'] : 0;
            $page_title = isset($entity['page_title']) && '' !== $entity['page_title'] ? $entity['page_title'] : (isset($entity['label']) ? $entity['label'] : __('Directory Listing', 'madextra-citations'));
            $page_slug = isset($entity['page_slug']) ? sanitize_title($entity['page_slug']) : '';
            $page_status = isset($entity['page_status']) ? sanitize_key($entity['page_status']) : 'publish';

            if (!$post_id && $page_slug) {
                $existing = get_page_by_path($page_slug);
                if ($existing instanceof WP_Post) {
                    $post_id = (int) $existing->ID;
                }
            }

            $post_args = array(
                'post_type' => 'page',
                'post_status' => $page_status,
                'post_title' => $page_title,
                'post_name' => $page_slug,
                'post_parent' => isset($entity['parent_page_id']) ? (int) $entity['parent_page_id'] : 0,
                'post_content' => self::generated_page_content($entity),
            );

            if ($post_id > 0) {
                $post_args['ID'] = $post_id;
                $result = wp_update_post($post_args, true);
            } else {
                $result = wp_insert_post($post_args, true);
            }

            if (!is_wp_error($result)) {
                $entity['generated_page_id'] = (int) $result;
                $entity['updated_at'] = current_time('mysql');
            }

            return $entity;
        }

        private static function generated_page_content(array $entity)
        {
            $template_id = isset($entity['template_id']) && '' !== $entity['template_id'] ? $entity['template_id'] : 'default-table';
            $query_id = isset($entity['query_id']) && '' !== $entity['query_id'] ? $entity['query_id'] : 'all-profiles';
            $parts = array();
            if (!empty($entity['intro_text'])) {
                $parts[] = wpautop($entity['intro_text']);
            }
            $parts[] = '[mec_filters query="' . esc_attr($query_id) . '" include_market="0"]';
            $parts[] = '[mec_listing template="' . esc_attr($template_id) . '" query="' . esc_attr($query_id) . '" show_filters="0"]';
            return implode("\n\n", $parts);
        }

        private static function get_dynamic_fields_for_target($target)
        {
            $rows = self::get_entities('field_groups');
            $defs = array();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!isset($row['target']) || $row['target'] !== $target) {
                    continue;
                }
                if (empty($row['field_key'])) {
                    continue;
                }
                $defs[$row['field_key']] = $row;
            }
            return $defs;
        }

        private static function get_relation_definitions($source_object = '')
        {
            $rows = self::get_entities('relations');
            if ('' === $source_object) {
                return $rows;
            }
            $filtered = array();
            foreach ($rows as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row['source_object']) && $source_object === $row['source_object']) {
                    $filtered[$key] = $row;
                }
            }
            return $filtered;
        }

        private static function dynamic_meta_key($field_key)
        {
            return self::META_DYNAMIC_PREFIX . sanitize_key($field_key);
        }

        private static function relation_meta_key($relation_key)
        {
            return self::META_RELATION_PREFIX . sanitize_key($relation_key);
        }

        public static function register_dynamic_meta_boxes()
        {
            $fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            $relations = self::get_relation_definitions(MadExtra_Citations_Plugin::CPT);
            if (!$fields && !$relations) {
                return;
            }

            add_meta_box(
                'mec_builder_dynamic_fields',
                __('Builder Fields & Relations', 'madextra-citations'),
                array(__CLASS__, 'render_dynamic_profile_meta_box'),
                MadExtra_Citations_Plugin::CPT,
                'normal',
                'default'
            );
        }

        public static function render_dynamic_profile_meta_box($post)
        {
            wp_nonce_field(self::NONCE_DYNAMIC_META, self::NONCE_DYNAMIC_META);
            $profile_data = self::profile_payload_for_visibility($post->ID);
            $fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            $relations = self::get_relation_definitions(MadExtra_Citations_Plugin::CPT);
            ?>
            <table class="form-table" role="presentation">
                <tbody>
                <?php foreach ($fields as $field_key => $field) : ?>
                    <?php
                    if (!self::passes_visibility_rules(isset($field['visibility_rules']) ? $field['visibility_rules'] : '', $profile_data)) {
                        continue;
                    }
                    $value = get_post_meta($post->ID, self::dynamic_meta_key($field_key), true);
                    ?>
                    <tr>
                        <th scope="row"><label for="mec_dyn_<?php echo esc_attr($field_key); ?>"><?php echo esc_html(isset($field['field_label']) ? $field['field_label'] : $field_key); ?></label></th>
                        <td>
                            <?php self::render_dynamic_field_input('mec_dyn[' . $field_key . ']', 'mec_dyn_' . $field_key, $field, $value); ?>
                            <?php if (!empty($field['help_text'])) : ?><p class="description"><?php echo esc_html($field['help_text']); ?></p><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ($relations as $relation_id => $relation) : ?>
                    <?php
                    $relation_key = isset($relation['relation_key']) ? $relation['relation_key'] : $relation_id;
                    $value = get_post_meta($post->ID, self::relation_meta_key($relation_key), true);
                    $selected = is_array($value) ? array_map('intval', $value) : array_map('intval', self::ensure_list_values((string) $value));
                    $options = self::relation_target_options(isset($relation['target_object']) ? $relation['target_object'] : MadExtra_Citations_Plugin::CPT);
                    ?>
                    <tr>
                        <th scope="row"><label for="mec_rel_<?php echo esc_attr($relation_key); ?>"><?php echo esc_html(isset($relation['label']) ? $relation['label'] : $relation_key); ?></label></th>
                        <td>
                            <select id="mec_rel_<?php echo esc_attr($relation_key); ?>" name="mec_rel[<?php echo esc_attr($relation_key); ?>][]" multiple style="min-width:320px;min-height:120px;">
                                <?php foreach ($options as $target_id => $target_label) : ?>
                                    <option value="<?php echo esc_attr($target_id); ?>" <?php selected(in_array((int) $target_id, $selected, true)); ?>><?php echo esc_html($target_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html(sprintf(__('Relation type: %s', 'madextra-citations'), isset($relation['relation_type']) ? $relation['relation_type'] : 'one_to_many')); ?></p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        private static function render_dynamic_field_input($name, $id, array $field, $value)
        {
            $type = isset($field['field_type']) ? $field['field_type'] : 'text';
            if ('textarea' === $type) {
                echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="large-text" rows="3">' . esc_textarea((string) $value) . '</textarea>';
                return;
            }
            if ('select' === $type) {
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                echo '<option value="">' . esc_html__('Select...', 'madextra-citations') . '</option>';
                foreach ($options as $option) {
                    echo '<option value="' . esc_attr($option) . '" ' . selected((string) $value, (string) $option, false) . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
                return;
            }
            if ('checkbox' === $type) {
                echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked((string) $value, '1', false) . '> ' . esc_html__('Enabled', 'madextra-citations') . '</label>';
                return;
            }
            $input_type = in_array($type, array('url', 'date', 'number'), true) ? $type : 'text';
            echo '<input type="' . esc_attr($input_type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" class="regular-text">';
        }

        private static function sanitize_dynamic_field_value(array $field, $value)
        {
            $type = isset($field['field_type']) ? $field['field_type'] : 'text';
            if ('textarea' === $type) {
                return sanitize_textarea_field((string) $value);
            }
            if ('url' === $type) {
                return esc_url_raw((string) $value);
            }
            if ('date' === $type) {
                return self::sanitize_date($value);
            }
            if ('number' === $type) {
                return (string) floatval($value);
            }
            if ('checkbox' === $type) {
                return !empty($value) ? '1' : '0';
            }
            if ('select' === $type) {
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
                $clean = sanitize_text_field((string) $value);
                return in_array($clean, $options, true) ? $clean : '';
            }
            return sanitize_text_field((string) $value);
        }

        public static function save_dynamic_profile_meta($post_id)
        {
            if (!isset($_POST[self::NONCE_DYNAMIC_META]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_DYNAMIC_META])), self::NONCE_DYNAMIC_META)) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            $raw = isset($_POST['mec_dyn']) ? (array) wp_unslash($_POST['mec_dyn']) : array();
            foreach ($fields as $field_key => $field) {
                $raw_value = isset($raw[$field_key]) ? $raw[$field_key] : '';
                $clean = self::sanitize_dynamic_field_value($field, $raw_value);
                update_post_meta($post_id, self::dynamic_meta_key($field_key), $clean);
            }

            $relations = self::get_relation_definitions(MadExtra_Citations_Plugin::CPT);
            $raw_rel = isset($_POST['mec_rel']) ? (array) wp_unslash($_POST['mec_rel']) : array();
            foreach ($relations as $relation_id => $relation) {
                $relation_key = isset($relation['relation_key']) ? $relation['relation_key'] : $relation_id;
                $value = isset($raw_rel[$relation_key]) ? (array) $raw_rel[$relation_key] : array();
                $value = array_values(array_filter(array_map('intval', $value)));
                if ('one_to_one' === (isset($relation['relation_type']) ? $relation['relation_type'] : '')) {
                    $value = $value ? array((int) $value[0]) : array();
                }
                update_post_meta($post_id, self::relation_meta_key($relation_key), $value);
            }
        }

        private static function relation_target_options($target_object)
        {
            $options = array();

            if (MadExtra_Citations_Plugin::CPT === $target_object) {
                $query = new WP_Query(
                    array(
                        'post_type' => MadExtra_Citations_Plugin::CPT,
                        'post_status' => array('publish', 'draft', 'pending', 'private'),
                        'posts_per_page' => 500,
                        'orderby' => 'title',
                        'order' => 'ASC',
                    )
                );
                foreach ($query->posts as $post) {
                    $options[(int) $post->ID] = get_the_title($post->ID) ? get_the_title($post->ID) : ('#' . $post->ID);
                }
                return $options;
            }

            if (MadExtra_Citations_Plugin::TAX_MARKET === $target_object || MadExtra_Citations_Plugin::TAX_SERVICE === $target_object) {
                $terms = get_terms(
                    array(
                        'taxonomy' => $target_object,
                        'hide_empty' => false,
                        'orderby' => 'name',
                        'order' => 'ASC',
                    )
                );
                if (!is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $options[(int) $term->term_id] = $term->name;
                    }
                }
                return $options;
            }

            if ('user' === $target_object) {
                $users = get_users(
                    array(
                        'number' => 500,
                        'orderby' => 'display_name',
                        'order' => 'ASC',
                        'fields' => array('ID', 'display_name', 'user_email'),
                    )
                );
                foreach ($users as $user) {
                    $label = $user->display_name ? $user->display_name : $user->user_email;
                    $options[(int) $user->ID] = $label . ' (#' . $user->ID . ')';
                }
            }

            return $options;
        }

        public static function render_market_term_add_fields()
        {
            self::render_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_MARKET, 0, false);
        }

        public static function render_market_term_edit_fields($term)
        {
            self::render_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_MARKET, (int) $term->term_id, true);
        }

        public static function save_market_term_fields($term_id)
        {
            self::save_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_MARKET, (int) $term_id);
        }

        public static function render_service_term_add_fields()
        {
            self::render_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_SERVICE, 0, false);
        }

        public static function render_service_term_edit_fields($term)
        {
            self::render_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_SERVICE, (int) $term->term_id, true);
        }

        public static function save_service_term_fields($term_id)
        {
            self::save_term_dynamic_fields(MadExtra_Citations_Plugin::TAX_SERVICE, (int) $term_id);
        }

        private static function render_term_dynamic_fields($taxonomy, $term_id, $is_edit)
        {
            if (!self::can_manage_builder()) {
                return;
            }

            $fields = self::get_dynamic_fields_for_target($taxonomy);
            if (!$fields) {
                return;
            }

            if (!$is_edit) {
                echo '<div class="form-field"><h3>' . esc_html__('Builder Fields', 'madextra-citations') . '</h3></div>';
                foreach ($fields as $key => $field) {
                    echo '<div class="form-field">';
                    echo '<label for="mec_dyn_' . esc_attr($key) . '">' . esc_html(isset($field['field_label']) ? $field['field_label'] : $key) . '</label>';
                    self::render_dynamic_field_input('mec_dyn[' . $key . ']', 'mec_dyn_' . $key, $field, '');
                    echo '</div>';
                }
                return;
            }

            foreach ($fields as $key => $field) {
                $value = get_term_meta($term_id, self::dynamic_meta_key($key), true);
                echo '<tr class="form-field">';
                echo '<th scope="row"><label for="mec_dyn_' . esc_attr($key) . '">' . esc_html(isset($field['field_label']) ? $field['field_label'] : $key) . '</label></th>';
                echo '<td>';
                self::render_dynamic_field_input('mec_dyn[' . $key . ']', 'mec_dyn_' . $key, $field, $value);
                echo '</td>';
                echo '</tr>';
            }
        }

        private static function save_term_dynamic_fields($taxonomy, $term_id)
        {
            if (!self::can_manage_builder()) {
                return;
            }
            $fields = self::get_dynamic_fields_for_target($taxonomy);
            if (!$fields) {
                return;
            }
            $raw = isset($_POST['mec_dyn']) ? (array) wp_unslash($_POST['mec_dyn']) : array();
            foreach ($fields as $key => $field) {
                $value = isset($raw[$key]) ? $raw[$key] : '';
                update_term_meta($term_id, self::dynamic_meta_key($key), self::sanitize_dynamic_field_value($field, $value));
            }
        }

        public static function render_user_fields($user)
        {
            if (!self::can_manage_builder()) {
                return;
            }
            $fields = self::get_dynamic_fields_for_target('user');
            if (!$fields) {
                return;
            }
            ?>
            <h2><?php esc_html_e('Directory Builder User Fields', 'madextra-citations'); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                <?php foreach ($fields as $key => $field) : ?>
                    <?php $value = get_user_meta($user->ID, self::dynamic_meta_key($key), true); ?>
                    <tr>
                        <th><label for="mec_dyn_<?php echo esc_attr($key); ?>"><?php echo esc_html(isset($field['field_label']) ? $field['field_label'] : $key); ?></label></th>
                        <td><?php self::render_dynamic_field_input('mec_dyn[' . $key . ']', 'mec_dyn_' . $key, $field, $value); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        public static function save_user_fields($user_id)
        {
            if (!current_user_can('edit_user', $user_id)) {
                return;
            }
            $fields = self::get_dynamic_fields_for_target('user');
            if (!$fields) {
                return;
            }
            $raw = isset($_POST['mec_dyn']) ? (array) wp_unslash($_POST['mec_dyn']) : array();
            foreach ($fields as $key => $field) {
                $value = isset($raw[$key]) ? $raw[$key] : '';
                update_user_meta($user_id, self::dynamic_meta_key($key), self::sanitize_dynamic_field_value($field, $value));
            }
        }

        private static function profile_core_fields()
        {
            return array(
                'directory_name' => array('type' => 'text', 'required' => true),
                'listing_url' => array('type' => 'url', 'required' => true),
                'status' => array('type' => 'select', 'required' => true),
                'last_verified_date' => array('type' => 'date', 'required' => false),
                'public_notes' => array('type' => 'textarea', 'required' => false),
                'nap_business_name' => array('type' => 'text', 'required' => true),
                'nap_address' => array('type' => 'textarea', 'required' => true),
                'nap_phone' => array('type' => 'text', 'required' => true),
                'business_website_url' => array('type' => 'url', 'required' => false),
                'business_logo_id' => array('type' => 'number', 'required' => false),
                'business_email' => array('type' => 'text', 'required' => false),
                'business_description' => array('type' => 'textarea', 'required' => false),
                'business_hours' => array('type' => 'textarea', 'required' => false),
                'address_street' => array('type' => 'text', 'required' => false),
                'address_city' => array('type' => 'text', 'required' => false),
                'address_state' => array('type' => 'text', 'required' => false),
                'address_zip' => array('type' => 'text', 'required' => false),
                'self_serve_enabled' => array('type' => 'checkbox', 'required' => false),
                'self_serve_cta_label' => array('type' => 'text', 'required' => false),
                'self_serve_cta_url' => array('type' => 'url', 'required' => false),
                'self_serve_price_text' => array('type' => 'text', 'required' => false),
                'public_profile_page_id' => array('type' => 'number', 'required' => false),
                'is_premium' => array('type' => 'checkbox', 'required' => false),
                'service_areas' => array('type' => 'textarea', 'required' => false),
                'faq_items' => array('type' => 'textarea', 'required' => false),
                'social_links' => array('type' => 'textarea', 'required' => false),
                'gallery_media_ids' => array('type' => 'text', 'required' => false),
                'primary_cta_label' => array('type' => 'text', 'required' => false),
                'primary_cta_url' => array('type' => 'url', 'required' => false),
                'secondary_cta_label' => array('type' => 'text', 'required' => false),
                'secondary_cta_url' => array('type' => 'url', 'required' => false),
                'premium_hero_text' => array('type' => 'text', 'required' => false),
                'premium_subheadline' => array('type' => 'text', 'required' => false),
                'extended_about_copy' => array('type' => 'textarea', 'required' => false),
                'services_summary' => array('type' => 'textarea', 'required' => false),
                'service_cards' => array('type' => 'textarea', 'required' => false),
                'premium_badge_text' => array('type' => 'text', 'required' => false),
                'premium_page_mode' => array('type' => 'text', 'required' => false),
                'premium_page_status' => array('type' => 'text', 'required' => false),
                'premium_last_generated_at' => array('type' => 'text', 'required' => false),
                'premium_layout_template_key' => array('type' => 'text', 'required' => false),
                'premium_manual_override' => array('type' => 'checkbox', 'required' => false),
                'premium_notes' => array('type' => 'textarea', 'required' => false),
                'internal_notes' => array('type' => 'textarea', 'required' => false),
                'is_featured' => array('type' => 'checkbox', 'required' => false),
                'featured_order' => array('type' => 'number', 'required' => false),
            );
        }

        private static function status_options()
        {
            return array('live', 'pending', 'in_progress', 'needs_fix', 'suspended');
        }

        private static function sanitize_profile_payload(array $payload)
        {
            $status = isset($payload['status']) ? sanitize_key($payload['status']) : 'pending';
            if (!in_array($status, self::status_options(), true)) {
                $status = 'pending';
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
                'directory_name' => isset($payload['directory_name']) ? sanitize_text_field($payload['directory_name']) : '',
                'listing_url' => isset($payload['listing_url']) ? esc_url_raw($payload['listing_url']) : '',
                'status' => $status,
                'last_verified_date' => self::sanitize_date(isset($payload['last_verified_date']) ? $payload['last_verified_date'] : ''),
                'public_notes' => isset($payload['public_notes']) ? sanitize_textarea_field($payload['public_notes']) : '',
                'nap_business_name' => isset($payload['nap_business_name']) ? sanitize_text_field($payload['nap_business_name']) : '',
                'nap_address' => isset($payload['nap_address']) ? sanitize_textarea_field($payload['nap_address']) : '',
                'nap_phone' => isset($payload['nap_phone']) ? sanitize_text_field($payload['nap_phone']) : '',
                'business_website_url' => isset($payload['business_website_url']) ? esc_url_raw($payload['business_website_url']) : '',
                'business_logo_id' => isset($payload['business_logo_id']) ? (string) max(0, (int) $payload['business_logo_id']) : '0',
                'business_email' => isset($payload['business_email']) ? sanitize_email($payload['business_email']) : '',
                'business_description' => isset($payload['business_description']) ? sanitize_textarea_field($payload['business_description']) : '',
                'business_hours' => isset($payload['business_hours']) ? sanitize_textarea_field($payload['business_hours']) : '',
                'address_street' => isset($payload['address_street']) ? sanitize_text_field($payload['address_street']) : '',
                'address_city' => isset($payload['address_city']) ? sanitize_text_field($payload['address_city']) : '',
                'address_state' => isset($payload['address_state']) ? sanitize_text_field($payload['address_state']) : '',
                'address_zip' => isset($payload['address_zip']) ? sanitize_text_field($payload['address_zip']) : '',
                'self_serve_enabled' => !empty($payload['self_serve_enabled']) ? '1' : '0',
                'self_serve_cta_label' => isset($payload['self_serve_cta_label']) ? sanitize_text_field($payload['self_serve_cta_label']) : '',
                'self_serve_cta_url' => isset($payload['self_serve_cta_url']) ? esc_url_raw($payload['self_serve_cta_url']) : '',
                'self_serve_price_text' => isset($payload['self_serve_price_text']) ? sanitize_text_field($payload['self_serve_price_text']) : '',
                'public_profile_page_id' => isset($payload['public_profile_page_id']) ? (string) max(0, (int) $payload['public_profile_page_id']) : '0',
                'is_premium' => !empty($payload['is_premium']) ? '1' : '0',
                'service_areas' => isset($payload['service_areas']) ? sanitize_textarea_field($payload['service_areas']) : '',
                'faq_items' => isset($payload['faq_items']) ? sanitize_textarea_field($payload['faq_items']) : '',
                'social_links' => isset($payload['social_links']) ? sanitize_textarea_field($payload['social_links']) : '',
                'gallery_media_ids' => isset($payload['gallery_media_ids']) ? sanitize_text_field($payload['gallery_media_ids']) : '',
                'primary_cta_label' => isset($payload['primary_cta_label']) ? sanitize_text_field($payload['primary_cta_label']) : '',
                'primary_cta_url' => isset($payload['primary_cta_url']) ? esc_url_raw($payload['primary_cta_url']) : '',
                'secondary_cta_label' => isset($payload['secondary_cta_label']) ? sanitize_text_field($payload['secondary_cta_label']) : '',
                'secondary_cta_url' => isset($payload['secondary_cta_url']) ? esc_url_raw($payload['secondary_cta_url']) : '',
                'premium_hero_text' => isset($payload['premium_hero_text']) ? sanitize_text_field($payload['premium_hero_text']) : '',
                'premium_subheadline' => isset($payload['premium_subheadline']) ? sanitize_text_field($payload['premium_subheadline']) : '',
                'extended_about_copy' => isset($payload['extended_about_copy']) ? sanitize_textarea_field($payload['extended_about_copy']) : '',
                'services_summary' => isset($payload['services_summary']) ? sanitize_textarea_field($payload['services_summary']) : '',
                'service_cards' => isset($payload['service_cards']) ? sanitize_textarea_field($payload['service_cards']) : '',
                'premium_badge_text' => isset($payload['premium_badge_text']) ? sanitize_text_field($payload['premium_badge_text']) : '',
                'premium_page_mode' => isset($payload['premium_page_mode']) ? sanitize_key($payload['premium_page_mode']) : '',
                'premium_page_status' => isset($payload['premium_page_status']) ? sanitize_key($payload['premium_page_status']) : '',
                'premium_last_generated_at' => isset($payload['premium_last_generated_at']) ? sanitize_text_field($payload['premium_last_generated_at']) : '',
                'premium_layout_template_key' => isset($payload['premium_layout_template_key']) ? sanitize_key($payload['premium_layout_template_key']) : '',
                'premium_manual_override' => !empty($payload['premium_manual_override']) ? '1' : '0',
                'premium_notes' => isset($payload['premium_notes']) ? sanitize_textarea_field($payload['premium_notes']) : '',
                'internal_notes' => isset($payload['internal_notes']) ? sanitize_textarea_field($payload['internal_notes']) : '',
                'is_featured' => $is_featured,
                'featured_order' => (string) $featured_order,
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

        private static function validate_profile_required(array $clean)
        {
            foreach (self::profile_core_fields() as $key => $meta) {
                if (empty($meta['required'])) {
                    continue;
                }
                if (!isset($clean[$key]) || '' === trim((string) $clean[$key])) {
                    return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'madextra-citations'), $key));
                }
            }
            if (!empty($clean['business_website_url']) && !wp_http_validate_url($clean['business_website_url'])) {
                return new WP_Error('invalid_business_website_url', __('Invalid business website URL.', 'madextra-citations'));
            }
            if (!empty($clean['self_serve_cta_url']) && !wp_http_validate_url($clean['self_serve_cta_url'])) {
                return new WP_Error('invalid_self_serve_cta_url', __('Invalid self-serve CTA URL.', 'madextra-citations'));
            }
            if (!empty($clean['primary_cta_url']) && !wp_http_validate_url($clean['primary_cta_url'])) {
                return new WP_Error('invalid_primary_cta_url', __('Invalid primary CTA URL.', 'madextra-citations'));
            }
            if (!empty($clean['secondary_cta_url']) && !wp_http_validate_url($clean['secondary_cta_url'])) {
                return new WP_Error('invalid_secondary_cta_url', __('Invalid secondary CTA URL.', 'madextra-citations'));
            }
            if (!empty($clean['business_email']) && !is_email($clean['business_email'])) {
                return new WP_Error('invalid_business_email', __('Invalid business email address.', 'madextra-citations'));
            }
            return true;
        }

        private static function can_edit_profile($post_id, $user_id = 0)
        {
            $user_id = $user_id ? (int) $user_id : get_current_user_id();
            if (!$user_id) {
                return false;
            }
            if (self::has_admin_fallback() || current_user_can('manage_citation_profiles')) {
                return true;
            }
            return (int) get_post_field('post_author', $post_id) === $user_id;
        }

        private static function can_delete_profile($post_id, $user_id = 0)
        {
            return self::can_edit_profile($post_id, $user_id);
        }

        private static function upsert_profile($post_id, array $payload, $author_id = 0)
        {
            $clean = self::sanitize_profile_payload($payload);
            if (!self::has_admin_fallback() && !current_user_can('manage_citation_profiles')) {
                $clean['internal_notes'] = '';
                $clean['is_featured'] = '0';
                $clean['featured_order'] = '0';
            }
            $validation = self::validate_profile_required($clean);
            if (is_wp_error($validation)) {
                return $validation;
            }

            $market_ids = isset($payload['mec_markets']) ? array_values(array_filter(array_map('intval', (array) $payload['mec_markets']))) : array();
            $service_ids = isset($payload['mec_services']) ? array_values(array_filter(array_map('intval', (array) $payload['mec_services']))) : array();
            if (method_exists('MadExtra_Citations_Plugin', 'validate_featured_slot')) {
                $featured_validation = MadExtra_Citations_Plugin::validate_featured_slot($post_id, $clean, $market_ids);
                if (is_wp_error($featured_validation)) {
                    return $featured_validation;
                }
            }
            if (method_exists('MadExtra_Citations_Plugin', 'validate_duplicate_profile')) {
                $duplicate_validation = MadExtra_Citations_Plugin::validate_duplicate_profile($post_id, $clean);
                if (is_wp_error($duplicate_validation)) {
                    return $duplicate_validation;
                }
            }

            $post_args = array(
                'post_type' => MadExtra_Citations_Plugin::CPT,
                'post_status' => 'publish',
                'post_title' => method_exists('MadExtra_Citations_Plugin', 'admin_business_title')
                    ? MadExtra_Citations_Plugin::admin_business_title($clean)
                    : $clean['directory_name'],
            );
            if ($post_id) {
                $post_args['ID'] = (int) $post_id;
                $result = wp_update_post($post_args, true);
            } else {
                $post_args['post_author'] = $author_id ? (int) $author_id : get_current_user_id();
                $result = wp_insert_post($post_args, true);
            }

            if (is_wp_error($result)) {
                return $result;
            }

            $profile_id = (int) $result;
            foreach ($clean as $key => $value) {
                update_post_meta($profile_id, MadExtra_Citations_Plugin::META_PREFIX . $key, $value);
            }

            wp_set_object_terms($profile_id, $market_ids, MadExtra_Citations_Plugin::TAX_MARKET, false);
            wp_set_object_terms($profile_id, $service_ids, MadExtra_Citations_Plugin::TAX_SERVICE, false);

            $dynamic_fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            $dynamic_payload = isset($payload['mec_dyn']) && is_array($payload['mec_dyn']) ? $payload['mec_dyn'] : array();
            foreach ($dynamic_fields as $field_key => $field) {
                $value = isset($dynamic_payload[$field_key]) ? $dynamic_payload[$field_key] : '';
                update_post_meta($profile_id, self::dynamic_meta_key($field_key), self::sanitize_dynamic_field_value($field, $value));
            }

            $relations = self::get_relation_definitions(MadExtra_Citations_Plugin::CPT);
            $relation_payload = isset($payload['mec_rel']) && is_array($payload['mec_rel']) ? $payload['mec_rel'] : array();
            foreach ($relations as $rel_id => $relation) {
                $relation_key = isset($relation['relation_key']) ? $relation['relation_key'] : $rel_id;
                $selected = isset($relation_payload[$relation_key]) ? (array) $relation_payload[$relation_key] : array();
                $selected = array_values(array_filter(array_map('intval', $selected)));
                if ('one_to_one' === (isset($relation['relation_type']) ? $relation['relation_type'] : '')) {
                    $selected = $selected ? array((int) $selected[0]) : array();
                }
                update_post_meta($profile_id, self::relation_meta_key($relation_key), $selected);
            }

            return $profile_id;
        }

        public static function handle_dashboard_profile_save()
        {
            if (!is_user_logged_in()) {
                wp_die(esc_html__('You must be logged in.', 'madextra-citations'));
            }
            if (!self::has_admin_fallback() && !current_user_can('submit_citation_profiles') && !current_user_can('manage_citation_profiles')) {
                wp_die(esc_html__('You do not have permission.', 'madextra-citations'));
            }

            if (!isset($_POST[self::NONCE_DASHBOARD]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_DASHBOARD])), 'mec_profile_save')) {
                wp_die(esc_html__('Invalid request.', 'madextra-citations'));
            }

            $post_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
            if ($post_id && !self::can_edit_profile($post_id)) {
                wp_die(esc_html__('You cannot edit this profile.', 'madextra-citations'));
            }

            $payload = isset($_POST['mec']) ? (array) wp_unslash($_POST['mec']) : array();
            $payload['mec_markets'] = isset($_POST['mec_markets']) ? (array) wp_unslash($_POST['mec_markets']) : array();
            $payload['mec_services'] = isset($_POST['mec_services']) ? (array) wp_unslash($_POST['mec_services']) : array();
            $payload['mec_dyn'] = isset($_POST['mec_dyn']) ? (array) wp_unslash($_POST['mec_dyn']) : array();
            $payload['mec_rel'] = isset($_POST['mec_rel']) ? (array) wp_unslash($_POST['mec_rel']) : array();

            $result = self::upsert_profile($post_id, $payload, get_current_user_id());
            $redirect = isset($_POST['mec_redirect']) ? esc_url_raw(wp_unslash($_POST['mec_redirect'])) : home_url('/');
            if (is_wp_error($result)) {
                $redirect = add_query_arg('mec_dash_notice', 'error', $redirect);
                wp_safe_redirect($redirect);
                exit;
            }

            $redirect = add_query_arg('mec_dash_notice', 'saved', $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        public static function handle_dashboard_profile_delete()
        {
            if (!is_user_logged_in()) {
                wp_die(esc_html__('You must be logged in.', 'madextra-citations'));
            }
            if (!self::has_admin_fallback() && !current_user_can('submit_citation_profiles') && !current_user_can('manage_citation_profiles')) {
                wp_die(esc_html__('You do not have permission.', 'madextra-citations'));
            }
            if (!isset($_POST[self::NONCE_DASHBOARD]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_DASHBOARD])), 'mec_profile_delete')) {
                wp_die(esc_html__('Invalid request.', 'madextra-citations'));
            }
            $post_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
            if (!$post_id || !self::can_delete_profile($post_id)) {
                wp_die(esc_html__('You cannot delete this profile.', 'madextra-citations'));
            }

            wp_trash_post($post_id);
            $redirect = isset($_POST['mec_redirect']) ? esc_url_raw(wp_unslash($_POST['mec_redirect'])) : home_url('/');
            wp_safe_redirect(add_query_arg('mec_dash_notice', 'deleted', $redirect));
            exit;
        }

        public static function render_profile_dashboard_shortcode($atts)
        {
            if (!is_user_logged_in()) {
                return '<p>' . sprintf(
                    esc_html__('Please %1$slog in%2$s to manage citation profiles.', 'madextra-citations'),
                    '<a href="' . esc_url(wp_login_url(get_permalink())) . '">',
                    '</a>'
                ) . '</p>';
            }
            if (!self::has_admin_fallback() && !current_user_can('submit_citation_profiles') && !current_user_can('manage_citation_profiles')) {
                return '<p>' . esc_html__('You do not have dashboard access.', 'madextra-citations') . '</p>';
            }

            $atts = shortcode_atts(
                array(
                    'form' => 'default-dashboard',
                ),
                $atts,
                'mec_profile_dashboard'
            );

            $form = self::get_entity('forms', sanitize_key($atts['form']));
            if (!$form) {
                $forms = self::get_entities('forms');
                $form = $forms ? reset($forms) : array();
            }
            $allowed_fields = isset($form['allowed_fields']) && is_array($form['allowed_fields']) ? $form['allowed_fields'] : array();
            if (!$allowed_fields) {
                $allowed_fields = array('directory_name', 'listing_url', 'status', 'last_verified_date', 'public_notes', 'nap_business_name', 'nap_address', 'nap_phone', 'business_website_url', 'business_email', 'business_description', 'business_hours', 'address_street', 'address_city', 'address_state', 'address_zip', 'is_featured', 'featured_order');
            }

            $editing_id = isset($_GET['mec_edit']) ? (int) $_GET['mec_edit'] : 0;
            if ($editing_id && !self::can_edit_profile($editing_id)) {
                $editing_id = 0;
            }
            $values = self::default_profile_form_values();
            $selected_markets = array();
            $selected_services = array();
            $dynamic_values = array();

            if ($editing_id) {
                $values = self::load_profile_form_values($editing_id);
                $selected_markets = wp_get_object_terms($editing_id, MadExtra_Citations_Plugin::TAX_MARKET, array('fields' => 'ids'));
                $selected_services = wp_get_object_terms($editing_id, MadExtra_Citations_Plugin::TAX_SERVICE, array('fields' => 'ids'));
                if (is_wp_error($selected_markets)) {
                    $selected_markets = array();
                }
                if (is_wp_error($selected_services)) {
                    $selected_services = array();
                }
                $dynamic_defs = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
                foreach ($dynamic_defs as $field_key => $def) {
                    $dynamic_values[$field_key] = get_post_meta($editing_id, self::dynamic_meta_key($field_key), true);
                }
            }

            $query_args = array(
                'post_type' => MadExtra_Citations_Plugin::CPT,
                'post_status' => array('publish', 'draft', 'pending'),
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            );
            if (!self::has_admin_fallback() && !current_user_can('manage_citation_profiles')) {
                $query_args['author'] = get_current_user_id();
            }
            $query = new WP_Query($query_args);

            $markets = get_terms(array('taxonomy' => MadExtra_Citations_Plugin::TAX_MARKET, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $services = get_terms(array('taxonomy' => MadExtra_Citations_Plugin::TAX_SERVICE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $dynamic_fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            $notice = isset($_GET['mec_dash_notice']) ? sanitize_key(wp_unslash($_GET['mec_dash_notice'])) : '';
            $current_url = self::current_url();

            ob_start();
            ?>
            <div class="mec-dash-wrap">
                <?php if ('saved' === $notice) : ?><div class="mec-dash-note mec-dash-note-success"><?php esc_html_e('Profile saved.', 'madextra-citations'); ?></div><?php endif; ?>
                <?php if ('deleted' === $notice) : ?><div class="mec-dash-note mec-dash-note-success"><?php esc_html_e('Profile deleted.', 'madextra-citations'); ?></div><?php endif; ?>
                <?php if ('error' === $notice) : ?><div class="mec-dash-note mec-dash-note-error"><?php esc_html_e('Profile save failed. Check required fields.', 'madextra-citations'); ?></div><?php endif; ?>

                <div class="mec-dash-grid">
                    <div class="mec-dash-list">
                        <h3><?php esc_html_e('Your Profiles', 'madextra-citations'); ?></h3>
                        <table class="mec-mini-table">
                            <thead><tr><th><?php esc_html_e('Directory', 'madextra-citations'); ?></th><th><?php esc_html_e('Status', 'madextra-citations'); ?></th><th><?php esc_html_e('Actions', 'madextra-citations'); ?></th></tr></thead>
                            <tbody>
                            <?php if (!$query->have_posts()) : ?>
                                <tr><td colspan="3"><?php esc_html_e('No profiles yet.', 'madextra-citations'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($query->posts as $profile_post) : ?>
                                    <?php
                                    $status = get_post_meta($profile_post->ID, MadExtra_Citations_Plugin::META_PREFIX . 'status', true);
                                    $edit_url = add_query_arg('mec_edit', (int) $profile_post->ID, $current_url);
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html(get_post_meta($profile_post->ID, MadExtra_Citations_Plugin::META_PREFIX . 'directory_name', true)); ?></td>
                                        <td><?php echo esc_html($status ? $status : '-'); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'madextra-citations'); ?></a>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                <input type="hidden" name="action" value="mec_profile_delete">
                                                <input type="hidden" name="profile_id" value="<?php echo esc_attr((string) $profile_post->ID); ?>">
                                                <input type="hidden" name="mec_redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <?php wp_nonce_field('mec_profile_delete', self::NONCE_DASHBOARD); ?>
                                                <button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js(__('Delete this profile?', 'madextra-citations')); ?>');"><?php esc_html_e('Delete', 'madextra-citations'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mec-dash-form">
                        <h3><?php echo $editing_id ? esc_html__('Edit Profile', 'madextra-citations') : esc_html__('Add Profile', 'madextra-citations'); ?></h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="mec_profile_save">
                            <input type="hidden" name="profile_id" value="<?php echo esc_attr((string) $editing_id); ?>">
                            <input type="hidden" name="mec_redirect" value="<?php echo esc_attr($current_url); ?>">
                            <?php wp_nonce_field('mec_profile_save', self::NONCE_DASHBOARD); ?>

                            <?php self::render_dashboard_core_fields($allowed_fields, $values); ?>

                            <label><?php esc_html_e('Markets', 'madextra-citations'); ?></label>
                            <select name="mec_markets[]" multiple>
                                <?php if (!is_wp_error($markets)) : ?>
                                    <?php foreach ($markets as $term) : ?>
                                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected(in_array((int) $term->term_id, array_map('intval', (array) $selected_markets), true)); ?>><?php echo esc_html($term->name); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>

                            <label><?php esc_html_e('Services', 'madextra-citations'); ?></label>
                            <select name="mec_services[]" multiple>
                                <?php if (!is_wp_error($services)) : ?>
                                    <?php foreach ($services as $term) : ?>
                                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected(in_array((int) $term->term_id, array_map('intval', (array) $selected_services), true)); ?>><?php echo esc_html($term->name); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>

                            <?php foreach ($dynamic_fields as $field_key => $field) : ?>
                                <?php
                                if (!self::passes_visibility_rules(isset($field['visibility_rules']) ? $field['visibility_rules'] : '', $values)) {
                                    continue;
                                }
                                $value = isset($dynamic_values[$field_key]) ? $dynamic_values[$field_key] : '';
                                ?>
                                <label><?php echo esc_html(isset($field['field_label']) ? $field['field_label'] : $field_key); ?></label>
                                <?php self::render_dynamic_field_input('mec_dyn[' . $field_key . ']', 'mec_dash_dyn_' . $field_key, $field, $value); ?>
                            <?php endforeach; ?>

                            <button type="submit"><?php echo $editing_id ? esc_html__('Update Profile', 'madextra-citations') : esc_html__('Create Profile', 'madextra-citations'); ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <style>
                .mec-dash-wrap { display:grid; gap:16px; }
                .mec-dash-note { padding:10px 12px; border-radius:8px; font-weight:600; }
                .mec-dash-note-success { background:#ecfdf3; color:#0a6b38; border:1px solid #a7f3d0; }
                .mec-dash-note-error { background:#fff5f5; color:#a4001d; border:1px solid #fecaca; }
                .mec-dash-grid { display:grid; gap:16px; grid-template-columns:1.1fr 1fr; }
                .mec-dash-list, .mec-dash-form { background:#fff; border:1px solid #d8e1f0; border-radius:10px; padding:14px; }
                .mec-mini-table { width:100%; border-collapse:collapse; }
                .mec-mini-table th, .mec-mini-table td { border-bottom:1px solid #edf1fb; padding:9px; text-align:left; }
                .mec-dash-form form { display:grid; gap:10px; }
                .mec-dash-form input[type="text"], .mec-dash-form input[type="url"], .mec-dash-form input[type="date"], .mec-dash-form textarea, .mec-dash-form select { width:100%; border:1px solid #ccd8ec; border-radius:8px; padding:9px; font:inherit; }
                .mec-dash-form select[multiple] { min-height:96px; }
                .mec-dash-form button { border:0; border-radius:8px; background:#1b4dd8; color:#fff; padding:10px 12px; font-weight:700; cursor:pointer; }
                @media (max-width: 920px) { .mec-dash-grid { grid-template-columns:1fr; } }
            </style>
            <?php
            return ob_get_clean();
        }

        private static function render_dashboard_core_fields(array $allowed_fields, array $values)
        {
            $status_options = self::status_options();
            $allowed = array_fill_keys($allowed_fields, true);

            if (isset($allowed['directory_name'])) {
                echo '<label>' . esc_html__('Directory Name', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[directory_name]" value="' . esc_attr($values['directory_name']) . '" required>';
            }
            if (isset($allowed['listing_url'])) {
                echo '<label>' . esc_html__('Listing URL', 'madextra-citations') . '</label>';
                echo '<input type="url" name="mec[listing_url]" value="' . esc_attr($values['listing_url']) . '" required>';
            }
            if (isset($allowed['status'])) {
                echo '<label>' . esc_html__('Status', 'madextra-citations') . '</label>';
                echo '<select name="mec[status]">';
                foreach ($status_options as $status) {
                    echo '<option value="' . esc_attr($status) . '" ' . selected($values['status'], $status, false) . '>' . esc_html(ucwords(str_replace('_', ' ', $status))) . '</option>';
                }
                echo '</select>';
            }
            if (isset($allowed['last_verified_date'])) {
                echo '<label>' . esc_html__('Last Verified Date', 'madextra-citations') . '</label>';
                echo '<input type="date" name="mec[last_verified_date]" value="' . esc_attr($values['last_verified_date']) . '">';
            }
            if (isset($allowed['public_notes'])) {
                echo '<label>' . esc_html__('Public Notes', 'madextra-citations') . '</label>';
                echo '<textarea name="mec[public_notes]" rows="3">' . esc_textarea($values['public_notes']) . '</textarea>';
            }
            if (isset($allowed['nap_business_name'])) {
                echo '<label>' . esc_html__('NAP Business Name', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[nap_business_name]" value="' . esc_attr($values['nap_business_name']) . '" required>';
            }
            if (isset($allowed['nap_address'])) {
                echo '<label>' . esc_html__('NAP Address', 'madextra-citations') . '</label>';
                echo '<textarea name="mec[nap_address]" rows="2" required>' . esc_textarea($values['nap_address']) . '</textarea>';
            }
            if (isset($allowed['nap_phone'])) {
                echo '<label>' . esc_html__('NAP Phone', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[nap_phone]" value="' . esc_attr($values['nap_phone']) . '" required>';
            }
            if (isset($allowed['business_website_url'])) {
                echo '<label>' . esc_html__('Business Website URL', 'madextra-citations') . '</label>';
                echo '<input type="url" name="mec[business_website_url]" value="' . esc_attr($values['business_website_url']) . '">';
            }
            if (isset($allowed['business_logo_id'])) {
                echo '<label>' . esc_html__('Business Logo Attachment ID', 'madextra-citations') . '</label>';
                echo '<input type="number" min="0" step="1" name="mec[business_logo_id]" value="' . esc_attr($values['business_logo_id']) . '">';
            }
            if (isset($allowed['business_email'])) {
                echo '<label>' . esc_html__('Business Email', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[business_email]" value="' . esc_attr($values['business_email']) . '">';
            }
            if (isset($allowed['business_description'])) {
                echo '<label>' . esc_html__('Business Description', 'madextra-citations') . '</label>';
                echo '<textarea name="mec[business_description]" rows="3">' . esc_textarea($values['business_description']) . '</textarea>';
            }
            if (isset($allowed['business_hours'])) {
                echo '<label>' . esc_html__('Business Hours', 'madextra-citations') . '</label>';
                echo '<textarea name="mec[business_hours]" rows="3">' . esc_textarea($values['business_hours']) . '</textarea>';
            }
            if (isset($allowed['address_street'])) {
                echo '<label>' . esc_html__('Street Address', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[address_street]" value="' . esc_attr($values['address_street']) . '">';
            }
            if (isset($allowed['address_city'])) {
                echo '<label>' . esc_html__('City', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[address_city]" value="' . esc_attr($values['address_city']) . '">';
            }
            if (isset($allowed['address_state'])) {
                echo '<label>' . esc_html__('State', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[address_state]" value="' . esc_attr($values['address_state']) . '">';
            }
            if (isset($allowed['address_zip'])) {
                echo '<label>' . esc_html__('ZIP Code', 'madextra-citations') . '</label>';
                echo '<input type="text" name="mec[address_zip]" value="' . esc_attr($values['address_zip']) . '">';
            }
            if (isset($allowed['internal_notes']) && current_user_can('manage_citation_profiles')) {
                echo '<label>' . esc_html__('Internal Notes', 'madextra-citations') . '</label>';
                echo '<textarea name="mec[internal_notes]" rows="3">' . esc_textarea($values['internal_notes']) . '</textarea>';
            }
            if (isset($allowed['is_featured'])) {
                echo '<label><input type="checkbox" name="mec[is_featured]" value="1" ' . checked($values['is_featured'], '1', false) . '> ' . esc_html__('Featured', 'madextra-citations') . '</label>';
            }
            if (isset($allowed['featured_order'])) {
                echo '<label>' . esc_html__('Featured Slot', 'madextra-citations') . '</label>';
                echo '<select name="mec[featured_order]">';
                foreach (array(0, 1, 2, 3) as $slot) {
                    $label = 0 === $slot ? __('Not featured', 'madextra-citations') : sprintf(__('Featured position %d', 'madextra-citations'), $slot);
                    echo '<option value="' . esc_attr((string) $slot) . '" ' . selected((int) $values['featured_order'], $slot, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            }
        }

        private static function default_profile_form_values()
        {
            return array(
                'directory_name' => '',
                'listing_url' => '',
                'status' => 'pending',
                'last_verified_date' => '',
                'public_notes' => '',
                'nap_business_name' => '',
                'nap_address' => '',
                'nap_phone' => '',
                'business_website_url' => '',
                'business_logo_id' => '0',
                'business_email' => '',
                'business_description' => '',
                'business_hours' => '',
                'address_street' => '',
                'address_city' => '',
                'address_state' => '',
                'address_zip' => '',
                'self_serve_enabled' => '0',
                'self_serve_cta_label' => '',
                'self_serve_cta_url' => '',
                'self_serve_price_text' => '',
                'public_profile_page_id' => '0',
                'is_premium' => '0',
                'service_areas' => '',
                'faq_items' => '',
                'social_links' => '',
                'gallery_media_ids' => '',
                'primary_cta_label' => '',
                'primary_cta_url' => '',
                'secondary_cta_label' => '',
                'secondary_cta_url' => '',
                'premium_hero_text' => '',
                'premium_subheadline' => '',
                'extended_about_copy' => '',
                'services_summary' => '',
                'service_cards' => '',
                'premium_badge_text' => '',
                'premium_page_mode' => '',
                'premium_page_status' => '',
                'premium_last_generated_at' => '',
                'premium_layout_template_key' => '',
                'premium_manual_override' => '0',
                'premium_notes' => '',
                'internal_notes' => '',
                'is_featured' => '0',
                'featured_order' => '0',
            );
        }

        private static function load_profile_form_values($post_id)
        {
            $values = self::default_profile_form_values();
            foreach ($values as $key => $default) {
                $values[$key] = get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . $key, true);
                if ('' === (string) $values[$key]) {
                    $values[$key] = $default;
                }
            }
            return $values;
        }

        private static function current_url()
        {
            global $wp;
            $request_path = '';
            if (isset($wp) && is_object($wp) && isset($wp->request)) {
                $request_path = (string) $wp->request;
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $request_path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
            }
            $base = home_url('/' . ltrim((string) $request_path, '/'));
            $query = array();
            foreach (array('mec_edit') as $key) {
                if (isset($_GET[$key])) {
                    $query[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
                }
            }
            return add_query_arg($query, $base);
        }

        public static function register_rest_routes()
        {
            $map = self::entity_map();
            $route_map = array(
                'field-groups' => 'field_groups',
                'templates' => 'templates',
                'queries' => 'queries',
                'forms' => 'forms',
                'pages' => 'pages',
                'relations' => 'relations',
            );

            foreach ($route_map as $route_slug => $type) {
                if (!isset($map[$type])) {
                    continue;
                }
                register_rest_route(
                    self::REST_NS,
                    '/' . $route_slug,
                    array(
                        array(
                            'methods' => WP_REST_Server::READABLE,
                            'callback' => function () use ($type) {
                                return rest_ensure_response(array('items' => array_values(self::get_entities($type))));
                            },
                            'permission_callback' => function () use ($type) {
                                return self::can_manage_entity($type);
                            },
                        ),
                        array(
                            'methods' => WP_REST_Server::CREATABLE,
                            'callback' => function (WP_REST_Request $request) use ($type) {
                                $entity = self::sanitize_entity($type, (array) $request->get_json_params());
                                self::save_entity($type, $entity);
                                return rest_ensure_response($entity);
                            },
                            'permission_callback' => function () use ($type) {
                                return self::can_manage_entity($type);
                            },
                        ),
                    )
                );

                register_rest_route(
                    self::REST_NS,
                    '/' . $route_slug . '/(?P<id>[a-zA-Z0-9_-]+)',
                    array(
                        array(
                            'methods' => WP_REST_Server::READABLE,
                            'callback' => function (WP_REST_Request $request) use ($type) {
                                $id = sanitize_key((string) $request['id']);
                                $entity = self::get_entity($type, $id);
                                if (!$entity) {
                                    return new WP_Error('not_found', __('Entity not found.', 'madextra-citations'), array('status' => 404));
                                }
                                return rest_ensure_response($entity);
                            },
                            'permission_callback' => function () use ($type) {
                                return self::can_manage_entity($type);
                            },
                        ),
                        array(
                            'methods' => WP_REST_Server::EDITABLE,
                            'callback' => function (WP_REST_Request $request) use ($type) {
                                $payload = (array) $request->get_json_params();
                                $payload['id'] = sanitize_key((string) $request['id']);
                                $entity = self::sanitize_entity($type, $payload);
                                self::save_entity($type, $entity);
                                return rest_ensure_response($entity);
                            },
                            'permission_callback' => function () use ($type) {
                                return self::can_manage_entity($type);
                            },
                        ),
                        array(
                            'methods' => WP_REST_Server::DELETABLE,
                            'callback' => function (WP_REST_Request $request) use ($type) {
                                $id = sanitize_key((string) $request['id']);
                                self::delete_entity($type, $id);
                                return rest_ensure_response(array('deleted' => $id));
                            },
                            'permission_callback' => function () use ($type) {
                                return self::can_manage_entity($type);
                            },
                        ),
                    )
                );
            }

            register_rest_route(
                self::REST_NS,
                '/profiles',
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(__CLASS__, 'rest_create_profile'),
                    'permission_callback' => function () {
                        return self::has_admin_fallback() || current_user_can('submit_citation_profiles') || current_user_can('manage_citation_profiles');
                    },
                )
            );

            register_rest_route(
                self::REST_NS,
                '/profiles/(?P<id>\d+)',
                array(
                    array(
                        'methods' => WP_REST_Server::EDITABLE,
                        'callback' => array(__CLASS__, 'rest_update_profile'),
                        'permission_callback' => function (WP_REST_Request $request) {
                            $post_id = (int) $request['id'];
                            return self::has_admin_fallback() || current_user_can('manage_citation_profiles') || self::can_edit_profile($post_id);
                        },
                    ),
                    array(
                        'methods' => WP_REST_Server::DELETABLE,
                        'callback' => array(__CLASS__, 'rest_delete_profile'),
                        'permission_callback' => function (WP_REST_Request $request) {
                            $post_id = (int) $request['id'];
                            return self::has_admin_fallback() || current_user_can('manage_citation_profiles') || self::can_delete_profile($post_id);
                        },
                    ),
                )
            );
        }

        public static function rest_create_profile(WP_REST_Request $request)
        {
            $payload = (array) $request->get_json_params();
            $result = self::upsert_profile(0, $payload, get_current_user_id());
            if (is_wp_error($result)) {
                return $result;
            }
            return rest_ensure_response(self::profile_to_api_data((int) $result));
        }

        public static function rest_update_profile(WP_REST_Request $request)
        {
            $post_id = (int) $request['id'];
            $payload = (array) $request->get_json_params();
            $result = self::upsert_profile($post_id, $payload, get_current_user_id());
            if (is_wp_error($result)) {
                return $result;
            }
            return rest_ensure_response(self::profile_to_api_data((int) $result));
        }

        public static function rest_delete_profile(WP_REST_Request $request)
        {
            $post_id = (int) $request['id'];
            if (!$post_id || MadExtra_Citations_Plugin::CPT !== get_post_type($post_id)) {
                return new WP_Error('invalid_profile', __('Invalid profile ID.', 'madextra-citations'), array('status' => 404));
            }
            wp_trash_post($post_id);
            return rest_ensure_response(array('deleted' => $post_id));
        }

        private static function profile_to_api_data($post_id)
        {
            $markets = wp_get_object_terms($post_id, MadExtra_Citations_Plugin::TAX_MARKET, array('fields' => 'slugs'));
            $services = wp_get_object_terms($post_id, MadExtra_Citations_Plugin::TAX_SERVICE, array('fields' => 'slugs'));
            if (is_wp_error($markets)) {
                $markets = array();
            }
            if (is_wp_error($services)) {
                $services = array();
            }
            $logo_id = (int) get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'business_logo_id', true);
            $public_page_id = (int) get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'public_profile_page_id', true);

            $data = array(
                'id' => (int) $post_id,
                'title' => get_the_title($post_id),
                'directory_name' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'directory_name', true),
                'listing_url' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'listing_url', true),
                'status' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'status', true),
                'last_verified_date' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'last_verified_date', true),
                'public_notes' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'public_notes', true),
                'nap_business_name' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'nap_business_name', true),
                'nap_address' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'nap_address', true),
                'nap_phone' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'nap_phone', true),
                'business_website_url' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'business_website_url', true),
                'business_logo_id' => (string) $logo_id,
                'business_logo_url' => $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '',
                'business_email' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'business_email', true),
                'business_description' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'business_description', true),
                'business_hours' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'business_hours', true),
                'address_street' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'address_street', true),
                'address_city' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'address_city', true),
                'address_state' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'address_state', true),
                'address_zip' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'address_zip', true),
                'self_serve_enabled' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'self_serve_enabled', true),
                'self_serve_cta_label' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'self_serve_cta_label', true),
                'self_serve_cta_url' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'self_serve_cta_url', true),
                'self_serve_price_text' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'self_serve_price_text', true),
                'public_profile_page_id' => (string) $public_page_id,
                'public_profile_page_url' => $public_page_id ? get_permalink($public_page_id) : '',
                'is_premium' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'is_premium', true),
                'service_areas' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'service_areas', true),
                'faq_items' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'faq_items', true),
                'social_links' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'social_links', true),
                'gallery_media_ids' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'gallery_media_ids', true),
                'primary_cta_label' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'primary_cta_label', true),
                'primary_cta_url' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'primary_cta_url', true),
                'secondary_cta_label' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'secondary_cta_label', true),
                'secondary_cta_url' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'secondary_cta_url', true),
                'premium_hero_text' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_hero_text', true),
                'premium_subheadline' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_subheadline', true),
                'extended_about_copy' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'extended_about_copy', true),
                'services_summary' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'services_summary', true),
                'service_cards' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'service_cards', true),
                'premium_badge_text' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_badge_text', true),
                'premium_page_mode' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_page_mode', true),
                'premium_page_status' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_page_status', true),
                'premium_last_generated_at' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_last_generated_at', true),
                'premium_layout_template_key' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_layout_template_key', true),
                'premium_manual_override' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'premium_manual_override', true),
                'is_featured' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'is_featured', true),
                'featured_order' => get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . 'featured_order', true),
                'markets' => array_values((array) $markets),
                'services' => array_values((array) $services),
                'dynamic' => array(),
                'relations' => array(),
            );
            $data['display_address'] = self::compose_full_address($data);
            if ('' === $data['display_address']) {
                $data['display_address'] = $data['nap_address'];
            }

            $fields = self::get_dynamic_fields_for_target(MadExtra_Citations_Plugin::CPT);
            foreach ($fields as $field_key => $field) {
                $data['dynamic'][$field_key] = get_post_meta($post_id, self::dynamic_meta_key($field_key), true);
            }

            $relations = self::get_relation_definitions(MadExtra_Citations_Plugin::CPT);
            foreach ($relations as $rel_id => $relation) {
                $relation_key = isset($relation['relation_key']) ? $relation['relation_key'] : $rel_id;
                $value = get_post_meta($post_id, self::relation_meta_key($relation_key), true);
                $data['relations'][$relation_key] = is_array($value) ? array_values(array_map('intval', $value)) : array();
            }

            return $data;
        }

        private static function parse_visibility_rules($raw_json)
        {
            if (!$raw_json) {
                return array();
            }
            $decoded = json_decode((string) $raw_json, true);
            return is_array($decoded) ? $decoded : array();
        }

        private static function profile_payload_for_visibility($post_id)
        {
            $payload = self::load_profile_form_values($post_id);
            $markets = wp_get_object_terms($post_id, MadExtra_Citations_Plugin::TAX_MARKET, array('fields' => 'slugs'));
            $services = wp_get_object_terms($post_id, MadExtra_Citations_Plugin::TAX_SERVICE, array('fields' => 'slugs'));
            $payload['markets'] = !is_wp_error($markets) ? array_values((array) $markets) : array();
            $payload['services'] = !is_wp_error($services) ? array_values((array) $services) : array();
            return $payload;
        }

        private static function passes_visibility_rules($raw_json, array $context)
        {
            $rules = self::parse_visibility_rules($raw_json);
            if (!$rules) {
                return true;
            }
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $field = isset($rule['field']) ? sanitize_key($rule['field']) : '';
                $operator = isset($rule['operator']) ? sanitize_key($rule['operator']) : 'eq';
                $expected = isset($rule['value']) ? (string) $rule['value'] : '';
                if ('' === $field) {
                    continue;
                }
                $actual = isset($context[$field]) ? $context[$field] : '';

                if ('eq' === $operator && (string) $actual !== $expected) {
                    return false;
                }
                if ('neq' === $operator && (string) $actual === $expected) {
                    return false;
                }
                if ('contains' === $operator) {
                    $haystack = is_array($actual) ? implode(',', $actual) : (string) $actual;
                    if (false === stripos($haystack, $expected)) {
                        return false;
                    }
                }
                if ('in' === $operator) {
                    $expected_list = self::ensure_list_values($expected);
                    if (is_array($actual)) {
                        if (!array_intersect($expected_list, array_map('strval', $actual))) {
                            return false;
                        }
                    } elseif (!in_array((string) $actual, $expected_list, true)) {
                        return false;
                    }
                }
            }
            return true;
        }

        private static function resolve_template($id)
        {
            $id = sanitize_key((string) $id);
            if ($id) {
                $template = self::get_entity('templates', $id);
                if ($template) {
                    return $template;
                }
            }
            $templates = self::get_entities('templates');
            if ($templates) {
                return reset($templates);
            }
            return array(
                'style' => 'table',
                'columns' => array('nap_business_name', 'services', 'business_website_url', 'nap_phone', 'display_address', 'listing_url'),
                'show_filters' => '1',
                'visibility_rules' => '',
            );
        }

        private static function resolve_query($id)
        {
            $id = sanitize_key((string) $id);
            if ($id) {
                $query = self::get_entity('queries', $id);
                if ($query) {
                    return $query;
                }
            }
            $queries = self::get_entities('queries');
            if ($queries) {
                return reset($queries);
            }
            return array('per_page' => 25, 'orderby' => 'title', 'order' => 'ASC');
        }

        private static function parse_request_filters()
        {
            return array(
                'search' => isset($_GET['mec_q']) ? sanitize_text_field(wp_unslash($_GET['mec_q'])) : '',
                'status' => isset($_GET['mec_status']) ? sanitize_key(wp_unslash($_GET['mec_status'])) : '',
                'market' => isset($_GET['mec_market']) ? sanitize_title(wp_unslash($_GET['mec_market'])) : '',
                'service' => isset($_GET['mec_service']) ? sanitize_title(wp_unslash($_GET['mec_service'])) : '',
                'featured' => isset($_GET['mec_featured']) ? sanitize_key(wp_unslash($_GET['mec_featured'])) : '',
                'date_from' => isset($_GET['mec_date_from']) ? self::sanitize_date(wp_unslash($_GET['mec_date_from'])) : '',
                'date_to' => isset($_GET['mec_date_to']) ? self::sanitize_date(wp_unslash($_GET['mec_date_to'])) : '',
            );
        }

        private static function build_listing_query_args(array $preset, array $overrides = array())
        {
            $filters = array_merge(self::parse_request_filters(), $overrides);

            $per_page = isset($overrides['per_page']) ? (int) $overrides['per_page'] : (isset($preset['per_page']) ? (int) $preset['per_page'] : 25);
            $per_page = min(200, max(1, $per_page));

            $page = isset($overrides['page']) ? (int) $overrides['page'] : (isset($_GET['mec_page']) ? (int) $_GET['mec_page'] : 1);
            $page = max(1, $page);

            $query_args = array(
                'post_type' => MadExtra_Citations_Plugin::CPT,
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'orderby' => isset($preset['orderby']) ? $preset['orderby'] : 'title',
                'order' => isset($preset['order']) ? $preset['order'] : 'ASC',
            );

            $tax_query = array();
            $market_filters = array();
            $service_filters = array();

            if (!empty($preset['market_values']) && is_array($preset['market_values'])) {
                $market_filters = array_map('sanitize_title', $preset['market_values']);
            }
            if (!empty($filters['market'])) {
                $market_filters = array(sanitize_title($filters['market']));
            }
            if ($market_filters) {
                $tax_query[] = array(
                    'taxonomy' => MadExtra_Citations_Plugin::TAX_MARKET,
                    'field' => 'slug',
                    'terms' => $market_filters,
                );
            }

            if (!empty($preset['service_values']) && is_array($preset['service_values'])) {
                $service_filters = array_map('sanitize_title', $preset['service_values']);
            }
            if (!empty($filters['service'])) {
                $service_filters = array(sanitize_title($filters['service']));
            }
            if ($service_filters) {
                $tax_query[] = array(
                    'taxonomy' => MadExtra_Citations_Plugin::TAX_SERVICE,
                    'field' => 'slug',
                    'terms' => $service_filters,
                );
            }
            if ($tax_query) {
                if (count($tax_query) > 1) {
                    $tax_query['relation'] = 'AND';
                }
                $query_args['tax_query'] = $tax_query;
            }

            $meta_query = array();
            $status_values = !empty($preset['status_values']) && is_array($preset['status_values']) ? array_values(array_filter($preset['status_values'])) : array();
            if (!empty($filters['status'])) {
                $status_values = array(sanitize_key($filters['status']));
            }
            if ($status_values) {
                $meta_query[] = array(
                    'key' => MadExtra_Citations_Plugin::META_PREFIX . 'status',
                    'value' => $status_values,
                    'compare' => 'IN',
                );
            }

            $featured = isset($preset['featured_only']) ? $preset['featured_only'] : '';
            if ('' !== $filters['featured']) {
                $featured = '1' === $filters['featured'] ? '1' : '0';
            }
            if ('1' === $featured) {
                $meta_query[] = array(
                    'key' => MadExtra_Citations_Plugin::META_PREFIX . 'is_featured',
                    'value' => '1',
                    'compare' => '=',
                );
            }

            $date_from = !empty($filters['date_from']) ? $filters['date_from'] : (isset($preset['date_from']) ? $preset['date_from'] : '');
            $date_to = !empty($filters['date_to']) ? $filters['date_to'] : (isset($preset['date_to']) ? $preset['date_to'] : '');
            if ($date_from || $date_to) {
                $date_clause = array(
                    'key' => MadExtra_Citations_Plugin::META_PREFIX . 'last_verified_date',
                    'type' => 'DATE',
                    'compare' => 'BETWEEN',
                    'value' => array($date_from ? $date_from : '0001-01-01', $date_to ? $date_to : '9999-12-31'),
                );
                $meta_query[] = $date_clause;
            }

            $meta_key = isset($preset['meta_key']) ? sanitize_key($preset['meta_key']) : '';
            $meta_value = isset($preset['meta_value']) ? (string) $preset['meta_value'] : '';
            if ($meta_key && '' !== $meta_value) {
                $meta_compare = isset($preset['meta_compare']) ? strtoupper((string) $preset['meta_compare']) : '=';
                $meta_query[] = array(
                    'key' => MadExtra_Citations_Plugin::META_PREFIX . $meta_key,
                    'value' => $meta_value,
                    'compare' => $meta_compare,
                );
            }

            $relation_key = isset($preset['relation_key']) ? sanitize_key($preset['relation_key']) : '';
            $relation_targets = isset($preset['relation_targets']) && is_array($preset['relation_targets']) ? array_map('intval', $preset['relation_targets']) : array();
            if ($relation_key && $relation_targets) {
                $relation_meta_key = self::relation_meta_key($relation_key);
                $relation_query = array('relation' => 'OR');
                foreach ($relation_targets as $target_id) {
                    $relation_query[] = array(
                        'key' => $relation_meta_key,
                        'value' => 'i:' . (int) $target_id . ';',
                        'compare' => 'LIKE',
                    );
                }
                $meta_query[] = $relation_query;
            }

            if ($meta_query) {
                if (count($meta_query) > 1) {
                    $meta_query['relation'] = 'AND';
                }
                $query_args['meta_query'] = $meta_query;
            }

            $search = '';
            if (!empty($filters['search'])) {
                $search = $filters['search'];
            } elseif (!empty($preset['search_default'])) {
                $search = $preset['search_default'];
            }
            if ($search) {
                $query_args['s'] = $search;
            }

            return $query_args;
        }

        private static function listing_field_value(array $profile, $field_key)
        {
            if ('services' === $field_key) {
                return !empty($profile['services']) ? implode(', ', $profile['services']) : '-';
            }
            if ('markets' === $field_key) {
                return !empty($profile['markets']) ? implode(', ', $profile['markets']) : '-';
            }
            if ('listing_url' === $field_key) {
                return '-';
            }
            if ('directory_name' === $field_key) {
                return '-';
            }
            if ('business_website_url' === $field_key) {
                if (empty($profile['business_website_url'])) {
                    return '-';
                }
                return '<a href="' . esc_url($profile['business_website_url']) . '" target="_blank" rel="noopener">' . esc_html__('Website', 'madextra-citations') . '</a>';
            }
            if ('public_profile_page_url' === $field_key) {
                if (empty($profile['public_profile_page_url'])) {
                    return '-';
                }
                return '<a href="' . esc_url($profile['public_profile_page_url']) . '">' . esc_html__('Profile', 'madextra-citations') . '</a>';
            }
            if ('display_address' === $field_key) {
                return !empty($profile['display_address']) ? nl2br(esc_html((string) $profile['display_address'])) : '-';
            }
            if ('business_logo_url' === $field_key) {
                if (empty($profile['business_logo_url'])) {
                    return '-';
                }
                return '<img src="' . esc_url($profile['business_logo_url']) . '" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">';
            }
            if (isset($profile[$field_key]) && '' !== (string) $profile[$field_key]) {
                return esc_html((string) $profile[$field_key]);
            }
            if (isset($profile['dynamic'][$field_key]) && '' !== (string) $profile['dynamic'][$field_key]) {
                return esc_html((string) $profile['dynamic'][$field_key]);
            }
            return '-';
        }

        public static function render_filters_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'query' => 'all-profiles',
                    'target' => '',
                    'include_market' => '1',
                ),
                $atts,
                'mec_filters'
            );
            $query_preset = self::resolve_query($atts['query']);
            return self::filters_markup(
                $query_preset,
                $atts['target'],
                array(
                    'include_market' => '0' !== (string) $atts['include_market'],
                )
            );
        }

        private static function filters_markup(array $query_preset, $target_url = '', array $options = array())
        {
            $filters = self::parse_request_filters();
            $markets = get_terms(array('taxonomy' => MadExtra_Citations_Plugin::TAX_MARKET, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $services = get_terms(array('taxonomy' => MadExtra_Citations_Plugin::TAX_SERVICE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
            $target_url = $target_url ? esc_url($target_url) : esc_url(self::current_url());
            $include_market = !isset($options['include_market']) || $options['include_market'];

            ob_start();
            ?>
            <form class="mec-filters-form" method="get" action="<?php echo $target_url; ?>">
                <input type="search" name="mec_q" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Search citations...', 'madextra-citations'); ?>">
                <select name="mec_status">
                    <option value=""><?php esc_html_e('All Statuses', 'madextra-citations'); ?></option>
                    <?php foreach (self::status_options() as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($include_market) : ?>
                    <select name="mec_market">
                        <option value=""><?php esc_html_e('All Markets', 'madextra-citations'); ?></option>
                        <?php if (!is_wp_error($markets)) : ?>
                            <?php foreach ($markets as $term) : ?>
                                <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($filters['market'], $term->slug); ?>><?php echo esc_html($term->name); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                <?php endif; ?>
                <select name="mec_service">
                    <option value=""><?php esc_html_e('All Services', 'madextra-citations'); ?></option>
                    <?php if (!is_wp_error($services)) : ?>
                        <?php foreach ($services as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($filters['service'], $term->slug); ?>><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <select name="mec_featured">
                    <option value=""><?php esc_html_e('All Profiles', 'madextra-citations'); ?></option>
                    <option value="1" <?php selected($filters['featured'], '1'); ?>><?php esc_html_e('Featured Only', 'madextra-citations'); ?></option>
                </select>
                <input type="date" name="mec_date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
                <input type="date" name="mec_date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
                <button type="submit"><?php esc_html_e('Apply', 'madextra-citations'); ?></button>
            </form>
            <style>
                .mec-filters-form { display:grid; gap:10px; grid-template-columns: minmax(220px,1.6fr) repeat(4,minmax(145px,1fr)) minmax(130px,.9fr) minmax(130px,.9fr) auto; margin-bottom:14px; }
                .mec-filters-form input, .mec-filters-form select, .mec-filters-form button { border:1px solid #ced9ee; border-radius:8px; padding:9px; font:inherit; width:100%; }
                .mec-filters-form button { background:#1b4dd8; color:#fff; font-weight:700; cursor:pointer; }
                @media (max-width: 980px) { .mec-filters-form { grid-template-columns:1fr 1fr; } }
            </style>
            <?php
            return ob_get_clean();
        }

        public static function render_listing_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'template' => 'default-table',
                    'query' => 'all-profiles',
                    'per_page' => '',
                    'page' => '',
                    'show_filters' => '',
                    'include_market' => '1',
                ),
                $atts,
                'mec_listing'
            );

            $template = self::resolve_template($atts['template']);
            $query_preset = self::resolve_query($atts['query']);

            $overrides = array();
            if ('' !== (string) $atts['per_page']) {
                $overrides['per_page'] = (int) $atts['per_page'];
            }
            if ('' !== (string) $atts['page']) {
                $overrides['page'] = (int) $atts['page'];
            }

            $query_args = self::build_listing_query_args($query_preset, $overrides);
            $query = new WP_Query($query_args);

            $profiles = array();
            foreach ($query->posts as $post) {
                $profile = self::profile_to_api_data($post->ID);
                if (!self::passes_visibility_rules(isset($template['visibility_rules']) ? $template['visibility_rules'] : '', $profile)) {
                    continue;
                }
                $profiles[] = $profile;
            }

            $columns = isset($template['columns']) && is_array($template['columns']) ? $template['columns'] : array('nap_business_name', 'services', 'status', 'last_verified_date', 'business_website_url', 'public_notes');
            $style = isset($template['style']) ? $template['style'] : 'table';

            ob_start();
            ?>
            <div class="mec-listing-wrap">
                <?php if (('0' !== (string) $atts['show_filters'] && !empty($template['show_filters'])) || '1' === (string) $atts['show_filters']) : ?>
                    <?php echo self::filters_markup($query_preset, '', array('include_market' => '0' !== (string) $atts['include_market'])); ?>
                <?php endif; ?>

                <?php if (!$profiles) : ?>
                    <p><?php esc_html_e('No profiles found for this query.', 'madextra-citations'); ?></p>
                <?php elseif ('cards' === $style) : ?>
                    <div class="mec-cards-grid">
                        <?php foreach ($profiles as $profile) : ?>
                            <article class="mec-card">
                                <?php foreach ($columns as $column_key) : ?>
                                    <div class="mec-card-row">
                                        <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $column_key))); ?>:</strong>
                                        <span><?php echo self::listing_field_value($profile, $column_key); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="mec-table-wrap">
                        <table class="mec-builder-table">
                            <thead>
                            <tr>
                                <?php foreach ($columns as $column_key) : ?>
                                    <th><?php echo esc_html(ucwords(str_replace('_', ' ', $column_key))); ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($profiles as $profile) : ?>
                                <tr>
                                    <?php foreach ($columns as $column_key) : ?>
                                        <td><?php echo self::listing_field_value($profile, $column_key); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ((int) $query->max_num_pages > 1) : ?>
                    <?php
                    $current = max(1, isset($query_args['paged']) ? (int) $query_args['paged'] : 1);
                    $base = add_query_arg('mec_page', '%#%');
                    $links = paginate_links(
                        array(
                            'base' => $base,
                            'format' => '',
                            'current' => $current,
                            'total' => (int) $query->max_num_pages,
                            'type' => 'array',
                        )
                    );
                    ?>
                    <?php if (!empty($links) && is_array($links)) : ?>
                        <nav class="mec-pagination">
                            <?php foreach ($links as $link) : ?>
                                <span class="mec-page-link"><?php echo wp_kses_post($link); ?></span>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <style>
                .mec-listing-wrap { display:grid; gap:14px; }
                .mec-table-wrap { overflow-x:auto; border:1px solid #d8e1f0; border-radius:10px; background:#fff; }
                .mec-builder-table { width:100%; border-collapse:collapse; min-width:720px; }
                .mec-builder-table th, .mec-builder-table td { padding:10px 12px; border-bottom:1px solid #edf1fb; text-align:left; vertical-align:top; }
                .mec-builder-table th { background:#f6f9ff; font-size:.82rem; letter-spacing:.03em; text-transform:uppercase; color:#36517e; }
                .mec-cards-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:12px; }
                .mec-card { background:#fff; border:1px solid #d8e1f0; border-radius:10px; padding:12px; display:grid; gap:6px; }
                .mec-card-row { display:grid; gap:2px; }
                .mec-pagination { display:flex; gap:6px; flex-wrap:wrap; }
                .mec-pagination .page-numbers { border:1px solid #ced9ee; border-radius:6px; padding:6px 9px; text-decoration:none; }
                .mec-pagination .current { background:#1b4dd8; border-color:#1b4dd8; color:#fff; }
            </style>
            <?php
            return ob_get_clean();
        }

        public static function register_elementor_widgets($widgets_manager)
        {
            if (!class_exists('\Elementor\Widget_Base')) {
                return;
            }
            $widgets_file = __DIR__ . '/class-mec-elementor-widgets.php';
            if (file_exists($widgets_file)) {
                require_once $widgets_file;
            }
            if (class_exists('MadExtra_Citations_Elementor_Listing_Widget')) {
                $widgets_manager->register(new MadExtra_Citations_Elementor_Listing_Widget());
            }
            if (class_exists('MadExtra_Citations_Elementor_Dynamic_Field_Widget')) {
                $widgets_manager->register(new MadExtra_Citations_Elementor_Dynamic_Field_Widget());
            }
            if (class_exists('MadExtra_Citations_Elementor_Filters_Widget')) {
                $widgets_manager->register(new MadExtra_Citations_Elementor_Filters_Widget());
            }
        }
    }
}
