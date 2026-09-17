<?php
require_once __DIR__ . '/../config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($isNew) {
        init_schema($pdo);
    }

    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT DEFAULT '',
            custom_fields TEXT DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'subscribed', -- subscribed | unsubscribed | bounced
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
    ");

    $pdo->exec("
        CREATE TABLE lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
    ");

    $pdo->exec("
        CREATE TABLE contact_list (
            contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            list_id INTEGER NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
            PRIMARY KEY (contact_id, list_id)
        );
    ");

    $pdo->exec("
        CREATE TABLE campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            list_id INTEGER NOT NULL REFERENCES lists(id),
            status TEXT NOT NULL DEFAULT 'draft', -- draft | sending | paused | completed
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            started_at TEXT,
            completed_at TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE campaign_sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT 'pending', -- pending | sent | failed | skipped
            error TEXT,
            sent_at TEXT,
            opened_at TEXT
        );
    ");

    $pdo->exec("CREATE INDEX idx_sends_campaign_status ON campaign_sends(campaign_id, status);");
    $pdo->exec("CREATE INDEX idx_contacts_status ON contacts(status);");

    // Default list to make first-run experience smoother
    $pdo->exec("INSERT INTO lists (name) VALUES ('All Contacts');");
}
