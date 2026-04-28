<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Quote\QuoteService;
use Ethelserth\Checkout\Model\Shipping\MethodDecorator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

/**
 * Shipping step Magewire component.
 *
 * Owns rate fetching, decoration, and persistence of the selected method
 * on the quote. Works against whatever Magento carriers the merchant has
 * enabled in admin (flatrate, freeshipping, tablerate, custom carriers) —
 * nothing here is carrier-specific.
 *
 * Lifecycle:
 *   1. `boot()` restores the saved method and hydrates `methods` from rates
 *      already persisted on the quote. It never contacts carriers — a fresh
 *      collect would hammer real-time carrier APIs on every Magewire round
 *      trip. Only falls back to a one-shot collect if the quote has an
 *      address but no persisted rows (e.g. resumed session).
 *   2. `onAddressSaved` listener re-reads persisted rates. The address
 *      component's own `collectAndSave` already ran the carriers, so no
 *      second collect is needed.
 *   3. `selectMethod()` persists + emits `shippingMethodSelected`. Its
 *      `collectAndSave` recomputes totals but doesn't re-run carriers
 *      (the `collectShippingRates` flag is off by this point).
 *   4. `onEditRequested('shipping')` resets completion when the user
 *      clicks "Edit" on the step header.
 */
class Shipping extends Component
{
    public string $selectedCarrier = '';
    public string $selectedMethod  = '';
    public bool   $complete        = false;
    public string $errorMessage    = '';

    /**
     * Decorated rates (see MethodDecorator for the shape).
     * @var array<int, array<string, mixed>>
     */
    public array $methods = [];

    protected $listeners = [
        'addressSaved'      => 'onAddressSaved',
        'stepEditRequested' => 'onEditRequested',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
        private readonly MethodDecorator $methodDecorator,
        private readonly LoggerInterface $logger,
    ) {}

    public function boot(): void
    {
        $this->errorMessage = '';

        $quote    = $this->checkoutSession->getQuote();
        $shipping = $quote->getShippingAddress();

        // Pre-fill selection from an existing quote (e.g. after page refresh).
        if ($shipping->getShippingMethod()) {
            [$carrier, $method] = explode('_', (string) $shipping->getShippingMethod(), 2) + ['', ''];
            $this->selectedCarrier = $carrier;
            $this->selectedMethod  = $method;
            $this->complete        = $carrier !== '' && $method !== '';
        }

        // Hydrate methods only when they aren't already carried in the Magewire
        // snapshot (first render / page refresh). Never re-collect from carriers
        // on a regular round-trip — rates already persist on the quote.
        if (!$this->methods && $shipping->getFirstname()) {
            $this->methods = $this->loadDecoratedRates();
        }
    }

    public function onAddressSaved(): void
    {
        $this->errorMessage = '';

        // Address save has just run collectAndSave → rates are already on the
        // quote. Re-read them, don't re-run carriers.
        $this->methods = $this->loadDecoratedRates();

        // If a previously selected method vanished (address change invalidated
        // the carrier) clear the selection so the UI forces a re-pick.
        if ($this->selectedCarrier !== '' && !$this->isSelectionAvailable()) {
            $this->selectedCarrier = '';
            $this->selectedMethod  = '';
            $this->complete        = false;
        }
    }

    public function selectMethod(string $carrierCode, string $methodCode): void
    {
        if ($carrierCode === '' || $methodCode === '') {
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $this->quoteService->setShippingMethod($quote, $carrierCode, $methodCode);
            $this->quoteService->collectAndSave($quote);
        } catch (\Throwable $e) {
            $this->logger->error('Shipping method save failed', ['exception' => $e]);
            $this->errorMessage = (string) __('We could not save your shipping choice. Please try again.');
            return;
        }

        $this->selectedCarrier = $carrierCode;
        $this->selectedMethod  = $methodCode;
        $this->complete        = true;

        $this->emit('shippingMethodSelected', $carrierCode, $methodCode);
        $this->dispatchBrowserEvent('shipping-method-selected', [
            'carrier' => $carrierCode,
            'method'  => $methodCode,
        ]);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * User-driven retry after a rate-fetch failure (transient carrier API
     * outage, network blip). Forces a fresh `collectShippingRates` instead of
     * just re-reading persisted rows — that's the whole point.
     */
    public function retryRates(): void
    {
        $this->errorMessage = '';

        try {
            $quote = $this->checkoutSession->getQuote();
            $rates = $this->quoteService->getShippingRates($quote);
            $this->methods = $this->methodDecorator->decorate($rates);
        } catch (\Throwable $e) {
            $this->logger->error('Shipping rate retry failed', ['exception' => $e]);
            $this->errorMessage = (string) __('Shipping rates are still unavailable. Please try again in a moment.');
        }
    }

    public function onEditRequested(string $step): void
    {
        if ($step !== 'shipping') {
            return;
        }
        $this->complete = false;
    }

    public function getSelectedSummary(): ?array
    {
        if (!$this->complete || $this->selectedCarrier === '' || $this->selectedMethod === '') {
            return null;
        }

        foreach ($this->methods as $method) {
            if ($method['carrier'] === $this->selectedCarrier
                && $method['method'] === $this->selectedMethod
            ) {
                return $method;
            }
        }

        // Methods array may be empty during a partial request — fall back to
        // persisted rates so the summary still renders on refresh without
        // re-hitting carrier endpoints.
        $quote = $this->checkoutSession->getQuote();
        $rates = $this->quoteService->getPersistedShippingRates($quote);
        return $this->methodDecorator->findDecoratedByCode(
            $rates,
            $this->selectedCarrier,
            $this->selectedMethod
        );
    }

    /**
     * Read persisted rates from the quote and decorate them. Falls back to a
     * one-shot carrier collect only when the quote has an address but no
     * persisted rows yet (e.g. resumed session).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadDecoratedRates(): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $rates = $this->quoteService->getPersistedShippingRates($quote);

            if (!$rates && $quote->getShippingAddress()->getCountryId()) {
                $rates = $this->quoteService->getShippingRates($quote);
            }

            return $this->methodDecorator->decorate($rates);
        } catch (\Throwable $e) {
            $this->logger->error('Shipping rate hydration failed', ['exception' => $e]);
            $this->errorMessage = (string) __('We could not load shipping options. Please verify your address.');
            return [];
        }
    }

    private function isSelectionAvailable(): bool
    {
        foreach ($this->methods as $method) {
            if ($method['carrier'] === $this->selectedCarrier
                && $method['method'] === $this->selectedMethod
                && !($method['error'] ?? null)
            ) {
                return true;
            }
        }
        return false;
    }
}
