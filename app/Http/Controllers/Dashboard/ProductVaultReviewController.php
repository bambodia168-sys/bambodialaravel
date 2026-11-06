<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\ProductFileVersion;
use App\Support\ProductReadiness;
use App\Support\ProductVault;
use App\Support\VaultMalwareScanner;
use App\Support\VaultReleaseGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductVaultReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status=(string)$request->query('status','pending');
        abort_unless(in_array($status,['pending','approved','rejected','disabled','all'],true),404);
        $versions=ProductFileVersion::query()
            ->with(['product:id,public_id,user_id,title,type,status,license_definition_id,cover_media_id',
                'uploader:id,name,public_id'])
            ->when($status!=='all',fn($query)=>$query->where('status',$status))
            ->latest()->paginate(10)->withQueryString();
        return view('dashboard.vault-versions',compact('versions','status'));
    }

    public function scan(Request $request,ProductFileVersion $version,VaultMalwareScanner $scanner): RedirectResponse
    {
        abort_unless(in_array($version->status,['pending','approved'],true),409);
        $path=ProductVault::verifiedPath($version->storage_path,$version->sha256,(int)$version->byte_size);
        abort_unless($path!==null,409,'Private file is missing or fails integrity validation.');

        // Scan the immutable private original, never a URL or user-supplied filesystem path.
        $result=$scanner->scanFile($path);
        abort_unless(in_array($result['status']??null,['clean','infected','error'],true),500);

        DB::transaction(function()use($request,$version,$result): void {
            $locked=ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless(in_array($locked->status,['pending','approved'],true),409);
            abort_unless(ProductVault::verifiedPath($locked->storage_path,$locked->sha256,
                (int)$locked->byte_size)!==null,409,'File integrity changed during screening.');

            $status=$result['status'];
            $attributes=[
                'malware_scan_status'=>$status,
                'malware_scanned_sha256'=>$status==='clean'?$locked->sha256:null,
                'malware_scan_engine'=>($status==='error'?null:mb_substr((string)($result['engine']??''),0,180))?:null,
                'malware_scanned_at'=>now(),
                'malware_scanned_by'=>$request->user()->id,
                'malware_scan_note'=>mb_substr((string)($result['note']??'Scanner returned no details.'),0,2000),
            ];
            if($status==='infected'){
                $attributes['status']=$locked->status==='approved'?'disabled':'rejected';
                $attributes['reviewed_at']=now();
                $attributes['reviewed_by']=$request->user()->id;
                $attributes['review_note']='Distribution blocked following antivirus threat detection.';
            }
            $locked->update($attributes);
            if ($status === 'infected') {
                AccountNotification::deliver(
                    (int) $locked->product()->firstOrFail()->user_id,
                    'vault.version.reviewed',
                    'Product file release blocked',
                    'Your uploaded release was blocked after malware screening.',
                    '/studio/products/'.$locked->marketplace_listing_id.'/releases'
                );
            }
            AuditEvent::record('vault.malware.'.$status,$request->user(),$request);
        });

        $message=match($result['status']){
            'clean'=>'ClamAV screening completed. Independent rights review and release approval remain required.',
            'infected'=>'A threat was detected; this release has been blocked.',
            default=>'ClamAV could not produce a reliable verdict. The release remains blocked.',
        };
        return back()->with('status',$message);
    }

    public function inspect(Request $request,ProductFileVersion $version): BinaryFileResponse
    {
        abort_unless(in_array($version->status,['pending','approved'],true),409);
        abort_unless(VaultReleaseGate::screened($version),409,
            'Complete a successful, current ClamAV scan before inspecting the original.');
        $path=ProductVault::verifiedPath($version->storage_path,$version->sha256,(int)$version->byte_size);
        abort_unless($path!==null,409,'Release integrity verification failed.');

        DB::transaction(function()use($version,$request): void {
            $locked=ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless(in_array($locked->status,['pending','approved'],true)
                && VaultReleaseGate::screened($locked),409);
            $locked->update(['inspected_at'=>now(),'inspected_by'=>$request->user()->id]);
            AuditEvent::record('vault.version.inspected',$request->user(),$request);
        });

        return response()->download($path,'bambodia-review-'.$version->id.'-'.$version->version.'.'.$version->file_type,[
            'Content-Type'=>'application/octet-stream',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Cross-Origin-Resource-Policy'=>'same-origin',
            'Content-Security-Policy'=>'sandbox',
        ]);
    }

    public function rights(Request $request,ProductFileVersion $version): RedirectResponse
    {
        $data=$request->validate([
            'decision'=>['required',Rule::in(['verified','rejected'])],
            'rights_review_note'=>['required','string','min:30','max:2000'],
            'confirm_rights'=>['exclude_unless:decision,verified','required','accepted'],
        ]);

        DB::transaction(function()use($request,$version,$data): void {
            $locked=ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            abort_unless(in_array($locked->status,['pending','approved'],true),409);
            abort_unless((int)$locked->uploaded_by!==(int)$request->user()->id,403,
                'The uploader cannot review their own distribution rights.');
            abort_unless($locked->rights_attested_at!==null && filled($locked->rights_evidence),409,
                'Creator rights evidence is required before independent review.');
            abort_unless(VaultReleaseGate::screened($locked)
                && $locked->inspected_at!==null
                && (int)$locked->inspected_by===(int)$request->user()->id,409,
                'Review the real, malware-screened original before recording distribution rights.');

            $locked->update([
                'rights_review_status'=>$data['decision'],
                'rights_review_note'=>$data['rights_review_note'],
                'rights_reviewed_by'=>$request->user()->id,
                'rights_reviewed_at'=>now(),
            ]);
            AuditEvent::record('vault.rights.'.$data['decision'],$request->user(),$request);
        });

        return back()->with('status','Distribution-rights review recorded. This is an operator assessment, not independent legal certification.');
    }

    public function decide(Request $request,ProductFileVersion $version): RedirectResponse
    {
        $data=$request->validate([
            'decision'=>['required',Rule::in(['approved','rejected','disabled'])],
            'review_note'=>['nullable','string','max:2000','required_if:decision,rejected,disabled'],
            'confirm_review'=>['sometimes','accepted'],
        ]);

        DB::transaction(function()use($version,$request,$data): void {
            $locked=ProductFileVersion::query()->lockForUpdate()->findOrFail($version->id);
            $allowed=$locked->status==='pending'?['approved','rejected']:
                ($locked->status==='approved'?['disabled']:[]);
            abort_unless(in_array($data['decision'],$allowed,true),409,
                'This release cannot enter the requested state.');

            if($data['decision']==='approved'){
                abort_unless($locked->inspected_at!==null
                    && (int)$locked->inspected_by===(int)$request->user()->id
                    && $request->boolean('confirm_review'),409,
                    'Inspect the original and confirm final release review before approving.');
                abort_unless(VaultReleaseGate::screened($locked) && VaultReleaseGate::rightsReviewed($locked),
                    409,'Current ClamAV clean result and independent rights review are required.');
                $product=$locked->product()->firstOrFail();
                abort_unless($product->status==='published',409,'The product is not currently published.');
                ProductReadiness::assertListing($product);
                if(ProductVault::verifiedPath($locked->storage_path,$locked->sha256,(int)$locked->byte_size)===null){
                    throw ValidationException::withMessages(['artifact'=>'Stored release integrity failed.']);
                }
            }

            $locked->update([
                'status'=>$data['decision'],
                'reviewed_at'=>now(),
                'reviewed_by'=>$request->user()->id,
                'review_note'=>$data['review_note']??null,
            ]);
            AccountNotification::deliver(
                (int) $locked->product()->firstOrFail()->user_id,
                'vault.version.reviewed',
                'Product file release reviewed',
                'Your product file release was '.$data['decision'].' after review.',
                '/studio/products/'.$locked->marketplace_listing_id.'/releases'
            );
            AuditEvent::record('vault.version.'.$data['decision'],$request->user(),$request);
        });

        return back()->with('status','Release marked '.$data['decision'].'.');
    }
}
