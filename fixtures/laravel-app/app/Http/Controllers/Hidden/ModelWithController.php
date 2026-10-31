<?php

namespace App\Http\Controllers\Hidden;

use App\Hidden\Models\EagerOrder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ModelWithController extends Controller
{
    public function index(): JsonResponse
    {
        // The model's $with adds one eager-load query to every request.
        return response()->json(EagerOrder::get());
    }
}
