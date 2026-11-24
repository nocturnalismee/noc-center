<?php

namespace App\Http\Controllers;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AgentDownloadController extends Controller
{
    /**
     * Stream the agent source as a tarball so a monitored server can install
     * it without a repository checkout.
     *
     * The archive contains no tokens, configuration, or dashboard secrets:
     * only the files under `agent/`.
     */
    public function __invoke(): BinaryFileResponse
    {
        $source = base_path('agent');

        abort_unless(is_dir($source), 404);

        $base = tempnam(sys_get_temp_dir(), 'noc-agent-');

        abort_if($base === false, 500);

        @unlink($base);

        $tarPath = $base.'.tar';
        $gzPath = $tarPath.'.gz';

        try {
            $archive = new PharData($tarPath);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
                $archive->addFile($file->getPathname(), 'noc-agent/'.$relative);
            }

            $archive->compress(Phar::GZ);
            unset($archive);
        } catch (Throwable $exception) {
            @unlink($tarPath);
            @unlink($gzPath);

            report($exception);

            abort(500, 'The agent bundle could not be built.');
        } finally {
            @unlink($tarPath);
        }

        abort_unless(is_file($gzPath), 500);

        return response()
            ->download($gzPath, 'noc-agent.tar.gz', ['Content-Type' => 'application/gzip'])
            ->deleteFileAfterSend(true);
    }
}
