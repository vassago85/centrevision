<?php

namespace App\Support\Alerts;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;

final class AlertRecipientResolver
{
    /**
     * @return list<string>
     */
    public static function emails(Site $site): array
    {
        $settings = AlertSettings::for($site);
        $emails = $settings->recipients();

        $userEmails = User::query()
            ->where('organization_id', $site->organization_id)
            ->whereIn('role', [UserRole::OwnerAdmin->value, UserRole::SecurityOperator->value])
            ->where('alert_email_opt_in', true)
            ->whereNotNull('email_verified_at')
            ->pluck('email')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->all();

        $merged = array_values(array_unique(array_filter(
            array_merge($emails, $userEmails),
            fn (string $email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL),
        )));

        return $merged;
    }
}
