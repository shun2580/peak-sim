<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\ChildLimits;
use App\Support\Limits;

class ReportController extends Controller
{
    private const LOCAL = 7;

    public function index(string $name)
    {
        $plain = 40 + 2;
        $fromClass = Limits::PAGE_SIZE;
        $arithmetic = Limits::PAGE_SIZE * 2 + 1;
        $nested = (Limits::CHUNK - 5) * self::LOCAL;
        $power = 2 ** 10;
        $concat = 'x' . Limits::PAGE_SIZE . 'y';
        $classString = Limits::class;
        $listValue = Limits::SIZES;
        $divisionByZero = 1 / 0;
        $fromVariable = $name;
        $fromCall = count([1, 2]);
        $fromEnv = env('PAGE_SIZE');
        $unknownConstant = Limits::MISSING;
        $staticLate = static::LOCAL;
        $loops = Limits::LOOPS_A;
        $overridden = ChildLimits::PAGE_SIZE;
        $inherited = ChildLimits::DOUBLE;
        $triple = ChildLimits::TRIPLE;

        return Order::take($fromClass)->get();
    }
}
