<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Address\Lookup;

/**
 * Contract for postcode lookup providers.
 *
 * Implementations must return a flat array with keys:
 *   street, house_number, house_number_addition, city, region, region_code, country_id
 * Missing keys are allowed — caller must handle gracefully.
 */
interface LookupInterface
{
    /**
     * Look up an address by postcode and house number.
     *
     * @param  string $postcode     e.g. "1234AB"
     * @param  string $houseNumber  e.g. "10" or "10A"
     * @param  string $countryId    ISO-2, e.g. "NL"
     * @return array{street?:string, house_number?:string, house_number_addition?:string, city?:string, region?:string, region_code?:string, country_id?:string}
     * @throws \Magento\Framework\Exception\LocalizedException  on API error
     */
    public function lookup(string $postcode, string $houseNumber, string $countryId = 'NL'): array;
}
