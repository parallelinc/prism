<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @return array<int, ToolCall>
     */
    public static function map(?array $output = []): array
    {
        $toolCalls = [];

        foreach ($output as $item) {
            if (isset($item['type'])) {
                $type = $item['type'];

                $toolCall = match ($type) {
                    'function_call' => new ToolCall(
                        id: data_get($item, 'id'),
                        resultId: data_get($item, 'call_id'),
                        name: data_get($item, 'name'),
                        arguments: data_get($item, 'arguments'),
                    ),
                    'reasoning' => new ToolCall(
                        id: data_get($item, 'id'),
                        reasoningId: data_get($item, 'id'),
                        reasoningSummary: data_get($item, 'summary'),
                    ),
                    'web_search_call' => new ToolCall(
                        id: data_get($item, 'id'),
                        webSearchId: data_get($item, 'id'),
                        arguments: data_get($item, 'action'),
                        status: data_get($item, 'status'),
                    ),
                    default => null
                };

                if (! is_null($toolCall)) {
                    $toolCalls[] = $toolCall;
                }
            }
        }

        return $toolCalls;
    }
}
