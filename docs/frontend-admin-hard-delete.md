# Frontend: Eliminacion Definitiva De Tenants Y Usuarios

Este flujo es para solicitudes legales de borrado real de datos. No reemplaza los botones normales de eliminar.

Base URL:

```http
/api/admin
```

Todas las rutas requieren:

```http
Authorization: Bearer {admin_token}
```

Permiso requerido:

```text
admin.tenants.purge
```

## Diferencia Entre Eliminar Normal Y Purge

Eliminar normal:

```http
DELETE /api/admin/tenants/{tenant_uid}
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

Hace eliminacion logica:

- Tenant: queda `ARCHIVADO`, usuarios bloqueados, tokens revocados.
- Usuario: queda bloqueado, tokens revocados.
- No borra registros fisicos.

Eliminacion definitiva:

```http
DELETE /api/admin/tenants/{tenant_uid}/purge
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}/purge
```

Hace borrado fisico irreversible.

## 1. Eliminar Definitivamente Un Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}/purge
```

Body:

```json
{
  "confirmation": "Nombre exacto del tenant",
  "delete_schema": true
}
```

Campos:

| Campo | Requerido | Tipo | Nota |
|---|---:|---|---|
| `confirmation` | Si | string | Debe coincidir exactamente con `tenant.nombre` |
| `delete_schema` | No | boolean | Default `true`; borra el schema tenant en PostgreSQL |

Ejemplo:

```json
{
  "confirmation": "Acme Corporation",
  "delete_schema": true
}
```

Respuesta exitosa:

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
    "support_sessions_deleted": 1,
    "support_tokens_deleted": 1,
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

Que borra:

- Registro de `tenants`.
- Usuarios del tenant.
- Roles del tenant.
- Tokens de usuarios.
- Sesiones/tokens de soporte asociados al tenant.
- Datos operativos en tablas compartidas `public.*` con `tenant_id`.
- Schema del tenant, si existe y `delete_schema=true`.

Errores comunes:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "confirmation": [
      "La confirmacion debe coincidir exactamente con el nombre del tenant"
    ]
  }
}
```

## 2. Eliminar Definitivamente Un Usuario Del Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}/purge
```

Body:

```json
{
  "confirmation": "email-del-usuario@empresa.com"
}
```

Campos:

| Campo | Requerido | Tipo | Nota |
|---|---:|---|---|
| `confirmation` | Si | string | Debe coincidir exactamente con el email del usuario |

Respuesta exitosa:

```json
{
  "success": true,
  "message": "Usuario eliminado definitivamente",
  "data": {
    "uid": "user-uuid",
    "email": "usuario@empresa.com",
    "tokens_deleted": 1,
    "support_sessions_deleted": 0,
    "pivot_rows_deleted": 1,
    "public_references_cleared": {
      "opportunities": {
        "owner_user_id": 2
      }
    },
    "schema_references_cleared": {
      "tasks": {
        "assigned_user_id": 4
      }
    }
  },
  "meta": null,
  "errors": null
}
```

Que borra:

- Registro fisico de `users`.
- Tokens del usuario.
- Roles/permisos asignados al usuario.
- Sesiones de soporte donde participa el usuario.

Que conserva:

- Datos operativos del tenant, como oportunidades, cotizaciones, facturas y tareas.

El backend limpia las referencias personales del usuario en datos operativos colocando esos campos en `null`, por ejemplo `owner_user_id`, `assigned_user_id`, `created_by_user_id`.

No permite eliminar definitivamente el ultimo owner activo del tenant:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "user": [
      "No puedes eliminar definitivamente el ultimo owner activo del tenant"
    ]
  }
}
```

## Recomendacion De UX

Usar dos acciones separadas:

- `Eliminar`: usa el endpoint normal y reversible/logico.
- `Eliminar definitivamente`: usa `/purge`.

Para `purge`, mostrar modal de alto riesgo:

```text
Esta accion no se puede deshacer.
Escribe exactamente: Acme Corporation
```

El boton debe estar deshabilitado hasta que el usuario escriba el texto exacto.

Despues de una respuesta exitosa:

- Si se borro un tenant: sacarlo del listado inmediatamente.
- Si se borro un usuario: sacarlo del listado de usuarios del tenant.
- Si responde `403`, ocultar o bloquear esta accion porque el admin no tiene `admin.tenants.purge`.

