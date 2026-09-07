<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PriceListItemRepository;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorPriceListReader;
use MyInvoice\Service\Integration\Revizior\ReviziorPriceResolver;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ceny z ceníku dodavatele pro podklad faktury v ReviziORu.
 *
 * Bez tohohle endpointu vyplňuje technik cenu u každého řádku ručně, i když ji
 * má v ceníku uloženou.
 */
#[Group('integration')]
final class PriceResolutionTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000011';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000051';
    private const OWNER_EMAIL = 'revizior-price-owner@example.invalid';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000011';
    private const LINE_KEY = '50000000-0000-4000-8000-000000000011';
    private const CODE = 'REV_ELEKTRO_ZAKLAD';

    private Connection $db;
    private ReviziorPriceResolver $resolver;
    private ReviziorPriceListReader $catalogue;
    private PriceListItemRepository $priceList;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->resolver = $container->get(ReviziorPriceResolver::class);
            $this->catalogue = $container->get(ReviziorPriceListReader::class);
            $this->priceList = $container->get(PriceListItemRepository::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $clients = $container->get(ReviziorClientSynchronizer::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
        $clients->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture('client-upsert-request'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testCatalogPriceIsResolvedForKnownCode(): void
    {
        $this->createPriceListItem(self::CODE, '4500.00');

        $result = $this->resolver->resolve(self::ORGANIZATION_UUID, $this->request());

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame(self::LINE_KEY, $item['lineKey']);
        self::assertSame(self::CODE, $item['code']);
        self::assertSame('4500.00', $item['unitPrice']);
        self::assertSame('CZK', $item['currency']);
        self::assertSame('21.00', $item['vatRate']);
        self::assertSame('catalog', $item['source']);
        self::assertNull($item['error']);
        self::assertNotNull($item['priceListItemId']);
    }

    /**
     * Nedohledatelná položka nesmí shodit celý podklad: ostatní řádky se ocení
     * a ta problémová se vrátí bez ceny s kódem chyby.
     */
    public function testUnknownCodeFailsOnlyItsOwnLine(): void
    {
        $this->createPriceListItem(self::CODE, '4500.00');

        $body = $this->request();
        $body['items'][] = [
            'lineKey' => '50000000-0000-4000-8000-000000000012',
            'code' => 'NEEXISTUJICI_KOD',
            'quantity' => '1.000',
        ];

        $result = $this->resolver->resolve(self::ORGANIZATION_UUID, $body);

        self::assertCount(2, $result['items']);
        self::assertNull($result['items'][0]['error']);
        self::assertSame('4500.00', $result['items'][0]['unitPrice']);
        self::assertSame('price_list_item_not_found', $result['items'][1]['error']);
        self::assertNull($result['items'][1]['unitPrice']);
    }

    /**
     * Výpis pro nabídku ve fakturačních pravidlech: kódy a názvy, **bez cen**.
     * Cena závisí na klientovi, měně a datu, takže mimo ten kontext nedává smysl.
     */
    public function testCatalogueListsActiveCodesWithoutPrices(): void
    {
        $this->createPriceListItem(self::CODE, '4500.00');

        $result = $this->catalogue->list(self::ORGANIZATION_UUID);

        self::assertCount(1, $result['items']);
        self::assertSame(self::CODE, $result['items'][0]['code']);
        self::assertSame('ks', $result['items'][0]['unit']);
        self::assertSame('21.00', $result['items'][0]['vatRate']);
        self::assertArrayNotHasKey('unitPrice', $result['items'][0]);
    }

    /** Klient bez vazby není chyba jedné položky, ale celého požadavku. */
    public function testUnlinkedClientIsRejected(): void
    {
        $body = $this->request();
        $body['clientUuid'] = '40000000-0000-4000-8000-000000000099';

        $this->expectException(ReviziorProvisioningException::class);
        $this->resolver->resolve(self::ORGANIZATION_UUID, $body);
    }

    /** Duplicitní `lineKey` by znamenal dvě ceny pro týž řádek podkladu. */
    public function testDuplicateLineKeyIsRejected(): void
    {
        $body = $this->request();
        $body['items'][] = $body['items'][0];

        $this->expectException(ReviziorProvisioningException::class);
        $this->resolver->resolve(self::ORGANIZATION_UUID, $body);
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return [
            'specVersion' => '1.0',
            'clientUuid' => self::CLIENT_UUID,
            'currency' => 'CZK',
            'rateDate' => '2026-09-05',
            'pricesIncludeVat' => false,
            'items' => [
                ['lineKey' => self::LINE_KEY, 'code' => self::CODE, 'quantity' => '1.000'],
            ],
        ];
    }

    private function createPriceListItem(string $code, string $unitPrice): void
    {
        $vatRateId = (int) $this->db->pdo()
            ->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21.00 AND is_reverse_charge = 0 ORDER BY id LIMIT 1")
            ->fetchColumn();

        $this->priceList->create($this->supplierId, [
            'code' => $code,
            'name' => 'Pravidelná revize elektroinstalace',
            'description' => 'Pravidelná revize elektroinstalace',
            'unit' => 'ks',
            'vat_rate_id' => $vatRateId,
            'prices_include_vat' => false,
            'base_currency_code' => 'CZK',
            'allow_exchange_rate_conversion' => false,
            'archived' => false,
        ], [
            ['currency_code' => 'CZK', 'unit_price' => $unitPrice, 'archived' => false],
        ]);
    }

    /** @return array<string,mixed> */
    private function provisionBody(): array
    {
        $body = $this->fixture('provision-request');
        $body['owner']['userUuid'] = self::OWNER_UUID;
        $body['owner']['email'] = self::OWNER_EMAIL;

        return $body;
    }

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . "/source/revizior-integration/contract/v1/{$name}.json"),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare('SELECT id, supplier_id FROM revizior_organization_links WHERE organization_uuid = ?');
        $statement->execute([self::ORGANIZATION_UUID]);
        $org = $statement->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM revizior_idempotency_keys WHERE subject_uuid = ?')->execute([self::ORGANIZATION_UUID]);
        if (!is_array($org)) {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);

            return;
        }
        $orgLinkId = (int) $org['id'];
        $supplierId = (int) $org['supplier_id'];
        $users = $pdo->prepare('SELECT user_id FROM revizior_user_links WHERE organization_link_id = ?');
        $users->execute([$orgLinkId]);
        $userIds = array_map('intval', $users->fetchAll(\PDO::FETCH_COLUMN));

        $pdo->prepare('DELETE FROM revizior_event_outbox WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE p FROM price_list_item_prices p JOIN price_list_items i ON i.id = p.price_list_item_id WHERE i.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM price_list_items WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_client_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE cec FROM client_email_contacts cec JOIN clients c ON c.id = cec.client_id WHERE c.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_user_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_organization_links WHERE id = ?')->execute([$orgLinkId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);
    }
}
