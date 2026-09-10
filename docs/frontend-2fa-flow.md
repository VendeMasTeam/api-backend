# Flujo Frontend Para 2FA

El 2FA es opcional por usuario. El backend ya no obliga a configurarlo en el primer login. Si el usuario lo activa, en siguientes inicios de sesion si debe enviar codigo 2FA o recovery code.

## Estado Del Usuario

Los endpoints de sesion devuelven:

```json
{
  "uid": "user-uuid",
  "name": "Juan",
  "email": "juan@example.com",
  "tenant_uid": "tenant-uuid",
  "is_platform_admin": false,
  "avatar_url": null,
  "two_factor_enabled": false,
  "locked_until": null
}
```

El frontend debe usar `two_factor_enabled` para mostrar:

- Si es `false`: boton "Activar 2FA".
- Si es `true`: boton "Regenerar codigos" y boton "Desactivar 2FA".

## Login

### Usuario Sin 2FA

`POST /api/login`

```json
{
  "email": "juan@example.com",
  "password": "secret123"
}
```

Respuesta:

```json
{
  "success": true,
  "data": {
    "token": "plain-token",
    "user": {
      "uid": "user-uuid",
      "two_factor_enabled": false
    }
  }
}
```

El frontend guarda el token y entra normal.

### Usuario Con 2FA

Primer intento sin codigo:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "two_factor_code": ["El codigo 2FA es obligatorio"]
  }
}
```

El frontend debe mostrar pantalla/campo para codigo.

Segundo intento:

`POST /api/login`

```json
{
  "email": "juan@example.com",
  "password": "secret123",
  "two_factor_code": "123456"
}
```

Tambien puede usar recovery code:

```json
{
  "email": "juan@example.com",
  "password": "secret123",
  "recovery_code": "ABCD-1234"
}
```

Respuesta correcta:

```json
{
  "success": true,
  "data": {
    "token": "plain-token",
    "user": {
      "uid": "user-uuid",
      "two_factor_enabled": true
    }
  }
}
```

## Activar 2FA Propio

Requiere Bearer token normal.

### 1. Generar Secreto

`GET /api/2fa/setup`

Respuesta:

```json
{
  "success": true,
  "message": "Escanea el codigo con tu autenticador",
  "data": {
    "secret": "BASE32SECRET",
    "otpauth_url": "otpauth://totp/...",
    "user": {
      "two_factor_enabled": false
    }
  }
}
```

Frontend:

- Renderizar QR desde `otpauth_url`.
- Mostrar `secret` como alternativa manual.
- No guardar `secret` permanentemente en localStorage.

### 2. Confirmar Codigo

`POST /api/2fa/confirm`

```json
{
  "code": "123456"
}
```

Respuesta:

```json
{
  "success": true,
  "message": "2FA configurado correctamente",
  "data": {
    "token": "new-plain-token",
    "recovery_codes": ["ABCD-1234", "EFGH-5678"],
    "user": {
      "two_factor_enabled": true
    }
  }
}
```

Frontend:

- Reemplazar el token anterior por `data.token`.
- Mostrar los `recovery_codes` una sola vez para que el usuario los guarde.
- Refrescar estado del usuario/auth init.

## Regenerar Recovery Codes

Solo para usuarios con 2FA activo.

`POST /api/2fa/recovery-codes/regenerate`

Respuesta:

```json
{
  "success": true,
  "message": "Recovery codes regenerados",
  "data": {
    "recovery_codes": ["ABCD-1234", "EFGH-5678"]
  }
}
```

Frontend debe mostrarlos una sola vez.

## Desactivar 2FA Propio

`DELETE /api/2fa`

```json
{
  "password": "secret123"
}
```

Respuesta:

```json
{
  "success": true,
  "message": "2FA desactivado correctamente",
  "data": {
    "user": {
      "two_factor_enabled": false
    }
  }
}
```

Si la password es incorrecta:

```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "password": ["La contraseña no es correcta"]
  }
}
```

## Admin Tenant: Resetear 2FA A Un Usuario

Requiere permiso `users.manage`.

`POST /api/users/{uid}/2fa/reset`

No requiere body.

Respuesta:

```json
{
  "success": true,
  "message": "2FA reseteado correctamente",
  "data": {
    "uid": "user-uuid",
    "email": "usuario@example.com",
    "two_factor_enabled": false
  }
}
```

Uso recomendado:

- En listado/detalle de usuarios, mostrar accion "Resetear 2FA" si el admin tiene `users.manage`.
- Pedir confirmacion visual antes de llamar.
- Despues del reset, ese usuario podra iniciar sesion solo con email/password y activar 2FA de nuevo desde su perfil.

## Superadmin Plataforma

Para usuarios globales de plataforma:

`POST /api/admin/platform/users/{uid}/2fa/reset`

No requiere body.

Respuesta:

```json
{
  "success": true,
  "message": "2FA reseteado correctamente",
  "data": {
    "uid": "platform-user-uuid",
    "two_factor_enabled": false
  }
}
```

Para usuarios de un tenant desde el panel superadmin:

`POST /api/admin/tenants/{tenant_uid}/users/{user_uid}/2fa/reset`

No requiere body.

Respuesta:

```json
{
  "success": true,
  "message": "2FA reseteado correctamente",
  "data": {
    "uid": "tenant-user-uuid",
    "name": "Usuario Tenant",
    "email": "usuario@tenant.com",
    "rol": "owner",
    "ultimo_acceso": null,
    "estado": "Activo",
    "two_factor_enabled": false
  }
}
```

## Reglas De UI

- No bloquear login por falta de 2FA si `two_factor_enabled` es `false`.
- Si login responde error en `two_factor_code`, pedir codigo 2FA manteniendo email/password en memoria del formulario.
- No guardar `secret` ni `recovery_codes` en localStorage.
- El QR se genera en frontend usando `otpauth_url`.
- Desactivar 2FA propio siempre debe pedir password.
- Reset administrativo no pide password del usuario afectado, porque es una accion de soporte/admin.
