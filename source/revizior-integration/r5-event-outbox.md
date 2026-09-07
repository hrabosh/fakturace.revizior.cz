# R5 — transakční event outbox

> Stav: hotovo — `eventOutbox=true` po zeleném cross-repo smoke testu
> Datum: 2026-09-02

## Kde událost vzniká

`ReviziorInvoiceEventPublisher` je jediné místo, kde se událost zapisuje.
Zapisuje **v transakci volajícího** (když žádná neběží, otevře si vlastní), takže
business změna a událost platí obě, nebo žádná. Doklad bez ReviziOR vazby je
no-op, takže se publisher smí volat odkudkoli bez podmínek u volajícího.

Napojené kanály:

| Událost | Místo |
|---|---|
| `invoice.draft_created` | `ReviziorInvoiceDraftService` (integrace) |
| `invoice.issued`, `invoice.paid` (auto z zálohy) | `IssueInvoiceAction` |
| `invoice.sent` | `SendEmailAction` |
| `invoice.payment_recorded` + `invoice.paid` / `invoice.partially_paid` | `InvoicePaymentService` (ruční platba, import, bankovní matching, mazání platby) |
| `invoice.paid` | `MarkPaidAction` |
| `invoice.cancelled`, `invoice.credit_note_issued` | `CancelInvoiceAction` |
| `invoice.deleted_draft` | `DeleteInvoiceAction` (před `delete()`, dokud vazba existuje) |

Platby jdou přes službu, takže událost vznikne bez ohledu na vstupní kanál.
Vystavení, odeslání, storno a smazání jsou zatím actions; jejich vytažení do
sdílených služeb zůstává otevřené (jako u R3 klienta), ale volání publisheru je
jednořádkové, takže je to při refaktoru přesun jednoho řádku.

## Sekvence a immutabilita

`revizior_invoice_links.event_sequence` se zamkne (`FOR UPDATE`), zvýší a uloží
do události. Unikát `(invoice_link_id, aggregate_sequence)` v outboxu drží
monotónnost i při souběhu. Payload je **immutable snapshot** — dispatcher ho
nikdy neregeneruje, jinak by se po výpadku doručila aktuální podoba faktury
místo té, která událost vyvolala.

## Doručení

`ReviziorOutboxDispatcher`:

- claim jedním `UPDATE` (`claimed_by`, TTL 5 minut) — dva workery si nevezmou
  týž event, po pádu workera se práce uvolní;
- `POST` na `deployment.revizior.callback.url`, connect timeout 3 s, request
  timeout z konfigurace (10 s);
- 2xx = `delivered`; 408, 425, 429, 5xx a síťová chyba = retry s exponenciálním
  backoffem a jitterem (5 s → hodina), respektuje `Retry-After`;
- ostatní 4xx = `failed` (dead letter) a chybový log — opakovat request, který
  protistrana odmítla jako neplatný, jen plní frontu;
- po `max_attempts` (12) končí event v `failed`.

CLI `api/bin/cron-revizior-outbox.php` (+ `cmd/cron-revizior-outbox.{sh,cmd}`):
bez argumentů odešle dávku, `--status` vypíše frontu a vrací exit 2, když
existuje dead letter (alerting), `--retry=<eventId>` vrátí dead letter do fronty.
Ve standalone instalaci skončí bez práce.

## Podpis

`ReviziorEventSigner` podepisuje **přesné bajty těla** RS256 a posílá
`X-MyInvoice-Key-Id` + `X-MyInvoice-Signature` (base64url). Zadání tomu říká
„detached JWS"; consumer (`RsaJwsVerifier::verifyDetached`) ověřuje raw body
bez JOSE hlavičky, takže obě strany implementují tenhle jednodušší tvar.
Kontrakt v1 popisuje jen hlavičky, takže se nic nemění.

Privátní klíč zůstává v MyInvoice (`/data/private/…`), consumer má jen veřejnou
polovinu.

## Nalezená nesrovnalost: zbývající částka

`invoices.amount_to_pay` je generovaný sloupec (celkem mínus záloha) a platby
v něm nejsou. Snapshot proto posílal u částečně uhrazené faktury pořád plnou
částku. Opraveno: `amountDueMinor` = `amount_to_pay − paid_total`, minimum 0
(přeplatek nedá zápornou hodnotu), storno 0. Platí pro událost i pro odpovědi
`POST /invoice-drafts` a `GET /invoices/{key}`.

## Cross-repo smoke (2026-09-02)

| Krok | Provider | Consumer |
|---|---|---|
| vystavení dokladu | `invoice.issued` seq 1, `delivered` (202) | `issued`, číslo `2609001`, sequence 1 |
| částečná úhrada 2 000 | `payment_recorded` seq 2 + `partially_paid` seq 3 | `partially_paid` |
| doplatek 3 445 | `payment_recorded` seq 4 + `paid` seq 5, `amountDueMinor` 0 | `paid`, zbývá 0, sequence 5 |
| `--status` | `pending=0 failed=0 delivered=5` | — |

Doručení běželo přes `cron-revizior-outbox.php` proti běžícímu ReviziORu;
consumer události zpracoval frontou (`messenger:consume`).

## Ověření

- unit: payload a podpis (4), snapshot builder (11 včetně zbývající částky),
  architektonický kontrakt (12);
- integrační `EventOutboxTest` nad MariaDB (4): událost při založení konceptu,
  monotónní sekvence, rollback nenechá nic, doklad bez vazby je no-op,
  dispatcher bez konfigurace nic nezahodí, requeue jen z `failed`;
- celá suite 2 269 testů / 7 954 assertions, PHPStan level 0 bez chyb.

## Další krok

~~R6 přílohy~~ — hotovo, viz [`r6-attachments.md`](r6-attachments.md). Zbývá
dokončení managed UI (`/settings/supplier`, odkaz na revizi) a R7 hardening.

## Událost o organizaci: `organization.onboarding_changed`

Outbox neveze jen doklady. Když se u tenanta změní stav fakturačního
onboardingu, jde do ReviziORu událost s agregátem `organization`:

```json
{ "aggregate": { "type": "organization", "id": "<supplier_id>",
                 "externalKey": "<organization_uuid>", "sequence": 1 },
  "data": { "status": "active", "onboardingState": "completed" } }
```

Sekvenci drží `revizior_organization_links.event_sequence` — vlastní čítač,
nezávislý na sekvencích dokladů. Bez něj by ReviziOR nepoznal pořadí a starší
stav by mohl přepsat novější.

**Proč událost, a ne čtení stavu.** `PUT /organizations/{uuid}` je zápis:
přepíše fakturační identitu dodavatele daty z ReviziORu. Volat ho jen proto,
abychom se dozvěděli stav, by smazalo, co uživatel doplnil ve fakturaci.

**Co je „dokončeno"**: název, adresa a — u plátce DPH — DIČ. Číselná řada mezi
podmínky nepatří: `invoice_number_format` je NULL-able a NULL znamená „použij
formát instalace", ne „chybí". Neplátce DPH je platná volba, ne nedokončený stav.

**Stav se přepočítává po uložení nastavení dodavatele.** Do 2026-09-05 se
`onboarding_state` zapsalo při provisioningu jako `incomplete` a **nikdy se
nezměnilo** — uživatel doplnil identitu a ReviziOR mu dál tvrdil, že nastavení
chybí, takže nešlo vystavit doklad. Provisioning sám stav nepočítá schválně:
dodavatel v tu chvíli teprve vzniká a jeho identita se stejně dokončuje až
v tenantovém nastavení.
