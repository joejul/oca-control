<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Validator;

/** Consulta (solo lectura) de la bitacora audit_log para el administrador. */
final class AuditController
{
    private const PER_PAGE = 50;

    /**
     * Tipo de entidad => [tabla, columna con el usuario "dueño" del registro].
     * Sirve para mostrar a quien le pertenece lo que se aprobo/anulo.
     */
    private const SUBJECTS = [
        'overtime'    => ['overtime_entries', 'user_id'],
        'vacation'    => ['vacation_requests', 'user_id'],
        'makeup'      => ['makeup_hours', 'user_id'],
        'early_leave' => ['early_leaves', 'user_id'],
        'consumption' => ['consumption_entries', 'consumer_id'],
        'user'        => ['users', 'id'],
    ];

    public function list(Request $r): Response
    {
        $where  = [];
        $params = [];

        $actor = $r->queryParam('actor');
        if ($actor !== null && $actor !== '') {
            $where[]  = 'a.actor_id = ?';
            $params[] = Validator::requireInt($actor, 'actor');
        }

        // action: un modulo completo ("overtime") o una accion exacta ("overtime.void").
        $action = $r->queryParam('action');
        if ($action !== null && $action !== '') {
            if (!preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $action)) {
                $action = '';
            }
            if (str_contains($action, '.') || $action === 'login') {
                $where[]  = 'a.action = ?';
                $params[] = $action;
            } elseif ($action !== '') {
                $where[]  = 'a.action LIKE ?';
                $params[] = $action . '.%';
            }
        } elseif ($r->queryParam('include_login') !== '1') {
            // Los inicios de sesion son ruido: solo salen si se piden.
            $where[] = "a.action <> 'login'";
        }

        $from = Validator::optionalDate($r->queryParam('from'), 'from');
        if ($from !== null) {
            $where[]  = 'a.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = Validator::optionalDate($r->queryParam('to'), 'to');
        if ($to !== null) {
            $where[]  = 'a.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $pdo      = Database::pdo();

        $count = $pdo->prepare("SELECT COUNT(*) FROM audit_log a WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $page   = max(1, (int) ($r->queryParam('page') ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $pdo->prepare(
            "SELECT a.id, a.created_at, a.actor_id, ua.display_name AS actor_name,
                    a.action, a.entity_type, a.entity_id, a.details_json
             FROM audit_log a
             LEFT JOIN users ua ON ua.id = a.actor_id
             WHERE $whereSql
             ORDER BY a.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET " . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $subjects = $this->subjectNames($rows);

        $out = [];
        foreach ($rows as $row) {
            $details = $row['details_json'] !== null ? json_decode((string) $row['details_json'], true) : null;
            $key     = ($row['entity_type'] ?? '') . ':' . ($row['entity_id'] ?? '');
            $out[] = [
                'id'           => (int) $row['id'],
                'created_at'   => $row['created_at'],
                'actor_id'     => $row['actor_id'] === null ? null : (int) $row['actor_id'],
                'actor_name'   => $row['actor_name'],
                'action'       => $row['action'],
                'entity_type'  => $row['entity_type'],
                'entity_id'    => $row['entity_id'] === null ? null : (int) $row['entity_id'],
                'subject_name' => $subjects[$key] ?? null,
                'details'      => is_array($details) ? $details : null,
            ];
        }

        return Response::json([
            'rows'     => $out,
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }

    /**
     * Nombre del colaborador "dueño" de cada registro mencionado en la pagina.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string> clave "tipo:id" => nombre
     */
    private function subjectNames(array $rows): array
    {
        $idsByType = [];
        foreach ($rows as $row) {
            $type = $row['entity_type'];
            if ($type !== null && $row['entity_id'] !== null && isset(self::SUBJECTS[$type])) {
                $idsByType[$type][(int) $row['entity_id']] = true;
            }
        }

        $names = [];
        foreach ($idsByType as $type => $ids) {
            [$table, $col] = self::SUBJECTS[$type];   // constantes: nunca vienen del usuario
            $list = array_keys($ids);
            $in   = implode(',', array_fill(0, count($list), '?'));
            $stmt = Database::pdo()->prepare(
                "SELECT t.id, u.display_name FROM $table t JOIN users u ON u.id = t.$col WHERE t.id IN ($in)"
            );
            $stmt->execute($list);
            foreach ($stmt->fetchAll() as $row) {
                $names[$type . ':' . $row['id']] = (string) $row['display_name'];
            }
        }
        return $names;
    }
}
