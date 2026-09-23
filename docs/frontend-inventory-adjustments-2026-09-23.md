# Frontend - Integracion final de ajustes de inventario

Fecha: 2026-09-23

Estado backend: implementado y probado.

Base URL:

```http
/api/inventory
```

Todas las rutas requieren token del tenant. Las consultas usan `inventory.read` y las mutaciones usan `inventory.manage`. El modulo tambien debe estar habilitado en el plan (`features.inventory`).

## 1. Categorias

Rutas:

```http
GET    /api/inventory/categories
POST   /api/inventory/categories
PUT    /api/inventory/categories/{uid}
DELETE /api/inventory/categories/{uid}
```

El frontend no debe mostrar ni enviar `key`. Backend lo genera y lo oculta en la respuesta.

Crear:

```json
{
  "name": "Materia Prima",
  "description": "Insumos base"
}
```

Editar:

```json
{
  "name": "Materia Prima Actualizada",
  "description": "Nueva descripcion"
}
```

## 2. Listado de productos

### Listado simple

Usar para selectores o CRUD sin necesitar el desglose completo por bodega:

```http
GET /api/inventory/products?page=1&per_page=25&search=&is_active=true
```

La lista esta en `response.data` y la paginacion en `response.meta.pagination`.

### Vista principal de stock

Usar cuando la tabla necesita `stocks[]`, totales y estado por producto:

```http
GET /api/inventory/master?page=1&per_page=25
```

Filtros soportados:

```text
search
category_uid
warehouse_uid
stock_state=normal|low|out
is_active=true|false|1|0
page
per_page
```

En este endpoint las filas estan en `response.data.data`, el resumen en `response.data.summary` y la paginacion en `response.meta.pagination`.

```ts
const rows = response.data.data
const summary = response.data.summary
const pagination = response.meta.pagination
```

Campos principales de cada producto:

```json
{
  "uid": "product-uuid",
  "sku": "CAM-001",
  "name": "Camisa",
  "category_uid": "category-uuid",
  "category_name": "Ropa",
  "unit_cost": 20000,
  "sale_price": 25000,
  "discount_percent": "0.00",
  "is_active": true,
  "stock_physical_total": 10,
  "stock_reserved_total": 2,
  "stock_available_total": 8,
  "stock_state": "normal",
  "stocks": []
}
```

No calcular los totales usando solamente las filas de la pagina actual. Usar siempre `response.data.summary`.

## 3. Bodegas

```http
GET /api/inventory/warehouses?page=1&per_page=25
```

Filtros:

```text
search
has_stock=true|false|1|0
page
per_page
```

La respuesta tiene una estructura diferente a `master`:

```ts
const rows = response.data
const summary = response.summary
const pagination = response.meta.pagination
```

Cards:

| Card | Campo |
| --- | --- |
| Total de bodegas | `summary.total_warehouses` |
| Bodegas activas | `summary.active_warehouses` |
| Stock fisico | `summary.stock_physical_total` |
| Stock disponible | `summary.stock_available_total` |
| Valor del stock | `summary.stock_value_total` |

`summary` representa todos los registros que cumplen los filtros, no solo la pagina actual.

## 4. Drawer de entrada de mercancia

Al abrir el drawer hacer una sola consulta:

```http
GET /api/inventory/stock/entry-options
```

Query params opcionales:

```text
warehouse_uid
search
is_active=true|false|1|0
limit=500
```

`limit` permite de 1 a 1000 productos. `is_active` es `true` por defecto.

Cuando se envia `warehouse_uid`:

- `warehouses` devuelve solamente la bodega seleccionada.
- `stocks` de cada producto devuelve solamente el stock de esa bodega.
- Los productos sin stock previo siguen disponibles para registrar su primera entrada.

Respuesta:

```json
{
  "data": {
    "filters": {
      "warehouse_uid": "warehouse-uuid",
      "search": null,
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
  }
}
```

No hacer una consulta por producto para obtener su stock.

## 5. Guardar entrada de mercancia

```http
POST /api/inventory/stocks/adjust/bulk
Content-Type: application/json
```

```json
{
  "warehouse_uid": "warehouse-uuid",
  "comment": "Entrada OC-0089",
  "items": [
    {
      "product_uid": "product-uuid",
      "quantity": 10
    }
  ]
}
```

Reglas:

- `items` debe contener al menos un producto.
- `quantity` debe ser un entero mayor o igual a 1.
- Toda la entrada se procesa en transaccion.
- Backend crea los movimientos `adjustment_in` y actualiza los stocks.

Respuesta relevante:

```json
{
  "data": {
    "movements": [],
    "stocks": [],
    "summary": {
      "warehouse_uid": "warehouse-uuid",
      "items": 2,
      "total_quantity": 70
    }
  }
}
```

Al finalizar correctamente, invalidar como minimo:

```text
inventory-master
inventory-products
inventory-warehouses
inventory-movements
inventory-entry-options
```

Los nombres concretos de las query keys pueden adaptarse a los hooks actuales del frontend.

## 6. Errores que debe mostrar el frontend

Los errores de validacion llegan con HTTP `422`:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "quantity": ["Mensaje de validacion"]
  }
}
```

Mostrar primero `errors[campo][0]`. Casos importantes:

- Codigo de bodega duplicado.
- UUID de producto o bodega invalido.
- Cantidad menor a 1.
- Stock fisico por debajo del reservado.
- Stock insuficiente para una salida o transferencia.

## 7. Checklist frontend

- Eliminar `key` del formulario de categorias.
- Mantener paginacion, busqueda y filtros en estado servidor.
- Usar `/inventory/master` para la tabla detallada de stock.
- Leer correctamente `data.data` en master y `data` en bodegas/productos.
- Usar los summaries del backend para las cards.
- Cargar el drawer con una sola llamada a `stock/entry-options`.
- Guardar todas las filas con una sola llamada a `stocks/adjust/bulk`.
- Invalidar queries de inventario despues de una entrada exitosa.
- Mostrar errores `422` por campo sin reemplazarlos por un mensaje generico.
