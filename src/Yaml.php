<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Symfony YAML, taken from the GUI's own vendor/ if installed, otherwise
 * from the VuFind installation (every VuFind ships it).
 */
final class Yaml
{
    public static function init(Instance $inst): void
    {
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return;
        }
        $own = dirname(__DIR__) . '/vendor/autoload.php';
        $vufind = $inst->home . '/vendor/autoload.php';
        foreach ([$own, $vufind] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                    return;
                }
            }
        }
        throw new HttpException('error.no_yaml', [], 500);
    }

    public static function parseFile(string $path): array
    {
        $data = is_file($path) ? \Symfony\Component\Yaml\Yaml::parseFile($path) : null;
        return is_array($data) ? $data : [];
    }

    public static function parse(string $yaml): mixed
    {
        try {
            return \Symfony\Component\Yaml\Yaml::parse($yaml);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            throw new HttpException('error.yaml_invalid', ['message' => $e->getMessage()]);
        }
    }

    public static function dump(array $data, int $inline = 8): string
    {
        return \Symfony\Component\Yaml\Yaml::dump($data, $inline, 2, \Symfony\Component\Yaml\Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }
}
