<?php

use Illuminate\Support\Facades\Route;

/**
 * The dashboard is served by this application.
 *
 * It used to be two processes: a Vite development server on 5173 and the API on
 * 8000, both started by hand from a terminal. That arrangement survived exactly
 * as long as nobody switched the machine off. When the power went the sensors
 * kept recording, because those run under systemd, and the screen stayed blank
 * — the worst possible pairing on an appliance whose whole job is to be looked
 * at.
 *
 * So the built dashboard is compiled into public/ and served from here. One
 * process to supervise, one port, no CORS surface, and nothing that has to be
 * remembered after a reboot.
 *
 * A development server is still the right tool while writing the frontend. It
 * just is not the thing a client stands in front of.
 */
Route::get('/{path?}', function () {
    $index = public_path('index.html');

    if (! file_exists($index)) {
        // Said plainly rather than as a 404. The cause is always the same and
        // the fix is one command; a "not found" would send somebody hunting
        // through routes for a bug that is not there.
        return response(
            "The dashboard has not been built.\n\nRun: deploy/build-dashboard.sh\n",
            503,
        )->header('Content-Type', 'text/plain');
    }

    // Read rather than streamed as a file response. A file response computes
    // its own caching headers and rewrote this one to "no-store, public",
    // which is a contradiction that different browsers resolve differently.
    // The document is a few kilobytes; the control is worth more than the copy.
    //
    // no-store: index.html names the content-hashed bundles. A cached copy
    // after an upgrade points at files that no longer exist, and the screen
    // goes white while the appliance runs perfectly underneath it.
    return response(file_get_contents($index), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store, must-revalidate',
    ]);
})->where('path', '^(?!api/|storage/).*$')->name('dashboard');
