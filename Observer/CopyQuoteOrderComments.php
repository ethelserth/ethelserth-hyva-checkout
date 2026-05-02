<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Observer;

use Ethelserth\Checkout\Model\OrderComments\Sanitizer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;

/**
 * Copies `quote.order_comments` → `sales_order.order_comments` at the
 * point Magento converts the quote into an order.
 *
 * Event: `sales_model_service_quote_submit_before` — fires inside
 * `Magento\Quote\Model\QuoteManagement::submit` between data assembly
 * and order persistence, so a setData() here lands in the same INSERT.
 *
 * The value is sanitised again here even though `Magewire/Step/*::saveOrderComments`
 * already sanitised before persisting to the quote. Reasons:
 *   1. A REST consumer may have written to the quote without going
 *      through Magewire (CartExtensionPlugin sanitizes that path too,
 *      but a third-party plugin could still bypass it).
 *   2. A historical quote could have been migrated in from an external
 *      system pre-sanitiser.
 * Sanitising on every write site is cheap and the only way to keep the
 * "no untrusted bytes ever reach the orders table" invariant intact.
 */
class CopyQuoteOrderComments implements ObserverInterface
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getData('quote');
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');

        if (!$quote instanceof Quote || !$order instanceof Order) {
            return;
        }

        $raw = (string) $quote->getData('order_comments');
        if ($raw === '') {
            return;
        }

        $order->setData('order_comments', $this->sanitizer->sanitize($raw));
    }
}
