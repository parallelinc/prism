<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Gemini\Maps\SchemaMap;
use Prism\Prism\Schema\ArrayBackedSchema;
use Prism\Prism\Structured\PendingRequest;

it('withSchema accepts a plain array schema with an explicit name', function (): void {
    $schemaArray = [
        'type' => 'object',
        'description' => 'A structured movie review',
        'properties' => [
            'title' => ['type' => 'string', 'description' => 'The movie title'],
            'rating' => ['type' => 'string', 'description' => 'Rating out of 5 stars'],
            'summary' => ['type' => 'string', 'description' => 'Brief review summary'],
        ],
        'required' => ['title', 'rating', 'summary'],
        'additionalProperties' => false,
    ];

    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($schemaArray, 'movie_review')
        ->withPrompt('Review the movie Inception')
        ->toRequest();

    expect($request->schema()->name())->toBe('movie_review')
        ->and($request->schema()->toArray())
        ->toBe($schemaArray);
});

it('withSchema accepts a compound array {name, schema}', function (): void {
    $compound = [
        'name' => 'movie_review',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'The movie title'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ],
    ];

    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($compound)
        ->withPrompt('Review the movie Inception')
        ->toRequest();

    expect($request->schema()->name())->toBe('movie_review')
        ->and($request->schema()->toArray())
        ->toBe($compound['schema']);
});

it('withSchema infers default name when omitted', function (): void {
    $schemaArray = [
        'type' => 'object',
        'properties' => [
            'foo' => ['type' => 'string', 'description' => 'foo'],
        ],
        'required' => ['foo'],
        'additionalProperties' => false,
    ];

    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4o')
        ->withSchema($schemaArray)
        ->withPrompt('Say hello')
        ->toRequest();

    expect($request->schema()->name())->toBe('output')
        ->and($request->schema()->toArray())
        ->toBe($schemaArray);
});

it('Gemini SchemaMap preserves provided type from ArrayBackedSchema', function (): void {
    $schemaArray = [
        'type' => 'object',
        'properties' => [
            'foo' => ['type' => 'string'],
        ],
        'required' => ['foo'],
        'additionalProperties' => false,
    ];

    $schema = new ArrayBackedSchema('test', $schemaArray);
    $mapped = (new SchemaMap($schema))->toArray();

    expect($mapped['type'])->toBe('object')
        ->and($mapped['properties'])->toHaveKey('foo')
        ->and($mapped)->not->toHaveKey('additionalProperties');
});
