<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Concern;

use Ethelserth\Checkout\Model\OrderComments\Sanitizer;
use Ethelserth\Checkout\ViewModel\OrderCommentsConfig;

/**
 * Adds `$orderComments` + boot/save lifecycle to any Magewire step
 * component. Used by `Magewire/Step/Address` and `Magewire/Step/Payment`
 * — whichever placement the merchant configures, the OWNING step's
 * Magewire instance writes to the quote.
 *
 * Because both steps `use` this trait, both have an identical property
 * + action set. They never both render the textarea at the same time
 * (the `OrderCommentsConfig::shouldRenderIn` gate in the partial
 * ensures that), so there is no sync problem — only one component is
 * ever the source-of-truth at a time.
 *
 * Trait expectations (the using class must provide):
 *   - `protected getOrderCommentsCheckoutSession(): \Magento\Checkout\Model\Session`
 *   - `protected getOrderCommentsQuoteService(): \Ethelserth\Checkout\Model\Quote\QuoteService`
 *   - `protected getOrderCommentsSanitizer(): Sanitizer`
 *   - `protected getOrderCommentsConfig(): OrderCommentsConfig`
 *
 * Accessors rather than direct property reads because PHP traits can't
 * reliably read private constructor-promoted properties of the using
 * class in every version the module supports.
 */
trait HasOrderComments
{
    /**
     * Magewire keeps this in the snapshot; survives Magewire round-trips,
     * page refreshes (via boot), and step edits.
     */
    public string $orderComments = '';

    /**
     * Call from the using component's `boot()`.
     * Reads the value back from the quote so a refresh / step-edit
     * never loses what the shopper typed.
     */
    protected function bootOrderComments(): void
    {
        $quote = $this->getOrderCommentsCheckoutSession()->getQuote();
        $this->orderComments = (string) $quote->getData('order_comments');
    }

    /**
     * In-memory write only — sanitises the property and stamps it onto
     * the live quote object WITHOUT calling save. Used by the parent
     * step's own action (saveAddress, placeOrder) which is about to
     * persist the quote anyway — saves us a duplicate cartRepository
     * round-trip in that flow.
     *
     * MUST be called from inside any parent action that persists the
     * quote, BEFORE that persistence runs. Otherwise the deferred
     * value just sits in `$this->orderComments` and never reaches the
     * column. (This is the bug we hit on first integration: `wire:blur`
     * isn't a Magewire directive, so the only round-trip carrying the
     * deferred value was `placeOrder`, which never called this method.)
     */
    protected function applyOrderCommentsToQuote(): void
    {
        if (!$this->getOrderCommentsConfig()->isEnabled()) {
            $this->orderComments = '';
            $this->getOrderCommentsCheckoutSession()->getQuote()->setData('order_comments', '');
            return;
        }

        $cleaned = $this->getOrderCommentsSanitizer()->sanitize($this->orderComments);
        // Reflect the sanitised value back to the property so the
        // textarea repaints with the cleaned form (e.g. tags gone,
        // length capped). Otherwise the shopper sees their input
        // unchanged but persisted differently — confusing.
        $this->orderComments = $cleaned;
        $this->getOrderCommentsCheckoutSession()->getQuote()->setData('order_comments', $cleaned);
    }

    /**
     * Magewire action — standalone "save now" path, wired from the
     * textarea's Alpine `@blur="$wire.saveOrderComments"`. Sanitises,
     * writes to the quote, persists. NEVER calls `collectTotals`
     * because the comment value doesn't influence totals.
     *
     * Also called as a no-op safety net if a parent action's
     * `applyOrderCommentsToQuote` somehow gets skipped — calling
     * `save()` here is idempotent.
     */
    public function saveOrderComments(): void
    {
        $this->applyOrderCommentsToQuote();
        $this->getOrderCommentsQuoteService()->save(
            $this->getOrderCommentsCheckoutSession()->getQuote()
        );

        // Tell the partial to flash its "Saved ✓" pill. The Alpine
        // x-data on `.checkout-order-comments` listens for this event
        // and toggles `showSaved` for ~1.5s. Pure UX feedback —
        // skip on the parent-action path (saveAddress / placeOrder)
        // so we don't briefly flash a confirmation that the shopper
        // didn't ask for.
        $this->dispatchBrowserEvent('order-comments-saved');
    }

    abstract protected function getOrderCommentsCheckoutSession(): \Magento\Checkout\Model\Session;
    abstract protected function getOrderCommentsQuoteService(): \Ethelserth\Checkout\Model\Quote\QuoteService;
    abstract protected function getOrderCommentsSanitizer(): Sanitizer;
    abstract protected function getOrderCommentsConfig(): OrderCommentsConfig;
}
