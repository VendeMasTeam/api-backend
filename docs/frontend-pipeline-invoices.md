# Frontend - Facturas En Pipeline

## Tab De Facturas Del Lead/Oportunidad

Para listar todas las facturas asociadas a una oportunidad no uses la primera cotizacion como filtro.

Usar:

```http
GET /api/finance/invoices?opportunity_uid={opportunity_uid}&page=1&per_page=25
```

Permiso:

```txt
finance.read
```

Esto devuelve las facturas de todas las cotizaciones donde:

```txt
quotation.quoteable_type = Opportunity
quotation.quoteable_uid = opportunity_uid
```

Respuesta esperada:

```json
{
  "success": true,
  "data": [
    {
      "uid": "invoice-uuid",
      "quotation_uid": "quotation-uuid",
      "invoice_number": "INV-2026-0001",
      "status": "issued",
      "currency": "COP",
      "total": 1500000,
      "paid_total": 0,
      "outstanding_total": 1500000,
      "issued_at": "2026-06-10",
      "due_date": "2026-07-10"
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 2
    }
  }
}
```

## Crear Factura Desde Cotizacion

```http
POST /api/finance/invoices
```

Permiso:

```txt
finance.manage
```

Body minimo:

```json
{
  "quotation_uid": "26a1c3fd-8381-4a41-86bc-c3c2246f8d78",
  "currency": "COP"
}
```

Body completo opcional:

```json
{
  "quotation_uid": "26a1c3fd-8381-4a41-86bc-c3c2246f8d78",
  "currency": "COP",
  "exchange_rate": 1,
  "due_date": "2026-07-10",
  "status": "issued"
}
```

## Regla De Stock

Al convertir una cotizacion a factura:

- Los items tipo servicio no requieren stock.
- Los productos fisicos requieren stock.
- Si hay stock disponible pero no estaba reservado, el backend intenta reservar automaticamente.
- Si la reserva automatica alcanza, crea la factura y consume la reserva.
- Si no alcanza stock fisico disponible, responde 422 con detalle de productos faltantes.

Error ejemplo:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "quotation_uid": [
      "No puedes facturar sin stock reservado suficiente para los productos fisicos"
    ],
    "items": [
      "Licencia CRM SKU DEMO-LIC-001 en Bodega Demo Bogota: requiere 5 unidades, disponible 2 y faltan 3"
    ]
  }
}
```

## Nota Para UI

Despues de crear factura:

1. Refrescar `GET /api/finance/invoices?opportunity_uid={opportunity_uid}`.
2. Refrescar cotizaciones si se muestra el estado, porque la cotizacion pasa a `invoiced`.
3. Refrescar stock si la pantalla muestra disponibilidad.

