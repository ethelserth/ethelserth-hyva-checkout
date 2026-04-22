<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Model\Address\FieldConfig;
use Ethelserth\Checkout\Model\Quote\QuoteService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\Country\Postcode\ConfigInterface as PostcodeConfig;
use Magento\Directory\Model\Country\Postcode\ValidatorInterface as PostcodeValidator;
use Magento\Directory\Model\ResourceModel\Country\Collection as CountryCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magewirephp\Magewire\Component;

class Address extends Component
{
    // ── Shipping address fields ───────────────────────────────────────────────

    public string $email      = '';
    public string $prefix     = '';
    public string $firstname  = '';
    public string $middlename = '';
    public string $lastname   = '';
    public string $suffix     = '';
    public string $company    = '';
    public string $vatId      = '';
    public string $street1    = '';
    public string $street2    = '';
    public string $street3    = '';
    public string $street4    = '';
    public string $city       = '';
    public string $region     = '';
    public string $regionId   = '';
    public string $postcode   = '';
    public string $countryId  = '';
    public string $telephone  = '';
    public string $fax        = '';

    // ── Billing address fields (used when billingSameAsShipping = false) ───────

    public string $billingPrefix     = '';
    public string $billingFirstname  = '';
    public string $billingLastname   = '';
    public string $billingCompany    = '';
    public string $billingStreet1    = '';
    public string $billingStreet2    = '';
    public string $billingCity       = '';
    public string $billingRegion     = '';
    public string $billingRegionId   = '';
    public string $billingPostcode   = '';
    public string $billingCountryId  = '';
    public string $billingTelephone  = '';

    // ── UI state ──────────────────────────────────────────────────────────────

    public bool   $billingSameAsShipping = true;
    public bool   $complete             = false;
    public bool   $isLoggedIn           = false;
    public string $errorMessage         = '';

    protected $listeners = [
        'stepEditRequested' => 'onEditRequested',
    ];

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly QuoteService $quoteService,
        private readonly FieldConfig $fieldConfig,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly CountryCollection $countryCollection,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PostcodeValidator $postcodeValidator,
        private readonly PostcodeConfig $postcodeConfig,
        private readonly DirectoryHelper $directoryHelper,
    ) {}

    public function boot(): void
    {
        $this->errorMessage = '';
        $this->isLoggedIn   = $this->customerSession->isLoggedIn();

        $defaultCountry = (string) $this->scopeConfig->getValue(
            'general/country/default',
            ScopeInterface::SCOPE_STORE
        ) ?: 'NL';

        $quote    = $this->checkoutSession->getQuote();
        $shipping = $quote->getShippingAddress();

        if ($shipping->getFirstname()) {
            $street = $shipping->getStreet() ?: [];
            $this->prefix     = (string) $shipping->getPrefix();
            $this->firstname  = (string) $shipping->getFirstname();
            $this->middlename = (string) $shipping->getMiddlename();
            $this->lastname   = (string) $shipping->getLastname();
            $this->suffix     = (string) $shipping->getSuffix();
            $this->company    = (string) $shipping->getCompany();
            $this->vatId      = (string) $shipping->getVatId();
            $this->street1    = $street[0] ?? '';
            $this->street2    = $street[1] ?? '';
            $this->street3    = $street[2] ?? '';
            $this->street4    = $street[3] ?? '';
            $this->city       = (string) $shipping->getCity();
            $this->postcode   = (string) $shipping->getPostcode();
            $this->countryId  = (string) ($shipping->getCountryId() ?: $defaultCountry);
            $this->telephone  = (string) $shipping->getTelephone();
            $this->fax        = (string) $shipping->getFax();
            $this->region     = (string) $shipping->getRegion();
            $this->regionId   = (string) $shipping->getRegionId();
        } else {
            $this->countryId        = $defaultCountry;
            $this->billingCountryId = $defaultCountry;
        }

        if ($quote->getCustomerEmail()) {
            $this->email = (string) $quote->getCustomerEmail();
        }
    }

    public function saveAddress(): void
    {
        $this->errorMessage = '';

        $this->telephone = preg_replace('/\D+/', '', $this->telephone) ?? '';
        if (!$this->billingSameAsShipping) {
            $this->billingTelephone = preg_replace('/\D+/', '', $this->billingTelephone) ?? '';
        }

        $errors = $this->validateFields();

        if (!empty($errors)) {
            $this->errorMessage = implode(' ', $errors);
            return;
        }

        $quote = $this->checkoutSession->getQuote();

        if ($this->email) {
            $quote->setCustomerEmail($this->email);
            $quote->setCustomerIsGuest(!$this->customerSession->isLoggedIn());
        }

        $streetLines = $this->fieldConfig->getStreetLines();
        $street = array_filter(
            array_slice([$this->street1, $this->street2, $this->street3, $this->street4], 0, $streetLines),
        );

        $this->quoteService->saveShippingAddress($quote, [
            'prefix'     => $this->prefix,
            'firstname'  => $this->firstname,
            'middlename' => $this->middlename,
            'lastname'   => $this->lastname,
            'suffix'     => $this->suffix,
            'company'    => $this->company,
            'vat_id'     => $this->vatId,
            'street'     => $street ?: [$this->street1],
            'city'       => $this->city,
            'region'     => $this->region,
            'region_id'  => $this->regionId ?: null,
            'postcode'   => $this->postcode,
            'country_id' => $this->countryId,
            'telephone'  => $this->telephone,
            'fax'        => $this->fax,
        ]);

        if ($this->billingSameAsShipping) {
            $this->quoteService->copyShippingToBilling($quote);
        } else {
            $billingStreet = array_filter([$this->billingStreet1, $this->billingStreet2]);
            $this->quoteService->saveBillingAddress($quote, [
                'prefix'     => $this->billingPrefix,
                'firstname'  => $this->billingFirstname,
                'lastname'   => $this->billingLastname,
                'company'    => $this->billingCompany,
                'street'     => $billingStreet ?: [$this->billingStreet1],
                'city'       => $this->billingCity,
                'region'     => $this->billingRegion,
                'region_id'  => $this->billingRegionId ?: null,
                'postcode'   => $this->billingPostcode,
                'country_id' => $this->billingCountryId,
                'telephone'  => $this->billingTelephone,
            ]);
        }

        // Fires VatValidator observer — assigns customer group for 0% intra-EU VAT if applicable
        $this->quoteService->collectAndSave($quote);

        $this->complete = true;
        $this->emit('addressSaved');
        $this->dispatchBrowserEvent('address-saved');
    }

    public function editAddress(): void
    {
        $this->complete = false;
        $this->emit('stepEditRequested', 'address');
        $this->dispatchBrowserEvent('step-edit-requested', 'address');
    }

    /**
     * Pre-fill form fields from a saved customer address.
     */
    public function selectSavedAddress(int $addressId): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        try {
            $address = $this->addressRepository->getById($addressId);
        } catch (LocalizedException) {
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if ((int) $address->getCustomerId() !== $customerId) {
            return;
        }

        $street = $address->getStreet() ?: [];

        $this->prefix     = (string) $address->getPrefix();
        $this->firstname  = (string) $address->getFirstname();
        $this->middlename = (string) $address->getMiddlename();
        $this->lastname   = (string) $address->getLastname();
        $this->suffix     = (string) $address->getSuffix();
        $this->company    = (string) $address->getCompany();
        $this->vatId      = (string) $address->getVatId();
        $this->street1    = $street[0] ?? '';
        $this->street2    = $street[1] ?? '';
        $this->city       = (string) $address->getCity();
        $this->postcode   = (string) $address->getPostcode();
        $defaultCountry   = (string) $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE) ?: 'NL';
        $this->countryId  = (string) ($address->getCountryId() ?: $defaultCountry);
        $this->telephone  = (string) $address->getTelephone();
        $this->fax        = (string) $address->getFax();
        $this->region     = (string) ($address->getRegion()?->getRegion() ?? '');
        $this->regionId   = (string) ($address->getRegionId() ?? '');
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Compact summary for the collapsed "done" view of the step.
     * Returns null until the address has been saved.
     *
     * @return array{
     *     name: string,
     *     email: string,
     *     company: string,
     *     street: string[],
     *     city_line: string,
     *     country: string,
     *     telephone: string,
     *     vat_id: string,
     *     billing_differs: bool,
     *     billing_lines: string[]
     * }|null
     */
    public function getAddressSummary(): ?array
    {
        if (!$this->complete || $this->firstname === '') {
            return null;
        }

        $name = trim(implode(' ', array_filter([
            $this->prefix, $this->firstname, $this->middlename, $this->lastname, $this->suffix,
        ])));

        $street = array_values(array_filter(
            [$this->street1, $this->street2, $this->street3, $this->street4],
            static fn(string $line): bool => $line !== '',
        ));

        $cityLine = implode(', ', array_filter([
            $this->city,
            $this->region,
            $this->postcode,
        ]));

        $billingLines = [];
        if (!$this->billingSameAsShipping) {
            $billingName = trim(implode(' ', array_filter([
                $this->billingPrefix, $this->billingFirstname, $this->billingLastname,
            ])));
            $billingStreet = array_filter([$this->billingStreet1, $this->billingStreet2]);
            $billingCity = implode(', ', array_filter([
                $this->billingCity, $this->billingRegion, $this->billingPostcode,
            ]));
            $billingLines = array_values(array_filter([
                $billingName,
                $this->billingCompany,
                ...$billingStreet,
                $billingCity,
                $this->getCountryName($this->billingCountryId),
                $this->billingTelephone,
            ]));
        }

        return [
            'name'            => $name,
            'email'           => $this->email,
            'company'         => $this->company,
            'street'          => $street,
            'city_line'       => $cityLine,
            'country'         => $this->getCountryName($this->countryId),
            'telephone'       => $this->telephone,
            'vat_id'          => $this->vatId,
            'billing_differs' => !$this->billingSameAsShipping,
            'billing_lines'   => $billingLines,
        ];
    }

    public function getCountryName(string $countryId): string
    {
        if ($countryId === '') {
            return '';
        }
        foreach ($this->getCountryOptions() as $option) {
            if ((string) ($option['value'] ?? '') === $countryId) {
                return (string) ($option['label'] ?? $countryId);
            }
        }
        return $countryId;
    }

    public function onEditRequested(string $step): void
    {
        if ($step !== 'address') {
            return;
        }
        $this->complete = false;
    }

    /**
     * Returns the FieldConfig for use in templates via $block->getData('field_config').
     * Called only during PHP rendering, not synced to frontend.
     */
    public function getFieldConfig(): FieldConfig
    {
        return $this->fieldConfig;
    }

    /**
     * Returns saved addresses for the logged-in customer.
     * Used in the saved-addresses template.
     *
     * @return \Magento\Customer\Api\Data\AddressInterface[]
     */
    public function getSavedAddresses(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return [];
        }
        $customer = $this->customerSession->getCustomerData();
        return $customer ? $customer->getAddresses() : [];
    }

    /**
     * Country options for the country selector.
     * Called during PHP rendering only.
     */
    public function getCountryOptions(): array
    {
        return $this->countryCollection
            ->loadByStore()
            ->toOptionArray(false);
    }

    /**
     * Region options for a given country code. Empty array = no predefined regions.
     * @return array<array{value: string, label: string, code: string}>
     */
    public function getRegionsForCountry(string $countryId): array
    {
        if (!$countryId) {
            return [];
        }
        $collection = $this->regionCollectionFactory->create()
            ->addCountryFilter($countryId)
            ->load();

        $regions = [];
        foreach ($collection as $region) {
            if ($region->getRegionId()) {
                $regions[] = [
                    'value' => (string) $region->getRegionId(),
                    'label' => (string) $region->getName(),
                    'code'  => (string) $region->getCode(),
                ];
            }
        }
        return $regions;
    }

    /**
     * Per-country postcode patterns + examples, suitable for client-side
     * live validation. Keyed by country code.
     *
     * Structure:
     *   [
     *     'GR' => [
     *       'optional' => false,
     *       'patterns' => [['pattern' => '^[0-9]{5}$', 'example' => '12345']]
     *     ],
     *     ...
     *   ]
     */
    public function getPostcodePatterns(): array
    {
        $out = [];
        foreach ($this->postcodeConfig->getPostCodes() as $country => $patterns) {
            if (!is_array($patterns)) {
                continue;
            }
            $items = [];
            foreach ($patterns as $entry) {
                if (!empty($entry['pattern'])) {
                    $items[] = [
                        'pattern' => (string) $entry['pattern'],
                        'example' => (string) ($entry['example'] ?? ''),
                    ];
                }
            }
            $out[$country] = [
                'optional' => $this->directoryHelper->isZipCodeOptional($country),
                'patterns' => $items,
            ];
        }
        return $out;
    }

    /**
     * Country → example national phone number (digits only).
     * Used client-side as the input placeholder and in validation hints.
     * Phones are stored digits-only; the example is a representative
     * national format. Unknown countries fall through to a generic hint.
     */
    public function getPhoneFormats(): array
    {
        return [
            'GR' => '2101234567',
            'GB' => '2012345678',
            'US' => '2025550123',
            'DE' => '3012345678',
            'FR' => '0123456789',
            'IT' => '0612345678',
            'ES' => '912345678',
            'NL' => '0201234567',
            'BE' => '21234567',
            'PT' => '211234567',
            'IE' => '12345678',
            'AT' => '1234567',
            'CH' => '441234567',
            'SE' => '812345678',
            'NO' => '21234567',
            'DK' => '32123456',
            'FI' => '912345678',
            'PL' => '221234567',
            'CZ' => '222123456',
            'RO' => '211234567',
            'HU' => '12345678',
            'BG' => '21234567',
            'HR' => '12345678',
            'SK' => '212345678',
            'SI' => '12345678',
            'LT' => '51234567',
            'LV' => '61234567',
            'EE' => '5123456',
            'LU' => '27123456',
            'MT' => '21234567',
            'CY' => '22123456',
        ];
    }

    /**
     * Whether region/state is required for the given country.
     * Driven by Stores > Config > General > State Options > State is Required for.
     */
    public function isRegionRequired(string $countryId): bool
    {
        $required = explode(',', (string) $this->scopeConfig->getValue(
            'general/region/state_required',
            ScopeInterface::SCOPE_STORE
        ));
        return in_array($countryId, $required, true);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateFields(): array
    {
        $errors = [];

        if (!$this->email) {
            $errors[] = (string) __('Email address is required.');
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = (string) __('Please enter a valid email address.');
        }

        $alwaysRequired = [
            'firstname' => __('First name'),
            'lastname'  => __('Last name'),
            'street1'   => __('Street address'),
            'city'      => __('City'),
            'countryId' => __('Country'),
        ];
        foreach ($alwaysRequired as $prop => $label) {
            if (empty($this->$prop)) {
                $errors[] = (string) __('%1 is required.', $label);
            }
        }

        $postcodeError = $this->validatePostcodeFor($this->countryId, $this->postcode, __('Postcode'));
        if ($postcodeError !== null) {
            $errors[] = $postcodeError;
        }

        $configDriven = [
            'prefix'     => ['prop' => 'prefix',     'label' => __('Prefix')],
            'middlename' => ['prop' => 'middlename',  'label' => __('Middle name')],
            'suffix'     => ['prop' => 'suffix',      'label' => __('Suffix')],
            'company'    => ['prop' => 'company',     'label' => __('Company')],
            'vat_id'     => ['prop' => 'vatId',       'label' => __('VAT number')],
            'telephone'  => ['prop' => 'telephone',   'label' => __('Phone number')],
            'fax'        => ['prop' => 'fax',         'label' => __('Fax')],
        ];
        foreach ($configDriven as $code => $meta) {
            if ($this->fieldConfig->isFieldRequired($code) && empty($this->{$meta['prop']})) {
                $errors[] = (string) __('%1 is required.', $meta['label']);
            }
        }

        if (!$this->billingSameAsShipping) {
            if (empty($this->billingFirstname)) {
                $errors[] = (string) __('Billing first name is required.');
            }
            if (empty($this->billingLastname)) {
                $errors[] = (string) __('Billing last name is required.');
            }
            if (empty($this->billingStreet1)) {
                $errors[] = (string) __('Billing street address is required.');
            }
            if (empty($this->billingCity)) {
                $errors[] = (string) __('Billing city is required.');
            }
            $billingPostcodeError = $this->validatePostcodeFor(
                $this->billingCountryId,
                $this->billingPostcode,
                __('Billing postcode')
            );
            if ($billingPostcodeError !== null) {
                $errors[] = $billingPostcodeError;
            }
        }

        return $errors;
    }

    /**
     * Validates postcode presence and format against the country's native pattern
     * (defined in zip_codes.xml). Countries in general/country/optional_zip_countries
     * may omit the postcode entirely.
     *
     * Returns the error string, or null if valid.
     */
    private function validatePostcodeFor(string $countryId, string $postcode, $label): ?string
    {
        $postcode = trim($postcode);
        $postcodeOptional = $countryId !== ''
            && $this->directoryHelper->isZipCodeOptional($countryId);

        if ($postcode === '') {
            return $postcodeOptional
                ? null
                : (string) __('%1 is required.', $label);
        }

        if ($countryId === '') {
            return null;
        }

        try {
            if (!$this->postcodeValidator->validate($postcode, $countryId)) {
                return (string) __(
                    'Please enter a valid %1 for the selected country.',
                    strtolower((string) $label)
                );
            }
        } catch (\InvalidArgumentException) {
            // Country has no pattern configured — treat as valid (same behaviour as Luma).
        }

        return null;
    }
}
