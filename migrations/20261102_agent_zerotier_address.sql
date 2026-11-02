-- Only authenticated agents may advertise their own observed ZeroTier source.
-- An address is not proof that the application port serves HTTP.
CREATE TABLE IF NOT EXISTS dm_agent_zerotier_address (
    machine_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ipv4 VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_agent_zt_address_machine FOREIGN KEY (machine_id) REFERENCES dm_machine(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
