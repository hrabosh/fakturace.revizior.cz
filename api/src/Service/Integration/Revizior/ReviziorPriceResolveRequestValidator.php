<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

/**
 * Validace payloadu `POST /organizations/{uuid}/prices/resolve` (kontrakt v1).
 *
 * Množství i ceny jdou po drátě jako **řetězce**, ne čísla: JSON zná jen
 * double a `0.1 + 0.2` na fakturách nikdo vidět nechce. Sem se proto pouští
 * jen desetinný zápis s tečkou, který se nikde nepřevádí na float.
 */
final class ReviziorPriceResolveRequestValidator
{
    private const ALLOWED = ['specVersion', 'clientUuid', 'currency', 'rateDate', 'pricesIncludeVat', 'items'];
    private const ITEM_ALLOWED = ['lineKey', 'code', 'quantity'];
    private const MAX_ITEMS = 500;

    /**
     * @param array<string,mixed> $body
     *
     * @return array{
     *   organizationUuid:string, clientUuid:string, currency:string, rateDate:string,
     *   pricesIncludeVat:bool, items:list<array{lineKey:string,code:string,quantity:string}>
     * }
     */
    public function validate(string $organizationUuid, array $body): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        $this->rejectUnknown($body, self::ALLOWED, '', $fields);

        if (($body['specVersion'] ?? null) !== ReviziorContract::VERSION) {
            $fields['specVersion'] = 'must_equal_1.0';
        }

        $clientUuid = $this->uuid((string) ($body['clientUuid'] ?? ''), 'clientUuid', $fields);

        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            $fields['currency'] = 'must_be_iso_4217';
        }

        $rateDate = trim((string) ($body['rateDate'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rateDate) !== 1) {
            $fields['rateDate'] = 'must_be_date';
        }

        if (!is_bool($body['pricesIncludeVat'] ?? null)) {
            $fields['pricesIncludeVat'] = 'required_bool';
        }

        $items = [];
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || $raw === [] || array_is_list($raw) === false) {
            $fields['items'] = 'required_non_empty_list';
        } elseif (count($raw) > self::MAX_ITEMS) {
            $fields['items'] = 'too_many';
        } else {
            $seen = [];
            foreach ($raw as $index => $item) {
                $prefix = "items.{$index}.";
                if (!is_array($item)) {
                    $fields[rtrim($prefix, '.')] = 'required_object';

                    continue;
                }
                $this->rejectUnknown($item, self::ITEM_ALLOWED, $prefix, $fields);

                $lineKey = $this->uuid((string) ($item['lineKey'] ?? ''), $prefix . 'lineKey', $fields);
                // Duplicitní klíč by znamenal dvě ceny pro týž řádek podkladu
                // a ReviziOR by nepoznal, která platí.
                if ($lineKey !== '' && isset($seen[$lineKey])) {
                    $fields[$prefix . 'lineKey'] = 'duplicate';
                }
                $seen[$lineKey] = true;

                $code = trim((string) ($item['code'] ?? ''));
                if ($code === '' || mb_strlen($code) > 50) {
                    $fields[$prefix . 'code'] = 'required_string';
                }

                $quantity = trim((string) ($item['quantity'] ?? ''));
                if (preg_match('/^\d{1,9}(\.\d{1,3})?$/D', $quantity) !== 1) {
                    $fields[$prefix . 'quantity'] = 'must_be_decimal_string';
                }

                $items[] = ['lineKey' => $lineKey, 'code' => $code, 'quantity' => $quantity];
            }
        }

        if ($fields !== []) {
            throw ReviziorProvisioningException::validation($fields);
        }

        return [
            'organizationUuid' => $organizationUuid,
            'clientUuid' => $clientUuid,
            'currency' => $currency,
            'rateDate' => $rateDate,
            'pricesIncludeVat' => (bool) $body['pricesIncludeVat'],
            'items' => $items,
        ];
    }

    /** @param array<string,string> $fields */
    private function uuid(string $value, string $field, array &$fields): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            $fields[$field] = 'must_be_uuid';

            return '';
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string>        $allowed
     * @param array<string,string> $fields
     */
    private function rejectUnknown(array $body, array $allowed, string $prefix, array &$fields): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $fields[$prefix . (string) $key] = 'unknown_field';
            }
        }
    }
}
