<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Support\PolicyContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PostManagerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
        ]);
        $posts = ContentEntry::query()->where('type', 'post')
            ->with('author:id,name')
            ->when(($filters['q'] ?? '') !== '', fn ($query) => $query->where('title', 'like', '%'.$filters['q'].'%'))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('updated_at')->paginate(10)->withQueryString();
        $counts = ContentEntry::query()->where('type', 'post')
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard.posts', compact('posts', 'counts', 'filters'));
    }

    public function create(): View
    {
        return $this->builder(new ContentEntry(['type' => 'post', 'status' => 'draft']));
    }

    public function edit(ContentEntry $entry): View
    {
        $this->ensurePost($entry);
        return $this->builder($entry);
    }

    private function builder(ContentEntry $post): View
    {
        $media = MediaAsset::query()
            ->where(function ($query): void {
                $query->where('review_status', 'approved')
                    ->orWhere(fn ($owned) => $owned->where('review_status', 'pending')
                        ->where('user_id', auth()->id()));
            })
            ->whereIn('mime_type', MediaAsset::ALLOWED_MIME)
            ->latest()->limit(80)->get(['id', 'public_id', 'alt_text', 'original_name', 'mime_type', 'review_status']);
        return view('dashboard.post-builder', compact('post', 'media'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $post = ContentEntry::query()->create([
            ...$data,
            'type' => 'post',
            'author_id' => $request->user()->id,
            'published_at' => $data['status'] === 'published' ? now() : null,
        ]);
        AuditEvent::record('post.created', $request->user(), $request);

        return redirect()->route('dashboard.posts.edit', $post)->with('status', 'Post created successfully.');
    }

    public function update(Request $request, ContentEntry $entry): RedirectResponse
    {
        $this->ensurePost($entry);
        $data = $this->validated($request);
        $data['published_at'] = $data['status'] === 'published'
            ? ($entry->status === 'published' ? ($entry->published_at ?? now()) : now())
            : null;
        $entry->update($data);
        AuditEvent::record('post.updated', $request->user(), $request);

        return redirect()->route('dashboard.posts.edit', $entry)->with('status', 'Post changes saved.');
    }

    public function preview(ContentEntry $entry): View
    {
        $this->ensurePost($entry);
        $entry->load('author:id,name', 'featuredMedia:id,public_id,review_status,alt_text,mime_type');
        return view('content.show', ['entry' => $entry, 'preview' => true]);
    }

    public function destroy(Request $request, ContentEntry $entry): RedirectResponse
    {
        $this->ensurePost($entry);
        $data = $request->validate([
            'confirm_title' => ['required', 'string', 'max:180'],
        ]);
        if (! hash_equals($entry->title, $data['confirm_title'])) {
            throw ValidationException::withMessages(['confirm_title' => 'Type the exact post title to confirm deletion.']);
        }
        $entry->delete();
        AuditEvent::record('post.deleted', $request->user(), $request);

        return redirect()->route('dashboard.posts')->with('status', 'Post deleted.');
    }

    private function ensurePost(ContentEntry $entry): void
    {
        abort_unless($entry->type === 'post', 404);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'excerpt' => ['nullable', 'string', 'max:320'],
            'body' => ['required', 'string', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'category' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'string', 'max:320'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'featured_type' => ['nullable', Rule::in(['none', 'image', 'video', 'youtube', 'color'])],
            'featured_media_id' => ['nullable', 'integer'],
            'featured_url' => ['nullable', 'string', 'max:255'],
            'featured_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
        ]);
        $html = PolicyContent::sanitize($data['body']);
        $plain = PolicyContent::plain($html);
        if (mb_strlen($plain) < 20) {
            throw ValidationException::withMessages(['body' => 'Write at least 20 characters of readable post content.']);
        }
        $data['body'] = $html;
        $data['body_format'] = 'html';
        $data['title'] = trim($data['title']);
        foreach (['excerpt', 'category', 'seo_title', 'seo_description'] as $key) {
            $data[$key] = trim($data[$key] ?? '') ?: null;
        }
        $tags = array_values(array_unique(array_filter(array_map(
            'trim', explode(',', $data['tags'] ?? '')
        ), fn ($tag) => $tag !== '')));
        if (count($tags) > 10 || collect($tags)->contains(fn ($tag) => mb_strlen($tag) > 35)) {
            throw ValidationException::withMessages(['tags' => 'Use no more than 10 tags, each up to 35 characters.']);
        }
        $data['tags'] = $tags ? implode(', ', $tags) : null;
        $media = empty($data['featured_media_id']) ? null : MediaAsset::query()->find($data['featured_media_id']);
        $type = $data['featured_type'] ?? ($media
            ? (str_starts_with($media->mime_type, 'video/') ? 'video' : 'image') : 'none');
        $data['featured_type'] = $type;
        $data['featured_media_id'] = null;
        $data['featured_url'] = null;
        $data['featured_color'] = null;

        if (in_array($type, ['image', 'video'], true)) {
            $validFormat = $media && ($type === 'image'
                ? str_starts_with($media->mime_type, 'image/')
                : str_starts_with($media->mime_type, 'video/'));
            $authorized = $media && ($media->review_status === 'approved'
                || ($data['status'] !== 'published' && $media->review_status === 'pending'
                    && (int) $media->user_id === (int) $request->user()->id));
            if (! $validFormat || ! $authorized) {
                throw ValidationException::withMessages(['featured_media_id' =>
                    'Choose approved media, or your pending upload for a private draft.']);
            }
            $data['featured_media_id'] = $media->id;
        } elseif ($type === 'youtube') {
            $url = $request->input('featured_url');
            $parts = is_string($url) ? parse_url(trim($url)) : false;
            $host = strtolower((string) ($parts['host'] ?? ''));
            $videoId = null;
            if (($parts['scheme'] ?? '') === 'https'
                && in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com',
                    'youtu.be', 'www.youtu.be'], true)) {
                if (str_ends_with($host, 'youtu.be')) {
                    $videoId = trim($parts['path'] ?? '', '/');
                } elseif (($parts['path'] ?? '') === '/watch') {
                    parse_str($parts['query'] ?? '', $query);
                    $videoId = $query['v'] ?? null;
                } elseif (preg_match('~^/(?:shorts|embed)/([a-zA-Z0-9_-]{11})/?$~D',
                    $parts['path'] ?? '', $matches)) {
                    $videoId = $matches[1];
                }
            }
            if (! is_string($videoId) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId)) {
                throw ValidationException::withMessages(['featured_url' => 'Enter a valid HTTPS YouTube video link.']);
            }
            $data['featured_url'] = 'https://www.youtube.com/watch?v='.$videoId;
        } elseif ($type === 'color') {
            if (! isset($request->featured_color)
                || ! preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $request->featured_color)) {
                throw ValidationException::withMessages(['featured_color' => 'Choose a valid color.']);
            }
            $data['featured_color'] = strtolower((string) $request->featured_color);
        }

        return $data;
    }
}
