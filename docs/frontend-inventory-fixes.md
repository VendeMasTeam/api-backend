# Frontend: Ajustes Inventario

Este documento resume los cambios de backend para categorias, productos, bodegas y entrada de mercancia.

Base URL:

```http
/api/inventory
```

Todas las rutas requieren:

```http
Authorization: Bearer {tenant_token}
```

## 1. Categorias

Ruta:

```http
GET /api/inventory/categories
POST /api/inventory/categories
PUT /api/inventory/categories/{uid}
```

El campo `key` ya no debe mostrarse ni enviarse desde frontend.

Crear categoria:

```json
{
  "name": "Materia Prima",
  "description": "Insumos base"
}
```

Editar categoria:

```json
{
  "name": "Materia Prima Actualizada",
  "description": "Nueva descripcion"
}
```

Respuesta:

```json
{
  "success": true,
  "message": "Categoria creada",
  "data": {
    "uid": "category-uuid",
    "name": "Materia Prima",
    "description": "Insumos base"
  },
  "meta": null,
  "errors": null
}
```

Nota:

- Backend sigue generando `key` internamente para compatibilidad/base de datos.
- Frontend no lo recibe ni lo necesita.

## 2. Productos Con Paginacion

Ruta:

```http
GET /api/inventory/products?page=1&per_page=25
```

Query params:

```text
page
per_page
search
is_active=true|false|1|0
```

Si el frontend no envia `page/per_page`, backend pagina por defecto:

```text
page=1
per_page=25
```

Respuesta:

```json
{
  "success": true,
  "message": null,
  "data": [
    {
      "uid": "product-uuid",
      "sku": "CAM-001",
      "name": "Camisa",
      "category_uid": "category-uuid",
      "category_name": "Ropa",
      "unit_cost": 20000,
      "sale_price": 25000,
      "discount_percent": "0.00",
      "stock_physical_total": 10,
      "stock_reserved_total": 2,
      "stock_available_total": 8,
      "stock_state": "normal"
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 80,
      "last_page": 4,
      "from": 1,
      "to": 25,
      "has_more_pages": true
    }
  },
  "errors": null
}
```

## 3. Bodegas Con Paginacion

Ruta:

```http
GET /api/inventory/warehouses?page=1&per_page=25
```

Query params:

```text
page
per_page
search
has_stock=true|false|1|0
```

Si el frontend no envia `page/per_page`, backend pagina por defecto:

```text
page=1
per_page=25
```

Respuesta:

```json
{
  "success": true,
  "message": null,
  "data": [
    {
      "uid": "warehouse-uuid",
      "name": "Bodega Central",
      "code": "BCN01",
      "location": "Principal",
      "is_active": true,
      "summary": {
        "sku_count": 10,
        "total_physical": 120,
        "total_reserved": 20,
        "total_available": 100,
        "total_value": 2500000
      }
    }
  ],
  "summary": {
    "total_warehouses": 6,
    "active_warehouses": 5,
    "total_physical_stock": 120,
    "total_available_stock": 100,
    "total_stock_value": 2500000,
    "stock_physical_total": 120,
    "stock_available_total": 100,
    "stock_value_total": 2500000
  },
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 6
    }
  },
  "errors": null
}
```

Mapeo para las cards del frontend:

| Card | Campo backend |
| ---- | ------------- |
| Stock fisico total | `summary.stock_physical_total` |
| Disponible total | `summary.stock_available_total` |
| Valor en stock | `summary.stock_value_total` |

Los campos `total_physical_stock`, `total_available_stock` y `total_stock_value` siguen disponibles por compatibilidad. El frontend no debe sumar las filas de `data`, porque `data` puede venir paginado y no representa el total global.

## 4. Drawer Registrar Entrada De Mercancia

Nueva ruta para cargar opciones en una sola llamada:

```http
GET /api/inventory/stock/entry-options
```

Query params:

```text
warehouse_uid opcional
search opcional
is_active=true|false|1|0 opcional, default true
limit opcional, default 500, max 1000
```

Ejemplo:

```http
GET /api/inventory/stock/entry-options?warehouse_uid=warehouse-uuid&search=camisa
```

Respuesta:

```json
{
  "success": true,
  "message": null,
  "data": {
    "filters": {
      "warehouse_uid": "warehouse-uuid",
      "search": "camisa",
      "is_active": true,
      "limit": 500
    },
    "warehouses": [
      {
        "uid": "warehouse-uuid",
        "name": "Bodega Central",
        "code": "BCN01",
        "location": "Principal"
      }
    ],
    "products": [
      {
        "uid": "product-uuid",
        "sku": "CAM-001",
        "name": "Camisa",
        "category_uid": "category-uuid",
        "category_name": "Ropa",
        "unit_cost": 20000,
        "sale_price": 25000,
        "is_active": true,
        "stocks": [
          {
            "warehouse_uid": "warehouse-uuid",
            "warehouse_name": "Bodega Central",
            "warehouse_code": "BCN01",
            "physical_stock": 10,
            "reserved_stock": 2,
            "available_stock": 8
          }
        ]
      }
    ],
    "summary": {
      "warehouses": 1,
      "products": 1
    }
  },
  "meta": null,
  "errors": null
}
```

Uso recomendado:

- Al abrir el drawer, llamar una sola vez a `GET /api/inventory/stock/entry-options`.
- No llamar un endpoint por cada producto.
- Para guardar la entrada, seguir usando:

```http
POST /api/inventory/stocks/adjust/bulk
```

Body:

```json
{
  "warehouse_uid": "warehouse-uuid",
  "comment": "Entrada de mercancia",
  "items": [
    {
      "product_uid": "product-uuid",
      "quantity": 10
    }
  ]
}
```
