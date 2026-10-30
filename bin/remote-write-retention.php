<?php
declare(strict_types=1);

/**
 * Opt-in retention maintenance for pending remote write payloads.
 *
 * Every write job stores its requested content in SQL until acknowledged.
 * A stopped or disconnected agent can leave queued and claimed jobs indefinitely.
 * This sweep removes their payload without ever re-claiming an attempt.
 *
 * - Queued writes older than 10 minutes are expired, matching the poll gate.
 * - Claimed writes older than 1 hour retain state=claimed (outcome unknown),
 *   but their content is discarded. A late result can still be reported once.
 * - Audit contains only identifiers and lifecycle metadata, never content.
 *
 * Call from a separately approved privileged scheduler, not on every MCP read.
 *
 * @return array{expired_queued:int,scrubbed_claimed:int}
 */
function remoteScrubStaleWritePayloads(PDO $db, int $limit = 100): array
{
    if ($limit < 1 || $limit > 500 || $db->inTransaction()) {
        throw new InvalidArgumentException('Invalid write retention invocation');
    }
    $counts = ['expired_queued' => 0, 'scrubbed_claimed' => 0];
    $db->beginTransaction();
    try {
        $query = $db->query(
            "SELECT id, deployment_id, state "
            . "FROM dm_remote_readonly_job "
            . "WHERE action_name='workspace.write_text' "
            . "AND arguments_json<>'{}' "
            . "AND ((state='queued' AND created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)) "
            . "OR (state='claimed' AND claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 60 MINUTE))) "
            . "ORDER BY created_at, id LIMIT " . $limit . " FOR UPDATE"
        );
        $jobs = $query->fetchAll(PDO::FETCH_ASSOC);
        $expire = $db->prepare(
            "UPDATE dm_remote_readonly_job SET state='expired', arguments_json='{}', "
            . "result_json=?, finished_at=UTC_TIMESTAMP(6) WHERE id=? AND state='queued'"
        );
        $scrub = $db->prepare(
            "UPDATE dm_remote_readonly_job SET arguments_json='{}' WHERE id=? AND state='claimed'"
        );
        $audit = $db->prepare(
            "INSERT INTO dm_audit_event (actor,event_type,object_type,object_id,outcome,details_json) "
            . "VALUES ('system:write-retention','remote.write.payload_scrubbed','remote-job',?,?,?)"
        );
        foreach ($jobs as $job) {
            $queued = $job['state'] === 'queued';
            if ($queued) {
                $expire->execute(['{"ok":false,"error":"write_job_expired"}', $job['id']]);
                $changed = $expire->rowCount();
            } else {
                $scrub->execute([$job['id']]);
                $changed = $scrub->rowCount();
            }
            if ($changed !== 1) {
                throw new RuntimeException('Concurrent write retention update');
            }
            $audit->execute([
                $job['id'],
                $queued ? 'expired' : 'outcome_unknown',
                json_encode([
                    'deployment_id' => $job['deployment_id'],
                    'previous_state' => $job['state'],
                    'cutoff_minutes' => $queued ? 10 : 60,
                ], JSON_THROW_ON_ERROR),
            ]);
            ++$counts[$queued ? 'expired_queued' : 'scrubbed_claimed'];
        }
        $db->commit();
        return $counts;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
