<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArrayBackedSchema;

trait HasSchema
{
    protected ?Schema $schema = null;

    /**
     * Accepts either a Schema instance or a PHP array representing a JSON schema.
     * When passing an array, you can optionally provide a $name. If omitted, we
     * will attempt to infer it from ['name' => ..., 'schema' => ...] shape, otherwise
     * it defaults to 'output'.
     *
     * @param  Schema|array<string, mixed>  $schema
     */
    public function withSchema(Schema|array $schema, ?string $name = null): self
    {
        if (is_array($schema)) {
            if ($name === null && isset($schema['name'], $schema['schema']) && is_array($schema['schema'])) {
                $name = (string) $schema['name'];
                $schema = $schema['schema'];
            }

            $name ??= 'output';

            $schema = new ArrayBackedSchema($name, $schema);
        }

        $this->schema = $schema;

        return $this;
    }
}
