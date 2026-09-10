<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait TenantScope
{
    protected static function bootTenantScope()
    {
        //  FILTRO AUTOMÁTICO POR TENANT
        static::addGlobalScope('tenant', function (Builder $builder) {

            // Solo aplica si hay usuario autenticado
            if (Auth::hasUser()) {
                $builder->where('tenant_id', Auth::user()->tenant_id);
            }

        });

        //  AUTO-ASIGNAR tenant_id AL CREAR
        static::creating(function ($model) {

            if (Auth::hasUser() && empty($model->tenant_id)) {
                $model->tenant_id = Auth::user()->tenant_id;
            }

        });
    }
}
