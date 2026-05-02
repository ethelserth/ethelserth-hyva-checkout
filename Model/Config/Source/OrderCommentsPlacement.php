<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for `ethelserth_checkout/order_comments/placement`.
 *
 * Two values out of the box: `address` and `payment`. The values
 * MUST stay in sync with `OrderCommentsConfig::PLACEMENT_*` and the
 * step names hard-coded in the shared partial's `shouldRenderIn()`
 * gate. If you add a third placement (e.g. `summary`), wire it in
 * all three places or you'll get a config option that silently does
 * nothing.
 */
class OrderCommentsPlacement implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'address',
                'label' => __('Address step (above "Continue to shipping" button)'),
            ],
            [
                'value' => 'payment',
                'label' => __('Payment step (above "Place order" button)'),
            ],
        ];
    }
}
