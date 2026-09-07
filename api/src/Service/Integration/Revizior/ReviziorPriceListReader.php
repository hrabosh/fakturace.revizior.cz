<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Ceníkové kódy dodavatele pro nabídku ve fakturačních pravidlech ReviziORu.
 *
 * Bez tohohle výpisu opisuje technik kódy ručně z druhé aplikace a překlep
 * pozná až u prvního podkladu, kde se cena nedohledá.
 *
 * **Ceny se nevracejí schválně.** Závisí na klientovi, měně a datu; kdyby je
 * ReviziOR dostal v seznamu, ukázal by je uživateli mimo kontext a rozešly by
 * se s tím, co spočítá `prices/resolve`.
 *
 * Archivované položky se vynechávají: na nový doklad je použít nelze.
 */
final class ReviziorPriceListReader
{
    private const MAX_ITEMS = 500;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{items: list<array{code:string,name:string,unit:string,vatRate:string}>}
     */
    public function list(string $organizationUuid): array
    {
        $pdo = $this->db->pdo();

        $organization = $pdo->prepare(
            'SELECT supplier_id, status FROM revizior_organization_links WHERE organization_uuid = ?'
        );
        $organization->execute([strtolower(trim($organizationUuid))]);
        $link = $organization->fetch(PDO::FETCH_ASSOC);

        if (!is_array($link)) {
            throw ReviziorProvisioningException::notFound('organization_not_provisioned');
        }
        if ((string) $link['status'] === 'suspended') {
            throw ReviziorProvisioningException::conflict('organization_suspended');
        }

        $statement = $pdo->prepare(
            'SELECT pli.code, pli.name, pli.unit, vr.rate_percent
               FROM price_list_items pli
               JOIN vat_rates vr ON vr.id = pli.vat_rate_id
              WHERE pli.supplier_id = ? AND pli.archived = 0
              ORDER BY pli.name ASC, pli.code ASC
              LIMIT ' . self::MAX_ITEMS
        );
        $statement->execute([(int) $link['supplier_id']]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'vatRate' => number_format((float) $row['rate_percent'], 2, '.', ''),
            ];
        }

        return ['items' => $items];
    }
}
