<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServiceInquiryController extends Controller
{
    public function index(Request $request): View
    {
        $creator = $request->routeIs('studio.inquiries');
        abort_unless($creator
            ? $request->user()->isCreator()
            : in_array($request->user()->role, ['customer','subscriber'],true),403);
        $status=(string)$request->query('status','all');
        abort_unless(in_array($status,['all','open','closed'],true),404);
        $search=trim((string)$request->query('q',''));
        abort_unless(mb_strlen($search)<=120,404);
        $owned=ServiceInquiry::query()->where($creator?'creator_id':'client_id',$request->user()->id);
        $counts=(clone $owned)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total','status');
        $inquiries=(clone $owned)
            ->with(['listing:id,public_id,title,type,slug,status','client:id,name,public_id','creator:id,name,public_id'])
            ->when($status!=='all',fn($q)=>$q->where('status',$status))
            ->when($search!=='',fn($q)=>$q->where(function($inner)use($search){
                $inner->where('subject','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->latest('updated_at')->paginate(10)->withQueryString();
        return view('inquiries.index',compact('inquiries','creator','status','search','counts'));
    }

    public function store(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['customer','subscriber'],true),403);
        $values=$request->validate([
            'subject'=>['required','string','min:6','max:180'],
            'body'=>['required','string','min:40','max:5000'],
            'proposed_budget'=>['nullable','numeric','min:0','max:9999999999.99'],
        ]);

        $id = DB::transaction(function () use ($request,$listing,$values): int {
            $listing=MarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            abort_unless($listing->status==='published'
                && in_array($listing->type,['service','freelance','project'],true),409,
                'This listing is not accepting inquiries.');
            abort_unless((int)$listing->user_id !== (int)$request->user()->id,403);

            $inquiry=ServiceInquiry::query()->create([
                'marketplace_listing_id'=>$listing->id,
                'client_id'=>$request->user()->id,
                'creator_id'=>$listing->user_id,
                'subject'=>$values['subject'],
                'proposed_budget'=>$values['proposed_budget']??null,
                'currency'=>'USD',
                'status'=>'open',
            ]);
            ServiceInquiryMessage::query()->create([
                'service_inquiry_id'=>$inquiry->id,
                'sender_id'=>$request->user()->id,
                'body'=>$values['body'],
            ]);
            AccountNotification::deliver(
                (int)$inquiry->creator_id,'inquiry.received','New client inquiry',
                'You received a private inquiry about a published listing.',
                '/inquiries/'.$inquiry->id
            );
            AuditEvent::record('inquiry.submitted',$request->user(),$request);
            return $inquiry->id;
        });

        return redirect()->route('inquiries.show',$id)->with('status','Your private inquiry was sent to the creator.');
    }

    public function show(Request $request, ServiceInquiry $inquiry): View
    {
        $this->authorizeParticipant($request,$inquiry);
        $inquiry->load(['listing:id,public_id,title,type,slug,status','client:id,name,public_id','creator:id,name,public_id']);
        $messages=$inquiry->messages()->with('sender:id,name')->oldest('id')->paginate(10);
        return view('inquiries.show',compact('inquiry','messages'));
    }

    public function reply(Request $request, ServiceInquiry $inquiry): RedirectResponse
    {
        $this->authorizeParticipant($request,$inquiry);
        $data=$request->validate(['body'=>['required','string','min:2','max:5000']]);
        DB::transaction(function () use ($request,$inquiry,$data): void {
            $locked=ServiceInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
            $this->authorizeParticipant($request,$locked);
            abort_unless($locked->status==='open',409,'This inquiry is closed.');
            ServiceInquiryMessage::query()->create([
                'service_inquiry_id'=>$locked->id,'sender_id'=>$request->user()->id,'body'=>$data['body'],
            ]);
            $locked->touch();
            $recipient=(int)$locked->client_id===(int)$request->user()->id
                ? (int)$locked->creator_id : (int)$locked->client_id;
            AccountNotification::deliver(
                $recipient,'inquiry.replied','New inquiry message',
                'You have a new private message from the other participant.',
                '/inquiries/'.$locked->id
            );
            AuditEvent::record('inquiry.replied',$request->user(),$request);
        });
        return back()->with('status','Message sent.');
    }

    public function close(Request $request, ServiceInquiry $inquiry): RedirectResponse
    {
        $this->authorizeParticipant($request,$inquiry);
        DB::transaction(function()use($request,$inquiry){
            $locked=ServiceInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
            $this->authorizeParticipant($request,$locked);
            abort_unless($locked->status==='open',409);
            $locked->update(['status'=>'closed','closed_by'=>$request->user()->id,'closed_at'=>now()]);
            $recipient=(int)$locked->client_id===(int)$request->user()->id
                ? (int)$locked->creator_id : (int)$locked->client_id;
            AccountNotification::deliver(
                $recipient,'inquiry.closed','Inquiry closed',
                'The other participant closed this conversation.',
                '/inquiries/'.$locked->id
            );
            AuditEvent::record('inquiry.closed',$request->user(),$request);
        });
        return back()->with('status','Inquiry closed.');
    }

    private function authorizeParticipant(Request $request,ServiceInquiry $inquiry): void
    {
        abort_unless((int)$request->user()->id===(int)$inquiry->client_id
            || (int)$request->user()->id===(int)$inquiry->creator_id,404);
    }
}
