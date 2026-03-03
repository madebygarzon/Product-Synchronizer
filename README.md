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

## Operational Benefits
- Minimizes manual catalog reconciliation
- Adds visibility into queue health and failures
- Supports safe rollout with configurable batch + cron behavior

---
## Author

- Created by **Carlos Garzón**
- Software Engineer, Fullstack Developer.
---

## Licenses

MIT
