<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Queue {
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'altek_sync_queue';
    }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            locked_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_event_key (event_key),
            KEY idx_status_next (status, next_attempt_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function enqueue($event_type, $product_id, array $payload, $modified_date = null) {
        global $wpdb;

        $sku = isset($payload['item']) ? Altek_Sync_Mapper::normalize_sku($payload['item']) : '';
        if ($sku === '') {
            return false;
        }

        $version = $modified_date ? $modified_date->date('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
        $event_key = hash('sha256', implode('|', [$event_type, (int) $product_id, $sku, $version]));
        $now = gmdate('Y-m-d H:i:s');

        return (bool) $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table_name() . "
             (event_key, event_type, product_id, payload, status, attempts, next_attempt_at, created_at, updated_at)
             VALUES (%s, %s, %d, %s, 'pending', 0, %s, %s, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            $event_key,
            $event_type,
            (int) $product_id,
            wp_json_encode($payload),
            $now,
            $now,
            $now
        ));
    }

    public static function claim_batch($limit) {
        global $wpdb;

        $table = self::table_name();
        $now = gmdate('Y-m-d H:i:s');
        $limit = max(1, intval($limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at <= %s
             ORDER BY id ASC
             LIMIT %d",
            $now,
            $limit
        ));

        $claimed = [];
        foreach ($rows as $row) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'processing', locked_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('pending', 'retry')",
                $now,
                $now,
                $row->id
            ));
            if ($updated) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public static function mark_done($id) {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(self::table_name(), [
            'status' => 'done',
            'locked_at' => null,
            'updated_at' => $now,
            'last_error' => null,
        ], ['id' => (int) $id]);
    }

    public static function mark_failed($id, $attempts, $error) {
        global $wpdb;

        $attempts = intval($attempts) + 1;
        $delay = min(3600, 300 * (2 ** min(6, $attempts)));
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + $delay);

        $wpdb->update(self::table_name(), [
            'status' => $attempts >= 8 ? 'failed' : 'retry',
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttempt,
            'locked_at' => null,
            'last_error' => wp_strip_all_tags((string) $error),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int) $id]);
    }

    public static function get_status_counts() {
        global $wpdb;

        $table = self::table_name();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);

        $base = [
            'pending' => 0,
            'processing' => 0,
            'retry' => 0,
            'done' => 0,
            'failed' => 0,
        ];

        foreach ((array) $rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $base)) {
                $base[$status] = intval($row['total'] ?? 0);
            }
        }

        return $base;
    }

    public static function get_recent_events($limit = 20) {
        global $wpdb;

        $table = self::table_name();
        $limit = max(1, intval($limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_type, product_id, payload, status, attempts, last_error, created_at, updated_at
             FROM {$table}
             ORDER BY id DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }
}
