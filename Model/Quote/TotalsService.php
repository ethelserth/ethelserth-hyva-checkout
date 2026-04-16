<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Quote;

use Magento\Quote\Model\Quote;

/**
 * Read-only totals data for templates.
 */
class TotalsService
{
    public function getSubtotal(Quote $quote): float
    {
        return (float) $quote->getSubtotal();
    }

    public function getGrandTotal(Quote $quote): float
    {
        return (float) $quote->getGrandTotal();
    }

    public function getShippingAmount(Quote $quote): float
    {
        return (float) $quote->getShippingAddress()->getShippingAmount();
    }

    public function getTaxAmount(Quote $quote): float
    {
        return (float) $quote->getShippingAddress()->getTaxAmount();
    }

    public function getDiscountAmount(Quote $quote): float
    {
        return (float) $quote->getShippingAddress()->getDiscountAmount();
    }

    public function getCouponCode(Quote $quote): ?string
    {
        return $quote->getCouponCode() ?: null;
    }

    /**
     * @return array{label: string, value: float}[]
     */
    public function getTotalsRows(Quote $quote): array
    {
        $rows = [];
        $address = $quote->getShippingAddress();

        $rows[] = ['label' => (string) __('Subtotal'), 'value' => (float) $quote->getSubtotal()];

        $discount = (float) $address->getDiscountAmount();
        if ($discount < 0) {
            $rows[] = [
                'label' => $quote->getCouponCode()
                    ? (string) __('Discount (%1)', $quote->getCouponCode())
                    : (string) __('Discount'),
                'value' => $discount,
            ];
        }

        $shipping = (float) $address->getShippingAmount();
        if ($shipping > 0) {
            $rows[] = ['label' => (string) __('Shipping'), 'value' => $shipping];
        } elseif ($address->getShippingMethod()) {
            $rows[] = ['label' => (string) __('Shipping'), 'value' => 0.0, 'free' => true];
        }

        $tax = (float) $address->getTaxAmount();
        if ($tax > 0) {
            $rows[] = ['label' => (string) __('Tax'), 'value' => $tax];
        }

        return $rows;
    }
}
