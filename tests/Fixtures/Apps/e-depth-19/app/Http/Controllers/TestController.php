<?php

namespace App\Http\Controllers;

use App\Chain\Level02;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function __construct(private readonly Level02 $next) {}

    public function index(): JsonResponse
    {
        return response()->json($this->next->run());
    }
}
