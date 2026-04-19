<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Address;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads Stores > Config > Customers > Customer Configuration > Name and Address Options.
 * All address field visibility and required-status is driven by this — nothing hardcoded in templates.
 * Implements ArgumentInterface so it can be injected as xsi:type="object" in layout XML.
 */
class FieldConfig implements ArgumentInterface
{
    private const CONFIG_PATHS = [
        'prefix'     => 'customer/address/prefix_show',
        'middlename' => 'customer/address/middlename_show',
        'suffix'     => 'customer/address/suffix_show',
        'dob'        => 'customer/address/dob_show',
        'vat_id'     => 'customer/address/taxvat_show',
        'gender'     => 'customer/address/gender_show',
        'fax'        => 'customer/address/fax_show',
        'telephone'  => 'customer/address/telephone_show',
        'company'    => 'customer/address/company_show',
        'street_lines' => 'customer/address/street_lines',
    ];

    /**
     * Fields that default to visible when no config path exists or config is empty.
     * Value mirrors Magento's default: 'req' = required, 'opt' = optional.
     */
    private const FALLBACKS = [
        'telephone' => 'req',
        'company'   => 'opt',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {}

    public function isFieldVisible(string $code): bool
    {
        $value = $this->resolve($code);
        return !in_array($value, ['', '0', 'no', null, false], true);
    }

    public function isFieldRequired(string $code): bool
    {
        return $this->resolve($code) === 'req';
    }

    public function isPostcodeLookupEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'ethelserth_checkout/address_lookup/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Number of street address lines (1–4, default 2).
     */
    public function getStreetLines(): int
    {
        $v = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATHS['street_lines'],
            ScopeInterface::SCOPE_STORE
        );
        return max(1, min(4, $v ?: 2));
    }

    private function resolve(string $code): mixed
    {
        if (!isset(self::CONFIG_PATHS[$code])) {
            return null;
        }
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PATHS[$code],
            ScopeInterface::SCOPE_STORE
        );
        if ($value === null || $value === '') {
            return self::FALLBACKS[$code] ?? null;
        }
        return $value;
    }
}
