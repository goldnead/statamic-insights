<?php

namespace Goldnead\StatamicInsights\SearchConsole;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
 * memo holds the last read only and is keyed by path, modification time and
 * size, so a long-lived worker does not serve an old file after a new one was
 * written, and does not collect one entry per version either.
 *
 * An unreadable file is said once per file state in the log, with the reason
 * and the path: the screen says only that it cannot read, the log says why.
 */
class SnapshotReader
{
    public const VERSION = 1;

    public const MISSING = 'missing';

    public const UNREADABLE = 'unreadable';

    public const OK = 'ok';

    /** More than this is not a snapshot; it is something else under that name. */
    private const MAX_BYTES = 10_000_000;

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

    /** The four windows the format promises. */
    public const PERIODS = ['7d', 'previous_7d', '28d', 'previous_28d'];

    /** @var array<string, array{status: string, data: array<string, mixed>|null}> */
    private array $memo = [];

    /** Why the last {@see load()} found the file unreadable. */
    private string $reason = '';

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

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $this->reason = '';
        $result = $this->load($path);

        if ($result['status'] === self::UNREADABLE) {
            $this->warnOnce($path, $key);
        }

        // Only the last state is worth keeping.
        $this->memo = [$key => $result];

        return $result;
    }

    /** @return array{status: string, data: array<string, mixed>|null} */
    protected function load(string $path): array
    {
        $unreadable = ['status' => self::UNREADABLE, 'data' => null];

        $size = @filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            $this->reason = 'the file is missing its size or larger than '.self::MAX_BYTES.' bytes';

            return $unreadable;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            $this->reason = 'the file is empty or could not be read';

            return $unreadable;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            $this->reason = 'the file is not a JSON object: '.json_last_error_msg();

            return $unreadable;
        }

        if (! $this->valid($data)) {
            return $unreadable;
        }

        return ['status' => self::OK, 'data' => $data];
    }

    /** One warning per file state, also across requests: the screen is opened more than once. */
    private function warnOnce(string $path, string $key): void
    {
        $context = ['path' => $path, 'reason' => $this->reason];
        $message = "insights: the Search Console snapshot is not readable ({$this->reason}).";

        try {
            if (! Cache::add('insights.search-console.unreadable.'.md5($key), 1, 86400)) {
                return;
            }
        } catch (Throwable) {
            // No cache: better to warn twice than never.
        }

        Log::warning($message, $context);
    }

    /** Fail a validity check and remember why. */
    private function fail(string $reason): bool
    {
        $this->reason = $reason;

        return false;
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
            return $this->fail('version is not '.self::VERSION);
        }

        // Strict ISO 8601: Carbon::parse would also take "gestern" or "next friday".
        if (! is_string($data['fetched_at'] ?? null) || ! preg_match(self::ISO_8601, $data['fetched_at'])) {
            return $this->fail('fetched_at is missing or not an ISO 8601 timestamp');
        }

        try {
            Carbon::parse($data['fetched_at']);
        } catch (Throwable) {
            return $this->fail('fetched_at is not a real point in time');
        }

        if (! is_array($data['daily'] ?? null) || ! is_array($data['periods'] ?? null)) {
            return $this->fail('daily or periods is missing');
        }

        foreach (self::PERIODS as $name) {
            $period = $data['periods'][$name] ?? null;

            if (! is_array($period)) {
                return $this->fail("period [{$name}] is missing");
            }

            foreach (['clicks', 'impressions'] as $figure) {
                // INF and NAN (a literal like 1e999 decodes to INF) are not figures.
                if (! is_numeric($period[$figure] ?? null) || ! is_finite((float) $period[$figure])) {
                    return $this->fail("period [{$name}] has no finite {$figure}");
                }
            }
        }

        return true;
    }
}
