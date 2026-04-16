<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment\Adapter;

use Magento\Quote\Model\Quote;

abstract class AbstractAdapter implements AdapterInterface
{
    public function getIconUrl(): string
    {
        return '';
    }

    public function isAvailable(Quote $quote): bool
    {
        return true;
    }

    public function afterPlaceOrder(Quote $quote): ?string
    {
        return null;
    }

    public function getJsAssets(): array
    {
        return [];
    }
}
