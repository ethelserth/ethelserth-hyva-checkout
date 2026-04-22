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
 *   1. `boot()` pulls the saved method from the quote so a refresh keeps
 *      the user on the same spot.
 *   2. `onAddressSaved` listener re-fetches rates when the address step
 *      emits `addressSaved`.
 *   3. `selectMethod()` persists + emits `shippingMethodSelected`.
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

        // Load rates if the address step is already done (prevents an empty
        // list on refresh). Address component writes a firstname on save.
        if ($shipping->getFirstname()) {
            $this->methods = $this->fetchDecoratedRates();
        }
    }

    public function onAddressSaved(): void
    {
        $this->errorMessage = '';
        $this->methods      = $this->fetchDecoratedRates();

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
        // a fresh decorate pass so the summary still renders on refresh.
        $quote = $this->checkoutSession->getQuote();
        $rates = $this->quoteService->getShippingRates($quote);
        return $this->methodDecorator->findDecoratedByCode(
            $rates,
            $this->selectedCarrier,
            $this->selectedMethod
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDecoratedRates(): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            $rates = $this->quoteService->getShippingRates($quote);
            return $this->methodDecorator->decorate($rates);
        } catch (\Throwable $e) {
            $this->logger->error('Shipping rate collection failed', ['exception' => $e]);
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
