<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment\Adapter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Adapter for all native Magento offline payment methods:
 *   checkmo, banktransfer, cashondelivery, free, purchaseorder.
 *
 * One instance per method, created by `MethodPool` from the static map.
 * All knobs (active flag, title, instructions, COD fee) are read directly
 * from the existing `payment/<code>/*` admin paths — we do not introduce a
 * parallel config tree.
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
        return $this->title !== '' ? $this->title : $this->methodCode;
    }

    public function isAvailable(Quote $quote): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'payment/' . $this->methodCode . '/active',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getInstructions(): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/' . $this->methodCode . '/instructions',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getFormTemplate(): ?string
    {
        return 'Ethelserth_Checkout::checkout/payment/offline/form.phtml';
    }

    /**
     * Cash-on-delivery is the only native offline method with a configurable
     * fee — `payment/cashondelivery/fee_amount`. Any other adapter that adds
     * a surcharge can override this hook.
     */
    public function getSurcharge(Quote $quote): float
    {
        if ($this->methodCode !== 'cashondelivery') {
            return 0.0;
        }

        $fee = (float) $this->scopeConfig->getValue(
            'payment/cashondelivery/fee_amount',
            ScopeInterface::SCOPE_STORE
        );

        return $fee > 0 ? $fee : 0.0;
    }
}
