<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Self-registration is how a property owner starts a trial, so it also
     * creates the organization and a first site: without a site the user would
     * have nothing to look at and the tenant middleware would turn them away.
     * Shop users never come through here; they arrive via an invitation.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'organization_name' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $organization = Organization::create([
                'name' => $input['organization_name'] ?? $input['name'],
                'type' => OrganizationType::Owner,
            ]);

            Site::query()->withoutGlobalScope(SiteScope::class)->create([
                'organization_id' => $organization->getKey(),
                'name' => 'My first site',
            ]);

            return User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'organization_id' => $organization->getKey(),
                'role' => UserRole::OwnerAdmin,
            ]);
        });
    }
}
