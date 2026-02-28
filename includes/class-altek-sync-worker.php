<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Worker {
    public function run() {
        $settings = Altek_Sync_Settings::get();
        $limit = max(1, intval($settings['batch_size']));

        $repo = new Altek_Sync_Pg_Repository();
        $jobs = Altek_Sync_Queue::claim_batch($limit);

        foreach ($jobs as $job) {
            try {
                $payload = json_decode($job->payload, true);
                if (!is_array($payload)) {
                    throw new Exception('Payload invalido.');
                }

                if (in_array($job->event_type, ['product.deleted', 'product.trashed'], true)) {
                    $repo->delete_product_by_sku($payload['item'] ?? '');
                } else {
                    $repo->upsert_product($payload);
                }

                Altek_Sync_Queue::mark_done($job->id);
            } catch (Throwable $e) {
                Altek_Sync_Queue::mark_failed($job->id, $job->attempts, $e->getMessage());
                error_log('[Product Synchronizer] Job ' . intval($job->id) . ' failed: ' . $e->getMessage());
            }
        }
    }
}
