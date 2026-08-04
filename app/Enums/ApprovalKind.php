<?php

namespace App\Enums;

/**
 * The classes of things that require a platform admin sign-off before they
 * take effect. Kept as an enum so a rogue caller cannot invent a new kind
 * that the inbox does not know how to render.
 *
 * More kinds are planned (partner registration, invoice adjustments,
 * high-value shop invitations) — this enum grows as they land.
 */
enum ApprovalKind: string
{
    case OwnerRegistration = 'owner_registration';

    public function label(): string
    {
        return match ($this) {
            self::OwnerRegistration => 'New owner sign-up',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OwnerRegistration => 'A new owner has signed up and is waiting for their trial to be unlocked.',
        };
    }
}
