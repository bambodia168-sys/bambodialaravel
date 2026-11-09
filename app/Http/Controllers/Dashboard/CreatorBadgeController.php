<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CreatorBadgeController extends Controller
{
    public function index(Request $request): View
    {
        $badges = CreatorBadge::query()->withCount('grants')->orderBy('sort_order')->get();
        $q = trim((string)$request->query('search',''));
        $creators = User::query()->whereIn('role',['freelancer','super_admin'])
            ->when($q !== '', fn($query) => $query->where(function($sub) use ($q) {
                $sub->where('name','like','%'.$q.'%')->orWhere('email','like','%'.$q.'%');
            }))->orderBy('name')->paginate(12)->withQueryString();
        $grants = CreatorBadgeGrant::query()->whereIn('user_id',$creators->pluck('id'))
            ->where('source','manual')->get()->groupBy('user_id');
        return view('dashboard.creator-badges',compact('badges','creators','grants','q'));
    }

    public function update(Request $request, CreatorBadge $badge): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['super_admin','admin'],true),403);
        $data = $request->validate([
            'name'=>['required','string','max:90'],
            'description'=>['required','string','max:1000'],
            'grant_mode'=>['required',Rule::in(['auto','manual'])],
            'enabled'=>['required','boolean'],
        ]);
        if ($data['grant_mode'] === 'auto'
            && ! in_array($badge->slug,['rising-talent','repeat-clients','on-time-delivery'],true)) {
            return back()->withErrors(['grant_mode'=>'No verified automatic criteria exist for this badge. Keep it Manual.']);
        }
        $badge->update($data);
        AuditEvent::record('creator.badge.definition.updated',$request->user(),$request);
        return back()->with('status','Badge definition updated.');
    }

    public function grant(Request $request, User $creator): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['super_admin','admin','staff'],true),403);
        abort_unless(in_array($creator->role,['freelancer','super_admin'],true),422);
        $data=$request->validate([
            'badge_id'=>['required','integer','exists:creator_badges,id'],
            'evidence_note'=>['required','string','min:8','max:1000'],
        ]);
        $badge=CreatorBadge::query()->findOrFail($data['badge_id']);
        abort_unless($badge->enabled && $badge->grant_mode === 'manual',422,'This badge cannot be manually granted.');
        DB::transaction(function () use ($badge,$creator,$request,$data) {
            CreatorBadgeGrant::query()->updateOrCreate(
                ['creator_badge_id'=>$badge->id,'user_id'=>$creator->id],
                ['source'=>'manual','granted_by'=>$request->user()->id,'evidence_note'=>$data['evidence_note']]
            );
            AuditEvent::record('creator.badge.granted',$request->user(),$request);
        });
        return back()->with('status','Badge granted with an evidence record.');
    }

    public function revoke(Request $request, User $creator, CreatorBadge $badge): RedirectResponse
    {
        abort_unless(in_array($request->user()->role,['super_admin','admin','staff'],true),403);
        if (strcasecmp((string)$creator->email,CreatorBadgeAwards::OWNER_EMAIL) === 0
            && $creator->role === 'super_admin') {
            return back()->withErrors(['badge'=>'Owner badges are automatically granted by platform policy.']);
        }
        CreatorBadgeGrant::query()->where('user_id',$creator->id)
            ->where('creator_badge_id',$badge->id)->where('source','manual')->delete();
        AuditEvent::record('creator.badge.revoked',$request->user(),$request);
        return back()->with('status','Manual badge grant revoked.');
    }
}
