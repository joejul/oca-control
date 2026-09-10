<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpException;

/**
 * Validaciones puntuales. Cada metodo lanza HttpException 422 si falla,
 * y devuelve el valor ya normalizado.
 */
final class Validator
{
    public static function requireString(mixed $value, string $field, int $min = 1, int $max = 255): string
    {
        if (!is_string($value)) {
            throw HttpException::unprocessable("El campo '$field' es obligatorio.", ['field' => $field]);
        }
        $value = trim($value);
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            throw HttpException::unprocessable(
                "El campo '$field' debe tener entre $min y $max caracteres.",
                ['field' => $field]
            );
        }
        return $value;
    }

    public static function optionalString(mixed $value, string $field, int $max = 500): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw HttpException::unprocessable("El campo '$field' no es valido.", ['field' => $field]);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw HttpException::unprocessable(
                "El campo '$field' no puede pasar de $max caracteres.",
                ['field' => $field]
            );
        }
        return $value;
    }

    public static function requireInt(mixed $value, string $field, int $min = 1, int $max = PHP_INT_MAX): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw HttpException::unprocessable("El campo '$field' no es valido.", ['field' => $field]);
        }
        return $value;
    }

    public static function requireDecimal(mixed $value, string $field, float $min, float $max): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            throw HttpException::unprocessable("El campo '$field' debe ser un numero.", ['field' => $field]);
        }
        $num = round((float) $value, 2);
        if ($num < $min || $num > $max) {
            throw HttpException::unprocessable(
                "El campo '$field' debe estar entre $min y $max.",
                ['field' => $field]
            );
        }
        return $num;
    }

    public static function requireDate(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw HttpException::unprocessable("El campo '$field' es obligatorio.", ['field' => $field]);
        }
        $value = trim($value);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw HttpException::unprocessable("El campo '$field' debe tener formato AAAA-MM-DD.", ['field' => $field]);
        }
        return $value;
    }

    public static function requirePin(mixed $value, string $field = 'pin'): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw HttpException::unprocessable('El PIN es obligatorio.', ['field' => $field]);
        }
        $value = (string) $value;
        if (!preg_match('/^[A-Za-z]\d{4}$/', $value)) {
            throw HttpException::unprocessable('El PIN debe tener 5 caracteres: 1 letra y 4 numeros (ej. a1234).', ['field' => $field]);
        }
        return $value;
    }

    public static function requireUsername(mixed $value, string $field = 'username'): string
    {
        $value = self::requireString($value, $field, 3, 40);
        $value = strtolower($value);
        if (!preg_match('/^[a-z0-9._-]+$/', $value)) {
            throw HttpException::unprocessable(
                'El usuario solo puede tener letras, numeros, punto, guion y guion bajo.',
                ['field' => $field]
            );
        }
        return $value;
    }
}
