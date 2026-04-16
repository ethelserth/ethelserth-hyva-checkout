<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Quote\QuoteService;
use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Shipping step Magewire component.
 * Phase 1: stub — ready for Phase 3 method list.
 */
class Shipping extends Component
{
    public string $selectedCarrier = '';
    public string $selectedMethod = '';
    public bool $complete = false;
    public bool $loading = false;

    /** @var array<int, array{carrier: string, method: string, carrier_title: string, method_title: string, price: float}> */
    public array $rates = [];

    protected $listeners = [
        'addressSaved'       => 'onAddressSaved',
        'stepEditRequested'  => 'onEditRequested',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
    ) {}

    public function boot(): void
    {
        $quote    = $this->checkoutSession->getQuote();
        $shipping = $quote->getShippingAddress();

        if ($shipping->getShippingMethod()) {
            [$carrier, $method] = explode('_', $shipping->getShippingMethod(), 2) + ['', ''];
            $this->selectedCarrier = $carrier;
            $this->selectedMethod  = $method;
            $this->complete        = true;
        }
    }

    public function onAddressSaved(): void
    {
        $this->loading = true;
        $this->rates   = $this->fetchRates();
        $this->loading = false;
    }

    public function selectMethod(string $carrierCode, string $methodCode): void
    {
        $quote = $this->checkoutSession->getQuote();
        $this->quoteService->setShippingMethod($quote, $carrierCode, $methodCode);
        $this->quoteService->collectAndSave($quote);

        $this->selectedCarrier = $carrierCode;
        $this->selectedMethod  = $methodCode;
        $this->complete        = true;

        $this->emit('shippingMethodSelected', $carrierCode, $methodCode);
    }

    public function editShipping(): void
    {
        $this->complete = false;
        $this->emit('stepEditRequested', 'shipping');
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

    /** @return array<int, array> */
    private function fetchRates(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $rawRates = $this->quoteService->getShippingRates($quote);
        $result = [];

        foreach ($rawRates as $rate) {
            $result[] = [
                'carrier'       => $rate->getCarrierCode(),
                'method'        => $rate->getMethodCode(),
                'carrier_title' => $rate->getCarrierTitle(),
                'method_title'  => $rate->getMethodTitle(),
                'price'         => (float) $rate->getPrice(),
            ];
        }

        return $result;
    }
}
