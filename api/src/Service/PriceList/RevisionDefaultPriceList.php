<?php

declare(strict_types=1);

namespace MyInvoice\Service\PriceList;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PriceListItemRepository;
use PDO;

/**
 * Startovací ceník pro revizního technika.
 *
 * ## Proč to existuje
 *
 * Prázdný ceník znamená, že technik vyplňuje cenu u každého řádku podkladu
 * ručně — a než si ceník založí, musí vymyslet i kódy. Sada pokrývá běžné
 * úkony (elektro, hromosvod, plyn, tlak, výtah, PO, školení, práce, doprava),
 * takže stačí upravit ceny.
 *
 * ## Ceny jsou návrh, ne doporučení
 *
 * Hodnoty jsou orientační výchozí body k přepsání, ne ceník trhu. Nulu tu
 * schválně nemáme: vystavený doklad s nulovou cenou je horší než doklad,
 * který se nevystaví, protože cena chybí.
 *
 * ## Opakované spuštění nic nepřepíše
 *
 * Kód je unikátní na dodavatele; položka, která už existuje, se přeskočí.
 * Technik tak může sadu nahrát znovu po ručním úklidu, aniž by přišel
 * o vlastní ceny.
 */
final class RevisionDefaultPriceList
{
    /**
     * @var list<array{code:string,name:string,unit:string,price:string}>
     */
    private const ITEMS = [
        ['code' => 'REV_ELEKTRO_PRAVIDELNA', 'name' => 'Pravidelná revize elektrické instalace', 'unit' => 'ks', 'price' => '4500.00'],
        ['code' => 'REV_ELEKTRO_VYCHOZI', 'name' => 'Výchozí revize elektrické instalace', 'unit' => 'ks', 'price' => '6500.00'],
        ['code' => 'REV_HROMOSVOD', 'name' => 'Revize hromosvodu a uzemnění', 'unit' => 'ks', 'price' => '2500.00'],
        ['code' => 'REV_SPOTREBIC', 'name' => 'Revize elektrického spotřebiče', 'unit' => 'ks', 'price' => '120.00'],
        ['code' => 'REV_NARADI', 'name' => 'Revize elektrického ručního nářadí', 'unit' => 'ks', 'price' => '90.00'],
        ['code' => 'REV_PLYN', 'name' => 'Revize plynového zařízení', 'unit' => 'ks', 'price' => '3500.00'],
        ['code' => 'REV_TLAK', 'name' => 'Revize tlakové nádoby', 'unit' => 'ks', 'price' => '2800.00'],
        ['code' => 'REV_VYTAH', 'name' => 'Odborná prohlídka výtahu', 'unit' => 'ks', 'price' => '3200.00'],
        ['code' => 'REV_HASICI', 'name' => 'Kontrola hasicího přístroje', 'unit' => 'ks', 'price' => '180.00'],
        ['code' => 'REV_HYDRANT', 'name' => 'Kontrola požárního hydrantu', 'unit' => 'ks', 'price' => '350.00'],
        ['code' => 'SKOLENI_BOZP', 'name' => 'Školení BOZP', 'unit' => 'osoba', 'price' => '450.00'],
        ['code' => 'PRACE_TECHNIK', 'name' => 'Práce revizního technika', 'unit' => 'h', 'price' => '650.00'],
        ['code' => 'DOPRAVA', 'name' => 'Doprava', 'unit' => 'km', 'price' => '14.00'],
        ['code' => 'ZPRAVA_KOPIE', 'name' => 'Vyhotovení kopie revizní zprávy', 'unit' => 'ks', 'price' => '250.00'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PriceListItemRepository $items,
    ) {}

    /**
     * @return array{created:list<string>, skipped:list<string>}
     */
    public function seed(int $supplierId): array
    {
        $currency = $this->items->activeCurrencyByCode($supplierId, 'CZK');
        if ($currency === null) {
            throw new \DomainException('Dodavatel nemá aktivní měnu CZK, do které by se ceník založil.');
        }

        $vatRateId = $this->defaultVatRateId();
        $existing = $this->existingCodes($supplierId);

        $created = [];
        $skipped = [];
        foreach (self::ITEMS as $item) {
            if (isset($existing[$item['code']])) {
                $skipped[] = $item['code'];

                continue;
            }

            $this->items->create($supplierId, [
                'code' => $item['code'],
                'name' => $item['name'],
                'description' => $item['name'],
                'unit' => $item['unit'],
                'vat_rate_id' => $vatRateId,
                'prices_include_vat' => false,
                'base_currency_code' => 'CZK',
                'allow_exchange_rate_conversion' => false,
                'archived' => false,
            ], [
                ['currency_code' => 'CZK', 'unit_price' => $item['price'], 'archived' => false],
            ]);
            $created[] = $item['code'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function defaultVatRateId(): int
    {
        $id = $this->db->pdo()->query(
            "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND is_reverse_charge = 0
                AND valid_from <= UTC_DATE() AND (valid_to IS NULL OR valid_to >= UTC_DATE())
              ORDER BY is_default DESC, rate_percent DESC, id ASC
              LIMIT 1"
        )?->fetchColumn();

        if ($id === false || $id === null) {
            throw new \DomainException('V číselníku chybí platná sazba DPH.');
        }

        return (int) $id;
    }

    /** @return array<string,true> */
    private function existingCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM price_list_items WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);

        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $codes[(string) $code] = true;
        }

        return $codes;
    }
}
