<?php

namespace App\DataObjects;

use Illuminate\Support\Str;

abstract class BaseDataObject
{

    /**
     * Create a new class instance.
     */
    public function __get(string $field)
    {
        if (property_exists($this, $field)) {
            return $this->$field;
        }

        throw new \InvalidArgumentException("Property {$field} does not exist in " . __CLASS__);
    }

    public function __eq(object $obj) : bool
    {
        $this_values = get_object_vars($this);
        $obj_values = get_object_vars($obj);
        if($this_values !== $obj_values)
        {
            return false;
        }
        return $this_values === $obj_values;
    }

    public function toArray(): array
    {
        $attributes = get_object_vars($this);
        $result = [];

        foreach ($attributes as $key => $value) {
            $result[Str::snake($key)] = $this->transformValue($value);
        }

        return $result;
    }

    protected function transformValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->transformValue($item), $value);
        }

        return $value;
    }
}
