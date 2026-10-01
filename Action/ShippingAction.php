<?php

namespace Omnibus\Gls\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Exception\CarrierException;
use Omnibus\Gls\Api;
use Omnibus\Model\Address;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/**
 * POST /shipments: the parcels under a product (service: PARCEL by default,
 * EXPRESS...), a ShopDelivery service when the shipment goes to a ParcelShop
 * (pickupPoint: the shop's id), its labels as PDF.
 */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $services = [];
        if ($s->pickupPoint) {
            $services[] = ['ShopDelivery' => ['ParcelShopID' => $s->pickupPoint]];
        }
        if ($s->recipient->email && $s->option('flex_delivery', true)) {
            $services[] = ['FlexDelivery' => ['FlexDeliveryService' => true]];
        }
        $data = $this->api->call('POST', '/shipments', ['Shipment' => array_filter([
            'ShipmentReference' => $s->reference ? [mb_substr($s->reference, 0, 40)] : null,
            'ShippingDate' => ($s->shippingDate ?? new \DateTimeImmutable())->format('Y-m-d'),
            'Product' => strtoupper($s->service ?? 'PARCEL'),
            'Consignee' => ['Address' => self::address($s->recipient)],
            'Shipper' => ['ContactID' => $this->api->contactId, 'AlternativeShipperAddress' => self::address($s->sender)],
            'ShipmentUnit' => array_map(static fn ($p) => array_filter(['Weight' => round(max(0.1, $p->weight / 1000), 2), 'ShipmentUnitReference' => $p->reference ? [$p->reference] : null]), $s->parcels),
            'Service' => $services ?: null,
        ]), 'PrintingOptions' => ['ReturnLabels' => ['TemplateSet' => 'NONE', 'LabelFormat' => 'PDF']]]);
        $units = $data['CreatedShipment']['ParcelData'] ?? [];
        $number = (string) ($units[0]['TrackID'] ?? $units[0]['ParcelNumber'] ?? '');
        if ('' === $number) {
            throw new CarrierException('gls', 'GLS booked no shipment.');
        }
        $labels = $data['CreatedShipment']['PrintData'] ?? [];
        $content = isset($labels[0]['Data']) ? base64_decode((string) $labels[0]['Data']) : null;
        $request->setResult(new Label('gls', $number, $content, Label::PDF, null, 'https://gls-group.eu/EU/en/parcel-tracking?match='.rawurlencode($number)));
    }

    private static function address(Address $a): array
    {
        return array_filter([
            'Name1' => mb_substr($a->company ?? $a->name, 0, 40),
            'Name2' => $a->company ? mb_substr($a->name, 0, 40) : null,
            'CountryCode' => strtoupper($a->country),
            'ZIPCode' => $a->postcode,
            'City' => $a->city,
            'Street' => mb_substr($a->line(0), 0, 40),
            'StreetNumber' => '' !== $a->line(1) ? null : (preg_match('/^(\d+\s?[a-zA-Z]?)\s/', $a->line(0), $m) ? $m[1] : null),
            'eMail' => $a->email,
            'MobilePhoneNumber' => $a->phone,
        ]);
    }
}
