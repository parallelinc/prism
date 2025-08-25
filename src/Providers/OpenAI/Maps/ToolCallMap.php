<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  null|array<int, array<string, mixed>>  $reasonings
     * @return array<int, ToolCall>
     */
    public static function map(?array $toolCalls, ?array $reasonings = null): array
    {
        if ($toolCalls === null) {
            return [];
        }

        // Pair each function_call with a reasoning item if available by matching index
        $indexedReasonings = array_values($reasonings ?? []);

        return array_values(array_map(
            function (array $toolCall, int $index) use ($indexedReasonings): ToolCall {
                $reasoning = $indexedReasonings[$index] ?? null;

                return new ToolCall(
                    id: data_get($toolCall, 'id'),
                    resultId: data_get($toolCall, 'call_id'),
                    name: data_get($toolCall, 'name'),
                    arguments: data_get($toolCall, 'arguments'),
                    reasoningId: $reasoning['id'] ?? null,
                    reasoningSummary: $reasoning['summary'] ?? null,
                );
            },
            array_values($toolCalls),
            array_keys(array_values($toolCalls)),
        ));
    }
}
