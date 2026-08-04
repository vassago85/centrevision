<?php

namespace Database\Factories;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => ApprovalKind::OwnerRegistration,
            'status' => ApprovalStatus::Pending,
            'subject_type' => Organization::class,
            'subject_id' => Organization::factory()->owner(),
            'payload' => [],
            'requested_by_user_id' => null,
        ];
    }

    public function forOwnerRegistration(?Organization $organization = null): static
    {
        $organization ??= Organization::factory()->owner()->create(['approved_at' => null]);

        return $this->state(fn () => [
            'kind' => ApprovalKind::OwnerRegistration,
            'subject_type' => Organization::class,
            'subject_id' => $organization->getKey(),
        ]);
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn () => [
            'status' => ApprovalStatus::Approved,
            'reviewed_by_user_id' => $reviewer?->getKey() ?? User::factory()->platformAdmin(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(?User $reviewer = null, ?string $note = null): static
    {
        return $this->state(fn () => [
            'status' => ApprovalStatus::Rejected,
            'reviewed_by_user_id' => $reviewer?->getKey() ?? User::factory()->platformAdmin(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }
}
