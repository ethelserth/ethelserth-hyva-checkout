<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Magewire\Step;

use Ethelserth\Checkout\Magewire\Concern\HasOrderComments;
use Ethelserth\Checkout\Model\Address\FieldConfig;
use Ethelserth\Checkout\Model\OrderComments\Sanitizer as OrderCommentsSanitizer;
use Ethelserth\Checkout\Model\Quote\QuoteService;
use Ethelserth\Checkout\ViewModel\OrderCommentsConfig;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
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
    use HasOrderComments;

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

    // ── Email / inline-sign-in state ──────────────────────────────────────────
    // Mirrors Magento's Luma checkout: once the shopper blurs the email
    // field, we ask `AccountManagementInterface::isEmailAvailable()` whether
    // an account exists. If yes, the form reveals an OPTIONAL password
    // field. Filled + correct → sign in mid-checkout. Filled + wrong →
    // inline error, do not advance. Left blank → continue as guest with
    // that email (Magento accepts a guest order on an existing-customer
    // email; the orders just aren't linked to the customer).

    public bool   $emailHasAccount     = false;
    public bool   $emailChecked        = false;
    public string $password            = '';
    public string $passwordError       = '';

    /**
     * Save-in-address-book opt-in. Default ON — Luma parity. The
     * checkbox is only RENDERED when the form values don't already
     * match a saved address (no point asking when it's the same one
     * the shopper picked from the dropdown). The component still
     * holds this property for guest-checkout sessions; it's a no-op
     * in `saveAddress` when `isLoggedIn === false`.
     */
    public bool $saveInAddressBook = true;

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
        private readonly OrderCommentsSanitizer $orderCommentsSanitizer,
        private readonly OrderCommentsConfig $orderCommentsConfig,
        private readonly AccountManagementInterface $accountManagement,
        private readonly StoreManagerInterface $storeManager,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly AddressInterfaceFactory $customerAddressFactory,
        private readonly RegionInterfaceFactory $customerRegionFactory,
        private readonly LoggerInterface $logger,
    ) {}

    // ── HasOrderComments trait wiring ─────────────────────────────────────────
    protected function getOrderCommentsCheckoutSession(): CheckoutSession        { return $this->checkoutSession; }
    protected function getOrderCommentsQuoteService(): QuoteService              { return $this->quoteService; }
    protected function getOrderCommentsSanitizer(): OrderCommentsSanitizer       { return $this->orderCommentsSanitizer; }
    protected function getOrderCommentsConfig(): OrderCommentsConfig             { return $this->orderCommentsConfig; }

    public function boot(): void
    {
        $this->errorMessage = '';
        $this->isLoggedIn   = $this->customerSession->isLoggedIn();
        $this->bootOrderComments();

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

    /**
     * Magewire action — fired on email-field blur via Alpine
     * `@blur="$wire.checkEmail"`. Asks Magento whether an account
     * already exists for the entered email; if yes, the form will
     * reveal an OPTIONAL inline password field.
     *
     * Skipped when:
     *   - the shopper is already logged in (no point asking)
     *   - the email is malformed (saves the API round-trip)
     *
     * The check is intentionally idempotent — it runs every time the
     * field blurs. If the shopper edits the email after the first
     * check, the second blur re-evaluates against the new value.
     */
    public function checkEmail(): void
    {
        $this->passwordError = '';

        if ($this->customerSession->isLoggedIn()) {
            $this->emailHasAccount = false;
            $this->emailChecked    = true;
            return;
        }

        $email = trim($this->email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->emailHasAccount = false;
            $this->emailChecked    = false;
            return;
        }

        try {
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            // `isEmailAvailable` returns TRUE when no account exists for
            // that email on the website (= "available to register"), so
            // an existing-account is the FALSE branch.
            $this->emailHasAccount = !$this->accountManagement->isEmailAvailable($email, $websiteId);
        } catch (\Throwable $e) {
            // Network blip / DB blip — don't block the shopper. They
            // can still proceed as guest; we'll just skip the
            // sign-in affordance for this round-trip.
            $this->emailHasAccount = false;
        }

        $this->emailChecked = true;
    }

    /**
     * Magewire action — fired by the explicit "Sign in" button inside
     * the inline-sign-in panel. Authenticates against the entered
     * email + password; on success, dispatches `checkout-reload` so
     * the root template performs a full page reload (the cleanest way
     * to surface saved addresses, customer-group VAT recalculation,
     * and quote merging without trying to swap state in place).
     *
     * On failure: `passwordError` is set, no event dispatched. The
     * shopper stays on the page with the password field still visible.
     *
     * `saveAddress` deliberately does NOT call this — the Continue
     * button is the guest-checkout path. If a shopper fills the
     * password and clicks Continue without first clicking Sign in,
     * Continue ignores the password and the order is created as a
     * guest order with that email (Magento accepts this; the order
     * just isn't linked to the customer record).
     */
    public function signIn(): void
    {
        $this->passwordError = '';

        if ($this->customerSession->isLoggedIn()) {
            // Already logged in — defensive guard. Just trigger the
            // reload so the UI reflects state.
            $this->dispatchBrowserEvent('checkout-reload');
            return;
        }

        if (trim($this->password) === '') {
            $this->passwordError = (string) __('Please enter your password to sign in.');
            return;
        }

        if (!$this->attemptSignIn()) {
            return;
        }

        // Auth + customer attach succeeded. Force a full page reload
        // so the entire checkout re-renders against the now-logged-in
        // session. Anything in-place (saved-address picker hydration,
        // customer-group VAT flip, quote merge) becomes free.
        $this->dispatchBrowserEvent('checkout-reload');
    }

    public function saveAddress(): void
    {
        $this->errorMessage  = '';
        // NOTE: passwordError is NOT cleared here — Continue is the
        // guest-checkout path and intentionally ignores the password.
        // Clearing it would also clear an in-flight failed-sign-in
        // message before the shopper has read it.

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

        // Stamp the deferred order_comments value onto the quote BEFORE
        // collectAndSave persists. Without this, a comment typed in the
        // Address-step placement would never reach the column —
        // `wire:blur` isn't a Magewire directive, so the only round-trip
        // carrying the value is this one (saveAddress).
        $this->applyOrderCommentsToQuote();

        // Fires VatValidator observer — assigns customer group for 0% intra-EU VAT if applicable
        $this->quoteService->collectAndSave($quote);

        // Save-in-address-book (Luma parity). Logged-in shoppers can
        // tick the checkbox above the submit to add the just-entered
        // address to their account's address book. Skipped when:
        //   - guest checkout (no customer to attach to)
        //   - opt-out (checkbox unticked)
        //   - the form values match a saved address (no duplicates)
        // Failures are logged and swallowed — they MUST NOT roll back
        // the just-saved quote address; the customer can re-save the
        // address from their account if it didn't auto-save.
        if ($this->customerSession->isLoggedIn()
            && $this->saveInAddressBook
            && $this->findMatchingSavedAddress() === null
        ) {
            $this->createCustomerAddressFromForm();
        }

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
     * Authenticate the shopper against an existing account, attach the
     * customer to the live quote, and regenerate the session id.
     *
     * Returns TRUE on success (caller continues with `saveAddress`),
     * FALSE on auth failure (caller has already abandoned via early
     * return; `passwordError` carries the user-facing message).
     *
     * Catches the broadest possible exception surface and treats every
     * failure as a "bad credentials" message. We don't distinguish
     * account-locked / disabled / wrong-password / no-such-email in
     * the UI — that's the same anti-enumeration posture Magento's
     * own Luma checkout uses.
     */
    private function attemptSignIn(): bool
    {
        try {
            $customer = $this->accountManagement->authenticate(
                trim($this->email),
                $this->password
            );
        } catch (\Throwable $e) {
            $this->passwordError = (string) __(
                'The email or password you entered is incorrect. '
                . 'Leave the password blank to continue as a guest, or try again.'
            );
            return false;
        }

        $this->customerSession->setCustomerDataAsLoggedIn($customer);
        $this->customerSession->regenerateId();
        $this->attachCustomerToQuote($customer);

        // Clear sign-in state — the shopper is authenticated, the
        // password block must collapse, and a future page reload
        // shouldn't re-prompt.
        $this->password         = '';
        $this->emailHasAccount  = false;
        $this->isLoggedIn       = true;
        $this->passwordError    = '';

        return true;
    }

    /**
     * Bind the freshly-authenticated customer onto the active quote so
     * the order is created against their customer_id (not as a guest)
     * and so saved addresses become available downstream.
     */
    private function attachCustomerToQuote(CustomerInterface $customer): void
    {
        $quote = $this->checkoutSession->getQuote();
        $quote->setCustomerId((int) $customer->getId());
        $quote->setCustomerEmail((string) $customer->getEmail());
        $quote->setCustomerFirstname((string) $customer->getFirstname());
        $quote->setCustomerLastname((string) $customer->getLastname());
        $quote->setCustomerGroupId((int) $customer->getGroupId());
        $quote->setCustomerIsGuest(false);

        try {
            $this->cartRepository->save($quote);
        } catch (NoSuchEntityException) {
            // Session expired between login and save — fall through;
            // the caller's saveAddress path will surface the right
            // error if the quote is genuinely gone.
        }
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
     * Whether the "Save in my address book" wrapper should be in the
     * DOM at all. True for logged-in customers; the actual show/hide
     * inside it is driven by Alpine against live `$wire.*` values
     * because the address fields are `wire:model.defer` and would
     * NOT trigger a round-trip on every keystroke. A purely server-
     * side gate would only update when the form posts — too late.
     */
    public function getShowSaveInAddressBook(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * JSON array of fingerprints for every saved address on the
     * customer record, ready to drop into an Alpine `x-data` block.
     * The string format MUST match `normalizedAddressFingerprint`
     * exactly — that's the parity the JS-side fingerprint helper
     * in `step/address.phtml` re-implements.
     */
    public function getSavedAddressFingerprintsJson(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '[]';
        }
        $fingerprints = [];
        foreach ($this->getSavedAddresses() as $saved) {
            $fingerprints[] = $this->normalizedAddressFingerprint(
                $saved->getStreet() ?: [],
                (string) $saved->getCity(),
                (string) $saved->getPostcode(),
                (string) $saved->getCountryId(),
                $saved->getRegionId() ? (int) $saved->getRegionId() : null
            );
        }
        return json_encode($fingerprints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Returns the saved address that matches the current form values,
     * or null if the form represents a new address.
     *
     * Match heuristic: the fields that semantically define an address
     * — street, city, postcode, country, region (when set). Excludes
     * prefix / middlename / suffix / company / phone — those are too
     * noisy (a shopper adding a middle name on this order shouldn't
     * trigger a duplicate save).
     */
    private function findMatchingSavedAddress(): ?AddressInterface
    {
        $current = $this->normalizedAddressFingerprint(
            array_filter([$this->street1, $this->street2, $this->street3, $this->street4]),
            $this->city,
            $this->postcode,
            $this->countryId,
            $this->regionId !== '' ? (int) $this->regionId : null
        );

        foreach ($this->getSavedAddresses() as $saved) {
            $candidate = $this->normalizedAddressFingerprint(
                $saved->getStreet() ?: [],
                (string) $saved->getCity(),
                (string) $saved->getPostcode(),
                (string) $saved->getCountryId(),
                $saved->getRegionId() ? (int) $saved->getRegionId() : null
            );
            if ($current === $candidate) {
                return $saved;
            }
        }
        return null;
    }

    /**
     * Build a deterministic fingerprint string from the address-defining
     * fields. Unicode-normalised, lowercase, whitespace-collapsed —
     * "Main Street" and "main  street" hash to the same value.
     */
    private function normalizedAddressFingerprint(
        array $streetLines,
        string $city,
        string $postcode,
        string $countryId,
        ?int $regionId
    ): string {
        $clean = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            return (string) preg_replace('/\s+/u', ' ', $s);
        };
        $streetJoined = $clean(implode(' ', array_filter($streetLines)));
        return implode('|', [
            $streetJoined,
            $clean($city),
            $clean($postcode),
            $clean($countryId),
            $regionId ?? '',
        ]);
    }

    /**
     * Build a customer address from the current form fields and persist
     * via the address repository. First saved address (no others on
     * file) becomes the default for both shipping AND billing —
     * consistent with Luma's first-save behaviour and saves the
     * customer a second click in their account.
     *
     * Wrapped broadly: a failure here MUST NOT cascade back into the
     * already-saved quote address. Worst case: log + swallow, customer
     * can save it manually from their account.
     */
    private function createCustomerAddressFromForm(): void
    {
        try {
            /** @var AddressInterface $address */
            $address = $this->customerAddressFactory->create();
            $address->setCustomerId((int) $this->customerSession->getCustomerId());
            $address->setFirstname($this->firstname);
            $address->setLastname($this->lastname);

            if ($this->prefix !== '')     { $address->setPrefix($this->prefix); }
            if ($this->middlename !== '') { $address->setMiddlename($this->middlename); }
            if ($this->suffix !== '')     { $address->setSuffix($this->suffix); }
            if ($this->company !== '')    { $address->setCompany($this->company); }
            if ($this->vatId !== '')      { $address->setVatId($this->vatId); }

            $streetLines = array_filter([$this->street1, $this->street2, $this->street3, $this->street4]);
            $address->setStreet($streetLines ?: [$this->street1]);

            $address->setCity($this->city);
            $address->setPostcode($this->postcode);
            $address->setCountryId($this->countryId);
            $address->setTelephone($this->telephone);
            if ($this->fax !== '') {
                $address->setFax($this->fax);
            }

            if ($this->regionId !== '') {
                $region = $this->customerRegionFactory->create();
                $region->setRegionId((int) $this->regionId);
                if ($this->region !== '') {
                    $region->setRegion($this->region);
                }
                $address->setRegion($region);
            }

            // First address on file → default for shipping AND billing.
            // Saves the customer a trip to their account to set defaults.
            if (empty($this->getSavedAddresses())) {
                $address->setIsDefaultShipping(true);
                $address->setIsDefaultBilling(true);
            }

            $this->addressRepository->save($address);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Ethelserth_Checkout] save-in-address-book failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
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
