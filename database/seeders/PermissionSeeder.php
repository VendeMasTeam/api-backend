<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'accounts.read', 'module' => 'accounts', 'action' => 'read', 'description' => 'Ver cuentas'],
            ['key' => 'accounts.create', 'module' => 'accounts', 'action' => 'create', 'description' => 'Crear cuentas'],
            ['key' => 'accounts.update', 'module' => 'accounts', 'action' => 'update', 'description' => 'Actualizar cuentas'],
            ['key' => 'accounts.delete', 'module' => 'accounts', 'action' => 'delete', 'description' => 'Eliminar cuentas'],
            ['key' => 'contacts.read', 'module' => 'contacts', 'action' => 'read', 'description' => 'Ver contactos'],
            ['key' => 'contacts.create', 'module' => 'contacts', 'action' => 'create', 'description' => 'Crear contactos'],
            ['key' => 'contacts.update', 'module' => 'contacts', 'action' => 'update', 'description' => 'Actualizar contactos'],
            ['key' => 'contacts.delete', 'module' => 'contacts', 'action' => 'delete', 'description' => 'Eliminar contactos'],
            ['key' => 'relations.read', 'module' => 'relations', 'action' => 'read', 'description' => 'Ver relaciones'],
            ['key' => 'relations.create', 'module' => 'relations', 'action' => 'create', 'description' => 'Crear relaciones'],
            ['key' => 'relations.delete', 'module' => 'relations', 'action' => 'delete', 'description' => 'Eliminar relaciones'],
            ['key' => 'crm-entities.read', 'module' => 'crm-entities', 'action' => 'read', 'description' => 'Ver entidades CRM'],
            ['key' => 'crm-entities.create', 'module' => 'crm-entities', 'action' => 'create', 'description' => 'Crear entidades CRM'],
            ['key' => 'crm-entities.update', 'module' => 'crm-entities', 'action' => 'update', 'description' => 'Actualizar entidades CRM'],
            ['key' => 'tags.manage', 'module' => 'tags', 'action' => 'manage', 'description' => 'Administrar etiquetas'],
            ['key' => 'search.use', 'module' => 'search', 'action' => 'use', 'description' => 'Usar busqueda dinamica'],
            ['key' => 'dashboard.read', 'module' => 'dashboard', 'action' => 'read', 'description' => 'Ver dashboard analitico'],
            ['key' => 'tasks.read', 'module' => 'tasks', 'action' => 'read', 'description' => 'Ver tareas'],
            ['key' => 'tasks.create', 'module' => 'tasks', 'action' => 'create', 'description' => 'Crear tareas'],
            ['key' => 'tasks.update', 'module' => 'tasks', 'action' => 'update', 'description' => 'Actualizar tareas'],
            ['key' => 'tasks.delete', 'module' => 'tasks', 'action' => 'delete', 'description' => 'Eliminar tareas'],
            ['key' => 'interactions.read', 'module' => 'interactions', 'action' => 'read', 'description' => 'Ver timeline de interacciones'],
            ['key' => 'interactions.create', 'module' => 'interactions', 'action' => 'create', 'description' => 'Registrar interacciones'],
            ['key' => 'activities.read', 'module' => 'activities', 'action' => 'read', 'description' => 'Ver actividades'],
            ['key' => 'activities.create', 'module' => 'activities', 'action' => 'create', 'description' => 'Crear actividades'],
            ['key' => 'activities.update', 'module' => 'activities', 'action' => 'update', 'description' => 'Actualizar actividades'],
            ['key' => 'activities.delete', 'module' => 'activities', 'action' => 'delete', 'description' => 'Eliminar actividades'],
            ['key' => 'segments.read', 'module' => 'segments', 'action' => 'read', 'description' => 'Ver segmentos dinamicos'],
            ['key' => 'segments.manage', 'module' => 'segments', 'action' => 'manage', 'description' => 'Administrar segmentos dinamicos'],
            ['key' => 'teams.read', 'module' => 'teams', 'action' => 'read', 'description' => 'Ver equipos'],
            ['key' => 'teams.manage', 'module' => 'teams', 'action' => 'manage', 'description' => 'Administrar equipos'],
            ['key' => 'automation.read', 'module' => 'automation', 'action' => 'read', 'description' => 'Ver reglas de automatizacion'],
            ['key' => 'automation.create', 'module' => 'automation', 'action' => 'create', 'description' => 'Crear reglas de automatizacion'],
            ['key' => 'automation.update', 'module' => 'automation', 'action' => 'update', 'description' => 'Actualizar reglas de automatizacion'],
            ['key' => 'automation.delete', 'module' => 'automation', 'action' => 'delete', 'description' => 'Eliminar reglas de automatizacion'],
            ['key' => 'documents.read', 'module' => 'documents', 'action' => 'read', 'description' => 'Ver documentos'],
            ['key' => 'documents.create', 'module' => 'documents', 'action' => 'create', 'description' => 'Subir documentos'],
            ['key' => 'documents.manage', 'module' => 'documents', 'action' => 'manage', 'description' => 'Administrar tipos, versiones y alertas documentales'],
            ['key' => 'inventory.read', 'module' => 'inventory', 'action' => 'read', 'description' => 'Ver inventario comercial'],
            ['key' => 'inventory.manage', 'module' => 'inventory', 'action' => 'manage', 'description' => 'Administrar catalogo y stock'],
            ['key' => 'inventory.reserve', 'module' => 'inventory', 'action' => 'reserve', 'description' => 'Reservar stock'],
            ['key' => 'inventory.report', 'module' => 'inventory', 'action' => 'report', 'description' => 'Ver reportes de inventario'],
            ['key' => 'quotations.read', 'module' => 'quotations', 'action' => 'read', 'description' => 'Ver cotizaciones'],
            ['key' => 'quotations.create', 'module' => 'quotations', 'action' => 'create', 'description' => 'Crear cotizaciones'],
            ['key' => 'quotations.update', 'module' => 'quotations', 'action' => 'update', 'description' => 'Actualizar cotizaciones'],
            ['key' => 'products.read', 'module' => 'products', 'action' => 'read', 'description' => 'Ver catalogo de productos y servicios'],
            ['key' => 'products.manage', 'module' => 'products', 'action' => 'manage', 'description' => 'Administrar catalogo, versiones y dependencias'],
            ['key' => 'products.install', 'module' => 'products', 'action' => 'install', 'description' => 'Asignar productos instalados a clientes'],
            ['key' => 'price-books.read', 'module' => 'price-books', 'action' => 'read', 'description' => 'Ver listas de precios'],
            ['key' => 'price-books.manage', 'module' => 'price-books', 'action' => 'manage', 'description' => 'Administrar listas de precios'],
            ['key' => 'commissions.read', 'module' => 'commissions', 'action' => 'read', 'description' => 'Ver comisiones'],
            ['key' => 'commissions.manage', 'module' => 'commissions', 'action' => 'manage', 'description' => 'Administrar comisiones'],
            ['key' => 'opportunities.read', 'module' => 'opportunities', 'action' => 'read', 'description' => 'Ver oportunidades'],
            ['key' => 'opportunities.manage', 'module' => 'opportunities', 'action' => 'manage', 'description' => 'Administrar pipeline y oportunidades'],
            ['key' => 'finance.read', 'module' => 'finance', 'action' => 'read', 'description' => 'Ver finanzas operativas'],
            ['key' => 'finance.manage', 'module' => 'finance', 'action' => 'manage', 'description' => 'Sincronizar finanzas operativas'],
            ['key' => 'reports.read', 'module' => 'reports', 'action' => 'read', 'description' => 'Ver reportes unificados'],
            ['key' => 'custom-fields.manage', 'module' => 'custom-fields', 'action' => 'manage', 'description' => 'Administrar campos personalizados'],
            ['key' => 'settings.manage', 'module' => 'settings', 'action' => 'manage', 'description' => 'Administrar configuracion del tenant'],
            ['key' => 'logs.read', 'module' => 'logs', 'action' => 'read', 'description' => 'Ver logs del tenant'],
            ['key' => 'metrics.read', 'module' => 'metrics', 'action' => 'read', 'description' => 'Ver metricas del tenant'],
            ['key' => 'plans.manage', 'module' => 'plans', 'action' => 'manage', 'description' => 'Administrar planes'],
            ['key' => 'admin.dashboard.read', 'module' => 'admin', 'action' => 'dashboard.read', 'description' => 'Ver dashboard global de plataforma'],
            ['key' => 'admin.tenants.manage', 'module' => 'admin', 'action' => 'tenants.manage', 'description' => 'Administrar tenants desde superadmin'],
            ['key' => 'admin.tenants.support', 'module' => 'admin', 'action' => 'tenants.support', 'description' => 'Ingresar a tenants en modo soporte de solo lectura'],
            ['key' => 'admin.tenants.purge', 'module' => 'admin', 'action' => 'tenants.purge', 'description' => 'Eliminar definitivamente tenants y usuarios por solicitud legal'],
            ['key' => 'admin.billing.manage', 'module' => 'admin', 'action' => 'billing.manage', 'description' => 'Administrar facturacion global'],
            ['key' => 'admin.telemetry.read', 'module' => 'admin', 'action' => 'telemetry.read', 'description' => 'Ver telemetria global'],
            ['key' => 'admin.alerts.manage', 'module' => 'admin', 'action' => 'alerts.manage', 'description' => 'Administrar alertas globales'],
            ['key' => 'users.manage', 'module' => 'users', 'action' => 'manage', 'description' => 'Administrar usuarios'],
            ['key' => 'expenses.read', 'module' => 'expenses', 'action' => 'read', 'description' => 'Ver gastos y categorias'],
            ['key' => 'expenses.manage', 'module' => 'expenses', 'action' => 'manage', 'description' => 'Administrar gastos, categorias y proveedores'],
            ['key' => 'expenses.report', 'module' => 'expenses', 'action' => 'report', 'description' => 'Ver reportes de gastos'],
            ['key' => 'purchases.read', 'module' => 'purchases', 'action' => 'read', 'description' => 'Ver ordenes de compra'],
            ['key' => 'purchases.manage', 'module' => 'purchases', 'action' => 'manage', 'description' => 'Administrar ordenes de compra'],
            ['key' => 'competitive-intelligence.read', 'module' => 'competitive-intelligence', 'action' => 'read', 'description' => 'Ver inteligencia competitiva'],
            ['key' => 'competitive-intelligence.manage', 'module' => 'competitive-intelligence', 'action' => 'manage', 'description' => 'Administrar competidores, battlecards y lost reasons'],
            ['key' => 'competitive-intelligence.report', 'module' => 'competitive-intelligence', 'action' => 'report', 'description' => 'Ver analitica de inteligencia competitiva'],
            ['key' => 'partners.read', 'module' => 'partners', 'action' => 'read', 'description' => 'Ver partners y canales'],
            ['key' => 'partners.manage', 'module' => 'partners', 'action' => 'manage', 'description' => 'Administrar partners y canales'],
            ['key' => 'partners.opportunities.read', 'module' => 'partners', 'action' => 'opportunities.read', 'description' => 'Ver oportunidades de partners'],
            ['key' => 'partners.opportunities.manage', 'module' => 'partners', 'action' => 'opportunities.manage', 'description' => 'Administrar oportunidades de partners'],
            ['key' => 'partners.resources.read', 'module' => 'partners', 'action' => 'resources.read', 'description' => 'Ver recursos para partners'],
            ['key' => 'partners.resources.manage', 'module' => 'partners', 'action' => 'resources.manage', 'description' => 'Administrar recursos para partners'],
            ['key' => 'projects.read', 'module' => 'projects', 'action' => 'read', 'description' => 'Ver proyectos de implementacion'],
            ['key' => 'projects.manage', 'module' => 'projects', 'action' => 'manage', 'description' => 'Administrar proyectos, hitos y asignaciones'],
        ];

        foreach ($permissions as $permission) {
            $permission['scope'] = str_starts_with($permission['key'], 'admin.') || $permission['key'] === 'plans.manage'
                ? Permission::SCOPE_PLATFORM
                : Permission::SCOPE_TENANT;
            $model = Permission::query()->firstOrNew(['key' => $permission['key']]);
            $model->fill($permission);

            if (empty($model->uid)) {
                $model->uid = (string) Str::uuid();
            }

            $model->save();
        }
    }
}
