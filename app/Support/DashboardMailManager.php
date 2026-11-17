<?php

namespace App\Support;

use App\Models\MailDeliverySetting;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use PDOException;

use function Illuminate\Support\enum_value;

class DashboardMailManager extends MailManager
{
    public const DRIVER = 'dashboard-smtp';

    public function getDefaultDriver()
    {
        return $this->activeProfile() === null ? parent::getDefaultDriver() : self::DRIVER;
    }

    public function mailer($name = null)
    {
        $name = enum_value($name) ?: null;
        if ($name === null || $name === self::DRIVER) {
            $profile = $this->activeProfile();

            if ($profile !== null) {
                $mailer = $this->buildSmtp($profile, self::DRIVER);
                foreach (['reply_to', 'to', 'return_path'] as $type) {
                    $this->setGlobalAddress($mailer, [], $type);
                }

                return $mailer;
            }

            $name = parent::getDefaultDriver();
        }

        return parent::mailer($name);
    }

    public function buildSmtp(#[\SensitiveParameter] array $profile, string $name): Mailer
    {
        $mailer = $this->build([
            'name' => $name,
            'transport' => 'smtp',
            'scheme' => $profile['encryption'] === 'tls' ? 'smtps' : 'smtp',
            'require_tls' => true,
            'host' => $profile['host'],
            'port' => $profile['port'],
            'username' => $profile['username'],
            'password' => $profile['password'],
            'timeout' => 8,
        ]);
        $mailer->alwaysFrom($profile['from_address'], $profile['from_name']);

        return $mailer;
    }

    private function activeProfile(): ?array
    {
        try {
            return MailDeliverySetting::query()->whereKey(MailDeliverySetting::SINGLETON)
                ->whereNotNull('active')->first(['active'])?->active;
        } catch (QueryException|PDOException) {
            // During installation, the application can still use its environment mailer.
            return null;
        }
    }
}
