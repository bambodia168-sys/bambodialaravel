<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('creator_badges', function (Blueprint $table): void {
            $table->json('auto_rules')->nullable();
            $table->text('eligibility_criteria')->nullable();
        });

        $defaults = [
            'rising-talent'=>[
                'min_completed'=>1,'max_completed'=>5,'max_account_age_days'=>365,
            ],
            'repeat-clients'=>[
                'min_repeat_contracts'=>2,
            ],
            'on-time-delivery'=>[
                'min_completed'=>3,'min_milestones'=>3,
            ],
        ];
        foreach ($defaults as $slug=>$rules) {
            DB::table('creator_badges')->where('slug',$slug)
                ->update(['auto_rules'=>json_encode($rules, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        Schema::table('creator_badges', function (Blueprint $table): void {
            $table->dropColumn(['auto_rules','eligibility_criteria']);
        });
    }
};
