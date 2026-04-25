<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Summary;

use Ethelserth\Checkout\Model\Quote\QuoteService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

/**
 * Coupon code apply/remove component.
 *
 * Mutates the quote (`setCouponCode` + `collectAndSave`) and emits
 * `couponApplied` / `couponRemoved` so the Totals component refreshes.
 *
 * Magento sets the coupon code back to empty when invalid — that's how
 * we detect rejection without a separate validation call.
 */
class Coupon extends Component
{
    public string $code            = '';
    public string $appliedCode     = '';
    public string $errorMessage    = '';
    public string $successMessage  = '';

    protected $listeners = [
        // Address change can invalidate region-restricted rules — re-read
        // what's actually applied on the quote.
        'addressSaved' => 'syncFromQuote',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
        private readonly LoggerInterface $logger,
    ) {}

    public function boot(): void
    {
        $this->syncFromQuote();
    }

    public function syncFromQuote(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';
        $this->appliedCode    = (string) ($this->checkoutSession->getQuote()->getCouponCode() ?? '');
    }

    public function applyCoupon(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        $code = trim($this->code);
        if ($code === '') {
            $this->errorMessage = (string) __('Please enter a discount code.');
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $quote->setCouponCode($code);
            $this->quoteService->collectAndSave($quote);
        } catch (\Throwable $e) {
            $this->logger->error('Coupon apply failed', ['exception' => $e]);
            $this->errorMessage = (string) __('We could not apply that code. Please try again.');
            return;
        }

        $applied = (string) ($this->checkoutSession->getQuote()->getCouponCode() ?? '');
        if ($applied === '' || strcasecmp($applied, $code) !== 0) {
            // Magento blanks the coupon code on the quote when the rule didn't match.
            $this->errorMessage = (string) __('The discount code is not valid for this cart.');
            $this->code         = '';
            return;
        }

        $this->appliedCode    = $applied;
        $this->code           = '';
        $this->successMessage = (string) __('Discount code applied.');

        $this->emit('couponApplied');
    }

    public function removeCoupon(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';

        try {
            $quote = $this->checkoutSession->getQuote();
            $quote->setCouponCode('');
            $this->quoteService->collectAndSave($quote);
        } catch (\Throwable $e) {
            $this->logger->error('Coupon remove failed', ['exception' => $e]);
            $this->errorMessage = (string) __('We could not remove the discount code.');
            return;
        }

        $this->appliedCode = '';
        $this->emit('couponRemoved');
    }
}
