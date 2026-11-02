<?php

namespace App\Http\Controllers;

use App\Ocm\Activity;
use App\Ocm\PluginPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $zip = PluginPackage::build();
        $version = PluginPackage::version() ?? 'dev';
        Activity::log('plugin_downloaded', ['version' => $version]);

        return response()->download($zip, "nb-ocmonitor-client-{$version}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
