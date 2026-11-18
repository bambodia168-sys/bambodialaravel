<?php

namespace App\Support\Security;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

final class PasskeyCeremonies extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();
        $url = parse_url(config('app.url'));
        $origin = ($url['scheme'] ?? '').'://'.($url['host'] ?? '');
        if (isset($url['port'])) {
            $origin .= ':'.$url['port'];
        }
        $factory->setAllowedOrigins([$origin]);

        return $factory;
    }
}
