<?php

namespace Goldnead\StatamicInsights\Tests\Feature\SearchConsole;

use Goldnead\StatamicInsights\SearchConsole\SnapshotReader;
use Goldnead\StatamicInsights\Tests\Support\SearchConsoleSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class SnapshotReaderTest extends SearchConsoleTestCase
{
    protected function reader(): SnapshotReader
    {
        // A fresh one: the container's singleton would carry a memo over.
        return new SnapshotReader;
    }

    #[Test]
    public function without_a_file_it_is_missing_and_nothing_is_thrown(): void
    {
        $this->assertFalse($this->reader()->exists());
        $this->assertSame(['status' => 'missing', 'data' => null], $this->reader()->read());
    }

    #[Test]
    public function the_default_path_is_under_storage_when_nothing_is_configured(): void
    {
        config(['statamic-insights.search_console.snapshot' => null]);

        $this->assertSame(storage_path('app/insights/search-console.json'), $this->reader()->path());

        config(['statamic-insights.search_console.snapshot' => '  ']);

        $this->assertSame(storage_path('app/insights/search-console.json'), $this->reader()->path());
    }

    #[Test]
    public function a_good_file_is_read(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));

        $read = $this->reader()->read();

        $this->assertTrue($this->reader()->exists());
        $this->assertSame('ok', $read['status']);
        $this->assertSame('sc-domain:example.test', $read['data']['property']);
        $this->assertCount(56, $read['data']['daily']);
    }

    /** @return array<string, array{0: string}> */
    public static function brokenFiles(): array
    {
        $good = SearchConsoleSnapshot::make(Carbon::parse('2026-10-21 12:00:00'));
        $without = function (string ...$path) use ($good) {
            $copy = $good;
            $ref = &$copy;
            foreach (array_slice($path, 0, -1) as $key) {
                $ref = &$ref[$key];
            }
            unset($ref[end($path)]);

            return json_encode($copy);
        };

        return [
            'empty file' => [''],
            'only whitespace' => ["  \n"],
            'not json' => ['<html>502 Bad Gateway</html>'],
            'a json scalar' => ['42'],
            'cut off half way' => [substr((string) json_encode($good), 0, 200)],
            'wrong version' => [json_encode(['version' => 2] + $good)],
            'no version' => [$without('version')],
            'no fetched_at' => [$without('fetched_at')],
            'fetched_at is not a date' => [json_encode(['fetched_at' => 'gestern'] + $good)],
            'no daily' => [$without('daily')],
            'no periods' => [$without('periods')],
            'one period missing' => [$without('periods', 'previous_28d')],
            'period without clicks' => [$without('periods', '7d', 'clicks')],
            'fetched_at with a space instead of the T' => [json_encode(['fetched_at' => '2026-10-21 12:00:00'] + $good)],
            'fetched_at in words' => [json_encode(['fetched_at' => 'next friday'] + $good)],
            'fetched_at without a zone' => [json_encode(['fetched_at' => '2026-10-21T12:00:00'] + $good)],
            'infinite clicks (1e999)' => [str_replace('"clicks":42', '"clicks":1e999', (string) json_encode($good))],
            'infinite impressions (-1e999)' => [str_replace('"impressions":1800', '"impressions":-1e999', (string) json_encode($good))],
        ];
    }

    #[Test]
    #[DataProvider('brokenFiles')]
    public function a_broken_file_is_unreadable_not_an_exception(string $content): void
    {
        file_put_contents($this->file, $content);

        $read = $this->reader()->read();

        $this->assertSame('unreadable', $read['status']);
        $this->assertNull($read['data']);
        $this->assertTrue($this->reader()->exists(), 'the section stays; it just says it cannot read');
    }

    #[Test]
    public function missing_top_lists_are_an_empty_table_not_a_broken_file(): void
    {
        $snapshot = SearchConsoleSnapshot::make(Carbon::now());
        unset($snapshot['top_queries'], $snapshot['top_pages']);
        SearchConsoleSnapshot::write($this->file, $snapshot);

        $this->assertSame('ok', $this->reader()->read()['status']);
    }

    #[Test]
    public function the_file_is_read_once_until_it_changes(): void
    {
        $now = Carbon::now();
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make($now, ['property' => 'sc-domain:aaa.test']));
        touch($this->file, 1_700_000_000);

        $reader = $this->reader();
        $this->assertSame('sc-domain:aaa.test', $reader->read()['data']['property']);

        // Same size, same modification time: the key has not changed, so this
        // read is served from memory. That is what "once per request" means.
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make($now, ['property' => 'sc-domain:bbb.test']));
        touch($this->file, 1_700_000_000);
        $this->assertSame('sc-domain:aaa.test', $reader->read()['data']['property']);

        // A new modification time reads again.
        touch($this->file, 1_700_000_100);
        $this->assertSame('sc-domain:bbb.test', $reader->read()['data']['property']);
    }

    #[Test]
    public function a_file_turning_broken_is_noticed(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));
        touch($this->file, 1_700_000_000);
        $reader = $this->reader();
        $this->assertSame('ok', $reader->read()['status']);

        file_put_contents($this->file, '{"version":1,');
        touch($this->file, 1_700_000_200);

        $this->assertSame('unreadable', $reader->read()['status']);
    }

    #[Test]
    public function a_file_that_vanishes_is_missing_again(): void
    {
        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));
        $reader = $this->reader();
        $this->assertSame('ok', $reader->read()['status']);

        unlink($this->file);

        $this->assertSame('missing', $reader->read()['status']);
    }

    #[Test]
    public function strict_iso_8601_timestamps_are_accepted(): void
    {
        foreach (['2026-10-21T12:00:00Z', '2026-10-21T12:00:00+02:00', '2026-10-21T12:00+0200', '2026-10-21T12:00:00.123Z'] as $stamp) {
            file_put_contents($this->file, json_encode(['fetched_at' => $stamp] + SearchConsoleSnapshot::make(Carbon::now())));

            $this->assertSame('ok', $this->reader()->read()['status'], $stamp);
        }
    }

    #[Test]
    public function only_the_last_file_state_is_kept_in_memory(): void
    {
        $reader = $this->reader();

        foreach ([1_700_000_000, 1_700_000_100, 1_700_000_200] as $time) {
            SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));
            touch($this->file, $time);
            $reader->read();
        }

        $memo = (new \ReflectionProperty($reader, 'memo'))->getValue($reader);

        $this->assertCount(1, $memo);
    }

    #[Test]
    public function an_unreadable_file_is_logged_once_with_reason_and_path(): void
    {
        Log::spy();

        file_put_contents($this->file, '{"version":1,');

        $reader = $this->reader();
        $reader->read();
        $reader->read();
        $this->reader()->read(); // another request, same file state

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'not readable')
                && $context['path'] === $this->file
                && $context['reason'] !== '');
    }

    #[Test]
    public function a_readable_file_logs_nothing(): void
    {
        Log::spy();

        SearchConsoleSnapshot::write($this->file, SearchConsoleSnapshot::make(Carbon::now()));
        $this->reader()->read();

        Log::shouldNotHaveReceived('warning');
    }
}
