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
     * Pull `order_comments` off the extension attribute (if a REST consumer
     * set it) and stamp it onto the quote's column data BEFORE save runs.
     */
    public function beforeSave(
        CartRepositoryInterface $subject,
        CartInterface $cart
    ): array {
        $ext = $cart->getExtensionAttributes();
        if ($ext !== null && method_exists($ext, 'getOrderComments')) {
            $value = $ext->getOrderComments();
            if ($value !== null) {
                $cart->setData('order_comments', $this->sanitizer->sanitize((string) $value));
            }
        }
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
