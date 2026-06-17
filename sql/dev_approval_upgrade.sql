CREATE TABLE IF NOT EXISTS scriptforge_dev_servers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_ip VARCHAR(45) NOT NULL,
    label VARCHAR(120) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scriptforge_dev_servers_server_ip (server_ip),
    KEY idx_scriptforge_dev_servers_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scriptforge_dev_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    server_ip VARCHAR(45) NOT NULL,
    resource_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scriptforge_dev_requests_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE scriptforge_dev_requests
    ADD COLUMN IF NOT EXISTS server_name VARCHAR(255) NULL AFTER server_ip,
    ADD COLUMN IF NOT EXISTS script_name VARCHAR(120) NULL AFTER resource_name,
    ADD COLUMN IF NOT EXISTS product_id INT UNSIGNED NULL AFTER script_name,
    ADD COLUMN IF NOT EXISTS license_key VARCHAR(255) NULL AFTER product_id,
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'approved', 'denied', 'revoked', 'expired') NOT NULL DEFAULT 'pending' AFTER license_key,
    ADD COLUMN IF NOT EXISTS heartbeat_status ENUM('inactive', 'active') NOT NULL DEFAULT 'inactive' AFTER status,
    ADD COLUMN IF NOT EXISTS requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER heartbeat_status,
    ADD COLUMN IF NOT EXISTS last_check_at DATETIME NULL AFTER requested_at,
    ADD COLUMN IF NOT EXISTS started_at DATETIME NULL AFTER last_check_at,
    ADD COLUMN IF NOT EXISTS last_seen DATETIME NULL AFTER started_at,
    ADD COLUMN IF NOT EXISTS decided_by VARCHAR(120) NULL AFTER last_seen,
    ADD COLUMN IF NOT EXISTS decided_at DATETIME NULL AFTER decided_by;

CREATE TABLE IF NOT EXISTS scriptforge_dev_approvals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    approved_by VARCHAR(120) NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    server_ip VARCHAR(45) NOT NULL,
    resource_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE scriptforge_dev_approvals
    ADD COLUMN IF NOT EXISTS action ENUM('approved', 'denied', 'revoked') NOT NULL DEFAULT 'approved' AFTER token,
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'denied', 'revoked', 'expired') NOT NULL DEFAULT 'active' AFTER action,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(120) NOT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER approved_by,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER approved_at,
    ADD COLUMN IF NOT EXISTS server_ip VARCHAR(45) NOT NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS resource_name VARCHAR(120) NOT NULL AFTER server_ip;
