<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wipe an entire owner organization and every child record it drags with it,
 * in a single transaction, then optionally rename another user (typically the
 * platform admin) in the same transaction. Built for the "kill the leftover
 * demo tenant" and "kill a churned customer" cases where the goal is to leave
 * no orphaned rows behind.
 *
 * The database's ON DELETE CASCADE handles most of the tree, but two things
 * are not FK-constrained and would leave orphans:
 *   - shop organizations (parent_site_id is a plain index) and their users
 *   - invoices (billable is polymorphic, site_id is nullOnDelete)
 * Both are cleaned up here explicitly before the parent org is deleted.
 *
 * Prints a --dry-run first so nobody wipes 25k plate events on autopilot.
 */
class DeleteOrganization extends Command
{
    protected $signature = 'account:delete-org
        {--id= : ID of the owner organization to delete}
        {--force : Actually perform the delete (otherwise runs as a dry-run and prints what would be removed)}
        {--rename-user= : Email of an unrelated user to update in the same transaction}
        {--rename-user-to= : New email for the --rename-user user}
        {--rename-user-password= : Optional new password for the --rename-user user}';

    protected $description = 'Delete an entire owner organization (and all child records / sub-shops) in one transaction';

    public function handle(): int
    {
        $id = (int) $this->option('id');

        if ($id <= 0) {
            $this->components->error('--id is required and must be positive');

            return self::FAILURE;
        }

        $org = Organization::find($id);

        if ($org === null) {
            $this->components->error("Organization {$id} not found");

            return self::FAILURE;
        }

        if ($org->type !== OrganizationType::Owner) {
            $this->components->error("Organization {$id} is not an Owner org (type={$org->type->value}) — refusing to run");

            return self::FAILURE;
        }

        $siteIds = $org->sites()->pluck('id');
        $cameraIds = \App\Models\Camera::whereIn('site_id', $siteIds)->pluck('id');
        $shopOrgs = Organization::query()
            ->where('type', OrganizationType::Shop)
            ->whereIn('parent_site_id', $siteIds)
            ->get();

        $shopUserCount = User::query()->whereIn('organization_id', $shopOrgs->pluck('id'))->count();

        // Every count the delete will touch, so the summary is precise and
        // doesn't hide a surprise (like "oh also 8k invoices vanished").
        $counts = [
            'org users' => $org->users()->count(),
            'sites' => $siteIds->count(),
            'cameras' => $cameraIds->count(),
            'plate events' => \App\Models\PlateEvent::whereIn('camera_id', $cameraIds)->count(),
            'visits' => \App\Models\Visit::query()
                ->withoutGlobalScope(\App\Models\Scopes\SiteScope::class)
                ->whereIn('site_id', $siteIds)->count(),
            'watchlist plates' => \App\Models\WatchlistPlate::query()
                ->withoutGlobalScope(\App\Models\Scopes\SiteScope::class)
                ->whereIn('site_id', $siteIds)->count(),
            'shop organizations' => $shopOrgs->count(),
            'shop users' => $shopUserCount,
            // Invoices are polymorphic (billable = owner org or shop org).
            // site_id lives on invoice_lines, not on the invoice itself, so
            // there's nothing to filter by site here.
            'invoices (org + shops)' => Invoice::query()
                ->where('billable_type', Organization::class)
                ->whereIn('billable_id', $shopOrgs->pluck('id')->push($org->id))
                ->count(),
        ];

        $this->line('');
        $this->components->info("Target: org id={$org->id} [{$org->name}]");
        foreach ($counts as $label => $n) {
            $this->line(sprintf('  %-32s %s', $label, number_format($n)));
        }
        $this->line('');

        if (! $this->option('force')) {
            $this->components->warn('DRY RUN — nothing has been deleted. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $renameUser = (string) $this->option('rename-user');
        $renameUserTo = (string) $this->option('rename-user-to');
        $renameUserPassword = (string) $this->option('rename-user-password');

        if ($renameUser !== '' && $renameUserTo === '' && $renameUserPassword === '') {
            $this->components->error('--rename-user needs at least --rename-user-to or --rename-user-password');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($org, $shopOrgs, $renameUser, $renameUserTo, $renameUserPassword): void {
                // 1. Invoices — polymorphic billable has no FK, so we clear
                //    invoices for both the parent org and its shop children
                //    before the orgs go away. invoice_lines cascade off
                //    invoice_id, so lines vanish with their parent invoice.
                $invoiceIds = $shopOrgs->pluck('id')->push($org->id);
                $invoiceDeleted = Invoice::query()
                    ->where('billable_type', Organization::class)
                    ->whereIn('billable_id', $invoiceIds)
                    ->delete();
                $this->components->info("Deleted {$invoiceDeleted} invoice(s)");

                // 2. Shop organizations — parent_site_id is unconstrained, so
                //    they don't cascade when their site is deleted. Each shop
                //    delete cascades its own users and shop_subscription.
                foreach ($shopOrgs as $shop) {
                    $shop->delete();
                }
                $this->components->info("Deleted {$shopOrgs->count()} shop organization(s) and their users");

                // 3. The org itself — cascades sites → cameras → plate_events,
                //    visits, watchlist, plate_tags, site_subscriptions,
                //    site_day_stats, security_invitations, shop_invitations,
                //    and the org's own users.
                $orgName = $org->name;
                $org->delete();
                $this->components->info("Deleted organization [{$orgName}] and all remaining child records");

                // 4. Optional bystander rename (typically the platform admin
                //    picking up a now-freed email).
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
            });
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->components->info('Done.');

        return self::SUCCESS;
    }
}
