<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Shipping\CarrierLogo;

use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * Resolves a carrier logo URL by carrier code.
 *
 * Logos are expected at:
 *   app/code/Ethelserth/Checkout/view/frontend/web/images/shipping/<carrierCode>.svg
 *
 * Custom carriers that have no asset fall back to null — the template then
 * renders a neutral generic icon. The resolver is intentionally indulgent:
 * any admin-configured carrier works, even if the module ships no artwork.
 */
class Resolver
{
    /**
     * Carriers we ship artwork for out of the box. Adding a new key + asset
     * is enough to light up a logo. Unknown carriers resolve to null.
     *
     * @var array<string, string>
     */
    private const KNOWN_CARRIERS = [
        'flatrate'     => 'flatrate.svg',
        'freeshipping' => 'freeshipping.svg',
        'tablerate'    => 'tablerate.svg',
        'ups'          => 'ups.svg',
        'usps'         => 'usps.svg',
        'fedex'        => 'fedex.svg',
        'dhl'          => 'dhl.svg',
        'dhlexpress'   => 'dhl.svg',
        'postnl'       => 'postnl.svg',
        'dpd'          => 'dpd.svg',
        'gls'          => 'gls.svg',
        'bpost'        => 'bpost.svg',
    ];

    public function __construct(
        private readonly AssetRepository $assetRepository,
    ) {}

    public function getLogoUrl(string $carrierCode): ?string
    {
        $key = strtolower($carrierCode);
        if (!isset(self::KNOWN_CARRIERS[$key])) {
            return null;
        }

        return $this->assetRepository->getUrl(
            'Ethelserth_Checkout::images/shipping/' . self::KNOWN_CARRIERS[$key]
        );
    }
}
