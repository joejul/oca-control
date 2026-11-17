<?php

declare(strict_types=1);

namespace App;

/**
 * Reglas de los periodos de cierre. Se cierra los dias de pago: el 15 y el 30 de cada mes
 * (en febrero el segundo cierre cae en el ultimo dia del mes). Cada cierre empieza el dia
 * siguiente al anterior, asi que el 31 pertenece al cierre que termina el 15 siguiente.
 *
 * Clase pura (sin base de datos) para poder probarla facil.
 */
final class ClosurePeriod
{
    private const MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Dia siguiente a la fecha del ultimo cierre. */
    public static function nextStart(string $lastEnd): string
    {
        return (new \DateTimeImmutable($lastEnd . ' +1 day'))->format('Y-m-d');
    }

    /** Proximo dia de pago (15 o 30) a partir de $start, inclusive. */
    public static function suggestedEnd(string $start): string
    {
        $d    = new \DateTimeImmutable($start);
        $day  = (int) $d->format('j');
        $last = (int) $d->format('t');
        $pay2 = min(30, $last);

        if ($day <= 15) {
            return $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 15)->format('Y-m-d');
        }
        if ($day <= $pay2) {
            return $d->setDate((int) $d->format('Y'), (int) $d->format('n'), $pay2)->format('Y-m-d');
        }
        // Dia 31: va con el cierre del 15 del mes siguiente.
        $next = $d->modify('first day of next month');
        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), 15)->format('Y-m-d');
    }

    /** "Cierre 1-15 Noviembre", "Cierre 31 Octubre - 15 Noviembre". */
    public static function label(string $start, string $end): string
    {
        $s = new \DateTimeImmutable($start);
        $e = new \DateTimeImmutable($end);

        $sd = (int) $s->format('j');
        $ed = (int) $e->format('j');
        $sm = self::MONTHS[(int) $s->format('n')];
        $em = self::MONTHS[(int) $e->format('n')];
        $sy = $s->format('Y');
        $ey = $e->format('Y');

        if ($sy === $ey && $s->format('n') === $e->format('n')) {
            return $sd === $ed ? "Cierre $sd $sm" : "Cierre $sd-$ed $sm";
        }
        if ($sy === $ey) {
            return "Cierre $sd $sm - $ed $em";
        }
        return "Cierre $sd $sm $sy - $ed $em $ey";
    }
}
