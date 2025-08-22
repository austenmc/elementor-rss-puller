<?php
/**
 * Plugin Name: Elementor RSS Puller (Cached, Repeating Cron)
 * Description: Elementor widget to render items from an RSS/Atom feed with cached data refreshed by a repeating WP-Cron job. Customizable title/description templates.
 * Version:     1.1.0
 * Author:      Austen McDonald
 * License:     GPL-2.0+
 */
namespace ERWP;

if (!defined('ABSPATH')) { exit; }


final class Plugin {
    const VERSION       = '1.1.0';
    const CRON_SCAN_HOOK= 'erwp_scan_feeds';
    const OPT_PREFIX    = 'erwp_feed_cache_';
    const REGISTRY_OPT  = 'erwp_feed_registry'; // array of feed_url => ['last_seen'=>ts, 'cache_minutes'=>int]

    public static function init() {
        // Elementor widget registration
        add_action('elementor/widgets/register', [__CLASS__, 'register_widget']);

        // Repeating cron: scan and refresh all feeds
        add_action(self::CRON_SCAN_HOOK, [__CLASS__, 'cron_scan_refresh_all']);

        // Add custom intervals
        add_filter('cron_schedules', function($schedules){
            if (!isset($schedules['erwp_1min']))   $schedules['erwp_1min']   = ['interval'=> 60,  'display'=>__('Every 1 minute','erwp')];
            return $schedules;
        });

        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);
    }

    public static function on_activate() {
        // Check for repeating scan if not present
        if (!wp_next_scheduled(self::CRON_SCAN_HOOK)) {
            wp_schedule_event(time() + 60, 'erwp_1min', self::CRON_SCAN_HOOK);
        }
        if (!get_option(self::REGISTRY_OPT)) {
            add_option(self::REGISTRY_OPT, [], false);
        }
    }

    public static function on_deactivate() {
        // Clear repeating event
        $timestamp = wp_next_scheduled(self::CRON_SCAN_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_SCAN_HOOK);
    }

    public static function register_widget($widgets_manager) {
	    if (!did_action('elementor/loaded')) { return; }
	     require_once __DIR__ . '/widget-rss.php';
        $widgets_manager->register( new \ERWP\Widgets\RSS_Widget() );
    }

    /** Add/refresh a feed in the registry (called from widget render) */
    public static function register_feed_url($feed_url, $cache_minutes) {
        $feed_url = trim((string)$feed_url);
        if (!$feed_url) return;
        $reg = get_option(self::REGISTRY_OPT, []);
        $reg[$feed_url] = [
            'last_seen'     => time(),
            'cache_minutes' => max(5, intval($cache_minutes)),
        ];
        update_option(self::REGISTRY_OPT, $reg, false);
    }

    /** Repeating cron task: iterate registry and refresh feeds */
    public static function cron_scan_refresh_all() {
        $reg = get_option(self::REGISTRY_OPT, []);
        if (empty($reg) || !is_array($reg)) return;

        foreach ($reg as $feed_url => $meta) {
            $cache_ttl = intval($meta['cache_minutes'] ?? 60) * 60;
            $cache = self::read_cache($feed_url);
            $needs_refresh = true;

            if ($cache && isset($cache['fetched_at'])) {
                $needs_refresh = (time() - intval($cache['fetched_at'])) >= $cache_ttl;
            }

            // Safety: also refresh if cache missing or on error
            if ($needs_refresh || !$cache || !empty($cache['error'])) {
                self::refresh_feed_now($feed_url, 30);
            }
        }
    }

    /** Fetch and write cache immediately (used by cron scanner) */
    private static function refresh_feed_now($feed_url, $limit = 30) {
        $items = self::fetch_feed_now($feed_url, $limit);
        if (is_wp_error($items)) {
            self::write_cache($feed_url, [
                'fetched_at' => time(),
                'error'      => $items->get_error_message(),
                'items'      => [],
                'ttl'        => 10 * 60,
            ]);
        } else {
            self::write_cache($feed_url, [
                'fetched_at' => time(),
                'error'      => null,
                'items'      => $items,
                'ttl'        => 60 * 60,
            ]);
        }
    }

    /** Public: return cached items; no single-event scheduling here */
    public static function get_cached_feed($feed_url, $max_items, $cache_minutes, $warm_if_empty = false) {
        $feed_url   = trim((string)$feed_url);
        $max_items  = max(1, intval($max_items));
        $cache_ttl  = max(5, intval($cache_minutes)) * 60;

        $cache = self::read_cache($feed_url);
        $items = [];
        $error = null;

        if ($cache && !empty($cache['items'])) {
            $items = $cache['items'];
            $error = $cache['error'] ?? null;
        } elseif ($warm_if_empty && current_user_can('edit_posts')) {
            // Editor preview warm-up (short timeout)
            $result = self::fetch_feed_now($feed_url, $max_items, 4);
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                $items = $result;
                self::write_cache($feed_url, [
                    'fetched_at' => time(),
                    'error'      => null,
                    'items'      => $items,
                    'ttl'        => $cache_ttl,
                ]);
            }
        } else {
            $error = $cache['error'] ?? null;
        }

        if (!empty($items)) {
            $items = array_slice($items, 0, $max_items);
        }
        return ['items' => $items, 'error' => $error];
    }

    /** Fetch feed now; returns array of normalized items or WP_Error */
    private static function fetch_feed_now($feed_url, $limit = 30, $timeout = 8) {
        $args = [
            'timeout' => $timeout,
            'headers' => ['User-Agent' => 'ERWP/1.1 (+WordPress; Elementor RSS Puller)'],
        ];
        $resp = wp_remote_get($feed_url, $args);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('bad_status', 'Feed returned HTTP ' . $code);
        }
        $body = wp_remote_retrieve_body($resp);
        if (!$body) return new \WP_Error('empty_body', 'Empty feed body');

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if (!$xml) return new \WP_Error('parse_error', 'Failed to parse XML');

        $items = [];
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $it) {
                $items[] = [
                    'title' => wp_strip_all_tags((string)($it->title ?? '')),
                    'link'  => esc_url_raw((string)($it->link ?? '')),
                    'description' => (string)($it->description ?? ''),
                    'date'  => (string)($it->pubDate ?? ''),
                ];
                if (count($items) >= $limit) break;
            }
        } else {
            foreach ($xml->entry as $entry) {
                $href = '';
                foreach ($entry->link as $lnk) {
                    $attrs = $lnk->attributes();
                    $rel = isset($attrs['rel']) ? (string)$attrs['rel'] : 'alternate';
                    if (isset($attrs['href']) && ($rel === 'alternate' || !$href)) {
                        $href = (string)$attrs['href'];
                    }
                }
                $desc = '';
                if (isset($entry->summary)) $desc = (string)$entry->summary;
                elseif (isset($entry->content)) $desc = (string)$entry->content;

                $items[] = [
                    'title' => wp_strip_all_tags((string)($entry->title ?? '')),
                    'link'  => esc_url_raw($href),
                    'description' => $desc,
                    'date'  => (string)($entry->updated ?? $entry->published ?? ''),
                ];
                if (count($items) >= $limit) break;
            }
        }
        return $items;
    }

    private static function cache_key($feed_url) {
        return self::OPT_PREFIX . md5(strtolower(trim($feed_url)));
    }
    private static function read_cache($feed_url) {
        $key = self::cache_key($feed_url);
        $val = get_option($key);
        return is_array($val) ? $val : null;
    }
    private static function write_cache($feed_url, array $payload) {
        $key = self::cache_key($feed_url);
        update_option($key, $payload, false); // autoload=false
    }

    /** Simple token replacement: {title}, {link}, {description}, {date}, {title_link} */
    public static function render_template($template, array $item, $opts = []) {
        $desc = $item['description'] ?? '';
        if (!($opts['allow_html'] ?? false)) {
            $desc = wp_strip_all_tags($desc);
        } else {
            $desc = wp_kses_post($desc);
        }
        if (!empty($opts['trim_chars'])) {
            $desc = mb_substr($desc, 0, intval($opts['trim_chars'])) . (mb_strlen($desc) > intval($opts['trim_chars']) ? '…' : '');
        } elseif (!empty($opts['trim_words'])) {
            $words = preg_split('/\s+/', wp_strip_all_tags($desc));
            if (count($words) > intval($opts['trim_words'])) {
                $desc = implode(' ', array_slice($words, 0, intval($opts['trim_words']))) . '…';
            }
        }

        $replacements = [
            '{title}'       => esc_html($item['title'] ?? ''),
            '{link}'        => esc_url($item['link'] ?? ''),
            '{description}' => $desc,
            '{date}'        => esc_html($item['date'] ?? ''),
        ];

        if (strpos($template, '{title_link}') !== false) {
            $attrs = [];
            if (!empty($opts['new_tab']))  $attrs[] = 'target="_blank" rel="noopener"';
            if (!empty($opts['nofollow'])) $attrs[] = 'rel="nofollow"';
            $attrs_str = $attrs ? ' ' . implode(' ', $attrs) : '';
            $replacements['{title_link}'] = '<a href="' . esc_url($item['link'] ?? '#') . '"' . $attrs_str . '>' . esc_html($item['title'] ?? '') . '</a>';
        }

        $out = strtr($template, $replacements);
        $allowed = [
            'a' => ['href'=>[], 'target'=>[], 'rel'=>[]],
            'p' => [], 'span'=>['class'=>[]], 'strong'=>[], 'em'=>[], 'b'=>[], 'i'=>[], 'br'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[], 'div'=>['class'=>[]],
            'h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[],
        ];
        return wp_kses($out, $allowed);
    }
}

Plugin::init();
