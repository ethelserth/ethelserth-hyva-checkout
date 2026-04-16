<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment\Adapter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Adapter for all native Magento offline payment methods:
 * checkmo, banktransfer, free, cashondelivery, purchaseorder.
 *
 * This adapter is instantiated once per offline method via MethodPool.
 */
class Offline extends AbstractAdapter
{
    public function __construct(
        private readonly string $methodCode,
        private readonly string $title,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {}

    public function getMethodCode(): string
    {
        return $this->methodCode;
    }

    public function getTitle(): string
    {
        return $this->title ?: $this->methodCode;
    }

    public function isAvailable(Quote $quote): bool
    {
        $path = 'payment/' . $this->methodCode . '/active';
        return (bool) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }
}
