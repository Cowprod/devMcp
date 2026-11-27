CREATE TABLE IF NOT EXISTS dm_template (
 id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 project_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_project VARCHAR(64) NOT NULL,
 repository VARCHAR(255) NOT NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 KEY dm_template_type_idx (project_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_project_template (
 project_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 template_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_sha CHAR(40) NULL,
 applied_sha CHAR(40) NULL,
 FOREIGN KEY (template_id) REFERENCES dm_template(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO dm_template VALUES
 ('cordova-tabs','Cordova / navigation par onglets','cordova','cordova-shell','Cowprod/cordova-shell',1),
 ('php-cowprod','PHP / socle Cowprod','php','referenciel','Cowprod/referenciel',1);
