<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

use Magento\Framework\ObjectManagerInterface;

class StepFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
    ) {}

    public function create(array $data): StepInterface
    {
        return $this->objectManager->create(Step::class, $data);
    }
}
