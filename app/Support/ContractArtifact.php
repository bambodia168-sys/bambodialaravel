<?php

namespace App\Support;

use App\Models\MilestoneSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ContractArtifact
{
    public static function persist(UploadedFile $file,int $contractId,int $milestoneId): array
    {
        $inspection=ProductVault::inspect($file);
        $path='marketplace/contract-deliverables/'.$contractId.'/'.$milestoneId.'/'.
            bin2hex(random_bytes(32)).'.'.$inspection['type'];

        $stored=Storage::disk('local')->putFileAs(dirname($path),$file,basename($path));
        if($stored===false){
            throw ValidationException::withMessages(['artifact'=>'Private deliverable storage is unavailable.']);
        }
        $name=basename(str_replace('\\','/',$file->getClientOriginalName()));
        $name=preg_replace('/[\x00-\x1f\x7f]/','',$name) ?: 'deliverable.'.$inspection['type'];
        return [
            'original_filename'=>mb_substr($name,0,180),
            'storage_path'=>$path,
            'file_type'=>$inspection['type'],
            'byte_size'=>(int)$file->getSize(),
            'sha256'=>$inspection['sha256'],
        ];
    }

    public static function verifiedPath(MilestoneSubmission $submission): ?string
    {
        if($submission->storage_path===null || $submission->sha256===null
            || $submission->byte_size===null || $submission->file_type===null){
            return null;
        }
        if(! preg_match('#^marketplace/contract-deliverables/[0-9]+/[0-9]+/[a-f0-9]{64}\.(zip|pdf|pptx)$#D',
            $submission->storage_path)){
            return null;
        }
        $disk=Storage::disk('local');
        if(! $disk->exists($submission->storage_path)){
            return null;
        }
        $path=$disk->path($submission->storage_path);
        if(! is_file($path) || is_link($path)
            || filesize($path)!==(int)$submission->byte_size
            || ! hash_equals($submission->sha256,hash_file('sha256',$path))){
            return null;
        }
        return $path;
    }
}
