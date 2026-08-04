<?php

namespace Database\Seeders;

use App\Enums\BaseTier;
use App\Enums\CameraRole;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationType;
use App\Enums\PlateTagType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\WatchlistKind;
use App\Jobs\GeneratePartnerPayouts;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PlateTag;
use App\Models\ShopInvitation;
use App\Models\ShopSubscription;
use App\Models\Site;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Models\WatchlistPlate;
use App\Support\Billing\InvoiceBuilder;
use Database\Seeders\Support\PlateFaker;
use Database\Seeders\Support\TrafficGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Builds the whole hierarchy so the app is explorable on first run: one owner
 * with two sites, cameras on each, a week of realistic traffic, shop
 * sub-accounts on the busier site, and a partner earning commission.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * Days of traffic history to generate.
     */
    protected const HISTORY_DAYS = 7;

    public function run(): void
    {
        $partner = Partner::create([
            'name' => 'Sentinel Camera Installations',
            'email' => 'accounts@sentinel-installs.co.za',
            'commission_rate' => 0.20,
        ]);

        $owner = Organization::create([
            'name' => 'Charsley Property Group',
            'type' => OrganizationType::Owner,
            'referred_by_partner_id' => $partner->id,
            'settings' => ['platform_shop_revenue_share' => 0.30],
        ]);

        $junction = $this->createSite($owner, 'Garsfontein Junction', '812 Garsfontein Rd, Pretoria', [
            ['North entrance', CameraRole::Entrance],
            ['South entrance', CameraRole::Entrance],
            ['North exit', CameraRole::Exit],
            ['South exit', CameraRole::Exit],
            ['Parkade east', CameraRole::Both],
            ['Parkade west', CameraRole::Both],
        ]);

        $corner = $this->createSite($owner, 'Menlyn Corner', '14 Atterbury Rd, Pretoria', [
            ['Main entrance', CameraRole::Entrance],
            ['Main exit', CameraRole::Exit],
            ['Service lane', CameraRole::Both],
            ['Rooftop deck', CameraRole::Both],
        ]);

        $this->createUsers($owner);
        $this->createSubscriptions($junction, $corner);
        $this->createShops($junction);
        $this->createInvoices();

        $this->generateTraffic($junction, 1.0);
        $this->generateTraffic($corner, 0.45);

        $this->tagStaffPlates($junction, $corner);
        $this->seedWatchlist($junction, $corner);
    }

    /**
     * Hand-picked watchlist entries so the Watchlist tab isn't empty on the
     * demo. Real plates come from the traffic history where possible, so hit
     * counts show real recent activity.
     */
    protected function seedWatchlist(Site $junction, Site $corner): void
    {
        $sampleFromTraffic = fn (Site $site) => $site->visits()->inRandomOrder()->value('plate_number') ?? 'ABC001GP';

        $entries = [
            [$junction, WatchlistKind::Block, 'STOLEN1GP', 'Reported stolen — do not admit', null],
            [$junction, WatchlistKind::Watch, $sampleFromTraffic($junction), 'Repeated late-night loitering', now()->addWeeks(4)],
            [$junction, WatchlistKind::Vip,   $sampleFromTraffic($junction), 'Anchor-tenant landlord', null],
            [$corner,   WatchlistKind::Watch, $sampleFromTraffic($corner),   'Suspected in trolley theft', now()->addWeeks(2)],
        ];

        foreach ($entries as [$site, $kind, $plate, $reason, $expires]) {
            WatchlistPlate::create([
                'site_id' => $site->id,
                'plate_number' => $plate,
                'kind' => $kind,
                'reason' => $reason,
                'expires_at' => $expires,
            ]);
        }
    }

    /**
     * @param  list<array{0: string, 1: CameraRole}>  $cameras
     */
    protected function createSite(Organization $owner, string $name, string $address, array $cameras): Site
    {
        $site = Site::create([
            'organization_id' => $owner->id,
            'name' => $name,
            'address' => $address,
            'settings' => ['dwell_alert_hours' => 4],
        ]);

        foreach ($cameras as $index => [$cameraName, $role]) {
            Camera::create([
                'site_id' => $site->id,
                'name' => $cameraName,
                'role' => $role,
                'ip_address' => sprintf('10.%d.20.%d', $site->id, $index + 11),
                'isapi_username' => 'admin',
                'isapi_password' => 'demo-camera-password',
                'channel_id' => 1,
                'is_active' => true,
                // Most cameras are healthy; one is deliberately stale so the
                // Cameras page has something to report.
                'last_event_at' => $index === 4 ? now()->subHours(9) : now()->subMinutes(random_int(1, 6)),
                'last_probe_ok_at' => $index === 4 ? now()->subHours(9) : now()->subMinutes(random_int(1, 6)),
                'last_probe_error' => $index === 4 ? 'Connection timed out after 10s' : null,
            ]);
        }

        return $site->refresh();
    }

    protected function createUsers(Organization $owner): void
    {
        User::create([
            'name' => 'Platform Admin',
            'email' => 'platform@centrevision.co.za',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'organization_id' => null,
            'role' => UserRole::PlatformAdmin,
        ]);

        User::create([
            'name' => 'Paul Charsley',
            'email' => 'owner@centrevision.co.za',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'organization_id' => $owner->id,
            'role' => UserRole::OwnerAdmin,
        ]);

        // A hired guard for the owner, seeded so the security-operator flow
        // shows up alongside the other roles on the demo login card.
        User::create([
            'name' => 'Jane Radebe',
            'email' => 'security@centrevision.co.za',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'organization_id' => $owner->id,
            'role' => UserRole::SecurityOperator,
        ]);
    }

    protected function createSubscriptions(Site $junction, Site $corner): void
    {
        // Six cameras puts Garsfontein Junction on the Standard tier.
        SiteSubscription::create([
            'site_id' => $junction->id,
            'base_tier' => BaseTier::Standard,
            'base_fee' => BaseTier::Standard->baseFee(),
            'variable_rate_per_camera_per_subuser' => config('trafficflow.variable_rate_per_camera_per_subuser'),
            'variable_fee_cap' => 1500.00,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->endOfMonth(),
        ]);

        SiteSubscription::create([
            'site_id' => $corner->id,
            'base_tier' => BaseTier::Starter,
            'base_fee' => BaseTier::Starter->baseFee(),
            'variable_rate_per_camera_per_subuser' => config('trafficflow.variable_rate_per_camera_per_subuser'),
            'variable_fee_cap' => null,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->endOfMonth(),
        ]);
    }

    protected function createShops(Site $site): void
    {
        $definitions = [
            ['Kloof Coffee Roasters', 'manager@kloofcoffee.co.za', 450.00, SubscriptionStatus::Active],
            ['Junction Pharmacy', 'admin@junctionpharmacy.co.za', 400.00, SubscriptionStatus::Active],
            ['Threadbare Clothing', 'owner@threadbare.co.za', 350.00, SubscriptionStatus::Active],
            ['Baseline Fitness', 'accounts@baselinefitness.co.za', 500.00, SubscriptionStatus::PastDue],
        ];
        foreach ($definitions as $index => [$name, $email, $amount, $status]) {
            $shop = Organization::create([
                'name' => $name,
                'type' => OrganizationType::Shop,
                'parent_site_id' => $site->id,
            ]);

            ShopSubscription::create([
                'organization_id' => $shop->id,
                'monthly_amount' => $amount,
                'status' => $status,
                'current_period_ends_at' => now()->endOfMonth(),
            ]);

            User::create([
                'name' => $name.' Manager',
                'email' => $index === 0 ? 'shop@centrevision.co.za' : $email,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'organization_id' => $shop->id,
                'role' => UserRole::ShopAdmin,
            ]);

            // The first shop also has a read-only user.
            if ($index === 0) {
                User::create([
                    'name' => 'Kloof Coffee Barista',
                    'email' => 'viewer@centrevision.co.za',
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'organization_id' => $shop->id,
                    'role' => UserRole::ShopViewer,
                ]);
            }
        }

        // One invite still outstanding, so the Shops page shows both states.
        ShopInvitation::create([
            'site_id' => $site->id,
            'shop_name' => 'Corner Bakery',
            'email' => 'hello@cornerbakery.co.za',
            'token' => ShopInvitation::generateToken(),
            'monthly_amount' => 400.00,
            'expires_at' => now()->addDays(9),
        ]);
    }

    /**
     * Two months of billing, run through the real InvoiceBuilder so the demo
     * shows exactly what production would issue. The older month is settled
     * and the recent one is still awaiting payment, so the Billing page has
     * both states and something to click Pay now on.
     */
    protected function createInvoices(): void
    {
        $builder = app(InvoiceBuilder::class);

        $settled = now()->subMonthsNoOverflow(2)->startOfMonth();
        $outstanding = now()->subMonthNoOverflow()->startOfMonth();

        foreach ($builder->generateForPeriod($settled) as $invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $invoice->period_end->copy()->addDays(2),
            ]);
        }

        $builder->generateForPeriod($outstanding);

        // Commission follows settled revenue, so only the older month counts.
        (new GeneratePartnerPayouts($settled->toDateString()))->handle();
    }

    protected function generateTraffic(Site $site, float $scale): void
    {
        $from = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();

        (new TrafficGenerator($site, $scale))->run($from, now()->startOfDay());
    }

    /**
     * Pre-tag the staff plates so shopper metrics are already correct before
     * TagRecurringPlates has run for the first time.
     */
    protected function tagStaffPlates(Site ...$sites): void
    {
        foreach ($sites as $site) {
            foreach (PlateFaker::staff() as $plate) {
                $exists = $site->visits()->where('plate_number', $plate)->exists();

                if (! $exists) {
                    continue;
                }

                PlateTag::create([
                    'site_id' => $site->id,
                    'plate_number' => $plate,
                    'tag' => PlateTagType::RecurringPattern,
                    'tagged_at' => now(),
                    'evidence' => ['source' => 'demo seeder'],
                ]);
            }
        }
    }
}
