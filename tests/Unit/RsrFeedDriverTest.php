<?php

namespace Tests\Unit;

use App\Services\Feeds\Drivers\RsrFeedDriver;
use App\Services\Feeds\DTOs\FeedItemDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

class RsrFeedDriverTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = dirname(__DIR__).'/Fixtures/rsr_sample_feed.txt';
    }

    /**
     * @return array<int, FeedItemDTO>
     */
    private function parse(): array
    {
        return iterator_to_array((new RsrFeedDriver)->parseFeed($this->fixture), false);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function callProtected(object $driver, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($driver, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($driver, $args);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $prop = (new ReflectionObject($object))->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }

    public function test_it_yields_only_ammunition_rows(): void
    {
        $items = $this->parse();

        $skus = array_map(static fn (FeedItemDTO $dto): string => $dto->distributor_sku, $items);

        $this->assertSame(
            ['RSR-AE223', 'RSR-WIN9', 'RSR-CCI22', 'RSR-SHORTUPC', 'RSR-FEDHST'],
            $skus,
        );
        $this->assertNotContains('RSR-GLOCK19', $skus, 'handgun (dept 1) must be filtered out');
        $this->assertNotContains('RSR-VORTEX', $skus, 'optic (dept 8) must be filtered out');

        // Every surviving row is Department 18 (Ammunition).
        foreach ($items as $dto) {
            $this->assertSame('18', $dto->raw_payload['department']);
        }
    }

    public function test_it_defaults_to_sftp_port_2222(): void
    {
        $driver = new RsrFeedDriver;

        $this->assertSame(2222, $this->callProtected($driver, 'defaultPort'));
        $this->assertSame(2222, RsrFeedDriver::DEFAULT_PORT);
        $this->assertSame('sftp', $this->callProtected($driver, 'defaultTransport'));
    }

    public function test_sftp_connection_provider_receives_port_2222_when_unspecified(): void
    {
        $driver = new RsrFeedDriver;

        // host/user only — no explicit port.
        $filesystem = $this->callProtected($driver, 'sftpFilesystem', [[
            'host' => 'ftps.rsrgroup.com',
            'username' => 'acct',
        ]]);

        $port = $this->readPrivate(
            $this->readPrivate(
                $this->readPrivate($filesystem, 'adapter'),
                'connectionProvider',
            ),
            'port',
        );

        $this->assertSame(2222, $port);
    }

    public function test_remote_path_resolves_to_the_primary_catalog_file(): void
    {
        $driver = new RsrFeedDriver;

        $this->assertSame('rsrinventory-new.txt', $this->callProtected($driver, 'defaultRemotePath'));
        $this->assertSame('rsrinventory-new.txt', RsrFeedDriver::CATALOG_FILE);

        // Unspecified, blank, and a bare "/" all fall back to the catalog file.
        $this->assertSame('rsrinventory-new.txt', $this->callProtected($driver, 'remotePath', [[]]));
        $this->assertSame('rsrinventory-new.txt', $this->callProtected($driver, 'remotePath', [['remote_path' => '/']]));
        $this->assertSame('rsrinventory-new.txt', $this->callProtected($driver, 'remotePath', [['remote_path' => '   ']]));
        $this->assertSame('rsrinventory-new.txt', $this->callProtected($driver, 'remotePath', [['path' => '']]));

        // An explicit path (e.g. the fast quantity delta file) is honoured, minus any leading slash.
        $this->assertSame('IM-QTY-CSV.csv', $this->callProtected($driver, 'remotePath', [['remote_path' => 'IM-QTY-CSV.csv']]));
        $this->assertSame('feeds/custom.txt', $this->callProtected($driver, 'remotePath', [['remote_path' => '/feeds/custom.txt']]));
        $this->assertSame('alt.txt', $this->callProtected($driver, 'remotePath', [['path' => 'alt.txt']]));
    }

    public function test_it_maps_delimited_columns_into_the_dto(): void
    {
        $ae = $this->parse()[0];

        $this->assertSame('RSR-AE223', $ae->distributor_sku);
        $this->assertSame('604544617375', $ae->raw_upc);
        $this->assertSame('AE223J', $ae->raw_mfr_part_number);
        $this->assertSame(7.5, $ae->wholesale_price);
        $this->assertSame(12.99, $ae->msrp_price);
        $this->assertSame(340, $ae->quantity_available);
        $this->assertTrue($ae->is_in_stock);
        $this->assertStringContainsString('American Eagle', $ae->raw_description);
        $this->assertSame('18', $ae->raw_payload['department']);
    }

    public function test_it_flags_zero_quantity_rows_as_out_of_stock(): void
    {
        $cci = $this->firstWhereSku('RSR-CCI22');

        $this->assertSame(0, $cci->quantity_available);
        $this->assertFalse($cci->is_in_stock);
    }

    public function test_it_strips_and_left_pads_short_upcs(): void
    {
        $short = $this->firstWhereSku('RSR-SHORTUPC');

        // source value is " 8-901234 " -> digits only -> padded to 12
        $this->assertSame('000008901234', $short->raw_upc);
    }

    public function test_it_reads_map_price_from_the_extended_column_only_when_present(): void
    {
        $this->assertSame(36.99, $this->firstWhereSku('RSR-FEDHST')->map_price);
        $this->assertNull($this->firstWhereSku('RSR-AE223')->map_price);
    }

    public function test_parse_feed_is_a_generator(): void
    {
        $this->assertInstanceOf(
            \Generator::class,
            (new RsrFeedDriver)->parseFeed($this->fixture),
        );
    }

    private function firstWhereSku(string $sku): FeedItemDTO
    {
        foreach ($this->parse() as $dto) {
            if ($dto->distributor_sku === $sku) {
                return $dto;
            }
        }

        $this->fail("No parsed item with SKU [{$sku}].");
    }
}
