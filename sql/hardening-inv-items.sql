-- Ejecutar en PostgreSQL antes de usar upsert de alto volumen.

-- 1) Diagnosticar duplicados por SKU normalizado
SELECT upper(trim(item)) AS sku_norm, COUNT(*) AS total
FROM public.inv_items
WHERE item IS NOT NULL AND trim(item) <> ''
GROUP BY upper(trim(item))
HAVING COUNT(*) > 1
ORDER BY total DESC;

-- 2) Unicidad por SKU normalizado
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ux_inv_items_item_norm
ON public.inv_items ((upper(trim(item))))
WHERE item IS NOT NULL AND trim(item) <> '';
