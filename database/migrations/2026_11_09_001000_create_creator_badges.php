<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_badges', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 70)->unique();
            $table->string('name', 90);
            $table->text('description');
            $table->string('icon', 40)->default('award');
            $table->string('tone', 24)->default('blue');
            $table->string('grant_mode', 12)->default('manual');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('creator_badge_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_badge_id')->constrained('creator_badges')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 12)->default('manual');
            $table->text('evidence_note')->nullable();
            $table->timestamps();
            $table->unique(['creator_badge_id','user_id']);
        });
        $badges = [
            ['top-rated','Top Rated','Demonstrates a sustained track record of top-tier client ratings, high overall earnings, and consistent project delivery.','star','gold','manual'],
            ['rising-talent','Rising Talent','Recognizes emerging professionals with strong potential, high early engagement, and early project success.','trending-up','purple','auto'],
            ['expert-vetted','Expert Vetted','Confirms elite, industry-leading expertise through deep technical screenings and direct portfolio evaluations.','shield-check','blue','manual'],
            ['fast-responder','Fast Responder','Highlights quick, reliable communication and rapid reply times to client inquiries and project messages.','zap','amber','manual'],
            ['identity-verified','Identity Verified','Verifies official government identification and authenticity to ensure a safe, trusted working relationship.','badge-check','blue','manual'],
            ['job-success-100','100% Job Success','Reflects a perfect record of client satisfaction across all completed contracts and deliverables.','target','green','manual'],
            ['repeat-clients','Repeat Clients','Shows strong client retention, ongoing partnerships, and re-hires built on trust and consistent results.','users','purple','auto'],
            ['top-rated-plus','Top Rated Plus','Reserved for top performers with extensive experience delivering high-value, long-term, and enterprise-level contracts.','crown','gold','manual'],
            ['on-time-delivery','On-Time Delivery','Signals punctuality and strict adherence to project deadlines.','clock-check','green','auto'],
            ['pro-verified','Pro Verified','Highlights that identity, background, and professional credentials have been manually vetted.','shield','blue','manual'],
            ['super-seller-level-two','Super Seller / Level Two','Indicates consistent high-volume orders with sustained 5-star ratings.','medal','gold','manual'],
            ['top-collaborator','Top Collaborator','Emphasizes strong communication, team integration, and proactive project alignment.','handshake','purple','manual'],
            ['skill-certified','Skill Certified','Validates technical or platform-specific proficiency through official assessments.','graduation-cap','blue','manual'],
            ['high-earner','High Earner','Demonstrates substantial career earnings and experience managing large budgets.','gem','gold','manual'],
            ['community-leader','Community Leader','Recognizes mentorship, forum contributions, or industry thought leadership.','heart-handshake','green','manual'],
            ['vip-client-favorite','VIP Client Favorite','Signals proven success working directly with enterprise or high-profile accounts.','heart','pink','manual'],
        ];
        foreach ($badges as $index => [$slug,$name,$description,$icon,$tone,$mode]) {
            DB::table('creator_badges')->insert([
                'slug'=>$slug,'name'=>$name,'description'=>$description,'icon'=>$icon,
                'tone'=>$tone,'grant_mode'=>$mode,'enabled'=>true,
                'sort_order'=>$index + 1,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('creator_badge_grants');
        Schema::dropIfExists('creator_badges');
    }
};