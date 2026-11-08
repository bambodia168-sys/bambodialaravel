<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use Illuminate\View\View;

class ContentController extends Controller
{
    public function show(string $content): View
    {
        abort_unless(preg_match('/^(\d{9})_([a-z0-9-]+)$/', $content, $match) === 1, 404);
        $entry = ContentEntry::query()->with('author:id,name', 'featuredMedia:id,public_id,review_status,alt_text,mime_type')
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->where('status', 'published')
            ->firstOrFail();

        return view('content.show', compact('entry'));
    }

    public function blog(): View
    {
        $posts = ContentEntry::query()->with('author:id,name', 'featuredMedia:id,public_id,review_status,alt_text,mime_type')
            ->where('type', 'post')->where('status', 'published')
            ->latest('published_at')->paginate(10);

        return view('content.blog', compact('posts'));
    }
}
