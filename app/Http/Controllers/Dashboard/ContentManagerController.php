<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContentManagerController extends Controller
{
    public function pages(): View
    {
        return $this->indexOf('page');
    }

    public function posts(): View
    {
        return $this->indexOf('post');
    }

    private function indexOf(string $type): View
    {
        $entries = ContentEntry::query()->where('type', $type)->latest()->paginate(10);
        $prefix = $type === 'page' ? 'dashboard.pages' : 'dashboard.posts';

        return view('dashboard.contents', compact('entries', 'type', 'prefix'));
    }

    public function storePage(Request $request): RedirectResponse
    {
        return $this->store($request, 'page');
    }

    public function storePost(Request $request): RedirectResponse
    {
        return $this->store($request, 'post');
    }

    private function store(Request $request, string $type): RedirectResponse
    {
        $data = $this->validated($request);
        ContentEntry::query()->create([
            'author_id' => $request->user()->id,
            'type' => $type,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published' ? now() : null,
        ]);

        return back()->with('status', ucfirst($type).' created.');
    }

    public function updatePage(Request $request, ContentEntry $entry): RedirectResponse
    {
        return $this->update($request, $entry, 'page');
    }

    public function updatePost(Request $request, ContentEntry $entry): RedirectResponse
    {
        return $this->update($request, $entry, 'post');
    }

    private function update(Request $request, ContentEntry $entry, string $type): RedirectResponse
    {
        abort_unless($entry->type === $type, 404);
        $data = $this->validated($request);
        $data['published_at'] = $data['status'] === 'published'
            ? ($entry->published_at ?? now())
            : null;
        $entry->update($data);

        return back()->with('status', ucfirst($type).' updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'body' => ['required', 'string', 'min:20', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
    }
}
