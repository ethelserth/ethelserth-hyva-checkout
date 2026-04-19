<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Address\Lookup;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Postcoder.com address lookup provider.
 * API key is configured at: Stores > Config > Checkout > Ethelserth Checkout > Postcoder API Key
 */
class Postcoder implements LookupInterface
{
    private const BASE_URL  = 'https://api.postcoder.com/pcw/%s/address/%s/%s+%s';
    private const API_KEY_PATH = 'ethelserth_checkout/address_lookup/postcoder_api_key';

    public function __construct(
        private readonly Curl $curl,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
    ) {}

    public function lookup(string $postcode, string $houseNumber, string $countryId = 'NL'): array
    {
        $apiKey = (string) $this->scopeConfig->getValue(self::API_KEY_PATH, ScopeInterface::SCOPE_STORE);
        if (!$apiKey) {
            throw new LocalizedException(__('Postcoder API key is not configured.'));
        }

        $url = sprintf(
            self::BASE_URL,
            urlencode($apiKey),
            strtolower($countryId),
            urlencode(str_replace(' ', '', $postcode)),
            urlencode($houseNumber)
        );

        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->get($url);

        $status = $this->curl->getStatus();
        $body   = $this->curl->getBody();

        if ($status !== 200) {
            $this->logger->error('Postcoder API error', ['status' => $status, 'url' => $url]);
            throw new LocalizedException(__('Address lookup service is unavailable. Please enter your address manually.'));
        }

        $results = json_decode($body, true);
        if (!is_array($results) || empty($results)) {
            throw new LocalizedException(__('No address found for postcode %1 %2.', $postcode, $houseNumber));
        }

        $first = $results[0];

        return [
            'street'                  => $first['addressline1'] ?? $first['street'] ?? '',
            'house_number'            => $first['buildingnumber'] ?? $houseNumber,
            'house_number_addition'   => $first['subbuildingname'] ?? '',
            'city'                    => $first['posttown'] ?? $first['locality'] ?? '',
            'region'                  => $first['county'] ?? $first['province'] ?? '',
            'region_code'             => $first['county_code'] ?? '',
            'country_id'              => strtoupper($countryId),
        ];
    }
}
