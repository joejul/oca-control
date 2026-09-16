<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Validator;

final class EventsController
{
    private const TYPES = [
        'Happy Hour', 'Karaoke', 'Viernes de Ocaso', 'DJ Night', 'Especial', 'Cerrado', 'Otro',
    ];

    private const ART_STATUSES = ['pending', 'completed'];

    /** Miercoles(3) .. Sabado(6) en formato ISO-8601 (N: 1=Lunes .. 7=Domingo). Datos reales confirmados en Sept 2026. */
    private const BASE_WEEK = [
        3 => [
            'type'          => 'Happy Hour',
            'theme'         => 'Happy Hour',
            'schedule'      => '7pm - 8pm',
            'artists'       => null,
            'promo'         => '2x1 en cocteles seleccionados de la casa, todos los miercoles de 7 a 8pm. '
                . 'El arranque perfecto de la semana - aprovecha antes de que se acabe la hora.',
            'internal_cost' => null,
        ],
        4 => [
            'type'          => 'Karaoke',
            'theme'         => 'Karaoke',
            'schedule'      => '9:00 PM',
            'artists'       => 'Paul',
            'promo'         => null,
            'internal_cost' => 100000,
        ],
        5 => [
            'type'          => 'Viernes de Ocaso',
            'theme'         => null,
            'schedule'      => '8:00 PM',
            'artists'       => 'Ocaso',
            'promo'         => null,
            'internal_cost' => null,
        ],
        6 => [
            'type'          => 'DJ Night',
            'theme'         => null,
            'schedule'      => '10pm',
            'artists'       => null,
            'promo'         => null,
            'internal_cost' => null,
        ],
    ];

    public function list(Request $r): Response
    {
        $month = Validator::requireMonth($r->queryParam('month'), 'month');

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM events WHERE month = :m ORDER BY date IS NULL, date ASC, id ASC'
        );
        $stmt->execute(['m' => $month]);

        return Response::json(['events' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $user */
    public function create(Request $r, array $user): Response
    {
        $date = Validator::optionalDate($r->input('date'), 'date');
        $month = $date !== null ? substr($date, 0, 7) : Validator::requireMonth($r->input('month'));
        $type = self::validateType($r->input('type'));
        $artStatus = self::validateArtStatus($r->input('art_status', 'pending'));

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO events (month, date, type, theme, schedule, cover, promo, artists, price, internal_cost, art_status)
             VALUES (:month, :date, :type, :theme, :schedule, :cover, :promo, :artists, :price, :internal_cost, :art_status)'
        )->execute([
            'month'         => $month,
            'date'          => $date,
            'type'          => $type,
            'theme'         => Validator::optionalString($r->input('theme'), 'theme', 160),
            'schedule'      => Validator::optionalString($r->input('schedule'), 'schedule', 80),
            'cover'         => Validator::optionalString($r->input('cover'), 'cover', 80),
            'promo'         => Validator::optionalString($r->input('promo'), 'promo', 2000),
            'artists'       => Validator::optionalString($r->input('artists'), 'artists', 160),
            'price'         => Validator::optionalString($r->input('price'), 'price', 40),
            'internal_cost' => Validator::optionalDecimal($r->input('internal_cost'), 'internal_cost', 0, 10_000_000),
            'art_status'    => $artStatus,
        ]);
        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'event.create', 'event', $id, ['month' => $month, 'date' => $date]);

        return Response::json(self::find($id), 201);
    }

    /** @param array<string,mixed> $user */
    public function update(array $user, int $id, Request $r): Response
    {
        $event = self::find($id);

        $sets   = [];
        $params = ['id' => $id];

        if ($r->has('date')) {
            $date = Validator::optionalDate($r->input('date'), 'date');
            $sets[] = 'date = :date';
            $params['date'] = $date;
            if ($date !== null) {
                $sets[] = 'month = :month';
                $params['month'] = substr($date, 0, 7);
            }
        }
        if ($r->has('type')) {
            $sets[] = 'type = :type';
            $params['type'] = self::validateType($r->input('type'));
        }
        if ($r->has('theme')) {
            $sets[] = 'theme = :theme';
            $params['theme'] = Validator::optionalString($r->input('theme'), 'theme', 160);
        }
        if ($r->has('schedule')) {
            $sets[] = 'schedule = :schedule';
            $params['schedule'] = Validator::optionalString($r->input('schedule'), 'schedule', 80);
        }
        if ($r->has('cover')) {
            $sets[] = 'cover = :cover';
            $params['cover'] = Validator::optionalString($r->input('cover'), 'cover', 80);
        }
        if ($r->has('promo')) {
            $sets[] = 'promo = :promo';
            $params['promo'] = Validator::optionalString($r->input('promo'), 'promo', 2000);
        }
        if ($r->has('artists')) {
            $sets[] = 'artists = :artists';
            $params['artists'] = Validator::optionalString($r->input('artists'), 'artists', 160);
        }
        if ($r->has('price')) {
            $sets[] = 'price = :price';
            $params['price'] = Validator::optionalString($r->input('price'), 'price', 40);
        }
        if ($r->has('internal_cost')) {
            $sets[] = 'internal_cost = :internal_cost';
            $params['internal_cost'] = Validator::optionalDecimal(
                $r->input('internal_cost'),
                'internal_cost',
                0,
                10_000_000
            );
        }
        if ($r->has('art_status')) {
            $sets[] = 'art_status = :art_status';
            $params['art_status'] = self::validateArtStatus($r->input('art_status'));
        }

        if ($sets === []) {
            throw HttpException::badRequest('Nada que actualizar.');
        }

        Database::pdo()
            ->prepare('UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);

        Audit::log((int) $user['id'], 'event.update', 'event', $id, $r->body);

        return Response::json(self::find($id));
    }

    /** @param array<string,mixed> $user */
    public function delete(array $user, int $id): Response
    {
        self::find($id);

        Database::pdo()->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);

        Audit::log((int) $user['id'], 'event.delete', 'event', $id);

        return Response::json(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string,mixed> $user */
    public function generate(array $user, Request $r): Response
    {
        $month = Validator::requireMonth($r->input('month'), 'month');
        $pdo   = Database::pdo();

        // Lock por mes: si dos personas (o un doble-click) disparan "Generar Mes"
        // a la vez, la segunda espera a que la primera termine y confirme en vez
        // de leer las mismas fechas "existentes" y duplicar los eventos base.
        $lockName = 'agenda_generate_' . $month;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
        $lockStmt->execute(['name' => $lockName]);
        if (!$lockStmt->fetchColumn()) {
            throw HttpException::unprocessable(
                'Otra persona está generando este mes en este momento. Intenta de nuevo en unos segundos.'
            );
        }

        try {
            $existing = $pdo->prepare('SELECT date FROM events WHERE month = :m AND date IS NOT NULL');
            $existing->execute(['m' => $month]);
            $existingDates = array_flip($existing->fetchAll(\PDO::FETCH_COLUMN));

            $first       = new \DateTimeImmutable($month . '-01');
            $daysInMonth = (int) $first->format('t');

            $insert = $pdo->prepare(
                'INSERT INTO events (month, date, type, theme, schedule, artists, promo, internal_cost, art_status)
                 VALUES (:m, :d, :t, :th, :sch, :a, :pr, :cost, :s)'
            );

            $created = 0;
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateStr = sprintf('%s-%02d', $month, $day);
                $isoDow  = (int) (new \DateTimeImmutable($dateStr))->format('N'); // 1=Lun .. 7=Dom

                if (!isset(self::BASE_WEEK[$isoDow]) || isset($existingDates[$dateStr])) {
                    continue;
                }

                $cfg = self::BASE_WEEK[$isoDow];
                $insert->execute([
                    'm'    => $month,
                    'd'    => $dateStr,
                    't'    => $cfg['type'],
                    'th'   => $cfg['theme'],
                    'sch'  => $cfg['schedule'],
                    'a'    => $cfg['artists'],
                    'pr'   => $cfg['promo'],
                    'cost' => $cfg['internal_cost'],
                    's'    => 'pending',
                ]);
                $created++;
            }
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }

        Audit::log((int) $user['id'], 'event.generate', 'event', null, ['month' => $month, 'created' => $created]);

        return Response::json(['created' => $created]);
    }

    /** @param array<string,mixed> $user */
    public function deleteMonth(array $user, Request $r): Response
    {
        $month = Validator::requireMonth($r->input('month'), 'month');

        $stmt = Database::pdo()->prepare('DELETE FROM events WHERE month = :m');
        $stmt->execute(['m' => $month]);
        $deleted = $stmt->rowCount();

        Audit::log((int) $user['id'], 'event.delete_month', 'event', null, [
            'month'   => $month,
            'deleted' => $deleted,
        ]);

        return Response::json(['month' => $month, 'deleted' => $deleted]);
    }

    private static function validateType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::TYPES, true)) {
            throw HttpException::unprocessable('Tipo de evento invalido.', ['field' => 'type']);
        }
        return $value;
    }

    private static function validateArtStatus(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ART_STATUSES, true)) {
            throw HttpException::unprocessable('Estado de arte invalido.', ['field' => 'art_status']);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Evento no encontrado.');
        }
        return self::castRows([$row])[0];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function castRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['internal_cost'] = $row['internal_cost'] !== null ? (float) $row['internal_cost'] : null;
        }
        return $rows;
    }
}
