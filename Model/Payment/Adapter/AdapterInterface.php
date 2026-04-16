<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment\Adapter;

use Magento\Quote\Model\Quote;

interface AdapterInterface
{
    /** Payment method code, e.g. 'checkmo', 'revolut_pay' */
    public function getMethodCode(): string;

    /** Human-readable title shown in the payment method list */
    public function getTitle(): string;

    /** Absolute URL to a logo image, or empty string */
    public function getIconUrl(): string;

    /** Whether this method is available for the given quote */
    public function isAvailable(Quote $quote): bool;

    /**
     * Called after placeOrder().
     * Return a redirect URL (e.g. PSP hosted page) or null to stay on confirmation.
     */
    public function afterPlaceOrder(Quote $quote): ?string;

    /**
     * JS assets to lazy-load when this method is selected.
     * @return string[] Absolute URLs
     */
    public function getJsAssets(): array;
}
