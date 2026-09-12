# Frontend - Pipeline Kanban E Historial

## Objetivo

El frontend debe dejar de inferir reglas del pipeline. El backend entrega:

- Board kanban con oportunidades activas.
- Oportunidades ganadas/perdidas recientes en columnas terminales.
- Posicion dentro de la columna para drag and drop.
- Historial paginado con todas las oportunidades.

## Board Kanban

```http
GET /api/opportunities/board
```

Permiso requerido:

```txt
opportunities.read
```

Query params:

| Param | Tipo | Default | Uso |
| ----- | ---- | ------- | --- |
| `search` | string | null | Busca por oportunidad o cliente relacionado |
| `origin` | string | null | Filtra por `lead_origin` |
| `product` | string | null | Filtra por producto/contexto comercial |
| `closed_days` | integer | 7 | Dias hacia atras para incluir cerradas |
| `include_closed` | boolean | true | Si es `false`, solo devuelve activas |
| `page` | integer | null | Paginacion opcional del board |
| `per_page` | integer | null | Tamano de pagina opcional |

Uso recomendado:

```http
GET /api/opportunities/board?closed_days=7&include_closed=true
```

Regla de UI:

- Mostrar en el board las oportunidades recibidas en `data.stages[*].items`.
- Si `item.is_closed = true`, aplicar estilo visual diferenciado.
- Si `item.closed_status = "won"`, es ganada.
- Si `item.closed_status = "lost"`, es perdida.
- Las oportunidades cerradas con mas de 7 dias no deben buscarse en el board; viven en historial.

Campos relevantes por item:

```json
{
  "uid": "opportunity-uuid",
  "title": "ERP",
  "amount": 15000000,
  "stage_uid": "stage-uuid",
  "stage_name": "Negociacion",
  "kanban_position": 1,
  "is_closed": false,
  "closed_status": null,
  "closed_at": null,
  "won_at": null,
  "lost_at": null
}
```

## Reordenar Dentro De Una Columna

```http
POST /api/opportunities/board/reorder
```

Permiso requerido:

```txt
opportunities.manage
```

Body:

```json
{
  "stage_uid": "stage-uuid",
  "ordered_opportunity_uids": [
    "opportunity-uuid-2",
    "opportunity-uuid-1",
    "opportunity-uuid-3"
  ]
}
```

Respuesta:

```json
{
  "success": true,
  "message": "Orden actualizado",
  "data": {
    "stage_uid": "stage-uuid",
    "ordered_opportunity_uids": [
      "opportunity-uuid-2",
      "opportunity-uuid-1",
      "opportunity-uuid-3"
    ]
  },
  "meta": null,
  "errors": null
}
```

Reglas:

- Este endpoint es para reordenar dentro de la misma columna.
- Todas las oportunidades enviadas deben pertenecer a `stage_uid`.
- Despues de guardar, refrescar el board o actualizar `kanban_position` localmente con el orden devuelto.

Error esperado si una oportunidad no pertenece a esa etapa:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "ordered_opportunity_uids": [
      "Todas las oportunidades deben existir y pertenecer a la etapa enviada"
    ]
  }
}
```

## Historial

```http
GET /api/opportunities/history
```

Permiso requerido:

```txt
opportunities.read
```

Query params:

| Param | Tipo | Uso |
| ----- | ---- | --- |
| `page` | integer | Pagina, default 1 |
| `per_page` | integer | Tamano de pagina |
| `search` | string | Busca por oportunidad o cliente relacionado |
| `stage_uid` | uuid | Filtra por etapa real |
| `owner_user_uid` | uuid | Filtra por responsable |
| `origin` | string | Filtra por origen del lead |
| `status` | string | `active`, `open`, `closed`, `won`, `lost` |
| `created_from` | date | Fecha creacion desde |
| `created_to` | date | Fecha creacion hasta |
| `closed_from` | date | Fecha cierre desde |
| `closed_to` | date | Fecha cierre hasta |

Ejemplos:

```http
GET /api/opportunities/history?page=1&per_page=25
GET /api/opportunities/history?status=closed&page=1&per_page=25
GET /api/opportunities/history?status=lost&closed_from=2026-06-01&closed_to=2026-06-30
```

Regla de UI:

- Usar historial para tabla paginada y filtros completos.
- No reconstruir el historial desde el board.
- Las cerradas antiguas que ya no aparecen en kanban deben aparecer aqui.

