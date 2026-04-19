<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Controller\Postcode;

use Ethelserth\Checkout\Model\Address\PostcodeLookup;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * AJAX endpoint: GET /checkout/postcode/lookup?postcode=1234AB&house_number=10
 * Returns JSON: { success: true, street, house_number, house_number_addition, city, region, region_code }
 */
class Lookup implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly PostcodeLookup $postcodeLookup,
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $postcode    = trim((string) $this->request->getParam('postcode', ''));
        $houseNumber = trim((string) $this->request->getParam('house_number', ''));
        $countryId   = strtoupper(trim((string) $this->request->getParam('country_id', 'NL')));

        if (!$postcode || !$houseNumber) {
            return $result->setData([
                'success' => false,
                'error'   => (string) __('Postcode and house number are required.'),
            ]);
        }

        try {
            $address = $this->postcodeLookup->lookup($postcode, $houseNumber, $countryId);
            return $result->setData(array_merge(['success' => true], $address));
        } catch (LocalizedException $e) {
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error'   => (string) __('Address lookup failed. Please enter your address manually.'),
            ]);
        }
    }
}
