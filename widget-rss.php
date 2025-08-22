<?php
// ===========================
// Elementor Widget
// ===========================
namespace ERWP\Widgets;

use \Elementor\Widget_Base;
use \Elementor\Controls_Manager;

if (!class_exists('\Elementor\Widget_Base')) { return; }

class RSS_Widget extends Widget_Base {
    public function get_name() { return 'erwp_rss_puller'; }
    public function get_title() { return __('RSS Posts (Cached, Cron)', 'erwp'); }
    public function get_icon() { return 'eicon-post-list'; }
    public function get_categories() { return ['general']; }

    protected function register_controls() {
        $this->start_controls_section('section_feed', ['label' => __('Feed', 'erwp')]);

        $this->add_control('feed_url', [
            'label' => __('Feed URL', 'erwp'),
            'type'  => Controls_Manager::TEXT,
            'placeholder' => 'https://example.com/feed.xml',
            'label_block' => true,
        ]);

        $this->add_control('items', [
            'label' => __('Items to show', 'erwp'),
            'type'  => Controls_Manager::NUMBER,
            'default' => 5,
            'min' => 1, 'max' => 50,
        ]);

        $this->add_control('cache_minutes', [
            'label' => __('Cache duration (minutes)', 'erwp'),
            'type'  => Controls_Manager::NUMBER,
            'default' => 60,
            'min' => 5, 'max' => 1440,
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_templates', ['label' => __('Templates', 'erwp')]);

        $this->add_control('item_wrapper_template', [
            'label' => __('Item Wrapper Template', 'erwp'),
            'type'  => Controls_Manager::TEXTAREA,
            'default' => "<div class=\"erwp-item\">\n{title_block}\n{description_block}\n</div>",
            'description' => __('Use {title_block} and {description_block} placeholders.', 'erwp'),
        ]);

        $this->add_control('title_template', [
            'label' => __('Title Template', 'erwp'),
            'type'  => Controls_Manager::TEXTAREA,
            'default' => '<h3 class="erwp-title">{title_link}</h3>',
            'description' => __('Tokens: {title}, {title_link}, {link}', 'erwp'),
        ]);

        $this->add_control('description_template', [
            'label' => __('Description Template', 'erwp'),
            'type'  => Controls_Manager::TEXTAREA,
            'default' => '<div class="erwp-desc">{description}</div>',
            'description' => __('Tokens: {description}, {date}', 'erwp'),
        ]);

        $this->add_control('strip_html', [
            'label' => __('Strip HTML from descriptions', 'erwp'),
            'type'  => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('trim_mode', [
            'label' => __('Trim Description', 'erwp'),
            'type'  => Controls_Manager::SELECT,
            'options' => [
                '' => __('No trim', 'erwp'),
                'words' => __('By words', 'erwp'),
                'chars' => __('By characters', 'erwp'),
            ],
            'default' => 'words',
        ]);

        $this->add_control('trim_amount', [
            'label' => __('Trim Amount', 'erwp'),
            'type'  => Controls_Manager::NUMBER,
            'default' => 40,
            'min' => 5, 'max' => 1000,
        ]);

        $this->add_control('links_new_tab', [
            'label' => __('Open links in new tab', 'erwp'),
            'type'  => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => 'yes',
        ]);

        $this->add_control('links_nofollow', [
            'label' => __('Add rel="nofollow" to links', 'erwp'),
            'type'  => Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default' => '',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('section_container', ['label' => __('Container', 'erwp')]);
        $this->add_responsive_control('container_tag', [
            'label' => __('Container Tag', 'erwp'),
            'type'  => Controls_Manager::SELECT,
            'options' => ['div'=>'div','section'=>'section','ul'=>'ul','ol'=>'ol'],
            'default' => 'div',
        ]);
        $this->add_control('container_class', [
            'label' => __('Container CSS class', 'erwp'),
            'type'  => Controls_Manager::TEXT,
            'default' => 'erwp-feed',
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        $feed_url     = trim($s['feed_url'] ?? '');
        $items_to_show= intval($s['items'] ?? 5);
        $cache_minutes= intval($s['cache_minutes'] ?? 60);

        if (empty($feed_url)) {
            if (current_user_can('edit_posts')) {
                echo '<em style="color:#999">Set a Feed URL to display items.</em>';
            }
            return;
        }

        // Register this feed in the global scanner registry
        \ERWP\Plugin::register_feed_url($feed_url, $cache_minutes);

        // Editor preview warm-up only; otherwise read cache only
        $warm = \Elementor\Plugin::$instance->editor->is_edit_mode();
        $result = \ERWP\Plugin::get_cached_feed($feed_url, $items_to_show, $cache_minutes, $warm);

        $items = $result['items'] ?? [];
        $error = $result['error'] ?? null;

        $allow_html = ($s['strip_html'] ?? 'yes') !== 'yes';
        $trim_mode  = $s['trim_mode'] ?? '';
        $trim_amount= intval($s['trim_amount'] ?? 0);
        $opts = [
            'allow_html' => $allow_html,
            'trim_words' => $trim_mode === 'words' ? $trim_amount : 0,
            'trim_chars' => $trim_mode === 'chars'  ? $trim_amount : 0,
            'new_tab'    => ($s['links_new_tab'] ?? '') === 'yes',
            'nofollow'   => ($s['links_nofollow'] ?? '') === 'yes',
        ];

        $container_tag   = in_array($s['container_tag'], ['div','section','ul','ol'], true) ? $s['container_tag'] : 'div';
        $container_class = sanitize_html_class($s['container_class'] ?: 'erwp-feed');

        $item_wrap_tpl   = $s['item_wrapper_template'] ?: "<div class=\"erwp-item\">\n{title_block}\n{description_block}\n</div>";
        $title_tpl       = $s['title_template'] ?: '<h3 class="erwp-title">{title_link}</h3>';
        $desc_tpl        = $s['description_template'] ?: '<div class="erwp-desc">{description}</div>';

        echo '<' . $container_tag . ' class="' . esc_attr($container_class) . '">';

        if (empty($items) && current_user_can('edit_posts')) {
            if ($error) {
                echo '<div class="erwp-msg" style="color:#c00">Feed error: ' . esc_html($error) . '</div>';
            } else {
                echo '<div class="erwp-msg" style="color:#999">No cached items yet. Background cron will populate this shortly.</div>';
            }
        }

        foreach ($items as $item) {
            $title_block = \ERWP\Plugin::render_template($title_tpl, $item, $opts);
            $desc_block  = \ERWP\Plugin::render_template($desc_tpl,  $item, $opts);

            $html = strtr($item_wrap_tpl, [
                '{title_block}' => $title_block,
                '{description_block}' => $desc_block,
            ]);
            $allowed = [
                'a' => ['href'=>[], 'target'=>[], 'rel'=>[]],
                'p' => [], 'span'=>['class'=>[]], 'strong'=>[], 'em'=>[], 'b'=>[], 'i'=>[], 'br'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[], 'div'=>['class'=>[]],
                'h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[],
            ];
            echo wp_kses($html, $allowed);
        }

        echo '</' . $container_tag . '>';
    }

    public function get_style_depends() { return []; }
}

