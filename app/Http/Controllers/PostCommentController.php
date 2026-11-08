<?php

namespace App\Http\Controllers;

use App\Models\ContentEntry;
use App\Models\PostComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PostCommentController extends Controller
{
    public function store(Request $request, string $content): RedirectResponse
    {
        abort_unless(preg_match('/^(\d{9})_([a-z0-9-]+)$/', $content, $match) === 1, 404);

        $post = ContentEntry::query()
            ->where('type', 'post')->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('public_id', $match[1])->where('slug', $match[2])
            ->firstOrFail();

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1500']]);
        $body = trim($data['body']);
        if (mb_strlen($body) < 2) {
            throw ValidationException::withMessages(['body' => 'Write at least two characters.']);
        }

        $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $body,
        ]);

        return redirect()->to($post->publicUrl().'#comments')
            ->with('status', 'Your comment was posted.');
    }

    public function destroy(Request $request, PostComment $comment): RedirectResponse
    {
        $canManage = (int) $comment->user_id === (int) $request->user()->id
            || in_array($request->user()->role, ['admin', 'super_admin'], true);
        abort_unless($canManage, 403);

        $post = $comment->post;
        abort_unless($post && $post->type === 'post', 404);
        $comment->delete();

        return redirect()->to($post->isPublic() ? $post->publicUrl().'#comments' : route('blog'))
            ->with('status', 'Comment deleted.');
    }
}
