Image Server (module for Omeka S)
=================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__


[Image Server] is a module for [Omeka S] that integrates the [IIIF specifications]
and a full image server to allow to process and share instantly images of any
size and medias (pdf, audio, video, 3D…) in the desired formats. It works with
the module [Iiif Server], that provides main manifests for items. Rotation,
zoom, inside search, etc. may be managed too. Dynamic lists of records may be
created, for example for browse pages.

So it can replace in Omeka any external image server like [Cantaloupe] or [IIP Image],
in particular when using the quick image processor [libvips] as backend.

The full specifications of the [International Image Interoperability Framework]
standard are supported (versions 2 and 3), so any widget that supports it can
use it.

Images are automatically tiled to the [Deep Zoom], the [Zoomify], the [jpeg 2000]
or the [tiled pyramidal tiff] format. Then they can be displayed directly in any
viewer that support thes formats, or in any viewer that supports the IIIF
protocol. Tiled images are displayed with [OpenSeadragon], the default viewer
integrated in Omeka S.

The [Image Server] supports the IXIF media extension too, so manifests can be
served for any type of file. For non-images files, it is recommended to use a
specific viewer or the [Universal Viewer], a widget that can display books,
images, maps, audio, movies, pdf, 3D, and anything else as long as the
appropriate extension is installed.

The IIIF manifests can be displayed with many viewers, the integrated [OpenSeadragon],
the [Universal Viewer], the advanced [Mirador], or the ligher and themable [Diva],
or any other IIIF compatible viewer.

The module supports the local storage (file system) by default. For external
storage, image serving works with any store whose `getUri()` returns a public
url. However, tile creation and deletion require the [Amazon S3] module
specifically, because they use methods (`hasFile()`, `deleteDir()`) not
available in Omeka `StoreInterface`.


Installation
------------

The installation depends on your infrastructure, your images and your rights:

- with or without big images,
- with or without external server,
- with or without vips (fast image tiler),
- with or without clean urls or arks identifier,
- with or without control of rights via module Access,
- with or without tiled images (pyramidal tiff/jpeg 2000),
- with or without quick tiling via update of file .htaccess.

### Module

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

With big bases or on shared hosts, it is recommended to install the optional
module [Job Queue] to avoid to overload the server when creating derivatives.

PHP should be installed with the extension `exif` in order to get the size of
images. This is the case for all major distributions and providers.

For [performance reasons], the recommended image processor is [libvips], used
through the php library [jcupitt/vips] or as a command line tool. See below the
three ways to install it.

The more common [ImageMagick] (command line `magick` or legacy `convert`), [GD]
or [Imagick] (php extensions) are supported as well. Unlike vips, they are
installed by default on most servers.

The module [Iiif Server] is optional. Install it alongside Image Server to
expose IIIF Presentation manifests in addition to the Image API.

* From the zip

Download the last release [ImageServer.zip] from the list of releases (the
master does not contain the dependencies), uncompress it in the `modules`
directory, and rename the module folder `ImageServer`.

* From the source and for development:

If the module was installed from the source, check if the name of the folder of
the module is `ImageServer`, go to the root of the module, and run either:

```sh
composer install --no-dev
```

Then install it like any other Omeka module.

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/ImageServer/phpunit.xml --testdox
```

### Install scenarios

[Image Server] and [Iiif Server] are two independent modules. Each one can be
installed alone, or both together.

- Image Server alone: serve the IIIF Image API (info.json + image requests) from
  the Omeka files locally, without manifest generation. Useful when manifests
  are provided by another tool, or when a IIIF viewer points directly at the
  image endpoints.

- Iiif Server alone: generates IIIF Presentation manifests and provides image
  sizing via the job "Media Dimensions". An external image server ([Cantaloupe],
  IIP Image, etc.) must be configured in Iiif Server settings to handle all
  image processing (tiling, dynamic transformations, region extraction).

- Both installed: Iiif Server auto-detects Image Server and uses it as the local
  IIIF image service in manifests. The image may still be provided by an
  external image server, in particular to manage clean urls and ark identifiers.

### http/2 or http/3

It is recommended to set the web server (usually Apache or Nginx) to serve files
with protocol `http/2`, that allows to send multiple files during the same tcp
connection, so to serve multiple tiles more quickly.

For Apache, you generally just need to enable the module and to replace
incompatible apache modules with new versions, and to make php running via
php-fpm:

```sh
a2dismod mpm_prefork
a2enmod mpm_event
a2enmod proxy_fcgi
a2enmod http2
systemctl restart apache2
systemctl restart php7.4-fpm
```

Of course, the config may be improved with http/3.

### CORS (Cross-Origin Resource Sharing)

To be able to share manifests and contents with other IIIF servers, the server
should allow CORS. The header is automatically set for manifests, but you may
have to allow access for files via the config of the server.

On Apache 2.4, the module "headers" should be enabled:

```sh
a2enmod headers
systemctl restart apache2
```

Then, you have to add the following rules, adapted to your needs, to the file
`.htaccess` at the root of Omeka S or in the main config of the server:

```
# CORS access for some files.
<IfModule mod_headers.c>
    Header setIfEmpty Access-Control-Allow-Origin "*"
    Header setIfEmpty Access-Control-Allow-Headers "origin, x-requested-with, content-type"
    Header setIfEmpty Access-Control-Allow-Methods "GET, POST"
</IfModule>
```

It is recommended to use the main config of the server, for example  with the
directive `<Directory>`.

To fix Amazon issues with cors, see the [aws documentation].

### Vips

For performance, it is recommended to use [libvips] as the Omeka thumbnailer. A
module allows to use it anywhere in Omeka, so it is recommended to use the
module [Vips].

If you don't want to install the module [Vips] but still want to use [libvips],
you should install it.

The library [libvips] can be used in three ways, selected in the module config
as image processor. See the module [Vips] for more details.

- The command line tool: a simple package available in all linux distributions.
  It is used too for the images that require more memory than the php one.
- The php library [jcupitt/vips] v1 with the php extension `ext-vips` (or
  [php-vips]): two times quicker than the command line tool. **Recommended for
  production.** The library v1 is included in the module.
- The php library [jcupitt/vips] v2 with the php extension `ext-ffi`: slightly
  better performance than v1, but complex in production: it requires `ffi.enable=true`
  in php.ini, that allows php to call any C function and to bypass the options
  `disable_functions` and `open_basedir`, so the php documentation
  [recommends against enabling FFI globally in production].

The two versions of the library have the same api and are detected
automatically.

#### Command line tool

On Debian/Ubuntu, with option "--no-install-recommends" to avoid to install the
heavy and useless graphical interface:

```sh
sudo apt install --no-install-recommends libvips-tools
```

On Centos/RedHat:

```sh
sudo dnf install vips-tools
```

Recommended version is 8.10 or higher. Versions prior to 8.4 have not been
tested.

#### Php library v1 with ext-vips (recommended)

Install the extension as a package when available:

```sh
# Debian/Ubuntu
sudo apt install --no-install-recommends php-vips
# Centos/RedHat
sudo dnf install php-vips
```

Else install it via [pecl], for example on Debian/Ubuntu (adapt the version of
php):

```sh
sudo apt install --no-install-recommends libvips-dev php-dev php-pear
sudo pecl install vips
echo "extension=vips.so" | sudo tee /etc/php/8.1/mods-available/vips.ini
sudo phpenmod vips
```

Then restart the web server or php-fpm and check it with `php -m | grep vips`.
The library [jcupitt/vips] v1 is already included in the module.

#### Php library v2 with ext-ffi

The extension `ffi` is standard since php 7.4, but it should be enabled in
php.ini with `ffi.enable=true` (the default value "preload" is not enough). For
php 8.3 and higher, the key `zend.max_allowed_stack_size=-1` should be added
too, since the library runs callbacks out of the main thread. Then install the
library v2 instead of v1 (see the module [Vips]).

### External image server (Cantaloupe): clean and ark identifiers

The media identifier option "Filename with extension" lets an external
Cantaloupe sharing the Omeka `files/original` directory resolve images directly,
but it exposes the file extension in the IIIF url (`{id}.jpg`). To serve a clean
(extension-less storage id) or a meaningful, permanent ark identifier instead,
three strategies are available, depending on who resolves the identifier to the
original file and on whether Cantaloupe shares the originals directory.

| Strategy                             | Identifier      | Resolver                          | Cantaloupe        | Choose when                                                                  |
|--------------------------------------|-----------------|-----------------------------------|-------------------|------------------------------------------------------------------------------|
| A: `delegates/cantaloupe.rb`         | storage id      | filesystem stat (no db, no glob)  | shares originals  | Cantaloupe already shares the originals dir and you only want clean urls     |
| B: `delegates/cantaloupe-resolve.rb` | clean / ark     | Omeka `/iiif-resolve`, cached     | shares originals  | permanent ark urls with maximum tile speed (tiles stay served by Cantaloupe) |
| C: `scripts/iiifforward.php`         | clean / ark     | PHP, in front of Cantaloupe       | untouched         | keep Cantaloupe unaware of arks; resolution and forwarding stay in Omeka     |

The pivot is the resolver: Cantaloupe resolves an identifier to a file on disk
without a database, so a filename-derived identifier (A) needs no lookup, while
an ark needs either a delegate that asks Omeka (B) or a PHP front that resolves
then forwards (C). In all three, audio and video are not concerned: they are
served by Iiif Server, not Cantaloupe, and the resolvers answer for images only.

* A: storage id
  Install `data/delegates/cantaloupe.rb`: it resolves the identifier to the
  original file by probing the known image extensions with a direct stat (no
  database, no directory glob, which matters since the originals directory is
  flat and may hold hundreds of thousands of files). See the header of the
  delegate for the install steps.

* B: ark via Omeka
  Install `data/delegates/cantaloupe-resolve.rb`: it asks Omeka, through the
  `/iiif-resolve` endpoint, for the storage filename of the identifier, then
  reads the file. The resolution reuses the Omeka/CleanUrl logic (no
  reimplementation) and is cached in the delegate, so tiles stay served directly
  by Cantaloupe. The endpoint is restricted to the ips of the setting
  "External image server: trusted ips for identifier resolution"; add the
  Cantaloupe host ip there.

With the module Access, both delegates can check the rights of the client: set
the environment variable `OMEKA_ACCESS=1` in the Cantaloupe container, and add
its ip to the Access setting "access_ip_proxy_trusted". Each image is checked
through the endpoint `/access/authorize` and the decision is cached two minutes
by client. When Omeka does not answer in time (2 s to connect, 3 s to answer),
the image is denied and nothing is cached (fail-closed).

* C: ark via PHP forward
  The script `data/scripts/iiifforward.php` resolves a clean or ark identifier
  to the storage filename in PHP, forwards the request to an internal Cantaloupe
  with that filename, and caches the response on disk. Cantaloupe stays
  filename-based and never sees the public identifier, so the internal storage
  name stays hidden. See the header of the script for the .htaccess rule and the
  environment configuration.

See also the [integration of Cantaloupe with Omeka] and the notes of Cantaloupe
about [performance] for the choice of the source formats.

### Fast tile script

A lightweight php script (`data/scripts/iiiftile.php`) serves the IIIF image
tiles without loading the whole Omeka S stack:

- Cache hit: ~5ms (vs ~200ms with Omeka S stack)
- Cache miss: ~200ms (vips processing + cache write)
- Pre-tiled images: still benefits from bypassing the Omeka S bootstrap

It uses the php library [jcupitt/vips] when available, else the command line
tool, else it hands over the request to Omeka S. It checks `is_public` of each
media via a lightweight database query.

**Warning**: the script checks only if the media is public. It does not check
the restrictions of the module [Access] (reserved or protected medias, embargo),
so a reserved image is served to anybody. Do not enable the script when the
module Access restricts some images.

The library v1 included in the module is used, so only the php extension
`ext-vips` is needed. To use the library v2 with `ext-ffi` instead, install it
in the directory `vendor/` of Omeka, that is loaded first:

```sh
cd /path/to/omeka
composer require jcupitt/vips:^2.0
```

Without the php library, the script uses the command line tool, that is
installed with the package `libvips-tools` (Debian/Ubuntu) or `vips-tools`
(Centos/RedHat).

Of course, if you use an external image server, you don't need to create static
or dynamic tiles (and in that case you don't need this module anyway).

#### Setup

The module adds these rules in `.htaccess` before the Omeka catch-all, that is
the block starting with `RewriteCond %{REQUEST_FILENAME} -f`:

```apache
# Fast IIIF tile server (bypass Omeka S stack).
RewriteCond %{REQUEST_URI} /iiif/([23]/)?[^/]+/[^/]+/[^/]+/[^/]+/[^.]+\.\w+$
RewriteRule iiif/(.*) modules/ImageServer/data/scripts/iiiftile.php [END,E=IIIF_PATH:/iiif/$1]
```

If you install modules with composer (see [#2432]), replace "modules/" by
"composer-addons/modules/".

The rules are not added when the option `imageserver_htaccess_skip` is set, or
when the file `.htaccess` is not writeable by the web server, for example when
it is mounted read-only in a container. In that case, add them manually after
`RewriteEngine On`, or in the config of the virtual host. When a proxy sends the
iiif urls to an external image server (Cantaloupe), the requests do not reach
Omeka, so the proxy should send them to Omeka first.

### Tile formats

Four formats are proposed to create tiles: DeepZoom, Zoomify, pyramidal tiff,
Jpeg 2000. If vips is available, the recommended format is tiled tiff. If
jpeg 2000 is available, use it. Else, use DeepZoom or Zoomify. For pyramidal
tiff and Jpeg 2000, some other tools may be required.

#### Tiled pyramidal tiff

Tiled pyramidal tiff is supported by ImageMagick, Imagick, GD and vips. However,
only vips can efficiently extract a small region without reading the whole file,
so it is recommended to use [libvips] for dynamic extraction of tiled tiff.

#### Jpeg 2000

Jpeg 2000 is supported by ImageMagick (since version 6.9.1.2-1, 2015) and by
vips (since version 8.12, when compiled with OpenJPEG support). In some cases,
you may need to install openjpeg tools:

```sh
sudo apt install libopenjp2-tools
```

Check support with:

```sh
# ImageMagick 7+
magick identify -list format | grep JP2
# or ImageMagick 6
convert identify -list format | grep JP2
# Vips
vips jp2kload
```


Image Server
------------

### Diagnostics

When opening the module configuration page, the module runs infrastructure
diagnostics and displays recommendations:

- Image processor: detect php-vips, vips cli, ImageMagick, Imagick, GD.
  Auto-select vips when available (best performance and memory efficiency).
- Tiling status: count tiled vs untiled images and recommend bulk tiling.
- Fast tile server: check if the `.htaccess` rewrite rule is set up (see below).
- Directories: verify that `files/tile/` and `files/tile/cache/` are writeable.
- PHP memory: warn if `memory_limit` is too low for image processing.
- Auto-tiling mode: warn if auto-tiling is not enabled.

### Auto-tiling

Tiles are created automatically for the new images bigger than the max size of
images processed dynamically (default setting "auto"), or for all of them when
the option to tile small images too is set or when there is no max size (see
below).
Existing images can be tiled in bulk via the config form. The module aggregates
all sizing and tiling requests into a single background job per HTTP request,
avoiding the overhead of one job per media.

**Warning**: don't forget to tile all existing medias and to prepare all image
dimensions. Run the tasks in the config form.

### Zoomable thumbnail display

An option in settings and site settings allows to replace the standard thumbnail
display with a zoomable [OpenSeadragon] viewer for images on public pages. Three
modes are available:

- Tile: display images via OpenSeadragon using pre-tiled data when available,
  with configurable fallback (large thumbnail or original in OSD).
- Large: standard large thumbnail (default for sites).
- Original: serves the original file (not recommended for large images).

This is independent of IIIF viewers (Universal Viewer, Mirador, Diva) and works
without them. It provides a simple zoom experience on any item/media page,
similar to the admin media show page.

### Creation of static tiles

If you use vips, the recommended processor strategy is "Auto/Vips" and "tiled tiff".
Else, if jpeg2000 is available, use "Auto/ImageMagick" and "Jpeg 2000". Else,
use "Auto" and Deepzoom.

For big images that are not stored in a versatile format (jpeg 2000 or tiled
tiff) and that cannot be processed dynamically quickly (for example with vips),
it is recommended to pre-tile them to load and zoom them instantly. It can be
done for any size of images. It may be recommended to manage at least the big
images (more than 10 to 50 MB, according to your server and your public).

Tiles can be created in four formats:

- [Deep Zoom Image] creates tile files. It is a free proprietary format from
  Microsoft largely supported.
- [Zoomify] is an old format that was largely supported by proprietary image
  softwares and free viewers, like the [OpenLayers Zoom]. Nevertheless, it's
  integration in the module is less optimized than Deep Zoom and is not
  recommended for now.
- Jpeg 2000 creates a single file that can be processed quickly with some image
  processor.
- Tiled pyramidal tiff creates a single file too.

All files created are stored in directory `files/tile` of Omeka and can be renamed
with [Archive Repertory] too.

The tiles are created via a background job for any image if the option is set to
manage them automatically. Images can be uploaded from a file, a url, or
imported.

The tiles can be created in bulk via a job, that can be run via a button in the
config form of the module.

### Dynamic creation of tiles and transformation

The IIIF specifications allow to ask for any region of the original image, at
any size, eventually with a rotation and a specified quality and format. The
image server creates them dynamically from the original image, from the Omeka
thumbnails or from the tiles if any.

This dynamic creation is quick when the original is not too big, or in a tiled
format like tiled pyramidal tiff or jpeg 2000, and when vips is used.

The dynamic creation of tiles can be done with the php extensions GD or Imagick
and with the command line tools ImageMagick, default in Omeka, and vips. GD is
generally a little quicker, but ImageMagick manages many more formats. An option
allows to select the library to use according to your server and your documents
or to let the module chooses automagically. Vips is the largely the quickest in
all cases, and it manages natively common formats (gif, jpeg, png, pdf, tiff).

In case of big files, it is recommended to use vips or the command line version
of ImageMagick, that is not limited by the php memory.

The option "Max size of images processed dynamically" limits the number of
pixels (width × height) of the original images processed dynamically, whatever
the image processor, for example 4000000 pixels (4 megapixels, about 2300 ×
1700, so a tile is processed in about 25 ms with GD or Imagick, and about 80 ms
for 12.5 megapixels, for each tile requested by the viewer). Beyond it, only the
tiles and the Omeka derivatives are served, so the big images should be tiled.
With vips, the tiled tiff and jpeg 2000 are processed dynamically too, and the
limit may be higher or empty, because vips uses few memory. Only the images
bigger than this limit are tiled: the smaller ones are always processed
dynamically, so tiling them is useless and only uses time and disk space. An
option allows to tile small images too. Without vips, the tiling of small images
too is recommended for a limit higher than 4000000 pixels, because each tile is
processed from the whole original image.

### Pages of pdf files

Each page of a pdf can be served as an image, like with [Cantaloupe], with the
identifier of the pdf and the number of the page separated with `;`, for example
`iiif/3/xxx.pdf;2/info.json`. So the viewers can display the pdf as images, with
the search and the annotations of the ocr of the module [Iiif Search]. Enable the
option "Display the pages of the pdf files as images of the image server" in the
module [Iiif Server] to set the pages in the manifests.

The page is rendered once with vips at the resolution set in the module Iiif
Server (150 dpi by default), in the directory `files/tile/pdf/`, then processed
like any image, by Omeka or by the fast tile script. Vips (command line tool or
php library) is required, with the support of pdf (poppler or pdfium), that is
included in the standard packages. The rendered pages are removed with the
media.

The directory `files/tile/pdf/` is protected from a direct web access with a
file `.htaccess`, because a page may be reserved by the module [Access], while
the image server checks the rights of the pdf. With Nginx, deny it in the config
of the server. The pdf itself, available as a download in the manifest, is
protected by the module Access like any original file.

Performance on a scanned page (680 × 1092 at 150 dpi): about 150 ms to render a
page with vips, then the page is processed like a jpeg, with the cache of the
fast tile script.

The character `;` must not be url-encoded, so check the rules of the proxy.

### Statistics of the viewed zones

With the module [Analytics] and its option to record the segments, the image
server records the zones of the images that are requested by the viewers, in a
grid of 16 × 16 cells by image and by month, both in Omeka and in the fast tile
script. A request of the full image is a view, not a zone, so it is not recorded.
The counts are stored in the table of the module Analytics. Without the module
or the option, nothing is recorded.

### Display of standard and tiled images

When created, the tiles are displayed automatically in admin and theme,
according to the setting in the section Image Server.

Any viewer that supports Deep Zoom or Zoomify can display them directly.
[OpenSeadragon], the viewer integrated by default in Omeka S, can display them
directly (from version 2.2.2), so it is quicker. This is the case for any
derivated viewer too (Universal Viewer, Mirador, etc.). The [OpenLayers] viewer
supports the two formats too.

The options for the default viewer can be changed in the theme (in partial "common/renderer/tile.phtml",
to copy in your theme, or by passing option `template` in the renderer).

When the viewer doesn’t support a format, but the IIIF protocol, the image can
be displayed through its IIIF url (https://example.org/iiif/{identifier}).
It can be done for any image, even if it is not tiled, because of the dynamic
transformation of images. OpenSeadragon supports iiif too (v2 and v3).

To display an image with the IIIF protocol, set its url (https://example.org/iiif/{identifier}/info.json)
in an attached media of type "IIIF" or use it directly in your viewer. The id is
the one of the media, not the item.

### Routes

All routes of the Image server are defined in `config/module.config.php`.
They follow the recommandations of the [IIIF specifications].

To view the json-ld manifests created for each resources of Omeka S, simply try
these urls (replace :id by a true id):

- https://example.org/iiif/:id/info.json for images files;
- https://example.org/iiif/:id/:region/:size/:rotation/:quality.:format for
images, for example: https://example.org/iiif/1/full/full/270/gray.png;
- https://example.org/iiif/:id/info.json for other files;
- https://example.org/iiif/:id.:format for the files.

By default, ids are the internal ids of Omeka S, but it is recommended to use
your own single and permanent identifiers that don’t depend on an internal
pointer in a database. The term `Dublin Core Identifier` is designed for that
and a record can have multiple single identifiers. There are many possibilities:
named number like in a library or a museum, isbn for books, or random id like
with ark, noid, doi, etc. They can be displayed in the public url with the
modules [Ark] and/or [Clean Url].

### External storage (Amazon S3 and others)

Image serving (IIIF Image API) and sizing work with any store whose `getUri()`
returns a publicly accessible URL, so any S3-compatible module (Amazon, MinIO,
Wasabi, etc.) works for that part. However, tile creation and deletion currently
require the [Amazon S3] module specifically, because the tiling pipeline calls
`AmazonS3`-specific methods (`hasFile()`, `deleteDir()`) that are not part of
Omeka `StoreInterface`.

Currently, only the public files are available: let the option "expiration" to "0".
You should add CORS header `Access-Control-Allow-Origin` to make OpenSeadragon
and other viewers working. See [aws documentation].

### Vips as default thumbnailer

To use vips as the [default thumbnailer] for Omeka S, install the module [Vips].
It provides PHP library mode (faster) and CLI fallback, and sets itself
automatically as the default thumbnailer. See the module [Vips] documentation
for configuration details.

Note: the old `ImageServer\File\Thumbnailer\Vips` thumbnailer has been removed.
If your `config/local.config.php` references it, replace it with the module
[Vips] and remove the alias.


TODO / Bugs
-----------

- [ ] Separate from Omeka/Laminas and Convert into a standalone composer package.
- [x] Cache all original images as jpeg2000 to speed up dynamic requests or any region/size.
- [x] Create thumbnails from the tiled image, not from the original (ok for vips).
- [ ] Skip old dynamic tile requests when they are too many, so only the last displayed ones are built.
- [ ] Add a cache mechanism for tiles (doc for server level or in php).
- [ ] Add the iiif tiling (see vips dzsave) and check it for performance.
- [ ] Support curl when allow_url_fopen and allow_url_include are forbidden.
- [ ] Automatically manage pdf as a list of canvas and images (extract size and page number, then manage it by the image server)
- [ ] Remove the specific choice of the processor and use the Omeka one (gd/imagemagick/imagick)
- [ ] Adapt the info.json to the image processor (don't list bitonal when using vips, etc.).
- [ ] Add the canonical link header.
- [x] Use the tiled images when available for arbitrary size request (ok for vips/tiled tiff).
- [ ] Update tiling pipeline to use `StoreInterface` instead of `AmazonS3`-specific methods, so any S3-compatible store can be used for tile creation/deletion.
- [ ] Add a limit (width/height) for dynamic extraction (used with zoning and annotations).
- [x] Add a processor for [php-vips].
- [x] Use vips as Omeka thumbnailer.
- [ ] Add auto as default type of tiles and thumbnailer (so choose tiled tiff if vips is installed, and select thumbnailer according to input format, etc.).
- [ ] For jpeg2000, use library [OpenJpeg] instead of ImageMagick or Vips: a dedicated decoder (`opj_decompress`/`kdu_expand`) getting internal tile directly is better for large jp2 (see [gitlab#7]).
- [x] Skip the warning about missing `vips`/`convert` commands when an external image server (e.g. Cantaloupe) is configured, since local image processing tools are not needed in that case.
- [ ] Fix bitonal with vips.
- [ ] Fix save jp2 with vips (vips does not support jp2 output natively).
- [x] Check why zoomify and deepzoom arounds (or overlap) are different (deepzoom is more compliant with OpenSeadragon). By design: DeepZoom uses 1px overlap, Zoomify uses 0.
- [ ] Check why zoomify create bigger thumbnails.
- [ ] Fix conversion of some iiif tiles for zoomify.

See module [Iiif Server].


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.

This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.

The module uses the [Deepzoom library] and [Zoomify library], the first based on
[Deepzoom] of Jeremy Buggs (license MIT) and the second of various authors
(license [GNU/GPL]). See files inside the folder `vendor` for more information.

* icc profile

The minimal sRGB ICC v2 profile is a domain public one [from Gimp].


Copyright
---------

* Copyright Daniel Berthereau, 2015-2026 (see [Daniel-KM])
* Copyright BibLibre, 2016-2017

This module is a rewrite of the [Universal Viewer plugin for Omeka Classic],
built for the [Bibliothèque patrimoniale] of [Mines ParisTech]. The upgrade to
Omeka S was initially done by [BibLibre]. It has the same features as the
original plugin, but separated into three modules (the IIIF server, the image
server and the widget Universal Viewer). It integrates the tiler [Zoomify] that
was used the plugin [OpenLayers Zoom] for [Omeka Classic] and another tiler to
support the [Deep Zoom Image] tile format.


[Image Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer
[Omeka S]: https://omeka.org/s
[International Image Interoperability Framework]: http://iiif.io
[IIIF specifications]: http://iiif.io/api/
[Cantaloupe]: https://cantaloupe-project.github.io
[IIP Image]: https://iipimage.sourceforge.io
[Iiif Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[Iiif Search]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifSearch
[Access]: https://gitlab.com/Daniel-KM/Omeka-S-module-Access
[Analytics]: https://gitlab.com/Daniel-KM/Omeka-S-module-Analytics
[OpenSeadragon]: https://openseadragon.github.io
[Universal Viewer]: https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Mirador]: https://gitlab.com/Daniel-KM/Omeka-S-module-Mirador
[Diva]: https://gitlab.com/Daniel-KM/Omeka-S-module-Diva
[Amazon S3]: https://gitlab.com/Daniel-KM/Omeka-S-module-AmazonS3
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[performance reasons]: https://github.com/libvips/libvips/wiki/Speed-and-memory-use
[Vips]: https://gitlab.com/Daniel-KM/Omeka-S-module-Vips
[libvips]: https://libvips.github.io/libvips
[php-vips]: https://github.com/libvips/php-vips-ext
[jcupitt/vips]: https://github.com/libvips/php-vips
[pecl]: https://pecl.php.net/package/vips
[recommends against enabling FFI globally in production]: https://www.php.net/manual/en/ffi.configuration.php
[ImageMagick]: https://www.imagemagick.org
[GD]: https://secure.php.net/manual/en/book.image.php
[Imagick]: https://php.net/manual/en/book.imagick.php
[ImageServer.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/releases
[Universal Viewer]: https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Ark]: https://github.com/BibLibre/omeka-s-module-Ark
[Clean Url]: https://github.com/BibLibre/omeka-s-module-CleanUrl
[Deep Zoom]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Deep Zoom Image]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Zoomify]: http://www.zoomify.com/
[jpeg 2000]: https://jpeg.org/jpeg2000
[tiled pyramidal tiff]: https://en.wikipedia.org/wiki/TIFF
[integration of Cantaloupe with Omeka]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate/-/blob/master/cantaloupe.md
[OpenLayers]: https://openlayers.org/
[Archive Repertory]: https://gitlab.com/Daniel-KM/Omeka-S-module-ArchiveRepertory
[Deepzoom library]: https://gitlab.com/Daniel-KM/LibraryDeepzoom
[Zoomify library]: https://gitlab.com/Daniel-KM/LibraryZoomify
[Deepzoom]: https://github.com/jeremytubbs/deepzoom
[gitlab#7]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/work_items/7
[aws documentation]: https://docs.aws.amazon.com/AmazonS3/latest/dev/cors.html
[default thumbnailer]: https://omeka.org/s/docs/user-manual/configuration/#thumbnails
[performance]: https://cantaloupe-project.github.io/manual/4.0/images.html
[OpenJpeg]: https://github.com/uclouvain/openjpeg
[#2432]: https://github.com/omeka/omeka-s/pull/2432
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/work_items
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[from Gimp]: https://pippin.gimp.org/sRGBz
[Universal Viewer plugin for Omeka Classic]: https://gitlab.com/Daniel-KM/Omeka-plugin-UniversalViewer
[BibLibre]: https://github.com/biblibre
[OpenLayers Zoom]: https://gitlab.com/Daniel-KM/Omeka-S-module-OpenLayersZoom
[Omeka Classic]: https://omeka.org
[Bibliothèque patrimoniale]: https://patrimoine.mines-paristech.fr
[Mines ParisTech]: http://mines-paristech.fr
[Job Queue]: https://gitlab.com/Daniel-KM/Omeka-S-module-JobQueue
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
