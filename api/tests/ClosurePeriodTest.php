<?php

declare(strict_types=1);

namespace App\Tests;

use App\ClosurePeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosurePeriodTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function suggestedEnds(): array
    {
        return [
            'inicio de mes'            => ['2026-11-01', '2026-11-15'],
            'mitad de quincena'        => ['2026-11-10', '2026-11-15'],
            'el propio 15'             => ['2026-11-15', '2026-11-15'],
            'despues del 15'           => ['2026-11-16', '2026-11-30'],
            'el propio 30'             => ['2026-11-30', '2026-11-30'],
            'dia 31 va al 15 siguiente' => ['2026-10-31', '2026-11-15'],
            '31 de diciembre'          => ['2026-12-31', '2027-01-15'],
            'febrero cierra el 28'     => ['2026-02-16', '2026-02-28'],
            'febrero bisiesto el 29'   => ['2028-02-16', '2028-02-29'],
            'febrero 28 es el cierre'  => ['2026-02-28', '2026-02-28'],
        ];
    }

    #[DataProvider('suggestedEnds')]
    public function testFinSugerido(string $start, string $expected): void
    {
        self::assertSame($expected, ClosurePeriod::suggestedEnd($start));
    }

    public function testCierresConsecutivosNoDejanDias(): void
    {
        // Recorre un año entero encadenando cierres: cada inicio es el dia siguiente del fin anterior.
        $start = '2026-01-01';
        $covered = 0;
        for ($i = 0; $i < 24; $i++) {
            $end = ClosurePeriod::suggestedEnd($start);
            self::assertGreaterThanOrEqual($start, $end);
            $covered += (int) (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
            $start = ClosurePeriod::nextStart($end);
        }
        // Los dias cubiertos suman exactamente la distancia recorrida: sin huecos ni traslapes.
        // (El 31 siempre abre el cierre siguiente, por eso 24 cierres terminan el 30/dic.)
        $span = (int) (new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable($start))->days;
        self::assertSame($span, $covered);
        self::assertSame('2026-12-31', $start);
    }

    public function testNombres(): void
    {
        self::assertSame('Cierre 1-15 Noviembre', ClosurePeriod::label('2026-11-01', '2026-11-15'));
        self::assertSame('Cierre 16-30 Noviembre', ClosurePeriod::label('2026-11-16', '2026-11-30'));
        self::assertSame('Cierre 31 Octubre - 15 Noviembre', ClosurePeriod::label('2026-10-31', '2026-11-15'));
        self::assertSame('Cierre 15 Noviembre', ClosurePeriod::label('2026-11-15', '2026-11-15'));
        self::assertSame('Cierre 31 Diciembre 2026 - 15 Enero 2027', ClosurePeriod::label('2026-12-31', '2027-01-15'));
    }
}
