<?php

namespace Tests\Feature;

use App\Http\Middleware\SetRobotsHeaders;
use App\Models\Distributor;
use App\Models\DistributorProduct;
use App\Models\MasterAmmunition;
use App\Models\User;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function distributor(): Distributor
    {
        return Distributor::create([
            'name' => 'RSR Group',
            'slug' => 'rsr',
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => ['host' => 'sftp.example.test', 'username' => 'u'],
        ]);
    }

    private function master(): MasterAmmunition
    {
        return MasterAmmunition::create([
            'manufacturer' => 'Magtech',
            'mfr_part_number' => 'MPN-1',
            'name' => 'Master 1',
            'caliber' => '9mm Luger',
            'bullet_weight_gr' => 115,
            'bullet_type' => 'FMJ',
            'rounds_per_box' => 1000,
            'is_tracked_in_report' => true,
        ]);
    }

    private function flaggedOffering(): DistributorProduct
    {
        return DistributorProduct::create([
            'distributor_id' => $this->distributor()->id,
            'master_ammunition_id' => $this->master()->id,
            'distributor_sku' => 'BULK9',
            'raw_description' => 'RECLAIMED 9MM RANGE BRASS LOADED',
            'wholesale_price' => 12.88,
            'quantity_available' => 100,
            'is_in_stock' => true,
            'needs_review' => true,
            'review_reason' => '$0.0129/rd is below the centerfire_handgun floor of $0.08',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Landing page + root redirect */
    /* ------------------------------------------------------------------ */

    public function test_root_shows_the_branded_landing_page_for_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Firehole Industry Data Operations')
            ->assertSee('Tactical Ammunition Supply Intelligence &amp; Distributor Analytics.', false)
            ->assertSee('Secure Access')
            ->assertSee(route('login'), false);
    }

    public function test_root_redirects_authenticated_users_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('supply.dashboard'));
    }

    public function test_login_route_and_password_routes_exist(): void
    {
        $this->get('/login')->assertOk()->assertSee('Secure Access');
        $this->get('/password/reset')->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Route::has('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('logout'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('password.request'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('password.email'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('password.reset'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('password.update'));
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'ops@firehole.com', 'password' => 'secret-pw-123']);

        $this->post('/login', ['email' => 'ops@firehole.com', 'password' => 'secret-pw-123'])
            ->assertRedirect(route('supply.dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /*  Robots / crawler shielding */
    /* ------------------------------------------------------------------ */

    public function test_robots_txt_disallows_every_crawler(): void
    {
        $response = $this->get('/robots.txt')->assertOk();

        $this->assertSame("User-agent: *\nDisallow: /\n", $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_committed_public_robots_file_disallows_the_app_but_allows_the_public_api(): void
    {
        $robots = str_replace("\r\n", "\n", (string) file_get_contents(public_path('robots.txt')));

        $this->assertSame(
            "User-agent: *\n"
            ."Allow: /api/v1/supply-summary\n"
            ."Disallow: /dashboard\n"
            ."Disallow: /report\n"
            ."Disallow: /\n",
            $robots,
        );

        // The catch-all still shields everything that is not explicitly allowed.
        $this->assertStringContainsString("\nDisallow: /\n", $robots);
    }

    public function test_x_robots_tag_header_is_present_on_every_response(): void
    {
        foreach (['/', '/login', '/report', '/dashboard', '/robots.txt'] as $uri) {
            $this->get($uri)->assertHeader('X-Robots-Tag', SetRobotsHeaders::DIRECTIVE);
        }
    }

    public function test_the_master_layout_carries_the_robots_meta_tag(): void
    {
        $this->get(route('supply.index'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">', false);
    }

    /* ------------------------------------------------------------------ */
    /*  Admin bypasses the passphrase gate */
    /* ------------------------------------------------------------------ */

    public function test_admin_approves_a_flagged_offering_without_the_passphrase(): void
    {
        $offering = $this->flaggedOffering();

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 50])
            ->assertRedirect(route('supply.dashboard'));

        $this->assertFalse((bool) $offering->fresh()->needs_review);
        $this->assertSame(50, $offering->fresh()->round_count);
        $this->assertDatabaseHas('distributor_sku_overrides', [
            'distributor_sku' => 'BULK9',
            'round_count' => 50,
        ]);
        // No passphrase was ever placed in the session.
        $this->assertNull(session('feed_admin_authenticated'));
    }

    public function test_admin_bulk_ignores_without_the_passphrase(): void
    {
        $offering = $this->flaggedOffering();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('supply.offerings.ignore_all', ['review' => 'flagged']))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $offering->fresh()->is_ignored);
        $this->assertFalse((bool) $offering->fresh()->needs_review);
    }

    public function test_admin_dashboard_drawer_shows_resolution_controls_without_unlocking(): void
    {
        $this->flaggedOffering();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.dashboard', ['review' => 'flagged']))
            ->assertOk()
            ->assertSee('Approve')
            ->assertSee('Ignore')
            ->assertDontSee('Unlock feed admin');
    }

    public function test_admin_hitting_the_unlock_screen_is_sent_straight_through(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.unlock'))
            ->assertRedirect(route('supply.distributors'));
    }

    /* ------------------------------------------------------------------ */
    /*  Non-admin still needs the passphrase / cannot reach feed health */
    /* ------------------------------------------------------------------ */

    public function test_non_admin_override_is_gated_behind_the_passphrase(): void
    {
        $offering = $this->flaggedOffering();

        $this->actingAs(User::factory()->create())
            ->patch(route('supply.offerings.approve', $offering), ['round_count' => 50])
            ->assertRedirect(route('admin.unlock'));

        $this->assertTrue((bool) $offering->fresh()->needs_review);
        $this->assertDatabaseCount('distributor_sku_overrides', 0);
    }

    public function test_non_admin_bulk_ignore_is_gated_behind_the_passphrase(): void
    {
        $offering = $this->flaggedOffering();

        $this->actingAs(User::factory()->create())
            ->post(route('supply.offerings.ignore_all'))
            ->assertRedirect(route('admin.unlock'));

        $this->assertFalse((bool) $offering->fresh()->is_ignored);
    }

    public function test_non_admin_can_still_unlock_with_the_passphrase_and_then_resolve(): void
    {
        config(['feed.admin_passphrase' => 'let-me-in']);
        $offering = $this->flaggedOffering();

        $this->actingAs(User::factory()->create())
            ->withSession(['feed_admin_authenticated' => true])
            ->patch(route('supply.offerings.ignore', $offering))
            ->assertRedirect(route('supply.dashboard'));

        $this->assertTrue((bool) $offering->fresh()->is_ignored);
    }

    public function test_non_admin_cannot_reach_distributors_and_feed_health(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('supply.distributors'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_from_distributors_and_feed_health(): void
    {
        $this->get(route('supply.distributors'))->assertRedirect(route('login'));
    }

    public function test_admin_can_reach_distributors_and_feed_health(): void
    {
        $this->distributor();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.distributors'))
            ->assertOk()
            ->assertSee('Distributors');
    }

    public function test_navigation_hides_the_feed_health_link_for_non_admins(): void
    {
        $this->get(route('supply.index'))
            ->assertOk()
            ->assertDontSee('Distributors & Feed Health');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('supply.index'))
            ->assertOk()
            ->assertSee('Distributors & Feed Health');
    }

    /* ------------------------------------------------------------------ */
    /*  user:create-admin command */
    /* ------------------------------------------------------------------ */

    public function test_create_admin_command_creates_a_new_elevated_user(): void
    {
        $this->artisan('user:create-admin', ['email' => 'newadmin@firehole.com', '--name' => 'New Admin'])
            ->expectsQuestion('Password (min 8 characters)', 'sup3r-secret')
            ->expectsQuestion('Confirm password', 'sup3r-secret')
            ->assertExitCode(0);

        $user = User::where('email', 'newadmin@firehole.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertSame('New Admin', $user->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('sup3r-secret', $user->password));
    }

    public function test_create_admin_command_elevates_an_existing_user(): void
    {
        User::factory()->create(['email' => 'someone@firehole.com', 'is_admin' => false]);

        $this->artisan('user:create-admin', ['email' => 'someone@firehole.com'])
            ->expectsQuestion('Password (min 8 characters)', 'another-secret')
            ->expectsQuestion('Confirm password', 'another-secret')
            ->assertExitCode(0);

        $this->assertTrue(User::where('email', 'someone@firehole.com')->firstOrFail()->isAdmin());
    }

    public function test_create_admin_command_rejects_a_bad_email(): void
    {
        $this->artisan('user:create-admin', ['email' => 'not-an-email'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
