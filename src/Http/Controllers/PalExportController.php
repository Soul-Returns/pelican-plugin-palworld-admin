<?php

namespace Soul\PalworldAdmin\Http\Controllers;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Soul\PalworldAdmin\PalworldService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PalExportController
{
    /** Max Level.sav size we are willing to proxy through the panel (compressed on disk). */
    private const MAX_SAVE_BYTES = 256 * 1024 * 1024;

    /**
     * Proxy the world's Level.sav from wings to the browser.
     *
     * The client-side Pal export needs the raw bytes via fetch(); wings'
     * signed download endpoint lives on another origin (CORS), so the panel
     * streams it instead - the file is small (a few MB compressed).
     */
    public function levelSav(Request $request, Server $server): Response
    {
        abort_unless($request->user()?->can(SubuserPermission::FileReadContent, $server), 403);
        abort_unless(PalworldService::isPalworldServer($server), 404);

        $path = PalworldService::worldSavePath($server);
        abort_unless($path, 404, 'No world save found under Pal/Saved/SaveGames/0');

        $content = (new DaemonFileRepository())
            ->setServer($server)
            ->getContent($path, self::MAX_SAVE_BYTES);

        Activity::event('server:file.download')->property('file', $path)->log();

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            // explicit so the browser can render a determinate progress bar
            // (chunked transfer would otherwise drop the length)
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * mtime/size of the world's Level.sav.
     *
     * REST /save only *initiates* a save; the game flushes the files
     * asynchronously and offers no completion signal. The export modal polls
     * this after saving and downloads once the file visibly changed and its
     * size is stable - instead of a blind fixed wait.
     */
    public function levelSavStat(Request $request, Server $server): JsonResponse
    {
        abort_unless($request->user()?->can(SubuserPermission::FileReadContent, $server), 403);
        abort_unless(PalworldService::isPalworldServer($server), 404);

        $path = PalworldService::worldSavePath($server);
        abort_unless($path, 404, 'No world save found under Pal/Saved/SaveGames/0');

        $entries = (new DaemonFileRepository())->setServer($server)->getDirectory(dirname($path));
        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === basename($path)) {
                return new JsonResponse([
                    'mtime' => $entry['modified'] ?? null,
                    'size' => $entry['size'] ?? null,
                ]);
            }
        }

        abort(404, 'Level.sav not found');
    }

    /** The bundled client-side exporter (resources/dist/palexport.js). */
    public function asset(): BinaryFileResponse
    {
        $file = dirname(__DIR__, 3) . '/resources/dist/palexport.js';
        abort_unless(is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
