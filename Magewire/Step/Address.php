<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Quote\QuoteService;
use Magewirephp\Magewire\Component;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Address step Magewire component.
 * Phase 1: stub — renders the address form shell.
 * Phase 2: full implementation with validation, VAT, billing toggle.
 */
class Address extends Component
{
    // ── Public properties (Magewire sends these to the frontend) ──────────

    public string $email = '';
    public string $firstname = '';
    public string $lastname = '';
    public string $company = '';
    public string $vatId = '';
    public string $street1 = '';
    public string $street2 = '';
    public string $city = '';
    public string $region = '';
    public string $regionId = '';
    public string $postcode = '';
    public string $countryId = 'NL';
    public string $telephone = '';

    public bool $billingSameAsShipping = true;
    public bool $complete = false;

    // Billing fields (only used when billingSameAsShipping = false)
    public string $billingFirstname = '';
    public string $billingLastname = '';
    public string $billingStreet1 = '';
    public string $billingCity = '';
    public string $billingPostcode = '';
    public string $billingCountryId = 'NL';

    protected $listeners = [
        'stepEditRequested' => 'onEditRequested',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteService $quoteService,
    ) {}

    public function boot(): void
    {
        // Pre-fill from existing quote address on page load
        $quote    = $this->checkoutSession->getQuote();
        $shipping = $quote->getShippingAddress();

        if ($shipping->getFirstname()) {
            $this->firstname  = (string) $shipping->getFirstname();
            $this->lastname   = (string) $shipping->getLastname();
            $this->company    = (string) $shipping->getCompany();
            $this->vatId      = (string) $shipping->getVatId();
            $street = $shipping->getStreet();
            $this->street1    = $street[0] ?? '';
            $this->street2    = $street[1] ?? '';
            $this->city       = (string) $shipping->getCity();
            $this->postcode   = (string) $shipping->getPostcode();
            $this->countryId  = (string) ($shipping->getCountryId() ?: 'NL');
            $this->telephone  = (string) $shipping->getTelephone();
            $this->region     = (string) $shipping->getRegion();
            $this->regionId   = (string) $shipping->getRegionId();
        }

        if ($quote->getCustomerEmail()) {
            $this->email = (string) $quote->getCustomerEmail();
        }
    }

    public function saveAddress(): void
    {
        // Basic required-field validation (full FieldConfig validation in Phase 2)
        if (!$this->firstname || !$this->lastname || !$this->postcode || !$this->countryId) {
            $this->dispatchErrorMessage((string) __('Please fill in all required fields.'));
            return;
        }

        $quote = $this->checkoutSession->getQuote();

        if ($this->email) {
            $quote->setCustomerEmail($this->email);
        }

        $this->quoteService->saveShippingAddress($quote, [
            'firstname'  => $this->firstname,
            'lastname'   => $this->lastname,
            'company'    => $this->company,
            'vat_id'     => $this->vatId,
            'street'     => array_filter([$this->street1, $this->street2]),
            'city'       => $this->city,
            'region'     => $this->region,
            'region_id'  => $this->regionId ?: null,
            'postcode'   => $this->postcode,
            'country_id' => $this->countryId,
            'telephone'  => $this->telephone,
        ]);

        if ($this->billingSameAsShipping) {
            $this->quoteService->copyShippingToBilling($quote);
        } else {
            $this->quoteService->saveBillingAddress($quote, [
                'firstname'  => $this->billingFirstname,
                'lastname'   => $this->billingLastname,
                'street'     => [$this->billingStreet1],
                'city'       => $this->billingCity,
                'postcode'   => $this->billingPostcode,
                'country_id' => $this->billingCountryId,
            ]);
        }

        // Collect totals — fires VatValidator observer if vat_id is present
        $this->quoteService->collectAndSave($quote);

        $this->complete = true;
        $this->emit('addressSaved');
    }

    public function editAddress(): void
    {
        $this->complete = false;
        $this->emit('stepEditRequested', 'address');
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function onEditRequested(string $step): void
    {
        if ($step !== 'address') {
            return;
        }
        $this->complete = false;
    }
}
