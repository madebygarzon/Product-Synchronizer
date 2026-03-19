# Product Synchronizer — WooCommerce to PostgreSQL

Asynchronous synchronization plugin that keeps WooCommerce products aligned with PostgreSQL (`public.inv_items`) through event queue processing.

## Why recruiters should care
- Real integration challenge solved with robust queue-based design.
- Focus on data consistency and operational resilience.
- Suitable for high-volume catalog sync scenarios.

## Technical Highlights
- WooCommerce hooks capture product changes
- Internal queue table for event processing
- Batch worker via WP-Cron
- SKU-based upsert into PostgreSQL
- Configurable deletion mode (`soft`/`hard`)

## Stack
- WordPress + WooCommerce
- PHP (`pgsql` extension)
- PostgreSQL

## Setup
1. Install plugin in `wp-content/plugins`
2. Activate in WordPress
3. Configure PostgreSQL connection in plugin settings
4. Run initial bootstrap and queue processing

## CLI Helpers
```bash
wp altek-sync bootstrap --batch=500
wp altek-sync run-worker
```

## Panel admin (guia visual)

La pantalla de ajustes muestra:

- Total productos WordPress.
- Total productos Altek (Postgres).
- Estado de cola (`pending`, `retry`, `processing`, `failed`).
- Tabla de ultimos 20 eventos (SKU, evento, estado, intentos, error).
- Boton `Comparar y encolar faltantes`.
- Boton `Procesar cola ahora`.

## Bootstrap inicial (comparativo por SKU)

Compara los SKUs de WooCommerce contra Postgres y encola solo los faltantes:

```bash
wp altek-sync bootstrap --batch=500
```

Procesar worker manualmente (opcional):

```bash
wp altek-sync run-worker
```

## Tabla de cola en WP

`{wp_prefix}altek_sync_queue`

Estados:
- `pending`
- `processing`
- `retry`
- `done`
- `failed`

## Mapeo v1 Woo -> inv_items

- `item`: `trim(product.sku)`. Si es numérico y tiene menos de 9 dígitos, se completa con ceros a la izquierda hasta 9.
- `codigobarras`: `product.sku`
- `nombre`: `product.name`
- `nombreweb`: `product.name`
- `existencia`: `product.stock_quantity` (si maneja stock)
- `costoestandar`: `product.regular_price`
- `costopromedio`: `product.regular_price`
- `id_altek`: `product.id` (WordPress ID)
- `imagen`: URL imagen destacada
- `bloqueado`: `FALSE` en alta/actualizacion
- `costoultimacompra` (opcional): `meta _altek_last_cost` si existe
- `idcategoria` (opcional): primera categoria Woo
- `observaciones` (opcional): `short_description`

## Recomendaciones de BD

Antes de produccion:

- Crear unicidad por SKU normalizado (`upper(trim(item))`).
- Definir politica de bajas oficial (soft/hard).
- Usar usuario PG de minimo privilegio.
- Confirmar SSL (`sslmode=require` si aplica).

---
## Author

- Created by **Carlos Garzón**
- Software Engineer, Fullstack Developer.
---