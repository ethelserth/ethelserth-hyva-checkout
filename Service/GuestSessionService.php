<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;

class GuestSessionService
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
    ) {}

    public function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

    public function getQuoteId(): ?int
    {
        $quote = $this->getQuote();
        return $quote->getId() ? (int) $quote->getId() : null;
    }
}
