<?php

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmEntity;
use App\Models\Opportunity;
use App\Models\Product;

if (! function_exists('formatCurrency')) {
    function formatCurrency($amount)
    {
        $tenant = auth()->user()?->tenant;

        if (! $tenant) {
            return number_format($amount, 2);
        }

        return $tenant->currency.' '.number_format($amount, 2);
    }
}

if (! function_exists('crm_entity_model_class')) {
    function crm_entity_model_class(string $type): ?string
    {
        return match ($type) {
            Account::class, 'Account', 'account', 'accounts', 'company', 'companies', 'COMPANY' => Account::class,
            Contact::class, 'Contact', 'contact', 'contacts', 'CONTACT' => Contact::class,
            CrmEntity::class, 'CrmEntity', 'crm_entity', 'crm-entity', 'crm_entities', 'crm-entities' => CrmEntity::class,
            Opportunity::class, 'Opportunity', 'opportunity', 'opportunities', 'pipeline', 'lead', 'leads', 'deal', 'deals', 'LEAD', 'DEAL' => Opportunity::class,
            Product::class, 'Product', 'product', 'products' => Product::class,
            default => null,
        };
    }
}

if (! function_exists('find_entity_by_uid')) {
    function find_entity_by_uid(string $type, string $uid)
    {
        $modelClass = crm_entity_model_class($type);

        if (! $modelClass) {
            return null;
        }

        return $modelClass::where('uid', $uid)->first();
    }
}

if (! function_exists('find_entity_id_by_uid')) {
    function find_entity_id_by_uid(string $type, string $uid): ?int
    {
        return find_entity_by_uid($type, $uid)?->id;
    }
}
