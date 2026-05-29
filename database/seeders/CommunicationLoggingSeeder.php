<?php

namespace Database\Seeders;

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CommunicationLoggingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedCommunicationTypes();
        $this->seedCommunicationBlockTypes();
        $this->seedPermissions();
    }

    private function seedCommunicationTypes(): void
    {
        collect([
            ['name' => 'Phone', 'slug' => CommunicationType::PHONE, 'sort_order' => 10],
            ['name' => 'Email', 'slug' => 'email', 'sort_order' => 20],
            ['name' => 'Text', 'slug' => 'text', 'sort_order' => 30],
            ['name' => 'Visit', 'slug' => 'visit', 'sort_order' => 40],
        ])->each(fn (array $type) => CommunicationType::query()->updateOrCreate(
            ['slug' => $type['slug']],
            [
                'name' => $type['name'],
                'sort_order' => $type['sort_order'],
                'is_active' => true,
                'is_system' => true,
            ],
        ));
    }

    private function seedCommunicationBlockTypes(): void
    {
        collect([
            ['name' => 'Summary', 'slug' => CommunicationBlockType::SUMMARY, 'sort_order' => 10],
            ['name' => 'Suggestion', 'slug' => 'suggestion', 'sort_order' => 20],
            ['name' => 'Warranty', 'slug' => 'warranty', 'sort_order' => 30],
            ['name' => 'Complaint', 'slug' => 'complaint', 'sort_order' => 40],
            ['name' => 'Assistance', 'slug' => 'assistance', 'sort_order' => 50],
        ])->each(fn (array $type) => CommunicationBlockType::query()->updateOrCreate(
            ['slug' => $type['slug']],
            [
                'name' => $type['name'],
                'sort_order' => $type['sort_order'],
                'is_active' => true,
                'is_system' => true,
            ],
        ));
    }

    private function seedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            'communication-logs.view',
            'communication-logs.create',
            'communication-logs.update',
            'communication-logs.delete',
            'communication-logs.view-audits',
            'communication-types.view',
            'communication-types.create',
            'communication-types.update',
            'communication-types.delete',
            'communication-block-types.view',
            'communication-block-types.create',
            'communication-block-types.update',
            'communication-block-types.delete',
            'customer-contacts.view',
            'customer-contacts.create',
            'customer-contacts.update',
            'customer-contacts.delete',
        ])->map(fn (string $permission): Permission => Permission::query()->firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ]));

        $role = Role::query()->firstOrCreate([
            'name' => 'sales-rep',
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissions);

        User::query()->each(fn (User $user) => $user->assignRole($role));

        Permission::updateOrCreate(['name' => 'masquerade']);
    }
}
