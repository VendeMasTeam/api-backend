# Frontend: Contrato De Permisos En auth/init

Endpoint:

```http
GET /api/auth/init
```

Este documento define como debe interpretar el frontend los permisos de tenant y de plataforma.

## Decision Final

Usar:

```text
permissions.effective -> permisos de tenant
admin_permissions     -> permisos de plataforma/superadmin/soporte
```

No mezclar ambos campos.

## 1. Usuario De Tenant

Ejemplo:

```json
{
  "data": {
    "user": {
      "uid": "user-uuid",
      "role": "owner",
      "is_platform_admin": false,
      "admin_roles": []
    },
    "tenant": {
      "uid": "tenant-uuid",
      "name": "Acme Corporation",
      "plan": "PRO"
    },
    "permissions": {
      "effective": [
        "users.manage",
        "opportunities.read",
        "opportunities.manage"
      ],
      "modules": {
        "users": ["manage"],
        "opportunities": ["read", "manage"]
      }
    },
    "admin_permissions": []
  }
}
```

Reglas frontend:

- Para modulos del tenant usar `permissions.effective`.
- Para acciones admin globales ignorar `permissions.effective`.
- `admin_permissions` viene presente, pero vacio.

## 2. Usuario De Plataforma

Aplica para:

- Superadmin.
- Soporte.
- Usuarios globales sin tenant.

Ejemplo:

```json
{
  "data": {
    "user": {
      "uid": "user-uuid",
      "role": "platform-admin",
      "is_platform_admin": true,
      "admin_roles": [
        {
          "uid": "role-uuid",
          "name": "Soporte",
          "key": "support",
          "is_system": true
        }
      ]
    },
    "tenant": {
      "uid": null,
      "name": null,
      "plan": "free",
      "logo_url": null
    },
    "permissions": {
      "effective": [],
      "modules": []
    },
    "admin_permissions": [
      "admin.dashboard.read",
      "admin.tenants.manage",
      "admin.tenants.support",
      "admin.telemetry.read"
    ]
  }
}
```

Reglas frontend:

- Para panel admin usar `admin_permissions`.
- `permissions.effective` viene vacio porque no hay tenant.
- `tenant.uid` viene `null`.
- Los modulos normales del tenant no deben habilitarse con permisos admin.

## 3. Checks Recomendados

Helper sugerido:

```ts
export function hasTenantPermission(init: AuthInit, permission: string): boolean {
  return init.permissions?.effective?.includes(permission) ?? false;
}

export function hasAdminPermission(init: AuthInit, permission: string): boolean {
  return init.admin_permissions?.includes(permission) ?? false;
}
```

Uso:

```ts
const canManageUsers = hasTenantPermission(init, "users.manage");
const canEnterSupport = hasAdminPermission(init, "admin.tenants.support");
const canPurgeTenant = hasAdminPermission(init, "admin.tenants.purge");
```

## 4. Respuestas A Las Preguntas

### admin_permissions Es Definitivo Para Plataforma

Si. `admin_permissions` es la fuente de verdad para permisos de plataforma.

Ejemplos:

```text
admin.tenants.manage
admin.tenants.support
admin.tenants.purge
admin.telemetry.read
admin.alerts.manage
```

### permissions.effective En Plataforma

En una sesion de plataforma, `permissions.effective` debe venir vacio.

No usar `permissions.effective` para permisos admin.

### Presencia Del Campo

`admin_permissions` viene siempre en `auth/init`.

- Usuario de plataforma: array con permisos admin.
- Usuario de tenant: array vacio `[]`.

### Fuente De Verdad Para Botones Admin

Si. Para botones globales/admin:

```text
admin.tenants.support -> boton Ingresar al tenant
admin.tenants.purge   -> eliminar definitivamente
admin.tenants.manage  -> gestionar tenants
```

usar siempre:

```text
admin_permissions
```

