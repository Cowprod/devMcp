CREATE TABLE IF NOT EXISTS dm_remote_project (
 project_id VARCHAR(64) NOT NULL,
 deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 repository VARCHAR(141) NOT NULL,
 workspace_path VARCHAR(1024) NOT NULL,
 config_json MEDIUMTEXT NULL,
 config_revision INT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY uq_remote_project_machine(project_id,machine_id),
 ready TINYINT(1) NOT NULL DEFAULT 0,
 metadata_json MEDIUMTEXT NULL,
 CONSTRAINT fk_remote_project_deployment FOREIGN KEY(deployment_id) REFERENCES dm_deployment(id),
 CONSTRAINT fk_remote_project_machine FOREIGN KEY(machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_remote_project_job (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 project_id VARCHAR(64) NOT NULL,
 machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 operation VARCHAR(24) NOT NULL,
 request_json MEDIUMTEXT NOT NULL,
 state ENUM('preparing','queued','claimed','succeeded','failed','expired') NOT NULL DEFAULT 'queued',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 claimed_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 expires_at DATETIME(6) NOT NULL,
 result_json MEDIUMTEXT NULL,
 CONSTRAINT fk_remote_project_job_machine FOREIGN KEY(machine_id) REFERENCES dm_machine(id),
 INDEX idx_project_job_deployment(deployment_id,created_at),
 INDEX idx_remote_project_jobs(machine_id,state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
