<?php

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approvals')] class extends Component {
    /** Current review filter: pending / decided / all. */
    public string $filter = 'pending';

    /** Approval being reviewed in the modal, or null when the modal is closed. */
    public ?int $reviewingId = null;

    /** Free-text note the reviewer attaches to their decision. */
    public string $note = '';

    /**
     * @return Collection<int, Approval>
     */
    #[Computed]
    public function approvals(): Collection
    {
        $query = Approval::query()
            ->with(['subject', 'requestedBy:id,name,email', 'reviewedBy:id,name'])
            ->orderByDesc('created_at');

        return match ($this->filter) {
            'pending' => $query->where('status', ApprovalStatus::Pending)->get(),
            'decided' => $query->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])->limit(100)->get(),
            default => $query->limit(100)->get(),
        };
    }

    /**
     * Pending count for the counter next to the tabs. Cheap so it doesn't
     * need caching — the inbox is only ever seen by a handful of admins.
     */
    #[Computed]
    public function pendingCount(): int
    {
        return Approval::query()->where('status', ApprovalStatus::Pending)->count();
    }

    #[Computed]
    public function reviewing(): ?Approval
    {
        if ($this->reviewingId === null) {
            return null;
        }

        return Approval::query()
            ->with(['subject', 'requestedBy'])
            ->find($this->reviewingId);
    }

    public function openReview(int $id): void
    {
        $this->reviewingId = $id;
        $this->note = '';
        $this->resetValidation();
    }

    public function closeReview(): void
    {
        $this->reset(['reviewingId', 'note']);
    }

    /**
     * Approve the sign-up. Delegated per-kind so the model does not have to
     * know about organizations, and later approval kinds can plug in their
     * own side effects here.
     */
    public function approve(): void
    {
        $approval = $this->reviewing;

        abort_if($approval === null || ! $approval->isPending(), 404);

        $note = trim($this->note) === '' ? null : trim($this->note);

        \Illuminate\Support\Facades\DB::transaction(function () use ($approval, $note): void {
            $approval->approve(auth()->user(), $note);

            $this->applyApprovedSideEffects($approval);
        });

        // Reset the cached lists so the row moves out of pending in-place.
        unset($this->approvals, $this->pendingCount, $this->reviewing);
        $this->closeReview();

        Flux::toast(variant: 'success', text: 'Approval recorded.');
    }

    public function reject(): void
    {
        $approval = $this->reviewing;

        abort_if($approval === null || ! $approval->isPending(), 404);

        // A rejection without a note is almost never what the reviewer
        // intends — the applicant deserves a reason. Require it.
        $this->validate([
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $approval->reject(auth()->user(), trim($this->note));

        unset($this->approvals, $this->pendingCount, $this->reviewing);
        $this->closeReview();

        Flux::toast(variant: 'success', text: 'Rejection recorded.');
    }

    /**
     * Apply the real-world side effect of an approval. Split from
     * Approval::approve() so the model stays pure and this method can grow
     * a match arm per approval kind as more come online.
     */
    protected function applyApprovedSideEffects(Approval $approval): void
    {
        match ($approval->kind) {
            ApprovalKind::OwnerRegistration => $this->approveOwnerRegistration($approval),
        };
    }

    protected function approveOwnerRegistration(Approval $approval): void
    {
        // Subject may be null if the org was deleted between the approval
        // landing in the inbox and being reviewed. In that case there is
        // nothing left to unlock, so the recorded decision is enough.
        $org = $approval->subject;

        if (! $org instanceof Organization) {
            return;
        }

        $org->forceFill([
            'approved_at' => now(),
            'approved_by_user_id' => auth()->id(),
        ])->save();
    }
}; ?>

<div>
    <x-page-header title="Approvals" subtitle="Sign-ups, adjustments and high-value invites that need a platform admin sign-off">
        <x-slot:actions>
            <flux:button size="sm" :variant="$filter === 'pending' ? 'primary' : 'ghost'" wire:click="$set('filter', 'pending')">
                Pending
                @if ($this->pendingCount > 0)
                    <span class="ml-1 rounded-full bg-white/20 px-1.5 py-0.5 text-[10px] font-semibold">{{ $this->pendingCount }}</span>
                @endif
            </flux:button>
            <flux:button size="sm" :variant="$filter === 'decided' ? 'primary' : 'ghost'" wire:click="$set('filter', 'decided')">Decided</flux:button>
            <flux:button size="sm" :variant="$filter === 'all' ? 'primary' : 'ghost'" wire:click="$set('filter', 'all')">All</flux:button>
        </x-slot:actions>
    </x-page-header>

    <x-panel>
        <x-data-table
            :headers="['Kind', 'Subject', 'Applicant', 'Requested', 'Status', ['label' => '', 'align' => 'right']]"
            :is-empty="$this->approvals->isEmpty()"
            :empty="$filter === 'pending' ? 'Nothing pending. New sign-ups will show up here.' : 'No approvals to show.'"
        >
            @foreach ($this->approvals as $approval)
                @php
                    $payload = $approval->payload ?? [];
                @endphp

                <tr wire:key="approval-{{ $approval->id }}">
                    <td class="border-b border-line py-2 font-medium">{{ $approval->kind->label() }}</td>
                    <td class="border-b border-line py-2">
                        <div class="font-medium text-ink">{{ $payload['organization_name'] ?? '—' }}</div>
                    </td>
                    <td class="border-b border-line py-2">
                        <div class="text-ink-2">{{ $payload['user_name'] ?? '—' }}</div>
                        <div class="text-[12px] text-ink-muted">{{ $payload['user_email'] ?? '' }}</div>
                    </td>
                    <td class="border-b border-line py-2 text-ink-2">{{ $approval->created_at->diffForHumans() }}</td>
                    <td class="border-b border-line py-2">
                        <x-badge :tone="$approval->status->tone()">{{ $approval->status->label() }}</x-badge>
                        @if ($approval->reviewedBy !== null)
                            <div class="mt-1 text-[11px] text-ink-muted">
                                by {{ $approval->reviewedBy->name }}, {{ $approval->reviewed_at?->diffForHumans() }}
                            </div>
                        @endif
                    </td>
                    <td class="border-b border-line py-2 text-right">
                        @if ($approval->isPending())
                            <flux:button size="xs" variant="primary" wire:click="openReview({{ $approval->id }})">Review</flux:button>
                        @elseif (! empty($approval->review_note))
                            <flux:button size="xs" variant="ghost" wire:click="openReview({{ $approval->id }})">View note</flux:button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>
    </x-panel>

    <flux:modal wire:model.self="reviewingId" @close="$wire.closeReview()" class="md:w-[32rem]">
        @php $current = $this->reviewing; @endphp

        @if ($current !== null)
            @php $payload = $current->payload ?? []; @endphp

            <div class="space-y-4">
                <flux:heading size="lg">{{ $current->kind->label() }}</flux:heading>

                <div class="rounded-lg border border-line bg-surface-2 p-4 text-[13.5px]">
                    <p class="text-[11.5px] font-semibold uppercase tracking-[0.14em] text-ink-muted">Organization</p>
                    <p class="mt-1 font-semibold text-ink">{{ $payload['organization_name'] ?? '—' }}</p>

                    <p class="mt-3 text-[11.5px] font-semibold uppercase tracking-[0.14em] text-ink-muted">Applicant</p>
                    <p class="mt-1 text-ink">{{ $payload['user_name'] ?? '—' }} <span class="text-ink-muted">·</span> {{ $payload['user_email'] ?? '—' }}</p>

                    <p class="mt-3 text-[11.5px] font-semibold uppercase tracking-[0.14em] text-ink-muted">Requested</p>
                    <p class="mt-1 text-ink-2">{{ $current->created_at->format('j M Y, H:i') }} ({{ $current->created_at->diffForHumans() }})</p>
                </div>

                @if ($current->isPending())
                    <flux:textarea
                        wire:model="note"
                        label="Note (optional for approve, required to reject)"
                        placeholder="Why this decision? Shown to the applicant on rejection."
                        rows="3"
                    />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="closeReview" type="button">Cancel</flux:button>
                        <flux:button variant="danger" wire:click="reject">Reject</flux:button>
                        <flux:button variant="primary" wire:click="approve">Approve</flux:button>
                    </div>
                @else
                    <div class="rounded-lg border border-line bg-surface-2 p-4 text-[13.5px]">
                        <p class="text-[11.5px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                            {{ $current->status->label() }} by {{ $current->reviewedBy?->name ?? 'unknown' }}, {{ $current->reviewed_at?->diffForHumans() ?? '' }}
                        </p>
                        <p class="mt-2 text-ink">{{ $current->review_note ?: 'No note.' }}</p>
                    </div>

                    <div class="flex justify-end">
                        <flux:button variant="primary" wire:click="closeReview">Close</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
