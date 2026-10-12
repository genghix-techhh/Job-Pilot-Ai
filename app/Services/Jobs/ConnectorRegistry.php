<?php

namespace App\Services\Jobs;

use App\Enums\SourceType;
use App\Models\JobSource;
use App\Services\Jobs\Connectors\AdzunaConnector;
use App\Services\Jobs\Connectors\GreenhouseConnector;
use App\Services\Jobs\Connectors\LeverConnector;
use App\Services\Jobs\Connectors\RemoteOkConnector;
use App\Services\Jobs\Connectors\RssFeedConnector;
use App\Services\Jobs\Connectors\SourceConnector;
use InvalidArgumentException;

class ConnectorRegistry
{
    /** @var array<string, class-string<SourceConnector>> */
    private const MAP = [
        'greenhouse' => GreenhouseConnector::class,
        'lever' => LeverConnector::class,
        'remoteok' => RemoteOkConnector::class,
        'rss' => RssFeedConnector::class,
        'adzuna' => AdzunaConnector::class,
    ];

    public function for(SourceType $type): SourceConnector
    {
        $class = self::MAP[$type->value] ?? throw new InvalidArgumentException("No connector for {$type->value}");

        return app($class);
    }

    public function forSource(JobSource $source): SourceConnector
    {
        return $this->for($source->type);
    }

    /** @return list<SourceType> source types the owner can add through the UI */
    public function addableTypes(): array
    {
        return array_map(fn ($v) => SourceType::from($v), array_keys(self::MAP));
    }
}
