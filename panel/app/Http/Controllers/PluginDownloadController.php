<?php

namespace App\Http\Controllers;

use App\Ocm\Activity;
use App\Ocm\PluginPackage;
use App\Ocm\PluginRelease;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        // Liefst de ondertekende release, zodat handmatige installaties gelijk zijn aan de updates.
        if (($release = PluginRelease::latest()) !== null && $release['version'] === PluginPackage::version()) {
            Activity::log('plugin_downloaded', ['version' => $release['version'], 'signed' => true]);

            return response()->download($release['path'], $release['file'], ['Content-Type' => 'application/zip']);
        }
        $zip = PluginPackage::build();
        $version = PluginPackage::version() ?? 'dev';
        Activity::log('plugin_downloaded', ['version' => $version]);

        return response()->download($zip, "nb-ocmonitor-client-{$version}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
