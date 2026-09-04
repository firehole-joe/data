<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ------------------------------------------------------------------ */
    /*  Access control */
    /* ------------------------------------------------------------------ */

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.users.store'), [
                'name' => 'Nope', 'email' => 'nope@firehole.com', 'password' => 'password123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@firehole.com']);
    }

    /* ------------------------------------------------------------------ */
    /*  Index */
    /* ------------------------------------------------------------------ */

    public function test_admin_sees_the_roster_with_role_badges(): void
    {
        $admin = $this->admin();
        $standard = User::factory()->create(['name' => 'Standard Sam', 'email' => 'sam@firehole.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management')
            ->assertSee('sam@firehole.com')
            ->assertSee($admin->email)
            ->assertSee('Standard')
            ->assertSee('Admin')
            ->assertSee('Add New User');
    }

    /* ------------------------------------------------------------------ */
    /*  Store */
    /* ------------------------------------------------------------------ */

    public function test_admin_creates_a_standard_user(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Casey Field',
                'email' => 'Casey@Firehole.com',
                'password' => 'a-good-password',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $user = User::where('email', 'casey@firehole.com')->firstOrFail();
        $this->assertSame('Casey Field', $user->name);
        $this->assertFalse($user->isAdmin());
        $this->assertTrue(Hash::check('a-good-password', $user->password));
    }

    public function test_admin_creates_an_admin_user(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Dana Ops',
                'email' => 'dana@firehole.com',
                'password' => 'another-strong-1',
                'is_admin' => '1',
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue(User::where('email', 'dana@firehole.com')->firstOrFail()->isAdmin());
    }

    public function test_store_rejects_a_short_password_and_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@firehole.com']);

        $this->actingAs($this->admin())
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Too Short', 'email' => 'taken@firehole.com', 'password' => 'short',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['password', 'email']);

        $this->assertSame(2, User::count()); // the admin + the pre-existing user
    }

    /* ------------------------------------------------------------------ */
    /*  Update */
    /* ------------------------------------------------------------------ */

    public function test_admin_toggles_another_users_role(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['name' => 'Riley', 'email' => 'riley@firehole.com']);

        // Promote.
        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), [
                'name' => 'Riley', 'email' => 'riley@firehole.com', 'is_admin' => '1',
            ])
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue($target->fresh()->isAdmin());

        // Demote (omit is_admin entirely).
        $this->actingAs($admin)
            ->put(route('admin.users.update', $target), [
                'name' => 'Riley', 'email' => 'riley@firehole.com',
            ])
            ->assertRedirect(route('admin.users.index'));
        $this->assertFalse($target->fresh()->isAdmin());
    }

    public function test_admin_updates_a_users_password_only_when_provided(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['email' => 'pw@firehole.com', 'password' => 'original-pass']);
        $originalHash = $target->password;

        // No password field -> hash unchanged, name updated.
        $this->actingAs($admin)->put(route('admin.users.update', $target), [
            'name' => 'Renamed', 'email' => 'pw@firehole.com',
        ])->assertRedirect(route('admin.users.index'));

        $target->refresh();
        $this->assertSame('Renamed', $target->name);
        $this->assertSame($originalHash, $target->password);

        // With password -> rehashed.
        $this->actingAs($admin)->put(route('admin.users.update', $target), [
            'name' => 'Renamed', 'email' => 'pw@firehole.com', 'password' => 'brand-new-pass',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue(Hash::check('brand-new-pass', $target->fresh()->password));
    }

    public function test_admin_cannot_demote_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name, 'email' => $admin->email,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function test_admin_deletes_a_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['email' => 'gone@firehole.com']);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /* ------------------------------------------------------------------ */
    /*  Landing page button */
    /* ------------------------------------------------------------------ */

    public function test_landing_page_shows_the_visit_firehole_button(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('Visit Firehole.com');
        $response->assertSee('href="https://firehole.com"', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
    }
}
