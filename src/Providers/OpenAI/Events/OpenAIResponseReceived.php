<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Client\Response as ClientResponse;

class OpenAIResponseReceived
{
    use Dispatchable;

    public function __construct(
        public ClientResponse $response,
        public string $handlerType
    ) {}
}
