<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Payment;

use Ethelserth\Checkout\Model\Payment\Adapter\AdapterInterface;
use Ethelserth\Checkout\Model\Payment\Adapter\Offline;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;

/**
 * Registry of all payment adapters.
 * PSP modules inject their adapters via etc/frontend/di.xml.
 * Offline methods are enumerated automatically from Magento config.
 */
class MethodPool
{
    private const OFFLINE_METHODS = [
        'checkmo'       => 'Check / Money order',
        'banktransfer'  => 'Bank Transfer Payment',
        'cashondelivery'=> 'Cash On Delivery',
        'free'          => 'No Payment Information Required',
        'purchaseorder' => 'Purchase Order',
    ];

    /** @var AdapterInterface[] */
    private array $resolved = [];

    /**
     * @param AdapterInterface[] $adapters Additional adapters registered via di.xml
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly array $adapters = [],
    ) {}

    /**
     * All adapters available for the given quote (filtered by isAvailable).
     * @return array<int, array{code: string, title: string, icon: string}>
     */
    public function getAvailableMethods(Quote $quote): array
    {
        $result = [];
        foreach ($this->getAdapters() as $adapter) {
            if ($adapter->isAvailable($quote)) {
                $result[] = [
                    'code'  => $adapter->getMethodCode(),
                    'title' => $adapter->getTitle(),
                    'icon'  => $adapter->getIconUrl(),
                ];
            }
        }
        return $result;
    }

    /** @return AdapterInterface[] */
    public function getAdapters(): array
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        // Offline methods (auto-discovered)
        foreach (self::OFFLINE_METHODS as $code => $defaultTitle) {
            $configTitle = $this->scopeConfig->getValue(
                "payment/{$code}/title",
                ScopeInterface::SCOPE_STORE
            );
            $this->resolved[] = $this->objectManager->create(Offline::class, [
                'methodCode' => $code,
                'title'      => $configTitle ?: $defaultTitle,
            ]);
        }

        // PSP adapters injected via di.xml
        foreach ($this->adapters as $adapter) {
            $this->resolved[] = $adapter;
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
