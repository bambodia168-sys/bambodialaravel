<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;

final class OrderReference
{
    public const DIGITS = '[1-9][0-9]{9}';

    public const ROUTE = self::DIGITS.'|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}';

    public const INDEX = 'marketplace_orders_public_reference_unique';

    public static function assign(callable $write): mixed
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                return $write((string) random_int(1_000_000_000, 9_999_999_999));
            } catch (UniqueConstraintViolationException $exception) {
                $detail = $exception->getPrevious()?->getMessage() ?? '';
                if ($attempt === 7 || (! str_contains($detail, self::INDEX)
                    && ! str_contains($detail, 'marketplace_orders.public_reference'))) {
                    throw $exception;
                }
            }
        }
    }
}
