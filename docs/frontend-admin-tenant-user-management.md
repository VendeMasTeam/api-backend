# Gestion Frontend De Tenants Y Usuarios Desde Superadmin

Este flujo es para el panel de superadmin. En esta fase la eliminacion es logica: no se borra el schema ni los datos fisicos del tenant.

## Seguridad

Todas las rutas requieren:

- Bearer token de superadmin global.
- Permiso `admin.tenants.manage`.

Base URL:

```http
/api/admin
```

## Listar Usuarios De Un Tenant

```http
GET /api/admin/tenants/{tenant_uid}/users
```

Query params soportados:

```http
?page=1&per_page=25
?search=juan
?role=owner
?estado=ACTIVO
```

Valores:

- `role`: `owner`, `manager`, `seller`
- `estado`: `ACTIVO`, `INACTIVO`, `active`, `inactive`

Respuesta:

```json
{
  "success": true,
  "data": [
    {
      "uid": "user-uuid",
      "name": "Juan Perez",
      "email": "juan@empresa.com",
      "rol": "owner",
      "ultimo_acceso": null,
      "estado": "Activo",
      "two_factor_enabled": false
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 1
    }
  }
}
```

## Crear Usuario De Tenant

```http
POST /api/admin/tenants/{tenant_uid}/users
```

Body:

```json
{
  "name": "Juan Perez",
  "email": "juan@empresa.com",
  "role": "owner"
}
```

Notas:

- `role` es opcional. Si no se envia, usa `owner`.
- El backend genera password temporal.
- El backend intenta enviar correo de recuperacion.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario administrador del tenant creado",
  "data": {
    "uid": "user-uuid",
    "name": "Juan Perez",
    "email": "juan@empresa.com",
    "tenant_uid": "tenant-uuid",
    "roles": ["owner"],
    "reset_email_sent": true,
    "reset_email_queued": true
  }
}
```

## Editar Usuario De Tenant

```http
PUT /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

Body permitido:

```json
{
  "name": "Juan Perez",
  "email": "juan@empresa.com",
  "role": "manager",
  "is_active": true,
  "password": "NuevaPassword123*"
}
```

Campos:

- `name`: opcional.
- `email`: opcional, unico globalmente.
- `role`: opcional, `owner`, `manager`, `seller`.
- `is_active`: opcional.
- `active`: alias opcional de `is_active`.
- `status`: opcional, `ACTIVO`, `INACTIVO`, `active`, `inactive`.
- `password`: opcional, minimo 8 caracteres.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario actualizado",
  "data": {
    "uid": "user-uuid",
    "name": "Juan Perez",
    "email": "juan@empresa.com",
    "rol": "manager",
    "ultimo_acceso": null,
    "estado": "Activo",
    "two_factor_enabled": false
  }
}
```

Regla importante:

- El backend no permite dejar un tenant sin un `owner` activo.

## Bloquear Usuario

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/lock
```

Efecto:

- Bloquea el usuario.
- Revoca sus tokens.

## Desbloquear Usuario

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/unlock
```

Efecto:

- Quita el bloqueo.
- Reinicia intentos fallidos.

## Resetear 2FA De Usuario Tenant

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/2fa/reset
```

Efecto:

- Desactiva 2FA para ese usuario.
- No cambia password.
- El usuario podra iniciar sesion con email/password y activar 2FA de nuevo.

## Eliminar Usuario De Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

Esto es eliminacion logica.

Efecto:

- Bloquea el usuario.
- Revoca sus tokens.
- No borra fisicamente el registro.
- No permite eliminar el ultimo `owner` activo del tenant.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario eliminado",
  "data": {
    "uid": "user-uuid",
    "estado": "Inactivo"
  }
}
```

Error cuando es el ultimo owner activo:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "user": ["No puedes eliminar el ultimo owner activo del tenant"]
  }
}
```

## Eliminar Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}
```

Esto es eliminacion logica.

Efecto:

- Marca el tenant como `ARCHIVADO`.
- `is_active=false`.
- Bloquea usuarios del tenant.
- Revoca tokens de usuarios del tenant.
- No borra schema.
- No borra datos del tenant.

Respuesta:

```json
{
  "success": true,
  "message": "Tenant eliminado",
  "data": {
    "uid": "tenant-uuid",
    "nombre": "Acme",
    "estado": "ARCHIVADO",
    "schema_name": "tenant_acme",
    "schema_ready": true
  }
}
```

## Restaurar Tenant

Si se elimina por error, se puede restaurar con:

```http
POST /api/admin/tenants/{tenant_uid}/restore
```

Nota:

- Esto reactiva el tenant.
- Si los usuarios fueron bloqueados por `DELETE /tenant`, el frontend/admin debe desbloquear los usuarios necesarios con `/unlock`.

## Recomendacion UI

- Mostrar "Eliminar tenant" como accion peligrosa con confirmacion.
- Texto recomendado: "Se archivara el tenant y se bloquearan sus usuarios. No se borraran datos ni schema."
- Para usuarios, mostrar "Eliminar" como "Desactivar usuario" si se quiere evitar confusion.
- Deshabilitar la accion de eliminar si el usuario es el ultimo owner activo, o manejar el error 422 del backend.
