Using image server Cantaloupe with Omeka
========================================

Cantaloupe can be used as an image server for Omeka. In that cases, there are
two ways to use it, like for any other image server: the files can be managed in
a specific directory or the Omeka directory files/original can be used.

In the first case, the librarian manages a repository with all files as he wants,
with the structure he wants, with the filenames he wants. The directory can be
on the same server or outside. The only point to check is the fact that files
should not be moved once inside Omeka, because Omeka does not know them. In this
structure, the medias should be attached to items as IIIF medias, not as direct
files loaded in Omeka. The urls may be similar or different to the one provided
by Omeka.

In the second case, the files are managed by Omeka and cantaloupe uses it as
read only. The librarian should not have access to the directory or to the
server. This structure allows to migrate from a version of Omeka with Image
server to a version of Omeka with an external image server without modifying
anything in metadata or process: files should still be loaded as before.

To make this structure working or if you want to rewrite Cantaloupe urls, there
should be a proxy that redirect urls to images to Cantaloupe. A proxy is
generally only some settings added in the configuration of the Apache web
server.

## Config of Cantaloupe

The config file is located at `/etc/cantaloupe/cantaloupe.properties`.

Use the config as you want and set the path prefix, for example:

```
FilesystemSource.lookup_strategy = BasicLookupStrategy
FilesystemSource.BasicLookupStrategy.path_prefix = /path/to/omeka/files/original/
FilesystemSource.BasicLookupStrategy.path_suffix =
```

Use other options as you want. Set the directory if you choose a local cache.

## Config of Apache as proxy

In the site config of Apache, for example `/etc/apache2/sites-enabled/default.conf`,
add these lines:

```apache2
<VirtualHost *:443>

  ...

  ###
  # Added for Cantaloupe / Start

  <Location "/iiif-img">
    Require all granted
    ## Request Header rules
    RequestHeader set X-Forwarded-Proto HTTPS
    RequestHeader set X-Forwarded-Port 443
    RequestHeader set X-Forwarded-Path /
  </Location>

  AllowEncodedSlashes nodecode

  ## Header rules
  ## as per http://httpd.apache.org/docs/2.4/mod/mod_headers.html#header
  Header set Access-Control-Allow-Origin "*"

  ## SSL Proxy directives
  SSLProxyEngine On

  ## Proxy rules
  ProxyRequests Off
  ProxyPreserveHost Off
  ProxyPass /iiif-img/ http://localhost:8182/iiif/ nocanon
  ProxyPassReverse /iiif-img/ http://localhost:8182/iiif/

  ## RedirectMatch rules
  RedirectMatch temp  ^/$ https://%{HTTP_HOST}/s/omeka-site

  ## Rewrite rules
  RewriteEngine On

  RewriteCond %{HTTP_USER_AGENT} "(MSIE [6789]|Mac OS X 10.[2-9]|Presto/(1|2.[2-9])|Firefox/4.0.1|Trident/[3456])"
  RewriteRule (.*) - [F,L,QSA]

  RewriteRule ^/iiif-img/([2|3]/)?([^\/]+)$ https://%{HTTP_HOST}/iiif-img/$1$2/info.json [NE,R=303,L]

  RewriteRule ^/iiif/((?:[2|3]/)?[^\/]+/(?:full|square|pct:[\d.]+|[\d.]*,[\d,.]*)/.+)$ https://%{HTTP_HOST}/iiif-img/$1 [NE,P,L]

  # Added for Cantaloupe / End
  ###

  ...

</VirtualHost>
```

## Config of systemd

The systemd config is not required, but recommended, whatever the config.
If not automatically created, create `/etc/systemd/system/cantaloupe.service`:

```ini
[Unit]
Description=Cantaloupe
Wants=network-online.target
After=network-online.target

[Service]
User=cantaloupe
Group=cantaloupe

ExecStart=/usr/lib/jvm/java-11-openjdk-amd64/bin/java -Dcantaloupe.config=/etc/cantaloupe/cantaloupe.properties -Xmx2g -jar /opt/cantaloupe/cantaloupe.jar
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

## Follow logs in console

```sh
tail -f /var/log/cantaloupe/application.log /var/log/cantaloupe/error.log /var/log/cantaloupe/access.log
```
