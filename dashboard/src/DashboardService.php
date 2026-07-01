<?php

namespace NHMP;

use PDO;

final class DashboardService
{
    public static function payloadForUser(string $userId): array
    {
        $cacheKey = 'tenant-dashboard:' . $userId;
        $cached = RedisCache::get($cacheKey);
        if ($cached) {
            $cached['_meta']['cache'] = 'redis';
            return $cached;
        }

        $pdo = Database::pdo();
        $redis = RedisCache::client();

        $userStmt = $pdo->prepare('SELECT u.id, u.name, u.surname, u.email, up.tenant AS plmn FROM "User" u JOIN UserPermissions up ON up.userId = u.id WHERE u.id = :id ORDER BY up.tenant');
        $userStmt->execute(['id' => $userId]);
        $rows = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return [
                'viewer' => null,
                'tenants' => [],
                'kpis' => ['active_sessions' => 0, 'active_imsi' => 0, 'total_tx_mb' => 0, 'total_rx_mb' => 0],
                'users' => [],
                'recent_sessions' => [],
                'live_metrics' => ['sessions' => [], 'metrics' => [], 'kpm' => []],
                '_meta' => ['cache' => 'miss', 'refreshed_at' => gmdate('c')],
            ];
        }

        $viewer = [
            'id' => $rows[0]['id'],
            'name' => $rows[0]['name'],
            'surname' => $rows[0]['surname'],
            'email' => $rows[0]['email'],
        ];

        $sql = <<<'SQL'
WITH allowed_tenants AS (
    SELECT up.tenant AS plmn
    FROM UserPermissions up
    WHERE up.userId = :user_id
), scoped_sessions AS (
    SELECT cs.id, cs.imsi, cs.ue_id, cs.amf_id, cs.rnti, cs."start", cs."end",
           SUBSTRING(cs.imsi, 1, 5) AS imsi_plmn,
           EXTRACT(EPOCH FROM (COALESCE(cs."end", NOW()) - cs."start"))::bigint AS duration_seconds
    FROM CustomerSession cs
    JOIN allowed_tenants t ON SUBSTRING(cs.imsi, 1, 5) = t.plmn
), perf AS (
    SELECT cp.imsi, cp.session_id,
           cp.tx_kbytes, cp.rx_kbytes,
           ROUND(cp.tx_kbytes / 1024.0, 2) AS tx_mb,
           ROUND(cp.rx_kbytes / 1024.0, 2) AS rx_mb
    FROM CustomerPerformance cp
), current_sessions AS (
    SELECT ss.*, p.tx_mb, p.rx_mb
    FROM scoped_sessions ss
    LEFT JOIN perf p ON p.imsi = ss.imsi AND p.session_id = ss.id
    WHERE ss."end" IS NULL
), user_rollup AS (
    SELECT ss.imsi,
           MIN(ss."start") AS first_seen,
           MAX(COALESCE(ss."end", NOW())) AS last_seen,
           COUNT(*)::int AS session_count,
           COUNT(*) FILTER (WHERE ss."end" IS NULL)::int AS active_session_count,
           COALESCE(SUM(ss.duration_seconds), 0)::bigint AS total_duration_seconds,
           ROUND(COALESCE(SUM(p.tx_kbytes), 0) / 1024.0, 2) AS total_tx_mb,
           ROUND(COALESCE(SUM(p.rx_kbytes), 0) / 1024.0, 2) AS total_rx_mb
    FROM scoped_sessions ss
    LEFT JOIN CustomerPerformance p ON p.imsi = ss.imsi AND p.session_id = ss.id
    GROUP BY ss.imsi
)
SELECT json_build_object(
    'viewer', json_build_object(
        'id', CAST(:viewer_id AS text),
        'name', CAST(:viewer_name AS text),
        'surname', CAST(:viewer_surname AS text),
        'email', CAST(:viewer_email AS text)
    ),
    'tenants', COALESCE((SELECT json_agg(plmn ORDER BY plmn) FROM allowed_tenants), '[]'::json),
    'kpis', json_build_object(
        'active_sessions', COALESCE((SELECT COUNT(*) FROM current_sessions), 0),
        'active_imsi', COALESCE((SELECT COUNT(DISTINCT imsi) FROM current_sessions), 0),
        'total_tx_mb', COALESCE((SELECT ROUND(SUM(tx_mb), 2) FROM current_sessions), 0),
        'total_rx_mb', COALESCE((SELECT ROUND(SUM(rx_mb), 2) FROM current_sessions), 0)
    ),
    'users', COALESCE((
        SELECT json_agg(row_to_json(ur) ORDER BY ur.active_session_count DESC, ur.last_seen DESC)
        FROM user_rollup ur
    ), '[]'::json),
    'recent_sessions', COALESCE((
        SELECT json_agg(row_to_json(cs) ORDER BY cs."start" DESC)
        FROM (
            SELECT id AS session_id, imsi, ue_id, amf_id, rnti, "start", "end", duration_seconds,
                   COALESCE(tx_mb, 0) AS tx_mb,
                   COALESCE(rx_mb, 0) AS rx_mb
            FROM current_sessions
            ORDER BY "start" DESC
            LIMIT 100
        ) cs
    ), '[]'::json)
) AS payload;
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $viewer['id'],
            'viewer_id' => $viewer['id'],
            'viewer_name' => $viewer['name'],
            'viewer_surname' => $viewer['surname'],
            'viewer_email' => $viewer['email'],
        ]);

        $payload = json_decode((string)$stmt->fetchColumn(), true) ?: [];
        $payload['_meta'] = ['cache' => 'miss', 'refreshed_at' => gmdate('c')];

        $sessionRows = $pdo->prepare(<<<'SQL'
SELECT cs.id, cs.imsi, cs.ue_id, cs.amf_id, cs.rnti, cs."start", cs."end",
       SUBSTRING(cs.imsi, 1, 5) AS plmn
FROM CustomerSession cs
JOIN UserPermissions up ON up.tenant = SUBSTRING(cs.imsi, 1, 5)
WHERE up.userId = :user_id AND cs."end" IS NULL
ORDER BY cs."start" DESC
SQL
        );
        $sessionRows->execute(['user_id' => $viewer['id']]);
        $openSessions = $sessionRows->fetchAll(PDO::FETCH_ASSOC);

        $metricData = [];
        $kpmData = [];
        foreach ($openSessions as $row) {
            $imsi = (string)$row['imsi'];
            $amfId = (string)$row['amf_id'];
            $metricData[$imsi] = $redis ? array_map('strval', $redis->hGetAll('dashboard:metrics:' . trim((string)$imsi)) ?: []) : [];
            $kpmData[$amfId] = $redis ? array_map('strval', $redis->hGetAll('dashboard:kpm:' . trim((string)$amfId)) ?: []) : [];
        }

        $payload['live_metrics'] = [
            'sessions' => $openSessions,
            'metrics' => $metricData,
            'kpm' => $kpmData,
        ];

        RedisCache::put($cacheKey, $payload);
        RedisCache::publish('tenant-dashboard-refresh', ['user_id' => $userId, 'at' => gmdate('c')]);
        return $payload;
    }
}