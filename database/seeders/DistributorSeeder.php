<?php

namespace Database\Seeders;

use App\Models\Distributor;
use Illuminate\Database\Seeder;

class DistributorSeeder extends Seeder
{
    /**
     * Seed the eight supported ammunition wholesalers.
     *
     * Rows are keyed by slug and upserted, so this seeder is safe to
     * re-run. Connection credentials are intentionally left blank here
     * and are expected to be filled in per environment.
     */
    public function run(): void
    {
        foreach ($this->distributors() as $distributor) {
            Distributor::updateOrCreate(
                ['slug' => $distributor['slug']],
                $distributor + ['connection_settings' => $this->connectionScaffold($distributor['transport_type'])],
            );
        }
    }

    /**
     * @return array<int, array{name: string, slug: string, transport_type: string, driver_class: string}>
     */
    private function distributors(): array
    {
        return [
            [
                'name' => 'RSR Group',
                'slug' => 'rsr',
                'transport_type' => 'sftp',
                'driver_class' => 'App\Services\Feeds\Drivers\RsrFeedDriver',
            ],
            [
                'name' => 'Chattanooga Shooting Supplies',
                'slug' => 'chattanooga',
                'transport_type' => 'ftp',
                'driver_class' => 'App\Services\Feeds\Drivers\ChattanoogaFeedDriver',
            ],
            [
                'name' => 'Zanders Sporting Goods',
                'slug' => 'zanders',
                'transport_type' => 'http_csv',
                'driver_class' => 'App\Services\Feeds\Drivers\ZandersFeedDriver',
            ],
            [
                'name' => "Lipsey's",
                'slug' => 'lipseys',
                'transport_type' => 'sftp',
                'driver_class' => 'App\Services\Feeds\Drivers\LipseysFeedDriver',
            ],
            [
                'name' => 'Crow Shooting Supply',
                'slug' => 'crow',
                'transport_type' => 'ftp',
                'driver_class' => 'App\Services\Feeds\Drivers\CrowFeedDriver',
            ],
            [
                'name' => '2nd Amendment Wholesale',
                'slug' => 'second_amendment',
                'transport_type' => 'rest_api',
                'driver_class' => 'App\Services\Feeds\Drivers\SecondAmendmentFeedDriver',
            ],
            [
                'name' => "Davidson's",
                'slug' => 'davidsons',
                'transport_type' => 'ftp',
                'driver_class' => 'App\Services\Feeds\Drivers\DavidsonsFeedDriver',
            ],
            [
                'name' => 'Primary Arms Wholesale',
                'slug' => 'primary_arms',
                'transport_type' => 'rest_api',
                'driver_class' => 'App\Services\Feeds\Drivers\PrimaryArmsFeedDriver',
            ],
        ];
    }

    /**
     * Empty credential scaffold shaped for the given transport type.
     *
     * @return array<string, mixed>
     */
    private function connectionScaffold(string $transportType): array
    {
        return match ($transportType) {
            'sftp' => [
                'host' => null,
                'port' => 22,
                'username' => null,
                'password' => null,
                'private_key' => null,
                'remote_path' => null,
            ],
            'ftp' => [
                'host' => null,
                'port' => 21,
                'username' => null,
                'password' => null,
                'passive' => true,
                'remote_path' => null,
            ],
            'rest_api' => [
                'base_uri' => null,
                'api_key' => null,
                'api_secret' => null,
            ],
            'http_csv' => [
                'url' => null,
                'username' => null,
                'password' => null,
            ],
            default => [],
        };
    }
}
