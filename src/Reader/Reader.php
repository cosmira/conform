<?php

declare(strict_types=1);

namespace Cosmira\Conform\Reader;

use Cosmira\Conform\Document\Document;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class Reader
{
    public static function file(string $path): Document
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("OpenAPI file not found [{$path}].");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['yaml', 'yml', 'json'], true)) {
            throw new InvalidArgumentException("Unsupported OpenAPI format [.{$extension}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read OpenAPI file [{$path}].");
        }

        return static::string($contents, $extension === 'json' ? 'json' : 'yaml');
    }

    public static function string(string $contents, ?string $format = null): Document
    {
        $format ??= str_starts_with(ltrim($contents), '{') ? 'json' : 'yaml';

        $data = match ($format) {
            'json' => json_decode($contents, true),
            'yaml', 'yml' => Yaml::parse($contents),
            default => throw new InvalidArgumentException("Unsupported OpenAPI format [{$format}]."),
        };

        if (! is_array($data) || ! isset($data['openapi'])) {
            throw new InvalidArgumentException('OpenAPI document must contain [openapi].');
        }

        $data['paths'] ??= [];

        return new Document($data);
    }

    public static function directory(string $directory): Document
    {
        if (! is_dir($directory)) {
            throw new InvalidArgumentException("OpenAPI directory not found [{$directory}].");
        }

        $directory = realpath($directory) ?: $directory;
        $files = [];

        foreach (Finder::create()->files()->in($directory)->name(['*.yaml', '*.yml', '*.json'])->sortByName() as $file) {
            $absolute = $file->getRealPath();
            $relative = str_replace('\\', '/', substr($absolute, strlen($directory) + 1));
            $extension = strtolower($file->getExtension());
            $contents = file_get_contents($absolute);

            if ($contents === false) {
                continue;
            }

            $data = $extension === 'json'
                ? json_decode($contents, true)
                : Yaml::parse($contents);

            if (! is_array($data) || ! isset($data['openapi'])) {
                continue;
            }

            $data['paths'] ??= [];
            $files[$relative] = $data;
        }

        if ($files === []) {
            throw new RuntimeException("No OpenAPI files found in [{$directory}].");
        }

        $merged = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Merged', 'version' => '0'],
            'paths' => [],
            'components' => [],
        ];

        foreach ($files as $data) {
            if (isset($data['openapi'])) {
                $merged['openapi'] = $data['openapi'];
            }

            if (isset($data['info']) && is_array($data['info'])) {
                $merged['info'] = $data['info'];
            }

            foreach ($data['paths'] ?? [] as $path => $item) {
                $merged['paths'][$path] = $item;
            }

            foreach ($data['components'] ?? [] as $section => $items) {
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $name => $value) {
                    $merged['components'][$section][$name] = $value;
                }
            }

            if (isset($data['security']) && is_array($data['security'])) {
                $merged['security'] = $data['security'];
            }

            if (isset($data['servers']) && is_array($data['servers'])) {
                $merged['servers'] = $data['servers'];
            }
        }

        return new Document($merged, $files);
    }
}
