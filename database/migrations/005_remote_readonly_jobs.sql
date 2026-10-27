-- Read-only remote job queue. Execution requires explicit future approval.
CREATE TABLE IF NOT EXISTS dm_remote_readonly_job (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_name VARCHAR(40) NOT NULL,
 arguments_json TEXT NOT NULL,
 state ENUM('queued','claimed','succeeded','failed','expired') NOT NULL DEFAULT 'queued',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 claimed_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 result_json MEDIUMTEXT NULL,
 CONSTRAINT fk_remote_job_deployment FOREIGN KEY (deployment_id) REFERENCES dm_deployment(id),
 CONSTRAINT fk_remote_job_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id),
 INDEX idx_remote_job_queue (machine_id,state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
