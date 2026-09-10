# Checklist de despliegue VPS - Schema por tenant

Este documento sirve para desplegar la migracion a schema por tenant sin romper lo que ya funciona en el VPS.

La regla principal es avanzar por etapas:

- Primero desplegar codigo en modo `shared`.
- Luego preparar schemas sin activar runtime.
- Despues activar `hybrid`.
- Finalmente migrar y activar tenant por tenant.

No activar `TENANCY_MODE=schema` global hasta que todos los tenants esten probados.

## Etapa 0 - Backup y diagnostico inicial

Entrar al proyecto en el VPS:

```bash
cd /docker/crm-api-backend
```

Ver contenedores:

```bash
docker compose ps
```

Ver configuracion general:

```bash
docker compose exec app php artisan about
```

Crear backup antes de cualquier cambio:

```bash
docker compose exec postgres pg_dump -U postgres -d vende_mas > backup_pre_schema_$(date +%F_%H%M).sql
```

Si el usuario o base de datos no son `postgres` / `vende_mas`, revisar `.env`:

```bash
cat .env | grep DB_
```

Validar que el backup exista y tenga peso:

```bash
ls -lh backup_pre_schema_*.sql
```

## Etapa 1 - Desplegar codigo sin activar schemas

Bajar cambios:

```bash
git pull
```

Construir y levantar contenedores:

```bash
docker compose build app
docker compose up -d
```

Mantener en `.env`:

```env
TENANCY_MODE=shared
```

Ejecutar migraciones globales:

```bash
docker compose exec app php artisan migrate --force
```

Limpiar y regenerar cache:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

Validar logs:

```bash
docker compose logs app --tail=100
```

Pruebas minimas:

- Login superadmin.
- Login tenant existente.
- `GET /api/auth/init`.
- Pipeline listado.
- Productos listado.

En esta etapa el sistema sigue usando `public`, porque `TENANCY_MODE=shared`.

## Etapa 2 - Seleccionar tenant piloto

Listar tenants:

```bash
docker compose exec app php artisan tinker
```

Dentro de tinker:

```php
App\Models\Tenant::select('id','uid','name','domain','schema_name','schema_migrated_at')->get();
```

Salir:

```php
exit
```

Elegir un `TENANT_UID` para probar primero.

## Etapa 3 - Crear schema y tablas del tenant piloto

Crear/backfillear schema:

```bash
docker compose exec app php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
```

Correr migraciones tenant dentro del schema:

```bash
docker compose exec app php artisan tenants:migrate --tenant_uid=TENANT_UID
```

Verificar tablas y conteos:

```bash
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

En esta etapa el tenant todavia no esta activo en modo schema.

## Etapa 4 - Copiar datos del tenant piloto

Primero hacer dry-run completo:

```bash
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID
```

Si el resultado se ve correcto, copiar realmente:

```bash
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --execute
```

Verificar:

```bash
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

Si hay tablas con `schema` mayor que `public`, puede ser normal si ya se hicieron pruebas escribiendo en schema.

## Etapa 5 - Activar modo hybrid en VPS

Editar `.env`:

```env
TENANCY_MODE=hybrid
```

Regenerar cache:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

Activar solo el tenant piloto:

```bash
docker compose exec app php artisan tenants:schemas:activate TENANT_UID
```

Verificar:

```bash
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

## Etapa 6 - Pruebas funcionales del tenant piloto

Probar con el owner del tenant:

- Login.
- `GET /api/auth/init`.
- Pipeline.
- Crear oportunidad.
- Ver detalle de oportunidad.
- Crear actividad desde oportunidad.
- Crear tarea desde oportunidad.
- Listar productos.
- Crear producto.
- Crear cotizacion.
- Agregar items.
- Reservar stock para productos fisicos.
- Crear factura desde cotizacion.
- Crear pago.
- Custom fields: crear campo, listar modulos, asignar valor.
- Inteligencia competitiva: competidores, battlecards, razones de perdida.

Revisar logs si algo falla:

```bash
docker compose logs app --tail=200
```

## Etapa 7 - Rollback rapido del tenant piloto

Si algo falla en modo `hybrid`, desactivar solo ese tenant:

```bash
docker compose exec app php artisan tenants:schemas:deactivate TENANT_UID
```

Regenerar cache:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
```

El tenant vuelve a usar `public`.

## Etapa 8 - Migrar tenants restantes

Repetir por cada tenant:

```bash
docker compose exec app php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:migrate --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --execute
docker compose exec app php artisan tenants:schemas:activate TENANT_UID
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

Validar cada tenant antes de pasar al siguiente.

## Etapa 9 - Verificaciones globales

Ver todos los tenants:

```bash
docker compose exec app php artisan tenants:schemas:verify --all
```

Ver schemas huerfanos:

```bash
docker compose exec app php artisan tenants:schemas:verify --orphans
```

Revisar contenedores:

```bash
docker stats --no-stream
docker compose ps
```

Revisar logs:

```bash
docker compose logs app --tail=300
```

## Etapa 10 - Antes de cambiar a schema global

No cambiar a:

```env
TENANCY_MODE=schema
```

hasta cumplir:

- Todos los tenants tienen `schema_name`.
- Todos los tenants tienen `schema_migrated_at`.
- Todos los tenants fueron probados desde frontend.
- No hay errores 500/504 en flujos criticos.
- `tenants:schemas:verify --all` esta correcto.
- `tenants:schemas:verify --orphans` no muestra schemas inesperados.
- Jobs y scheduler usan contexto tenant.
- Reportes superadmin fueron revisados para no depender de un solo schema.

## Comandos utiles

Ver tenants:

```bash
docker compose exec app php artisan tinker
```

```php
App\Models\Tenant::select('id','uid','name','domain','schema_name','schema_migrated_at')->get();
```

Sembrar datos demo:

```bash
docker compose exec app php artisan tenants:seed-demo TENANT_UID
```

Sembrar datos demo con owner especifico:

```bash
docker compose exec app php artisan tenants:seed-demo TENANT_UID --user_email=owner@correo.com
```

Reiniciar servicios:

```bash
docker compose restart app queue scheduler
```

Ver logs de errores:

```bash
docker compose logs app --tail=300
```
