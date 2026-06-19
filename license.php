<?php

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

function respondJson(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($json === false) {
        $json = json_encode([
            'error' => 'json_encode_failed',
            'message' => 'Response serialization failed.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    echo $json;
    exit;
}

set_exception_handler(function (Throwable $error) {
    error_log('ScriptForge license exception: ' . $error->getMessage());

    respondJson([
        'error' => 'server_exception',
        'message' => $error->getMessage()
    ], 200);
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
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'error' => 'fatal_error',
        'message' => $error['message'],
        'file' => basename($error['file'] ?? ''),
        'line' => $error['line'] ?? null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});

// ===============================
// CONFIG
// ===============================

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
$devModeValue = strtolower(trim((string)($_GET['devmode'] ?? $_GET['dev_mode'] ?? '')));
$devMode = in_array($devModeValue, ['1', 'true', 'yes', 'on'], true);
$defaultWebhookUrl = 'https://discord.com/api/webhooks/1510409400722915520/eb2btoaOHn5I0qkuHo6YHh70kQEv_K2mCSKDkwdsVQPqY1vamzb_lWfoi2ycOUkXAMqU';

function respond(array $payload): void
{
    http_response_code(200);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($json === false) {
        $json = json_encode([
            'error' => 'json_encode_failed',
            'message' => 'Response serialization failed.'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    echo $json;
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

    try {
        if (function_exists('curl_init')) {
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
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 5,
            ]
        ]);

        @file_get_contents($url, false, $context);
    } catch (Throwable $error) {
        error_log('ScriptForge webhook failed: ' . $error->getMessage());
    }
}

function normalizeWebhookUrl($value, string $fallback = ''): string
{
    $candidate = trim((string)$value);
    $fallback = trim($fallback);

    if ($candidate === '' || $candidate === '0' || $candidate === '1' || strtolower($candidate) === 'null') {
        return $fallback;
    }

    if (preg_match('#^https://(?:canary\\.|ptb\\.)?discord(?:app)?\\.com/api/webhooks/\\d+/[A-Za-z0-9._-]+$#', $candidate)) {
        return $candidate;
    }

    if (preg_match('#^https://discord\\.com/api/webhooks/\\d+/[A-Za-z0-9._-]+$#', $candidate)) {
        return $candidate;
    }

    return $fallback;
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

function getTableColumns(mysqli $conn, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $conn->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );

    if (!$stmt) {
        return $cache[$table] = [];
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
    }

    $stmt->close();

    return $cache[$table] = $columns;
}

function getDevRequestSchema(mysqli $conn): array
{
    $columns = getTableColumns($conn, 'scriptforge_dev_requests');

    $schema = [
        'token' => isset($columns['token']) ? 'token' : (isset($columns['request_token']) ? 'request_token' : null),
        'server_name' => isset($columns['server_name']) ? 'server_name' : null,
        'product_id' => isset($columns['product_id']) ? 'product_id' : null,
        'license_key' => isset($columns['license_key']) ? 'license_key' : null,
        'heartbeat_status' => isset($columns['heartbeat_status']) ? 'heartbeat_status' : null,
        'requested_at' => isset($columns['requested_at']) ? 'requested_at' : (isset($columns['created_at']) ? 'created_at' : null),
        'last_check' => isset($columns['last_check_at']) ? 'last_check_at' : (isset($columns['last_check']) ? 'last_check' : null),
        'started_at' => isset($columns['started_at']) ? 'started_at' : null,
        'last_seen' => isset($columns['last_seen']) ? 'last_seen' : null,
        'decided_by' => isset($columns['decided_by']) ? 'decided_by' : null,
        'decided_at' => isset($columns['decided_at']) ? 'decided_at' : null,
    ];

    return $schema;
}

function buildDevRequestSelectSql(mysqli $conn): string
{
    $schema = getDevRequestSchema($conn);
    $fields = [
        'id',
        $schema['token'] !== null ? $schema['token'] . ' AS token' : 'NULL AS token',
        'server_ip',
        $schema['server_name'] !== null ? $schema['server_name'] . ' AS server_name' : 'NULL AS server_name',
        'resource_name',
        'script_name',
        $schema['product_id'] !== null ? $schema['product_id'] . ' AS product_id' : 'NULL AS product_id',
        $schema['license_key'] !== null ? $schema['license_key'] . ' AS license_key' : 'NULL AS license_key',
        'status',
        $schema['heartbeat_status'] !== null ? $schema['heartbeat_status'] . ' AS heartbeat_status' : 'NULL AS heartbeat_status',
        $schema['requested_at'] !== null ? $schema['requested_at'] . ' AS requested_at' : 'NULL AS requested_at',
        $schema['last_check'] !== null ? $schema['last_check'] . ' AS last_check_at' : 'NULL AS last_check_at',
        $schema['started_at'] !== null ? $schema['started_at'] . ' AS started_at' : 'NULL AS started_at',
        $schema['last_seen'] !== null ? $schema['last_seen'] . ' AS last_seen' : 'NULL AS last_seen',
        $schema['decided_by'] !== null ? $schema['decided_by'] . ' AS decided_by' : 'NULL AS decided_by',
        $schema['decided_at'] !== null ? $schema['decided_at'] . ' AS decided_at' : 'NULL AS decided_at',
    ];

    return 'SELECT ' . implode(', ', $fields) . ' FROM scriptforge_dev_requests';
}

function getActiveDevServer(mysqli $conn, string $serverIP): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, server_ip, label, active
         FROM scriptforge_dev_servers
         WHERE server_ip = ?
           AND active = 1
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('ScriptForge DEV server lookup failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('s', $serverIP);
    $stmt->execute();
    $result = $stmt->get_result();
    $server = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $server ?: null;
}

function syncDevServerState(mysqli $conn, string $serverIP, ?string $label, bool $active, ?string $createdBy = null): void
{
    $columns = getTableColumns($conn, 'scriptforge_dev_servers');
    $hasCreatedBy = isset($columns['created_by']);

    if ($hasCreatedBy) {
        $stmt = $conn->prepare(
            'INSERT INTO scriptforge_dev_servers
             (server_ip, label, active, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 label = VALUES(label),
                 active = VALUES(active),
                 created_by = COALESCE(VALUES(created_by), created_by)'
        );
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO scriptforge_dev_servers
             (server_ip, label, active)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 label = VALUES(label),
                 active = VALUES(active)'
        );
    }

    if (!$stmt) {
        error_log('ScriptForge DEV server sync failed: ' . $conn->error);
        return;
    }

    $activeInt = $active ? 1 : 0;
    $cleanLabel = $label !== null && trim($label) !== '' ? trim($label) : null;
    $cleanCreatedBy = $createdBy !== null && trim($createdBy) !== '' ? trim($createdBy) : null;

    if ($hasCreatedBy) {
        $stmt->bind_param('ssis', $serverIP, $cleanLabel, $activeInt, $cleanCreatedBy);
    } else {
        $stmt->bind_param('ssi', $serverIP, $cleanLabel, $activeInt);
    }

    if (!$stmt->execute()) {
        error_log('ScriptForge DEV server sync execute failed: ' . $stmt->error);
    }

    $stmt->close();
}

function createDevRequestToken(): string
{
    return bin2hex(random_bytes(16));
}

function buildDevRequestHint(bool $isPlaceholder, bool $allowDevFlow): string
{
    if ($isPlaceholder) {
        return $allowDevFlow
            ? 'Placeholder key with active DEV server entry. Waiting for Discord approval.'
            : 'Placeholder key waiting for Discord approval.';
    }

    return $allowDevFlow
        ? 'No matching license entry found. Waiting for Discord approval.'
        : 'DEV request waiting for Discord approval.';
}

function bindStatementParams(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '' || $values === []) {
        return true;
    }

    $params = [$types];
    foreach ($values as $index => &$value) {
        $params[] = &$value;
    }
    unset($value);

    return call_user_func_array([$stmt, 'bind_param'], $params);
}

function getReusableDevRequest(mysqli $conn, string $serverIP, string $resource): ?array
{
    $selectSql = buildDevRequestSelectSql($conn) .
        ' WHERE server_ip = ?
           AND resource_name = ?
           AND status IN ("pending", "dev_pending", "approved", "denied", "revoked")
         ORDER BY id DESC
         LIMIT 1';

    $stmt = $conn->prepare(
        $selectSql
    );

    if (!$stmt) {
        error_log('ScriptForge DEV reusable request lookup failed: ' . $conn->error);
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
    int $productId,
    string $license
): ?array {
    $schema = getDevRequestSchema($conn);
    $token = createDevRequestToken();
    $columns = [];
    $placeholders = [];
    $values = [];
    $types = '';

    $addBound = function (string $column, $value, string $type) use (&$columns, &$placeholders, &$values, &$types): void {
        $columns[] = $column;
        $placeholders[] = '?';
        $values[] = $value;
        $types .= $type;
    };

    $addExpression = function (string $column, string $expression) use (&$columns, &$placeholders): void {
        $columns[] = $column;
        $placeholders[] = $expression;
    };

    if ($schema['token'] !== null) {
        $addBound($schema['token'], $token, 's');
    }

    $addBound('server_ip', $serverIP, 's');

    if ($schema['server_name'] !== null) {
        $addBound($schema['server_name'], $serverName, 's');
    }

    $addBound('resource_name', $resource, 's');
    $addBound('script_name', $script, 's');

    if ($schema['product_id'] !== null) {
        $addBound($schema['product_id'], $productId, 'i');
    }

    if ($schema['license_key'] !== null) {
        $addBound($schema['license_key'], $license, 's');
    }

    $addBound('status', 'pending', 's');

    if ($schema['heartbeat_status'] !== null) {
        $addBound($schema['heartbeat_status'], 'inactive', 's');
    }

    if ($schema['requested_at'] !== null) {
        $addExpression($schema['requested_at'], 'NOW()');
    }

    if ($schema['last_check'] !== null) {
        $addExpression($schema['last_check'], 'NOW()');
    }

    if ($schema['started_at'] !== null) {
        $addExpression($schema['started_at'], 'NULL');
    }

    if ($schema['last_seen'] !== null) {
        $addExpression($schema['last_seen'], 'NULL');
    }

    if ($schema['decided_by'] !== null) {
        $addExpression($schema['decided_by'], 'NULL');
    }

    if ($schema['decided_at'] !== null) {
        $addExpression($schema['decided_at'], 'NULL');
    }

    $stmt = $conn->prepare(
        'INSERT INTO scriptforge_dev_requests
        (' . implode(', ', $columns) . ')
        VALUES
        (' . implode(', ', $placeholders) . ')'
    );

    if (!$stmt) {
        error_log('ScriptForge DEV request insert prepare failed: ' . $conn->error);
        return null;
    }

    if (!bindStatementParams($stmt, $types, $values)) {
        error_log('ScriptForge DEV request insert bind failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }

    if (!$stmt->execute()) {
        error_log('ScriptForge DEV request insert failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }

    $requestId = (int)$stmt->insert_id;
    $stmt->close();

    if ($requestId > 0) {
        $fetchSql = buildDevRequestSelectSql($conn) . ' WHERE id = ? LIMIT 1';
        $fetchStmt = $conn->prepare($fetchSql);

        if ($fetchStmt) {
            $fetchStmt->bind_param('i', $requestId);
            $fetchStmt->execute();
            $result = $fetchStmt->get_result();
            $request = $result ? $result->fetch_assoc() : null;
            $fetchStmt->close();

            if ($request) {
                $request['__created'] = true;
                return $request;
            }
        }
    }

    $request = getReusableDevRequest($conn, $serverIP, $resource);

    if ($request) {
        $request['__created'] = false;
    }

    return $request;
}

function getActiveDevApproval(mysqli $conn, string $token, string $serverIP, string $resource): ?array
{
    $columns = getTableColumns($conn, 'scriptforge_dev_approvals');
    $tokenColumn = isset($columns['token']) ? 'token' : (isset($columns['request_token']) ? 'request_token' : null);
    $statusColumn = isset($columns['status']) ? 'status' : null;
    $actionColumn = isset($columns['action']) ? 'action' : null;
    $activeColumn = isset($columns['active']) ? 'active' : null;
    $expiresColumn = isset($columns['expires_at']) ? 'expires_at' : null;

    if ($tokenColumn === null) {
        return null;
    }

    $where = [
        $tokenColumn . ' = ?',
        'server_ip = ?',
        'resource_name = ?'
    ];

    if ($actionColumn !== null) {
        $where[] = $actionColumn . ' = "approved"';
    }

    if ($statusColumn !== null) {
        $where[] = $statusColumn . ' = "active"';
    } elseif ($activeColumn !== null) {
        $where[] = $activeColumn . ' = 1';
    }

    if ($expiresColumn !== null) {
        $where[] = $expiresColumn . ' > NOW()';
    }

    $stmt = $conn->prepare(
        'SELECT *
         FROM scriptforge_dev_approvals
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY id DESC
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('ScriptForge DEV approval lookup failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('sss', $token, $serverIP, $resource);
    $stmt->execute();
    $result = $stmt->get_result();
    $approval = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $approval ?: null;
}

function expireOldDevApprovals(mysqli $conn): void
{
    try {
        $columns = getTableColumns($conn, 'scriptforge_dev_approvals');
        $tokenColumn = isset($columns['token']) ? 'token' : (isset($columns['request_token']) ? 'request_token' : null);
        $statusColumn = isset($columns['status']) ? 'status' : null;
        $activeColumn = isset($columns['active']) ? 'active' : null;
        $expiresColumn = isset($columns['expires_at']) ? 'expires_at' : null;

        if ($statusColumn !== null && $expiresColumn !== null) {
        $stmt = $conn->prepare(
            'UPDATE scriptforge_dev_approvals
             SET ' . $statusColumn . ' = "expired"
             WHERE ' . $statusColumn . ' = "active"
               AND ' . $expiresColumn . ' IS NOT NULL
               AND ' . $expiresColumn . ' <= NOW()'
        );

        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
        }

        if ($tokenColumn !== null && $expiresColumn !== null) {
            $joinConditions = [
                'a.' . $tokenColumn . ' = r.token',
                $expiresColumn !== null
                    ? 'a.' . $expiresColumn . ' > NOW()'
                    : '1=1'
            ];

            if ($statusColumn !== null) {
                $joinConditions[] = 'a.' . $statusColumn . ' = "active"';
            } elseif ($activeColumn !== null) {
                $joinConditions[] = 'a.' . $activeColumn . ' = 1';
            }

            $stmt = $conn->prepare(
                'UPDATE scriptforge_dev_requests r
                 LEFT JOIN scriptforge_dev_approvals a
                     ON ' . implode(' AND ', $joinConditions) . '
                 SET r.status = "expired",
                     r.heartbeat_status = "inactive"
                 WHERE r.status = "approved"
                   AND a.id IS NULL'
            );

            if ($stmt) {
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable $error) {
        error_log('ScriptForge DEV cleanup skipped: ' . $error->getMessage());
    }
}

function updateDevHeartbeat(mysqli $conn, int $requestId, string $heartbeat): void
{
    $schema = getDevRequestSchema($conn);

    if ($heartbeat === 'active') {
        $updates = [];
        if ($schema['heartbeat_status'] !== null) {
            $updates[] = $schema['heartbeat_status'] . ' = "active"';
        }
        if ($schema['started_at'] !== null) {
            $updates[] = $schema['started_at'] . ' = IF(' . $schema['started_at'] . ' IS NULL, NOW(), ' . $schema['started_at'] . ')';
        }
        if ($schema['last_seen'] !== null) {
            $updates[] = $schema['last_seen'] . ' = NOW()';
        }
        if ($schema['last_check'] !== null) {
            $updates[] = $schema['last_check'] . ' = NOW()';
        }
        if ($updates === []) {
            return;
        }
        $stmt = $conn->prepare('UPDATE scriptforge_dev_requests SET ' . implode(', ', $updates) . ' WHERE id = ?');
    } elseif ($heartbeat === 'heartbeat') {
        $updates = [];
        if ($schema['heartbeat_status'] !== null) {
            $updates[] = $schema['heartbeat_status'] . ' = "active"';
        }
        if ($schema['last_seen'] !== null) {
            $updates[] = $schema['last_seen'] . ' = NOW()';
        }
        if ($schema['last_check'] !== null) {
            $updates[] = $schema['last_check'] . ' = NOW()';
        }
        if ($updates === []) {
            return;
        }
        $stmt = $conn->prepare('UPDATE scriptforge_dev_requests SET ' . implode(', ', $updates) . ' WHERE id = ?');
    } elseif ($heartbeat === 'inactive') {
        $updates = [];
        if ($schema['heartbeat_status'] !== null) {
            $updates[] = $schema['heartbeat_status'] . ' = "inactive"';
        }
        if ($schema['last_check'] !== null) {
            $updates[] = $schema['last_check'] . ' = NOW()';
        }
        if ($updates === []) {
            return;
        }
        $stmt = $conn->prepare('UPDATE scriptforge_dev_requests SET ' . implode(', ', $updates) . ' WHERE id = ?');
    } else {
        $column = $schema['last_check'] ?? null;
        if ($column === null) {
            return;
        }
        $stmt = $conn->prepare('UPDATE scriptforge_dev_requests SET ' . $column . ' = NOW() WHERE id = ?');
    }

    if (!$stmt) {
        error_log('ScriptForge DEV heartbeat update failed: ' . $conn->error);
        return;
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->close();
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

$productStmt = $conn->prepare(
    'SELECT *
     FROM scriptforge_products
     WHERE script_name = ?
     LIMIT 1'
);

if (!$productStmt) {
    respond([
        'status' => 'offline',
        'license_valid' => false,
        'error' => 'product_query_failed',
        'message' => $conn->error,
    ]);
}

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

$product['webhook_url'] = normalizeWebhookUrl($product['webhook_url'] ?? '', $defaultWebhookUrl);
$product['log_success'] = $product['log_success'] ?? 0;
$product['log_failed'] = $product['log_failed'] ?? 1;

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
    $devServer = getActiveDevServer($conn, $serverIP);
    $isPlaceholder = isPlaceholderLicense($license);
    $allowDevFlow = $devMode || $devServer !== null;
    $devRequest = getReusableDevRequest($conn, $serverIP, $resource);
    $devRequestWasCreated = false;

    if (($isPlaceholder || $allowDevFlow) && !$devRequest) {
        $devRequest = createDevRequest(
            $conn,
            $serverIP,
            $serverName !== '' ? $serverName : null,
            $resource,
            $script,
            (int)$product['id'],
            $license
        );
        $devRequestWasCreated = !empty($devRequest['__created']);
    }

    if ($isPlaceholder) {
        if ($devRequestWasCreated && !empty($product['webhook_url'])) {
            $devHint = buildDevRequestHint(true, $allowDevFlow);
            sendWebhook(
                (string)$product['webhook_url'],
                'New ScriptForge DEV request',
                "**Token:** " . ($devRequest['token'] ?? 'unknown') .
                "\n**Product:** " . $script .
                "\n**Resource:** " . $resource .
                "\n**IP:** " . $serverIP .
                "\n**Server:** " . ($serverName !== '' ? $serverName : 'Unknown') .
                "\n\n**Status:** PENDING" .
                "\n**Hint:** {$devHint}",
                16753920
            );
        }

        if ($devRequest) {
            $token = (string)($devRequest['token'] ?? '');
            $devStatus = strtolower((string)($devRequest['status'] ?? 'pending'));

            if ($devStatus === 'denied' || $devStatus === 'revoked') {
                syncDevServerState($conn, $serverIP, $serverName !== '' ? $serverName : null, false, $devStatus);
            } else {
                $activeApproval = getActiveDevApproval($conn, $token, $serverIP, $resource);

                if ($activeApproval) {
                    syncDevServerState($conn, $serverIP, $serverName !== '' ? $serverName : null, true, 'approved');
                    updateDevHeartbeat($conn, (int)$devRequest['id'], $heartbeat);

                    error_log("ScriptForge placeholder DEV request i.O.: {$serverIP} / {$resource} / {$script}");

                    respond([
                        'script' => $script,
                        'resource' => $resource,
                        'version' => $product['latest_version'] ?? $version,
                        'changelog' => $product['changelog'] ?? null,
                        'status' => $product['status'],
                        'license_valid' => true,
                        'license_status' => 'dev_approved',
                'dev_mode' => true,
                'dev_request_token' => $token,
                'dev_request_hint' => buildDevRequestHint(true, $allowDevFlow),
                'dev_approval_expires_at' => $activeApproval['expires_at'],
                'log_success' => false,
                'log_failed' => true,
                        'webhook_url' => null,
                        'server_ip' => $serverIP,
                        'ip_lock' => false,
                    ]);
                }
            }

            updateDevHeartbeat($conn, (int)$devRequest['id'], '');

            error_log("ScriptForge placeholder DEV request pending: {$serverIP} / {$resource} / {$script}");

            respond([
                'script' => $script,
                'resource' => $resource,
                'version' => $product['latest_version'] ?? $version,
                'changelog' => $product['changelog'] ?? null,
                'status' => $product['status'],
                'license_valid' => false,
                'license_status' => 'dev_pending',
                'dev_mode' => true,
                'dev_request_token' => $token,
                'dev_request_hint' => buildDevRequestHint(true, $allowDevFlow),
                'message' => 'Placeholder key is waiting for Discord approval.',
                'server_ip' => $serverIP,
                'ip_lock' => false,
            ]);
        }

        error_log("ScriptForge placeholder DEV request could not be created: {$serverIP} / {$resource} / {$script}");

        respond([
            'script' => $script,
            'resource' => $resource,
            'version' => $product['latest_version'] ?? $version,
            'changelog' => $product['changelog'] ?? null,
            'status' => $product['status'],
            'license_valid' => false,
            'license_status' => 'dev_pending',
            'dev_mode' => true,
            'dev_request_token' => null,
            'dev_request_hint' => buildDevRequestHint(true, $allowDevFlow),
            'message' => 'Placeholder key is waiting for Discord approval.',
            'server_ip' => $serverIP,
            'ip_lock' => false,
        ]);
    }

    if ($devRequestWasCreated && !empty($product['webhook_url']) && $allowDevFlow) {
        $devHint = buildDevRequestHint(false, $allowDevFlow);
        sendWebhook(
            (string)$product['webhook_url'],
            'New ScriptForge DEV request',
            "**Token:** " . ($devRequest['token'] ?? 'unknown') .
            "\n**Product:** " . $script .
            "\n**Resource:** " . $resource .
            "\n**IP:** " . $serverIP .
            "\n**Server:** " . ($serverName !== '' ? $serverName : 'Unknown') .
            "\n\n**Status:** PENDING" .
            "\n**Hint:** {$devHint}",
            16753920
        );
    }

    if ($allowDevFlow) {
        if ($devRequest) {
            $token = (string)($devRequest['token'] ?? '');
            $devStatus = strtolower((string)($devRequest['status'] ?? 'pending'));

            if ($devStatus === 'denied' || $devStatus === 'revoked') {
                syncDevServerState($conn, $serverIP, $serverName !== '' ? $serverName : null, false, $devStatus);

                respond([
                    'script' => $script,
                    'resource' => $resource,
                    'version' => $product['latest_version'] ?? $version,
                    'changelog' => $product['changelog'] ?? null,
                    'status' => $product['status'],
                    'license_valid' => false,
                    'license_status' => $devStatus === 'denied' ? 'dev_denied' : 'dev_revoked',
                    'dev_mode' => true,
                    'dev_request_token' => $token,
                    'dev_request_hint' => buildDevRequestHint(false, $allowDevFlow),
                    'server_ip' => $serverIP,
                    'ip_lock' => false,
                ]);
            }

            $activeApproval = getActiveDevApproval($conn, $token, $serverIP, $resource);

            if ($activeApproval) {
                syncDevServerState($conn, $serverIP, $serverName !== '' ? $serverName : null, true, 'approved');
                updateDevHeartbeat($conn, (int)$devRequest['id'], $heartbeat);

                error_log("ScriptForge DEV request i.O.: {$serverIP} / {$resource} / {$script}");

                respond([
                    'script' => $script,
                    'resource' => $resource,
                    'version' => $product['latest_version'] ?? $version,
                    'changelog' => $product['changelog'] ?? null,
                    'status' => $product['status'],
                    'license_valid' => true,
                    'license_status' => 'dev_approved',
                    'dev_mode' => true,
                    'dev_request_token' => $token,
                    'dev_request_hint' => buildDevRequestHint(false, $allowDevFlow),
                    'dev_approval_expires_at' => $activeApproval['expires_at'],
                    'log_success' => false,
                    'log_failed' => true,
                    'webhook_url' => null,
                    'server_ip' => $serverIP,
                    'ip_lock' => false,
                ]);
            }

            updateDevHeartbeat($conn, (int)$devRequest['id'], '');

            error_log("ScriptForge DEV request pending: {$serverIP} / {$resource} / {$script}");

            respond([
                'script' => $script,
                'resource' => $resource,
                'version' => $product['latest_version'] ?? $version,
                'changelog' => $product['changelog'] ?? null,
                'status' => $product['status'],
                'license_valid' => false,
                'license_status' => 'dev_pending',
                'dev_mode' => true,
                'dev_request_token' => $token,
                'dev_request_hint' => buildDevRequestHint(false, $allowDevFlow),
                'message' => 'DEV request is waiting for Discord approval.',
                'server_ip' => $serverIP,
                'ip_lock' => false,
            ]);
        }
    }

    $pendingUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $webhookUrl = normalizeWebhookUrl($product['webhook_url'] ?? '', $defaultWebhookUrl);

    $insertStmt = $conn->prepare(
        'INSERT INTO scriptforge_licenses
         (license_key, script_name, resource_name, status, server_name, server_ip, last_ip, first_check, last_check, total_checks, pending_until, webhook_url, log_success, log_failed, ip_lock)
         VALUES
         (?, ?, ?, "pending", ?, ?, ?, NOW(), NOW(), 1, ?, ?, 0, 1, 0)'
    );

    $insertStmt->bind_param(
        'ssssssss',
        $license,
        $script,
        $resource,
        $serverName,
        $serverIP,
        $serverIP,
        $pendingUntil,
        $webhookUrl
    );

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
     SET server_name = ?,
         last_ip = ?,
         last_check = NOW(),
         total_checks = total_checks + 1
     WHERE id = ?'
);

$rowId = (int)$row['id'];
$updateStmt->bind_param('ssi', $serverName, $serverIP, $rowId);
$updateStmt->execute();
$updateStmt->close();

if (empty($row['first_check'])) {
    $firstCheckStmt = $conn->prepare('UPDATE scriptforge_licenses SET first_check = NOW() WHERE id = ?');
    $firstCheckStmt->bind_param('i', $rowId);
    $firstCheckStmt->execute();
    $firstCheckStmt->close();
}

if (empty($row['server_ip'])) {
    $ipStmt = $conn->prepare('UPDATE scriptforge_licenses SET server_ip = ? WHERE id = ?');
    $ipStmt->bind_param('si', $serverIP, $rowId);
    $ipStmt->execute();
    $ipStmt->close();
    $row['server_ip'] = $serverIP;
}

$currentStatus = (string)($row['status'] ?? 'inactive');
$webhookUrl = normalizeWebhookUrl($row['webhook_url'] ?? '', (string)($product['webhook_url'] ?? $defaultWebhookUrl));
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
    $hbStmt = $conn->prepare(
        'UPDATE scriptforge_licenses
         SET resource_status = "active",
             started_at = IF(started_at IS NULL OR resource_status = "inactive", NOW(), started_at),
             last_seen = NOW()
         WHERE id = ?'
    );
    $hbStmt->bind_param('i', $rowId);
    $hbStmt->execute();
    $hbStmt->close();
} elseif ($heartbeat === 'inactive') {
    $hbStmt = $conn->prepare(
        'UPDATE scriptforge_licenses
         SET resource_status = "inactive"
         WHERE id = ?'
    );
    $hbStmt->bind_param('i', $rowId);
    $hbStmt->execute();
    $hbStmt->close();
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

    $expireTrialStmt = $conn->prepare(
        'UPDATE scriptforge_licenses
         SET status = "expired",
             resource_status = "inactive"
         WHERE id = ?'
    );
    $expireTrialStmt->bind_param('i', $rowId);
    $expireTrialStmt->execute();
    $expireTrialStmt->close();

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
