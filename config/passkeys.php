<?php

use App\Models\Passkey;
use App\Models\User;
use App\Support\Security\PasskeyCeremonies;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

return [
    'models' => ['passkey' => Passkey::class, 'authenticatable' => User::class],
    'actions' => [
        'configure_ceremony_step_manager_factory' => PasskeyCeremonies::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptionsAction::class,
        'generate_passkey_register_options' => GeneratePasskeyRegisterOptionsAction::class,
        'store_passkey' => StorePasskeyAction::class,
    ],
];
