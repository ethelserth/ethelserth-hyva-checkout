<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Address;

/**
 * Splits a full street string into street name and house number.
 *
 * Handles NL/DE/BE format: "Keizersgracht 100", "Keizersgracht 100-A", "Lange Leidsestraat 20 B".
 * The house number is the trailing numeric token (with optional alphanumeric suffix).
 * Everything before the number is the street name.
 */
class StreetSplitter
{
    /**
     * @return array{street: string, house_number: string, addition: string}
     */
    public function split(string $full): array
    {
        $full = trim($full);

        // Match: optional letters (street), then a number, then optional alphanumeric addition
        // Examples: "Keizersgracht 100", "Keizersgracht 100A", "Lange Weg 20-3", "Plein 4 B"
        if (preg_match('/^(.*?)\s+(\d+)\s*([A-Za-z0-9\-\/]*)$/', $full, $m)) {
            return [
                'street'       => trim($m[1]),
                'house_number' => trim($m[2]),
                'addition'     => trim($m[3]),
            ];
        }

        return [
            'street'       => $full,
            'house_number' => '',
            'addition'     => '',
        ];
    }
}
