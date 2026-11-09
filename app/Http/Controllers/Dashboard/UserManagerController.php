<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CreatorBadgeAwards;
use App\Support\UserManagementAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagerController extends Controller
{
    private const LOCALES = ['en','zh','km','ko','ja','th','vi','de'];

    public function index(Request $request): View
    {
        $search=mb_substr(trim((string)$request->query('search','')),0,120);
        $role=(string)$request->query('role','');
        $status=(string)$request->query('status','');
        $sort=(string)$request->query('sort','newest');
        abort_if($role!=='' && !in_array($role,User::ROLES,true),404);
        abort_if($status!=='' && !in_array($status,['active','suspended','archived'],true),404);
        abort_unless(in_array($sort,['newest','oldest','name'],true),404);
        $users=User::query()
            ->when($search!=='',fn($q)=>$q->where(function($w)use($search){
                $w->where('name','like','%'.$search.'%')
                    ->orWhere('username','like','%'.$search.'%')
                    ->orWhere('email','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->when($role!=='',fn($q)=>$q->where('role',$role))
            ->when($status!=='',fn($q)=>$q->where('account_status',$status))
            ->when($sort==='newest',fn($q)=>$q->orderByDesc('id'))
            ->when($sort==='oldest',fn($q)=>$q->orderBy('id'))
            ->when($sort==='name',fn($q)=>$q->orderBy('name')->orderBy('id'))
            ->paginate(10)->withQueryString();

        return view('dashboard.users',compact('users','search','role','status','sort'));
    }

    public function create(): View
    {
        return view('dashboard.user-form',[
            'member'=>new User(['role'=>'customer','account_status'=>'active','locale'=>'en']),
            'profile'=>new UserProfile(),
            'isCreate'=>true,
            'permissions'=>[],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$this->validated($request,null);
        $user=DB::transaction(function()use($data,$request){
            $user=User::query()->create([
                'name'=>$data['name'],'email'=>$data['email'],
                'username'=>$data['username']??null,'password'=>$data['password'],
                'role'=>$data['role'],'locale'=>$data['locale'],
                'account_status'=>'active',
            ]);
            $this->saveProfile($user,$data);
            $this->savePermissions($user,$data['permissions']??[]);
            AuditEvent::record('user.created',$request->user(),$request);
            $this->recordAction($request,$user,'created',['name','username','email','role','locale','profile','permissions']);
            return $user;
        });
        return redirect()->route('dashboard.users.show',$user)->with('status','User created. Email is not automatically verified.');
    }

    public function show(User $user): View
    {
        $user->load('userProfile');
        $counts=$this->counts($user);
        $listings=MarketplaceListing::query()->where('user_id',$user->id)
            ->orderByDesc('id')->limit(50)->get(['id','public_id','title','type','status','updated_at']);
        $jobs=MarketplaceJob::query()->where('client_id',$user->id)
            ->orderByDesc('id')->limit(50)->get(['id','public_id','title','status','updated_at']);
        $events=DB::table('user_management_events')->where('user_id',$user->id)->orderByDesc('id')->limit(20)->get();
        $transfers=DB::table('user_ownership_transfers')
            ->where(function($q)use($user){
                $q->where('from_user_id',$user->id)->orWhere('to_user_id',$user->id);
            })->orderByDesc('id')->limit(15)->get();
        return view('dashboard.user-show',[
            'member'=>$user,'counts'=>$counts,'listings'=>$listings,'jobs'=>$jobs,'transfers'=>$transfers,'events'=>$events,
            'permissions'=>UserManagementAccess::allowedFor($user),
        ]);
    }

    public function edit(User $user): View
    {
        return view('dashboard.user-form',[
            'member'=>$user,'profile'=>$user->userProfile ?? new UserProfile(),
            'isCreate'=>false,'permissions'=>UserManagementAccess::allowedFor($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data=$this->validated($request,$user);
        DB::transaction(function()use($user,$data,$request){
            $locked=User::query()->lockForUpdate()->findOrFail($user->id);
            $this->guardProtected($locked,$data,$request->user());
            $changedEmail=strcasecmp($locked->email,$data['email'])!==0;
            $changedRole=$locked->role!==$data['role'];
            $changedStatus=$locked->account_status!==$data['account_status'];
            $locked->fill([
                'name'=>$data['name'],'email'=>$data['email'],
                'username'=>$data['username']??null,
                'role'=>$data['role'],'locale'=>$data['locale'],
                'account_status'=>$data['account_status'],
            ]);
            if ($changedEmail) $locked->email_verified_at=null;
            if (!empty($data['password'])) $locked->password=$data['password'];
            if ($changedEmail || $changedStatus || !empty($data['password'])) {
                $locked->remember_token=Str::random(60);
                DB::table('sessions')->where('user_id',$locked->id)->delete();
            }
            $locked->save();
            $this->saveProfile($locked,$data);
            $this->savePermissions($locked,$data['permissions']??[]);
            AuditEvent::record('user.updated',$request->user(),$request);
            $this->recordAction($request,$locked,'updated',array_keys($data));
            if ($changedRole) AuditEvent::record('user.role.changed',$request->user(),$request);
            if ($changedStatus) AuditEvent::record('user.status.changed',$request->user(),$request);
            if (!empty($data['password'])) AuditEvent::record('user.password.reset',$request->user(),$request);
        });
        return redirect()->route('dashboard.users.show',$user)->with('status','User details and access settings saved.');
    }

    public function archive(Request $request, User $user): RedirectResponse
    {
        $data=$request->validate(['confirmation'=>['required','in:ARCHIVE'],'reason'=>['required','string','min:12','max:500']]);
        DB::transaction(function()use($user,$request,$data){
            $locked=User::query()->lockForUpdate()->findOrFail($user->id);
            $this->guardProtected($locked,['role'=>$locked->role,'email'=>$locked->email,'account_status'=>'archived'],$request->user());
            $locked->account_status='archived';
            $locked->remember_token=Str::random(60);
            $locked->save();
            DB::table('sessions')->where('user_id',$locked->id)->delete();
            AuditEvent::record('user.archived',$request->user(),$request);
            $this->recordAction($request,$locked,'archived',['account_status'],$data['reason']);
        });
        return redirect()->route('dashboard.users.show',$user)
            ->with('status','Account archived (reversible). Records, licenses, contracts and audit history are retained.');
    }

    public function restore(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->account_status!=='active',409);
        DB::transaction(function()use($user,$request){
            $locked=User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->update(['account_status'=>'active']);
            AuditEvent::record('user.restored',$request->user(),$request);
            $this->recordAction($request,$locked,'restored',['account_status']);
        });
        return redirect()->route('dashboard.users.show',$user)->with('status','Account restored.');
    }

    private function validated(Request $request, ?User $member): array
    {
        $base=[
            'name'=>['required','string','min:2','max:120'],
            'username'=>['nullable','string','regex:/^[a-zA-Z][a-zA-Z0-9_.-]{2,59}$/D',Rule::unique('users','username')->ignore($member?->id)],
            'email'=>['required','email:rfc','max:255',Rule::unique('users','email')->ignore($member?->id)],
            'role'=>['required',Rule::in(User::ROLES)],
            'locale'=>['required',Rule::in(self::LOCALES)],
            'date_of_birth'=>['nullable','date_format:Y-m-d','before:today','after:1900-01-01'],
            'nationality'=>['nullable','string','max:80'],
            'bio'=>['nullable','string','max:3000'],
            'password'=>[$member ? 'nullable' : 'required','string','min:12','max:128','confirmed'],
            'permissions'=>['nullable','array'],
        ];
        foreach(array_keys(UserManagementAccess::CAPABILITIES) as $cap) {
            $base['permissions.'.$cap]=['sometimes','required','boolean'];
        }
        if($member) $base['account_status']=['required',Rule::in(['active','suspended','archived'])];
        $data=$request->validate($base);
        // No unknown permission keys may be silently persisted.
        foreach(array_keys($data['permissions']??[]) as $permission) {
            abort_unless(array_key_exists($permission,UserManagementAccess::CAPABILITIES),422);
        }
        return $data;
    }

    private function guardProtected(User $member,array $data,User $actor): void
    {
        $owner=strcasecmp($member->email,CreatorBadgeAwards::OWNER_EMAIL)===0 && $member->role==='super_admin';
        if($owner){
            abort_if($data['role']!=='super_admin' || strcasecmp($data['email'],$member->email)!==0
                || ($data['account_status']??'active')!=='active',422,
                'The designated platform owner cannot be demoted, renamed by email, suspended or archived.');
        }
        abort_if($member->is($actor) &&
            ($data['role']!=='super_admin' || ($data['account_status']??'active')!=='active'),422,
            'Cannot revoke your own administrator access.');
        if($member->role==='super_admin'
            && ($data['role']!=='super_admin' || ($data['account_status']??'active')!=='active')){
            abort_if(User::query()->where('role','super_admin')->where('account_status','active')->count()<=1,422,
                'Cannot deactivate or demote the last active super administrator.');
        }
    }

    private function saveProfile(User $user,array $data): void
    {
        UserProfile::query()->updateOrCreate(['user_id'=>$user->id],[
            'date_of_birth'=>$data['date_of_birth']??null,
            'nationality'=>$data['nationality']??null,
            'bio'=>$data['bio']??null,
        ]);
    }

    private function savePermissions(User $user,array $permissions): void
    {
        foreach(UserManagementAccess::CAPABILITIES as $key=>$label) {
            DB::table('user_permission_overrides')->updateOrInsert(
                ['user_id'=>$user->id,'permission'=>$key],
                ['allowed'=>(bool)($permissions[$key]??true),'updated_at'=>now(),'created_at'=>now()]
            );
        }
    }

    private function recordAction(Request $request,User $user,string $action,array $fields=[],?string $reason=null): void
    {
        DB::table('user_management_events')->insert([
            'actor_id'=>$request->user()->id,'user_id'=>$user->id,
            'action'=>$action,'changed_fields'=>json_encode($fields,JSON_THROW_ON_ERROR),
            'reason'=>$reason,'created_at'=>now(),
        ]);
    }

    private function counts(User $user): array
    {
        return [
            'products'=>MarketplaceListing::query()->where('user_id',$user->id)->where('type','product')->count(),
            'projects'=>MarketplaceListing::query()->where('user_id',$user->id)->where('type','project')->count(),
            'services'=>MarketplaceListing::query()->where('user_id',$user->id)->where('type','service')->count(),
            'freelance'=>MarketplaceListing::query()->where('user_id',$user->id)->where('type','freelance')->count(),
            'jobs'=>MarketplaceJob::query()->where('client_id',$user->id)->count(),
            'contracts'=>DB::table('freelance_contracts')->where('client_id',$user->id)->orWhere('freelancer_id',$user->id)->count(),
        ];
    }
}
