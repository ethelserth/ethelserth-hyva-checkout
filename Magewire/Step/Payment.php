<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Payment\MethodPool;
use Ethelserth\Checkout\Model\Quote\QuoteService;
use Ethelserth\Checkout\Model\Quote\TotalsService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

/**
 * Payment step component.
 *
 * Owns the per-quote method list, applies the chosen method to the quote,
 * and runs the place-order pipeline through the adapter's before/after
 * hooks. Browser events dispatched here (`payment-method-selected`,
 * `order-placed`) drive the Alpine store advance / final redirect.
 */
class Payment extends Component
{
    public string $selectedMethod = '';
    public bool $placing = false;

    /**
     * @var array<int, array{
     *     code: string,
     *     title: string,
     *     icon: string,
     *     instructions: string,
     *     form_template: ?string,
     *     surcharge: float,
     *     surcharge_formatted: string
     * }>
     */
    public array $methods = [];

    /**
     * Lazy-loaded JS asset URLs for the currently selected method (Revolut
     * SDK, Stripe, etc.). Empty for offline methods.
     * @var string[]
     */
    public array $jsAssets = [];

    protected $listeners = [
        'shippingMethodSelected' => 'refresh',
        'couponApplied'          => 'refresh',
        'couponRemoved'          => 'refresh',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
        private readonly MethodPool $methodPool,
        private readonly TotalsService $totalsService,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger,
    ) {}

    public function boot(): void
    {
        $this->refresh();

        $quote = $this->checkoutSession->getQuote();
        $existing = (string) $quote->getPayment()->getMethod();
        if ($existing !== '' && $this->methodPool->getAdapter($existing)) {
            $this->selectedMethod = $existing;
            $this->jsAssets = $this->methodPool->getAdapter($existing)?->getJsAssets() ?? [];
        }
    }

    /** Re-pull the available methods. Cheap — no carrier round-trips. */
    public function refresh(): void
    {
        $this->methods = $this->methodPool->getAvailableMethods(
            $this->checkoutSession->getQuote()
        );

        // If the previously selected method is no longer available
        // (e.g. quote total changed), drop the selection.
        if ($this->selectedMethod !== '' && !$this->isMethodInList($this->selectedMethod)) {
            $this->selectedMethod = '';
            $this->jsAssets = [];
        }
    }

    public function selectMethod(string $methodCode): void
    {
        $adapter = $this->methodPool->getAdapter($methodCode);
        if (!$adapter) {
            $this->dispatchErrorMessage((string) __('That payment method is no longer available.'));
            return;
        }

        $quote = $this->checkoutSession->getQuote();

        try {
            $this->quoteService->setPaymentMethod($quote, $methodCode);
            // Re-collect so any surcharge / totals shift propagates
            // to the summary sidebar immediately.
            $this->quoteService->collectAndSave($quote);
        } catch (\Throwable $e) {
            $this->logger->warning('[Ethelserth_Checkout] selectMethod failed: ' . $e->getMessage());
            $this->dispatchErrorMessage((string) __('We could not apply that payment method. Please try another.'));
            return;
        }

        $this->selectedMethod = $methodCode;
        $this->jsAssets = $adapter->getJsAssets();

        // Emit (Magewire-to-Magewire — the Totals listener picks this up)
        // and dispatch (browser CustomEvent — for any Alpine-side hooks
        // and for symmetry with address/shipping).
        $this->emit('paymentMethodSelected', $methodCode);
        $this->dispatchBrowserEvent('payment-method-selected', ['code' => $methodCode]);
    }

    public function placeOrder(): void
    {
        if ($this->selectedMethod === '') {
            $this->dispatchErrorMessage((string) __('Please select a payment method.'));
            return;
        }

        $adapter = $this->methodPool->getAdapter($this->selectedMethod);
        if (!$adapter) {
            $this->dispatchErrorMessage((string) __('That payment method is no longer available.'));
            return;
        }

        $this->placing = true;

        try {
            $quote = $this->checkoutSession->getQuote();
            $adapter->beforePlaceOrder($quote);

            $orderId = $this->quoteService->placeOrder($quote);

            // Fresh quote read — `placeOrder` clears the active quote and
            // creates the order; some adapters (PSP redirects) may want
            // the persisted order to look up the redirect URL. Future
            // adapters can fetch from $checkoutSession->getLastRealOrder().
            $redirectUrl = $adapter->afterPlaceOrder($quote);

            $payload = ['orderId' => $orderId];
            if ($redirectUrl !== null && $redirectUrl !== '') {
                $payload['redirectUrl'] = $redirectUrl;
            }

            $this->emit('orderPlaced', $orderId);
            $this->dispatchBrowserEvent('order-placed', $payload);
        } catch (LocalizedException $e) {
            $this->placing = false;
            $this->dispatchErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->placing = false;
            $this->logger->critical(
                '[Ethelserth_Checkout] placeOrder failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->dispatchErrorMessage(
                (string) __('We could not place your order. Please review your details and try again.')
            );
        }
    }

    public function getGrandTotalFormatted(): string
    {
        return $this->priceCurrency->format(
            $this->totalsService->getGrandTotal($this->checkoutSession->getQuote()),
            false
        );
    }

    public function getCurrentInstructions(): string
    {
        if ($this->selectedMethod === '') {
            return '';
        }
        foreach ($this->methods as $method) {
            if ($method['code'] === $this->selectedMethod) {
                return $method['instructions'];
            }
        }
        return '';
    }

    private function isMethodInList(string $code): bool
    {
        foreach ($this->methods as $method) {
            if ($method['code'] === $code) {
                return true;
            }
        }
        return false;
    }
}
