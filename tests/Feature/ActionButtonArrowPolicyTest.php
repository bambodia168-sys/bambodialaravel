<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ActionButtonArrowPolicyTest extends TestCase
{
    public function test_text_labeled_actions_never_append_decorative_arrow_icons(): void
    {
        $violations = [];
        $suffix = '/(?:[\\p{L}\\p{N}\\}\\]]|<\\/(?:span|strong|small)>)\\h+<x-icon\\s+name=["\\\'](?:arrow-up-right|chevron-right|external-link)["\\\'][^>]*\\/>/u';
        $unicodeSuffix = '/<(?:a|button)\\b[^>]*>[^<\\n]{1,180}(?:↗|→|➜|➔)\\h*<\\/(?:a|button)>/u';

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (preg_split('/\\R/', File::get($file->getPathname())) as $index => $line) {
                if (preg_match($suffix, $line) || preg_match($unicodeSuffix, $line)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                        .':'.($index + 1);
                }
            }
        }

        $this->assertSame([], $violations, 'Decorative trailing arrow on text-labeled action: '.implode(', ', $violations));
    }

    public function test_functional_icon_only_blog_slider_and_back_to_top_are_preserved(): void
    {
        $home = File::get(resource_path('views/home.blade.php'));
        $main = File::get(resource_path('views/layouts/main.blade.php'));
        $pager = File::get(resource_path('views/components/pagination.blade.php'));

        $this->assertStringContainsString('data-home-blog-next aria-label="Next blog posts"', $home);
        $this->assertStringContainsString('<x-icon name="chevron-right" size="19"/>', $home);
        $this->assertStringContainsString('data-back-to-top', $main);
        $this->assertStringContainsString('<x-icon name="arrow-up" size="19"/>', $main);
        $this->assertStringContainsString('>Next</a>', $pager);
        $this->assertStringNotContainsString('Next <x-icon', $pager);
    }
}
