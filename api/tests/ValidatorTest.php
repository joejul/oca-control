<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\HttpException;
use App\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequireDecimalNormalizesAndRounds(): void
    {
        self::assertSame(2.0, Validator::requireDecimal('2', 'hours', 0.25, 24));
        self::assertSame(2.5, Validator::requireDecimal('2,5', 'hours', 0.25, 24));
        self::assertSame(3500.0, Validator::requireDecimal(3500, 'amount', 1, 1_000_000));
    }

    public function testRequireDecimalRejectsOutOfRange(): void
    {
        $this->expectException(HttpException::class);
        Validator::requireDecimal('30', 'hours', 0.25, 24);
    }

    public function testRequireDecimalRejectsNonNumeric(): void
    {
        $this->expectException(HttpException::class);
        Validator::requireDecimal('abc', 'amount', 1, 100);
    }

    public function testRequireDateAcceptsIsoOnly(): void
    {
        self::assertSame('2026-09-08', Validator::requireDate('2026-09-08', 'work_date'));
    }

    public function testRequireDateRejectsBadFormat(): void
    {
        $this->expectException(HttpException::class);
        Validator::requireDate('08/09/2026', 'work_date');
    }

    public function testRequirePinRange(): void
    {
        self::assertSame('a1234', Validator::requirePin('a1234'));
        self::assertSame('Z0000', Validator::requirePin('Z0000'));
    }

    public function testRequirePinRejectsShortOrLong(): void
    {
        $this->expectException(HttpException::class);
        Validator::requirePin('a123');
    }

    public function testRequirePinRejectsInvalidCharacters(): void
    {
        $this->expectException(HttpException::class);
        Validator::requirePin('12345');
    }

    public function testRequireUsernameLowercasesAndValidates(): void
    {
        self::assertSame('josue', Validator::requireUsername('Josue'));

        $this->expectException(HttpException::class);
        Validator::requireUsername('con espacios');
    }
}
