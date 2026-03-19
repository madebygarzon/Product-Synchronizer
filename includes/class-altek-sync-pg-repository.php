<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Pg_Repository {
    private $settings;
    private $column_meta_cache = [];

    public function __construct() {
        $this->settings = Altek_Sync_Settings::get();
    }

    private function get_conn_string() {
        return sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5 sslmode=%s',
            $this->settings['host'],
            $this->settings['port'],
            $this->settings['database'],
            $this->settings['user'],
            $this->settings['password'],
            $this->settings['sslmode']
        );
    }

    private function schema() {
        $schema = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->settings['schema']);
        return $schema ?: 'public';
    }

    private function with_connection($callback) {
        if (!function_exists('pg_connect')) {
            throw new Exception('La extension pgsql de PHP no esta instalada.');
        }

        $conn = @pg_connect($this->get_conn_string());
        if (!$conn) {
            throw new Exception('No fue posible conectar a PostgreSQL.');
        }

        try {
            return $callback($conn);
        } finally {
            pg_close($conn);
        }
    }

    public function upsert_product(array $product) {
        $schema = $this->schema();
        $sku = Altek_Sync_Mapper::normalize_sku($product['item'] ?? '');
        if ($sku === '') {
            throw new Exception('SKU vacio.');
        }

        return $this->with_connection(function ($conn) use ($schema, $product, $sku) {
            pg_query($conn, 'BEGIN');
            try {
                $meta = $this->get_inv_items_column_meta($conn, $schema);
                $columns = $meta['columns'];
                $limits = $meta['limits'];

                $selectSql = "SELECT id FROM \"{$schema}\".\"inv_items\" WHERE upper(trim(item)) = upper(trim($1)) ORDER BY id ASC LIMIT 1 FOR UPDATE";
                $selectRes = pg_query_params($conn, $selectSql, [$sku]);
                if (!$selectRes) {
                    throw new Exception(pg_last_error($conn));
                }

                $existing = pg_fetch_assoc($selectRes);
                $writeData = $this->build_product_write_data($product, $sku, $columns, $limits);

                if (empty($writeData)) {
                    throw new Exception('No hay columnas compatibles para escribir en inv_items.');
                }

                if ($existing && isset($existing['id'])) {
                    $this->execute_dynamic_update($conn, $schema, 'inv_items', 'id', intval($existing['id']), $writeData);
                } else {
                    $this->execute_dynamic_insert($conn, $schema, 'inv_items', $writeData);
                }

                pg_query($conn, 'COMMIT');
                return true;
            } catch (Throwable $e) {
                pg_query($conn, 'ROLLBACK');
                throw $e;
            }
        });
    }

    public function delete_product_by_sku($sku) {
        $schema = $this->schema();
        $sku = Altek_Sync_Mapper::normalize_sku($sku);
        if ($sku === '') {
            return false;
        }

        return $this->with_connection(function ($conn) use ($schema, $sku) {
            $deleteMode = $this->settings['delete_mode'];
            if ($deleteMode === 'hard') {
                $sql = "DELETE FROM \"{$schema}\".\"inv_items\" WHERE upper(trim(item)) = upper(trim($1))";
                $res = pg_query_params($conn, $sql, [$sku]);
                if (!$res) {
                    throw new Exception(pg_last_error($conn));
                }
                return true;
            }

            $meta = $this->get_inv_items_column_meta($conn, $schema);
            $columns = $meta['columns'];
            $setClauses = [];
            $params = [];

            if (isset($columns['bloqueado'])) {
                $params[] = true;
                $setClauses[] = '"bloqueado" = $' . count($params);
            }
            if (isset($columns['existencia'])) {
                $params[] = 0;
                $setClauses[] = '"existencia" = $' . count($params);
            }

            if (empty($setClauses)) {
                return true;
            }

            $params[] = $sku;
            $sql = "UPDATE \"{$schema}\".\"inv_items\" SET " . implode(', ', $setClauses) . " WHERE upper(trim(item)) = upper(trim($" . count($params) . '))';
            $res = pg_query_params($conn, $sql, $params);
            if (!$res) {
                throw new Exception(pg_last_error($conn));
            }

            return true;
        });
    }

    public function count_products() {
        $schema = $this->schema();
        return $this->with_connection(function ($conn) use ($schema) {
            $sql = "SELECT COUNT(*) AS total FROM \"{$schema}\".\"inv_items\"";
            $res = pg_query($conn, $sql);
            if (!$res) {
                throw new Exception(pg_last_error($conn));
            }
            $row = pg_fetch_assoc($res);
            return isset($row['total']) ? intval($row['total']) : 0;
        });
    }

    public function get_existing_sku_set() {
        $schema = $this->schema();
        return $this->with_connection(function ($conn) use ($schema) {
            $sql = "SELECT item FROM \"{$schema}\".\"inv_items\" WHERE item IS NOT NULL AND trim(item) <> ''";
            $res = pg_query($conn, $sql);
            if (!$res) {
                throw new Exception(pg_last_error($conn));
            }

            $set = [];
            while ($row = pg_fetch_assoc($res)) {
                $sku = Altek_Sync_Mapper::normalize_sku($row['item'] ?? '');
                if ($sku !== '') {
                    $set[$sku] = true;
                }
            }
            return $set;
        });
    }

    private function build_product_write_data(array $product, $sku, array $columns, array $limits) {
        $writeData = [];

        $base = [
            'item' => $sku,
            'codigobarras' => (string) ($product['codigobarras'] ?? $sku),
            'nombre' => (string) ($product['nombre'] ?? ''),
            'nombreweb' => (string) ($product['nombreweb'] ?? ''),
            'existencia' => (float) ($product['existencia'] ?? 0),
            'costoestandar' => (float) ($product['costoestandar'] ?? 0),
            'costopromedio' => (float) ($product['costopromedio'] ?? 0),
            'id_altek' => (int) ($product['id_altek'] ?? 0),
            'imagen' => (string) ($product['imagen'] ?? ''),
            'bloqueado' => false,
        ];

        foreach ($base as $column => $value) {
            if (!isset($columns[$column])) {
                continue;
            }
            if (is_string($value)) {
                $value = $this->truncate_to_column($value, $limits[$column] ?? null);
            }
            $writeData[$column] = $value;
        }

        $optional = ['costoultimacompra', 'idcategoria', 'observaciones', 'alto', 'ancho'];
        foreach ($optional as $column) {
            if (!isset($columns[$column]) || !array_key_exists($column, $product)) {
                continue;
            }

            $value = $product[$column];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value)) {
                $value = $this->truncate_to_column($value, $limits[$column] ?? null);
            }
            $writeData[$column] = $value;
        }

        return $writeData;
    }

    private function execute_dynamic_insert($conn, $schema, $table, array $writeData) {
        $columns = array_keys($writeData);
        $values = array_values($writeData);
        $values = array_map([$this, 'normalize_pg_param_value'], $values);

        $quotedCols = array_map([$this, 'quote_ident'], $columns);
        $placeholders = [];
        for ($i = 1; $i <= count($values); $i++) {
            $placeholders[] = '$' . $i;
        }

        $sql = sprintf(
            'INSERT INTO "%s"."%s" (%s) VALUES (%s)',
            $schema,
            $table,
            implode(',', $quotedCols),
            implode(',', $placeholders)
        );

        $res = pg_query_params($conn, $sql, $values);
        if (!$res) {
            throw new Exception(pg_last_error($conn));
        }
    }

    private function execute_dynamic_update($conn, $schema, $table, $pkColumn, $pkValue, array $writeData) {
        $columns = array_keys($writeData);
        $values = array_values($writeData);
        $values = array_map([$this, 'normalize_pg_param_value'], $values);

        $setClauses = [];
        for ($i = 0; $i < count($columns); $i++) {
            $setClauses[] = $this->quote_ident($columns[$i]) . ' = $' . ($i + 1);
        }

        $values[] = $pkValue;
        $wherePlaceholder = '$' . count($values);

        $sql = sprintf(
            'UPDATE "%s"."%s" SET %s WHERE %s = %s',
            $schema,
            $table,
            implode(', ', $setClauses),
            $this->quote_ident($pkColumn),
            $wherePlaceholder
        );

        $res = pg_query_params($conn, $sql, $values);
        if (!$res) {
            throw new Exception(pg_last_error($conn));
        }
    }

    private function get_inv_items_column_meta($conn, $schema) {
        $cacheKey = $schema . '.inv_items';
        if (isset($this->column_meta_cache[$cacheKey])) {
            return $this->column_meta_cache[$cacheKey];
        }

        $sql = "SELECT column_name, character_maximum_length
                FROM information_schema.columns
                WHERE table_schema = $1
                  AND table_name = 'inv_items'";
        $res = pg_query_params($conn, $sql, [$schema]);
        if (!$res) {
            throw new Exception(pg_last_error($conn));
        }

        $columns = [];
        $limits = [];
        while ($row = pg_fetch_assoc($res)) {
            $columnName = (string) ($row['column_name'] ?? '');
            if ($columnName === '') {
                continue;
            }
            $columns[$columnName] = true;

            $rawLimit = $row['character_maximum_length'] ?? null;
            $limits[$columnName] = $rawLimit !== null ? intval($rawLimit) : null;
        }

        // Defensive fallback for legacy schemas where metadata may be incomplete.
        $fallbacks = [
            'item' => 60,
            'codigobarras' => 60,
            'nombre' => 60,
            'nombreweb' => 60,
            'imagen' => 255,
            'observaciones' => 1024,
        ];
        foreach ($fallbacks as $column => $fallbackLimit) {
            if (!isset($limits[$column]) || intval($limits[$column]) <= 0) {
                $limits[$column] = $fallbackLimit;
            }
        }

        $meta = [
            'columns' => $columns,
            'limits' => $limits,
        ];

        $this->column_meta_cache[$cacheKey] = $meta;
        return $meta;
    }

    private function truncate_to_column($value, $limit) {
        $value = (string) $value;
        $limit = $limit !== null ? intval($limit) : 0;
        if ($limit <= 0) {
            return $value;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit);
    }

    private function quote_ident($identifier) {
        $identifier = (string) $identifier;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new Exception('Identificador SQL invalido: ' . $identifier);
        }
        return '"' . $identifier . '"';
    }

    private function normalize_pg_param_value($value) {
        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }
        return $value;
    }
}
