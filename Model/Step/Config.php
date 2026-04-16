<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;

class Config
{
    /** @var array<string, array> */
    private array $steps = [];
    private bool $loaded = false;

    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly string $configFile = 'checkout_steps.xml',
    ) {}

    /** @return array<string, array> Merged step data, keyed by name */
    public function getStepsData(): array
    {
        if (!$this->loaded) {
            $this->load();
        }
        return $this->steps;
    }

    private function load(): void
    {
        $this->loaded = true;

        // Scan Config/checkout_steps.xml in every registered module
        $modulePaths = $this->componentRegistrar->getPaths(ComponentRegistrar::MODULE);

        foreach ($modulePaths as $modulePath) {
            $file = $modulePath . '/Config/' . $this->configFile;
            if (!file_exists($file)) {
                continue;
            }

            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }

            foreach ($xml->steps->step ?? [] as $step) {
                $attrs = (array) $step->attributes();
                $attrs = $attrs['@attributes'] ?? [];
                $name  = (string) ($attrs['name'] ?? '');
                if (!$name) {
                    continue;
                }

                if (!isset($this->steps[$name])) {
                    $this->steps[$name] = [
                        'name'      => $name,
                        'label'     => '',
                        'magewire'  => '',
                        'template'  => '',
                        'order'     => 10,
                        'unlock_on' => null,
                        'disabled'  => false,
                    ];
                }

                foreach (['label', 'magewire', 'template', 'order', 'unlock_on', 'disabled'] as $key) {
                    if (isset($attrs[$key])) {
                        $value = (string) $attrs[$key];
                        if ($key === 'order') {
                            $value = (int) $value;
                        } elseif ($key === 'disabled') {
                            $value = $value === 'true' || $value === '1';
                        } elseif ($key === 'unlock_on') {
                            $value = $value ?: null;
                        }
                        $this->steps[$name][$key] = $value;
                    }
                }
            }
        }
    }
}
