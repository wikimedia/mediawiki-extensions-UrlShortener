The UrlShortener extension is a MediaWiki extension that accepts arbitrary urls
from a list of allowed domains and shortens them.

== Configuration ==

=== URL routing configuration ===

Configures the template to use when generating the shortened URL. Using this
feature will require mod_rewrite (or an equivalent). If set to false (default),
the short URLs will use the not-so-short
<code>/wiki/Special:UrlShortener/5234</code> since it will work regardless of
web server configuration.

If you wanted your short URLs in the form of <code>domain.org/r/5234</code>, you would set:

<source lang="php">
$wgUrlShortenerTemplate = '/r/$1';
</source>

=== Short domain name ===

If you have a custom short domain name, you can set it by using:

<source lang="php">
$wgUrlShortenerServer = "short.wiki";
</source>

If set to false (default), it will use $wgServer.

=== Global database ===

Set this to the name of a database if you wish to use one central database for
your wiki farm. If set to false (default), it will use the wiki's normal database.

<source lang="php">
$wgUrlShortenerDBName = false;
</source>

If the database is on an external cluster, you will also need to configure that.

<source lang="php">
$wgUrlShortenerDBCluster = false;
</source>

=== Allow arbitrary ports ===

By default, only URLs with ports 80 and 443 are accepted and are automatically removed.
If your wiki is set up using a custom port, set this to true to allow shortening URLs
that have arbitrary ports.

<source lang="php">
$wgUrlShortenerAllowArbitraryPorts = true
</source>

=== AllowedDomains regex ===

Configures the acceptable domains that users can submit links for. This is an
array of regular expressions. If set to false (default), it will set up a allow-list for the current domain (using $wgServer).

<source lang="php">
$wgUrlShortenerAllowedDomains = false;
</source>

For example, to only allow links from wikipedia.org or wikimedia.org, we would use the following:

<source lang="php">
$wgUrlShortenerAllowedDomains = [
	'(.*\.)?wikimedia\.org',
	'(.*\.)?wikipedia\.org',
];
</source>

If we want to allow links from any domain:

<source lang="php">
$wgUrlShortenerAllowedDomains = [ '.*' ];
</source>

=== AllowedDomains documentation ===

To provide human-readable documentation of the list, this is an array of the allowed domains that will be displayed on Special:UrlShortener.

If set to false (default), it will output a normalized version of $wgServer.

<source lang="php">
$wgUrlShortenerApprovedDomains = false;
</source>

If you only allow wikipedia.org and wikimedia.org in the above example:

<source lang="php">
$wgUrlShortenerApprovedDomains = [
	'*.wikimedia.org',
	'*.wikipedia.org',
];
</source>

=== Shortcode character set ===

If you want to customize the character set the shortcodes use, you can override
this setting. If changed, any existing short URLs will go to the wrong
destination.

<source lang="php">
$wgUrlShortenerIdSet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz-';
</source>

In addition, for decoding a character mapping can be specified.
This can be used to map any symbol onto another and maintain
backwards-compatibility for previously generated URLs.
The following example maps the `$` symbol to `-`:

<source lang="php">
$wgUrlShortenerIdMapping = [ '$' => '-' ];
</source>

=== Read-only mode ===

Set $wgUrlShortenerReadOnly to true to prevent users from creating new
short URLs. This is mainly intended as a hack while deploying to Wikimedia sites
and will be removed once it is no longer needed.

== License ==

© 2014 Yuvaraj Pandian (yuvipanda@gmail.com) under the Apache 2.0 license,
see the COPYING file for the full license.
