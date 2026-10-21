<?php

namespace Goldnead\StatamicInsights\Tests\Feature\SearchConsole;

use Goldnead\StatamicInsights\Tests\TestCase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * A snapshot file in a directory of its own, per test.
 */
abstract class SearchConsoleTestCase extends TestCase
{
    protected string $dir;

    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/insights-gsc-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->file = $this->dir.'/search-console.json';

        config(['statamic-insights.search_console.snapshot' => $this->file]);
        config(['app.timezone' => 'Europe/Berlin']);

        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (glob($this->dir.'/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    protected function benutzer(bool $darf = true)
    {
        if ($darf) {
            return tap(User::make()->email('darf@example.com')->makeSuper())->save();
        }

        $rolle = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email('darfnicht@example.com')->assignRole($rolle))->save();
    }
}
