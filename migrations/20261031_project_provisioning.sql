-- Independent provisioning journal; no foreign keys and no implicit grants.
-- Apply through the controlled database migration process before enabling writes.
CREATE TABLE IF NOT EXISTS dm_project_provisioning (
    project_id VARCHAR(64) NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    repository VARCHAR(141) NOT NULL,
    repository_mode VARCHAR(8) NOT NULL,
    state VARCHAR(32) NOT NULL,
    repository_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    machine VARCHAR(128) NULL,
    workspace_path VARCHAR(1024) NULL,
    actions_enabled TINYINT(1) NOT NULL DEFAULT 0,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dm_project_provisioning_repository (repository)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
