<?php

namespace Tests\Unit;

use Database\Seeders\BambodiaTaxonomyThirteenSeeder;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BambodiaTaxonomyCatalogTest extends TestCase
{
    public function test_exact_ten_job_categories_have_distinct_slugs(): void
    {
        $catalog = new ReflectionClass(BambodiaTaxonomyThirteenSeeder::class);
        $names = $catalog->getReflectionConstant('JOBS')->getValue();
        self::assertSame([
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
        ], $names);
        self::assertCount(10, array_unique(array_map(Str::slug(...), $names)));
    }

    public function test_plugins_extensions_has_three_distinct_children_only(): void
    {
        $catalog = new ReflectionClass(BambodiaTaxonomyThirteenSeeder::class);
        $children = $catalog->getReflectionConstant('CHILDREN')->getValue();

        self::assertSame(['Browser Extensions', 'Laravel Packages', 'Integrations'], $children);
        self::assertNotContains('WordPress Plugins', $children);
        self::assertCount(3, array_unique(array_map(Str::slug(...), $children)));
    }
}
