-- Hosting jobs are intentionally separate from the read-only job table.
-- No enqueue endpoint, agent polling or privileged execution is enabled by
-- merely applying this migration. A central opt-in workflow is still required.
CREATE TABLE IF NOT EXISTS dm_remote_hosting_job (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action_name ENUM('prepare-all','activate-initial','abort-initial') NOT NULL,
 request_json TEXT NOT NULL,
 state ENUM('queued','claimed','succeeded','failed','expired') NOT NULL DEFAULT 'queued',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 expires_at DATETIME(6) NOT NULL,
 claimed_at DATETIME(6) NULL,
 finished_at DATETIME(6) NULL,
 result_json TEXT NULL,
 CONSTRAINT fk_host_job_deployment FOREIGN KEY (deployment_id) REFERENCES dm_deployment(id),
 CONSTRAINT fk_host_job_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id),
 UNIQUE KEY uq_host_job_operation (machine_id, id),
 INDEX idx_host_job_queue (machine_id, state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
