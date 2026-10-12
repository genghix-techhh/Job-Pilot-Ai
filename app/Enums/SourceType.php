<?php

namespace App\Enums;

enum SourceType: string
{
    case Greenhouse = 'greenhouse';
    case Lever = 'lever';
    case RemoteOk = 'remoteok';
    case Rss = 'rss';
    case Adzuna = 'adzuna';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Greenhouse => 'Greenhouse board',
            self::Lever => 'Lever site',
            self::RemoteOk => 'Remote OK',
            self::Rss => 'RSS / Atom feed',
            self::Adzuna => 'Adzuna',
            self::Manual => 'Manual import',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Greenhouse => 'Public Job Board API. Add one company board token per source (for example "stripe"). It cannot discover companies on its own.',
            self::Lever => 'Public Postings API. Add one company site name per source (for example "netflix").',
            self::RemoteOk => 'Remote OK public JSON feed. Every job links back to Remote OK as the source, as their terms require.',
            self::Rss => 'Any RSS or Atom feed you add yourself. Only public http(s) feeds are fetched.',
            self::Adzuna => 'Optional. Needs your own Adzuna API credentials and your confirmation that your intended use is permitted.',
            self::Manual => 'Jobs you paste in by hand (URL, title, company, description).',
        };
    }

    /** Sources the scheduler may sync automatically. */
    public function isSyncable(): bool
    {
        return $this !== self::Manual;
    }
}
