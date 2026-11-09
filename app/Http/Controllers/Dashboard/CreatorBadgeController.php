<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use App\Support\CreatorBadgeDisplay;
use App\Support\UserManagementAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CreatorBadgeController extends Controller
{
    public const AUTO_SLUGS = ['rising-talent','repeat-clients','on-time-delivery'];
    public const ICONS = ['award','star','shield','shield-check','badge-check','trending-up',
        'zap','target','crown','clock-check','users','handshake','medal',
        'graduation-cap','gem','heart-handshake','heart','check-circle'];
    public const TONES = ['blue','gold','purple','amber','green','pink'];
    private const BUILTIN_SLUGS = ['top-rated','rising-talent','expert-vetted','fast-responder',
        'identity-verified','job-success-100','repeat-clients','top-rated-plus',
        'on-time-delivery','pro-verified','super-seller-level-two','top-collaborator',
        'skill-certified','high-earner','community-leader','vip-client-favorite'];

    public function index(Request $request): View
    {
        $badges=CreatorBadge::query()->withCount('grants')->orderBy('sort_order')->orderBy('id')->get();
        $search=mb_substr(trim((string)$request->query('search','')),0,120);
        $role=(string)$request->query('role','');
        $status=(string)$request->query('status','');
        abort_unless($role==='' || in_array($role,User::ROLES,true),404);
        abort_unless($status==='' || in_array($status,['active','suspended','archived'],true),404);
        $creators=User::query()
            ->when($search!=='',fn($query)=>$query->where(function($sub)use($search){
                $sub->where('name','like','%'.$search.'%')
                    ->orWhere('email','like','%'.$search.'%')
                    ->orWhere('username','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->when($role!=='',fn($query)=>$query->where('role',$role))
            ->when($status!=='',fn($query)=>$query->where('account_status',$status))
            ->orderByDesc('id')->paginate(10)->withQueryString();

        $grants=CreatorBadgeGrant::query()->with('badge')
            ->whereIn('user_id',$creators->getCollection()->pluck('id'))
            ->where('source','manual')->get()->groupBy('user_id');
        $awards=[];
        foreach($creators as $creator){
            $awards[$creator->id]=CreatorBadgeAwards::earned($creator,$badges);
        }
        $recentActions=DB::table('creator_badge_actions as actions')
            ->leftJoin('users as administrators','administrators.id','=','actions.actor_id')
            ->whereIn('actions.user_id',$creators->getCollection()->pluck('id'))
            ->orderByDesc('actions.id')->limit(100)
            ->get(['actions.user_id','actions.action','actions.reason',
                'actions.created_at','administrators.name as reviewer_name'])
            ->groupBy('user_id');
        $canGrant=UserManagementAccess::allows($request->user(),'badge_awards');
        $canEdit=in_array($request->user()->role,['super_admin','admin'],true);
        return view('dashboard.creator-badges',compact('badges','creators','grants','awards',
            'search','role','status','canGrant','canEdit','recentActions'));
    }

    public function settings(Request $request): View
    {
        abort_unless(in_array($request->user()->role,['super_admin','admin'],true),403);
        $badges=CreatorBadge::query()->withCount('grants')->orderBy('sort_order')->orderBy('id')->get();
        $display=CreatorBadgeDisplay::settings();
        return view('dashboard.creator-badge-settings',compact('badges','display'));
    }

    public function displaySettings(Request $request): RedirectResponse
    {
        $this->mustEdit($request);
        $data=$request->validate([
            'display_mode'=>['required',Rule::in(array_keys(CreatorBadgeDisplay::MODES))],
            'badge_size'=>['required',Rule::in(array_keys(CreatorBadgeDisplay::SIZES))],
            'tooltips_enabled'=>['required','boolean'],
        ]);
        DB::table('creator_badge_settings')->where('id',1)->update([...$data,'updated_at'=>now()]);
        AuditEvent::record('creator.badge.display.updated',$request->user(),$request);
        return back()->with('status','Badge display settings saved for light and dark mode.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->mustEdit($request);
        $data=$request->validate([
            'slug'=>['required','string','max:70','regex:/^[a-z][a-z0-9-]*$/D',Rule::unique('creator_badges','slug')],
            'name'=>['required','string','max:90'],
            'description'=>['required','string','max:1000'],
            'eligibility_criteria'=>['sometimes','nullable','string','max:1000'],
            'icon'=>['required',Rule::in(self::ICONS)],
            'tone'=>['required',Rule::in(self::TONES)],
            'sort_order'=>['required','integer','between:1,999'],
        ]);
        CreatorBadge::query()->create([...$data,'grant_mode'=>'manual','enabled'=>true]);
        AuditEvent::record('creator.badge.definition.created',$request->user(),$request);
        return back()->with('status','Custom badge created. It can now be granted manually.');
    }

    public function update(Request $request, CreatorBadge $badge): RedirectResponse
    {
        $this->mustEdit($request);
        $data=$request->validate([
            'name'=>['required','string','max:90'],
            'description'=>['required','string','max:1000'],
            'eligibility_criteria'=>['sometimes','nullable','string','max:1000'],
            'grant_mode'=>['required',Rule::in(['auto','manual'])],
            'enabled'=>['required','boolean'],
            'icon'=>['sometimes','required',Rule::in(self::ICONS)],
            'tone'=>['sometimes','required',Rule::in(self::TONES)],
            'sort_order'=>['sometimes','required','integer','between:1,999'],
            'auto_rules'=>['sometimes','array'],
            'auto_rules.min_completed'=>['sometimes','integer','between:1,1000'],
            'auto_rules.max_completed'=>['sometimes','integer','between:1,1000'],
            'auto_rules.max_account_age_days'=>['sometimes','integer','between:30,1095'],
            'auto_rules.min_repeat_contracts'=>['sometimes','integer','between:2,1000'],
            'auto_rules.min_milestones'=>['sometimes','integer','between:1,1000'],
        ]);
        if (array_key_exists('auto_rules',$data)) {
            $allowed = match($badge->slug) {
                'rising-talent'=>['min_completed','max_completed','max_account_age_days'],
                'repeat-clients'=>['min_repeat_contracts'],
                'on-time-delivery'=>['min_completed','min_milestones'],
                default=>[],
            };
            if (!$allowed || array_diff(array_keys($data['auto_rules']),$allowed)) {
                return back()->withErrors(['auto_rules'=>'No supported automatic metric rule exists for this badge.']);
            }
            $defaults = match($badge->slug) {
                'rising-talent'=>['min_completed'=>1,'max_completed'=>5,'max_account_age_days'=>365],
                'repeat-clients'=>['min_repeat_contracts'=>2],
                'on-time-delivery'=>['min_completed'=>3,'min_milestones'=>3],
                default=>[],
            };
            $data['auto_rules'] = array_replace($defaults,$badge->auto_rules ?? [],$data['auto_rules']);
            if($badge->slug==='rising-talent'
                && $data['auto_rules']['min_completed']>$data['auto_rules']['max_completed']) {
                return back()->withErrors(['auto_rules'=>'Minimum completed contracts cannot exceed the maximum.']);
            }
        }
        if($data['grant_mode']==='auto' && !in_array($badge->slug,self::AUTO_SLUGS,true)){
            return back()->withErrors(['grant_mode'=>'No verified automatic criteria exist for this badge. Keep it Manual.']);
        }
        $badge->update($data);
        AuditEvent::record('creator.badge.definition.updated',$request->user(),$request);
        return back()->with('status','Badge definition updated.');
    }

    public function destroy(Request $request, CreatorBadge $badge): RedirectResponse
    {
        $this->mustEdit($request);
        $request->validate(['confirmation'=>['required','in:DELETE']]);
        if(in_array($badge->slug,self::BUILTIN_SLUGS,true)){
            return back()->withErrors(['badge'=>'Built-in medals cannot be deleted. Disable them instead.']);
        }
        if($badge->grants()->exists()){
            return back()->withErrors(['badge'=>'This badge has existing awards. Disable it or revoke every grant before deletion.']);
        }
        $badge->delete();
        AuditEvent::record('creator.badge.definition.deleted',$request->user(),$request);
        return back()->with('status','Unused custom badge deleted.');
    }

    public function grant(Request $request, User $creator): RedirectResponse
    {
        $this->mustGrant($request);
        abort_unless($creator->account_status==='active',422,'Only active accounts can receive badges.');
        $data=$request->validate([
            'badge_id'=>['required','integer','exists:creator_badges,id'],
            'evidence_note'=>['required','string','min:8','max:1000'],
        ]);
        $badge=CreatorBadge::query()->findOrFail($data['badge_id']);
        abort_unless($badge->enabled,422,'Disabled badges cannot be granted.');
        // An explicit manual grant can override an automatic source; it must carry evidence.
        DB::transaction(function()use($creator,$badge,$request,$data){
            CreatorBadgeGrant::query()->updateOrCreate(
                ['creator_badge_id'=>$badge->id,'user_id'=>$creator->id],
                ['source'=>'manual','granted_by'=>$request->user()->id,'evidence_note'=>$data['evidence_note']]
            );
            $this->activity($request,'grant','single',$creator,$badge,1,$data['evidence_note']);
            AuditEvent::record('creator.badge.granted',$request->user(),$request);
        });
        return back()->with('status','Badge granted or evidence updated.');
    }

    public function grantAll(Request $request, User $creator): RedirectResponse
    {
        $this->mustGrant($request);
        abort_unless($creator->account_status==='active',422,'Only active users can receive awards.');
        $data=$this->bulkRules($request,'GRANT ALL');
        $badges=CreatorBadge::query()->where('enabled',true)->get();
        abort_if($badges->isEmpty(),422,'No enabled badges to grant.');
        DB::transaction(function()use($creator,$request,$data,$badges){
            $this->insertGrants([$creator->id],$badges,$request->user()->id,$data['evidence_note']);
            $this->activity($request,'grant_all','single',$creator,null,$badges->count(),$data['evidence_note']);
            AuditEvent::record('creator.badge.bulk.granted',$request->user(),$request);
        });
        return back()->with('status','All enabled badges granted to '.$creator->name.'.');
    }

    public function grantEveryUser(Request $request): RedirectResponse
    {
        $this->mustGrant($request);
        abort_unless($request->user()->role==='super_admin',403);
        $data=$this->bulkRules($request,'GRANT EVERY USER');
        $badges=CreatorBadge::query()->where('enabled',true)->get();
        abort_if($badges->isEmpty(),422,'No enabled badges.');
        $total=User::query()->where('account_status','active')->count();
        abort_if($total>10000,422,'For safety, use scoped batches for more than 10,000 users.');
        $actor=$request->user()->id;
        DB::transaction(function()use($request,$data,$badges,$actor,$total){
            User::query()->where('account_status','active')->orderBy('id')
                ->select('id')->chunkById(100,function($users)use($badges,$actor,$data){
                    $this->insertGrants($users->pluck('id')->all(),$badges,$actor,$data['evidence_note']);
                });
            $this->activity($request,'grant_all','all_active',null,null,$total,$data['evidence_note']);
            AuditEvent::record('creator.badge.bulk.granted',$request->user(),$request);
        },3);
        return back()->with('status',"All enabled badges granted to {$total} active users. Historical evidence was recorded.");
    }

    public function revoke(Request $request, User $creator, CreatorBadge $badge): RedirectResponse
    {
        $this->mustGrant($request);
        if(CreatorBadgeAwards::isDesignatedOwner($creator)){
            return back()->withErrors(['badge'=>'Owner badges are automatically granted by platform policy.']);
        }
        $data=$request->validate(['reason'=>['nullable','string','max:1000']]);
        DB::transaction(function()use($request,$creator,$badge,$data){
            $deleted=CreatorBadgeGrant::query()->where('user_id',$creator->id)
                ->where('creator_badge_id',$badge->id)->where('source','manual')->delete();
            $this->activity($request,'revoke','single',$creator,$badge,$deleted,$data['reason']??'Manual grant revoked.');
            AuditEvent::record('creator.badge.revoked',$request->user(),$request);
        });
        return back()->with('status','Manual grant revoked. Automatically earned awards remain based on verified metrics.');
    }

    public function revokeAll(Request $request, User $creator): RedirectResponse
    {
        $this->mustGrant($request);
        abort_if(CreatorBadgeAwards::isDesignatedOwner($creator),422,
            'The designated owner retains the enabled badge catalog.');
        $data=$request->validate([
            'confirmation'=>['required','in:REVOKE ALL'],
            'reason'=>['required','string','min:12','max:1000'],
        ]);
        DB::transaction(function()use($request,$creator,$data){
            $count=CreatorBadgeGrant::query()->where('user_id',$creator->id)->where('source','manual')->delete();
            $this->activity($request,'revoke_all','single',$creator,null,$count,$data['reason']);
            AuditEvent::record('creator.badge.bulk.revoked',$request->user(),$request);
        });
        return back()->with('status','All manual grants revoked for '.$creator->name.'. Automatic awards remain metric-based.');
    }

    private function bulkRules(Request $request,string $confirmation): array
    {
        return $request->validate([
            'confirmation'=>['required',Rule::in([$confirmation])],
            'evidence_note'=>['required','string','min:20','max:1000'],
            'attest_evidence'=>['accepted'],
        ]);
    }

    private function insertGrants(array $userIds,$badges,int $actor,string $reason): void
    {
        if(!$userIds)return;
        $rows=[];$now=now();
        foreach($userIds as $id)foreach($badges as $badge){
            $rows[]=[
                'user_id'=>$id,'creator_badge_id'=>$badge->id,
                'granted_by'=>$actor,'source'=>'manual','evidence_note'=>$reason,
                'created_at'=>$now,'updated_at'=>$now,
            ];
        }
        // Existing manually reviewed grants are left intact; bulk action never overwrites evidence.
        foreach(array_chunk($rows,500) as $chunk) DB::table('creator_badge_grants')->insertOrIgnore($chunk);
    }

    private function activity(Request $request,string $action,string $scope,?User $creator,
        ?CreatorBadge $badge,int $affected,string $reason): void
    {
        DB::table('creator_badge_actions')->insert([
            'actor_id'=>$request->user()->id,'user_id'=>$creator?->id,
            'creator_badge_id'=>$badge?->id,'action'=>$action,'scope'=>$scope,
            'affected_count'=>$affected,'reason'=>$reason,'created_at'=>now(),
        ]);
    }

    private function mustGrant(Request $request): void
    {
        abort_unless(UserManagementAccess::allows($request->user(),'badge_awards'),403);
    }
    private function mustEdit(Request $request): void
    {
        abort_unless(in_array($request->user()->role,['super_admin','admin'],true),403);
    }
}
