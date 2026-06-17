<?php
header('Content-Type: application/json');

$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "scriptforge";

$secret = $_GET['secret'] ?? '';
$cleanupSecret = "mein_super_geheimer_key_2026";

if ($secret !== $cleanupSecret) {
    echo json_encode([
        "success" => false,
        "error" => "unauthorized"
    ]);
    exit;
}

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    echo json_encode([
        "success" => false,
        "error" => "database_connection_failed"
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE scriptforge_licenses
    SET resource_status = 'inactive'
    WHERE resource_status = 'active'
    AND (
        last_seen IS NULL
        OR last_seen < (NOW() - INTERVAL 5 MINUTE)
    )
");

$stmt->execute();

$licenseAffectedRows = $stmt->affected_rows;

$stmt->close();

$devCleanupError = null;

try {
    $devApprovalStmt = $conn->prepare("
        UPDATE scriptforge_dev_approvals
        SET status = 'expired'
        WHERE status = 'active'
        AND expires_at IS NOT NULL
        AND expires_at <= NOW()
    ");

    $devApprovalStmt->execute();
    $devApprovalAffectedRows = $devApprovalStmt->affected_rows;
    $devApprovalStmt->close();

    $devRequestExpireStmt = $conn->prepare("
        UPDATE scriptforge_dev_requests r
        LEFT JOIN scriptforge_dev_approvals a
            ON a.token = r.token
            AND a.status = 'active'
            AND a.expires_at > NOW()
        SET r.status = 'expired',
            r.heartbeat_status = 'inactive'
        WHERE r.status = 'approved'
        AND a.id IS NULL
    ");

    $devRequestExpireStmt->execute();
    $devRequestExpireAffectedRows = $devRequestExpireStmt->affected_rows;
    $devRequestExpireStmt->close();

    $devHeartbeatStmt = $conn->prepare("
        UPDATE scriptforge_dev_requests
        SET heartbeat_status = 'inactive'
        WHERE heartbeat_status = 'active'
        AND (
            last_seen IS NULL
            OR last_seen < (NOW() - INTERVAL 5 MINUTE)
        )
    ");

    $devHeartbeatStmt->execute();
    $devHeartbeatAffectedRows = $devHeartbeatStmt->affected_rows;
    $devHeartbeatStmt->close();
} catch (Throwable $error) {
    $devCleanupError = $error->getMessage();
    error_log("ScriptForge DEV cleanup skipped: " . $devCleanupError);
}

echo json_encode([
    "success" => true,
    "message" => "Heartbeat cleanup completed.",
    "affected_rows" => $licenseAffectedRows,
    "dev_approvals_expired" => $devApprovalAffectedRows,
    "dev_requests_expired" => $devRequestExpireAffectedRows,
    "dev_heartbeats_inactive" => $devHeartbeatAffectedRows,
    "dev_cleanup_error" => $devCleanupError
]);

$conn->close();
