<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Http;

use ComplyRobin\Runner\Exceptions\NetworkError;

interface HttpClient
{
    /**
     * @throws NetworkError when no answer came back at all.
     */
    public function send(Request $request): Response;
}
