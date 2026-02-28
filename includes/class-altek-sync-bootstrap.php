<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Bootstrap {
    public static function enqueue_missing_products($per_page = 500) {
        if (!function_exists('wc_get_products')) {
            throw new Exception('WooCommerce no esta activo.');
        }

        $repo = new Altek_Sync_Pg_Repository();
        $existingSkuSet = $repo->get_existing_sku_set();

        $page = 1;
        $stats = [
            'wp_total' => 0,
            'pg_existing' => count($existingSkuSet),
            'enqueued_missing' => 0,
            'skipped_existing' => 0,
            'skipped_no_sku' => 0,
        ];

        do {
            $ids = wc_get_products([
                'status' => ['publish', 'draft', 'pending', 'private'],
                'limit' => max(1, intval($per_page)),
                'page' => $page,
                'return' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            if (empty($ids)) {
                break;
            }

            foreach ($ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) {
                    continue;
                }
                $stats['wp_total']++;

                $payload = Altek_Sync_Mapper::map_product($product);
                if (empty($payload['item'])) {
                    $stats['skipped_no_sku']++;
                    continue;
                }

                $sku = Altek_Sync_Mapper::normalize_sku($payload['item']);
                if (isset($existingSkuSet[$sku])) {
                    $stats['skipped_existing']++;
                    continue;
                }

                Altek_Sync_Queue::enqueue(
                    'product.bootstrap',
                    $product_id,
                    $payload,
                    $product->get_date_modified('edit')
                );
                $existingSkuSet[$sku] = true;
                $stats['enqueued_missing']++;
            }

            $page++;
        } while (count($ids) > 0);

        return $stats;
    }
}
