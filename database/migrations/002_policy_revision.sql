CREATE TABLE IF NOT EXISTS dm_policy_revision (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 deployment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision_number INT UNSIGNED NOT NULL,
 policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_policy_revision (deployment_id, revision_number),
 CONSTRAINT fk_policy_deployment FOREIGN KEY (deployment_id) REFERENCES dm_deployment(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE dm_job ADD COLUMN policy_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE dm_job ADD CONSTRAINT fk_job_policy FOREIGN KEY (policy_revision_id) REFERENCES dm_policy_revision(id);
