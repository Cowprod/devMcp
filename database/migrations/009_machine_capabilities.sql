-- Explicit declarations only. Existing hosting and macOS iOS actions are preserved.
CREATE TABLE IF NOT EXISTS dm_machine_capability (
 machine_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 hosting TINYINT(1) NOT NULL,
 cordova_android TINYINT(1) NOT NULL,
 cordova_ios TINYINT(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO dm_machine_capability (machine_key,hosting,cordova_android,cordova_ios)
 SELECT id,1,0,IF(os_family='macos',1,0) FROM dm_machine;
INSERT IGNORE INTO dm_machine_capability (machine_key,hosting,cordova_android,cordova_ios)
 VALUES ('local',1,0,0);
