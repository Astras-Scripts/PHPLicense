<?php

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

$heartbeat = $_GET['heartbeat'] ?? '';


$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "scriptforge";

$script = $_GET['script'] ?? '';
$license = $_GET['license'] ?? '';
$resource = $_GET['resource'] ?? $script;
$serverIP = $_SERVER['REMOTE_ADDR'];
$serverName = trim($_GET['servername'] ?? '');
$devModeValue = strtolower(trim($_GET['devmode'] ?? $_GET['dev_mode'] ?? ''));
$devMode = in_array($devModeValue, ['1', 'true', 'yes', 'on'], true);

$placeholderKeys = [
    '',
    'DEINE-LIZENZ',
    'YOUR_LICENSE_KEY',
    'PUT_IN_YOUR_TBX_KEY'
];

if ($serverName === '' || strtolower($serverName) === 'unknown server') {
    $serverName = null;
}


$defaultWebhookUrl = "https://discord.com/api/webhooks/1510409400722915520/eb2btoaOHn5I0qkuHo6YHh70kQEv_K2mCSKDkwdsVQPqY1vamzb_lWfoi2ycOUkXAMqU";

function sendDiscordWebhook($webhookUrl, $title, $message, $color = 16753920) {
    if (empty($webhookUrl)) {
        return;
    }

    $payload = json_encode([
        "username" => "ScriptForge Logs",
        "embeds" => [[
            "title" => $title,
            "description" => $message,
            "color" => $color,
            "footer" => [
                "text" => "ScriptForge Verification"
            ],
            "timestamp" => gmdate("c")
        ]]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 5
            ]
        ]);

        @file_get_contents($webhookUrl, false, $context);
    } catch (Throwable $error) {
        error_log("ScriptForge webhook failed: " . $error->getMessage());
    }
}

function isPlaceholderLicenseKey($license, $placeholderKeys) {
    return in_array(trim($license), $placeholderKeys, true);
}

function createDevRequestToken() {
    return bin2hex(random_bytes(16));
}

function getActiveDevServer($conn, $serverIP) {
    $stmt = $conn->prepare("
        SELECT id, server_ip, label, active
        FROM scriptforge_dev_servers
        WHERE server_ip = ?
        AND active = 1
        LIMIT 1
    ");

    $stmt->bind_param("s", $serverIP);
    $stmt->execute();
    $result = $stmt->get_result();
    $server = $result->fetch_assoc();
    $stmt->close();

    return $server ?: null;
}

function getReusableDevRequest($conn, $serverIP, $resource) {
    $stmt = $conn->prepare("
        SELECT *
        FROM scriptforge_dev_requests
        WHERE server_ip = ?
        AND resource_name = ?
        AND status IN ('pending', 'approved', 'denied', 'revoked')
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->bind_param("ss", $serverIP, $resource);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    return $request ?: null;
}

function createDevRequest($conn, $serverIP, $serverName, $resource, $script, $productId, $license) {
    $token = createDevRequestToken();

    $stmt = $conn->prepare("
        INSERT INTO scriptforge_dev_requests
        (
            token,
            server_ip,
            server_name,
            resource_name,
            script_name,
            product_id,
            license_key,
            status,
            heartbeat_status,
            requested_at,
            last_check_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'pending',
            'inactive',
            NOW(),
            NOW()
        )
    ");

    $stmt->bind_param(
        "sssssis",
        $token,
        $serverIP,
        $serverName,
        $resource,
        $script,
        $productId,
        $license
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        echo json_encode([
            "error" => "dev_request_insert_failed",
            "message" => $error
        ]);
        exit;
    }

    $stmt->close();

    return getReusableDevRequest($conn, $serverIP, $resource);
}

function getActiveDevApproval($conn, $token, $serverIP, $resource) {
    $stmt = $conn->prepare("
        SELECT *
        FROM scriptforge_dev_approvals
        WHERE token = ?
        AND server_ip = ?
        AND resource_name = ?
        AND action = 'approved'
        AND status = 'active'
        AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->bind_param("sss", $token, $serverIP, $resource);
    $stmt->execute();
    $result = $stmt->get_result();
    $approval = $result->fetch_assoc();
    $stmt->close();

    return $approval ?: null;
}

function expireOldDevApprovals($conn) {
    try {
        $stmt = $conn->prepare("
            UPDATE scriptforge_dev_approvals
            SET status = 'expired'
            WHERE status = 'active'
            AND expires_at IS NOT NULL
            AND expires_at <= NOW()
        ");

        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
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

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log("ScriptForge DEV cleanup skipped: " . $error->getMessage());
    }
}

function updateDevHeartbeat($conn, $requestId, $heartbeat) {
    if ($heartbeat === 'active') {
        $stmt = $conn->prepare("
            UPDATE scriptforge_dev_requests
            SET heartbeat_status = 'active',
                started_at = IF(started_at IS NULL OR heartbeat_status = 'inactive', NOW(), started_at),
                last_seen = NOW(),
                last_check_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();
    } elseif ($heartbeat === 'heartbeat') {
        $stmt = $conn->prepare("
            UPDATE scriptforge_dev_requests
            SET heartbeat_status = 'active',
                last_seen = NOW(),
                last_check_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();
    } elseif ($heartbeat === 'inactive') {
        $stmt = $conn->prepare("
            UPDATE scriptforge_dev_requests
            SET heartbeat_status = 'inactive',
                last_check_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            UPDATE scriptforge_dev_requests
            SET last_check_at = NOW()
            WHERE id = ?
        ");

        $stmt->bind_param("i", $requestId);
        $stmt->execute();
        $stmt->close();
    }
}

if ($script === '') {
    echo json_encode([
        "error" => "missing_parameters"
    ]);
    exit;
}

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    echo json_encode([
        "error" => "database_connection_failed"
    ]);
    exit;
}

expireOldDevApprovals($conn);

// ===============================
// HEARTBEAT CLEANUP
// ===============================

$cleanupStmt = $conn->prepare("
    UPDATE scriptforge_licenses
    SET resource_status = 'inactive'
    WHERE resource_status = 'active'
    AND last_seen IS NOT NULL
    AND last_seen < (NOW() - INTERVAL 5 MINUTE)
");

$cleanupStmt->execute();
$cleanupStmt->close();


$productStmt = $conn->prepare("
    SELECT id, latest_version, changelog, status
    FROM scriptforge_products
    WHERE script_name = ?
");

$productStmt->bind_param("s", $script);
$productStmt->execute();

$productResult = $productStmt->get_result();

if (!$product = $productResult->fetch_assoc()) {
    echo json_encode([
        "error" => "script_not_found"
    ]);
    exit;
}

if (isPlaceholderLicenseKey($license, $placeholderKeys)) {
    $devRequest = getReusableDevRequest($conn, $serverIP, $resource);

    if (!$devRequest) {
        $devRequest = createDevRequest(
            $conn,
            $serverIP,
            $serverName,
            $resource,
            $script,
            (int)$product['id'],
            $license
        );

        sendDiscordWebhook(
            $defaultWebhookUrl,
            "Neue DEV Anfrage",
            "**Token:** " . $devRequest['token'] .
            "\n**Produkt:** " . $script .
            "\n**Resource:** " . $resource .
            "\n**IP:** " . $serverIP .
            "\n**Server:** " . ($serverName ?: "Unbekannt") .
            "\n\n**Status:** PENDING" .
            "\n**Hinweis:** Standard-Key erkannt, DEV Request angelegt.",
            16753920
        );
    }

    if ($devRequest['status'] === 'denied' || $devRequest['status'] === 'revoked') {
        updateDevHeartbeat($conn, (int)$devRequest['id'], '');

        echo json_encode([
            "script" => $script,
            "resource" => $resource,
            "version" => $product['latest_version'],
            "changelog" => $product['changelog'],
            "status" => $product['status'],
            "license_valid" => false,
            "license_status" => $devRequest['status'] === 'denied' ? "dev_denied" : "dev_revoked",
            "dev_mode" => true,
            "dev_request_token" => $devRequest['token'],
            "server_ip" => $serverIP,
            "ip_lock" => false
        ]);

        exit;
    }

    $activeApproval = getActiveDevApproval(
        $conn,
        $devRequest['token'],
        $serverIP,
        $resource
    );

    if ($activeApproval) {
        updateDevHeartbeat($conn, (int)$devRequest['id'], $heartbeat);

        echo json_encode([
            "script" => $script,
            "resource" => $resource,
            "version" => $product['latest_version'],
            "changelog" => $product['changelog'],
            "status" => $product['status'],
            "license_valid" => true,
            "license_status" => "dev_approved",
            "dev_mode" => true,
            "dev_request_token" => $devRequest['token'],
            "dev_approval_expires_at" => $activeApproval['expires_at'],
            "log_success" => false,
            "log_failed" => true,
            "webhook_url" => null,
            "server_ip" => $serverIP,
            "ip_lock" => false
        ]);

        exit;
    }

    updateDevHeartbeat($conn, (int)$devRequest['id'], '');

    echo json_encode([
        "script" => $script,
        "resource" => $resource,
        "version" => $product['latest_version'],
        "changelog" => $product['changelog'],
        "status" => $product['status'],
        "license_valid" => false,
        "license_status" => "dev_pending",
        "dev_mode" => true,
        "dev_request_token" => $devRequest['token'],
        "message" => "DEV request is waiting for Discord approval.",
        "server_ip" => $serverIP,
        "ip_lock" => false
    ]);

    exit;
}

/*
    WICHTIG:
    Lizenz wird pro Kombination geprüft:
    license_key + script_name + resource_name
*/
$licenseStmt = $conn->prepare("
    SELECT *
    FROM scriptforge_licenses
    WHERE license_key = ?
    AND script_name = ?
    AND resource_name = ?
    LIMIT 1
");

$licenseStmt->bind_param("sss", $license, $script, $resource);
$licenseStmt->execute();

$licenseResult = $licenseStmt->get_result();

$licenseValid = false;
$licenseData = null;

// Lizenz nicht vorhanden
if ($licenseResult->num_rows === 0) {
    if (!$devMode && isPlaceholderLicenseKey($license, $placeholderKeys)) {
        echo json_encode([
            "error" => "placeholder_license_key",
            "message" => "Placeholder license keys do not create pending entries.",
            "license_valid" => false,
            "license_status" => "invalid",
            "dev_mode" => $devMode,
            "server_ip" => $serverIP
        ]);

        exit;
    }

    if ($devMode) {
        $devRequest = getReusableDevRequest($conn, $serverIP, $resource);

            if (!$devRequest) {
                $devRequest = createDevRequest(
                    $conn,
                    $serverIP,
                    $serverName,
                    $resource,
                    $script,
                    (int)$product['id'],
                    $license
                );

                sendDiscordWebhook(
                    $defaultWebhookUrl,
                    "Neue DEV Anfrage",
                    "**Token:** " . $devRequest['token'] .
                    "\n**Produkt:** " . $script .
                    "\n**Resource:** " . $resource .
                    "\n**IP:** " . $serverIP .
                    "\n**Server:** " . ($serverName ?: "Unbekannt") .
                    "\n\n**Status:** PENDING" .
                    "\n**Hinweis:** Kein Eintrag in scriptforge_licenses.",
                    16753920
                );
            }

            if ($devRequest['status'] === 'denied' || $devRequest['status'] === 'revoked') {
                updateDevHeartbeat($conn, (int)$devRequest['id'], '');

                echo json_encode([
                    "script" => $script,
                    "resource" => $resource,
                    "version" => $product['latest_version'],
                    "changelog" => $product['changelog'],
                    "status" => $product['status'],
                    "license_valid" => false,
                    "license_status" => $devRequest['status'] === 'denied' ? "dev_denied" : "dev_revoked",
                    "dev_mode" => true,
                    "dev_request_token" => $devRequest['token'],
                    "server_ip" => $serverIP,
                    "ip_lock" => false
                ]);

                exit;
            }

            $activeApproval = getActiveDevApproval(
                $conn,
                $devRequest['token'],
                $serverIP,
                $resource
            );

            if ($activeApproval) {
                updateDevHeartbeat($conn, (int)$devRequest['id'], $heartbeat);

                echo json_encode([
                    "script" => $script,
                    "resource" => $resource,
                    "version" => $product['latest_version'],
                    "changelog" => $product['changelog'],
                    "status" => $product['status'],
                    "license_valid" => true,
                    "license_status" => "dev_approved",
                    "dev_mode" => true,
                    "dev_request_token" => $devRequest['token'],
                    "dev_approval_expires_at" => $activeApproval['expires_at'],
                    "log_success" => false,
                    "log_failed" => true,
                    "webhook_url" => null,
                    "server_ip" => $serverIP,
                    "ip_lock" => false
                ]);

                exit;
            }

            updateDevHeartbeat($conn, (int)$devRequest['id'], '');

            echo json_encode([
                "script" => $script,
                "resource" => $resource,
                "version" => $product['latest_version'],
                "changelog" => $product['changelog'],
                "status" => $product['status'],
                "license_valid" => false,
                "license_status" => "dev_pending",
                "dev_mode" => true,
                "dev_request_token" => $devRequest['token'],
                "message" => "DEV request is waiting for Discord approval.",
                "server_ip" => $serverIP,
                "ip_lock" => false
            ]);

            exit;
    }

    $pendingUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $insertStmt = $conn->prepare("
        INSERT INTO scriptforge_licenses
        (
            license_key,
            script_name,
            resource_name,
            status,
            server_name,
            server_ip,
            last_ip,
            first_check,
            last_check,
            total_checks,
            pending_until,
            webhook_url,
            log_failed
        )
            VALUES
        (
            ?,
            ?,
            ?,
            'pending',
            ?,
            ?,
            ?,
            NOW(),
            NOW(),
            1,
            ?,
            ?,
            1
        )
    ");

    $insertStmt->bind_param(
        "ssssssss",
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
        echo json_encode([
            "error" => "license_insert_failed",
            "message" => $insertStmt->error
        ]);
        exit;
    }

    sendDiscordWebhook(
        $defaultWebhookUrl,
        "⚠️ Neue Lizenzanfrage",
        "**Lizenz:** " . $license .
        "\n**Produkt:** " . $script .
        "\n**Resource:** " . $resource .
        "\n**IP:** " . $serverIP .
        "\n\n**Status:** PENDING" .
        "\n**Gültig bis:** " . $pendingUntil,
        16753920
    );

    echo json_encode([
        "notice" => "license_pending",
        "message" => "Lizenz nicht hinterlegt. Temporäre Aktivierung erstellt.",
        "license_valid" => true,
        "license_status" => "pending",
        "pending_until" => $pendingUntil,
        "server_ip" => $serverIP
    ]);

    exit;
}

if ($licenseResult->num_rows > 0) {
    $licenseData = $licenseResult->fetch_assoc();

    // Erste Aktivierung speichern
    if (empty($licenseData['first_check'])) {
        $firstCheckStmt = $conn->prepare("
            UPDATE scriptforge_licenses
            SET first_check = NOW()
            WHERE id = ?
        ");

        $firstCheckStmt->bind_param("i", $licenseData['id']);
        $firstCheckStmt->execute();

        $licenseData['first_check'] = date('Y-m-d H:i:s');
    }

    // Erste Server-IP speichern
    if (empty($licenseData['server_ip'])) {
        $ipStmt = $conn->prepare("
            UPDATE scriptforge_licenses
            SET server_ip = ?
            WHERE id = ?
        ");

        $ipStmt->bind_param("si", $serverIP, $licenseData['id']);
        $ipStmt->execute();

        $licenseData['server_ip'] = $serverIP;
    }

    // Jede Prüfung speichern + hochzählen
    $checkStmt = $conn->prepare("
        UPDATE scriptforge_licenses
        SET
            server_name = ?,
            last_ip = ?,
            last_check = NOW(),
            total_checks = total_checks + 1
        WHERE id = ?
    ");

    $checkStmt->bind_param(
        "ssi",
        $serverName,
        $serverIP,
        $licenseData['id']
    );


    $checkStmt->execute();

    // IP-Bindung prüfen
    if (
        isset($licenseData['ip_lock']) &&
        (int)$licenseData['ip_lock'] === 1 &&
        $licenseData['server_ip'] !== $serverIP
    ) {
        echo json_encode([
            "error" => "ip_mismatch",
            "license_valid" => false,

            "log_failed" => isset($licenseData) ? (bool)$licenseData["log_failed"] : true,
            "webhook_url" => isset($licenseData) ? $licenseData["webhook_url"] : null,

            "expected_ip" => $licenseData['server_ip'],
            "current_ip" => $serverIP,

            "server_ip" => $serverIP,
            "ip_lock" => true
        ]);

        exit;
    }

    // Heartbeat verarbeiten (ACTIVE + PENDING)
    // Heartbeat verarbeiten (ACTIVE + PENDING)
    if (
        $licenseData['status'] === 'active' ||
        $licenseData['status'] === 'pending'
    ) {
        if ($heartbeat === 'active') {

            $stmt = $conn->prepare("
                UPDATE scriptforge_licenses
                SET
                    resource_status = 'active',
                    started_at = IF(started_at IS NULL OR resource_status = 'inactive', NOW(), started_at),
                    last_seen = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param("i", $licenseData['id']);
            $stmt->execute();
            $stmt->close();
        }

        elseif ($heartbeat === 'heartbeat') {

            $stmt = $conn->prepare("
                UPDATE scriptforge_licenses
                SET
                    resource_status = 'active',
                    last_seen = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param("i", $licenseData['id']);
            $stmt->execute();
            $stmt->close();
        }

        elseif ($heartbeat === 'inactive') {

            $stmt = $conn->prepare("
                UPDATE scriptforge_licenses
                SET
                    resource_status = 'inactive'
                WHERE id = ?
            ");

            $stmt->bind_param("i", $licenseData['id']);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Lizenzstatus prüfen - aktiv
    if ($licenseData['status'] === 'active') {
        $licenseValid = true;
    }

    // Lizenzstatus prüfen - im Wartezustand
    if ($licenseData['status'] === 'pending') {

        // Pending abgelaufen
        if (
            empty($licenseData['pending_until']) ||
            strtotime($licenseData['pending_until']) <= time()
        ) {

            $expireStmt = $conn->prepare("
                UPDATE scriptforge_licenses
                SET
                    status = 'inactive',
                    resource_status = 'inactive'
                WHERE id = ?
            ");

            $expireStmt->bind_param(
                "i",
                $licenseData['id']
            );

            $expireStmt->execute();

            sendDiscordWebhook(
                !empty($licenseData["webhook_url"]) ? $licenseData["webhook_url"] : $defaultWebhookUrl,
                "⏰ Pending Lizenz abgelaufen",
                "**Lizenz:** " . $license .
                "\n**Produkt:** " . $script .
                "\n**Resource:** " . $licenseData['resource_name'] .
                "\n**IP:** " . $serverIP .
                "\n\n**Status:** INACTIVE" .
                "\n**Grund:** Pending-Zeit abgelaufen",
                15158332
            );

            $licenseData['status'] = 'inactive';
        }

        // Pending noch gültig
        else {

            $licenseValid = true;

         //    sendDiscordWebhook(
         //        !empty($licenseData["webhook_url"]) ? $licenseData["webhook_url"] : $defaultWebhookUrl,
         //        "⚠️ Lizenz weiterhin im Prüfstatus",
         //        "**Lizenz:** " . $license .
         //        "\n**Produkt:** " . $script .
         //        "\n**Resource:** " . $licenseData['resource_name'] .
          //       "\n**IP:** " . $serverIP .
         //        "\n\n**Status:** PENDING" .
         //        "\n**Gültig bis:** " . $licenseData['pending_until'] .
         //        "\n**Checks:** " . ($licenseData['total_checks'] + 1),
         //        16753920
         //    );
        }
    }

    // Lizenzstatus prüfen - Trial
    if ($licenseData['status'] === 'trial') {

        if (
            !empty($licenseData['expires_at']) &&
            strtotime($licenseData['expires_at']) > time()
        ) {
            $licenseValid = true;
        } else {

            $expireTrialStmt = $conn->prepare("
                UPDATE scriptforge_licenses
                SET
                    status = 'expired',
                    resource_status = 'inactive'
                WHERE id = ?
            ");

            $expireTrialStmt->bind_param(
                "i",
                $licenseData['id']
            );

            $expireTrialStmt->execute();

            sendDiscordWebhook(
                !empty($licenseData["webhook_url"]) ? $licenseData["webhook_url"] : $defaultWebhookUrl,
                "⏰ Trial Lizenz abgelaufen",
                "**Lizenz:** " . $license .
                "\n**Produkt:** " . $script .
                "\n**Resource:** " . $licenseData['resource_name'] .
                "\n**IP:** " . $serverIP .
                "\n\n**Status:** EXPIRED" .
                "\n**Grund:** Trial-Zeit abgelaufen",
                15158332
            );

            $licenseData['status'] = 'expired';
        }
    }
}




echo json_encode([
    "script" => $script,
    "resource" => $resource,
    "version" => $product['latest_version'],
    "changelog" => $product['changelog'],
    "status" => $product['status'],
    "license_valid" => $licenseValid,
    "license_status" => isset($licenseData) ? $licenseData["status"] : null,
    "log_success" => isset($licenseData) ? (bool)$licenseData["log_success"] : false,
    "log_failed" => isset($licenseData) ? (bool)$licenseData["log_failed"] : true,
    "webhook_url" => isset($licenseData) ? $licenseData["webhook_url"] : null,
    "server_ip" => $serverIP,
    "ip_lock" => isset($licenseData) ? (bool)$licenseData["ip_lock"] : false
]);

$conn->close();





