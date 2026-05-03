<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Plugin\Quote;

use Ethelserth\Checkout\Model\OrderComments\Sanitizer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartExtensionFactory;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Bridges the `quote.order_comments` column with the `order_comments`
 * extension attribute on `Magento\Quote\Api\Data\CartInterface`.
 *
 * - `afterGet*`: populates the extension attribute from the column so
 *   REST consumers reading the cart see the value.
 * - `beforeSave`: pulls the extension attribute back into the column
 *   so REST consumers writing via `/V1/carts/...` persist the value.
 *
 * Both sides run the input through `Sanitizer` — defense in depth in
 * case a REST consumer or a third-party extension bypassed the
 * Magewire `saveOrderComments` path.
 *
 * --- WHY beforeSave compares against origData ----------------------
 * `afterGet*` auto-populates the extension attribute from the just-
 * loaded column value. That copy is then attached to the in-memory
 * cart object for the rest of the request. If something else in the
 * request (Magewire's `applyOrderCommentsToQuote`, a custom plugin,
 * the user-flow CTA) mutates `quote.order_comments` via `setData()`,
 * the extension attribute is now STALE — it still holds the load-
 * time value, not the freshly-set one.
 *
 * If `beforeSave` blindly mirrored ext → data on every save it would
 * UNDO that fresh setData with the stale ext value. We hit this bug
 * on first integration: a comment typed in the Payment placement
 * never reached the DB because this plugin overwrote the freshly-
 * set 'leave at door' with the auto-populated empty-string ext value
 * a quarter-second before the actual save SQL ran.
 *
 * The fix: only mirror ext → data when the ext value DIFFERS from
 * `origData` (= the column value at load time). Match means "ext is
 * stale from afterGet, trust the column data." Differ means "REST
 * consumer set a new value, apply it to the column."
 */
class CartExtensionPlugin
{
    public function __construct(
        private readonly CartExtensionFactory $extensionFactory,
        private readonly Sanitizer $sanitizer,
    ) {}

    public function afterGet(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): CartInterface {
        return $this->populateExtensionAttribute($cart);
    }

    public function afterGetActive(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): CartInterface {
        return $this->populateExtensionAttribute($cart);
    }

    public function afterGetForCustomer(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): CartInterface {
        return $this->populateExtensionAttribute($cart);
    }

    public function afterGetActiveForCustomer(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): CartInterface {
        return $this->populateExtensionAttribute($cart);
    }

    /**
     * REST writes via `extension_attributes.order_comments` reach the
     * column through this hook. Our own `setData('order_comments', …)`
     * paths reach the column directly — and this hook MUST NOT undo
     * those by mirroring a stale ext value back over them.
     */
    public function beforeSave(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): array {
        $ext = $cart->getExtensionAttributes();
        if ($ext === null || !method_exists($ext, 'getOrderComments')) {
            return [$cart];
        }

        $extValue = $ext->getOrderComments();
        if ($extValue === null) {
            return [$cart];
        }

        $extCleaned = $this->sanitizer->sanitize((string) $extValue);

        // Match `populateExtensionAttribute`'s null-vs-empty-string
        // handling so the comparison is symmetric — `''` from origData
        // and `''` from a sanitised empty ext look the same.
        $origData = (string) ($cart instanceof \Magento\Framework\Model\AbstractModel
            ? $cart->getOrigData('order_comments')
            : null);

        if ($extCleaned === $origData) {
            // Ext is just the auto-populated copy from `afterGet*`;
            // a `setData()` elsewhere in the request may have changed
            // the column data already. Trust the column, leave it.
            return [$cart];
        }

        $cart->setData('order_comments', $extCleaned);
        return [$cart];
    }

    private function populateExtensionAttribute(CartInterface $cart): CartInterface
    {
        $ext = $cart->getExtensionAttributes() ?: $this->extensionFactory->create();
        $value = (string) $cart->getData('order_comments');

        if (method_exists($ext, 'setOrderComments')) {
            $ext->setOrderComments($value !== '' ? $this->sanitizer->sanitize($value) : '');
        }
        $cart->setExtensionAttributes($ext);

        return $cart;
    }
}
