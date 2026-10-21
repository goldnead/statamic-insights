<?php

namespace Goldnead\StatamicInsights\SearchConsole;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the Search Console snapshot file, and nothing else.
 *
 * **This addon never calls Google and holds no Google credentials.** Somebody
 * else writes the file (a cron job, a script on another machine, a CI run);
 * this class only reads what ended up on disk. That is the whole reason the
 * screen can stay honest about its source: the date it prints is the date the
 * file says, not the date somebody opened the page.
 *
 * Three states, and a screen needs all three:
 *
 * - `missing`: no file. The section does not exist for this installation, and
 *   the navigation does not show it.
 * - `unreadable`: a file, but not one of ours — half-written, truncated, wrong
 *   version, not JSON. Said on the screen in one sentence instead of crashing
 *   it, and never thrown upwards: a broken export must not take the Control
 *   Panel down with it.
 * - `ok`: a decoded, shape-checked snapshot.
 *
 * Read at most once per request, and again only when the file changed. The
 * memo is keyed by path, modification time and size, so a long-lived worker
 * does not serve an old file after a new one was written.
 */
class SnapshotReader
{
    public const VERSION = 1;

    public const MISSING = 'missing';

    public const UNREADABLE = 'unreadable';

    public const OK = 'ok';

    /** More than this is not a snapshot; it is something else under that name. */
    private const MAX_BYTES = 10_000_000;

    /** The four windows the format promises. */
    public const PERIODS = ['7d', 'previous_7d', '28d', 'previous_28d'];

    /** @var array<string, array{status: string, data: array<string, mixed>|null}> */
    private array $memo = [];

    public function path(): string
    {
        $path = trim((string) config('statamic-insights.search_console.snapshot', ''));

        return $path !== '' ? $path : storage_path('app/insights/search-console.json');
    }

    /** Whether there is anything to show a section for. Cheap: one `stat`. */
    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** @return array{status: string, data: array<string, mixed>|null} */
    public function read(): array
    {
        $path = $this->path();

        clearstatcache(true, $path);

        if (! is_file($path)) {
            return ['status' => self::MISSING, 'data' => null];
        }

        $key = $path.'|'.(int) @filemtime($path).'|'.(int) @filesize($path);

        return $this->memo[$key] ??= $this->load($path);
    }

    /** @return array{status: string, data: array<string, mixed>|null} */
    protected function load(string $path): array
    {
        $unreadable = ['status' => self::UNREADABLE, 'data' => null];

        $size = @filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            return $unreadable;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            return $unreadable;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! $this->valid($data)) {
            return $unreadable;
        }

        return ['status' => self::OK, 'data' => $data];
    }

    /**
     * Is this the shape version 1 promises?
     *
     * Strict about what the screen cannot draw without (the version, a time to
     * print, the daily series, the four windows), and lenient about the rest:
     * a missing top list is an empty table, not a broken screen.
     *
     * @param  array<string, mixed>  $data
     */
    protected function valid(array $data): bool
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            return false;
        }

        if (! is_string($data['fetched_at'] ?? null)) {
            return false;
        }

        try {
            Carbon::parse($data['fetched_at']);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($data['daily'] ?? null) || ! is_array($data['periods'] ?? null)) {
            return false;
        }

        foreach (self::PERIODS as $name) {
            $period = $data['periods'][$name] ?? null;

            if (! is_array($period) || ! is_numeric($period['clicks'] ?? null) || ! is_numeric($period['impressions'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
