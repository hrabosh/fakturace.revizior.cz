<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\ReviziorOnboardingService;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stav fakturačního onboardingu nad skutečnou DB.
 *
 * Regrese z produkce (2026-09-05): `onboarding_state` se zapsalo při
 * provisioningu jako `incomplete` a nikdy se nezměnilo. Uživatel doplnil
 * fakturační identitu, ale ReviziOR mu dál tvrdil, že nastavení chybí,
 * a nepustil ho vystavit doklad.
 */
#[Group('integration')]
final class OnboardingStateTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000009';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000049';
    private const OWNER_EMAIL = 'revizior-onboarding-owner@example.invalid';

    private Connection $db;
    private ReviziorOnboardingService $onboarding;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->onboarding = $container->get(ReviziorOnboardingService::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW COLUMNS FROM revizior_organization_links LIKE 'event_sequence'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0159_revizior_organization_event_sequence.sql chybí.');
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testCompleteIdentityFlipsStateAndPublishesEvent(): void
    {
        self::assertSame('incomplete', $this->link()['onboarding_state'], 'provisioning začíná nedokončeným stavem');

        $this->onboarding->refreshForSupplier($this->supplierId);

        $link = $this->link();
        self::assertSame('completed', $link['onboarding_state']);
        self::assertSame('active', $link['status']);
        self::assertSame(1, (int) $link['event_sequence']);

        $events = $this->organizationEvents();
        self::assertCount(1, $events);
        self::assertSame('organization.onboarding_changed', $events[0]['event_type']);

        $payload = json_decode((string) $events[0]['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('organization', $payload['aggregate']['type']);
        self::assertSame(self::ORGANIZATION_UUID, $payload['aggregate']['externalKey']);
        self::assertSame(1, $payload['aggregate']['sequence']);
        self::assertSame('completed', $payload['data']['onboardingState']);
    }

    /** Beze změny stavu nevzniká další událost — jinak by outbox rostl při každém uložení. */
    public function testUnchangedStateDoesNotPublishAgain(): void
    {
        $this->onboarding->refreshForSupplier($this->supplierId);
        $this->onboarding->refreshForSupplier($this->supplierId);

        self::assertCount(1, $this->organizationEvents());
    }

    /** Plátce DPH bez DIČ nemá čím vystavit daňový doklad. */
    public function testVatPayerWithoutVatNumberStaysIncomplete(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1, dic = NULL WHERE id = ?')
            ->execute([$this->supplierId]);

        $this->onboarding->refreshForSupplier($this->supplierId);

        self::assertSame('incomplete', $this->link()['onboarding_state']);
        self::assertCount(0, $this->organizationEvents());
    }

    /** Prázdná adresa je stejný případ jako chybějící DIČ. */
    public function testMissingAddressStaysIncomplete(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET street = \'\' WHERE id = ?')->execute([$this->supplierId]);

        $this->onboarding->refreshForSupplier($this->supplierId);

        self::assertSame('incomplete', $this->link()['onboarding_state']);
    }

    /** Dodavatel bez vazby na ReviziOR (standalone firma) je no-op. */
    public function testSupplierWithoutLinkIsANoOp(): void
    {
        $this->onboarding->refreshForSupplier(999999);

        self::assertCount(0, $this->organizationEvents());
    }

    /** @return array<string,mixed> */
    private function link(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status, onboarding_state, event_sequence FROM revizior_organization_links WHERE organization_uuid = ?'
        );
        $statement->execute([self::ORGANIZATION_UUID]);

        /** @var array<string,mixed> $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function organizationEvents(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT o.event_type, o.aggregate_sequence, o.payload_json
               FROM revizior_event_outbox o
               JOIN revizior_organization_links rol ON rol.id = o.organization_link_id
              WHERE rol.organization_uuid = ? AND o.aggregate_type = \'organization\'
              ORDER BY o.aggregate_sequence'
        );
        $statement->execute([self::ORGANIZATION_UUID]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
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
