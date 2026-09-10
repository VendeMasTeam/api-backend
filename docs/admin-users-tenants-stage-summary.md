# Resumen De Etapa: Gestion Admin De Usuarios Y Tenants

Esta etapa queda implementada en modo seguro. La siguiente fase queda en pausa: eliminacion fisica de tenants, schemas y limpieza definitiva de datos.

## Que Se Hizo

Se completo la gestion de usuarios de tenant desde el panel superadmin.

Endpoints agregados o completados:

```http
GET /api/admin/tenants/{tenant_uid}/users
POST /api/admin/tenants/{tenant_uid}/users
PUT /api/admin/tenants/{tenant_uid}/users/{user_uid}
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/lock
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/unlock
POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/2fa/reset
DELETE /api/admin/tenants/{tenant_uid}
```

Tambien se actualizo:

- `public/openapi.yaml`
- Tests Feature de superadmin
- Documentacion frontend especifica

Documento frontend principal:

```text
docs/frontend-admin-tenant-user-management.md
```

## Comportamiento De Eliminacion

La eliminacion actual es logica, no fisica.

Cuando se elimina un usuario de tenant:

- Se bloquea el usuario.
- Se revocan sus tokens.
- No se borra el registro de la tabla `users`.
- No se borran datos relacionados.
- No se permite eliminar el ultimo `owner` activo del tenant.

Cuando se elimina un tenant:

- El tenant queda con `status = ARCHIVADO`.
- El tenant queda con `is_active = false`.
- Se bloquean sus usuarios.
- Se revocan los tokens de sus usuarios.
- No se elimina el schema.
- No se eliminan tablas.
- No se eliminan datos operativos.

Esto se hizo asi para proteger la VPS y evitar perdida accidental de informacion mientras termina la migracion por schema.

## Como Lo Debe Implementar El Frontend

### Listar Usuarios Del Tenant

```http
GET /api/admin/tenants/{tenant_uid}/users?page=1&per_page=25
```

Filtros disponibles:

```http
search=juan
role=owner|manager|seller
estado=ACTIVO|INACTIVO
```

Respuesta esperada:

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

### Editar Usuario

```http
PUT /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

Body:

```json
{
  "name": "Juan Perez",
  "email": "juan@empresa.com",
  "role": "manager",
  "is_active": true,
  "password": "NuevaPassword123*"
}
```

Todos los campos son opcionales. El frontend puede mandar solo los que cambian.

### Eliminar Usuario

```http
DELETE /api/admin/tenants/{tenant_uid}/users/{user_uid}
```

En UI conviene mostrarlo como:

```text
Desactivar usuario
```

No como borrado definitivo, porque el backend solo lo bloquea.

Si intenta eliminar el ultimo owner activo:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "user": ["No puedes eliminar el ultimo owner activo del tenant"]
  }
}
```

### Eliminar Tenant

```http
DELETE /api/admin/tenants/{tenant_uid}
```

En UI conviene mostrar confirmacion fuerte:

```text
Se archivara el tenant, se bloquearan sus usuarios y se revocaran sus sesiones. No se borraran datos ni schema.
```

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

### Restaurar Tenant

```http
POST /api/admin/tenants/{tenant_uid}/restore
```

Importante:

- Esto reactiva el tenant.
- Si los usuarios quedaron bloqueados por la eliminacion logica, el admin debe desbloquearlos con `/unlock`.

## Validaciones Importantes Del Frontend

El frontend deberia:

- No permitir eliminar/desactivar visualmente al ultimo `owner` activo si ya puede calcularlo.
- Igual manejar el error `422`, porque el backend es la fuente final.
- Mostrar `estado` como `Activo` o `Inactivo`.
- Usar `two_factor_enabled` para mostrar accion de reset 2FA.
- Despues de eliminar usuario, refrescar la tabla o moverlo a estado `Inactivo`.
- Despues de eliminar tenant, refrescar listado y mostrarlo como `ARCHIVADO` o sacarlo de la vista activa.

## Que Falta

Queda en pausa la fase peligrosa.

Pendientes:

- Definir si realmente se permitira borrado fisico de tenants.
- Crear comando seguro para eliminar schema:

```bash
php artisan tenants:schemas:drop {tenant_uid} --cascade
```

- Crear endpoint separado si se decide permitir borrar schema desde UI.
- Agregar doble confirmacion para borrado definitivo.
- Registrar auditoria de eliminacion:
  - quien elimino
  - cuando
  - tenant afectado
  - si se borro schema o solo se archivo
- Definir politica de retencion:
  - cuantos dias queda archivado
  - cuando se puede purgar
  - si se puede restaurar despues de purgar

## Estado Actual

Esta etapa esta funcional y probada.

Pruebas ejecutadas:

```bash
php artisan test --filter=SuperAdminManagementTest
php artisan test --filter=TwoFactorManagementTest
```

Resultado:

```text
SuperAdminManagementTest: passed
TwoFactorManagementTest: passed
```

## Despliegue

No requiere migracion nueva.

Despues de desplegar:

```bash
php artisan optimize:clear
php artisan route:cache
```

Si Swagger queda cacheado o no refleja endpoints:

```bash
php artisan optimize:clear
```
