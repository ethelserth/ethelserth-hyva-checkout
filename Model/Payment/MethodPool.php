<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment;

use Ethelserth\Checkout\Model\Payment\Adapter\AdapterInterface;
use Ethelserth\Checkout\Model\Payment\Adapter\Offline;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Registry of payment adapters.
 *
 * Offline methods are auto-discovered (each enabled `payment/<code>/active`
 * spawns one shared `Offline` adapter instance). PSP modules append their
 * own adapters via etc/di.xml — see the `Ethelserth\Checkout\Model\Payment\MethodPool`
 * `adapters` argument.
 */
class MethodPool
{
    private const OFFLINE_METHODS = [
        'checkmo'        => 'Check / Money order',
        'banktransfer'   => 'Bank Transfer Payment',
        'cashondelivery' => 'Cash On Delivery',
        'free'           => 'No Payment Information Required',
        'purchaseorder'  => 'Purchase Order',
    ];

    /** @var AdapterInterface[]|null */
    private ?array $resolved = null;

    /**
     * @param AdapterInterface[] $adapters Additional adapters registered via di.xml.
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly array $adapters = [],
    ) {}

    /**
     * Methods available for the given quote, materialised as arrays so a
     * Magewire component can hold them in a public property.
     *
     * @return array<int, array{
     *     code: string,
     *     title: string,
     *     icon: string,
     *     instructions: string,
     *     form_template: ?string,
     *     surcharge: float,
     *     surcharge_formatted: string
     * }>
     */
    public function getAvailableMethods(Quote $quote): array
    {
        $result = [];
        foreach ($this->getAdapters() as $adapter) {
            if (!$adapter->isAvailable($quote)) {
                continue;
            }

            $surcharge = $adapter->getSurcharge($quote);

            $result[] = [
                'code'                => $adapter->getMethodCode(),
                'title'               => $adapter->getTitle(),
                'icon'                => $adapter->getIconUrl(),
                'instructions'        => $adapter->getInstructions(),
                'form_template'       => $adapter->getFormTemplate(),
                'surcharge'           => $surcharge,
                'surcharge_formatted' => $surcharge > 0
                    ? '+' . $this->priceCurrency->format($surcharge, false)
                    : '',
            ];
        }
        return $result;
    }

    /** @return AdapterInterface[] */
    public function getAdapters(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $this->resolved = [];

        // Offline methods (auto-discovered from `payment/<code>/active`).
        foreach (self::OFFLINE_METHODS as $code => $defaultTitle) {
            $configTitle = (string) $this->scopeConfig->getValue(
                "payment/{$code}/title",
                ScopeInterface::SCOPE_STORE
            );
            $this->resolved[] = $this->objectManager->create(Offline::class, [
                'methodCode' => $code,
                'title'      => $configTitle !== '' ? $configTitle : $defaultTitle,
            ]);
        }

        // PSP adapters injected via di.xml.
        foreach ($this->adapters as $adapter) {
            if ($adapter instanceof AdapterInterface) {
                $this->resolved[] = $adapter;
            }
        }

        return $this->resolved;
    }

    public function getAdapter(string $methodCode): ?AdapterInterface
    {
        foreach ($this->getAdapters() as $adapter) {
            if ($adapter->getMethodCode() === $methodCode) {
                return $adapter;
            }
        }
        return null;
    }
}
