<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Canceled => 'Canceled',
        };
    }

    /**
     * Whether the subscriber may reach the dashboard rather than the paywall.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active], true);
    }

    /**
     * Only genuinely paying shops count towards an owner's variable fee.
     * Trialing shops are not billed to the owner.
     */
    public function countsTowardsVariableFee(): bool
    {
        return $this === self::Active;
    }
}
