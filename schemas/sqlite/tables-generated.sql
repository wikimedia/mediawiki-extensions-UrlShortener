-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: schemas/table.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/urlshortcodes (
  usc_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  usc_url_hash CHAR(32) NOT NULL,
  usc_url BLOB NOT NULL,
  usc_deleted SMALLINT DEFAULT 0 NOT NULL
);

CREATE UNIQUE INDEX urlshortcodes_url_hash ON /*_*/urlshortcodes (usc_url_hash);
