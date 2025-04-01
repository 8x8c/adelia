<?php
declare(strict_types=1);

// install.php
error_reporting(E_ALL);
ini_set("display_errors", "1");

// Create the /db directory if it doesn't exist
$dbDir = __DIR__ . '/db';
if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {
        exit("Failed to create /db directory.");
    }
}

// Define the database file path
define('CHESSIB_DBNAME', $dbDir . '/chessib.db');
define('CHESSIB_DBPOSTS', "posts");

// Open (or create) the SQLite3 database
$db = new SQLite3(CHESSIB_DBNAME);
$db->busyTimeout(5000);

// Set WAL mode for better concurrency
if (!$db->exec("PRAGMA journal_mode=WAL;")) {
    exit("Failed to set WAL mode.");
}

// Create the posts table
$createTableSQL = "
CREATE TABLE IF NOT EXISTS " . CHESSIB_DBPOSTS . " (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent INTEGER NOT NULL,
    timestamp INTEGER NOT NULL,
    bumped INTEGER NOT NULL,
    ip TEXT NOT NULL,
    name TEXT NOT NULL,
    tripcode TEXT NOT NULL,
    email TEXT NOT NULL,
    nameblock TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    password TEXT NOT NULL,
    file TEXT NOT NULL,
    file_hex TEXT NOT NULL,
    file_original TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    file_size_formatted TEXT NOT NULL,
    image_width INTEGER NOT NULL,
    image_height INTEGER NOT NULL,
    thumb TEXT NOT NULL,
    thumb_width INTEGER NOT NULL,
    thumb_height INTEGER NOT NULL,
    stickied INTEGER NOT NULL DEFAULT 0,
    moderated INTEGER NOT NULL DEFAULT 1
);
";

if (!$db->exec($createTableSQL)) {
    exit("Failed to create posts table: " . $db->lastErrorMsg());
}

// Set permissions on the database file
chmod(CHESSIB_DBNAME, 0664);

// Define INSTALL_MODE so that post.php does not run its main logic when included
define('INSTALL_MODE', true);

// Include post.php to get access to rebuildIndexes()
require_once 'post.php';

// Build the index page(s)
rebuildIndexes();

// Redirect the user to index.html
header("Location: index.html");
exit;
