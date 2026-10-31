<?php

namespace App\Http\Controllers;

use Illuminate\Notifications\Notifiable;

/** The vendor trait may define anything, and a trait method wins over an inherited one. */
class AuditedController extends BaseController
{
    use Notifiable;

    public function own(): string
    {
        return 'own';
    }
}
