<?php

declare(strict_types=1);

namespace PeakSim\Input;

/**
 * Reads a directory that was exported beforehand: `route-list.json` and `model-show/*.json` (the file names do not
 * matter; the "class" inside indexes them). Nothing is read until it is asked for, and what was read is kept.
 */
final class ExportDirSource implements ArtisanSource
{
    private const ROUTE_LIST = 'route-list.json';
    private const MODEL_DIR = 'model-show';

    /** @var list<RouteEntry>|null */
    private ?array $routes = null;

    /** @var array<string, ModelInfo>|null keyed by class without a leading backslash */
    private ?array $models = null;

    public function __construct(private readonly string $dir)
    {
    }

    public function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $path = rtrim($this->dir, '/\\') . '/' . self::ROUTE_LIST;
        $data = $this->decode($path, self::ROUTE_LIST);
        try {
            return $this->routes = ArtisanPayload::routes($data);
        } catch (\UnexpectedValueException $e) {
            throw new ArtisanOutputNotJsonException(self::ROUTE_LIST . ': ' . $e->getMessage() . ' / ' . self::ROUTE_LIST . ' の形が想定と違う', 0, $e);
        }
    }

    public function modelInfo(string $class): ?ModelInfo
    {
        return $this->models()[ltrim($class, '\\')] ?? null;
    }

    /** @return array<string, ModelInfo> */
    private function models(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        $models = [];
        $files = glob(rtrim($this->dir, '/\\') . '/' . self::MODEL_DIR . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $label = self::MODEL_DIR . '/' . basename($file);
            try {
                $info = ArtisanPayload::model($this->decode($file, $label));
            } catch (\UnexpectedValueException $e) {
                throw new ArtisanOutputNotJsonException($label . ': ' . $e->getMessage() . ' / ' . $label . ' の形が想定と違う', 0, $e);
            }
            $models[$info->class] ??= $info;
        }

        return $this->models = $models;
    }

    private function decode(string $path, string $label): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ArtisanFailedException("export file not found or unreadable: {$label} (export directory: {$this->dir}) / 書き出しファイルが無い、または読めない: {$label}");
        }

        try {
            return ArtisanPayload::decode((string) file_get_contents($path));
        } catch (\JsonException $e) {
            throw new ArtisanOutputNotJsonException("{$label} is not valid JSON ({$e->getMessage()}) / {$label} は JSON として読めない", 0, $e);
        }
    }
}
