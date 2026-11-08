<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContentController extends Controller
{
    public function show(string $content): View
    {
        abort_unless(preg_match('/^(\d{9})_([a-z0-9-]+)$/', $content, $match) === 1, 404);

        $entry = ContentEntry::query()
            ->with('author:id,name', 'featuredMedia:id,public_id,alt_text,mime_type')
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->where('status', 'published')
            ->firstOrFail();

        $relatedPosts = collect();
        $blogCategories = collect();
        if ($entry->type === 'post') {
            $relatedPosts = ContentEntry::query()
                ->where('type', 'post')->where('status', 'published')
                ->where('id', '!=', $entry->id)
                ->latest('published_at')->orderByDesc('id')
                ->limit(5)->get(['id', 'title', 'public_id', 'slug', 'category', 'published_at']);

            $blogCategories = ContentEntry::query()
                ->where('type', 'post')->where('status', 'published')
                ->whereNotNull('category')->where('category', '!=', '')
                ->select('category')->selectRaw('COUNT(*) AS total')
                ->groupBy('category')->orderBy('category')->get();
        }

        return view('content.show', compact('entry', 'relatedPosts', 'blogCategories'));
    }

    public function blog(Request $request): View
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['grid', 'list'])],
            'columns' => ['nullable', 'integer', Rule::in([2, 3, 4, 5])],
            'category' => ['nullable', 'string', 'max:80'],
        ]);
        $view = $filters['view'] ?? 'grid';
        $columns = (int) ($filters['columns'] ?? 3);
        $category = trim((string) ($filters['category'] ?? ''));
        $perPage = $view === 'grid' ? $columns * 3 : 3;

        $posts = ContentEntry::query()
            ->with('author:id,name', 'featuredMedia:id,public_id,alt_text,mime_type')
            ->where('type', 'post')->where('status', 'published')
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->latest('published_at')->orderByDesc('id')
            ->paginate($perPage)->withQueryString();

        return view('content.blog', compact('posts', 'view', 'columns', 'category'));
    }
}
