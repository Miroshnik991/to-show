<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Http\Requests\Sberpay\SberpayCallbackRequest;
use Illuminate\Http\Response;

class SberProcessController extends PayProcessController
{
    public function callback(SberpayCallbackRequest $request): Response
    {
        return $this->webhook($this->resolveSystem('sberpay'), $request);
    }
}
