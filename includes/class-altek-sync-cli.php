<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_CLI {
    public static function register() {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('altek-sync bootstrap', [__CLASS__, 'bootstrap']);
        WP_CLI::add_command('altek-sync run-worker', [__CLASS__, 'run_worker']);
    }

    public static function bootstrap($args, $assoc_args) {
        $batch = isset($assoc_args['batch']) ? intval($assoc_args['batch']) : 500;
        $stats = Altek_Sync_Bootstrap::enqueue_missing_products($batch);

        WP_CLI::log('Comparacion WooCommerce vs Altek completada.');
        WP_CLI::log('WP total revisados: ' . intval($stats['wp_total']));
        WP_CLI::log('PG SKUs existentes: ' . intval($stats['pg_existing']));
        WP_CLI::log('Omitidos por existir en PG: ' . intval($stats['skipped_existing']));
        WP_CLI::log('Omitidos por SKU vacio: ' . intval($stats['skipped_no_sku']));
        WP_CLI::success('Encolados por faltantes en PG: ' . intval($stats['enqueued_missing']));
    }

    public static function run_worker() {
        $worker = new Altek_Sync_Worker();
        $worker->run();
        WP_CLI::success('Worker ejecutado.');
    }
}
