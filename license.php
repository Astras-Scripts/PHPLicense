<?php

header('Content-Type: application/json; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'scriptforge';

$license = trim((string)($_GET['license'] ?? ''));
$script = trim((string)($_GET['script'] ?? ''));
$resource = trim((string)($_GET['resource'] ?? $script));
$version = trim((string)($_GET['version'] ?? ''));
$heartbeat = trim((string)($_GET['heartbeat'] ?? ''));
$serverName = trim((string)($_GET['servername'] ?? ''));
$serverIP = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

function respond(array $payload): void
{
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sendWebhook(string $url, string $title, string $message, int $color = 15158332): void
{
    if ($url === '') {
        return;
    }

    $body = json_encode([
        'username' => 'ScriptForge Logs',
        'embeds' => [[
            'title' => $title,
            'description' => $message,
            'color' => $color,
            'footer' => ['text' => 'ScriptForge Verification'],
            'timestamp' => gmdate('c'),
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function isPlaceholderLicense(string $license): bool
{
    return in_array($license, [
        '',
        'DEINE-LIZENZ',
        'YOUR_LICENSE_KEY',
        'PUT_IN_YOUR_TBX_KEY',
    ], true);
}

function dbColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['Field'])) {
                $columns[] = (string)$row['Field'];
            }
        }
        $result->free();
    }

    return $columns;
}

function hasColumn(array $columns, string $name): bool
{
    return in_array($name, $columns, true);
}

function productResponse(array $product, string $serverIP, ?bool $licenseValid = null, ?string $licenseStatus = null, array $extra = []): array
{
    return array_merge([
        'status' => 'online',
        'license_valid' => $licenseValid,
        'license_status' => $licenseStatus,
        'version' => $product['latest_version'] ?? null,
        'changelog' => $product['changelog'] ?? null,
        'log_success' => (bool)($product['log_success'] ?? false),
        'log_failed' => (bool)($product['log_failed'] ?? true),
        'webhook_url' => $product['webhook_url'] ?? null,
        'server_ip' => $serverIP,
        'ip_lock' => (bool)($extra['ip_lock'] ?? false),
    ], $extra);
}

if ($script === '' || $license === '') {
    respond([
        'status' => 'offline',
        'license_valid' => false,
        'error' => 'missing_parameters',
    ]);
}

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    respond([
        'status' => 'offline',
        'license_valid' => false,
        'error' => 'database_connection_failed',
    ]);
}

$productColumns = dbColumns($conn, 'scriptforge_products');
$productSelect = [
    'id',
    'script_name',
    'latest_version',
    'changelog',
    'status',
];

foreach (['webhook_url', 'log_success', 'log_failed'] as $optionalColumn) {
    if (hasColumn($productColumns, $optionalColumn)) {
        $productSelect[] = $optionalColumn;
    }
}

$productStmt = $conn->prepare(
    'SELECT ' . implode(', ', $productSelect) . '
     FROM scriptforge_products
     WHERE script_name = ?
     LIMIT 1'
);

$productStmt->bind_param('s', $script);
$productStmt->execute();
$productResult = $productStmt->get_result();
$product = $productResult ? $productResult->fetch_assoc() : null;
$productStmt->close();

if (!$product) {
    respond([
        'status' => 'offline',
        'license_valid' => false,
        'error' => 'script_not_found',
        'server_ip' => $serverIP,
    ]);
}

$product['webhook_url'] = (string)($product['webhook_url'] ?? '');
$product['log_success'] = (bool)($product['log_success'] ?? false);
$product['log_failed'] = (bool)($product['log_failed'] ?? true);

if (($product['status'] ?? 'online') !== 'online') {
    if (!empty($product['log_failed']) && !empty($product['webhook_url'])) {
        sendWebhook(
            (string)$product['webhook_url'],
            'ScriptForge verification failed',
            "**Reason:** Product not online\n**Resource:** {$resource}\n**Version:** {$version}\n**IP:** {$serverIP}",
            15158332
        );
    }

    respond(array_merge(
        productResponse($product, $serverIP, false, 'product_not_online'),
        [
            'status' => (string)$product['status'],
            'error' => 'product_not_online',
        ]
    ));
}

$licenseColumns = dbColumns($conn, 'scriptforge_licenses');

$licenseStmt = $conn->prepare(
    'SELECT *
     FROM scriptforge_licenses
     WHERE license_key = ?
       AND script_name = ?
       AND resource_name = ?
     LIMIT 1'
);

$licenseStmt->bind_param('sss', $license, $script, $resource);
$licenseStmt->execute();
$licenseResult = $licenseStmt->get_result();
$row = $licenseResult ? $licenseResult->fetch_assoc() : null;
$licenseStmt->close();

if (!$row) {
    if (isPlaceholderLicense($license)) {
        respond(array_merge(
            productResponse($product, $serverIP, false, 'invalid'),
            [
                'error' => 'placeholder_license',
            ]
        ));
    }

    $pendingUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $webhookUrl = (string)($product['webhook_url'] ?? '');

    $insertColumns = ['license_key', 'script_name', 'resource_name', 'status'];
    $insertValues = ['?', '?', '?', '"pending"'];
    $insertTypes = 'sss';
    $insertParams = [$license, $script, $resource];

    foreach ([
        'server_name' => $serverName,
        'server_ip' => $serverIP,
        'last_ip' => $serverIP,
        'first_check' => null,
        'last_check' => null,
        'total_checks' => 1,
        'pending_until' => $pendingUntil,
        'webhook_url' => $webhookUrl,
        'log_success' => 0,
        'log_failed' => 1,
        'ip_lock' => 0,
    ] as $column => $value) {
        if (!hasColumn($licenseColumns, $column)) {
            continue;
        }

        $insertColumns[] = $column;

        if (in_array($column, ['first_check', 'last_check'], true)) {
            $insertValues[] = 'NOW()';
            continue;
        }

        $insertValues[] = '?';
        if (is_int($value)) {
            $insertTypes .= 'i';
        } else {
            $insertTypes .= 's';
        }
        $insertParams[] = $value;
    }

    $insertSql = sprintf(
        'INSERT INTO scriptforge_licenses (%s) VALUES (%s)',
        implode(', ', $insertColumns),
        implode(', ', $insertValues)
    );

    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bind_param($insertTypes, ...$insertParams);

    if (!$insertStmt->execute()) {
        respond(array_merge(
            productResponse($product, $serverIP, false, 'license_insert_failed'),
            [
                'error' => 'license_insert_failed',
            ]
        ));
    }

    $insertStmt->close();

    if ($webhookUrl !== '') {
        sendWebhook(
            $webhookUrl,
            'New ScriptForge license request',
            "**License:** {$license}\n**Product:** {$script}\n**Resource:** {$resource}\n**IP:** {$serverIP}\n**Status:** PENDING\n**Valid until:** {$pendingUntil}",
            16753920
        );
    }

    respond([
        'status' => 'online',
        'license_valid' => true,
        'notice' => 'license_pending',
        'pending_allowed' => true,
        'pending_until' => $pendingUntil,
        'version' => $product['latest_version'] ?? $version,
        'changelog' => $product['changelog'] ?? null,
        'log_success' => false,
        'log_failed' => true,
        'webhook_url' => $webhookUrl !== '' ? $webhookUrl : ($product['webhook_url'] ?? null),
        'server_ip' => $serverIP,
        'ip_lock' => false,
    ]);
}

$updateStmt = $conn->prepare(
    'UPDATE scriptforge_licenses
     SET ' . implode(', ', array_filter([
         hasColumn($licenseColumns, 'server_name') ? 'server_name = ?' : null,
         hasColumn($licenseColumns, 'last_ip') ? 'last_ip = ?' : null,
         hasColumn($licenseColumns, 'last_check') ? 'last_check = NOW()' : null,
         hasColumn($licenseColumns, 'total_checks') ? 'total_checks = total_checks + 1' : null,
     ])) . '
     WHERE id = ?'
);

$rowId = (int)$row['id'];
$updateTypes = '';
$updateParams = [];
if (hasColumn($licenseColumns, 'server_name')) {
    $updateTypes .= 's';
    $updateParams[] = $serverName;
}
if (hasColumn($licenseColumns, 'last_ip')) {
    $updateTypes .= 's';
    $updateParams[] = $serverIP;
}
$updateTypes .= 'i';
$updateParams[] = $rowId;

$updateStmt->bind_param($updateTypes, ...$updateParams);
$updateStmt->execute();
$updateStmt->close();

if (empty($row['first_check'])) {
    if (hasColumn($licenseColumns, 'first_check')) {
        $firstCheckStmt = $conn->prepare('UPDATE scriptforge_licenses SET first_check = NOW() WHERE id = ?');
        $firstCheckStmt->bind_param('i', $rowId);
        $firstCheckStmt->execute();
        $firstCheckStmt->close();
    }
}

if (empty($row['server_ip'])) {
    if (hasColumn($licenseColumns, 'server_ip')) {
        $ipStmt = $conn->prepare('UPDATE scriptforge_licenses SET server_ip = ? WHERE id = ?');
        $ipStmt->bind_param('si', $serverIP, $rowId);
        $ipStmt->execute();
        $ipStmt->close();
        $row['server_ip'] = $serverIP;
    }
}

$currentStatus = (string)($row['status'] ?? 'inactive');
$webhookUrl = (string)($row['webhook_url'] ?? ($product['webhook_url'] ?? ''));
$logSuccess = (bool)($row['log_success'] ?? $product['log_success'] ?? false);
$logFailed = (bool)($row['log_failed'] ?? $product['log_failed'] ?? true);
$ipLock = (int)($row['ip_lock'] ?? 0) === 1;
$expectedIP = (string)($row['server_ip'] ?? '');

if ($ipLock && $expectedIP !== '' && $expectedIP !== $serverIP) {
    if ($logFailed && $webhookUrl !== '') {
        sendWebhook(
            $webhookUrl,
            'ScriptForge IP lock triggered',
            "**Server:** {$serverName}\n**Current IP:** {$serverIP}\n**Expected IP:** {$expectedIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** IP_MISMATCH",
            15158332
        );
    }

    respond(productResponse(
        $product,
        $serverIP,
        false,
        'ip_mismatch',
        [
            'error' => 'ip_mismatch',
            'expected_ip' => $expectedIP,
            'current_ip' => $serverIP,
            'pending_allowed' => false,
            'webhook_url' => $webhookUrl,
            'log_success' => $logSuccess,
            'log_failed' => $logFailed,
            'ip_lock' => true,
        ]
    ));
}

if ($heartbeat === 'active' || $heartbeat === 'heartbeat') {
    $hbSets = [];
    $hbTypes = 'i';
    $hbParams = [$rowId];

    if (hasColumn($licenseColumns, 'resource_status')) {
        $hbSets[] = 'resource_status = "active"';
    }
    if (hasColumn($licenseColumns, 'started_at')) {
        $hbSets[] = 'started_at = IF(started_at IS NULL OR resource_status = "inactive", NOW(), started_at)';
    }
    if (hasColumn($licenseColumns, 'last_seen')) {
        $hbSets[] = 'last_seen = NOW()';
    }

    if ($hbSets) {
        $hbStmt = $conn->prepare(
            'UPDATE scriptforge_licenses
             SET ' . implode(', ', $hbSets) . '
             WHERE id = ?'
        );
        $hbStmt->bind_param($hbTypes, ...$hbParams);
        $hbStmt->execute();
        $hbStmt->close();
    }
} elseif ($heartbeat === 'inactive') {
    if (hasColumn($licenseColumns, 'resource_status')) {
        $hbStmt = $conn->prepare(
            'UPDATE scriptforge_licenses
             SET resource_status = "inactive"
             WHERE id = ?'
        );
        $hbStmt->bind_param('i', $rowId);
        $hbStmt->execute();
        $hbStmt->close();
    }
}

if ($currentStatus === 'pending') {
    $pendingUntil = (string)($row['pending_until'] ?? '');
    $pendingAllowed = $pendingUntil !== '' && strtotime($pendingUntil) > time();

    if (!$pendingAllowed) {
        if ($logFailed && $webhookUrl !== '') {
            sendWebhook(
                $webhookUrl,
                'ScriptForge pending expired',
                "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** PENDING_EXPIRED",
                15158332
            );
        }

        respond(productResponse(
            $product,
            $serverIP,
            false,
            'license_pending',
            [
                'notice' => 'license_pending',
                'pending_allowed' => false,
                'pending_until' => $pendingUntil !== '' ? $pendingUntil : null,
                'error' => 'license_pending_expired',
                'webhook_url' => $webhookUrl,
                'log_success' => $logSuccess,
                'log_failed' => $logFailed,
                'ip_lock' => $ipLock,
            ]
        ));
    }

    if ($logSuccess && $webhookUrl !== '') {
        sendWebhook(
            $webhookUrl,
            'ScriptForge pending valid',
            "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** PENDING_ACTIVE",
            3066993
        );
    }

    respond([
        'status' => 'online',
        'license_valid' => true,
        'notice' => 'license_pending',
        'pending_allowed' => true,
        'pending_until' => $pendingUntil,
        'version' => $product['latest_version'] ?? $version,
        'changelog' => $product['changelog'] ?? null,
        'log_success' => $logSuccess,
        'log_failed' => $logFailed,
        'webhook_url' => $webhookUrl,
        'server_ip' => $serverIP,
        'ip_lock' => $ipLock,
    ]);
}

if ($currentStatus === 'trial') {
    $expiresAt = (string)($row['expires_at'] ?? '');

    if ($expiresAt !== '' && strtotime($expiresAt) > time()) {
        if ($logSuccess && $webhookUrl !== '') {
            sendWebhook(
                $webhookUrl,
                'ScriptForge trial valid',
                "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** TRIAL_ACTIVE",
                3066993
            );
        }

        respond([
            'status' => 'online',
            'license_valid' => true,
            'version' => $product['latest_version'] ?? $version,
            'changelog' => $product['changelog'] ?? null,
            'log_success' => $logSuccess,
            'log_failed' => $logFailed,
            'webhook_url' => $webhookUrl,
            'server_ip' => $serverIP,
            'ip_lock' => $ipLock,
        ]);
    }

    $expireSets = [];
    if (hasColumn($licenseColumns, 'status')) {
        $expireSets[] = 'status = "expired"';
    }
    if (hasColumn($licenseColumns, 'resource_status')) {
        $expireSets[] = 'resource_status = "inactive"';
    }

    if ($expireSets) {
        $expireTrialStmt = $conn->prepare(
            'UPDATE scriptforge_licenses
             SET ' . implode(', ', $expireSets) . '
             WHERE id = ?'
        );
        $expireTrialStmt->bind_param('i', $rowId);
        $expireTrialStmt->execute();
        $expireTrialStmt->close();
    }

    if ($logFailed && $webhookUrl !== '') {
        sendWebhook(
            $webhookUrl,
            'ScriptForge trial expired',
            "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** EXPIRED",
            15158332
        );
    }

    respond(productResponse(
        $product,
        $serverIP,
        false,
        'expired',
        [
            'error' => 'license_expired',
            'webhook_url' => $webhookUrl,
            'log_success' => $logSuccess,
            'log_failed' => $logFailed,
            'ip_lock' => $ipLock,
        ]
    ));
}

if ($currentStatus !== 'active') {
    if ($logFailed && $webhookUrl !== '') {
        sendWebhook(
            $webhookUrl,
            'ScriptForge license invalid',
            "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** {$currentStatus}",
            15158332
        );
    }

    respond(productResponse(
        $product,
        $serverIP,
        false,
        'license_invalid',
        [
            'error' => 'license_invalid',
            'webhook_url' => $webhookUrl,
            'log_success' => $logSuccess,
            'log_failed' => $logFailed,
            'ip_lock' => $ipLock,
        ]
    ));
}

if ($logSuccess && $webhookUrl !== '') {
    sendWebhook(
        $webhookUrl,
        'ScriptForge license successful',
        "**Server:** {$serverName}\n**IP:** {$serverIP}\n**Product:** {$script}\n**Version:** {$version}\n**Status:** ACTIVE",
        3066993
    );
}

respond([
    'status' => 'online',
    'license_valid' => true,
    'version' => $product['latest_version'] ?? $version,
    'changelog' => $product['changelog'] ?? null,
    'log_success' => $logSuccess,
    'log_failed' => $logFailed,
    'webhook_url' => $webhookUrl,
    'server_ip' => $serverIP,
    'ip_lock' => $ipLock,
]);
