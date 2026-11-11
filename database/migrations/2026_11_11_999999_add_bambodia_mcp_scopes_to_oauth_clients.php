<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel MCP supports optional per-client OAuth scopes. Passport's stock
 * oauth_clients migration does not include this field, so explicitly add it
 * before restricted ChatGPT client registration is enabled.
 *
 * Additive and reversible; never removes, publishes or changes customer data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('oauth_clients', 'scopes')) {
            Schema::table('oauth_clients', static function (Blueprint $table): void {
                $table->json('scopes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('oauth_clients', 'scopes')) {
            Schema::table('oauth_clients', static function (Blueprint $table): void {
                $table->dropColumn('scopes');
            });
        }
    }
};
