<?php

namespace Tests\Feature;

use App\Models\Distributor;
use App\Models\FeedRun;
use App\Services\Feeds\Contracts\FeedDriverInterface;
use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\FeedIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DistributorAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['feed.admin_passphrase' => self::PASS]);
    }

    private function distributor(array $overrides = []): Distributor
    {
        static $n = 0;
        $n++;

        return Distributor::create(array_merge([
            'name' => "Distributor {$n}",
            'slug' => "dist-{$n}",
            'driver_class' => RsrFeedDriver::class,
            'transport_type' => 'sftp',
            'connection_settings' => [
                'host' => 'old.host.test',
                'port' => 22,
                'username' => 'olduser',
                'password' => 'old-pass',
            ],
            'is_active' => true,
            'sync_frequency' => 'hourly',
        ], $overrides));
    }

    private function asAdmin(): self
    {
        return $this->withSession(['feed_admin_authenticated' => true]);
    }

    /* ---------------------------------------------------------------- */
    /*  Passphrase gate */
    /* ---------------------------------------------------------------- */

    public function test_edit_redirects_to_unlock_when_locked(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $this->get(route('distributors.edit', 'rsr'))
            ->assertRedirect(route('admin.unlock'));

        $this->assertSame(route('distributors.edit', 'rsr'), session('feed_admin_return_url'));
    }

    public function test_post_actions_are_gated_too(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $this->post(route('distributors.sync', 'rsr'))->assertRedirect(route('admin.unlock'));
        $this->post(route('distributors.test', 'rsr'))->assertRedirect(route('admin.unlock'));
    }

    public function test_unlock_form_renders(): void
    {
        $this->get(route('admin.unlock'))
            ->assertOk()
            ->assertSee('Feed Admin')
            ->assertSee('Passphrase');
    }

    public function test_wrong_passphrase_is_rejected(): void
    {
        $this->from(route('admin.unlock'))
            ->post(route('admin.unlock.attempt'), ['passphrase' => 'not-it'])
            ->assertRedirect(route('admin.unlock'))
            ->assertSessionHasErrors('passphrase');

        $this->assertNull(session('feed_admin_authenticated'));
    }

    public function test_correct_passphrase_unlocks_and_returns_to_intended_url(): void
    {
        $target = route('distributors.edit', 'rsr');

        $this->withSession(['feed_admin_return_url' => $target])
            ->post(route('admin.unlock.attempt'), ['passphrase' => self::PASS])
            ->assertRedirect($target);

        $this->assertTrue(session('feed_admin_authenticated'));
    }

    public function test_lock_clears_the_session_flag(): void
    {
        $this->asAdmin()
            ->post(route('admin.lock'))
            ->assertRedirect(route('supply.index'));

        $this->assertNull(session('feed_admin_authenticated'));
    }

    /* ---------------------------------------------------------------- */
    /*  Edit form */
    /* ---------------------------------------------------------------- */

    public function test_admin_sees_transport_specific_fields_for_sftp(): void
    {
        $this->distributor(['slug' => 'rsr', 'transport_type' => 'sftp']);

        $this->asAdmin()->get(route('distributors.edit', 'rsr'))
            ->assertOk()
            ->assertSee('Credentials')
            ->assertSee('Host')
            ->assertSee('Remote Path')
            ->assertSee('Feed Settings')
            ->assertSee('Test Connection')
            ->assertSee('Run Sync Now')
            ->assertDontSee('API Key');
    }

    public function test_admin_sees_transport_specific_fields_for_rest_api(): void
    {
        $this->distributor(['slug' => 'pa', 'transport_type' => 'rest_api']);

        $this->asAdmin()->get(route('distributors.edit', 'pa'))
            ->assertOk()
            ->assertSee('Base URL')
            ->assertSee('API Key')
            ->assertDontSee('Remote Path');
    }

    /* ---------------------------------------------------------------- */
    /*  Update */
    /* ---------------------------------------------------------------- */

    public function test_update_saves_and_encrypts_connection_settings(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);

        $this->asAdmin()->put(route('distributors.update', 'rsr'), [
            'settings' => [
                'host' => 'sftp.rsr.example',
                'port' => '2222',
                'username' => 'acct1234',
                'password' => 'brand-new-secret',
                'remote_path' => 'out/inventory.txt',
            ],
            'is_active' => '1',
            'sync_frequency' => 'daily',
        ])
            ->assertRedirect(route('distributors.edit', 'rsr'))
            ->assertSessionHas('success');

        $distributor->refresh();

        $this->assertSame('sftp.rsr.example', $distributor->connection_settings['host']);
        $this->assertSame(2222, $distributor->connection_settings['port']);
        $this->assertSame('brand-new-secret', $distributor->connection_settings['password']);
        $this->assertSame('daily', $distributor->sync_frequency);
        $this->assertTrue($distributor->is_active);

        $raw = DB::table('distributors')->where('id', $distributor->id)->value('connection_settings');
        $this->assertStringNotContainsString('brand-new-secret', $raw, 'secrets must be stored encrypted');
        $this->assertStringNotContainsString('sftp.rsr.example', $raw);
    }

    public function test_update_keeps_stored_secret_when_the_field_is_left_blank(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);

        $this->asAdmin()->put(route('distributors.update', 'rsr'), [
            'settings' => [
                'host' => 'new.host.example',
                'port' => '22',
                'username' => 'acct',
                'password' => '',
                'remote_path' => 'inv.txt',
            ],
            'sync_frequency' => 'hourly',
        ])->assertRedirect(route('distributors.edit', 'rsr'));

        $distributor->refresh();

        $this->assertSame('new.host.example', $distributor->connection_settings['host']);
        $this->assertSame('old-pass', $distributor->connection_settings['password'], 'blank secret should be preserved');
        $this->assertFalse($distributor->is_active, 'absent checkbox deactivates the feed');
    }

    public function test_update_requires_a_sync_frequency(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $this->asAdmin()->from(route('distributors.edit', 'rsr'))
            ->put(route('distributors.update', 'rsr'), ['settings' => ['host' => 'x']])
            ->assertSessionHasErrors('sync_frequency');
    }

    /* ---------------------------------------------------------------- */
    /*  Test connection */
    /* ---------------------------------------------------------------- */

    public function test_test_connection_flashes_success(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $driver = Mockery::mock(FeedDriverInterface::class);
        $driver->shouldReceive('testConnection')->once()->andReturnTrue();
        $this->app->instance(RsrFeedDriver::class, $driver);

        $this->asAdmin()->from(route('distributors.edit', 'rsr'))
            ->post(route('distributors.test', 'rsr'))
            ->assertRedirect(route('distributors.edit', 'rsr'))
            ->assertSessionHas('success');
    }

    public function test_test_connection_flashes_warning_when_the_driver_returns_false(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $driver = Mockery::mock(FeedDriverInterface::class);
        $driver->shouldReceive('testConnection')->andReturnFalse();
        $this->app->instance(RsrFeedDriver::class, $driver);

        $this->asAdmin()->from(route('distributors.edit', 'rsr'))
            ->post(route('distributors.test', 'rsr'))
            ->assertSessionHas('warning');
    }

    public function test_test_connection_flashes_error_when_the_driver_throws(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $driver = Mockery::mock(FeedDriverInterface::class);
        $driver->shouldReceive('testConnection')->andThrow(new \RuntimeException('handshake failed'));
        $this->app->instance(RsrFeedDriver::class, $driver);

        $this->asAdmin()->from(route('distributors.edit', 'rsr'))
            ->post(route('distributors.test', 'rsr'))
            ->assertSessionHas('error');
    }

    /* ---------------------------------------------------------------- */
    /*  Manual sync */
    /* ---------------------------------------------------------------- */

    public function test_manual_sync_runs_ingestion_and_flashes_a_summary(): void
    {
        $distributor = $this->distributor(['slug' => 'rsr']);

        $run = new FeedRun([
            'status' => 'completed',
            'rows_processed' => 12,
            'rows_updated' => 5,
            'rows_failed' => 0,
        ]);

        $service = Mockery::mock(FeedIngestionService::class);
        $service->shouldReceive('ingest')->once()
            ->with(Mockery::on(fn ($arg) => $arg instanceof Distributor && $arg->is($distributor)))
            ->andReturn($run);
        $this->app->instance(FeedIngestionService::class, $service);

        $this->asAdmin()
            ->post(route('distributors.sync', 'rsr'))
            ->assertRedirect(route('supply.distributors'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '12 processed')
                && str_contains($message, '5 updated'));
    }

    public function test_manual_sync_flashes_error_when_the_run_fails(): void
    {
        $this->distributor(['slug' => 'rsr']);

        $run = new FeedRun([
            'status' => 'failed',
            'rows_processed' => 3,
            'rows_updated' => 0,
            'rows_failed' => 3,
            'error_message' => 'sftp unreachable',
        ]);

        $service = Mockery::mock(FeedIngestionService::class);
        $service->shouldReceive('ingest')->andReturn($run);
        $this->app->instance(FeedIngestionService::class, $service);

        $this->asAdmin()
            ->post(route('distributors.sync', 'rsr'))
            ->assertRedirect(route('supply.distributors'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'failed')
                && str_contains($message, 'sftp unreachable'));
    }

    /* ---------------------------------------------------------------- */
    /*  Distributor list */
    /* ---------------------------------------------------------------- */

    public function test_distributor_list_shows_admin_action_controls(): void
    {
        $this->distributor(['name' => 'RSR Group', 'slug' => 'rsr']);

        $this->get(route('supply.distributors'))
            ->assertOk()
            ->assertSee('Edit Credentials')
            ->assertSee('Test Connection')
            ->assertSee('Sync Now')
            ->assertSee(route('distributors.edit', 'rsr'), false);
    }
}
