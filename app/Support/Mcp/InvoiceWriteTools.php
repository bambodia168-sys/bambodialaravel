<?php

namespace App\Support\Mcp;

use App\Models\User;
use App\Policies\MarketplaceInvoicePolicy;
use App\Support\Invoices\InvoiceDraftService;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Confirmed draft-only mutations through the native transaction service. */
final class InvoiceWriteTools
{
    public const TOOLS=[
        'bambodia_invoice_draft_create'=>'Create one private, unissued invoice draft from your eligible source order.',
        'bambodia_invoice_draft_update'=>'Change or clear the due date of your eligible private invoice draft.',
        'bambodia_invoice_draft_discard'=>'Permanently discard your private unissued draft; preserves the row and history.',
    ];
    private const UUID='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
    public function __construct(private readonly InvoiceDraftService $native) {}

    public static function definitions(bool $includeWrites): array
    {
        if (!$includeWrites || !MarketplaceInvoicePolicy::writesEnabled()) return [];
        $uuid=['type'=>'string','format'=>'uuid','pattern'=>self::UUID];
        $date=['type'=>['string','null'],'format'=>'date','pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $out=[];
        foreach(self::TOOLS as $name=>$description) {
            $create=$name==='bambodia_invoice_draft_create';$update=$name==='bambodia_invoice_draft_update';
            $id=$create?'order_reference':'invoice_id';
            $props=[$id=>$create?['type'=>'string','pattern'=>'^[1-9][0-9]{9}$']:$uuid,
                'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'request_id'=>$uuid,
                'confirm'=>['type'=>'boolean','const'=>true]];
            if ($create || $update) $props['due_on']=$date;
            $required=[$id,'expected_version','request_id','confirm'];
            if ($update) $required[]='due_on';
            $out[]=['name'=>$name,'description'=>$description.' Review the exact action with the user before literal confirm=true. Use the current source_version (create), version (update/discard), or discard_version (discard only), and a new UUID per new action. Replay only the same UUID and canonical payload.',
                'inputSchema'=>['type'=>'object','properties'=>$props,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>!$create&&!$update,'idempotentHint'=>true,'openWorldHint'=>false]];
        }
        return $out;
    }

    private function reject(string $field,string $message): never
    { throw ValidationException::withMessages([$field=>$message]); }

    public function call(string $name,array $args,User $owner,bool $mayRead,bool $mayWrite): array
    {
        $actor=User::query()->find($owner->getKey());
        if (!$mayRead || !$mayWrite || !$actor || $actor->role!=='super_admin' || $actor->account_status!=='active'
            || config('mcp.production_writes_enabled',false)!==true || !MarketplaceInvoicePolicy::writesEnabled()
            || !UserManagementAccess::allows($actor,'commerce_orders'))
            $this->reject('access','Active super administrator and invoice WRITE authorization required.');
        if (!isset(self::TOOLS[$name])) $this->reject('name','Unknown invoice WRITE tool.');
        $create=$name==='bambodia_invoice_draft_create';$update=$name==='bambodia_invoice_draft_update';
        $id=$create?'order_reference':'invoice_id';
        $rules=[$id=>$create?['required','string','regex:/^[1-9][0-9]{9}$/D']:['required','string','uuid','regex:/'.self::UUID.'/D'],
            'expected_version'=>['required','string','regex:/^[a-f0-9]{64}$/D'],
            'request_id'=>['required','string','uuid','regex:/'.self::UUID.'/D'],
            'confirm'=>['required','boolean']];
        if ($create || $update) $rules['due_on']=[$update?'present':'sometimes','nullable','string','regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'];
        if (array_diff(array_keys($args),array_keys($rules))) $this->reject('arguments','Unsupported arguments.');
        foreach($args as $key=>$value) {
            if ($key==='confirm' && $value!==true) $this->reject('confirm','Literal true and explicit approval required.');
            if ($key==='due_on' && $value===null) continue;
            if (in_array('string',$rules[$key],true) && !is_string($value)) $this->reject($key,'Invalid JSON scalar type.');
            if ($key==='due_on' && is_string($value) && (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)
                || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))))
                $this->reject('due_on','Invalid calendar date.');
        }
        $v=Validator::make($args,$rules)->validate();
        $action=$create?'create':($update?'update':'discard');
        try {
            $r=$this->native->mutate($actor,$action,$v[$id],$v['expected_version'],$v['request_id'],$v['confirm'],$v['due_on']??null,true);
        } catch (ModelNotFoundException $e) {
            $this->reject('resource','Authorized invoice or eligible source not found.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode()===404) $this->reject('resource','Authorized invoice or eligible source not found.');
            if ($e->getStatusCode()===409) $this->reject('conflict','Invoice operation conflicts with current state or prior request. Review before retrying.');
            throw $e;
        }
        return ['invoice_id'=>$r['invoice_id'],'status'=>$r['status'],'revision'=>$r['revision'],
            'version'=>$r['version'],'request_id'=>$r['request_id'],'audit_id'=>$r['audit_id'],'replayed'=>$r['replayed']];
    }
}
