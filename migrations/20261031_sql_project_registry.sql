-- SQL source of truth for local project definitions and reusable action templates.
-- No foreign keys; scoped to devmcp_distributed.
CREATE TABLE IF NOT EXISTS dm_local_project_config (
    project_id VARCHAR(64) NOT NULL PRIMARY KEY,
    config_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (JSON_VALID(config_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_shared_action_config (
    action_id VARCHAR(128) NOT NULL PRIMARY KEY,
    config_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (JSON_VALID(config_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
