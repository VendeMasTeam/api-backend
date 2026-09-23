# Respuesta backend - ajustes 2026-09-11

Fecha de resolución: 2026-09-23.

Se revisó `2026-09-11-inventario-ajustes (1).md`. No se crearon endpoints nuevos: los flujos pendientes se resolvieron ampliando contratos existentes y documentando rutas que ya estaban disponibles.

## 1. Multimoneda

Frontend no necesita enviar `exchange_rate` al crear una cotización.

`POST /api/quotations` ahora:

1. Toma `currency` como moneda de la cotización.
2. Toma `local_currency` o la moneda principal del tenant.
3. Busca automáticamente la tasa más reciente `currency -> local_currency` en `currency/rates`.
4. Usa tasa `1` si ambas monedas son iguales.
5. Conserva tasa `1` por compatibilidad si todavía no existe una tasa para el par.

Si frontend envía `exchange_rate`, ese valor explícito tiene prioridad. Al editar, cambiar `currency` o `local_currency` sin mandar `exchange_rate` recalcula la tasa.

La factura creada desde una cotización hereda `currency` y `exchange_rate` de la cotización. No hace falta consultar `/currency/convert` antes de crearla.

## 2. Tags

Los tipos oficiales son:

| `entity_type` | Entidad backend |
|---|---|
| `CONTACT` | Contacto |
| `COMPANY` | Empresa (`Account`) |
| `LEAD` | Oportunidad en contexto de lead |
| `DEAL` | Oportunidad en contexto de negocio |

`LEAD` y `DEAL` apuntan al mismo modelo de oportunidad; son contextos comerciales, no tablas separadas.

### Asignar y retirar

```http
POST /api/tags/assign
Content-Type: application/json

{
  "tag_uid": "uuid-tag",
  "entity_type": "CONTACT",
  "entity_uid": "uuid-entidad"
}
```

```http
POST /api/tags/unassign
Content-Type: application/json

{
  "tag_uid": "uuid-tag",
  "entity_type": "CONTACT",
  "entity_uid": "uuid-entidad"
}
```

También se acepta `DELETE /api/tags/assign` con el mismo body para retirar. Backend valida que el tipo esté incluido en `tag.entity_types`.

Los listados y detalles de `/api/contacts` y `/api/accounts` ahora incluyen `tags`, por lo que los pickers pueden reflejar el estado actual sin consultas adicionales.

## 3. Segmentos

No se necesita un endpoint nuevo para “Ver contactos”. Ya existe:

```http
POST /api/segments/{segmentUid}/run
```

Respuesta relevante:

```json
{
  "data": {
    "segment": { "uid": "...", "execution_count": 2, "last_run_at": "..." },
    "count": 1,
    "data": [{ "uid": "...", "tags": [] }]
  }
}
```

`total_contacts` no es un contador persistido. Para mostrar el total vigente se debe usar `data.count` de cada ejecución.

Segmentación y automatización usan el mismo `ConditionEvaluator`, aunque cada formulario valida actualmente su vocabulario permitido. Segmentos acepta:

```text
equals, not_equals, contains, greater_than, less_than, in, not_in
```

El campo `tags` ya puede evaluarse por UID, clave o nombre:

```json
{
  "field": "tags",
  "operator": "contains",
  "value": "uuid-tag"
}
```

Para `in` y `not_in`, frontend debe enviar `value` como array. Backend mantiene compatibilidad con texto separado por comas.

## 4. Automatización

Para `apply_tag`, usar una referencia real:

```json
{
  "type": "apply_tag",
  "config": {
    "entity_type": "contact",
    "entity_uid": "uuid-contacto",
    "tag_uid": "uuid-tag"
  }
}
```

Backend también acepta temporalmente `config.tag` y lo busca como UID, `key` o nombre. Frontend debe migrar el input libre a un selector alimentado por `GET /api/tags` y enviar `tag_uid`.

La acción `update_field` acepta el contrato actual del frontend:

```json
{
  "type": "update_field",
  "config": {
    "field_name": "status",
    "field_value": "active"
  }
}
```

Solo se actualizan campos incluidos en `$fillable` de la entidad. Un nombre no permitido falla la acción sin modificar el registro.

Las reglas de asignación sí aceptan `conditions`; el formulario frontend puede reutilizar su constructor de condiciones. `logic` define `AND` o `OR`.

## 5. Contactos y empresas

- Empresa vinculada: usar `GET /api/accounts?search=...&per_page=...`; no depender de filas cargadas en la tabla principal.
- `institution_type`: el endpoint de opciones ya existe en `GET /api/tenant/institution-types`; su selector es trabajo de frontend.
- Bóveda: enviar `entity_type=accounts` para empresas y `entity_type=contacts` para personas/instituciones.
- Relaciones: usar el tipo real `account` o `contact`. Para desvincular una relación conocida, usar `DELETE /api/relations/{relationUid}`. El endpoint por par queda como compatibilidad.
- El tab “Todos” sigue requiriendo combinar `/accounts` y `/contacts`; no se creó otro endpoint para evitar duplicar capacidades de búsqueda ya existentes.

## 6. Pendientes exclusivos de frontend

1. Construir los pickers de tags en contactos, empresas y oportunidades.
2. Cambiar `apply_tag` de texto libre a `tag_uid`.
3. Hacer que “Ver contactos” ejecute `POST /segments/{uid}/run` y represente su resultado.
4. Enviar arrays reales para operadores `in` y `not_in`.
5. Consumir `/accounts?search=` en selectores de empresa y relaciones.
6. Consumir `/tenant/institution-types` en el formulario B2G.
7. Corregir `entity_type` en Bóveda y Relaciones según la entidad abierta.
8. Eliminar la tarjeta decorativa de velocidad de consulta o dejarla oculta hasta contar con una métrica real.
9. Evitar la consulta duplicada a `/automation/trigger-events`.

## 7. Verificación backend

Se ejecutaron:

```text
SalesBackendIntegrationTest
StructuralModulesIntegrationTest
SettingsBackendIntegrationTest
```

Resultado: 61 pruebas aprobadas, 540 aserciones.
