<?php

namespace Omnibus\Gls\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Gls\Api;
use Omnibus\Request\Cancel;
use Omnibus\Request\Request;

/** POST /shipments/cancel/{trackId}: a shipment not yet collected, cancelled. */
final class CancelAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Cancel;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Cancel);
        $data = $this->api->call('POST', '/shipments/cancel/'.rawurlencode($request->trackingNumber));
        $request->setResult(\in_array(strtoupper((string) ($data['result'] ?? '')), ['CANCELLED', 'CANCELLATION_PENDING'], true));
    }
}
