<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Shipping\CarrierLogo;

use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * Resolves a carrier logo URL by carrier code.
 *
 * The module ships NO carrier artwork by default — every carrier resolves
 * to null and the template falls back to the neutral truck glyph. Stores
 * (or PSP modules) that want branded logos override this resolver via DI
 * and ship their own SVG assets.
 *
 * The earlier version of this class kept a static map of "known carriers"
 * (flatrate, freeshipping, tablerate, ups, …) but the assets were never
 * shipped — the `<img>` 404'd and browsers fell back to rendering the
 * `alt` text wrapped inside a 36×24 box, which looked like garbled noise.
 */
class Resolver
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
    ) {}

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — the carrier code is
     * the contract; subclasses use it. Kept here so DI overrides slot in
     * without changing the call site.
     */
    public function getLogoUrl(string $carrierCode): ?string
    {
        return null;
    }
}
