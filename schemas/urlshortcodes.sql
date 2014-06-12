-- Replace /*_*/ with the proper prefix
-- Replace /*$wgDBTableOptions*/ with the correct options

-- Table for storing mappings between url shortcodes and the URLs they represent
CREATE TABLE IF NOT EXISTS /*_*/urlshortcodes (
    -- base36 representation of this is used as shortcode
	usc_id INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT,
	-- md5 hash of the URL, used to make lookups based on URL indexable
	usc_url_hash CHAR(32) NOT NULL,
	-- Fully qualified URL that this shortcode should redirect to
	usc_url BLOB NOT NULL
) /*$wgDBTableOptions*/;

-- Used to lookup whether a URL already has a shortcode, so we can reuse it instead of
-- creating a new one.
CREATE UNIQUE INDEX /*i*/urlshortcodes_url_hash ON /*_*/urlshortcodes (usc_url_hash);
