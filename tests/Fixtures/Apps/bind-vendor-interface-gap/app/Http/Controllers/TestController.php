<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(Filesystem $files): JsonResponse
    {
        $content = $files->get('report.txt'); // PLANT: vendor interface, no app binding

        return response()->json(['size' => strlen($content)]);
    }
}
