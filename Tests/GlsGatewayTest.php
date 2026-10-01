<?php

namespace Omnibus\Gls\Tests;

use Omnibus\Gls\GlsGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Pickup;
use Omnibus\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GlsGatewayTest extends TestCase
{
    private array $calls = [];

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $url, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : [], $options['headers']];

            return match (true) {
                str_contains($url, 'shipit-wbm-test01') && str_ends_with($path, '/shipments') => new MockResponse(json_encode(['CreatedShipment' => ['ParcelData' => [['TrackID' => 'ZSXJ4MK1', 'ParcelNumber' => '78712345678']], 'PrintData' => [['Data' => base64_encode('%PDF-1.4 gls'), 'LabelFormat' => 'PDF']]]])),
                str_contains($path, '/shipments/cancel/') => new MockResponse(json_encode(['TrackID' => 'ZSXJ4MK1', 'result' => 'CANCELLED'])),
                str_contains($url, 'gls-group.eu/app/service') => new MockResponse(json_encode(['tuStatus' => [['tuNo' => '78712345678', 'progressBar' => ['statusInfo' => 'DELIVERED'], 'history' => [
                    ['date' => '2026-10-02', 'time' => '11:20:00', 'evtDscr' => 'The parcel has been delivered.', 'address' => ['city' => 'Paris', 'countryName' => 'France']],
                    ['date' => '2026-10-01', 'time' => '18:05:00', 'evtDscr' => 'The parcel has reached the parcel center.', 'address' => ['city' => 'Toussieu', 'countryName' => 'France']],
                ]]]])),
                default => new MockResponse(json_encode(['message' => 'No such resource '.$path]), ['http_code' => 404, 'response_headers' => ['message' => 'No such resource', 'error' => 'E404']]),
            };
        });

        return (new GlsGatewayFactory($http))->create(['username' => 'user', 'password' => 'pass', 'contact_id' => '276a1234', 'sandbox' => true, 'rates' => [['service' => 'PARCEL', 'label' => 'GLS', 'bands' => [2000 => 690]]]]);
    }

    public function testAShipmentIsCreatedWithItsLabel(): void
    {
        $label = $this->gateway()->ship(Fixtures::shipment());
        self::assertSame('ZSXJ4MK1', $label->trackingNumber);
        self::assertSame('%PDF-1.4 gls', $label->content);
        $sent = $this->calls[0][2]['Shipment'];
        self::assertSame('276a1234', $sent['Shipper']['ContactID']);
        self::assertSame('PARCEL', $sent['Product']);
        self::assertSame(['CMD-1042'], $sent['ShipmentReference']);
        self::assertSame('Émile Zola', $sent['Consignee']['Address']['Name1']);
        self::assertSame(0.8, $sent['ShipmentUnit'][0]['Weight']);
        self::assertSame(['FlexDelivery' => ['FlexDeliveryService' => true]], $sent['Service'][0]);
    }

    public function testAParcelShopDeliveryAddsTheService(): void
    {
        $this->gateway()->ship(new Shipment(Fixtures::shop(), new Address('Émile Zola', ['21 bis rue de Bruxelles'], '75009', 'Paris', 'FR'), [new Parcel(800)], pickupPoint: '2500123456'));
        self::assertSame(['ShopDelivery' => ['ParcelShopID' => '2500123456']], $this->calls[0][2]['Shipment']['Service'][0]);
    }

    public function testTrackingCancelAndConfiguredRates(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('78712345678', 'en');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame(TrackingStatus::IN_TRANSIT, $tracking->events[0]->status);
        self::assertSame('Paris France', $tracking->latest()->location);
        self::assertStringContainsString('/EU/en/', $this->calls[0][1]);

        self::assertTrue($gateway->cancel('ZSXJ4MK1'));
        self::assertSame(690, $gateway->rate(Fixtures::shipment())[0]->amount);
        self::assertFalse($gateway->supports(Pickup::class));
    }
}
