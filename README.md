# Product Synchronizer — WooCommerce to PostgreSQL

Asynchronous synchronization plugin that keeps WooCommerce products aligned with PostgreSQL (`public.inv_items`) through event queue processing.

## Why recruiters should care
- Real integration challenge solved with a robust queue-based design.
- Focus on data consistency and operational resilience.
- Suitable for high-volume catalog synchronization scenarios.

## Technical Highlights
- WooCommerce hooks capture product changes.
- Internal queue table for event processing.
- Batch worker via WP-Cron.
- SKU-based upsert into PostgreSQL.
- Configurable deletion mode (`soft`/`hard`).

## Stack
- WordPress + WooCommerce
- PHP (`pgsql` extension)
- PostgreSQL

## Setup
1. Install the plugin in `wp-content/plugins`.
2. Activate it in WordPress.
3. Configure the PostgreSQL connection in plugin settings.
4. Run the initial bootstrap and queue processing.

## CLI Helpers
```bash
wp altek-sync bootstrap --batch=500
wp altek-sync run-worker
```

## Admin Panel (visual guide)

The settings screen shows:

- Total WordPress products.
- Total Altek products (Postgres).
- Queue status (`pending`, `retry`, `processing`, `failed`).
- Table with the latest 20 events (SKU, event, status, attempts, error).
- `Compare and enqueue missing` button.
- `Process queue now` button.

## Initial Bootstrap (SKU comparison)

Compares WooCommerce SKUs against Postgres and enqueues only missing ones:

```bash
wp altek-sync bootstrap --batch=500
```

Run the worker manually (optional):

```bash
wp altek-sync run-worker
```

## Queue Table in WordPress

`{wp_prefix}altek_sync_queue`

Statuses:
- `pending`
- `processing`
- `retry`
- `done`
- `failed`

## v1 Mapping Woo -> inv_items

- `item`: `trim(product.sku)`. If numeric and shorter than 9 digits, it is left-padded with zeros to 9 digits.
- `codigobarras`: `product.sku`
- `nombre`: `product.name`
- `nombreweb`: `product.name`
- `existencia`: `product.stock_quantity` (when stock is managed)
- `costoestandar`: `product.regular_price`
- `costopromedio`: `product.regular_price`
- `id_altek`: `product.id` (WordPress ID)
- `imagen`: featured image URL
- `bloqueado`: `FALSE` on create/update
- `costoultimacompra` (optional): `meta _altek_last_cost` if present
- `idcategoria` (optional): first Woo category
- `observaciones` (optional): `short_description`
- `alto` (optional): `product.height` when numeric
- `ancho` (optional): `product.width` when numeric

## Database Recommendations

Before production:

- Create uniqueness on normalized SKU (`upper(trim(item))`).
- Define an official product deletion policy (soft/hard).
- Use a least-privilege PostgreSQL user.
- Confirm SSL (`sslmode=require`) when applicable.

---
## Author

- Created by **Carlos Garzón**
- Software Engineer, Fullstack Developer.

---
## Licenses

MIT
