<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('bambodia_api_owner');
        AuditEvent::record('developer.api.account.read', $owner, $request);

        return response()->json(['data' => [
            'public_id' => $owner->public_id,
            'name' => $owner->name,
            'email' => $owner->email,
            'role' => $owner->role,
            'locale' => $owner->locale,
        ]])->header('Cache-Control', 'no-store');
    }
}
