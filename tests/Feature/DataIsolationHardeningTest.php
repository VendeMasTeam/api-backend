<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataIsolationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_see_other_tenant_accounts(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A', 'owner');
        [$tenantB, $userB] = $this->tenantWithUser('Tenant B', 'owner');

        $accountA = $this->account($tenantA, $userA, 'Cuenta Tenant A');
        $this->account($tenantB, $userB, 'Cuenta Tenant B');

        Auth::login($userA);

        $this->assertSame([$accountA->uid], Account::query()->pluck('uid')->all());
    }

    public function test_seller_only_sees_own_accounts(): void
    {
        [$tenant, $seller] = $this->tenantWithUser('Tenant Seller', 'seller');
        $otherSeller = $this->user($tenant, 'other-seller');

        $ownAccount = $this->account($tenant, $seller, 'Cuenta Propia');
        $this->account($tenant, $otherSeller, 'Cuenta Otro Seller');

        Auth::login($seller);

        $this->assertSame([$ownAccount->uid], Account::query()->pluck('uid')->all());
    }

    public function test_manager_sees_own_and_subordinate_accounts(): void
    {
        [$tenant, $manager] = $this->tenantWithUser('Tenant Manager', 'manager');
        $seller = $this->user($tenant, 'seller-managed', $manager);
        $unrelatedSeller = $this->user($tenant, 'seller-unrelated');

        $managerAccount = $this->account($tenant, $manager, 'Cuenta Manager');
        $sellerAccount = $this->account($tenant, $seller, 'Cuenta Subordinado');
        $this->account($tenant, $unrelatedSeller, 'Cuenta No Visible');

        Auth::login($manager);

        $this->assertEqualsCanonicalizing(
            [$managerAccount->uid, $sellerAccount->uid],
            Account::query()->pluck('uid')->all()
        );
    }

    public function test_owner_sees_all_accounts_in_own_tenant(): void
    {
        [$tenant, $owner] = $this->tenantWithUser('Tenant Owner', 'owner');
        $seller = $this->user($tenant, 'seller-owner-scope');

        $ownerAccount = $this->account($tenant, $owner, 'Cuenta Owner');
        $sellerAccount = $this->account($tenant, $seller, 'Cuenta Seller');

        Auth::login($owner);

        $this->assertEqualsCanonicalizing(
            [$ownerAccount->uid, $sellerAccount->uid],
            Account::query()->pluck('uid')->all()
        );
    }

    public function test_platform_superadmin_can_enter_admin_routes(): void
    {
        $permission = Permission::query()->create([
            'key' => 'admin.dashboard.read',
            'module' => 'admin',
            'action' => 'read',
            'description' => 'Read admin dashboard',
            'scope' => Permission::SCOPE_PLATFORM,
        ]);

        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin@example.test',
            'password' => bcrypt('secret123'),
            'tenant_id' => null,
            'is_platform_admin' => true,
            'two_factor_secret' => 'secret',
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->givePermissionTo($permission);

        Sanctum::actingAs($admin, ['access:full', 'platform:admin']);

        $this->getJson('/api/admin/dashboard')->assertOk();
    }

    private function tenantWithUser(string $tenantName, string $roleKey): array
    {
        $tenant = Tenant::query()->create([
            'name' => $tenantName,
            'status' => 'active',
            'is_active' => true,
        ]);
        $role = $this->role($tenant, $roleKey);
        $user = $this->user($tenant, $roleKey.'-'.uniqid());
        $user->assignRole($role);

        return [$tenant, $user];
    }

    private function role(Tenant $tenant, string $key): Role
    {
        return Role::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => ucfirst($key),
            'key' => $key,
            'is_system' => true,
        ]);
    }

    private function user(Tenant $tenant, string $name, ?User $manager = null): User
    {
        return User::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'manager_id' => $manager?->getKey(),
            'name' => $name,
            'email' => $name.'-'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
        ]);
    }

    private function account(Tenant $tenant, User $owner, string $name): Account
    {
        return Account::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->getKey(),
            'owner_user_id' => $owner->getKey(),
            'name' => $name,
            'document' => 'DOC-'.uniqid(),
        ]);
    }
}
