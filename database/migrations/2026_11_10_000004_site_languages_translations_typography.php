<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_languages', function (Blueprint $table): void {
            $table->string('code', 8)->primary();
            $table->string('name', 80);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('site_translation_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('site_languages')->cascadeOnDelete();
            $table->string('translation_key', 130);
            $table->text('translation_value')->nullable();
            $table->boolean('is_translated')->default(false);
            $table->timestamps();
            $table->unique(['language_code', 'translation_key']);
        });
        Schema::create('site_typography_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('site_languages')->cascadeOnDelete();
            $table->string('element', 20);
            $table->string('font_family', 24);
            $table->unsignedTinyInteger('font_size');
            $table->unsignedSmallInteger('font_weight')->default(400);
            $table->decimal('line_height', 3, 2)->default(1.50);
            $table->string('alignment', 12)->default('left');
            $table->timestamps();
            $table->unique(['language_code', 'element']);
        });
        foreach ([
            'en'=>'English','zh'=>'中文','km'=>'ខ្មែរ','ko'=>'한국어',
            'ja'=>'日本語','th'=>'ไทย','vi'=>'Tiếng Việt','de'=>'Deutsch',
        ] as $code => $name) {
            DB::table('site_languages')->insert([
                'code'=>$code,'name'=>$name,'is_enabled'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_typography_rules');
        Schema::dropIfExists('site_translation_entries');
        Schema::dropIfExists('site_languages');
    }
};
