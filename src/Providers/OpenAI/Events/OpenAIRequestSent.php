<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Prism\Prism\Contracts\PrismRequest;

class OpenAIRequestSent
{
    use Dispatchable;

    public function __construct(
        public PrismRequest $request,
        public string $handlerType,
        public ?array $payload = []
    ) {}
}
