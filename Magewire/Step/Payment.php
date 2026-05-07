<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Magewire\Concern\HasOrderComments;
use Ethelserth\Checkout\Model\OrderComments\Sanitizer as OrderCommentsSanitizer;
use Ethelserth\Checkout\Model\Payment\MethodPool;
use Ethelserth\Checkout\Model\Quote\QuoteService;
use Ethelserth\Checkout\Model\Quote\TotalsService;
use Ethelserth\Checkout\ViewModel\NewsletterConfig;
use Ethelserth\Checkout\ViewModel\OrderCommentsConfig;
use Ethelserth\Checkout\ViewModel\TermsConfig;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
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
    use HasOrderComments;

    public string $selectedMethod = '';
    public bool $placing = false;

    /**
     * Newsletter opt-in checkbox state. Honoured AFTER successful
     * order placement only — we don't subscribe on a check that
     * never reaches a placed order.
     */
    public bool $subscribeNewsletter = false;

    /**
     * IDs of terms-and-conditions agreements the shopper has
     * accepted. Magewire's `wire:model.defer` on a checkbox bound
     * to an array property toggles values in/out automatically.
     *
     * @var int[]
     */
    public array $acceptedAgreementIds = [];

    public string $agreementsError = '';

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
        private readonly OrderCommentsSanitizer $orderCommentsSanitizer,
        private readonly OrderCommentsConfig $orderCommentsConfig,
        private readonly TermsConfig $termsConfig,
        private readonly NewsletterConfig $newsletterConfig,
        private readonly SubscriptionManagerInterface $subscriptionManager,
    ) {}

    // ── HasOrderComments trait wiring ─────────────────────────────────────────
    protected function getOrderCommentsCheckoutSession(): CheckoutSession        { return $this->checkoutSession; }
    protected function getOrderCommentsQuoteService(): QuoteService              { return $this->quoteService; }
    protected function getOrderCommentsSanitizer(): OrderCommentsSanitizer       { return $this->orderCommentsSanitizer; }
    protected function getOrderCommentsConfig(): OrderCommentsConfig             { return $this->orderCommentsConfig; }

    public function boot(): void
    {
        $this->refresh();
        $this->bootOrderComments();

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
        $this->agreementsError = '';

        if ($this->selectedMethod === '') {
            $this->dispatchErrorMessage((string) __('Please select a payment method.'));
            return;
        }

        // Terms-and-conditions are REQUIRED. Every active agreement
        // (from `Sales > Terms and Conditions` admin) must be in the
        // shopper's `acceptedAgreementIds` array, or we abort with an
        // inline error and DO NOT touch the quote / payment / order.
        // This mirrors what `Magento\CheckoutAgreements\Model\AgreementsValidator`
        // does inside the native checkout — re-deriving here saves a
        // dependency injection while keeping the contract identical.
        if (!$this->areAllAgreementsAccepted()) {
            $this->agreementsError = (string) __(
                'Please agree to the terms and conditions to place your order.'
            );
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

            // Stamp the deferred order_comments value onto the quote
            // BEFORE placeOrder runs. Without this, a comment typed in
            // the Payment-step placement would never reach the column —
            // `wire:blur` isn't a Magewire directive, so the only
            // round-trip carrying the value is this one (placeOrder).
            // The quote→order observer (sales_model_service_quote_submit_before)
            // then copies the value to sales_order in the same INSERT.
            $this->applyOrderCommentsToQuote();
            $this->quoteService->save($quote);

            $adapter->beforePlaceOrder($quote);

            $orderId = $this->quoteService->placeOrder($quote);

            // Newsletter opt-in fires AFTER the order placement
            // succeeds. Wrapped — a failed subscription must NEVER
            // block the order; we log and move on.
            $this->maybeSubscribeNewsletter($quote);

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
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Quote / order entity disappeared mid-flight — almost always a
            // session expiry or a tab opened from stale cart state. Send the
            // shopper back to the cart instead of stranding them on a form
            // whose underlying quote no longer exists.
            $this->placing = false;
            $this->logger->warning(
                '[Ethelserth_Checkout] placeOrder hit a missing entity: ' . $e->getMessage()
            );
            $this->dispatchBrowserEvent('checkout-session-expired', [
                'message' => (string) __('Your checkout session has expired. Please return to your cart.'),
            ]);
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

    /**
     * Returns true when every active terms-and-conditions agreement
     * has been checked. Empty active list (or feature disabled) →
     * trivially true (nothing to require).
     *
     * Compares as integers because Magewire ships array-of-string
     * values from checkbox `value="1"` attributes; the explicit
     * cast is what makes `in_array(…, …, true)` strict-mode safe.
     */
    private function areAllAgreementsAccepted(): bool
    {
        $required = $this->termsConfig->getRequiredAgreementIds();
        if (empty($required)) {
            return true;
        }
        $accepted = array_map('intval', $this->acceptedAgreementIds);
        foreach ($required as $id) {
            if (!in_array((int) $id, $accepted, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Honour the newsletter opt-in checkbox by subscribing the email
     * after the order has been placed. Wrapped in `Throwable` because
     * a subscription failure (mail-server hiccup, SubscriberFactory
     * config drift, double-opt-in confirmation send error) must
     * NEVER take an already-placed order down with it.
     *
     * Skipped silently when the checkbox is off, the feature is
     * admin-disabled, or the email is empty — defending against
     * weird quote states rather than throwing inside what's now a
     * post-place-order flow.
     */
    private function maybeSubscribeNewsletter(\Magento\Quote\Model\Quote $quote): void
    {
        if (!$this->subscribeNewsletter || !$this->newsletterConfig->isEnabled()) {
            return;
        }

        $email = trim((string) $quote->getCustomerEmail());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $storeId = (int) $quote->getStoreId();
            $this->subscriptionManager->subscribe($email, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Ethelserth_Checkout] newsletter subscription failed for placed order: '
                    . $e->getMessage()
            );
        }
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
