<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\HttpException;
use App\TimeDebt;
use App\Validator;
use PHPUnit\Framework\TestCase;

/** Pruebas puras (sin base de datos) del calculo de salidas anticipadas. */
final class TimeDebtTest extends TestCase
{
    public function testMinutesBetweenComputesDifference(): void
    {
        self::assertSame(150, TimeDebt::minutesBetween('15:00', '12:30'));
        self::assertSame(1, TimeDebt::minutesBetween('15:00', '14:59'));
        self::assertSame(80, TimeDebt::minutesBetween('18:00', '16:40'));
    }

    public function testMinutesBetweenRejectsEqualTimes(): void
    {
        $this->expectException(HttpException::class);
        TimeDebt::minutesBetween('15:00', '15:00');
    }

    public function testMinutesBetweenRejectsActualAfterScheduled(): void
    {
        try {
            TimeDebt::minutesBetween('15:00', '16:00');
            self::fail('Debio lanzar HttpException');
        } catch (HttpException $e) {
            self::assertSame(422, $e->getCode());
        }
    }

    public function testFormat(): void
    {
        self::assertSame('2 h 30 min', TimeDebt::format(150));
        self::assertSame('1 h', TimeDebt::format(60));
        self::assertSame('45 min', TimeDebt::format(45));
        self::assertSame('0 min', TimeDebt::format(0));
    }

    public function testRequireTimeAcceptsValidTimes(): void
    {
        self::assertSame('08:05', Validator::requireTime('08:05', 'h'));
        self::assertSame('23:59', Validator::requireTime('23:59', 'h'));
        self::assertSame('15:30', Validator::requireTime('15:30:00', 'h'));
    }

    /** @dataProvider invalidTimes */
    public function testRequireTimeRejectsInvalid(mixed $value): void
    {
        $this->expectException(HttpException::class);
        Validator::requireTime($value, 'h');
    }

    /** @return array<string,array{0:mixed}> */
    public static function invalidTimes(): array
    {
        return [
            'hora 24'      => ['24:00'],
            'minuto 60'    => ['12:60'],
            'sin cero'     => ['8:05'],
            'texto'        => ['tarde'],
            'vacio'        => [''],
            'null'         => [null],
            'numero'       => [1530],
        ];
    }
}
