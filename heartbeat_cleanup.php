<?php
header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "scriptforge";

$secret = $_GET['secret'] ?? '';
$cleanupSecret = "mein_super_geheimer_key_2026";

$licenseAffectedRows = 0;
$devApprovalAffectedRows = 0;
$devRequestExpireAffectedRows = 0;
$devHeartbeatAffectedRows = 0;

if ($secret !== $cleanupSecret) {
    echo json_encode([
        "success" => false,
        "error" => "unauthorized"
    ]);
    exit;
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

    if ($conn->connect_error) {
        throw new RuntimeException("database_connection_failed");
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

    if (!$stmt) {
        throw new RuntimeException("license_cleanup_prepare_failed: " . $conn->error);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException("license_cleanup_execute_failed: " . $stmt->error);
    }

    $licenseAffectedRows = $stmt->affected_rows;
    $stmt->close();

    $devApprovalStmt = $conn->prepare("
        UPDATE scriptforge_dev_approvals
        SET status = 'expired'
        WHERE status = 'active'
        AND expires_at IS NOT NULL
        AND expires_at <= NOW()
    ");

    if ($devApprovalStmt) {
        if ($devApprovalStmt->execute()) {
            $devApprovalAffectedRows = $devApprovalStmt->affected_rows;
        } else {
            error_log("ScriptForge DEV cleanup skipped (dev_approvals): " . $devApprovalStmt->error);
        }
        $devApprovalStmt->close();
    } else {
        error_log("ScriptForge DEV cleanup skipped (dev_approvals prepare): " . $conn->error);
    }

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

    if ($devRequestExpireStmt) {
        if ($devRequestExpireStmt->execute()) {
            $devRequestExpireAffectedRows = $devRequestExpireStmt->affected_rows;
        } else {
            error_log("ScriptForge DEV cleanup skipped (dev_requests expire): " . $devRequestExpireStmt->error);
        }
        $devRequestExpireStmt->close();
    } else {
        error_log("ScriptForge DEV cleanup skipped (dev_requests expire prepare): " . $conn->error);
    }

    $devHeartbeatStmt = $conn->prepare("
        UPDATE scriptforge_dev_requests
        SET heartbeat_status = 'inactive'
        WHERE heartbeat_status = 'active'
        AND (
            last_seen IS NULL
            OR last_seen < (NOW() - INTERVAL 5 MINUTE)
        )
    ");

    if ($devHeartbeatStmt) {
        if ($devHeartbeatStmt->execute()) {
            $devHeartbeatAffectedRows = $devHeartbeatStmt->affected_rows;
        } else {
            error_log("ScriptForge DEV cleanup skipped (dev_heartbeats): " . $devHeartbeatStmt->error);
        }
        $devHeartbeatStmt->close();
    } else {
        error_log("ScriptForge DEV cleanup skipped (dev_heartbeats prepare): " . $conn->error);
    }

    echo json_encode([
        "success" => true,
        "message" => "Heartbeat cleanup completed.",
        "affected_rows" => $licenseAffectedRows,
        "dev_approvals_expired" => $devApprovalAffectedRows,
        "dev_requests_expired" => $devRequestExpireAffectedRows,
        "dev_heartbeats_inactive" => $devHeartbeatAffectedRows
    ]);

    $conn->close();
} catch (Throwable $error) {
    error_log("ScriptForge heartbeat_cleanup failed: " . $error->getMessage());

    echo json_encode([
        "success" => false,
        "error" => "cleanup_failed",
        "message" => $error->getMessage(),
        "affected_rows" => $licenseAffectedRows,
        "dev_approvals_expired" => $devApprovalAffectedRows,
        "dev_requests_expired" => $devRequestExpireAffectedRows,
        "dev_heartbeats_inactive" => $devHeartbeatAffectedRows
    ]);
}
