<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Money;
use IndianOpinion\GenQueue\Redactor;
use PHPUnit\Framework\TestCase;

final class MoneyPayloadTest extends TestCase
{
    public function testDecimalToNanosWithHalfUpRounding(): void
    {
        $this->assertSame(0, Money::toNanos('0'));
        $this->assertSame(5000000000, Money::toNanos('5'));
        $this->assertSame(5000000000, Money::toNanos(5));
        $this->assertSame(250000000, Money::toNanos('0.25'));
        $this->assertSame(250000000, Money::toNanos('.25'));
        $this->assertSame(100000000, Money::toNanos(0.1));
        $this->assertSame(1, Money::toNanos('0.0000000005'));
        $this->assertSame(0, Money::toNanos('0.0000000004'));
        $this->assertSame(1000000000, Money::toNanos('0.9999999999'));
        $this->assertSame(1500000000, Money::toNanos(' +1.5 '));
        $this->assertSame(0, Money::toNanos('-0'));
    }

    public function testBadAmountsThrow(): void
    {
        foreach (['-1', '-0.01', 'abc', '', '1e5', '1,5', '1.2.3', '10000000000'] as $bad) {
            try {
                Money::toNanos($bad);
                $this->fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Money::toNanos(-0.5);
    }

    public function testFormat(): void
    {
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('1.50', Money::format(1500000000));
        $this->assertSame('0.000123', Money::format(123000));
        $this->assertSame('12.345678901', Money::format(12345678901));
        $this->assertSame('-0.50', Money::format(-500000000));
    }

    public function testRedactor(): void
    {
        $this->assertSame('Bearer [redacted] ok', Redactor::clean('Bearer abc.def ok'));
        $this->assertSame('url?key=[redacted]', Redactor::clean('url?key=12345'));
        $this->assertSame('é', Redactor::cut('éé', 3));
        $this->assertSame(500, strlen(Redactor::clean(str_repeat('a', 900))));
        $this->assertSame('bad ? byte', Redactor::clean("bad \xB1 byte"));
    }
}
