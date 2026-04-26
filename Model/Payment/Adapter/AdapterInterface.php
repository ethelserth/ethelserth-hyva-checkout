<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment\Adapter;

use Magento\Quote\Model\Quote;

/**
 * Contract every payment method exposes to the checkout step.
 *
 * Offline methods (Bank Transfer, COD, …) use the shared `Offline` adapter.
 * PSP-backed methods (Viva, Revolut, …) implement their own adapter, usually
 * extending `AbstractAdapter` to inherit the no-op defaults.
 */
interface AdapterInterface
{
    /** Payment method code, e.g. 'checkmo', 'revolut_pay'. */
    public function getMethodCode(): string;

    /** Human-readable title shown in the payment method list. */
    public function getTitle(): string;

    /** Absolute URL to a logo image, or empty string. */
    public function getIconUrl(): string;

    /** Whether this method is available for the given quote. */
    public function isAvailable(Quote $quote): bool;

    /**
     * Description shown once the method is selected (HTML allowed —
     * caller is responsible for trusting the source).
     * Offline methods read this from `payment/<code>/instructions`.
     */
    public function getInstructions(): string;

    /**
     * Surcharge applied if the customer picks this method (e.g. COD fee).
     * 0.0 means no surcharge. Display-only — collection of the actual fee
     * stays Magento's job (handled by the method's own model).
     */
    public function getSurcharge(Quote $quote): float;

    /**
     * Optional inline template path (e.g. 'Vendor_Module::payment/foo.phtml')
     * rendered inside the method body — used by adapters that need their
     * own form (Revolut card, iDEAL bank picker, …). Null = no body beyond
     * the instructions text.
     */
    public function getFormTemplate(): ?string;

    /**
     * Hook called immediately before `placeOrder()` runs. Throw a
     * LocalizedException to abort with a user-facing message.
     */
    public function beforePlaceOrder(Quote $quote): void;

    /**
     * Hook called after `placeOrder()` succeeds.
     * Return a redirect URL (e.g. PSP hosted page) or null to stay on
     * the local confirmation page.
     */
    public function afterPlaceOrder(Quote $quote): ?string;

    /**
     * JS assets to lazy-load when this method is selected.
     * @return string[] Absolute URLs.
     */
    public function getJsAssets(): array;
}
