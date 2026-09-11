# Frontend: Gestion De Usuarios De Cualquier Tenant Desde Admin

Este documento describe como debe manejar el frontend la gestion de usuarios de cualquier tenant desde el panel admin/superadmin.

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
admin.tenants.manage
```

## 1. Listar Usuarios De Un Tenant

```http
GET /api/admin/tenants/{tenant_uid}/users
```

Query params:

```http
page=1
per_page=25
search=juan
role=owner|manager|seller
estado=ACTIVO|INACTIVO|active|inactive
```

Ejemplo:

```http
GET /api/admin/tenants/tenant-uuid/users?page=1&per_page=25&search=juan
```

Respuesta:

```json
{
  "success": true,
  "message": null,
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
  },
  "errors": null
}
```

## 2. Crear Usuario En Un Tenant

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

Campos:

| Campo | Requerido | Valores |
|---|---:|---|
| `name` | Si | string |
| `email` | Si | email unico |
| `role` | No | `owner`, `manager`, `seller` |

Si `role` no se envia, backend usa `owner`.

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
  },
  "meta": null,
  "errors": null
}
```

Nota frontend:

- Mostrar mensaje aunque el email no llegue.
- Si el correo falla, admin puede asignar password desde editar usuario.

## 3. Editar Usuario De Un Tenant

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

Todos los campos son opcionales. El frontend puede enviar solo lo que cambio.

Campos:

| Campo | Valores |
|---|---|
| `name` | string |
| `email` | email unico |
| `role` | `owner`, `manager`, `seller` |
| `is_active` | boolean |
| `active` | alias boolean |
| `status` | `ACTIVO`, `INACTIVO`, `active`, `inactive` |
| `password` | string minimo 8 |

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
  },
  "meta": null,
  "errors": null
}
```

## 4. Eliminar Usuario De Un Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

Importante:

Esto NO borra fisicamente el usuario.

El backend hace eliminacion logica:

- Bloquea el usuario.
- Revoca sus tokens.
- Lo deja como `estado: "Inactivo"`.
- No borra datos relacionados.
- No permite eliminar el ultimo `owner` activo del tenant.

Respuesta exitosa:

```json
{
  "success": true,
  "message": "Usuario eliminado",
  "data": {
    "uid": "user-uuid",
    "name": "Juan Perez",
    "email": "juan@empresa.com",
    "rol": "seller",
    "ultimo_acceso": null,
    "estado": "Inactivo",
    "two_factor_enabled": false
  },
  "meta": null,
  "errors": null
}
```

Error si intenta eliminar el ultimo owner activo:

```json
{
  "success": false,
  "message": "Validation error",
  "data": null,
  "meta": null,
  "errors": {
    "user": [
      "No puedes eliminar el ultimo owner activo del tenant"
    ]
  }
}
```

Recomendacion UI:

- Mostrar el boton como `Desactivar usuario` o `Eliminar usuario`.
- Mostrar confirmacion antes de llamar el endpoint.
- Texto sugerido:

```text
Este usuario quedara inactivo y se cerraran sus sesiones. No se borraran sus datos.
```

Despues de eliminar:

- Refrescar tabla.
- O actualizar la fila localmente con `estado: "Inactivo"`.
- Si la vista esta filtrada por activos, sacar la fila de la tabla.

Para borrado fisico por solicitud legal, usar el flujo de eliminacion definitiva en [frontend-admin-hard-delete.md](frontend-admin-hard-delete.md).

## 5. Bloquear Usuario

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/lock
```

Efecto:

- Bloquea usuario.
- Revoca tokens activos.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario bloqueado",
  "data": {
    "uid": "user-uuid",
    "estado": "Inactivo"
  }
}
```

## 6. Desbloquear Usuario

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/unlock
```

Efecto:

- Quita bloqueo.
- Reinicia intentos fallidos.

Respuesta:

```json
{
  "success": true,
  "message": "Usuario desbloqueado",
  "data": {
    "uid": "user-uuid",
    "estado": "Activo"
  }
}
```

## 7. Resetear 2FA De Usuario

```http
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/2fa/reset
```

Body:

```json
{}
```

Efecto:

- Desactiva 2FA para ese usuario.
- No cambia password.
- El usuario puede volver a activar 2FA desde su perfil.

Respuesta:

```json
{
  "success": true,
  "message": "2FA reseteado correctamente",
  "data": {
    "uid": "user-uuid",
    "name": "Juan Perez",
    "email": "juan@empresa.com",
    "rol": "owner",
    "estado": "Activo",
    "two_factor_enabled": false
  }
}
```

## 8. Reglas Que Debe Respetar El Frontend

El backend es la fuente final, pero el frontend debe ayudar a evitar errores.

Reglas:

- No permitir eliminar/desactivar visualmente el ultimo `owner` activo si se puede calcular.
- Igual manejar el error `422`, porque otro admin pudo cambiar datos en paralelo.
- Si `two_factor_enabled=true`, mostrar accion `Resetear 2FA`.
- Si `estado=Activo`, mostrar accion `Bloquear` o `Eliminar`.
- Si `estado=Inactivo`, mostrar accion `Desbloquear`.
- Despues de cambiar rol, refrescar usuarios del tenant.
- Despues de cambiar password, mostrar confirmacion generica, no mostrar el password de vuelta.

## 9. Estados Recomendados En UI

Mapeo:

```text
Activo   -> usuario puede iniciar sesion
Inactivo -> usuario bloqueado, tokens revocados
```

Roles:

```text
owner   -> administrador principal del tenant
manager -> gestor
seller  -> vendedor
```

## 10. Flujo Recomendado De Pantalla

En la vista de detalle del tenant:

1. Tab `Usuarios`.
2. Tabla con columnas:
   - Nombre
   - Email
   - Rol
   - Estado
   - Ultimo acceso
   - 2FA
   - Acciones
3. Acciones por usuario:
   - Editar
   - Cambiar password
   - Bloquear / Desbloquear
   - Resetear 2FA
   - Eliminar / Desactivar

## 11. Errores Comunes

Email repetido:

```json
{
  "message": "Validation error",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```

Usuario no encontrado:

```json
{
  "message": "Usuario no encontrado"
}
```

Tenant no encontrado:

```json
{
  "message": "Tenant no encontrado"
}
```

Sin permisos:

```json
{
  "message": "No autorizado"
}
```

## 12. Nota Importante

Esta etapa usa eliminacion logica para proteger datos en produccion.

No existe borrado fisico de usuario desde esta pantalla.

Si mas adelante se necesita purga definitiva, debe ser otro flujo separado con auditoria y doble confirmacion.
