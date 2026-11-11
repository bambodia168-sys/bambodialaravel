<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\Mcp\McpAccessGrant;
use App\Support\Mcp\BambodiaTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BambodiaMcpController extends Controller
{
    public function handle(Request $request): Response
    {
        $body = $request->json()->all();
        $id = $body['id'] ?? null;
        if (! is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0'
            || ! is_string($body['method'] ?? null)
            || (! is_null($id) && ! is_string($id) && ! is_int($id))) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request.');
        }

        if (str_starts_with($body['method'], 'notifications/')) {
            return response('', 202)->header('Cache-Control', 'no-store');
        }
        if (! array_key_exists('id', $body)) {
            return $this->error(null, -32600, 'Method calls require an id.');
        }

        $token = $request->attributes->get('bambodia_api_token');
        $owner = $request->attributes->get('bambodia_api_owner');
        if (! ($token instanceof ApiToken || $token instanceof McpAccessGrant) || ! $owner) {
            return $this->error($id, -32000, 'Unauthorized.');
        }

        $params = $body['params'] ?? [];
        if (! is_array($params) || array_is_list($params) === false && count($params) > 100) {
            return $this->error($id, -32602, 'Invalid params.');
        }

        try {
            $result = match ($body['method']) {
                'initialize' => [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'bambodia', 'version' => '0.1.0'],
                    'instructions' => 'Admin-only connector. Search/list/get records; draft mutations require mcp.write. Publishing, legal approval, uploads, deployments and security changes must be handled in their existing authorized workflows.',
                ],
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->definitions($token->allows('mcp.write'))],
                'tools/call' => $this->callTool($params, $request, $token),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => null,
            };
            if ($result === null) {
                return $this->error($id, -32601, 'Method not found.');
            }
            return $this->result($id, $result);
        } catch (ValidationException $exception) {
            return $this->toolError($id, implode(' ', array_merge(...array_values($exception->errors()))));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->toolError($id, 'Record not found.');
        } catch (Throwable $exception) {
            report($exception);
            return $this->toolError($id, 'Operation could not be completed. No details were disclosed.');
        }
    }

    private function callTool(array $params, Request $request, ApiToken|McpAccessGrant $token): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (! is_string($name) || ! is_array($args)) {
            throw ValidationException::withMessages(['arguments' => 'Invalid tool name or arguments.']);
        }
        $service = new BambodiaTools($request);
        $owner = $request->attributes->get('bambodia_api_owner');
        $writeNames = ['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site','bambodia_draft_media'];
        if (in_array($name, $writeNames, true) && ! $token->allows('mcp.write')) {
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'Write scope is required.']],
            ];
        }

        $data = match ($name) {
            'bambodia_overview' => $service->overview(),
            'bambodia_list' => $service->listing($args),
            'bambodia_get' => $service->get($args),
            'bambodia_create_draft' => $service->createDraft($args, $owner),
            'bambodia_update_draft' => $service->updateDraft($args, $owner),
            'bambodia_delete_draft' => $service->deleteDraft($args, $owner),
            'bambodia_analytics' => app(\App\Support\Mcp\AdministrationTools::class, ['request' => $request])->analytics($args),
            'bambodia_update_own_profile' => app(\App\Support\Mcp\AdministrationTools::class, ['request' => $request])->updateOwnProfile($args, $owner),
            'bambodia_manage_site' => app(\App\Support\Mcp\AdministrationTools::class, ['request' => $request])->manageSite($args, $owner),
            'bambodia_draft_media' => app(\App\Support\Mcp\DraftMediaTools::class, ['request' => $request])->change($args, $owner),
            default => throw ValidationException::withMessages(['name' => 'Unknown tool.']),
        };

        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
            'structuredContent' => $data,
            'isError' => false,
        ];
    }

    private function definitions(bool $canWrite): array
    {
        $entity = ['type' => 'string', 'enum' => BambodiaTools::ENTITIES];
        $id = ['type' => 'integer', 'minimum' => 1];
        $base = [
            [
                'name' => 'bambodia_overview',
                'description' => 'Describe Bambodia MCP capabilities and approval boundaries.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'bambodia_list',
                'description' => 'Search Bambodia records by entity and optional title/name text; returns ten records per page.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'entity' => $entity, 'search' => ['type' => 'string'], 'page' => $id,
                ], 'required' => ['entity']],
            ],
            [
                'name' => 'bambodia_get',
                'description' => 'Read one Bambodia record by database ID. Never returns passwords, tokens or private file paths.',
                'inputSchema' => ['type' => 'object', 'properties' => ['entity' => $entity, 'id' => $id], 'required' => ['entity','id']],
            ],
        ];
        $base[] = [
            'name' => 'bambodia_analytics',
            'description' => 'Read-only aggregate counts of marketplace status, media review and recent audit events. Never exposes user identities or secret values.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'days' => ['type'=>'integer','minimum'=>1,'maximum'=>90],
            ]],
        ];
        if (! $canWrite) {
            return $base;
        }

        $draft = ['type' => 'string', 'enum' => ['product','project','service','freelance','job','post','page','policy']];
        return [...$base,
            [
                'name' => 'bambodia_draft_media',
                'description' => 'Attach or detach existing approved media owned by the signed-in admin to their own unpublished product, listing, job or post/page draft. No uploading, rights bypass or publication.',
                'inputSchema' => ['type'=>'object','properties'=>[
                    'entity'=>['type'=>'string','enum'=>['product','project','service','freelance','job','post','page']],
                    'id'=>$id,
                    'action'=>['type'=>'string','enum'=>['attach','detach']],
                    'purpose'=>['type'=>'string','enum'=>['cover','featured','gallery']],
                    'media_id'=>$id,
                ],'required'=>['entity','id','action','purpose','media_id']],
            ],
            [
                'name' => 'bambodia_update_own_profile',
                'description' => 'Update only the signed-in administrator creator biography/headline/website/visibility. No other user or account settings can change.',
                'inputSchema' => ['type'=>'object','properties'=>[
                    'fields' => ['type'=>'object','properties'=>[
                        'headline'=>['type'=>'string'],
                        'biography'=>['type'=>'string'],
                        'website_url'=>['type'=>['string','null']],
                        'is_public'=>['type'=>'boolean'],
                    ]],
                ],'required'=>['fields']],
            ],
            [
                'name' => 'bambodia_manage_site',
                'description' => 'Create/update/delete website SEO metadata and public social links. Updating active records requires confirm_live_change=true; deleting requires an exact title/label.',
                'inputSchema' => ['type'=>'object','properties'=>[
                    'entity'=>['type'=>'string','enum'=>['seo','social']],
                    'action'=>['type'=>'string','enum'=>['create','update','delete']],
                    'id'=>$id,
                    'fields'=>['type'=>'object'],
                    'confirm_title'=>['type'=>'string'],
                    'confirm_live_change'=>['type'=>'boolean'],
                ],'required'=>['entity','action']],
            ],
            [
                'name' => 'bambodia_create_draft',
                'description' => 'Create an unpublished draft. Never submits for approval or publishes.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'entity' => $draft,
                    'fields' => ['type'=>'object','description'=>'Fields: title and description for listings; job also requires category; title and body for posts/pages; title, body and category for policy.'],
                ], 'required' => ['entity','fields']],
            ],
            [
                'name' => 'bambodia_update_draft',
                'description' => 'Edit allowlisted fields only on an existing unpublished draft. Never changes ownership or approval state.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'entity' => $draft, 'id' => $id, 'fields' => ['type'=>'object'],
                ], 'required' => ['entity','id','fields']],
            ],
            [
                'name' => 'bambodia_delete_draft',
                'description' => 'Permanently remove an unpublished draft only with its exact title as confirmation. Never deletes published content.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'entity' => $draft, 'id' => $id, 'confirm_title' => ['type'=>'string'],
                ], 'required' => ['entity','id','confirm_title']],
            ],
        ];
    }

    private function result(string|int|null $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result])
            ->header('Cache-Control', 'no-store');
    }

    private function error(string|int|null $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]], 200)
            ->header('Cache-Control','no-store');
    }

    private function toolError(string|int|null $id, string $message): JsonResponse
    {
        return $this->result($id, ['content'=>[['type'=>'text','text'=>$message]],'isError'=>true]);
    }
}
