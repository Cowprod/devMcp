CREATE TABLE IF NOT EXISTS dm_theme (
 id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 project VARCHAR(64) NOT NULL,
 repository VARCHAR(255) NOT NULL,
 preview VARCHAR(255) NOT NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dm_project_theme (
 project_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 theme_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 installed_sha CHAR(40) NULL,
 FOREIGN KEY (theme_id) REFERENCES dm_theme(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO dm_theme VALUES
 ('quartz','Quartz','theme-quartz','https://github.com/Cowprod/theme-quartz','http://192.168.194.189:19052/',1),
 ('listee','Listee','listee-bootstrap','https://github.com/Cowprod/listee-bootstrap','http://192.168.194.189:18539/',0);
