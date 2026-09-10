# Plan de migracion a schema por tenant

## Objetivo

Separar los datos operativos de cada tenant en un schema PostgreSQL propio, manteniendo en `public` la informacion de plataforma necesaria para autenticar, facturar y administrar tenants.

## Estado actual

- La aplicacion usa una base compartida con columna `tenant_id`.
- Los modelos tenant-aware usan `TenantScope`.
- `public` contiene tanto datos de plataforma como datos operativos de tenants.

## Fase 1 - Preparacion segura

- Agregar `tenants.schema_name`.
- Bloquear nombres de tenant repetidos; el nombre comercial ahora es unico sin importar mayusculas/minusculas.
- Provisionar schema al crear tenant.
- Agregar comando para provisionar schemas de tenants existentes.
- Mantener `TENANCY_MODE=shared`, sin cambiar runtime ni mover datos.

Estado en codigo:

- `TENANCY_MODE=hybrid` es el valor recomendado para despliegue gradual en VPS.
- `TenantSchemaService::createSchema()` crea el schema si la conexion es PostgreSQL.
- `TenantSchemaService::bootstrapTenant()` crea schema, corre migraciones tenant, marca `schema_migrated_at` y permite provisionar datos base dentro del schema.
- `TenantSchemaService::generateSchemaName()` genera schemas desde el nombre del tenant, por ejemplo `tenant_yeda10` o `tenant_tt_corporation`. Si el nombre sanitizado choca con un schema existente, agrega un sufijo corto del UID.
- Existe un indice unico case-insensitive sobre `tenants.name` para impedir duplicados como `Acme` y `acme`.
- `tenants:schemas:provision` permite generar/backfillear `schema_name` sin cambiar el runtime.
- Existe una primera migracion tenant para CRM/pipeline en `database/migrations/tenant/2026_05_28_000001_create_tenant_crm_pipeline_tables.php`.
- Existe una segunda migracion tenant para inventario, catalogo, cotizaciones, facturas y competencia en `database/migrations/tenant/2026_05_28_000002_create_tenant_sales_inventory_finance_tables.php`.
- Existe una tercera migracion tenant para extensibilidad y operacion en `database/migrations/tenant/2026_05_28_000003_create_tenant_operations_extension_tables.php`.
- Existe una cuarta migracion tenant para reservas/movimientos, credito, compras, gastos, comisiones y partners en `database/migrations/tenant/2026_05_28_000004_create_tenant_procurement_commission_partner_tables.php`.

## Fase 2 - Clasificacion de tablas

Tablas que se quedan en `public`:

- tenants
- plans
- currencies
- permissions
- admin roles / permisos de plataforma
- users, tokens y sesiones mientras auth siga siendo global

Tablas candidatas a schema por tenant:

- accounts
- contacts
- crm_entities
- opportunities
- activities
- tasks
- quotations
- invoices
- inventory
- projects
- relations
- custom fields
- documents metadata
- reports operativos

Estado en codigo:

- `config/tenancy.php` contiene `global_tables` y `tenant_tables`.
- El manifiesto es explicito para evitar mover tablas administrativas por accidente.

## Fase 3 - Migraciones tenant-aware

- Separar migraciones de plataforma y migraciones tenant.
- Crear comando para ejecutar migraciones dentro de un schema especifico.
- Usar una tabla `tenant_migrations` dentro de cada schema para que cada tenant tenga su propio historial.
- No eliminar `tenant_id` al inicio; mantenerlo durante la transicion como cinturon de seguridad.

Comandos disponibles:

```bash
php artisan tenants:migrate --tenant_uid=TENANT_UID --pretend
php artisan tenants:migrate --tenant_uid=TENANT_UID
# Sin --tenant_uid migra todos los tenants.
php artisan tenants:migrate
```

## Fase 4 - Migracion de datos

- Congelar escrituras por tenant durante la ventana de migracion.
- Copiar datos desde `public` al schema del tenant.
- Validar conteos por tabla.
- Validar checksums simples en tablas criticas.
- Activar tenant en modo schema de forma individual.

Comando de preparacion/copia:

```bash
# Solo simula y muestra conteos. No copia datos.
php artisan tenants:schemas:copy-data TENANT_UID

# Simula solo algunas tablas.
php artisan tenants:schemas:copy-data TENANT_UID --tables=accounts,contacts,opportunities

# Copia realmente. Usar solo cuando las tablas tenant ya existen.
php artisan tenants:schemas:copy-data TENANT_UID --execute

# Copia realmente limpiando antes las tablas destino. Usar solo en ventana controlada.
php artisan tenants:schemas:copy-data TENANT_UID --execute --truncate
```

Regla importante: el comando no crea tablas operativas con `LIKE public...`; solo copia si la tabla destino ya existe en el schema tenant. Esto reduce riesgos de defaults, secuencias y llaves foraneas mal clonadas.

El comando copia usando columnas explicitas comunes entre `public.tabla` y `tenant_schema.tabla`, no `SELECT *`. Esto permite que PostgreSQL tenga distinto orden fisico de columnas sin romper la copia.

## Fase 5 - Switching runtime

- Resolver tenant por usuario autenticado.
- Setear `search_path` a `"schema_tenant", public` solo cuando el tenant este migrado.
- Resetear `search_path` al final de cada request y job.
- Mantener fallback a modo shared para tenants no migrados.

Estado en codigo:

- `TENANCY_MODE=shared`: ningun tenant usa schema en runtime.
- `TENANCY_MODE=hybrid`: solo tenants con `schema_migrated_at` usan schema.
- `TENANCY_MODE=schema`: todos los tenants usan schema.
- `POST /api/admin/tenants` ya ejecuta bootstrap automatico para nuevos tenants en PostgreSQL.
- `tenants:schemas:activate TENANT_UID` marca el tenant como migrado.
- `tenants:schemas:deactivate TENANT_UID` lo devuelve a modo shared cuando `TENANCY_MODE=hybrid`.

Flujo recomendado para activar un tenant de prueba:

```bash
php artisan migrate --force
php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
php artisan tenants:migrate --tenant_uid=TENANT_UID
php artisan tenants:schemas:copy-data TENANT_UID --tables=accounts,contacts,crm_entities,relations,opportunity_stages,opportunities,tasks,activities --execute
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_categories,warehouses,inventory_products,inventory_stocks,products,price_books,price_book_items,quotations,quotation_items,invoices,payments,competitors,battlecards,lost_reasons --execute
php artisan tenants:schemas:copy-data TENANT_UID --tables=product_versions,product_dependencies,custom_fields,custom_field_values,tags,taggables,documents,document_types,document_versions,alert_rules,document_alerts,projects,project_milestones,project_assignments,segments,teams,team_user,automation_rules,automation_assignment_rules --execute
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_reservations,inventory_movements,credit_profiles,exchange_rates,credit_rules,financial_records,commission_rules,commission_entries,commission_plans,commission_plan_role,commission_assignments,commission_targets,commission_runs,commission_run_items,expense_categories,cost_centers,suppliers,expenses,purchase_orders,purchase_order_items,purchase_order_payments,purchase_order_receipts,purchase_order_receipt_items,partners,partner_opportunities,opportunity_conflicts,partner_resources,partner_access --execute
php artisan tenants:schemas:activate TENANT_UID
```

Para rollback rapido en modo `hybrid`:

```bash
php artisan tenants:schemas:deactivate TENANT_UID
```

## Fase 6 - Limpieza

- Cuando todos los tenants esten migrados, evaluar remover dependencias fuertes de `tenant_id` en tablas operativas.
- Mantener `tenant_id` si aporta auditoria o consultas cross-tenant controladas.

## Regla de seguridad

No activar `TENANCY_MODE=schema` globalmente hasta que:

- Exista migracion tenant-aware probada.
- Los jobs/queues reseteen `search_path`.
- El login/auth/init funcionen desde `public`.
- Exista rollback por tenant.

## Siguiente paso recomendado

1. Crear migraciones tenant reales en `database/migrations/tenant`.
2. Empezar con el subconjunto ya creado: `accounts`, `contacts`, `crm_entities`, `relations`, `opportunity_stages`, `opportunities`, `tasks`, `activities`.
3. Ejecutar:

```bash
php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
php artisan tenants:migrate --tenant_uid=TENANT_UID --pretend
php artisan tenants:migrate --tenant_uid=TENANT_UID
php artisan tenants:schemas:copy-data TENANT_UID --tables=accounts,contacts,crm_entities,relations,opportunity_stages,opportunities,tasks,activities
```

4. Revisar que el dry-run reporte `tenant_exists=true` para esas tablas.
5. Copiar con `--execute` solo en tenant de prueba.

Segundo bloque disponible:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_categories,warehouses,inventory_products,inventory_stocks,products,price_books,price_book_items,quotations,quotation_items,invoices,payments,competitors,battlecards,lost_reasons
```

Si el dry-run esta correcto:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_categories,warehouses,inventory_products,inventory_stocks,products,price_books,price_book_items,quotations,quotation_items,invoices,payments,competitors,battlecards,lost_reasons --execute
```

Tercer bloque disponible:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=product_versions,product_dependencies,custom_fields,custom_field_values,tags,taggables,documents,document_types,document_versions,alert_rules,document_alerts,projects,project_milestones,project_assignments,segments,teams,team_user,automation_rules,automation_assignment_rules
```

Si el dry-run esta correcto:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=product_versions,product_dependencies,custom_fields,custom_field_values,tags,taggables,documents,document_types,document_versions,alert_rules,document_alerts,projects,project_milestones,project_assignments,segments,teams,team_user,automation_rules,automation_assignment_rules --execute
```

Cuarto bloque disponible:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_reservations,inventory_movements,credit_profiles,exchange_rates,credit_rules,financial_records,commission_rules,commission_entries,commission_plans,commission_plan_role,commission_assignments,commission_targets,commission_runs,commission_run_items,expense_categories,cost_centers,suppliers,expenses,purchase_orders,purchase_order_items,purchase_order_payments,purchase_order_receipts,purchase_order_receipt_items,partners,partner_opportunities,opportunity_conflicts,partner_resources,partner_access
```

Si el dry-run esta correcto:

```bash
php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_reservations,inventory_movements,credit_profiles,exchange_rates,credit_rules,financial_records,commission_rules,commission_entries,commission_plans,commission_plan_role,commission_assignments,commission_targets,commission_runs,commission_run_items,expense_categories,cost_centers,suppliers,expenses,purchase_orders,purchase_order_items,purchase_order_payments,purchase_order_receipts,purchase_order_receipt_items,partners,partner_opportunities,opportunity_conflicts,partner_resources,partner_access --execute
```

## Estado funcional actual

La base de schema por tenant ya esta funcional:

- Nuevos tenants pueden nacer con schema propio.
- Las tablas tenant existen en `database/migrations/tenant`.
- El runtime puede operar en modo `hybrid`.
- Los tenants ya migrados se enrutan por `tenant.schema`.
- Los tenants no migrados pueden seguir leyendo/escribiendo en `public` mientras `TENANCY_MODE=hybrid`.
- `tenant_id` se mantiene dentro de tablas tenant para compatibilidad, auditoria y rollback.

Lo que sigue no es cambiar rutas del frontend. El frontend sigue consumiendo `/api/...`; el backend resuelve el schema desde `token -> user -> tenant -> schema_name`.

## Despliegue por etapas en VPS

### Etapa 0 - Congelar y respaldar

Antes de desplegar:

```bash
docker compose ps
docker compose exec app php artisan about
docker compose exec app php artisan route:list --path=api
docker compose exec postgres pg_dump -U POSTGRES_USER -d vende_mas > backup_pre_schema.sql
```

Validar que el backup quedo creado y pesa mas de 0 bytes.

### Etapa 1 - Subir codigo sin activar schemas

Desplegar codigo nuevo, pero mantener:

```env
TENANCY_MODE=shared
```

Comandos:

```bash
git pull
docker compose build app
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

Pruebas minimas:

- Login superadmin.
- Login tenant existente.
- `GET /api/auth/init`.
- Pipeline listado.
- Productos listado.

Si algo falla en esta etapa, el problema no es el cambio de schema runtime, porque todavia sigue `shared`.

### Etapa 2 - Crear schemas sin mover runtime

Crear schemas y correr migraciones tenant sin activar tenants:

```bash
docker compose exec app php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:migrate --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

En esta etapa el tenant todavia opera desde `public`.

### Etapa 3 - Copiar datos por bloques

Hacer primero dry-run por bloque:

```bash
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --tables=accounts,contacts,crm_entities,relations,opportunity_stages,opportunities,tasks,activities
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_categories,warehouses,inventory_products,inventory_stocks,products,price_books,price_book_items,quotations,quotation_items,invoices,payments,competitors,battlecards,lost_reasons
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --tables=product_versions,product_dependencies,custom_fields,custom_field_values,tags,taggables,documents,document_types,document_versions,alert_rules,document_alerts,projects,project_milestones,project_assignments,segments,teams,team_user,automation_rules,automation_assignment_rules
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --tables=inventory_reservations,inventory_movements,credit_profiles,exchange_rates,credit_rules,financial_records,commission_rules,commission_entries,commission_plans,commission_plan_role,commission_assignments,commission_targets,commission_runs,commission_run_items,expense_categories,cost_centers,suppliers,expenses,purchase_orders,purchase_order_items,purchase_order_payments,purchase_order_receipts,purchase_order_receipt_items,partners,partner_opportunities,opportunity_conflicts,partner_resources,partner_access
```

Si el dry-run esta correcto, repetir con `--execute`.

### Etapa 4 - Activar solo un tenant piloto

Cambiar VPS a:

```env
TENANCY_MODE=hybrid
```

Recrear cache:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

Activar un solo tenant:

```bash
docker compose exec app php artisan tenants:schemas:activate TENANT_UID
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

Pruebas del tenant piloto:

- Login owner.
- `GET /api/auth/init`.
- `GET /api/opportunities/board`.
- Crear oportunidad.
- Crear actividad/tarea desde oportunidad.
- Listar productos.
- Crear cotizacion.
- Reservar stock.
- Crear factura desde cotizacion.
- Crear y asignar custom field.
- Listar razones de perdida/competidores.

### Etapa 5 - Migrar tenants restantes uno por uno

Por cada tenant:

```bash
docker compose exec app php artisan tenants:schemas:provision --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:migrate --tenant_uid=TENANT_UID
docker compose exec app php artisan tenants:schemas:copy-data TENANT_UID --execute
docker compose exec app php artisan tenants:schemas:activate TENANT_UID
docker compose exec app php artisan tenants:schemas:verify TENANT_UID
```

Validar cada tenant antes de continuar con el siguiente.

### Etapa 6 - Rollback rapido por tenant

Mientras VPS este en `hybrid`, si un tenant falla:

```bash
docker compose exec app php artisan tenants:schemas:deactivate TENANT_UID
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
```

Ese tenant vuelve a usar `public` sin tocar los datos copiados en su schema.

### Etapa 7 - Endurecimiento antes de schema global

Antes de cambiar a `TENANCY_MODE=schema`, validar:

- Todos los tenants tienen `schema_name` y `schema_migrated_at`.
- `tenants:schemas:verify --all` no muestra tablas faltantes.
- `tenants:schemas:verify --orphans` esta limpio.
- Jobs y scheduler usan `TenantSchemaService::runForTenant()`.
- Reportes superadmin que agregan datos operativos iteran por tenant o usan resumen global.
- No hay 500/504 en flujos criticos durante varios dias.

Solo despues de eso evaluar:

```env
TENANCY_MODE=schema
```
