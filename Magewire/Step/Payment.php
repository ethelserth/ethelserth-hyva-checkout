<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Payment\MethodPool;
use Ethelserth\Checkout\Model\Quote\QuoteService;
use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;

/**
 * Payment step Magewire component.
 * Phase 1: stub — ready for Phase 5 adapter wiring.
 */
class Payment extends Component
{
    public string $selectedMethod = '';
    public bool $complete = false;
    public bool $placing = false;

    /** @var array<int, array{code: string, title: string, icon: string}> */
    public array $methods = [];

    protected $listeners = [
        'shippingMethodSelected' => 'onShippingMethodSelected',
        'stepEditRequested'      => 'onEditRequested',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
        private readonly MethodPool $methodPool,
        private readonly LoggerInterface $logger,
    ) {}

    public function boot(): void
    {
        $quote = $this->checkoutSession->getQuote();

        if ($quote->getPayment()->getMethod()) {
            $this->selectedMethod = (string) $quote->getPayment()->getMethod();
        }

        $this->methods = $this->methodPool->getAvailableMethods($quote);
    }

    public function onShippingMethodSelected(): void
    {
        $quote = $this->checkoutSession->getQuote();
        $this->methods = $this->methodPool->getAvailableMethods($quote);
    }

    public function selectMethod(string $methodCode): void
    {
        $this->selectedMethod = $methodCode;
        $quote = $this->checkoutSession->getQuote();
        $this->quoteService->setPaymentMethod($quote, $methodCode);
        $this->quoteService->save($quote);
        $this->emit('paymentMethodSelected', $methodCode);
    }

    public function placeOrder(): void
    {
        if (!$this->selectedMethod) {
            $this->dispatchErrorMessage((string) __('Please select a payment method.'));
            return;
        }

        $this->placing = true;

        try {
            $quote   = $this->checkoutSession->getQuote();
            $orderId = $this->quoteService->placeOrder($quote);
            $this->emit('orderPlaced', $orderId);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->dispatchErrorMessage($e->getMessage());
            $this->placing = false;
        } catch (\Throwable $e) {
            $this->logger->critical('[Ethelserth_Checkout] placeOrder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->dispatchErrorMessage((string) __('An error occurred while placing your order. Please try again.'));
            $this->placing = false;
        }
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function onEditRequested(string $step): void
    {
        if ($step === 'payment') {
            $this->complete = false;
        }
    }
}
