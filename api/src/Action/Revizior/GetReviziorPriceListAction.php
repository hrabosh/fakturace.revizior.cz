<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Service\Integration\Revizior\ReviziorPriceListReader;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Výpis ceníkových kódů dodavatele.
 *
 * Vrací **kódy a názvy, ne ceny**: ReviziOR z toho staví nabídku ve fakturačních
 * pravidlech, aby technik kód nehledal v druhé aplikaci. Cenu si vyžádá až
 * `prices/resolve`, kde na ni má klienta i datum.
 */
final class GetReviziorPriceListAction
{
    public function __construct(
        private readonly ReviziorPriceListReader $reader,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $requestId = $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER);
        try {
            $data = $this->reader->list((string) ($args['organizationUuid'] ?? ''));

            return ReviziorResponse::success($response, $data, $requestId);
        } catch (ReviziorProvisioningException $e) {
            return ReviziorResponse::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                $requestId,
                $e->retryable,
                $e->fields,
            );
        } catch (Throwable $e) {
            $this->logger->error('ReviziOR price list read failed', [
                'request_id' => $requestId,
                'exception' => $e::class,
            ]);

            return ReviziorResponse::error(
                $response,
                'provider_temporarily_unavailable',
                'Fakturační služba je dočasně nedostupná.',
                503,
                $requestId,
                true,
            );
        }
    }
}
