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
    server_name VARCHAR(255) NULL,
    resource_name VARCHAR(120) NOT NULL,
    script_name VARCHAR(120) NULL,
    product_id INT UNSIGNED NULL,
    license_key VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'denied', 'revoked', 'expired') NOT NULL DEFAULT 'pending',
    heartbeat_status ENUM('inactive', 'active') NOT NULL DEFAULT 'inactive',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_check_at DATETIME NULL,
    started_at DATETIME NULL,
    last_seen DATETIME NULL,
    decided_by VARCHAR(120) NULL,
    decided_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scriptforge_dev_requests_token (token),
    KEY idx_scriptforge_dev_requests_status (status),
    KEY idx_scriptforge_dev_requests_lookup (server_ip, resource_name, status),
    KEY idx_scriptforge_dev_requests_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scriptforge_dev_approvals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    action ENUM('approved', 'denied', 'revoked') NOT NULL,
    status ENUM('active', 'denied', 'revoked', 'expired') NOT NULL,
    approved_by VARCHAR(120) NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    server_ip VARCHAR(45) NOT NULL,
    resource_name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_scriptforge_dev_approvals_token_status (token, status),
    KEY idx_scriptforge_dev_approvals_lookup (server_ip, resource_name, status, expires_at),
    CONSTRAINT fk_scriptforge_dev_approvals_request
        FOREIGN KEY (request_id)
        REFERENCES scriptforge_dev_requests (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
