# Frontend: Modo Soporte Para Ingresar A Un Tenant

Este flujo permite que un usuario de plataforma con permiso de soporte entre a un tenant desde el panel de clientes para revisar datos sin modificar informacion.

## Seguridad Del Flujo

- El boton solo debe mostrarse si el usuario admin tiene permiso `admin.tenants.support`.
- El acceso es temporal.
- El token de soporte es diferente al token del panel admin.
- El modo soporte es de solo lectura.
- Toda escritura devuelve `403`.

## Boton En Clientes

Ubicacion sugerida:

```text
Panel admin -> Clientes / Tenants -> Acciones -> Ingresar al tenant
```

Antes de llamar el endpoint, pedir un motivo:

```text
Motivo de soporte
```

## Iniciar Modo Soporte

```http
POST /api/admin/tenants/{tenant_uid}/support-login
Authorization: Bearer {admin_token}
```

Body:

```json
{
  "reason": "Soporte solicitado por el cliente para revisar pipeline"
}
```

Respuesta:

```json
{
  "success": true,
  "message": "Sesion de soporte iniciada",
  "data": {
    "token": "support-token",
    "expires_at": "2026-06-10T15:30:00.000000Z",
    "session": {
      "active": true,
      "uid": "support-session-uuid",
      "tenant_uid": "tenant-uuid",
      "tenant_name": "Acme Corporation",
      "support_user_uid": "admin-user-uuid",
      "support_user_name": "Soporte Plataforma",
      "impersonated_user_uid": "owner-user-uuid",
      "impersonated_user_email": "owner@tenant.com",
      "reason": "Soporte solicitado por el cliente para revisar pipeline",
      "started_at": "2026-06-10T14:30:00.000000Z",
      "expires_at": "2026-06-10T15:30:00.000000Z",
      "ended_at": null,
      "mode": "support",
      "readonly": true
    }
  },
  "meta": null,
  "errors": null
}
```

## Manejo De Tokens En Frontend

Al recibir `data.token`:

1. Guardar el token admin actual en memoria o storage separado.
2. Cambiar el token activo de API por `data.token`.
3. Redirigir al dashboard del tenant.
4. Llamar `GET /api/auth/init`.
5. Mostrar banner fijo de modo soporte.

Ejemplo de estado:

```json
{
  "admin_token": "token-original-admin",
  "active_token": "support-token",
  "support_mode": true
}
```

## Auth Init En Modo Soporte

```http
GET /api/auth/init
Authorization: Bearer {support_token}
```

Respuesta incluye:

```json
{
  "data": {
    "tenant": {
      "uid": "tenant-uuid",
      "name": "Acme Corporation"
    },
    "support_mode": {
      "active": true,
      "tenant_uid": "tenant-uuid",
      "tenant_name": "Acme Corporation",
      "mode": "support",
      "readonly": true,
      "expires_at": "2026-06-10T15:30:00.000000Z"
    }
  }
}
```

Si no esta en soporte:

```json
{
  "support_mode": {
    "active": false
  }
}
```

## Banner Recomendado

Mostrar algo como:

```text
Modo soporte activo: Acme Corporation. Solo lectura. Expira a las 10:30.
```

Accion visible:

```text
Salir de soporte
```

## Salir Del Modo Soporte

```http
POST /api/support-session/stop
Authorization: Bearer {support_token}
```

Respuesta:

```json
{
  "success": true,
  "message": "Sesion de soporte finalizada",
  "data": {
    "active": false,
    "uid": "support-session-uuid",
    "ended_at": "2026-06-10T14:45:00.000000Z"
  }
}
```

Luego el frontend debe:

1. Borrar `support_token`.
2. Restaurar `admin_token` como token activo.
3. Volver al panel admin de clientes.

## Escrituras Bloqueadas

Cualquier `POST`, `PUT`, `PATCH` o `DELETE` con token de soporte devuelve:

```json
{
  "success": false,
  "message": "Modo soporte solo permite lectura",
  "data": null,
  "meta": null,
  "errors": {
    "support_mode": [
      "No puedes modificar datos mientras estas en modo soporte"
    ]
  }
}
```

Excepciones permitidas:

```http
POST /api/support-session/stop
POST /api/logout
```

## Errores Importantes

Sin permiso:

```http
403
```

Tenant inactivo:

```json
{
  "message": "Tenant inactivo",
  "errors": {
    "tenant": ["No puedes ingresar en modo soporte a un tenant inactivo"]
  }
}
```

Tenant sin owner activo:

```json
{
  "message": "Validation error",
  "errors": {
    "support": ["El tenant no tiene un owner activo para iniciar soporte"]
  }
}
```

## Pendiente Para Una Fase Posterior

- Pantalla de historial de sesiones de soporte.
- Permitir acciones especificas de soporte bajo auditoria, si el negocio lo necesita.
- Notificar al tenant cuando soporte entre o salga.
