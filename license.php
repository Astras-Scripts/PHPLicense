<?php

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

function respondJson(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $error) {
    error_log('ScriptForge license exception: ' . $error->getMessage());

    respondJson([
        'error' => 'server_exception',
        'message' => $error->getMessage()
    ], 500);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    error_log('ScriptForge license fatal: ' . $error['message']);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'error' => 'fatal_error',
        'message' => $error['message'],
        'file' => basename($error['file'] ?? ''),
        'line' => $error['line'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ===============================
// CONFIG
// ===============================

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'scriptforge';

$defaultWebhookUrl = 'https://discord.com/api/webhooks/1510409400722915520/eb2btoaOHn5I0qkuHo6YHh70kQEv_K2mCSKDkwdsVQPqY1vamzb_lWfoi2ycOUkXAMqU';

$placeholderKeys = [
    'DEINE-LIZENZ',
    'YOUR_LICENSE_KEY',
    'PUT_IN_YOUR_TBX_KEY'
];

// ===============================
// INPUT
// ===============================

$heartbeat = trim($_GET['heartbeat'] ?? '');
$script = trim($_GET['script'] ?? '');
$license = trim($_GET['license'] ?? '');
$resource = trim($_GET['resource'] ?? $script);
$clientVersion = trim($_GET['version'] ?? '');
$serverIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$serverName = trim($_GET['servername'] ?? '');
$devModeValue = strtolower(trim($_GET['devmode'] ?? $_GET['dev_mode'] ?? ''));
$devMode = in_array($devModeValue, ['1', 'true', 'yes', 'on'], true);

if ($serverName === '' || strtolower($serverName) === 'unknown server') {
    $serverName = null;
}

if ($script === '') {
    respondJson([
        'error' => 'missing_parameters',
        'message' => 'Missing script parameter.'
    ], 400);
}

if ($resource === '') {
    $resource = $script;
}

// ===============================
// HELPERS
// ===============================

function isPlaceholderLicenseKey(string $license, array $placeholderKeys): bool
{
    return in_array(trim($license), $placeholderKeys, true);
}

function createDevRequestToken(): string
{
    return bin2hex(random_bytes(16));
}

function buildDevRequestComponents(string $token): array
{
    return [[
        'type' => 1,
        'components' => [
            [
                'type' => 2,
                'style' => 3,
                'label' => 'Freigeben',
                'custom_id' => 'sfdev:approve:' . $token
            ],
            [
                'type' => 2,
                'style' => 4,
                'label' => 'Ablehnen',
                'custom_id' => 'sfdev:deny:' . $token
            ]
        ]
    ]];
}

function sendDiscordWebhook(?string $webhookUrl, string $title, string $message, int $color = 16753920, ?array $components = null): void
{
    if (empty($webhookUrl)) {
        return;
    }

    $payload = [
        'username' => 'ScriptForge Logs',
        'embeds' => [[
            'title' => $title,
            'description' => $message,
            'color' => $color,
            'footer' => [
                'text' => 'ScriptForge Verification'
            ],
            'timestamp' => gmdate('c')
        ]]
    ];

    if (!empty($components)) {
        $payload['components'] = $components;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'ignore_errors' => true,
                'timeout' => 5
            ]
        ]);

        @file_get_contents($webhookUrl, false, $context);
    } catch (Throwable $error) {
        error_log('ScriptForge webhook failed: ' . $error->getMessage());
    }
}

function getReusableDevRequest(mysqli $conn, string $serverIP, string $resource): ?array
{
    $stmt = $conn->prepare("\n        SELECT *\n        FROM scriptforge_dev_requests\n        WHERE server_ip = ?\n        AND resource_name = ?\n        AND status IN ('pending', 'approved', 'denied', 'revoked', 'expired')\n        ORDER BY id DESC\n        LIMIT 1\n    ");

    if (!$stmt) {
        error_log('ScriptForge DEV reusable prepare failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('ss', $serverIP, $resource);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $request ?: null;
}

function createDevRequest(
    mysqli $conn,
    string $serverIP,
    ?string $serverName,
    string $resource,
    string $script,
    string $clientVersion,
    string $license,
    string $reason
): ?array {
    $token = createDevRequestToken();

    // DB schema: request_token, script_name, resource_name, license_key, server_name, server_ip, version, reason, status, created_at, last_check
    $stmt = $conn->prepare("\n        INSERT INTO scriptforge_dev_requests\n        (\n            request_token,\n            script_name,\n            resource_name,\n            license_key,\n            server_name,\n            server_ip,\n            version,\n            reason,\n            status,\n            created_at,\n            last_check\n        )\n        VALUES\n        (\n            ?,\n            ?,\n            ?,\n            ?,\n            ?,\n            ?,\n            ?,\n            ?,\n            'pending',\n            NOW(),\n            NOW()\n        )\n    ");

    if (!$stmt) {
        respondJson([
            'error' => 'dev_request_prepare_failed',
            'message' => $conn->error
        ], 500);
    }

    $stmt->bind_param(
        'ssssssss',
        $token,
        $script,
        $resource,
        $license,
        $serverName,
        $serverIP,
        $clientVersion,
        $reason
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        respondJson([
            'error' => 'dev_request_insert_failed',
            'message' => $error
        ], 500);
    }

    $stmt->close();

    return getReusableDevRequest($conn, $serverIP, $resource);
}

function updateDevHeartbeat(mysqli $conn, int $requestId): void
{
    $stmt = $conn->prepare("\n        UPDATE scriptforge_dev_requests\n        SET last_check = NOW()\n        WHERE id = ?\n    ");

    if (!$stmt) {
        error_log('ScriptForge DEV heartbeat prepare failed: ' . $conn->error);
        return;
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->close();
}

function expireOldDevRequests(mysqli $conn): void
{
    try {
        $stmt = $conn->prepare("\n            UPDATE scriptforge_dev_requests\n            SET status = 'expired'\n            WHERE status = 'approved'\n            AND expires_at IS NOT NULL\n            AND expires_at <= NOW()\n        ");

        if (!$stmt) {
            error_log('ScriptForge DEV cleanup skipped: ' . $conn->error);
            return;
        }

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('ScriptForge DEV cleanup skipped: ' . $error->getMessage());
    }
}

function isDevApproved(array $devRequest): bool
{
    if (($devRequest['status'] ?? '') !== 'approved') {
        return false;
    }

    if (empty($devRequest['expires_at'])) {
        return true;
    }

    return strtotime($devRequest['expires_at']) > time();
}

function respondDevRequest(array $devRequest, array $product, string $script, string $resource, string $serverIP): void
{
    $token = $devRequest['request_token'] ?? '';
    $status = $devRequest['status'] ?? 'pending';

    if ($status === 'denied' || $status === 'revoked') {
        respondJson([
            'script' => $script,
            'resource' => $resource,
            'version' => $product['latest_version'],
            'changelog' => $product['changelog'],
            'status' => $product['status'],
            'license_valid' => false,
            'license_status' => $status === 'denied' ? 'dev_denied' : 'dev_revoked',
            'dev_mode' => true,
            'dev_request_token' => $token,
            'server_ip' => $serverIP,
            'ip_lock' => false
        ]);
    }

    if (isDevApproved($devRequest)) {
        respondJson([
            'script' => $script,
            'resource' => $resource,
            'version' => $product['latest_version'],
            'changelog' => $product['changelog'],
            'status' => $product['status'],
            'license_valid' => true,
            'license_status' => 'dev_approved',
            'dev_mode' => true,
            'dev_request_token' => $token,
            'dev_approval_expires_at' => $devRequest['expires_at'] ?? null,
            'log_success' => false,
            'log_failed' => true,
            'webhook_url' => null,
            'server_ip' => $serverIP,
            'ip_lock' => false
        ]);
    }

    respondJson([
        'script' => $script,
        'resource' => $resource,
        'version' => $product['latest_version'],
        'changelog' => $product['changelog'],
        'status' => $product['status'],
        'license_valid' => false,
        'license_status' => $status === 'expired' ? 'dev_expired' : 'dev_pending',
        'dev_mode' => true,
        'dev_request_token' => $token,
        'message' => 'DEV request is waiting for Discord approval.',
        'server_ip' => $serverIP,
        'ip_lock' => false
    ]);
}

// ===============================
// DATABASE
// ===============================

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    respondJson([
        'error' => 'database_connection_failed',
        'message' => $conn->connect_error
    ], 500);
}

$conn->set_charset('utf8mb4');

expireOldDevRequests($conn);

// ===============================
// HEARTBEAT CLEANUP
// ===============================

$cleanupStmt = $conn->prepare("\n    UPDATE scriptforge_licenses\n    SET resource_status = 'inactive'\n    WHERE resource_status = 'active'\n    AND last_seen IS NOT NULL\n    AND last_seen < (NOW() - INTERVAL 5 MINUTE)\n");

if ($cleanupStmt) {
    $cleanupStmt->execute();
    $cleanupStmt->close();
} else {
    error_log('ScriptForge license cleanup skipped: ' . $conn->error);
}

// ===============================
// PRODUCT LOOKUP
// ===============================

$productStmt = $conn->prepare("\n    SELECT id, latest_version, changelog, status\n    FROM scriptforge_products\n    WHERE script_name = ?\n    LIMIT 1\n");

if (!$productStmt) {
    respondJson([
        'error' => 'product_prepare_failed',
        'message' => $conn->error
    ], 500);
}

$productStmt->bind_param('s', $script);
$productStmt->execute();
$productResult = $productStmt->get_result();
$product = $productResult ? $productResult->fetch_assoc() : null;
$productStmt->close();

if (!$product) {
    respondJson([
        'error' => 'script_not_found',
        'script' => $script
    ], 404);
}

// ===============================
// PLACEHOLDER KEY => DEV REQUEST
// ===============================

if (isPlaceholderLicenseKey($license, $placeholderKeys)) {
    $devRequest = getReusableDevRequest($conn, $serverIP, $resource);

    if (!$devRequest) {
        $devRequest = createDevRequest(
            $conn,
            $serverIP,
            $serverName,
            $resource,
            $script,
            $clientVersion,
            $license,
            'placeholder_key'
        );

        if ($devRequest) {
            $token = $devRequest['request_token'] ?? '';

            sendDiscordWebhook(
                $defaultWebhookUrl,
                'Neue DEV Anfrage',
                '**Token:** ' . $token .
                "\n**Produkt:** " . $script .
                "\n**Resource:** " . $resource .
                "\n**IP:** " . $serverIP .
                "\n**Server:** " . ($serverName ?: 'Unbekannt') .
                "\n\n**Status:** PENDING" .
                "\n**Hinweis:** Standard-Key erkannt, DEV Request angelegt.",
                16753920,
                buildDevRequestComponents($token)
            );
        }
    }

    if ($devRequest) {
        updateDevHeartbeat($conn, (int)$devRequest['id']);
        respondDevRequest($devRequest, $product, $script, $resource, $serverIP);
    }

    respondJson([
        'error' => 'dev_request_failed',
        'license_valid' => false,
        'license_status' => 'dev_error',
        'server_ip' => $serverIP
    ], 500);
}

// ===============================
// NORMAL LICENSE LOOKUP
// ===============================

$licenseStmt = $conn->prepare("\n    SELECT *\n    FROM scriptforge_licenses\n    WHERE license_key = ?\n    AND script_name = ?\n    AND resource_name = ?\n    LIMIT 1\n");

if (!$licenseStmt) {
    respondJson([
        'error' => 'license_prepare_failed',
        'message' => $conn->error
    ], 500);
}

$licenseStmt->bind_param('sss', $license, $script, $resource);
$licenseStmt->execute();
$licenseResult = $licenseStmt->get_result();

$licenseValid = false;
$licenseData = null;

// ===============================
// LICENSE NOT FOUND
// ===============================

if ($licenseResult->num_rows === 0) {
    $licenseStmt->close();

    if ($devMode) {
        $devRequest = getReusableDevRequest($conn, $serverIP, $resource);

        if (!$devRequest) {
            $devRequest = createDevRequest(
                $conn,
                $serverIP,
                $serverName,
                $resource,
                $script,
                $clientVersion,
                $license,
                'license_not_found'
            );

            if ($devRequest) {
                $token = $devRequest['request_token'] ?? '';

                sendDiscordWebhook(
                    $defaultWebhookUrl,
                    'Neue DEV Anfrage',
                    '**Token:** ' . $token .
                    "\n**Produkt:** " . $script .
                    "\n**Resource:** " . $resource .
                    "\n**IP:** " . $serverIP .
                    "\n**Server:** " . ($serverName ?: 'Unbekannt') .
                    "\n\n**Status:** PENDING" .
                    "\n**Hinweis:** Kein Eintrag in scriptforge_licenses.",
                    16753920,
                    buildDevRequestComponents($token)
                );
            }
        }

        if ($devRequest) {
            updateDevHeartbeat($conn, (int)$devRequest['id']);
            respondDevRequest($devRequest, $product, $script, $resource, $serverIP);
        }
    }

    $pendingUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $insertStmt = $conn->prepare("\n        INSERT INTO scriptforge_licenses\n        (\n            license_key,\n            script_name,\n            resource_name,\n            status,\n            server_name,\n            server_ip,\n            last_ip,\n            first_check,\n            last_check,\n            total_checks,\n            pending_until,\n            webhook_url,\n            log_failed\n        )\n        VALUES\n        (\n            ?,\n            ?,\n            ?,\n            'pending',\n            ?,\n            ?,\n            ?,\n            NOW(),\n            NOW(),\n            1,\n            ?,\n            ?,\n            1\n        )\n    ");

    if (!$insertStmt) {
        respondJson([
            'error' => 'license_insert_prepare_failed',
            'message' => $conn->error
        ], 500);
    }

    $insertStmt->bind_param(
        'ssssssss',
        $license,
        $script,
        $resource,
        $serverName,
        $serverIP,
        $serverIP,
        $pendingUntil,
        $defaultWebhookUrl
    );

    if (!$insertStmt->execute()) {
        $error = $insertStmt->error;
        $insertStmt->close();

        respondJson([
            'error' => 'license_insert_failed',
            'message' => $error
        ], 500);
    }

    $insertStmt->close();

    sendDiscordWebhook(
        $defaultWebhookUrl,
        '⚠️ Neue Lizenzanfrage',
        '**Lizenz:** ' . $license .
        "\n**Produkt:** " . $script .
        "\n**Resource:** " . $resource .
        "\n**IP:** " . $serverIP .
        "\n**Server:** " . ($serverName ?: 'Unbekannt') .
        "\n\n**Status:** PENDING" .
        "\n**Gültig bis:** " . $pendingUntil,
        16753920
    );

    respondJson([
        'notice' => 'license_pending',
        'message' => 'Lizenz nicht hinterlegt. Temporäre Aktivierung erstellt.',
        'script' => $script,
        'resource' => $resource,
        'version' => $product['latest_version'],
        'changelog' => $product['changelog'],
        'status' => $product['status'],
        'license_valid' => true,
        'license_status' => 'pending',
        'pending_until' => $pendingUntil,
        'server_ip' => $serverIP,
        'ip_lock' => false
    ]);
}

// ===============================
// LICENSE FOUND
// ===============================

$licenseData = $licenseResult->fetch_assoc();
$licenseStmt->close();

if (!$licenseData) {
    respondJson([
        'error' => 'license_fetch_failed',
        'license_valid' => false,
        'license_status' => 'invalid',
        'server_ip' => $serverIP
    ], 500);
}

if (empty($licenseData['first_check'])) {
    $firstCheckStmt = $conn->prepare("\n        UPDATE scriptforge_licenses\n        SET first_check = NOW()\n        WHERE id = ?\n    ");

    if ($firstCheckStmt) {
        $firstCheckStmt->bind_param('i', $licenseData['id']);
        $firstCheckStmt->execute();
        $firstCheckStmt->close();
        $licenseData['first_check'] = date('Y-m-d H:i:s');
    }
}

if (empty($licenseData['server_ip'])) {
    $ipStmt = $conn->prepare("\n        UPDATE scriptforge_licenses\n        SET server_ip = ?\n        WHERE id = ?\n    ");

    if ($ipStmt) {
        $ipStmt->bind_param('si', $serverIP, $licenseData['id']);
        $ipStmt->execute();
        $ipStmt->close();
        $licenseData['server_ip'] = $serverIP;
    }
}

$checkStmt = $conn->prepare("\n    UPDATE scriptforge_licenses\n    SET\n        server_name = ?,\n        last_ip = ?,\n        last_check = NOW(),\n        total_checks = total_checks + 1\n    WHERE id = ?\n");

if ($checkStmt) {
    $checkStmt->bind_param('ssi', $serverName, $serverIP, $licenseData['id']);
    $checkStmt->execute();
    $checkStmt->close();
}

// IP lock
if (
    isset($licenseData['ip_lock']) &&
    (int)$licenseData['ip_lock'] === 1 &&
    !empty($licenseData['server_ip']) &&
    $licenseData['server_ip'] !== $serverIP
) {
    respondJson([
        'error' => 'ip_mismatch',
        'license_valid' => false,
        'license_status' => 'ip_mismatch',
        'log_failed' => (bool)($licenseData['log_failed'] ?? true),
        'webhook_url' => $licenseData['webhook_url'] ?? null,
        'expected_ip' => $licenseData['server_ip'],
        'current_ip' => $serverIP,
        'server_ip' => $serverIP,
        'ip_lock' => true
    ]);
}

// Heartbeat processing for active + pending + trial
if (in_array($licenseData['status'], ['active', 'pending', 'trial'], true)) {
    if ($heartbeat === 'active') {
        $stmt = $conn->prepare("\n            UPDATE scriptforge_licenses\n            SET\n                resource_status = 'active',\n                started_at = IF(started_at IS NULL OR resource_status = 'inactive', NOW(), started_at),\n                last_seen = NOW()\n            WHERE id = ?\n        ");
    } elseif ($heartbeat === 'heartbeat') {
        $stmt = $conn->prepare("\n            UPDATE scriptforge_licenses\n            SET\n                resource_status = 'active',\n                last_seen = NOW()\n            WHERE id = ?\n        ");
    } elseif ($heartbeat === 'inactive') {
        $stmt = $conn->prepare("\n            UPDATE scriptforge_licenses\n            SET resource_status = 'inactive'\n            WHERE id = ?\n        ");
    } else {
        $stmt = null;
    }

    if ($stmt) {
        $stmt->bind_param('i', $licenseData['id']);
        $stmt->execute();
        $stmt->close();
    }
}

// License status
if ($licenseData['status'] === 'active') {
    $licenseValid = true;
}

if ($licenseData['status'] === 'pending') {
    if (empty($licenseData['pending_until']) || strtotime($licenseData['pending_until']) <= time()) {
        $expireStmt = $conn->prepare("\n            UPDATE scriptforge_licenses\n            SET\n                status = 'inactive',\n                resource_status = 'inactive'\n            WHERE id = ?\n        ");

        if ($expireStmt) {
            $expireStmt->bind_param('i', $licenseData['id']);
            $expireStmt->execute();
            $expireStmt->close();
        }

        sendDiscordWebhook(
            !empty($licenseData['webhook_url']) ? $licenseData['webhook_url'] : $defaultWebhookUrl,
            '⏰ Pending Lizenz abgelaufen',
            '**Lizenz:** ' . $license .
            "\n**Produkt:** " . $script .
            "\n**Resource:** " . ($licenseData['resource_name'] ?? $resource) .
            "\n**IP:** " . $serverIP .
            "\n\n**Status:** INACTIVE" .
            "\n**Grund:** Pending-Zeit abgelaufen",
            15158332
        );

        $licenseData['status'] = 'inactive';
    } else {
        $licenseValid = true;
    }
}

if ($licenseData['status'] === 'trial') {
    if (!empty($licenseData['expires_at']) && strtotime($licenseData['expires_at']) > time()) {
        $licenseValid = true;
    } else {
        $expireTrialStmt = $conn->prepare("\n            UPDATE scriptforge_licenses\n            SET\n                status = 'expired',\n                resource_status = 'inactive'\n            WHERE id = ?\n        ");

        if ($expireTrialStmt) {
            $expireTrialStmt->bind_param('i', $licenseData['id']);
            $expireTrialStmt->execute();
            $expireTrialStmt->close();
        }

        sendDiscordWebhook(
            !empty($licenseData['webhook_url']) ? $licenseData['webhook_url'] : $defaultWebhookUrl,
            '⏰ Trial Lizenz abgelaufen',
            '**Lizenz:** ' . $license .
            "\n**Produkt:** " . $script .
            "\n**Resource:** " . ($licenseData['resource_name'] ?? $resource) .
            "\n**IP:** " . $serverIP .
            "\n\n**Status:** EXPIRED" .
            "\n**Grund:** Trial-Zeit abgelaufen",
            15158332
        );

        $licenseData['status'] = 'expired';
    }
}

respondJson([
    'script' => $script,
    'resource' => $resource,
    'version' => $product['latest_version'],
    'changelog' => $product['changelog'],
    'status' => $product['status'],
    'license_valid' => $licenseValid,
    'license_status' => $licenseData['status'] ?? null,
    'log_success' => (bool)($licenseData['log_success'] ?? false),
    'log_failed' => (bool)($licenseData['log_failed'] ?? true),
    'webhook_url' => $licenseData['webhook_url'] ?? null,
    'server_ip' => $serverIP,
    'ip_lock' => (bool)($licenseData['ip_lock'] ?? false)
]);
