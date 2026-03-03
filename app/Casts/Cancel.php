<?php

namespace App\Casts;

use App\ValueObjects\Cancel as CancelValueObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Cancel implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CancelValueObject
    {
        return !empty($value)
            ? new CancelValueObject(
                $value['reason'],
                $value['date'],
            ) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (!$value instanceof CancelValueObject) {
            throw new InvalidArgumentException('The given value is not an Cancel instance.');
        }

        return [
            $key => [
                'reason' => $value->reason,
                'date' => $value->date,
            ],
        ];
    }
}
