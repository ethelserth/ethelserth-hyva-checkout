<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Address;

use Ethelserth\Checkout\Model\Address\Lookup\LookupInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Application-level postcode lookup service.
 * Delegates to the configured LookupInterface provider and applies StreetSplitter
 * when the provider does not return a pre-split street + house number.
 */
class PostcodeLookup
{
    public function __construct(
        private readonly LookupInterface $provider,
        private readonly StreetSplitter $splitter,
    ) {}

    /**
     * @return array{street:string, house_number:string, house_number_addition:string, city:string, region:string, region_code:string, country_id:string}
     * @throws LocalizedException
     */
    public function lookup(string $postcode, string $houseNumber, string $countryId = 'NL'): array
    {
        $result = $this->provider->lookup(
            trim($postcode),
            trim($houseNumber),
            strtoupper($countryId)
        );

        if (empty($result['street']) && !empty($result['full_street'])) {
            $split = $this->splitter->split($result['full_street']);
            $result['street']       = $split['street'];
            $result['house_number'] = $split['house_number'] ?: $houseNumber;
        }

        return [
            'street'                => (string) ($result['street'] ?? ''),
            'house_number'          => (string) ($result['house_number'] ?? $houseNumber),
            'house_number_addition' => (string) ($result['house_number_addition'] ?? ''),
            'city'                  => (string) ($result['city'] ?? ''),
            'region'                => (string) ($result['region'] ?? ''),
            'region_code'           => (string) ($result['region_code'] ?? ''),
            'country_id'            => (string) ($result['country_id'] ?? $countryId),
        ];
    }
}
