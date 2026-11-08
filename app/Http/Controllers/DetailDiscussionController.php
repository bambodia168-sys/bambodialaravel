<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\DetailComment;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DetailDiscussionController extends Controller
{
    private function subject(string $kind, string $reference, string $slug): array
    {
        abort_unless(preg_match('/^[0-9]{9}$/D', $reference) === 1
            && preg_match('/^[a-z0-9-]+$/D', $slug) === 1, 404);

        if ($kind === 'job') {
            $record = MarketplaceJob::query()->with('client:id,name')
                ->where('status', 'published')->where('public_id', $reference)
                ->where('slug', $slug)->firstOrFail();
            return ['job', $record, $record->title, $record->publicUrl(), (int) $record->client_id];
        }
        abort_unless(in_array($kind, MarketplaceListing::TYPES, true), 404);
        $record = MarketplaceListing::query()
            ->publiclyAvailable()->where('type', $kind)
            ->where('public_id', $reference)->where('slug', $slug)->firstOrFail();
        return ['listing', $record, $record->title, $record->publicUrl(), (int) $record->user_id];
    }

    private function params(string $kind, string $reference, string $slug): array
    {
        return compact('kind', 'reference', 'slug');
    }

    private function commentFor(array $subject, DetailComment $comment): void
    {
        abort_unless($comment->subject_type === $subject[0]
            && (int) $comment->subject_id === (int) $subject[1]->id, 404);
    }

    public function comments(Request $request, string $kind, string $reference, string $slug): View
    {
        $subject = $this->subject($kind, $reference, $slug);
        $comments = DetailComment::query()
            ->where('subject_type', $subject[0])->where('subject_id', $subject[1]->id)
            ->whereNull('parent_id')
            ->with(['user:id,name', 'replies.user:id,name'])
            ->oldest()->paginate(12);
        return view('details.comments', [
            'kind' => $kind, 'title' => $subject[2], 'publicUrl' => $subject[3],
            'params' => $this->params($kind,$reference,$slug),
            'comments' => $comments,
        ]);
    }

    public function storeComment(Request $request, string $kind, string $reference, string $slug): RedirectResponse
    {
        $subject = $this->subject($kind, $reference, $slug);
        $values = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2500'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $body = trim($values['body']);
        if (mb_strlen($body) < 2) {
            throw ValidationException::withMessages(['body'=>'Enter at least two characters.']);
        }
        $params = $this->params($kind,$reference,$slug);
        DB::transaction(function () use ($request, $subject, $values, $body, $params): void {
            $parent = null;
            if (! empty($values['parent_id'])) {
                $parent = DetailComment::query()->where('subject_type',$subject[0])
                    ->where('subject_id',$subject[1]->id)->whereNull('parent_id')
                    ->findOrFail($values['parent_id']);
            }
            DetailComment::query()->create([
                'subject_type'=>$subject[0], 'subject_id'=>$subject[1]->id,
                'user_id'=>$request->user()->id, 'parent_id'=>$parent?->id,
                'body'=>$body,
            ]);
            $recipient = $parent ? (int) $parent->user_id : $subject[4];
            if ($recipient !== (int) $request->user()->id) {
                AccountNotification::deliver($recipient,'detail.comment.received',
                    'New comment on your '.($subject[0] === 'job' ? 'job' : 'listing'),
                    'A member posted a comment or reply on '.$subject[2].'.',
                    route('detail.comments',$params,false));
            }
            AuditEvent::record('detail.comment.created',$request->user(),$request);
        });
        return redirect()->route('detail.comments',$params)->with('status','Comment published.');
    }

    public function updateComment(Request $request, string $kind, string $reference, string $slug, DetailComment $comment): RedirectResponse
    {
        $subject=$this->subject($kind,$reference,$slug);
        $this->commentFor($subject,$comment);
        abort_unless((int)$comment->user_id === (int)$request->user()->id,403);
        $data=$request->validate(['body'=>['required','string','min:2','max:2500']]);
        $body=trim($data['body']);
        if (mb_strlen($body)<2) throw ValidationException::withMessages(['body'=>'Enter at least two characters.']);
        $comment->update(['body'=>$body]);
        AuditEvent::record('detail.comment.updated',$request->user(),$request);
        return redirect()->route('detail.comments',$this->params($kind,$reference,$slug))
            ->with('status','Comment updated.');
    }

    public function destroyComment(Request $request, string $kind, string $reference, string $slug, DetailComment $comment): RedirectResponse
    {
        $subject=$this->subject($kind,$reference,$slug);
        $this->commentFor($subject,$comment);
        abort_unless((int)$comment->user_id === (int)$request->user()->id
            || in_array($request->user()->role,['admin','super_admin'],true),403);
        $comment->delete();
        AuditEvent::record('detail.comment.deleted',$request->user(),$request);
        return redirect()->route('detail.comments',$this->params($kind,$reference,$slug))
            ->with('status','Comment removed.');
    }

    public function support(Request $request, string $kind, string $reference, string $slug): View
    {
        $subject=$this->subject($kind,$reference,$slug);
        $tickets=SupportTicket::query()->where('user_id',$request->user()->id)
            ->where('context_type',$subject[0])->where('context_id',$subject[1]->id)
            ->latest()->paginate(10);
        return view('details.support',[
            'kind'=>$kind,'title'=>$subject[2],'publicUrl'=>$subject[3],
            'params'=>$this->params($kind,$reference,$slug),'tickets'=>$tickets,
        ]);
    }

    public function storeSupport(Request $request, string $kind, string $reference, string $slug): RedirectResponse
    {
        $subject=$this->subject($kind,$reference,$slug);
        $data=$request->validate([
            'subject'=>['required','string','min:5','max:180'],
            'message'=>['required','string','min:10','max:10000'],
        ]);
        $ticket=DB::transaction(function()use($request,$subject,$data): SupportTicket {
            $ticket=SupportTicket::query()->create([
                'user_id'=>$request->user()->id,
                'context_type'=>$subject[0],
                'context_id'=>$subject[1]->id,
                'subject'=>trim($data['subject']),
                'status'=>'open',
            ]);
            $ticket->messages()->create([
                'user_id'=>$request->user()->id,
                'body'=>trim($data['message']),
            ]);
            AuditEvent::record('support.ticket.created',$request->user(),$request);
            return $ticket;
        });
        return redirect()->route('support.show',$ticket->public_id)
            ->with('status','Your private support request was created.');
    }
}
