<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Stav fakturačního onboardingu tenanta a jeho cesta zpátky do ReviziORu.
 *
 * ## Proč to vzniklo
 *
 * `onboarding_state` se zapsalo při provisioningu jako `incomplete` a **nikdy
 * se nezměnilo**. Uživatel tak doplnil fakturační identitu, ale ReviziOR dál
 * hlásil „nastavení není dokončené" a nepustil ho vystavit doklad. Stav se
 * proto přepočítává po každé změně nastavení dodavatele.
 *
 * ## Co je „dokončeno"
 *
 * Údaje, bez kterých nejde vystavit daňový doklad: název, adresa a — u plátce
 * DPH — DIČ. Číselná řada mezi ně nepatří: `invoice_number_format` je NULL-able
 * a NULL znamená „použij formát instalace", ne „chybí". Neplátce DPH je platná
 * volba, ne nedokončený stav.
 *
 * ## Změna se posílá událostí
 *
 * ReviziOR se stav nedozví jinak: `PUT /organizations/{uuid}` je zápis, který
 * by identitu dodavatele přepsal daty z ReviziORu, takže se na čtení použít
 * nedá. Událost jde stejným outboxem jako doklady — podepsaná, sekvenovaná
 * a s opakováním při výpadku.
 */
final class ReviziorOnboardingService
{
    public const STATE_COMPLETED = 'completed';
    public const STATE_INCOMPLETE = 'incomplete';

    private const EVENT_TYPE = 'organization.onboarding_changed';
    private const SPEC_VERSION = '1.0';

    public function __construct(
        private readonly Connection $db,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Přepočítá stav po změně nastavení dodavatele.
     *
     * Dodavatel bez vazby na ReviziOR (standalone instalace, ručně založená
     * firma) je no-op, takže se smí volat odkudkoli bez podmínek u volajícího.
     */
    public function refreshForSupplier(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $link = $this->lockLink($pdo, $supplierId);
            if ($link === null) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return;
            }

            $state = $this->evaluate($pdo, $supplierId);
            if ($state === (string) $link['onboarding_state']) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return;
            }

            // Suspendovaná organizace zůstává suspendovaná: dokončený onboarding
            // není důvod ji vzkřísit, o tom rozhoduje ReviziOR.
            $status = (string) $link['status'] === 'suspended'
                ? 'suspended'
                : ($state === self::STATE_COMPLETED ? 'active' : 'onboarding');

            $sequence = (int) $link['event_sequence'] + 1;
            $pdo->prepare(
                'UPDATE revizior_organization_links
                    SET onboarding_state = ?, status = ?, event_sequence = ?, updated_at = UTC_TIMESTAMP(6)
                  WHERE id = ?'
            )->execute([$state, $status, $sequence, (int) $link['id']]);

            $this->publish($pdo, $link, $state, $status, $sequence);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$ownTransaction) {
                throw $e;
            }
            // Uložené nastavení je důležitější než propsání stavu. Další uložení
            // stav dopočítá znovu, takže se nic neztratí natrvalo.
            $this->logger->error('ReviziOR onboarding refresh failed', [
                'supplier_id' => $supplierId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function evaluate(PDO $pdo, int $supplierId): string
    {
        $statement = $pdo->prepare(
            'SELECT company_name, street, city, zip, dic, is_vat_payer
               FROM supplier WHERE id = ?'
        );
        $statement->execute([$supplierId]);
        $supplier = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($supplier)) {
            return self::STATE_INCOMPLETE;
        }

        foreach (['company_name', 'street', 'city', 'zip'] as $field) {
            if (trim((string) ($supplier[$field] ?? '')) === '') {
                return self::STATE_INCOMPLETE;
            }
        }

        if ((bool) $supplier['is_vat_payer'] && trim((string) ($supplier['dic'] ?? '')) === '') {
            return self::STATE_INCOMPLETE;
        }

        return self::STATE_COMPLETED;
    }

    /** @param array<string,mixed> $link */
    private function publish(PDO $pdo, array $link, string $state, string $status, int $sequence): void
    {
        $payload = [
            'specVersion' => self::SPEC_VERSION,
            'eventId' => Uuid::v4()->toRfc4122(),
            'eventType' => self::EVENT_TYPE,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'organizationId' => (string) $link['organization_uuid'],
            'supplierId' => (string) $link['supplier_id'],
            'aggregate' => [
                'type' => 'organization',
                'id' => (string) $link['supplier_id'],
                'externalKey' => (string) $link['organization_uuid'],
                'sequence' => $sequence,
            ],
            'data' => [
                'status' => $status,
                'onboardingState' => $state,
            ],
        ];

        $pdo->prepare(
            'INSERT INTO revizior_event_outbox
                (id, organization_link_id, invoice_link_id, aggregate_type, aggregate_id, aggregate_sequence,
                 event_type, spec_version, payload_json, payload_hash, state, delivery_attempts,
                 next_attempt_at, created_at)
             VALUES (?, ?, NULL, \'organization\', ?, ?, ?, ?, ?, ?, \'pending\', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        )->execute([
            (string) $payload['eventId'],
            (int) $link['id'],
            (string) $link['supplier_id'],
            $sequence,
            self::EVENT_TYPE,
            self::SPEC_VERSION,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->hasher->hash($payload),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function lockLink(PDO $pdo, int $supplierId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, organization_uuid, supplier_id, status, onboarding_state, event_sequence
               FROM revizior_organization_links
              WHERE supplier_id = ?
                FOR UPDATE'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
