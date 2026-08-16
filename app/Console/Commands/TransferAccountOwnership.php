<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hand a customer organization from one Owner-admin user to another. Runs
 * everything in a single transaction so a mid-way failure rolls the whole
 * transfer back and no half-migrated state survives.
 *
 * Typical use: a shop owner sells their business and the account moves to
 * the buyer, who wants a fresh login and no lingering access from the old
 * owner. Historical records that reference the departing user drop to null
 * (created_by, approved_by, added_by) rather than being deleted — audit
 * history stays intact, just anonymised.
 */
class TransferAccountOwnership extends Command
{
    protected $signature = 'account:transfer-ownership
        {--from= : Email of the current Owner admin whose access will be revoked}
        {--to-name= : Full name of the new Owner admin}
        {--to-email= : Email of the new Owner admin (must not already exist)}
        {--to-password= : Password for the new Owner admin}
        {--org-name= : Optional new name for the organization}
        {--keep-from : Do not delete the departing user; leave them in place as-is}
        {--rename-user= : Email of an unrelated user (typically the platform admin) to update in the same transaction}
        {--rename-user-to= : New email for the --rename-user user (needs --rename-user)}
        {--rename-user-password= : Optional new password for the --rename-user user}';

    protected $description = 'Transfer an organization to a new Owner admin and (optionally) delete the previous one';

    public function handle(): int
    {
        $fromEmail = (string) $this->option('from');
        $toName = (string) $this->option('to-name');
        $toEmail = (string) $this->option('to-email');
        $toPassword = (string) $this->option('to-password');
        $orgName = $this->option('org-name');
        $keepFrom = (bool) $this->option('keep-from');
        $renameUser = (string) $this->option('rename-user');
        $renameUserTo = (string) $this->option('rename-user-to');
        $renameUserPassword = (string) $this->option('rename-user-password');

        foreach (['from' => $fromEmail, 'to-name' => $toName, 'to-email' => $toEmail, 'to-password' => $toPassword] as $flag => $value) {
            if ($value === '') {
                $this->components->error("--{$flag} is required");

                return self::FAILURE;
            }
        }

        if ($renameUser !== '' && $renameUserTo === '' && $renameUserPassword === '') {
            $this->components->error('--rename-user needs at least --rename-user-to or --rename-user-password');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($fromEmail, $toName, $toEmail, $toPassword, $orgName, $keepFrom, $renameUser, $renameUserTo, $renameUserPassword): void {
                $from = User::query()->where('email', $fromEmail)->first();

                if ($from === null) {
                    throw new RuntimeException("No user found with email {$fromEmail}");
                }

                $org = $from->organization;

                if ($org === null) {
                    throw new RuntimeException("{$fromEmail} has no organization to hand over");
                }

                if (User::query()->where('email', $toEmail)->exists()) {
                    throw new RuntimeException("A user with email {$toEmail} already exists — refusing to overwrite");
                }

                $this->line('');
                $this->components->info('Before');
                $this->line("  org: [{$org->name}] (id={$org->id})");
                foreach ($org->users as $u) {
                    $this->line("  - {$u->name} <{$u->email}> [{$u->role->value}]");
                }

                if (is_string($orgName) && $orgName !== '' && $orgName !== $org->name) {
                    $org->update(['name' => $orgName]);
                    $this->components->info("Renamed organization to [{$orgName}]");
                }

                $newOwner = User::create([
                    'name' => $toName,
                    'email' => $toEmail,
                    'password' => $toPassword,
                    'organization_id' => $org->id,
                    'role' => UserRole::OwnerAdmin,
                    'email_verified_at' => now(),
                ]);

                $this->components->info("Created {$newOwner->name} <{$newOwner->email}> as Owner admin (id={$newOwner->id})");

                if (! $keepFrom) {
                    $from->delete();
                    $this->components->info("Deleted departing user {$fromEmail}");
                }

                // Optional: rename / reset password on a completely separate
                // user in the same transaction. The typical case is renaming
                // the platform admin to the departing owner's now-freed email
                // so a single human still has one login they recognise.
                if ($renameUser !== '') {
                    $target = User::query()->where('email', $renameUser)->first();

                    if ($target === null) {
                        throw new RuntimeException("--rename-user {$renameUser} not found");
                    }

                    $updates = [];

                    if ($renameUserTo !== '' && $renameUserTo !== $target->email) {
                        if (User::query()->where('email', $renameUserTo)->exists()) {
                            throw new RuntimeException("Cannot rename {$renameUser} to {$renameUserTo} — email already in use");
                        }
                        $updates['email'] = $renameUserTo;
                    }

                    if ($renameUserPassword !== '') {
                        $updates['password'] = $renameUserPassword;
                    }

                    if ($updates !== []) {
                        $target->update($updates);
                        $changed = implode(', ', array_keys($updates));
                        $this->components->info("Updated {$renameUser} ({$changed})");
                    }
                }

                $this->line('');
                $this->components->info('After');
                $fresh = $org->fresh();
                $this->line("  org: [{$fresh->name}] (id={$fresh->id})");
                foreach ($fresh->users as $u) {
                    $this->line("  - {$u->name} <{$u->email}> [{$u->role->value}]");
                }
            });
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
