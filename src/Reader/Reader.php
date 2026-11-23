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
    /**
     * Extension for JSON documents.
     */
    private const JSON = 'json';

    /**
     * Long extension for YAML documents.
     */
    private const YAML = 'yaml';

    /**
     * Short extension for YAML documents.
     */
    private const YML = 'yml';

    /**
     * File extensions recognized when discovering OpenAPI sources.
     */
    private const FORMATS = [self::YAML, self::YML, self::JSON];

    /**
     * Read a specification using the format indicated by its file extension.
     */
    public static function file(string $path): Document
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("OpenAPI file not found [{$path}].");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported OpenAPI format [.{$extension}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read OpenAPI file [{$path}].");
        }

        return static::string($contents, $extension === self::JSON ? self::JSON : self::YAML);
    }

    /**
     * Parse specification text, detecting its format when none is supplied.
     */
    public static function string(string $contents, ?string $format = null): Document
    {
        $defaultFormat = str_starts_with(ltrim($contents), '{') ? self::JSON : self::YAML;
        $detectedFormat = $format ?? $defaultFormat;
        $data = self::decode($contents, $detectedFormat);

        if (! is_array($data) || ! isset($data['openapi'])) {
            throw new InvalidArgumentException('OpenAPI document must contain [openapi].');
        }

        $data['paths'] ??= [];

        return new Document($data);
    }

    /**
     * Read a bundle in file name order and retain its documents for references.
     */
    public static function directory(string $directory): Document
    {
        if (! is_dir($directory)) {
            throw new InvalidArgumentException("OpenAPI directory not found [{$directory}].");
        }

        $root = realpath($directory) ?: $directory;
        $files = self::documents($root);

        if ($files === []) {
            throw new RuntimeException("No OpenAPI files found in [{$root}].");
        }

        return new Document(self::merge($files), $files);
    }

    /**
     * Select the decoder for the supplied OpenAPI serialization format.
     */
    private static function decode(string $contents, string $extension): mixed
    {
        $parsers = [
            self::JSON => static fn (string $value) => json_decode($value, true),
            self::YAML => Yaml::parse(...),
            self::YML => Yaml::parse(...),
        ];

        $parse = $parsers[$extension]
            ?? throw new InvalidArgumentException("Unsupported OpenAPI format [{$extension}].");

        return $parse($contents);
    }

    /**
     * Collect readable OpenAPI files indexed by their bundle-relative paths.
     */
    private static function documents(string $directory): array
    {
        $patterns = array_map(static fn (string $extension) => '*.'.$extension, self::FORMATS);
        $finder = Finder::create()->files()->in($directory)->name($patterns)->sortByName();
        $files = [];

        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            $contents = file_get_contents($absolute);

            if ($contents === false) {
                continue;
            }

            $data = self::decode($contents, strtolower($file->getExtension()));

            if (! is_array($data) || ! isset($data['openapi'])) {
                continue;
            }

            $data['paths'] ??= [];
            $relative = str_replace('\\', '/', substr($absolute, strlen($directory) + 1));
            $files[$relative] = $data;
        }

        return $files;
    }

    /**
     * Combine bundle documents, giving precedence to later definitions.
     */
    private static function merge(array $files): array
    {
        $merged = [
            'openapi' => '3.0.0',
            'info' => ['title' => 'Merged', 'version' => '0'],
            'paths' => [],
            'components' => [],
        ];

        foreach ($files as $data) {
            $merged['openapi'] = $data['openapi'];

            if (is_array($data['paths'])) {
                $merged['paths'] = array_replace($merged['paths'], $data['paths']);
            }

            $merged['components'] = self::mergeComponents(
                $merged['components'],
                (array) ($data['components'] ?? []),
            );

            foreach (['info', 'security', 'servers'] as $key) {
                if (is_array($data[$key] ?? null)) {
                    $merged[$key] = $data[$key];
                }
            }
        }

        return $merged;
    }

    /**
     * Replace matching component names within each section.
     */
    private static function mergeComponents(array $components, array $incoming): array
    {
        $merged = $components;

        foreach ($incoming as $section => $items) {
            if (is_array($items) && $items !== []) {
                $merged[$section] = array_replace($merged[$section] ?? [], $items);
            }
        }

        return $merged;
    }
}
