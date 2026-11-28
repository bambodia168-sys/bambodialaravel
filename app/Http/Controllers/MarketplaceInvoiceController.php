<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceInvoice;
use App\Policies\MarketplaceInvoicePolicy;
use App\Support\Invoices\InvoiceDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MarketplaceInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceDraftService $invoices) {}

    private function page(string $view,array $data): Response
    {
        return response()->view('invoices.'.$view,$data)->withHeaders([
            'Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex, nofollow, noarchive',
            'Referrer-Policy'=>'no-referrer',
        ]);
    }

    private function index(Request $request,bool $studio): Response
    {
        abort_unless(MarketplaceInvoicePolicy::enabled(),404);
        $allowed=$studio?MarketplaceInvoice::STATUSES:['issued','void'];
        $data=$request->validate(['status'=>['nullable','string',Rule::in($allowed)]]);
        $status=$data['status']??null;
        return $this->page('index',['studio'=>$studio,'statuses'=>$allowed,'status'=>$status,
            'invoices'=>$this->invoices->list($request->user(),$studio,$status)]);
    }

    public function studioIndex(Request $request): Response { return $this->index($request,true); }
    public function accountIndex(Request $request): Response { return $this->index($request,false); }

    public function preview(Request $request): Response
    {
        abort_unless(MarketplaceInvoicePolicy::writesEnabled() && $request->user()->isCreator(),404);
        $data=$request->validate(['order_reference'=>['required','string','regex:/^[1-9][0-9]{9}$/D']]);
        return $this->page('preview',['source'=>$this->invoices->preview($request->user(),$data['order_reference']),
            'requestId'=>(string)Str::uuid()]);
    }

    private function show(Request $request,bool $studio,string $invoice): Response
    {
        return $this->page('show',['studio'=>$studio,'invoice'=>$this->invoices->detail($request->user(),$studio,$invoice),
            'updateRequest'=>(string)Str::uuid(),'clearRequest'=>(string)Str::uuid(),'discardRequest'=>(string)Str::uuid()]);
    }

    public function studioShow(Request $request,string $invoice): Response { return $this->show($request,true,$invoice); }
    public function accountShow(Request $request,string $invoice): Response { return $this->show($request,false,$invoice); }

    private function execute(Request $request,string $action,?string $invoice=null): RedirectResponse
    {
        abort_unless(MarketplaceInvoicePolicy::writesEnabled() && $request->user()->isCreator(),404);
        $rules=['expected_version'=>['required','string','regex:/^[a-f0-9]{64}$/D'],
            'request_id'=>['required','string','uuid'],'confirm'=>['required','in:1']];
        if ($action==='create') $rules['order_reference']=['required','string','regex:/^[1-9][0-9]{9}$/D'];
        if ($action!=='discard') $rules['due_on']=[$action==='update'?'present':'sometimes','nullable','string','date_format:Y-m-d'];
        // Native HTML transport has only these fields plus CSRF/method. Reject
        // injected totals, status, ownership, recipient and notification flags.
        $unknown=array_diff(array_keys($request->all()),[...array_keys($rules),'_token','_method']);
        if ($unknown) throw ValidationException::withMessages(['request'=>'Unsupported invoice fields.']);
        $data=$request->validate($rules);
        if ($data['confirm']!=='1') throw ValidationException::withMessages(['confirm'=>'Explicit confirmation is required.']);
        $result=$this->invoices->mutate($request->user(),$action,$invoice??$data['order_reference'],
            $data['expected_version'],$data['request_id'],true,$data['due_on']??null);
        return redirect()->route('studio.invoices.show',$result['invoice_id'])->with('status',
            $result['replayed']?'Previous confirmed operation returned.':'Private invoice draft operation completed.');
    }

    public function store(Request $request): RedirectResponse { return $this->execute($request,'create'); }
    public function update(Request $request,string $invoice): RedirectResponse { return $this->execute($request,'update',$invoice); }
    public function discard(Request $request,string $invoice): RedirectResponse { return $this->execute($request,'discard',$invoice); }
}
