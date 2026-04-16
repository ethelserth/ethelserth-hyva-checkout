<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote;

class CustomerSessionService
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
    ) {}

    public function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    public function getCustomerId(): ?int
    {
        $id = $this->customerSession->getCustomerId();
        return $id ? (int) $id : null;
    }
}
