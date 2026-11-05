<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FreelanceContract extends Model
{
    protected $fillable = [
        'job_proposal_id','client_id','freelancer_id','title','scope','terms','currency',
        'agreed_budget','terms_sha256','status','offered_at','accepted_at','completed_at','cancelled_at','freelancer_completed_at',
    ];
    protected function casts(): array
    {
        return ['agreed_budget'=>'decimal:2','offered_at'=>'datetime','accepted_at'=>'datetime',
            'completed_at'=>'datetime','cancelled_at'=>'datetime','freelancer_completed_at'=>'datetime'];
    }
    protected static function booted(): void
    {
        static::creating(function (self $contract): void {
            for ($i=0;$i<30;$i++) {
                $id=(string)random_int(100000000,999999999);
                if (! self::query()->where('public_id',$id)->exists()) {
                    $contract->public_id=$id;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate unique contract reference.');
        });
    }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function proposal(): BelongsTo { return $this->belongsTo(JobProposal::class,'job_proposal_id'); }
    public function client(): BelongsTo { return $this->belongsTo(User::class,'client_id'); }
    public function freelancer(): BelongsTo { return $this->belongsTo(User::class,'freelancer_id'); }
    public function milestones(): HasMany { return $this->hasMany(ContractMilestone::class)->orderBy('sequence'); }
    public function consents(): HasMany { return $this->hasMany(ContractConsent::class); }
    public function messages(): HasMany { return $this->hasMany(ContractMessage::class); }
    public function dispute(): HasOne { return $this->hasOne(ContractDispute::class); }
    public function isParticipant(int $userId): bool
    {
        return (int)$this->client_id===$userId || (int)$this->freelancer_id===$userId;
    }
    public function termsDigest(): string
    {
        return hash('sha256', json_encode([
            'job_proposal_id'=>$this->job_proposal_id,'client_id'=>$this->client_id,
            'freelancer_id'=>$this->freelancer_id,'title'=>$this->title,
            'scope'=>$this->scope,'terms'=>$this->terms,
            'currency'=>$this->currency,'agreed_budget'=>$this->agreed_budget,
            'milestones'=>$this->milestones()->get()->map(fn ($m)=>[
                $m->sequence,$m->title,$m->description,$m->amount,$m->due_on?->format('Y-m-d'),
            ])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
