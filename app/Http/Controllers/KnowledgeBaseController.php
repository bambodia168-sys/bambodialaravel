<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use App\Support\PolicyContent;
use App\Support\SupportKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($search) > 120, 404);
        $category = (string) $request->query('category', '');
        abort_if($category !== '' && ! array_key_exists($category, SupportKnowledge::CATEGORIES), 404);

        $articles = KnowledgeArticle::query()
            ->where('status', 'published')
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($search !== '', fn ($query) => $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('excerpt', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('published_at')->orderBy('title')
            ->paginate(12)->withQueryString();

        $categoryCounts = KnowledgeArticle::query()->where('status', 'published')
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')->pluck('total','category');

        return view('support.knowledge-index', compact('articles', 'search', 'category', 'categoryCounts'));
    }

    public function show(string $reference, string $slug): View|RedirectResponse
    {
        $article = KnowledgeArticle::query()
            ->where('public_code', $reference)
            ->where('status', 'published')->firstOrFail();

        if ($article->slug !== $slug) {
            return redirect($article->publicUrl(), 301);
        }

        // Keep same-category guides first, then show published guides from other
        // categories so the sidebar is useful even when a topic has one article.
        $related = KnowledgeArticle::query()->where('status', 'published')
            ->where('category', $article->category)
            ->where('id', '!=', $article->id)
            ->orderByDesc('published_at')->orderBy('title')->limit(5)
            ->get(['id','title','slug','public_code']);

        $otherGuides = KnowledgeArticle::query()->where('status', 'published')
            ->where('category', '!=', $article->category)
            ->where('id', '!=', $article->id)
            ->orderByDesc('published_at')->orderBy('title')->limit(max(0, 8 - $related->count()))
            ->get(['id','title','slug','public_code']);

        $html = PolicyContent::display($article->body, $article->body_format);
        return view('support.knowledge-show', compact('article', 'related', 'otherGuides', 'html'));
    }
}
