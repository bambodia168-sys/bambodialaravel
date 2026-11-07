<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\KnowledgeArticle;
use App\Support\PolicyContent;
use App\Support\SupportKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q',''));
        abort_if(mb_strlen($search) > 120, 404);
        $status = (string) $request->query('status', '');
        abort_if($status !== '' && ! in_array($status, ['draft','published','archived'], true), 404);
        $articles = KnowledgeArticle::query()
            ->with('author:id,name')
            ->when($status !== '', fn ($builder) => $builder->where('status', $status))
            ->when($search !== '', fn ($builder) => $builder->where('title', 'like', '%'.$search.'%'))
            ->latest()->paginate(10)->withQueryString();
        $counts = KnowledgeArticle::query()->selectRaw('status,COUNT(*) as total')
            ->groupBy('status')->pluck('total','status');

        return view('dashboard.knowledge-index', compact('articles','counts','status','search'));
    }

    public function create(): View
    {
        return view('dashboard.knowledge-builder', [
            'article' => new KnowledgeArticle(['body_format'=>'html','status'=>'draft','category'=>'getting-started']),
        ]);
    }

    public function edit(KnowledgeArticle $article): View
    {
        return view('dashboard.knowledge-builder', compact('article'));
    }

    public function preview(KnowledgeArticle $article): View
    {
        $html = PolicyContent::display($article->body, $article->body_format);
        return view('dashboard.knowledge-preview', compact('article','html'));
    }

    public function seed(Request $request): RedirectResponse
    {
        $created = DB::transaction(function () use ($request): int {
            $created = 0;
            foreach (SupportKnowledge::starterArticles() as $template) {
                if (KnowledgeArticle::query()->where('title', $template['title'])->exists()) {
                    continue;
                }
                KnowledgeArticle::query()->create([
                    ...$template, 'author_id'=>$request->user()->id, 'status'=>'draft',
                ]);
                $created++;
            }
            return $created;
        });
        AuditEvent::record('knowledge.starter_drafts_created', $request->user(), $request);
        return redirect()->route('dashboard.knowledge.index')
            ->with('status', $created.' knowledge article drafts created. Review and publish each article.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        if ($request->input('status') === 'published') {
            return back()->withErrors(['status'=>'Save a draft first, then review and publish it.'])->withInput();
        }
        $article = KnowledgeArticle::query()->create([
            ...$data, 'status'=>'draft', 'author_id'=>$request->user()->id,
        ]);
        AuditEvent::record('knowledge.article.created', $request->user(), $request);
        return redirect()->route('dashboard.knowledge.edit', $article)
            ->with('status', 'Knowledge article draft created.');
    }

    public function update(Request $request, KnowledgeArticle $article): RedirectResponse
    {
        $data = $this->validated($request, $article);
        $extra = $request->validate([
            'status'=>['required',Rule::in(['draft','published','archived'])],
            'confirm_review'=>['sometimes','accepted'],
        ]);
        if ($extra['status'] === 'published' && ! $request->boolean('confirm_review')) {
            return back()->withErrors(['confirm_review'=>'Confirm editorial review before publishing.'])->withInput();
        }

        DB::transaction(function () use ($request,$article,$data,$extra): void {
            $locked = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
            $status = $extra['status'];
            $locked->fill([...$data, 'status'=>$status]);
            if ($status === 'published') {
                $locked->published_at ??= now();
                $locked->reviewed_by = $request->user()->id;
            } elseif ($status === 'archived' || $status === 'draft') {
                $locked->published_at = null;
            }
            $locked->save();
            AuditEvent::record($status === 'published' ? 'knowledge.article.published' : 'knowledge.article.updated',
                $request->user(), $request);
        });
        return redirect()->route('dashboard.knowledge.edit', $article)
            ->with('status', 'Knowledge article saved. Publication status: '.$extra['status'].'.');
    }

    private function validated(Request $request, ?KnowledgeArticle $article = null): array
    {
        $data = $request->validate([
            'title'=>['required','string','min:6','max:180',
                Rule::unique('knowledge_articles','title')->ignore($article?->id)],
            'excerpt'=>['required','string','min:25','max:320'],
            'category'=>['required',Rule::in(array_keys(SupportKnowledge::CATEGORIES))],
            'body'=>['required','string','min:50','max:100000'],
            'body_format'=>['nullable',Rule::in(['html','markdown'])],
        ]);
        $data['body_format'] ??= $article?->body_format ?? 'html';
        if ($data['body_format'] === 'html') {
            $data['body'] = PolicyContent::sanitize($data['body']);
            if (mb_strlen(PolicyContent::plain($data['body'])) < 50) {
                throw ValidationException::withMessages(['body'=>'Enter at least 50 characters of readable content.']);
            }
        }
        return $data;
    }
}
