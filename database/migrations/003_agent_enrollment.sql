-- One-time machine enrollment invitations. Never store plaintext invitation secrets.
CREATE TABLE IF NOT EXISTS dm_enrollment_invitation (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_enrollment_token_hash (token_hash),
  KEY idx_enrollment_machine (machine_id, expires_at),
  CONSTRAINT fk_enrollment_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
