<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MadExtra_Directory_Data')) {
    final class MadExtra_Directory_Data
    {
        const SCHEMA_OPTION = 'mec_directory_schema_version';
        const SCHEMA_VERSION = '1.0.0';
        const CRON_HOOK = 'mec_process_directory_import_job';
        const UPLOAD_SUBDIR = 'mec-directory-imports';
        const BATCH_SIZE = 200;

        public static function bootstrap()
        {
            add_action('init', array(__CLASS__, 'maybe_upgrade_schema'));
            add_action(self::CRON_HOOK, array(__CLASS__, 'process_import_job'));
        }

        public static function activate()
        {
            self::maybe_upgrade_schema(true);
        }

        public static function maybe_upgrade_schema($force = false)
        {
            $version = get_option(self::SCHEMA_OPTION, '');
            if (!$force && self::SCHEMA_VERSION === (string) $version) {
                return;
            }

            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();
            $businesses = self::table('mec_directory_businesses');
            $verticals = self::table('mec_directory_verticals');
            $jobs = self::table('mec_directory_import_jobs');
            $errors = self::table('mec_directory_import_errors');

            dbDelta(
                "CREATE TABLE {$verticals} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    slug varchar(100) NOT NULL,
                    label varchar(191) NOT NULL,
                    description text NULL,
                    is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
                    created_at datetime NOT NULL,
                    updated_at datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY slug (slug)
                ) {$charset};"
            );

            dbDelta(
                "CREATE TABLE {$jobs} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    filename varchar(255) NOT NULL,
                    file_path text NOT NULL,
                    file_hash varchar(64) NOT NULL DEFAULT '',
                    vertical_slug varchar(100) NOT NULL,
                    source_label varchar(191) NOT NULL DEFAULT 'csv-upload',
                    status varchar(30) NOT NULL DEFAULT 'queued',
                    total_rows bigint(20) unsigned NOT NULL DEFAULT 0,
                    processed_rows bigint(20) unsigned NOT NULL DEFAULT 0,
                    inserted_count bigint(20) unsigned NOT NULL DEFAULT 0,
                    updated_count bigint(20) unsigned NOT NULL DEFAULT 0,
                    deactivated_count bigint(20) unsigned NOT NULL DEFAULT 0,
                    error_count bigint(20) unsigned NOT NULL DEFAULT 0,
                    cursor_offset bigint(20) unsigned NOT NULL DEFAULT 0,
                    uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
                    started_at datetime NULL,
                    completed_at datetime NULL,
                    created_at datetime NOT NULL,
                    updated_at datetime NOT NULL,
                    PRIMARY KEY  (id),
                    KEY status (status),
                    KEY vertical_slug (vertical_slug),
                    KEY created_at (created_at)
                ) {$charset};"
            );

            dbDelta(
                "CREATE TABLE {$businesses} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    vertical_slug varchar(100) NOT NULL,
                    vertical_label varchar(191) NOT NULL DEFAULT '',
                    external_source_id varchar(191) NOT NULL,
                    source_id_type varchar(60) NOT NULL DEFAULT '',
                    source_label varchar(191) NOT NULL DEFAULT 'csv-upload',
                    business_name varchar(191) NOT NULL,
                    full_address text NULL,
                    street_address varchar(191) NULL,
                    city varchar(191) NULL,
                    municipality varchar(191) NULL,
                    state varchar(191) NULL,
                    zip varchar(40) NULL,
                    country varchar(100) NULL,
                    timezone varchar(100) NULL,
                    phone_raw varchar(80) NULL,
                    phone_standard varchar(80) NULL,
                    email varchar(191) NULL,
                    website_url text NULL,
                    domain varchar(191) NULL,
                    claimed_google tinyint(1) unsigned NOT NULL DEFAULT 0,
                    reviews_count int(11) NOT NULL DEFAULT 0,
                    average_rating decimal(4,2) NOT NULL DEFAULT 0.00,
                    business_status varchar(60) NULL,
                    hours longtext NULL,
                    latitude varchar(60) NULL,
                    longitude varchar(60) NULL,
                    plus_code varchar(120) NULL,
                    place_id varchar(191) NULL,
                    gmb_url text NULL,
                    cid varchar(191) NULL,
                    knowledge_url text NULL,
                    image_url text NULL,
                    review_url text NULL,
                    facebook_url text NULL,
                    linkedin_url text NULL,
                    twitter_url text NULL,
                    instagram_url text NULL,
                    youtube_url text NULL,
                    meta_description longtext NULL,
                    kgmid varchar(191) NULL,
                    is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
                    last_seen_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    linked_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    public_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    claim_status varchar(40) NOT NULL DEFAULT 'unclaimed',
                    payment_status varchar(40) NOT NULL DEFAULT '',
                    checkout_session_id varchar(191) NOT NULL DEFAULT '',
                    claimed_email varchar(191) NOT NULL DEFAULT '',
                    claimed_at datetime NULL,
                    source_payload longtext NULL,
                    created_at datetime NOT NULL,
                    updated_at datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY vertical_source (vertical_slug, external_source_id),
                    KEY vertical_active (vertical_slug, is_active),
                    KEY city (city(100)),
                    KEY linked_profile_id (linked_profile_id),
                    KEY public_page_id (public_page_id),
                    KEY checkout_session_id (checkout_session_id)
                ) {$charset};"
            );

            dbDelta(
                "CREATE TABLE {$errors} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    job_id bigint(20) unsigned NOT NULL,
                    row_number bigint(20) unsigned NOT NULL DEFAULT 0,
                    source_business_id varchar(191) NOT NULL DEFAULT '',
                    error_message text NOT NULL,
                    raw_row longtext NULL,
                    created_at datetime NOT NULL,
                    PRIMARY KEY  (id),
                    KEY job_id (job_id),
                    KEY row_number (row_number)
                ) {$charset};"
            );

            self::seed_verticals();
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }

        public static function table($name)
        {
            global $wpdb;
            return $wpdb->prefix . $name;
        }

        public static function seed_verticals()
        {
            global $wpdb;
            $table = self::table('mec_directory_verticals');
            $now = current_time('mysql');
            foreach (self::default_verticals() as $vertical) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $vertical['slug']));
                if ($exists) {
                    $wpdb->update(
                        $table,
                        array(
                            'label' => $vertical['label'],
                            'description' => $vertical['description'],
                            'is_active' => 1,
                            'updated_at' => $now,
                        ),
                        array('slug' => $vertical['slug']),
                        array('%s', '%s', '%d', '%s'),
                        array('%s')
                    );
                    continue;
                }

                $wpdb->insert(
                    $table,
                    array(
                        'slug' => $vertical['slug'],
                        'label' => $vertical['label'],
                        'description' => $vertical['description'],
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s')
                );
            }
        }

        public static function default_verticals()
        {
            return array(
                array(
                    'slug' => 'wellness',
                    'label' => 'Wellness',
                    'description' => 'Wellness businesses and spas.',
                ),
                array(
                    'slug' => 'medical-spas',
                    'label' => 'Medical Spas',
                    'description' => 'Medical spas and aesthetic clinics.',
                ),
            );
        }

        public static function get_verticals($include_inactive = false)
        {
            global $wpdb;
            $table = self::table('mec_directory_verticals');
            $sql = "SELECT * FROM {$table}";
            if (!$include_inactive) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY label ASC';
            return $wpdb->get_results($sql, ARRAY_A);
        }

        public static function create_import_job_from_upload(array $file, $vertical_slug, $uploaded_by = 0)
        {
            if (empty($file['tmp_name']) || empty($file['name'])) {
                return new WP_Error('missing_upload', __('No CSV file uploaded.', 'madextra-citations'));
            }

            $vertical_slug = self::normalize_vertical_slug($vertical_slug);
            if ('' === $vertical_slug) {
                return new WP_Error('missing_vertical', __('Choose a directory vertical before uploading.', 'madextra-citations'));
            }

            $vertical = self::get_vertical($vertical_slug);
            if (!$vertical) {
                return new WP_Error('invalid_vertical', __('Invalid directory vertical.', 'madextra-citations'));
            }

            $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if ('csv' !== $ext) {
                return new WP_Error('invalid_file_type', __('Only CSV uploads are supported.', 'madextra-citations'));
            }

            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $uploads = wp_upload_dir();
            $target_dir = trailingslashit($uploads['basedir']) . self::UPLOAD_SUBDIR;
            wp_mkdir_p($target_dir);

            add_filter('upload_dir', array(__CLASS__, 'filter_upload_dir'));
            $moved = wp_handle_upload(
                $file,
                array(
                    'test_form' => false,
                    'mimes' => array('csv' => 'text/csv'),
                )
            );
            remove_filter('upload_dir', array(__CLASS__, 'filter_upload_dir'));

            if (empty($moved['file']) || !empty($moved['error'])) {
                return new WP_Error('upload_failed', !empty($moved['error']) ? $moved['error'] : __('CSV upload failed.', 'madextra-citations'));
            }

            $row_count = self::count_csv_rows($moved['file']);
            $file_hash = is_readable($moved['file']) ? (string) hash_file('sha256', $moved['file']) : '';
            $now = current_time('mysql');

            global $wpdb;
            $jobs_table = self::table('mec_directory_import_jobs');
            $inserted = $wpdb->insert(
                $jobs_table,
                array(
                    'filename' => sanitize_file_name((string) $file['name']),
                    'file_path' => $moved['file'],
                    'file_hash' => $file_hash,
                    'vertical_slug' => $vertical_slug,
                    'source_label' => 'manual-upload',
                    'status' => 'queued',
                    'total_rows' => max(0, $row_count),
                    'uploaded_by' => (int) $uploaded_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
            );

            if (!$inserted) {
                return new WP_Error('job_insert_failed', __('Could not create import job.', 'madextra-citations'));
            }

            $job_id = (int) $wpdb->insert_id;
            self::schedule_import_job($job_id, 3);
            return self::get_job($job_id);
        }

        public static function filter_upload_dir($dirs)
        {
            $dirs['subdir'] = '/' . self::UPLOAD_SUBDIR;
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        }

        public static function schedule_import_job($job_id, $delay = 5)
        {
            $job_id = (int) $job_id;
            if ($job_id <= 0) {
                return;
            }

            wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_HOOK, array($job_id));
        }

        public static function process_import_job($job_id)
        {
            $job = self::get_job($job_id);
            if (!$job) {
                return;
            }

            if (!in_array($job['status'], array('queued', 'running', 'failed'), true)) {
                return;
            }

            if (empty($job['file_path']) || !is_readable($job['file_path'])) {
                self::mark_job_failed($job['id'], __('Uploaded CSV file is missing or unreadable.', 'madextra-citations'));
                return;
            }

            global $wpdb;
            $jobs_table = self::table('mec_directory_import_jobs');
            $wpdb->update(
                $jobs_table,
                array(
                    'status' => 'running',
                    'started_at' => $job['started_at'] ? $job['started_at'] : current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $job['id']),
                array('%s', '%s', '%s'),
                array('%d')
            );

            $handle = new SplFileObject($job['file_path'], 'r');
            $handle->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

            $headers = $handle->fgetcsv();
            if (!is_array($headers) || !$headers) {
                self::mark_job_failed($job['id'], __('CSV header row is missing.', 'madextra-citations'));
                return;
            }

            $normalized_headers = self::normalize_headers($headers);
            $offset = (int) $job['cursor_offset'];
            $processed_this_run = 0;
            $inserted = (int) $job['inserted_count'];
            $updated = (int) $job['updated_count'];
            $errors = (int) $job['error_count'];
            $processed_total = (int) $job['processed_rows'];

            if ($offset > 0) {
                $handle->seek($offset + 1);
            }

            while (!$handle->eof() && $processed_this_run < self::BATCH_SIZE) {
                $row = $handle->fgetcsv();
                if (!is_array($row) || self::row_is_empty($row)) {
                    continue;
                }

                $processed_this_run++;
                $processed_total++;
                $row_number = $offset + $processed_this_run;
                $assoc = self::combine_headers_and_row($normalized_headers, $row);
                $mapped = self::map_csv_row($assoc, $job['vertical_slug']);
                if (is_wp_error($mapped)) {
                    $errors++;
                    self::insert_error($job['id'], $row_number, '', $mapped->get_error_message(), $assoc);
                    continue;
                }

                $result = self::upsert_business($mapped, (int) $job['id']);
                if (is_wp_error($result)) {
                    $errors++;
                    self::insert_error(
                        $job['id'],
                        $row_number,
                        isset($mapped['external_source_id']) ? $mapped['external_source_id'] : '',
                        $result->get_error_message(),
                        $assoc
                    );
                    continue;
                }

                if (!empty($result['inserted'])) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }

            $new_offset = $offset + $processed_this_run;
            $is_complete = $handle->eof();
            unset($handle);

            $wpdb->update(
                $jobs_table,
                array(
                    'processed_rows' => $processed_total,
                    'inserted_count' => $inserted,
                    'updated_count' => $updated,
                    'error_count' => $errors,
                    'cursor_offset' => $new_offset,
                    'status' => $is_complete ? 'finalizing' : 'running',
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $job['id']),
                array('%d', '%d', '%d', '%d', '%d', '%s', '%s'),
                array('%d')
            );

            if ($is_complete) {
                self::finalize_import_job((int) $job['id']);
                return;
            }

            self::schedule_import_job((int) $job['id'], 3);
        }

        public static function get_job($job_id)
        {
            global $wpdb;
            $table = self::table('mec_directory_import_jobs');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $job_id), ARRAY_A);
            return is_array($row) ? $row : array();
        }

        public static function query_jobs(array $filters = array())
        {
            global $wpdb;
            $table = self::table('mec_directory_import_jobs');
            $where = array('1=1');
            $params = array();

            if (!empty($filters['vertical_slug'])) {
                $where[] = 'vertical_slug = %s';
                $params[] = self::normalize_vertical_slug($filters['vertical_slug']);
            }
            if (!empty($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = sanitize_key((string) $filters['status']);
            }

            $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100';
            if ($params) {
                $sql = $wpdb->prepare($sql, $params);
            }
            return $wpdb->get_results($sql, ARRAY_A);
        }

        public static function retry_job($job_id)
        {
            global $wpdb;
            $job = self::get_job($job_id);
            if (!$job) {
                return new WP_Error('missing_job', __('Import job not found.', 'madextra-citations'));
            }

            $wpdb->update(
                self::table('mec_directory_import_jobs'),
                array(
                    'status' => 'queued',
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => (int) $job_id),
                array('%s', '%s'),
                array('%d')
            );

            self::schedule_import_job((int) $job_id, 3);
            return true;
        }

        public static function get_job_errors($job_id)
        {
            global $wpdb;
            $table = self::table('mec_directory_import_errors');
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE job_id = %d ORDER BY row_number ASC", (int) $job_id),
                ARRAY_A
            );
        }

        public static function get_business($business_id)
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $business_id), ARRAY_A);
            return is_array($row) ? $row : array();
        }

        public static function find_business_by_session($session_id)
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE checkout_session_id = %s ORDER BY id DESC LIMIT 1", sanitize_text_field((string) $session_id)),
                ARRAY_A
            );
            return is_array($row) ? $row : array();
        }

        public static function query_businesses(array $args = array())
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $page = max(1, (int) (isset($args['page']) ? $args['page'] : 1));
            $limit = min(100, max(1, (int) (isset($args['limit']) ? $args['limit'] : 25)));
            $offset = ($page - 1) * $limit;

            $where = array('1=1');
            $params = array();

            if (empty($args['include_inactive'])) {
                $where[] = 'is_active = 1';
            }
            if (!empty($args['vertical_slug'])) {
                $where[] = 'vertical_slug = %s';
                $params[] = self::normalize_vertical_slug($args['vertical_slug']);
            }
            if (!empty($args['city'])) {
                $where[] = 'city = %s';
                $params[] = sanitize_text_field((string) $args['city']);
            }
            if (!empty($args['search'])) {
                $search = '%' . $wpdb->esc_like(sanitize_text_field((string) $args['search'])) . '%';
                $where[] = '(business_name LIKE %s OR city LIKE %s OR full_address LIKE %s OR phone_standard LIKE %s OR website_url LIKE %s OR meta_description LIKE %s)';
                $params = array_merge($params, array($search, $search, $search, $search, $search, $search));
            }

            $where_sql = implode(' AND ', $where);
            $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
            if ($params) {
                $count_sql = $wpdb->prepare($count_sql, $params);
            }
            $total = (int) $wpdb->get_var($count_sql);

            $select_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY reviews_count DESC, average_rating DESC, business_name ASC LIMIT %d OFFSET %d";
            $select_params = array_merge($params, array($limit, $offset));
            $items = $wpdb->get_results($wpdb->prepare($select_sql, $select_params), ARRAY_A);

            return array(
                'items' => is_array($items) ? $items : array(),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            );
        }

        public static function list_cities($vertical_slug = '')
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $where = 'WHERE is_active = 1';
            $params = array();
            if ($vertical_slug) {
                $where .= ' AND vertical_slug = %s';
                $params[] = self::normalize_vertical_slug($vertical_slug);
            }

            $sql = "SELECT DISTINCT city FROM {$table} {$where} AND city <> '' ORDER BY city ASC";
            if ($params) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return $wpdb->get_col($sql);
        }

        public static function featured_businesses(array $args = array())
        {
            $args['limit'] = isset($args['limit']) ? (int) $args['limit'] : 6;
            $result = self::query_businesses(
                array(
                    'vertical_slug' => isset($args['vertical_slug']) ? $args['vertical_slug'] : '',
                    'page' => 1,
                    'limit' => $args['limit'],
                )
            );
            return isset($result['items']) ? $result['items'] : array();
        }

        public static function create_manual_submission(array $payload)
        {
            $vertical_slug = self::normalize_vertical_slug(isset($payload['vertical_slug']) ? $payload['vertical_slug'] : '');
            if ('' === $vertical_slug) {
                return new WP_Error('missing_vertical', __('Choose a directory vertical before submitting.', 'madextra-citations'));
            }

            $vertical = self::get_vertical($vertical_slug);
            if (!$vertical) {
                return new WP_Error('invalid_vertical', __('Invalid directory vertical.', 'madextra-citations'));
            }

            $business_name = isset($payload['business_name']) ? sanitize_text_field((string) $payload['business_name']) : '';
            if ('' === $business_name) {
                return new WP_Error('missing_business_name', __('Business name is required.', 'madextra-citations'));
            }

            $website_url = isset($payload['website_url']) ? esc_url_raw((string) $payload['website_url']) : '';
            $phone_standard = isset($payload['phone_standard']) ? sanitize_text_field((string) $payload['phone_standard']) : '';
            $seed = strtolower(trim($business_name . '|' . $website_url . '|' . $phone_standard));
            $external_source_id = isset($payload['external_source_id']) ? sanitize_text_field((string) $payload['external_source_id']) : '';
            if ('' === $external_source_id) {
                $external_source_id = 'manual-' . md5($seed);
            }

            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE vertical_slug = %s AND external_source_id = %s LIMIT 1",
                    $vertical_slug,
                    $external_source_id
                )
            );

            $now = current_time('mysql');
            $data = array(
                'vertical_slug' => $vertical_slug,
                'vertical_label' => $vertical['label'],
                'external_source_id' => $external_source_id,
                'source_id_type' => 'manual',
                'source_label' => 'manual-submit',
                'business_name' => $business_name,
                'full_address' => isset($payload['full_address']) ? sanitize_textarea_field((string) $payload['full_address']) : '',
                'street_address' => isset($payload['street_address']) ? sanitize_text_field((string) $payload['street_address']) : '',
                'city' => isset($payload['city']) ? sanitize_text_field((string) $payload['city']) : '',
                'municipality' => isset($payload['municipality']) ? sanitize_text_field((string) $payload['municipality']) : '',
                'state' => isset($payload['state']) ? sanitize_text_field((string) $payload['state']) : '',
                'zip' => isset($payload['zip']) ? sanitize_text_field((string) $payload['zip']) : '',
                'country' => isset($payload['country']) ? sanitize_text_field((string) $payload['country']) : '',
                'timezone' => isset($payload['timezone']) ? sanitize_text_field((string) $payload['timezone']) : '',
                'phone_raw' => isset($payload['phone_raw']) ? sanitize_text_field((string) $payload['phone_raw']) : '',
                'phone_standard' => $phone_standard,
                'email' => isset($payload['email']) ? sanitize_email((string) $payload['email']) : '',
                'website_url' => $website_url,
                'domain' => isset($payload['domain']) ? sanitize_text_field((string) $payload['domain']) : '',
                'hours' => isset($payload['hours']) ? sanitize_textarea_field((string) $payload['hours']) : '',
                'image_url' => isset($payload['image_url']) ? esc_url_raw((string) $payload['image_url']) : '',
                'meta_description' => isset($payload['meta_description']) ? sanitize_textarea_field((string) $payload['meta_description']) : '',
                'business_status' => isset($payload['business_status']) ? sanitize_text_field((string) $payload['business_status']) : 'pending',
                'is_active' => !empty($payload['is_active']) ? 1 : 0,
                'claim_status' => isset($payload['claim_status']) ? sanitize_key((string) $payload['claim_status']) : 'submitted',
                'source_payload' => isset($payload['source_payload']) ? wp_json_encode($payload['source_payload']) : '',
                'updated_at' => $now,
            );

            $format = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s');

            if ($existing_id > 0) {
                $wpdb->update($table, $data, array('id' => $existing_id), $format, array('%d'));
                return self::get_business($existing_id);
            }

            $data['created_at'] = $now;
            $format[] = '%s';
            $inserted = $wpdb->insert($table, $data, $format);
            if (!$inserted) {
                return new WP_Error('manual_business_insert_failed', __('Could not create the directory business record.', 'madextra-citations'));
            }

            return self::get_business((int) $wpdb->insert_id);
        }

        public static function update_business_claim_state($business_id, array $changes)
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $allowed = array(
                'linked_profile_id' => '%d',
                'public_page_id' => '%d',
                'claim_status' => '%s',
                'payment_status' => '%s',
                'checkout_session_id' => '%s',
                'claimed_email' => '%s',
                'claimed_at' => '%s',
                'updated_at' => '%s',
            );

            $data = array();
            $format = array();
            foreach ($allowed as $key => $fmt) {
                if (!array_key_exists($key, $changes)) {
                    continue;
                }
                $data[$key] = $changes[$key];
                $format[] = $fmt;
            }
            if (!$data) {
                return false;
            }
            if (!isset($data['updated_at'])) {
                $data['updated_at'] = current_time('mysql');
                $format[] = '%s';
            }

            return false !== $wpdb->update($table, $data, array('id' => (int) $business_id), $format, array('%d'));
        }

        public static function get_vertical($slug)
        {
            $slug = self::normalize_vertical_slug($slug);
            if ('' === $slug) {
                return array();
            }

            global $wpdb;
            $table = self::table('mec_directory_verticals');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug), ARRAY_A);
            return is_array($row) ? $row : array();
        }

        private static function count_csv_rows($file_path)
        {
            if (!is_readable($file_path)) {
                return 0;
            }

            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return 0;
            }

            $count = -1;
            while (false !== fgetcsv($handle)) {
                $count++;
            }
            fclose($handle);
            return max(0, $count);
        }

        private static function row_is_empty(array $row)
        {
            foreach ($row as $value) {
                if ('' !== trim((string) $value)) {
                    return false;
                }
            }
            return true;
        }

        private static function normalize_headers(array $headers)
        {
            $normalized = array();
            $seen = array();
            foreach ($headers as $header) {
                $key = strtolower(trim((string) $header));
                $key = preg_replace('/[^a-z0-9]+/', '_', $key);
                $key = trim((string) $key, '_');
                if ('' === $key) {
                    $key = 'column';
                }
                if (!isset($seen[$key])) {
                    $seen[$key] = 1;
                    $normalized[] = $key;
                    continue;
                }
                $seen[$key]++;
                $normalized[] = $key . '__' . $seen[$key];
            }
            return $normalized;
        }

        private static function combine_headers_and_row(array $headers, array $row)
        {
            $assoc = array();
            foreach ($headers as $index => $header) {
                $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }
            return $assoc;
        }

        private static function pick(array $row, array $keys)
        {
            foreach ($keys as $key) {
                if (!empty($row[$key])) {
                    return trim((string) $row[$key]);
                }
            }
            return '';
        }

        private static function normalize_yes_no($value)
        {
            $value = strtolower(trim((string) $value));
            return in_array($value, array('yes', 'y', '1', 'true', 'claimed'), true) ? 1 : 0;
        }

        private static function normalize_lat_lng($value)
        {
            $value = trim((string) $value);
            $value = str_replace(array('Lat ', 'Lng '), '', $value);
            return $value;
        }

        private static function map_csv_row(array $row, $vertical_slug)
        {
            $source_id = self::pick(
                $row,
                array('source_business_id', 'cid', 'place_id', 'kgmid', 'google_knowledge_url', 'google_knowledge_url__2', 'gmb_url', 'domain')
            );
            if ('' === $source_id) {
                return new WP_Error('missing_source_id', __('Row is missing a stable source business ID.', 'madextra-citations'));
            }

            $business_name = self::pick($row, array('name', 'nap_business_name', 'business_name'));
            if ('' === $business_name) {
                return new WP_Error('missing_business_name', __('Row is missing a business name.', 'madextra-citations'));
            }

            $vertical = self::get_vertical($vertical_slug);
            $vertical_label = !empty($vertical['label']) ? $vertical['label'] : ucwords(str_replace('-', ' ', $vertical_slug));

            return array(
                'vertical_slug' => self::normalize_vertical_slug($vertical_slug),
                'vertical_label' => $vertical_label,
                'external_source_id' => $source_id,
                'source_id_type' => self::source_id_type($row, $source_id),
                'source_label' => 'manual-upload',
                'business_name' => $business_name,
                'full_address' => self::pick($row, array('full_address', 'nap_address')),
                'street_address' => self::pick($row, array('street_address', 'address_street')),
                'city' => self::pick($row, array('city', 'address_city')),
                'municipality' => self::pick($row, array('municipality')),
                'state' => self::pick($row, array('state', 'address_state')),
                'zip' => self::pick($row, array('zip', 'address_zip')),
                'country' => self::pick($row, array('country')),
                'timezone' => self::pick($row, array('timezone')),
                'phone_raw' => self::pick($row, array('phone_1', 'nap_phone')),
                'phone_standard' => self::pick($row, array('phone_standard_format', 'phone')),
                'email' => sanitize_email(self::pick($row, array('email_from_website', 'business_email', 'email'))),
                'website_url' => esc_url_raw(self::pick($row, array('website', 'business_website_url'))),
                'domain' => self::pick($row, array('domain')),
                'claimed_google' => self::normalize_yes_no(self::pick($row, array('claimed_google_my_business'))),
                'reviews_count' => (int) self::pick($row, array('reviews_count')),
                'average_rating' => (float) self::pick($row, array('average_rating')),
                'business_status' => self::pick($row, array('business_status')),
                'hours' => self::pick($row, array('hours', 'business_hours')),
                'latitude' => self::normalize_lat_lng(self::pick($row, array('latitude'))),
                'longitude' => self::normalize_lat_lng(self::pick($row, array('longitude'))),
                'plus_code' => self::pick($row, array('plus_code')),
                'place_id' => self::pick($row, array('place_id')),
                'gmb_url' => esc_url_raw(self::pick($row, array('gmb_url'))),
                'cid' => self::pick($row, array('cid')),
                'knowledge_url' => esc_url_raw(self::pick($row, array('google_knowledge_url', 'google_knowledge_url__2'))),
                'image_url' => esc_url_raw(self::pick($row, array('image_url'))),
                'review_url' => esc_url_raw(self::pick($row, array('review_url'))),
                'facebook_url' => esc_url_raw(self::pick($row, array('facebook_url'))),
                'linkedin_url' => esc_url_raw(self::pick($row, array('linkedin_url'))),
                'twitter_url' => esc_url_raw(self::pick($row, array('twitter_url'))),
                'instagram_url' => esc_url_raw(self::pick($row, array('instagram_url'))),
                'youtube_url' => esc_url_raw(self::pick($row, array('youtube_url'))),
                'meta_description' => self::pick($row, array('meta_description')),
                'kgmid' => self::pick($row, array('kgmid')),
                'source_payload' => wp_json_encode($row),
            );
        }

        private static function source_id_type(array $row, $source_id)
        {
            foreach (array('source_business_id', 'cid', 'place_id', 'kgmid', 'google_knowledge_url', 'google_knowledge_url__2', 'gmb_url', 'domain') as $key) {
                if (!empty($row[$key]) && trim((string) $row[$key]) === $source_id) {
                    return $key;
                }
            }
            return 'derived';
        }

        private static function upsert_business(array $mapped, $job_id)
        {
            global $wpdb;
            $table = self::table('mec_directory_businesses');
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE vertical_slug = %s AND external_source_id = %s",
                    $mapped['vertical_slug'],
                    $mapped['external_source_id']
                )
            );

            $now = current_time('mysql');
            $data = array_merge(
                $mapped,
                array(
                    'is_active' => 1,
                    'last_seen_job_id' => (int) $job_id,
                    'updated_at' => $now,
                )
            );

            if ($existing_id > 0) {
                $wpdb->update($table, $data, array('id' => $existing_id));
                return array('business_id' => $existing_id, 'inserted' => false);
            }

            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
            if (!$wpdb->insert_id) {
                return new WP_Error('insert_failed', __('Could not insert business row.', 'madextra-citations'));
            }

            return array('business_id' => (int) $wpdb->insert_id, 'inserted' => true);
        }

        private static function insert_error($job_id, $row_number, $source_business_id, $message, array $raw_row)
        {
            global $wpdb;
            $wpdb->insert(
                self::table('mec_directory_import_errors'),
                array(
                    'job_id' => (int) $job_id,
                    'row_number' => (int) $row_number,
                    'source_business_id' => sanitize_text_field((string) $source_business_id),
                    'error_message' => $message,
                    'raw_row' => wp_json_encode($raw_row),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s')
            );
        }

        private static function finalize_import_job($job_id)
        {
            global $wpdb;
            $job = self::get_job($job_id);
            if (!$job) {
                return;
            }

            $businesses = self::table('mec_directory_businesses');
            $deactivated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$businesses}
                        SET is_active = 0, updated_at = %s
                      WHERE vertical_slug = %s
                        AND last_seen_job_id <> %d
                        AND is_active = 1",
                    current_time('mysql'),
                    $job['vertical_slug'],
                    (int) $job_id
                )
            );

            $wpdb->update(
                self::table('mec_directory_import_jobs'),
                array(
                    'status' => 'completed',
                    'deactivated_count' => max(0, (int) $deactivated),
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => (int) $job_id),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        private static function mark_job_failed($job_id, $message)
        {
            global $wpdb;
            $wpdb->update(
                self::table('mec_directory_import_jobs'),
                array(
                    'status' => 'failed',
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => (int) $job_id),
                array('%s', '%s'),
                array('%d')
            );
            self::insert_error((int) $job_id, 0, '', $message, array());
        }

        public static function normalize_vertical_slug($slug)
        {
            return sanitize_title((string) $slug);
        }
    }
}
