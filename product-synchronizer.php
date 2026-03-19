<?php
/**
 * Plugin Name: Product Synchronizer
 * Description: Sincronizador de productos desde Woocommerce a sistema Altek.
 * Version: 3.5.0
 * Author: Ing Carlos Garzon
 * Licences: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALTEK_SYNC_VERSION', '0.1.0');
define('ALTEK_SYNC_PLUGIN_FILE', __FILE__);
define('ALTEK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALTEK_SYNC_CRON_HOOK', 'altek_sync_process_queue');

require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-settings.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-queue.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-mapper.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-pg-repository.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-worker.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-bootstrap.php';
require_once ALTEK_SYNC_PLUGIN_DIR . 'includes/class-altek-sync-cli.php';

function altek_sync_activate() {
    Altek_Sync_Queue::install_table();
    if (!wp_next_scheduled(ALTEK_SYNC_CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'altek_sync_custom', ALTEK_SYNC_CRON_HOOK);
    }
}

function altek_sync_deactivate() {
    $timestamp = wp_next_scheduled(ALTEK_SYNC_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, ALTEK_SYNC_CRON_HOOK);
    }
}

register_activation_hook(ALTEK_SYNC_PLUGIN_FILE, 'altek_sync_activate');
register_deactivation_hook(ALTEK_SYNC_PLUGIN_FILE, 'altek_sync_deactivate');

add_filter('cron_schedules', function ($schedules) {
    $settings = Altek_Sync_Settings::get();
    $minutes = max(1, intval($settings['cron_interval_minutes']));

    $schedules['altek_sync_custom'] = [
        'interval' => $minutes * 60,
        'display'  => sprintf('Product Synchronizer every %d minutes', $minutes),
    ];

    return $schedules;
});

add_action('plugins_loaded', function () {
    Altek_Sync_Settings::boot();
    Altek_Sync_CLI::register();

    $enqueue_product_event = function ($post_id, $event_type) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if (!$product) {
            return;
        }

        $payload = Altek_Sync_Mapper::map_product($product);
        if (empty($payload['item'])) {
            error_log('[Product Synchronizer] Product ' . intval($post_id) . ' skipped: empty SKU.');
            return;
        }

        Altek_Sync_Queue::enqueue($event_type, $post_id, $payload, $product->get_date_modified('edit'));
    };

    add_action('save_post_product', function ($post_id, $post, $update) use ($enqueue_product_event) {
        $event_type = $update ? 'product.updated' : 'product.created';
        $enqueue_product_event($post_id, $event_type);
    }, 10, 3);

    // Woo hooks are more reliable for product persistence lifecycle.
    add_action('woocommerce_new_product', function ($product_id) use ($enqueue_product_event) {
        $enqueue_product_event($product_id, 'product.created');
    }, 10, 1);

    add_action('woocommerce_update_product', function ($product_id) use ($enqueue_product_event) {
        $enqueue_product_event($product_id, 'product.updated');
    }, 10, 1);

    add_action('trashed_post', function ($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        $payload = [
            'item' => $product ? Altek_Sync_Mapper::normalize_sku($product->get_sku()) : '',
            'product_id' => intval($post_id),
        ];

        Altek_Sync_Queue::enqueue('product.trashed', $post_id, $payload, null);
    });

    add_action('before_delete_post', function ($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        $payload = [
            'item' => $product ? Altek_Sync_Mapper::normalize_sku($product->get_sku()) : '',
            'product_id' => intval($post_id),
        ];

        Altek_Sync_Queue::enqueue('product.deleted', $post_id, $payload, null);
    });

    $worker = new Altek_Sync_Worker();
    add_action(ALTEK_SYNC_CRON_HOOK, [$worker, 'run']);
});
