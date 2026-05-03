<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Plugin\Sales;

use Ethelserth\Checkout\Model\OrderComments\Sanitizer;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Mirror of `CartExtensionPlugin`, for placed orders.
 *
 * - `afterGet` / `afterGetList`: surface `sales_order.order_comments` as
 *   the `order_comments` extension attribute for REST consumers reading
 *   `/V1/orders/...`.
 * - `beforeSave`: persist the extension attribute back to the column
 *   when a REST consumer writes (rare but supported).
 *
 * Both sides sanitize. Reads sanitise too because historical rows
 * could pre-date the sanitiser's existence — never trust DB content
 * to be safe.
 *
 * `beforeSave` uses the same `extCleaned === origData` guard as
 * `CartExtensionPlugin` — see that file's docblock for the full
 * rationale. Short version: the auto-populated ext from `afterGet`
 * is stale by the time a `setData()` elsewhere in the request lands
 * on the column; mirroring ext → data on every save would silently
 * undo that fresh write.
 */
class OrderExtensionPlugin
{
    public function __construct(
        private readonly OrderExtensionFactory $extensionFactory,
        private readonly Sanitizer $sanitizer,
    ) {}

    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): OrderInterface {
        return $this->populateExtensionAttribute($order);
    }

    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $result
    ): OrderSearchResultInterface {
        foreach ($result->getItems() as $order) {
            $this->populateExtensionAttribute($order);
        }
        return $result;
    }

    public function beforeSave(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): array {
        $ext = $order->getExtensionAttributes();
        if ($ext === null || !method_exists($ext, 'getOrderComments')) {
            return [$order];
        }

        $extValue = $ext->getOrderComments();
        if ($extValue === null) {
            return [$order];
        }

        $extCleaned = $this->sanitizer->sanitize((string) $extValue);

        $origData = (string) ($order instanceof \Magento\Framework\Model\AbstractModel
            ? $order->getOrigData('order_comments')
            : null);

        if ($extCleaned === $origData) {
            // Stale auto-populated ext from `afterGet`. Trust the
            // column data — see CartExtensionPlugin docblock.
            return [$order];
        }

        $order->setData('order_comments', $extCleaned);
        return [$order];
    }

    private function populateExtensionAttribute(OrderInterface $order): OrderInterface
    {
        $ext = $order->getExtensionAttributes() ?: $this->extensionFactory->create();
        $value = (string) $order->getData('order_comments');

        if (method_exists($ext, 'setOrderComments')) {
            $ext->setOrderComments($value !== '' ? $this->sanitizer->sanitize($value) : '');
        }
        $order->setExtensionAttributes($ext);

        return $order;
    }
}
