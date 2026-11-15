<?php

namespace Database\Seeders;

use App\Models\MarketplaceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BambodiaTaxonomyThirteenSeeder extends Seeder
{
    private const JOBS = [
        'Software Development',
        'Design & Creative',
        'Marketing & Communications',
        'Sales & Business Development',
        'Customer Service & Support',
        'Finance & Accounting',
        'Human Resources & Recruitment',
        'Administration & Operations',
        'Education & Training',
        'IT & Technical Support',
    ];

    private const CHILDREN = [
        'Browser Extensions',
        'Laravel Packages',
        'Integrations',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = User::query()->where('role', 'super_admin')
                ->where('account_status', 'active')
                ->orderBy('id')->first();
            if (! $admin) {
                throw new RuntimeException('No active super administrator; taxonomy not changed.');
            }

            $parent = MarketplaceCategory::query()
                ->where('type', 'product')->where('slug', 'plugins-extensions')
                ->lockForUpdate()->first();
            if (! $parent || $parent->name !== 'Plugins & Extensions' ||
                ! $parent->is_active || $parent->parent_id !== null) {
                throw new RuntimeException('Required Plugins & Extensions parent is missing or conflicting.');
            }

            foreach (self::JOBS as $index => $name) {
                $this->addOrVerify('job', $name, null, $index + 1, (int) $admin->id);
            }
            foreach (self::CHILDREN as $index => $name) {
                $this->addOrVerify('product', $name, (int) $parent->id, $index + 1, (int) $admin->id);
            }
        });

        $this->command?->info('BAMBODIA_TAXONOMY_13=VERIFIED');
    }

    private function addOrVerify(string $type, string $name, ?int $parent, int $order, int $admin): void
    {
        $slug = Str::slug($name);
        $row = MarketplaceCategory::query()->where('type', $type)
            ->where('slug', $slug)->lockForUpdate()->first();
        if ($row) {
            if ($row->name !== $name || ! $row->is_active ||
                ($row->parent_id === null ? null : (int) $row->parent_id) !== $parent) {
                throw new RuntimeException('Conflicting category: '.$type.'/'.$slug);
            }
            return;
        }
        MarketplaceCategory::query()->create([
            'type' => $type,
            'name' => $name,
            'description' => $type === 'job'
                ? 'Employment opportunities in '.$name.'.'
                : $name.' for ready-made software and digital products.',
            'is_active' => true,
            'sort_order' => $order,
            'parent_id' => $parent,
            'created_by' => $admin,
        ]);
    }
}
