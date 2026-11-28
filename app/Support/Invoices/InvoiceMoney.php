<?php

namespace App\Support\Invoices;

use InvalidArgumentException;

final class InvoiceMoney
{
    public static function cents(string $amount): int
    {
        if (PHP_INT_SIZE < 8 || ! preg_match('/^(0|[1-9][0-9]{0,9})\.[0-9]{2}$/D',$amount)) {
            throw new InvalidArgumentException('Invalid canonical USD amount.');
        }
        [$whole,$fraction] = explode('.',$amount);
        return (int)$whole * 100 + (int)$fraction;
    }

    public static function format(int $cents): string
    {
        if ($cents < 0 || $cents > 999999999999) {
            throw new InvalidArgumentException('Amount exceeds DECIMAL(12,2).');
        }
        return intdiv($cents,100).'.'.str_pad((string)($cents % 100),2,'0',STR_PAD_LEFT);
    }
}
