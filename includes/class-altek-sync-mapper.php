<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Mapper {
    public static function normalize_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku !== '' && ctype_digit($sku) && strlen($sku) < 9) {
            return str_pad($sku, 9, '0', STR_PAD_LEFT);
        }
        return $sku;
    }

    public static function map_product($product) {
        $sku = self::normalize_sku($product->get_sku());
        $name = (string) $product->get_name();
        $price = $product->get_regular_price() !== '' ? (float) $product->get_regular_price() : 0;
        $stock = $product->managing_stock() ? (float) $product->get_stock_quantity() : 0;

        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        $payload = [
            'item' => $sku,
            'codigobarras' => $sku,
            'nombre' => $name,
            'nombreweb' => $name,
            'existencia' => $stock,
            'costoestandar' => $price,
            'costopromedio' => $price,
            'imagen' => (string) $image_url,
            'id_altek' => (int) $product->get_id(),
            'bloqueado' => false,
        ];

        // Optional fields, only sent when meaningful.
        $lastCost = $product->get_meta('_altek_last_cost', true);
        if ($lastCost !== '' && is_numeric($lastCost)) {
            $payload['costoultimacompra'] = (float) $lastCost;
        }

        $categoryIds = method_exists($product, 'get_category_ids') ? (array) $product->get_category_ids() : [];
        if (!empty($categoryIds)) {
            $firstCategoryId = intval($categoryIds[0]);
            if ($firstCategoryId > 0) {
                $payload['idcategoria'] = $firstCategoryId;
            }
        }

        $shortDescription = method_exists($product, 'get_short_description')
            ? trim(wp_strip_all_tags((string) $product->get_short_description()))
            : '';
        if ($shortDescription !== '') {
            $payload['observaciones'] = $shortDescription;
        }

        return $payload;
    }
}
