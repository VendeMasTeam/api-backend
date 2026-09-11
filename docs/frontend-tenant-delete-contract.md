# Frontend: Contrato De Archivado Y Eliminacion De Tenants

Este documento define que endpoints debe usar el frontend para tenants cuando hay acciones parecidas.

Base URL:

```http
/api/admin
```

Todas las rutas requieren:

```http
Authorization: Bearer {admin_token}
```

## Decision Final

Usar como rutas canonicas:

```http
POST   /api/admin/tenants/{tenant_uid}/archive
POST   /api/admin/tenants/{tenant_uid}/restore
DELETE /api/admin/tenants/{tenant_uid}/purge
```

Dejar como legacy:

```http
DELETE /api/admin/tenants/{tenant_uid}
```

El frontend nuevo no deberia usar `DELETE /api/admin/tenants/{tenant_uid}` para tenants porque hace casi lo mismo que `archive`, pero su nombre puede confundir.

## 1. Archivar Tenant

Ruta canonica:

```http
POST /api/admin/tenants/{tenant_uid}/archive
```

Uso en UI:

```text
Archivar tenant
```

Efecto:

- Cambia `estado` a `ARCHIVADO`.
- Cambia `is_active` a `false`.
- No borra datos.
- Es reversible.

Respuesta:

```json
{
  "success": true,
  "message": "Tenant archivado",
  "data": {
    "uid": "tenant-uuid",
    "nombre": "Acme Corporation",
    "estado": "ARCHIVADO",
    "schema_name": "tenant_acme_corporation",
    "schema_ready": true
  },
  "meta": null,
  "errors": null
}
```

## 2. Restaurar Tenant

Ruta canonica:

```http
POST /api/admin/tenants/{tenant_uid}/restore
```

Uso en UI:

```text
Restaurar tenant
```

Efecto:

- Cambia `estado` a `ACTIVO`.
- Cambia `is_active` a `true`.
- Quita `expires_at`.
- No recrea datos porque nunca fueron borrados.

Respuesta:

```json
{
  "success": true,
  "message": "Tenant restaurado",
  "data": {
    "uid": "tenant-uuid",
    "nombre": "Acme Corporation",
    "estado": "ACTIVO",
    "schema_name": "tenant_acme_corporation",
    "schema_ready": true
  },
  "meta": null,
  "errors": null
}
```

## 3. Eliminar Normal

Ruta legacy:

```http
DELETE /api/admin/tenants/{tenant_uid}
```

Estado:

```text
No usar en frontend nuevo.
```

Motivo:

- Tambien hace eliminacion logica.
- Tambien se puede revertir con `/restore`.
- Puede confundirse con eliminacion definitiva.

Si una pantalla vieja ya lo usa, no se rompe. Pero para nuevas pantallas usar `/archive`.

## 4. Eliminar Definitivamente

Ruta canonica:

```http
DELETE /api/admin/tenants/{tenant_uid}/purge
```

Uso en UI:

```text
Eliminar definitivamente
```

Permiso requerido:

```text
admin.tenants.purge
```

Body:

```json
{
  "confirmation": "Acme Corporation",
  "delete_schema": true
}
```

Regla:

- `confirmation` debe coincidir exactamente con el nombre del tenant.
- Si no coincide, backend responde `422`.
- Si falta permiso, backend responde `403`.

Efecto:

- Borra el registro del tenant.
- Borra usuarios del tenant.
- Borra roles del tenant.
- Revoca tokens.
- Borra sesiones de soporte asociadas.
- Borra datos compartidos en `public.*` por `tenant_id`.
- Borra el schema del tenant si `delete_schema=true`.
- No tiene restore.

Respuesta:

```json
{
  "success": true,
  "message": "Tenant eliminado definitivamente",
  "data": {
    "uid": "tenant-uuid",
    "schema_name": "tenant_acme_corporation",
    "tenant_users_deleted": 3,
    "tenant_roles_deleted": 3,
    "tenant_user_tokens_deleted": 2,
    "support_sessions_deleted": 0,
    "support_tokens_deleted": 0,
    "shared_rows_deleted": {
      "opportunities": 12,
      "contacts": 30,
      "accounts": 8
    },
    "schema_deleted": true
  },
  "meta": null,
  "errors": null
}
```

## Recomendacion De UI

Mostrar dos acciones claras:

```text
Archivar
Eliminar definitivamente
```

No mostrar `Eliminar normal` en pantallas nuevas.

Para `Eliminar definitivamente`, usar modal de confirmacion fuerte:

```text
Esta accion no se puede deshacer.
Se eliminaran usuarios, datos y schema del tenant.
Escribe exactamente: Acme Corporation
```

El boton debe estar deshabilitado hasta que el texto coincida exactamente.

## Resumen Para Desarrollo

| Accion UI | Endpoint | Reversible | Usar |
|---|---|---:|---:|
| Archivar | `POST /api/admin/tenants/{tenant_uid}/archive` | Si | Si |
| Restaurar | `POST /api/admin/tenants/{tenant_uid}/restore` | Si | Si |
| Eliminar normal | `DELETE /api/admin/tenants/{tenant_uid}` | Si | No, legacy |
| Eliminar definitivo | `DELETE /api/admin/tenants/{tenant_uid}/purge` | No | Si, solo legal |

