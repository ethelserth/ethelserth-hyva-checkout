<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Shipping;

use Ethelserth\Checkout\Model\Shipping\CarrierLogo\Resolver as CarrierLogoResolver;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Address\Rate;

/**
 * Decorates raw shipping rates for display.
 *
 * Everything a rate carries — carrier code, carrier/method title, price,
 * error message — is configured by Magento in
 * `Stores > Configuration > Sales > Shipping Methods`. We never duplicate
 * that into our own admin section. This class only adds purely presentational
 * extras on top of what the rate itself provides:
 *
 *   - price_formatted : currency-aware, "Free" for zero-cost methods
 *   - logo_url        : carrier logo (or null for carriers we don't ship artwork for)
 *   - badge           : 'cheapest' | null, auto-derived from the rate set
 *   - badge_label     : localised label for the badge
 *   - error           : passthrough for rate-level errors
 *
 * Works with ANY enabled shipping method — offline carriers (flatrate,
 * freeshipping, tablerate), real-time carriers (UPS, USPS, DHL…), and any
 * custom carrier plugged in by a third-party module. Unknown carriers
 * simply render without a logo.
 */
class MethodDecorator
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CarrierLogoResolver $carrierLogoResolver,
    ) {}

    /**
     * @param Rate[] $rates
     * @return array<int, array{
     *     carrier: string,
     *     method: string,
     *     code: string,
     *     carrier_title: string,
     *     method_title: string,
     *     price: float,
     *     price_formatted: string,
     *     logo_url: ?string,
     *     badge: ?string,
     *     badge_label: ?string,
     *     error: ?string
     * }>
     */
    public function decorate(array $rates): array
    {
        if (!$rates) {
            return [];
        }

        $validRates = array_filter(
            $rates,
            static fn(Rate $rate): bool => !$rate->getErrorMessage()
        );
        $cheapestCode = $this->findCheapestCode($validRates);

        $decorated = [];
        foreach ($rates as $rate) {
            $carrierCode = (string) $rate->getCarrier();
            $methodCode  = (string) $rate->getMethod();
            $code        = $carrierCode . '_' . $methodCode;
            $price       = (float) $rate->getPrice();
            $error       = $rate->getErrorMessage() ? (string) $rate->getErrorMessage() : null;

            $badge = !$error && $code === $cheapestCode ? 'cheapest' : null;

            $decorated[] = [
                'carrier'         => $carrierCode,
                'method'          => $methodCode,
                'code'            => $code,
                'carrier_title'   => (string) ($rate->getCarrierTitle() ?: $carrierCode),
                'method_title'    => (string) ($rate->getMethodTitle() ?: $methodCode),
                'price'           => $price,
                'price_formatted' => $this->formatPrice($price),
                'logo_url'        => $this->carrierLogoResolver->getLogoUrl($carrierCode),
                'badge'           => $badge,
                'badge_label'     => $badge ? (string) __('Cheapest') : null,
                'error'           => $error,
            ];
        }

        return $decorated;
    }

    /**
     * Decorate a single rate by code (used for the collapsed summary
     * after a method has been selected).
     *
     * @param Rate[] $rates
     */
    public function findDecoratedByCode(array $rates, string $carrierCode, string $methodCode): ?array
    {
        foreach ($this->decorate($rates) as $decorated) {
            if ($decorated['carrier'] === $carrierCode && $decorated['method'] === $methodCode) {
                return $decorated;
            }
        }
        return null;
    }

    private function formatPrice(float $price): string
    {
        if ($price <= 0.0) {
            return (string) __('Free');
        }
        return (string) $this->priceCurrency->format($price, false);
    }

    /**
     * Only flags a "cheapest" when there is more than one priced option —
     * a single rate shouldn't wear a comparison badge.
     *
     * @param Rate[] $rates
     */
    private function findCheapestCode(array $rates): ?string
    {
        if (count($rates) <= 1) {
            return null;
        }

        $cheapest = null;
        $cheapestPrice = PHP_FLOAT_MAX;

        foreach ($rates as $rate) {
            $price = (float) $rate->getPrice();
            if ($price < $cheapestPrice) {
                $cheapestPrice = $price;
                $cheapest = $rate->getCarrier() . '_' . $rate->getMethod();
            }
        }

        return $cheapest;
    }
}
