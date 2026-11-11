<?php

namespace App\Http\Controllers;

use App\Models\AiDraftRequest;
use App\Support\AiDraftStudio;
use App\Support\BuilderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AiDraftController extends Controller
{
    public function generate(Request $request, AiDraftStudio $studio): JsonResponse
    {
        $values = $request->validate([
            'type' => ['required', Rule::in(BuilderAccess::TYPES)],
            'reference' => ['nullable', 'regex:/\A[0-9]{9}\z/D'],
            'brief' => ['required', 'string', 'min:30', 'max:1500'],
            'tone' => ['required', Rule::in(['professional','friendly','concise'])],
            'locale' => ['required', Rule::in(['en','km','zh','ko','ja','th','vi','de','fr','hi','id','ms','es'])],
            'consent' => ['required', 'accepted'],
        ]);

        // No source record is saved or published. Only metadata is recorded.
        // The creator must review the output before inserting it in Builder.
        return response()->json($studio->generate(
            $request->user(), $values['type'], $values['brief'],
            $values['tone'], $values['locale'], $values['reference'] ?? null
        ));
    }

    public function reviewed(Request $request, AiDraftRequest $draft): JsonResponse
    {
        abort_unless((int) $draft->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($draft->status === 'completed', 409);
        $request->validate(['confirmed' => ['required','accepted']]);

        if ($draft->reviewed_at === null) {
            $updated = AiDraftRequest::query()->whereKey($draft->id)
                ->whereNull('reviewed_at')->where('status', 'completed')
                ->update(['reviewed_at' => now(), 'updated_at' => now()]);
            if ($updated) {
                \App\Models\AuditEvent::record('ai.draft.reviewed', $request->user(), $request);
            }
        }

        return response()->json([
            'reviewed' => true,
            'message' => 'Reviewed draft may now be inserted locally. Nothing has been saved or published.',
        ]);
    }
}
