<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Quote;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\AddressInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Rate;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for all quote mutations in the checkout.
 * Magewire components never call quote repositories directly.
 */
class QuoteService
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly CartManagementInterface $cartManagement,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Save shipping (and optionally billing) address to quote.
     * Does NOT call collectTotals — caller must do so explicitly.
     */
    public function saveShippingAddress(Quote $quote, array $addressData): void
    {
        $shipping = $quote->getShippingAddress();
        $this->populateAddress($shipping, $addressData);
        $shipping->setCollectShippingRates(true);
    }

    /**
     * Copy shipping address to billing address.
     */
    public function copyShippingToBilling(Quote $quote): void
    {
        $shipping = $quote->getShippingAddress();
        $billing  = $quote->getBillingAddress();

        $billing->setSameAsBilling(true);
        foreach ([
            'firstname', 'lastname', 'company', 'street', 'city',
            'region', 'region_id', 'postcode', 'country_id',
            'telephone', 'fax', 'vat_id',
        ] as $field) {
            $billing->setData($field, $shipping->getData($field));
        }
    }

    /**
     * Save a separate billing address (when billing ≠ shipping).
     */
    public function saveBillingAddress(Quote $quote, array $addressData): void
    {
        $billing = $quote->getBillingAddress();
        $this->populateAddress($billing, $addressData);
        $billing->setSameAsBilling(false);
    }

    /**
     * Set shipping method on the quote.
     */
    public function setShippingMethod(Quote $quote, string $carrierCode, string $methodCode): void
    {
        $shipping = $quote->getShippingAddress();
        $shipping->setShippingMethod($carrierCode . '_' . $methodCode);
    }

    /**
     * Set payment method code on the quote.
     */
    public function setPaymentMethod(Quote $quote, string $methodCode, array $additionalData = []): void
    {
        $payment = $quote->getPayment();
        $payment->setMethod($methodCode);
        if ($additionalData) {
            $payment->setAdditionalInformation($additionalData);
        }
    }

    /**
     * Collect totals. This fires the VatValidator observer automatically.
     * Must be called after address save for VAT group assignment to take effect.
     */
    public function collectTotals(Quote $quote): void
    {
        $quote->collectTotals();
    }

    /**
     * Persist the quote.
     */
    public function save(Quote $quote): void
    {
        $this->cartRepository->save($quote);
    }

    /**
     * Collect totals and persist in one call.
     */
    public function collectAndSave(Quote $quote): void
    {
        $this->collectTotals($quote);
        $this->save($quote);
    }

    /**
     * Force a fresh rate collection — runs every enabled carrier's rating call.
     * Expensive for real-time carriers (UPS/FedEx/DHL/PostNL). Only call this
     * when the address has changed or no rates are persisted yet.
     *
     * @return Rate[]
     */
    public function getShippingRates(Quote $quote): array
    {
        $shipping = $quote->getShippingAddress();
        $shipping->setCollectShippingRates(true);
        $shipping->collectShippingRates();

        return $this->getPersistedShippingRates($quote);
    }

    /**
     * Read rates already persisted on the quote shipping address. Does NOT
     * contact carriers. Safe to call on every Magewire round-trip.
     *
     * @return Rate[]
     */
    public function getPersistedShippingRates(Quote $quote): array
    {
        $grouped = $quote->getShippingAddress()->getGroupedAllShippingRates();
        return $grouped ? array_merge(...array_values($grouped)) : [];
    }

    /**
     * Place the order and return the order ID.
     */
    public function placeOrder(Quote $quote): int
    {
        return (int) $this->cartManagement->placeOrder($quote->getId());
    }

    private function populateAddress(\Magento\Quote\Model\Quote\Address $address, array $data): void
    {
        $scalarFields = [
            'firstname', 'lastname', 'company', 'city',
            'region', 'region_id', 'postcode', 'country_id',
            'telephone', 'fax', 'vat_id', 'prefix', 'suffix', 'middlename',
        ];

        foreach ($scalarFields as $field) {
            if (array_key_exists($field, $data)) {
                $address->setData($field, $data[$field]);
            }
        }

        if (isset($data['street'])) {
            $street = is_array($data['street']) ? $data['street'] : [$data['street']];
            $address->setStreet($street);
        }
    }
}
