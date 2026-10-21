<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Goldnead\StatamicInsights\SearchConsole\SearchConsoleView;
use Goldnead\StatamicInsights\SearchConsole\SnapshotReader;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Search Console screen: clicks, impressions, click-through rate and
 * position, from a snapshot file somebody else writes.
 *
 * The route exists whether or not the file does (a nav item resolves its target
 * on every Control Panel page), but the navigation only shows the entry while
 * the file is there. Reached directly without one, the screen says so instead
 * of answering 404 — a bookmark to a section that went quiet is not an error.
 */
class SearchConsoleController extends Controller
{
    public function __construct(protected SnapshotReader $snapshot) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view insights');

        $read = $this->snapshot->read();

        return Inertia::render('insights::SearchConsole', [
            'status' => $read['status'],
        ] + ($read['status'] === SnapshotReader::OK ? SearchConsoleView::assemble($read['data']) : []));
    }
}
