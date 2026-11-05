<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MilestoneSubmission;
use App\Support\ContractArtifact;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractArtifactController extends Controller
{
    public function download(Request $request,MilestoneSubmission $submission): BinaryFileResponse
    {
        $submission->load('milestone.contract');
        $milestone=$submission->milestone;
        $contract=$milestone?->contract;
        abort_unless($contract!==null,404);

        $participant=$contract->isParticipant((int)$request->user()->id);
        $caseReviewer=!$participant
            && in_array($request->user()->role,['super_admin','admin'],true)
            && $contract->dispute()->exists();

        abort_unless($participant||$caseReviewer,404);
        abort_unless($submission->storage_path!==null,404);

        $absolute=ContractArtifact::verifiedPath($submission);
        abort_unless($absolute!==null,409,'Milestone artifact integrity could not be verified.');

        AuditEvent::record($caseReviewer?'contract.file.case_inspected':'contract.file.downloaded',
            $request->user(),$request);

        $filename='bambodia-deliverable-'.$contract->public_id.
            '-'.$milestone->sequence.'-'.$submission->id.'.'.$submission->file_type;

        return response()->download($absolute,$filename,[
            'Content-Type'=>'application/octet-stream',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Cross-Origin-Resource-Policy'=>'same-origin',
            'Content-Security-Policy'=>'sandbox',
        ]);
    }
}
