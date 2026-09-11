# Frontend: Usuarios De Plataforma

Este documento cubre la gestion de usuarios globales de plataforma, como superadmins y soporte.

Base URL:

```http
/api/admin/platform/users
```

Todas las rutas requieren token de plataforma:

```http
Authorization: Bearer {platform_admin_token}
```

## Permisos

Gestion normal:

```text
admin.tenants.manage
```

Acciones criticas:

```text
admin.tenants.purge
```

Las acciones criticas son:

- Desactivar usuario de plataforma.
- Activar usuario de plataforma.
- Eliminar definitivamente usuario de plataforma.

## 1. Listar Usuarios De Plataforma

```http
GET /api/admin/platform/users?page=1&per_page=10
```

Query params:

```http
page=1
per_page=10
search=soporte
admin_role_uid=role-uuid
```

Respuesta:

```json
{
  "success": true,
  "message": null,
  "data": [
    {
      "uid": "user-uuid",
      "name": "Soporte Plataforma",
      "email": "soporte@vende-mas.com",
      "is_active": true,
      "status": "ACTIVO",
      "admin_roles": []
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 10,
      "total": 1
    }
  },
  "errors": null
}
```

## 2. Desactivar Usuario De Plataforma

```http
POST /api/admin/platform/users/{user_uid}/lock
```

Body:

```json
{}
```

Efecto:

- Bloquea el usuario.
- Revoca sus tokens.
- No borra datos.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario de plataforma desactivado",
  "data": {
    "uid": "user-uuid",
    "email": "soporte@vende-mas.com",
    "status": "INACTIVO",
    "is_active": false
  },
  "meta": null,
  "errors": null
}
```

Reglas:

- No se puede desactivar el propio usuario autenticado.
- No se puede desactivar el ultimo superadmin activo.

## 3. Activar Usuario De Plataforma

```http
POST /api/admin/platform/users/{user_uid}/unlock
```

Body:

```json
{}
```

Efecto:

- Quita bloqueo.
- Reinicia intentos fallidos.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario de plataforma activado",
  "data": {
    "uid": "user-uuid",
    "email": "soporte@vende-mas.com",
    "status": "ACTIVO",
    "is_active": true
  },
  "meta": null,
  "errors": null
}
```

## 4. Eliminar Definitivamente Usuario De Plataforma

```http
DELETE /api/admin/platform/users/{user_uid}/purge
```

Body:

```json
{
  "confirmation": "soporte@vende-mas.com"
}
```

Regla:

- `confirmation` debe ser exactamente el email del usuario.

Efecto:

- Borra fisicamente el usuario.
- Borra sus tokens.
- Borra relaciones con roles/permisos.
- Borra sesiones de soporte asociadas.
- No tiene restore.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario de plataforma eliminado definitivamente",
  "data": {
    "uid": "user-uuid",
    "email": "soporte@vende-mas.com",
    "tokens_deleted": 1,
    "support_sessions_deleted": 0,
    "pivot_rows_deleted": 1
  },
  "meta": null,
  "errors": null
}
```

Errores comunes:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "confirmation": [
      "La confirmacion debe coincidir exactamente con el email del usuario"
    ]
  }
}
```

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "user": [
      "No puedes eliminar definitivamente tu propio usuario de plataforma"
    ]
  }
}
```

## Recomendacion De UI

Mostrar acciones segun estado:

| Estado | Accion principal |
|---|---|
| `ACTIVO` | Desactivar |
| `INACTIVO` | Activar |

Mostrar `Eliminar definitivamente` separado, con modal de alto riesgo:

```text
Esta accion no se puede deshacer.
Escribe exactamente: soporte@vende-mas.com
```

El boton debe quedar deshabilitado hasta que el texto coincida exactamente.

No mostrar estas acciones si el usuario autenticado no tiene:

```text
admin.tenants.purge
```

