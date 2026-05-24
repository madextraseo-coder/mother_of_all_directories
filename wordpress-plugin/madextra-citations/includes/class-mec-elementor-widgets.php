<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Elementor\Widget_Base')) {
    return;
}

if (!class_exists('MadExtra_Citations_Elementor_Listing_Widget')) {
    class MadExtra_Citations_Elementor_Listing_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'mec_listing_widget';
        }

        public function get_title()
        {
            return esc_html__('MEC Listing', 'madextra-citations');
        }

        public function get_icon()
        {
            return 'eicon-post-list';
        }

        public function get_categories()
        {
            return array('general');
        }

        protected function register_controls()
        {
            $this->start_controls_section('section_content', array('label' => esc_html__('Settings', 'madextra-citations')));
            $this->add_control('template', array('label' => esc_html__('Template ID', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'default-table'));
            $this->add_control('query', array('label' => esc_html__('Query ID', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'all-profiles'));
            $this->add_control('per_page', array('label' => esc_html__('Per Page', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 25, 'min' => 1, 'max' => 200));
            $this->end_controls_section();
        }

        protected function render()
        {
            $settings = $this->get_settings_for_display();
            $template = isset($settings['template']) ? sanitize_key($settings['template']) : 'default-table';
            $query = isset($settings['query']) ? sanitize_key($settings['query']) : 'all-profiles';
            $per_page = isset($settings['per_page']) ? (int) $settings['per_page'] : 25;
            echo do_shortcode('[mec_listing template="' . esc_attr($template) . '" query="' . esc_attr($query) . '" per_page="' . esc_attr((string) $per_page) . '"]');
        }
    }
}

if (!class_exists('MadExtra_Citations_Elementor_Dynamic_Field_Widget')) {
    class MadExtra_Citations_Elementor_Dynamic_Field_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'mec_dynamic_field_widget';
        }

        public function get_title()
        {
            return esc_html__('MEC Dynamic Field', 'madextra-citations');
        }

        public function get_icon()
        {
            return 'eicon-database';
        }

        public function get_categories()
        {
            return array('general');
        }

        protected function register_controls()
        {
            $this->start_controls_section('section_content', array('label' => esc_html__('Settings', 'madextra-citations')));
            $this->add_control('field_key', array('label' => esc_html__('Field Key', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::TEXT));
            $this->add_control('post_id', array('label' => esc_html__('Profile ID (optional)', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 0, 'min' => 0));
            $this->end_controls_section();
        }

        protected function render()
        {
            $settings = $this->get_settings_for_display();
            $field_key = sanitize_key(isset($settings['field_key']) ? $settings['field_key'] : '');
            if (!$field_key) {
                return;
            }
            $post_id = isset($settings['post_id']) && (int) $settings['post_id'] > 0 ? (int) $settings['post_id'] : get_the_ID();
            $value = get_post_meta($post_id, MadExtra_Citations_Builder::META_DYNAMIC_PREFIX . $field_key, true);
            if ('' === (string) $value) {
                $value = get_post_meta($post_id, MadExtra_Citations_Plugin::META_PREFIX . $field_key, true);
            }
            echo '<span class="mec-dynamic-field">' . esc_html((string) $value) . '</span>';
        }
    }
}

if (!class_exists('MadExtra_Citations_Elementor_Filters_Widget')) {
    class MadExtra_Citations_Elementor_Filters_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'mec_filters_widget';
        }

        public function get_title()
        {
            return esc_html__('MEC Filters', 'madextra-citations');
        }

        public function get_icon()
        {
            return 'eicon-filter';
        }

        public function get_categories()
        {
            return array('general');
        }

        protected function register_controls()
        {
            $this->start_controls_section('section_content', array('label' => esc_html__('Settings', 'madextra-citations')));
            $this->add_control('query', array('label' => esc_html__('Query ID', 'madextra-citations'), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'all-profiles'));
            $this->end_controls_section();
        }

        protected function render()
        {
            $settings = $this->get_settings_for_display();
            $query = isset($settings['query']) ? sanitize_key($settings['query']) : 'all-profiles';
            echo do_shortcode('[mec_filters query="' . esc_attr($query) . '"]');
        }
    }
}
