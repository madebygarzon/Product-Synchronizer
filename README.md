# Product Synchronizer (WordPress Plugin)

Plugin MVP para sincronizar productos de WooCommerce hacia PostgreSQL (`public.inv_items`) con procesamiento asincrono.

## Flujo

1. Hooks de WooCommerce detectan cambios de producto.
2. El plugin encola eventos en tabla interna de WordPress.
3. WP-Cron procesa la cola en lotes.
4. Se ejecuta `upsert` en PostgreSQL por SKU normalizado.
5. En baja, aplica soft delete (`bloqueado=TRUE`) o hard delete, segun configuracion.

## Requisitos

- WordPress + WooCommerce activos.
- Extension `pgsql` habilitada en PHP.
- Acceso de red WordPress -> PostgreSQL.

## Instalacion paso a paso

1. Copia la carpeta `product-synchronizer` en `wp-content/plugins/`.
2. En WordPress, ve a `Plugins` y activa `Product Synchronizer`.
3. Verifica prerrequisitos:
- WooCommerce activo.
- Extension PHP `pgsql` habilitada.
- Conectividad de red desde WordPress hacia PostgreSQL.
4. Ve a `Settings > Product Synchronizer`.
5. Configura conexion PostgreSQL:
- `Host`
- `Port`
- `Database`
- `User`
- `Password`
- `Schema` (normalmente `public`)
- `SSL mode` (`disable/prefer/require` segun tu servidor)
6. Ajusta operacion:
- `Batch size` (recomendado inicial: `500`)
- `Cron (min)` (frecuencia de procesamiento automatico)
- `Delete mode` (`soft` recomendado; `hard` solo si lo necesitas)
7. Guarda cambios.
8. Confirma que el panel muestra contadores de WordPress y Altek sin errores de conexion.

## Primer arranque (sincronizacion inicial)

Opcion A (UI recomendada):
1. Click en `Comparar y encolar faltantes`.
2. Click en `Procesar cola ahora`.
3. Revisa `Estado de cola` y `Ultimos 20 eventos` hasta ver `retry=0` y `failed=0`.

Opcion B (WP-CLI):

```bash
wp altek-sync bootstrap --batch=500
wp altek-sync run-worker
```

## Validacion rapida post-instalacion

1. Crea un producto nuevo en WooCommerce con SKU.
2. Ejecuta `Procesar cola ahora`.
3. Verifica en PostgreSQL:

```sql
select item, nombre, existencia, bloqueado
from public.inv_items
where item = 'TU_SKU_NORMALIZADO';
```

4. Edita el producto y repite para validar `UPDATE`.
5. Elimina/manda a papelera y valida comportamiento segun `Delete mode`.

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
## ✍️ Autor

- Desarrollado por **Carlos Garzón**  
- Software Engineer, Fullstack Web Developer.
---