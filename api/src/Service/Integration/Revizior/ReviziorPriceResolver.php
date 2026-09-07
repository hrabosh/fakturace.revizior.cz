<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PriceListItemRepository;
use MyInvoice\Service\Invoice\PriceListItemResolver;
use MyInvoice\Service\Invoice\PriceListResolutionException;
use PDO;

/**
 * Ceny z ceníku dodavatele pro podklad faktury v ReviziORu (R3, §2.13).
 *
 * ## Proč to existuje
 *
 * ReviziOR ceny nezná a znát nemá — vlastní je fakturační služba včetně
 * zákaznických výjimek, sazeb DPH a platnosti v čase. Bez tohohle endpointu
 * musí technik u každého řádku podkladu cenu vyplnit ručně, i když ji má
 * v ceníku uloženou.
 *
 * ## Chyba je per položka, ne per požadavek
 *
 * Jedna nedohledatelná položka nesmí shodit celý podklad: ostatní řádky se
 * ocení a ta problémová se vrátí s `error` a bez ceny. ReviziOR ji pak nechá
 * na ručním vyplnění (pravidlo 13g) — prázdno je legitimní výsledek, odhad ne.
 *
 * ## Výpočet nedubluje
 *
 * Cenu i převod měny řeší `PriceListItemResolver`, tentýž kód jako ruční
 * vystavení dokladu v UI. Kdyby se počítala zvlášť, rozejdou se při první
 * změně kurzové logiky.
 */
final class ReviziorPriceResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PriceListItemRepository $items,
        private readonly PriceListItemResolver $resolver,
        private readonly ReviziorPriceResolveRequestValidator $validator,
    ) {}

    /**
     * @param array<string,mixed> $body
     *
     * @return array{items: list<array<string,mixed>>}
     */
    public function resolve(string $organizationUuid, array $body): array
    {
        $input = $this->validator->validate($organizationUuid, $body);

        $pdo = $this->db->pdo();
        $organization = $this->organization($pdo, $input['organizationUuid']);
        if ($organization === null) {
            throw ReviziorProvisioningException::notFound('organization_not_provisioned');
        }
        if ((string) $organization['status'] === 'suspended') {
            throw ReviziorProvisioningException::conflict('organization_suspended');
        }

        $supplierId = (int) $organization['supplier_id'];
        $clientId = $this->clientId($pdo, (int) $organization['id'], $input['clientUuid']);
        if ($clientId === null) {
            throw ReviziorProvisioningException::notFound('client_not_linked');
        }

        $currency = $this->items->activeCurrencyByCode($supplierId, $input['currency']);
        if ($currency === null) {
            throw ReviziorProvisioningException::validation(['currency' => 'Měna není u dodavatele aktivní.']);
        }

        $codes = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['code'],
            $input['items'],
        )));
        $itemIdsByCode = $this->itemIdsByCode($pdo, $supplierId, $codes);
        $referenceDate = new DateTimeImmutable($input['rateDate']);

        $resolved = [];
        foreach ($input['items'] as $item) {
            $resolved[] = $this->resolveItem(
                $item,
                $itemIdsByCode,
                $supplierId,
                $clientId,
                (int) $currency['id'],
                $input['pricesIncludeVat'],
                $referenceDate,
                (string) $currency['code'],
            );
        }

        return ['items' => $resolved];
    }

    /**
     * @param array<string,mixed>  $item
     * @param array<string,int>    $itemIdsByCode
     *
     * @return array<string,mixed>
     */
    private function resolveItem(
        array $item,
        array $itemIdsByCode,
        int $supplierId,
        int $clientId,
        int $currencyId,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
        string $currencyCode,
    ): array {
        $code = (string) $item['code'];
        $lineKey = (string) $item['lineKey'];
        $quantity = (string) $item['quantity'];

        $itemId = $itemIdsByCode[$code] ?? null;
        if ($itemId === null) {
            return $this->failedItem($lineKey, $code, $quantity, $currencyCode, $pricesIncludeVat, 'price_list_item_not_found');
        }

        try {
            $row = $this->resolver->resolveMany(
                [$itemId],
                $supplierId,
                $clientId,
                $currencyId,
                $pricesIncludeVat,
                $referenceDate,
            )[$itemId];
        } catch (PriceListResolutionException $e) {
            return $this->failedItem($lineKey, $code, $quantity, $currencyCode, $pricesIncludeVat, $e->errorCode);
        }

        return [
            'lineKey' => $lineKey,
            'code' => $code,
            'priceListItemId' => (string) $row['price_list_item_id'],
            'description' => (string) ($row['description'] !== '' ? $row['description'] : $row['name']),
            'unit' => (string) $row['unit'],
            'quantity' => $quantity,
            'unitPrice' => number_format((float) $row['unit_price_without_vat'], 2, '.', ''),
            'currency' => $currencyCode,
            'pricesIncludeVat' => (bool) $row['prices_include_vat'],
            'vatRate' => number_format((float) $row['vat_rate_percent'], 2, '.', ''),
            'source' => $this->source((string) $row['catalog_price_source']),
            'effectiveAt' => $referenceDate->format('Y-m-d'),
            'error' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function failedItem(
        string $lineKey,
        string $code,
        string $quantity,
        string $currencyCode,
        bool $pricesIncludeVat,
        string $errorCode,
    ): array {
        return [
            'lineKey' => $lineKey,
            'code' => $code,
            'priceListItemId' => null,
            'description' => '',
            'unit' => '',
            'quantity' => $quantity,
            'unitPrice' => null,
            'currency' => $currencyCode,
            'pricesIncludeVat' => $pricesIncludeVat,
            'vatRate' => null,
            'source' => null,
            'effectiveAt' => null,
            'error' => $errorCode,
        ];
    }

    /**
     * Zdroj ceny v názvosloví kontraktu.
     *
     * `customer_explicit` a `catalog_explicit` jsou interní názvy; kontrakt zná
     * `customer_override`, `catalog` a `catalog_converted`.
     */
    private function source(string $internal): string
    {
        return match ($internal) {
            'customer_explicit' => 'customer_override',
            'catalog_explicit' => 'catalog',
            default => 'catalog_converted',
        };
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string,int>
     */
    private function itemIdsByCode(PDO $pdo, int $supplierId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, code FROM price_list_items WHERE supplier_id = ? AND code IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$supplierId], $codes));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }

        return $map;
    }

    /** @return array<string,mixed>|null */
    private function organization(PDO $pdo, string $organizationUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status FROM revizior_organization_links WHERE organization_uuid = ?'
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function clientId(PDO $pdo, int $organizationLinkId, string $clientUuid): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT client_id FROM revizior_client_links WHERE organization_link_id = ? AND client_uuid = ?'
        );
        $stmt->execute([$organizationLinkId, $clientUuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
