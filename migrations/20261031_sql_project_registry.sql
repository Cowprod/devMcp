-- SQL source of truth for local MCP project definitions and reusable action templates.
-- Remote projects remain in dm_project/dm_deployment on the same MariaDB server.
-- Explicit readiness sentinel makes the import/cutover atomic. No foreign keys.
CREATE TABLE IF NOT EXISTS dm_local_project_config (
    project_id VARCHAR(64) NOT NULL PRIMARY KEY,
    config_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_dm_local_project_config_json CHECK (JSON_VALID(config_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_shared_action_config (
    action_id VARCHAR(128) NOT NULL PRIMARY KEY,
    config_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_dm_shared_action_config_json CHECK (JSON_VALID(config_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_project_registry_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    ready TINYINT(1) NOT NULL DEFAULT 0,
    migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
