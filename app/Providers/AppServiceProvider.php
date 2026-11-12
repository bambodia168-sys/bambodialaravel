<?php

namespace App\Providers;

use App\Models\CloudStorageConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Activation is opt-in after an independent production acceptance.
        Passport::useAccessTokenEntity(\App\Support\Mcp\BambodiaPassportAccessToken::class);
        Passport::tokensCan([
            'mcp:use' => 'Read Bambodia MCP resources and reports',
            'mcp.write' => 'Edit only explicitly authorized drafts and settings',
            'offline_access' => 'Refresh an approved connection',
        ]);
        Passport::tokensExpireIn(now()->addMinutes(30));
        Passport::refreshTokensExpireIn(now()->addDays(14));
        Passport::authorizationView(function (array $parameters) {
            $user = $parameters['user'] ?? null;
            abort_unless($user instanceof \App\Models\User
                && $user->role === 'super_admin'
                && $user->account_status === 'active', 403);
            return view('auth.oauth.authorize', $parameters);
        });

        // The installer and migration commands start before this table exists.
        // Keep legacy .env settings active unless the admin explicitly chooses
        // a new Media Library upload mode in the verified Cloud Storage wizard.
        try {
            if (! Schema::hasTable('cloud_storage_connections')) {
                return;
            }
            $connection = CloudStorageConnection::query()->find(1);
        } catch (\Illuminate\Database\QueryException|\PDOException) {
            // Composer discovery, first install and offline artisan commands
            // must still work before the database connection exists.
            return;
        }
        if (! $connection) {
            return;
        }

        // Configuring the R2 disk always preserves reading existing R2 media,
        // even when uploads are set to Local.
        config(['filesystems.disks.r2' => $connection->diskConfig()]);
        Storage::forgetDisk('r2');

        if (in_array($connection->upload_mode, ['local', 'r2'], true)) {
            config(['filesystems.media_library_disk' => $connection->upload_mode]);
        }
    }
}
