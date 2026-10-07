<?php

declare(strict_types=1);

namespace VuFindConfigGui\Mcp;

use VuFindConfigGui\FileStore;
use VuFindConfigGui\Instance;
use VuFindConfigGui\Yaml;

/**
 * Structured editing of ModelContextProtocol.yaml.
 *
 * Disabled tools and resources are "parked" under a key VuFind does not
 * read (default GuiDisabled), so they can be switched on again later.
 * Comments in the YAML file are lost on save; the original with all
 * comments stays in config/vufind/.
 *
 * EXPERIMENTAL: targets VuFind pull request #4939 (not merged yet).
 */
final class McpConfig
{
    public const FILE = 'config/vufind/ModelContextProtocol.yaml';

    /** Known sections in the order they are written; unknown ones are kept after them */
    private const ORDER = ['General', 'AutoDiscovery', 'ResourceTemplates', 'Tools', 'ContentTypes', 'ResponseFields',
        'AllowedServices', 'CapabilityNamespaces'];

    public function __construct(private readonly Instance $inst, private readonly FileStore $files)
    {
    }

    public function parkedKey(): string
    {
        return $this->inst->mcp['parked_key'] ?? 'GuiDisabled';
    }

    public function load(): array
    {
        Yaml::init($this->inst);
        return Yaml::parseFile($this->files->effectivePath(self::FILE));
    }

    /** Fields VuFind's search API can return (keys of SearchApiRecordFields.yaml) */
    public function availableFields(): array
    {
        Yaml::init($this->inst);
        $out = [];
        foreach (Yaml::parseFile($this->inst->origPath('config/vufind/SearchApiRecordFields.yaml')) as $name => $def) {
            $out[] = ['name' => $name, 'description' => is_array($def) ? ($def['description'] ?? '') : ''];
        }
        return $out;
    }

    public function render(array $model): string
    {
        Yaml::init($this->inst);
        $data = [];
        foreach (array_merge(self::ORDER, [$this->parkedKey()], array_keys($model)) as $k) {
            if (array_key_exists($k, $model) && !array_key_exists($k, $data) && $model[$k] !== null && $model[$k] !== []) {
                $data[$k] = $model[$k];
            }
        }
        $yaml = "# Written by vufind-config-gui. The original with all comments: config/vufind/ModelContextProtocol.yaml\n"
            . "# Docs: https://vufind.org/wiki/configuration:model_context_protocol\n"
            . '# ' . $this->parkedKey() . ": tools/resources disabled in the GUI (ignored by VuFind)\n\n"
            . Yaml::dump($data, 8);
        Yaml::parse($yaml); // self-test before anything is written
        return $yaml;
    }
}
