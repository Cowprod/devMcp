-- devMcp distributed registry v1. MySQL 8 / MariaDB 10.6+, InnoDB, UTC.
-- Applied by a dedicated migration runner; never automatically during MCP startup.
CREATE TABLE IF NOT EXISTS dm_machine (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  os_family VARCHAR(32) NOT NULL,
  architecture VARCHAR(32) NOT NULL,
  agent_version VARCHAR(64) NULL,
  status ENUM('pending','active','disabled','revoked') NOT NULL DEFAULT 'pending',
  last_seen_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_machine_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_project (
  id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  repository_url VARCHAR(1024) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_deployment (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  project_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  workspace_path VARCHAR(2048) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_deployment_machine_project (machine_id,project_id),
  CONSTRAINT fk_deployment_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id),
  CONSTRAINT fk_deployment_project FOREIGN KEY (project_id) REFERENCES dm_project(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_action_template (
  id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  risk ENUM('read','write','hardware') NOT NULL DEFAULT 'read',
  definition_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY(id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_deployment_action (
  deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_revision INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY(deployment_id,action_id),
  CONSTRAINT fk_deployment_action_deployment FOREIGN KEY(deployment_id) REFERENCES dm_deployment(id),
  CONSTRAINT fk_deployment_action_template FOREIGN KEY(action_id,action_revision) REFERENCES dm_action_template(id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_agent_identity (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_identity_credential (credential_hash),
  CONSTRAINT fk_identity_machine FOREIGN KEY(machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_job (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_revision INT UNSIGNED NOT NULL,
  arguments_json JSON NOT NULL,
  status ENUM('queued','leased','running','succeeded','failed','cancelled','timed_out','expired','uncertain') NOT NULL DEFAULT 'queued',
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_job_idempotency (deployment_id,idempotency_key),
  KEY idx_job_queue (status,expires_at,created_at),
  CONSTRAINT fk_job_deployment FOREIGN KEY(deployment_id) REFERENCES dm_deployment(id),
  CONSTRAINT fk_job_action FOREIGN KEY(action_id,action_revision) REFERENCES dm_action_template(id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_job_attempt (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  fencing_token BIGINT UNSIGNED NOT NULL,
  lease_expires_at DATETIME(6) NOT NULL,
  started_at DATETIME(6) NULL,
  finished_at DATETIME(6) NULL,
  exit_code INT NULL,
  UNIQUE KEY uq_attempt_fencing (job_id,fencing_token),
  KEY idx_attempt_lease (lease_expires_at),
  CONSTRAINT fk_attempt_job FOREIGN KEY(job_id) REFERENCES dm_job(id),
  CONSTRAINT fk_attempt_machine FOREIGN KEY(machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_job_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_job_event_job (job_id,id),
  CONSTRAINT fk_event_job FOREIGN KEY(job_id) REFERENCES dm_job(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_audit_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(128) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  object_type VARCHAR(64) NOT NULL,
  object_id VARCHAR(128) NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  details_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_audit_created (created_at),
  KEY idx_audit_object (object_type,object_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
