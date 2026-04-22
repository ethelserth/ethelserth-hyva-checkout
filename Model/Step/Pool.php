<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

class Pool
{
    /** @var StepInterface[]|null */
    private ?array $sorted = null;

    public function __construct(
        private readonly Config $config,
        private readonly StepFactory $stepFactory,
    ) {}

    /** @return StepInterface[] sorted by order, disabled steps excluded */
    public function getSteps(): array
    {
        if ($this->sorted !== null) {
            return $this->sorted;
        }

        $data = $this->config->getStepsData();
        $steps = [];

        foreach ($data as $item) {
            if (!empty($item['disabled'])) {
                continue;
            }
            $steps[] = $this->stepFactory->create([
                'name'         => $item['name'],
                'label'        => $item['label'],
                'magewireClass'=> $item['magewire'],
                'template'     => $item['template'],
                'order'        => (int) $item['order'],
                'unlockOn'     => $item['unlock_on'] ?? null,
                'disabled'     => false,
            ]);
        }

        usort($steps, fn(StepInterface $a, StepInterface $b) => $a->getOrder() <=> $b->getOrder());
        $this->sorted = $steps;

        return $this->sorted;
    }
}
