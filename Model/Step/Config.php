<?php
declare(strict_types=1);

namespace Ethelserth\Checkout\Model\Step;

use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

class Config
{
    /** @var array<string, array> */
    private array $steps = [];
    private bool $loaded = false;

    public function __construct(
        private readonly ModuleDirReader $moduleDirReader,
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
        $files = $this->moduleDirReader->getConfigurationFiles($this->configFile);

        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }
            foreach ($xml->steps->step ?? [] as $step) {
                $attrs = (array) $step->attributes();
                $attrs = $attrs['@attributes'] ?? [];
                $name = (string) ($attrs['name'] ?? '');
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
