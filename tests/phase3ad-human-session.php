<?php
declare(strict_types=1);
// Presentation-only fixture. Real auth/migrations are tested in owned FPM suites.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI fixture only');
$ownedHumanDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ownedHumanDb->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_system INTEGER NOT NULL,deleted_at TEXT);INSERT INTO users VALUES(1,0,NULL),(9,0,NULL)');
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$ownedHumanDb);
