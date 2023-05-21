CREATE TABLE IF NOT EXISTS `web_session` (
	`id` char(32) CHARSET 'ascii' NOT NULL,
	`timestamp` int(11) unsigned NOT NULL,
	`data` longtext CHARSET 'utf8mb4' NOT NULL,
	`dump` longtext CHARSET 'utf8mb4' NULL COMMENT 'IF jsonDebug = TRUE',
	PRIMARY KEY (`id`),
	KEY `timestamp` (`timestamp`)
) ENGINE=InnoDB;
