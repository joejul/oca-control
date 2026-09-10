<?php

declare(strict_types=1);

namespace App;

final class Audit
{
    /** @param array<string,mixed> $details */
    public static function log(
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = []
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log (actor_id, action, entity_type, entity_id, details_json)
             VALUES (:actor, :action, :etype, :eid, :details)'
        );
        $stmt->execute([
            'actor'   => $actorId,
            'action'  => $action,
            'etype'   => $entityType,
            'eid'     => $entityId,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
