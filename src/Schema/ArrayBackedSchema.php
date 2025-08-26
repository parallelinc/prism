<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Contracts\Schema as SchemaContract;

class ArrayBackedSchema implements SchemaContract
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        protected readonly string $name,
        protected readonly array $schema,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = $this->schema;

        // Best-effort normalization when a top-level type is not provided
        if (! isset($schema['type'])) {
            if (isset($schema['properties'])) {
                $schema['type'] = 'object';
            } elseif (isset($schema['items'])) {
                $schema['type'] = 'array';
            }
        }

        $this->removeTitleKey($schema);

        return $schema;
    }

    protected function removeTitleKey(array &$array)
    {
        foreach ($array as $key => &$value) {
            // If the current key is "title", unset it
            if ($key === 'title') {
                unset($array[$key]);
            }
            // If the value is an array, recurse into it
            elseif (is_array($value)) {
                $this->removeTitleKey($value);
            }
        }
    }
}
