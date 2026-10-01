<?php

namespace Omnibus\Gls\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Gls\Api;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;

/** GLS's public parcel tracking: the history, oldest first. */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $data = $this->api->track($request->trackingNumber, $request->locale);
        $parcel = $data['tuStatus'][0] ?? [];
        $events = [];
        foreach ($parcel['history'] ?? [] as $h) {
            $events[] = new TrackingEvent(new \DateTimeImmutable(($h['date'] ?? '1970-01-01').' '.($h['time'] ?? '00:00:00')), self::status($h['evtDscr'] ?? null), (string) ($h['evtDscr'] ?? ''), trim(implode(' ', array_filter([$h['address']['city'] ?? null, $h['address']['countryName'] ?? null]))) ?: null);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        $status = $events ? $events[array_key_last($events)]->status : TrackingStatus::UNKNOWN;
        if ('DELIVERED' === strtoupper((string) ($parcel['progressBar']['statusInfo'] ?? ''))) {
            $status = TrackingStatus::DELIVERED;
        }
        $request->setResult(new TrackingModel('gls', $request->trackingNumber, $status, $events));
    }

    private static function status(?string $description): TrackingStatus
    {
        $d = strtolower((string) $description);

        return match (true) {
            str_contains($d, 'delivered') || str_contains($d, 'livré') || str_contains($d, 'zugestellt') => str_contains($d, 'parcelshop') || str_contains($d, 'parcel shop') ? TrackingStatus::AVAILABLE_FOR_PICKUP : TrackingStatus::DELIVERED,
            str_contains($d, 'out for delivery') || str_contains($d, 'en cours de livraison') || str_contains($d, 'in zustellung') => TrackingStatus::OUT_FOR_DELIVERY,
            str_contains($d, 'not delivered') || str_contains($d, 'non livré') || str_contains($d, 'nicht zugestellt') || str_contains($d, 'exception') => TrackingStatus::EXCEPTION,
            str_contains($d, 'return') || str_contains($d, 'retour') || str_contains($d, 'rück') => TrackingStatus::RETURNED,
            str_contains($d, 'data') || str_contains($d, 'données') || str_contains($d, 'daten') => TrackingStatus::PENDING,
            '' !== $d => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
