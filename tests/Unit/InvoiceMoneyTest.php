<?php

namespace Tests\Unit;

use App\Support\Invoices\InvoiceMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceMoneyTest extends TestCase
{
    public function test_exact_cents_and_maximum(): void
    {
        $this->assertSame(30,InvoiceMoney::cents('0.10')+InvoiceMoney::cents('0.20'));
        $this->assertSame('0.30',InvoiceMoney::format(30));
        $this->assertSame(999999999999,InvoiceMoney::cents('9999999999.99'));
        $this->assertSame('9999999999.99',InvoiceMoney::format(999999999999));
        $this->assertSame('0.00',InvoiceMoney::format(0));
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn($v)=>[$v],['-0.01','+1.00','1e2','1.001','1.2','01.00','10000000000.00',' 1.00','1.00 ','NaN','']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceMoney::cents($value);
    }

    public function test_overflow_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceMoney::format(1000000000000);
    }
}
