# Respuesta Backend - Inventario/Ajustes 2026-09-11

Estado: implementado y verificado en backend local.

## 1. Productos - `GET /api/inventory/master`

Este es el endpoint que debe usar frontend para la vista de productos/stock cuando necesita el desglose `stocks[]` por bodega.

Query params soportados:

- `page`
- `per_page`
- `search`
- `category_uid`
- `warehouse_uid`
- `stock_state`: `normal`, `low`, `out`
- `is_active`: `true`, `false`, `1`, `0`

Respuesta:

```json
{
  "success": true,
  "data": {
    "filters": {
      "category_uid": null,
      "warehouse_uid": null,
      "stock_state": null,
      "search": null,
      "is_active": null
    },
    "data": [
      {
        "uid": "uuid",
        "sku": "SKU-001",
        "name": "Producto",
        "category_uid": "uuid",
        "category_name": "Categoria",
        "unit_cost": 1200,
        "sale_price": 1500,
        "discount_percent": 0,
        "is_active": true,
        "stock_physical_total": 50,
        "stock_reserved_total": 5,
        "stock_available_total": 45,
        "stock_state": "normal",
        "stocks": [
          {
            "uid": "uuid",
            "warehouse_uid": "uuid",
            "physical_stock": 50,
            "reserved_stock": 5,
            "available_stock": 45,
            "warehouse": {
              "uid": "uuid",
              "name": "Bodega Central",
              "code": "BCN01"
            }
          }
        ]
      }
    ],
    "summary": {
      "products": 2,
      "active_products": 1,
      "out_of_stock_count": 1,
      "total_physical_stock": 50,
      "total_reserved_stock": 5,
      "total_available_stock": 45
    }
  },
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 2,
      "last_page": 1,
      "from": 1,
      "to": 2,
      "has_more_pages": false
    }
  },
  "errors": null
}
```

Frontend debe leer filas desde `response.data.data` y paginacion desde `response.meta.pagination`.

## 2. Bodegas duplicadas - `POST /api/inventory/warehouses`

Si el usuario crea o edita una bodega con `code` repetido dentro del tenant, backend ahora responde 422 limpio.

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "code": ["Ya existe una bodega con ese codigo"]
  }
}
```

No deberia volver a salir SQL crudo por este caso.

## 3. Cotizaciones - `GET /api/quotations`

El filtro `search` queda soportado para:

- `quote_number`
- `title`
- UID de la entidad asociada (`account`, `contact`, `crm_entity`)

Backend castea UUID a texto para PostgreSQL, asi que busquedas como `search=2` o un UUID ya no deben romper con 500.

## 4. Facturas - `GET /api/finance/invoices`

El filtro `search` queda soportado para:

- `invoice_number`
- UID de factura
- UID de cotizacion asociada

Tambien se mantiene:

- `opportunity_uid`
- `quotation_uid`
- `entity_type`
- `entity_uid`
- `status`
- `page`
- `per_page`

## 5. Reglas de credito

Backend si las usa en flujo real:

- Al crear cotizacion, `QuotationService` valida `CreditService::ensureCanOperate`.
- Al crear factura desde cotizacion, `InvoiceService` valida `CreditService::ensureCanOperate`.

Frontend no necesita bloquear manualmente antes. Puede mostrar el error 422 que llegue desde backend si el cliente esta bloqueado por riesgo de credito.

## 6. Multimoneda

Backend tiene:

- `GET /api/currency/rates`
- `POST /api/currency/rates`
- `POST /api/currency/convert`
- moneda principal en localization

Al crear factura desde cotizacion, backend usa `exchange_rate` para calcular subtotal/total en la moneda enviada.

Pendiente de producto/frontend:

- Definir si la UI debe pedir tasa al facturar o usar una tasa guardada.
- Definir si se va a crear pantalla para altas de nuevas monedas/tasas o solo editar tasas existentes.
- Conectar `currencyService.convert()` donde se necesiten previews de conversion.

## 7. Comisiones - `GET /api/commissions/plans`

`search` ya existe y filtra por nombre del plan.

## 8. Proyectos - `GET /api/projects`

Backend ahora devuelve `summary` agregado, independiente de la pagina actual.

```json
{
  "success": true,
  "data": [],
  "summary": {
    "total_projects": 3,
    "active_projects": 1,
    "completed_projects": 1,
    "paused_projects": 1,
    "overdue_milestones": 1
  },
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 10,
      "total": 3,
      "last_page": 1
    }
  }
}
```

Frontend debe mapear las cards desde `response.summary`, no desde la pagina actual.

## 9. Settings/Usuarios - `GET /api/users`

Backend ahora devuelve `summary` agregado, independiente de la pagina actual.

```json
{
  "success": true,
  "data": [],
  "summary": {
    "total_users": 3,
    "active_users": 2,
    "inactive_users": 1
  },
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 10,
      "total": 3,
      "last_page": 1
    }
  }
}
```

Frontend debe mapear las cards desde `response.summary`.

## 10. Contactos B2B/B2C/B2G

El flujo actual del frontend coincide con backend:

- B2B: `POST /api/accounts`
- B2C/B2G: `POST /api/contacts`

`POST /api/accounts` acepta:

```json
{
  "name": "Empresa SAS",
  "document": "900123456",
  "email": "contacto@empresa.com",
  "phone": "3000000000",
  "status": "active",
  "industry": "Software",
  "website": "https://empresa.com",
  "address": "Direccion"
}
```

Tambien acepta `tax_id`, pero backend lo normaliza a `document`.

`POST /api/contacts` acepta:

```json
{
  "type": "person",
  "name": "Ana Perez",
  "email": "ana@example.com",
  "phone": "3000000000",
  "status": "active",
  "job_title": "Compras",
  "company_uid": "uuid",
  "is_public_entity": false
}
```

Para B2G enviar `type: "government"` o `is_public_entity: true`. Backend normaliza `name` a `first_name`/`last_name` y `job_title` a `position`.

Nota: `country`, `city`, `id_number` e `institution_type` hoy se ignoran en `contacts`.

## 11. Tags - `POST /api/tags`

Backend ya soporta `entity_types` y se agrego migracion tenant para evitar el 500.

Payload:

```json
{
  "name": "VIP",
  "color": "#2563eb",
  "entity_types": ["CONTACT", "COMPANY"]
}
```

Respuesta:

```json
{
  "uid": "uuid",
  "name": "VIP",
  "key": "vip",
  "color": "#2563eb",
  "category": "general",
  "entity_types": ["CONTACT", "COMPANY"]
}
```

Pendiente de producto/frontend:

- Crear los pickers para asignar tags en contactos, empresas, leads/deals u otras entidades.
- El backend ya tiene `POST /api/tags/assign` y `POST /api/tags/unassign`.

## 12. Tipos de documento - `GET /api/document-types`

Backend ahora alinea `auth/init`:

- El modulo `settings` incluye permisos de documentos.
- El item `document-types` viaja en `modules[].items[]`.
- El plan permite modulo `documents` cuando se habilita `crm` o `settings`.

Frontend recomendado:

- Poner `itemKey: "document-types"` en el item "Tipos de Documento".
- Antes de renderizar la pagina, validar `documents.read` o el `item.enabled` recibido en `auth/init`.
- Para crear/editar/eliminar tipos de documento, validar `documents.manage`.

## Resumen de cambios backend

- `/api/inventory/master`: ahora devuelve `meta.pagination` raiz.
- `/api/inventory/warehouses`: duplicado de `code` devuelve 422 limpio.
- `/api/quotations`: `search` no debe romper por UUID en PostgreSQL.
- `/api/finance/invoices`: `search` no debe romper por UUID en PostgreSQL.
- `/api/projects`: agrega `summary`.
- `/api/users`: agrega `summary`.
- `/api/tags`: migracion tenant para `entity_types`.
- `/api/auth/init`: agrega `document-types` como item de settings y permisos de documentos.

## Comandos para desplegar migraciones tenant

Despues de subir el repo:

```bash
php artisan migrate --force
php artisan tenants:migrate --force
```

Para verificar un tenant:

```bash
php artisan tenants:schemas:verify TENANT_UID --tables=tags,projects,users
```
