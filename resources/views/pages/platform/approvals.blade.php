<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approvals')] class extends Component {
    // Skeleton only — Commit 3 introduces the `Approval` model and wires
    // pending owner registrations, partner applications, invoice
    // adjustments and high-value shop invitations into this page.
    public int $pending = 0;
}; ?>

<div>
    <x-page-header title="Approvals" subtitle="Sign-ups, adjustments and high-value invites that need a platform admin sign-off" />

    <x-panel heading="Coming next">
        <p class="text-sm text-ink-2">
            An approvals inbox arrives in the next commit. It will queue new owner sign-ups,
            partner applications, manual invoice adjustments and shop invitations above
            R500 for a platform admin to approve or reject with a note.
        </p>
    </x-panel>
</div>
