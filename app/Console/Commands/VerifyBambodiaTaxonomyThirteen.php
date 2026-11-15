<?php

namespace App\Console\Commands;

use App\Models\MarketplaceCategory;
use App\Support\BambodiaTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class VerifyBambodiaTaxonomyThirteen extends Command
{
    protected $signature = 'bambodia:taxonomy:verify';
    protected $description = 'Read-only verification of ten Job categories and three nested Product categories';

    private const JOBS = [
        'Software Development', 'Design & Creative', 'Marketing & Communications',
        'Sales & Business Development', 'Customer Service & Support',
        'Finance & Accounting', 'Human Resources & Recruitment',
        'Administration & Operations', 'Education & Training', 'IT & Technical Support',
    ];

    private const CHILDREN = ['Browser Extensions', 'Laravel Packages', 'Integrations'];

    public function handle(): int
    {
        $parent = MarketplaceCategory::query()
            ->where('type', 'product')->where('slug', 'plugins-extensions')->first();
        if (! $parent || $parent->name !== 'Plugins & Extensions' ||
            ! $parent->is_active || $parent->parent_id !== null) {
            $this->error('BAMBODIA_TAXONOMY_PARENT=INVALID');
            return self::FAILURE;
        }

        $jobs = 0;
        foreach (self::JOBS as $name) {
            $category = MarketplaceCategory::query()
                ->where('type', 'job')->where('slug', Str::slug($name))->first();
            if (! $category || $category->name !== $name ||
                ! $category->is_active || $category->parent_id !== null) {
                $this->error('BAMBODIA_JOB_CATEGORY_MISSING='.Str::slug($name));
                return self::FAILURE;
            }
            $jobs++;
        }

        $children = 0;
        foreach (self::CHILDREN as $name) {
            $category = MarketplaceCategory::query()
                ->where('type', 'product')->where('slug', Str::slug($name))->first();
            if (! $category || $category->name !== $name || ! $category->is_active ||
                (int) $category->parent_id !== (int) $parent->id) {
                $this->error('BAMBODIA_PRODUCT_SUBCATEGORY_MISSING='.Str::slug($name));
                return self::FAILURE;
            }
            $children++;
        }

        $choices = BambodiaTaxonomy::jobChoices();
        foreach (self::JOBS as $name) {
            if (($choices[Str::slug($name)] ?? null) !== $name) {
                $this->error('BAMBODIA_JOB_CHOICES=INVALID');
                return self::FAILURE;
            }
        }

        $this->info('BAMBODIA_JOB_CATEGORIES='.$jobs.'/10');
        $this->info('BAMBODIA_PRODUCT_SUBCATEGORIES='.$children.'/3');
        $this->info('BAMBODIA_TAXONOMY_13=PASS');

        return self::SUCCESS;
    }
}
