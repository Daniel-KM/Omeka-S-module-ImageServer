Image Server (module for Omeka S)
=================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better.__


[Image Server] is a module for [Omeka S] that integrates the [IIIF specifications]
and a simple image server (similar to a basic [IIP Image]) to allow to process
and share instantly images of any size and medias (pdf, audio, video, 3D…) in
the desired formats. It works with the module [Iiif Server], that provides main
manifests for items.

The full specifications of the [International Image Interoperability Framework]
standard are supported (versions 2 and 3), so any widget that supports it can
use it.

Rotation, zoom, inside search, etc. may be managed too. Dynamic lists of records
may be created, for example for browse pages.

Images are automatically tiled to the [Deep Zoom] or to the [Zoomify] formats.
They can be displayed directly in any viewer that support thes formats, or in
any viewer that supports the IIIF protocol. Tiled images are displayed with
[OpenSeadragon], the default viewer integrated in Omeka S.

The [Image Server] supports the IXIF media extension too, so manifests can be
served for any type of file. For non-images files, it is recommended to use a
specific viewer or the [Universal Viewer], a widget that can display books,
images, maps, audio, movies, pdf, 3D, and anything else as long as the
appropriate extension is installed.

The IIIF manifests can be displayed with many viewers, the integrated [OpenSeadragon],
the [Universal Viewer], the advanced [Mirador], or the ligher and themable [Diva],
or any other IIIF compatible viewer.

It supports Amazon S3 backend throught the module [Amazon S3].


Installation
------------

PHP should be installed with the extension `exif` in order to get the size of
images. This is the case for all major distributions and providers. At least one
of the php extensions [`GD`] or [`Imagick`] are recommended. They are installed
by default in most servers. If not, the image server will use the command line
[ImageMagick] tool `convert`, that is used in the default config of Omeka
anyway.

The module [Iiif Server] is currently required and should be installed first.

Note: To keep old options from [Universal Viewer], upgrade it to version 3.4.3
before enabling of ImageServer. Else, simply set them in the config form.

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

* CORS (Cross-Origin Resource Sharing)

To be able to share manifests and contents with other Image servers, the server
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
    <FilesMatch "\.json$">
        Header add Access-Control-Allow-Origin "*"
        Header add Access-Control-Allow-Headers "origin, x-requested-with, content-type"
        Header add Access-Control-Allow-Methods "GET, POST, OPTIONS"
    </FilesMatch>
</IfModule>
```

It is recommended to use the main config of the server, for example  with the
directive `<Directory>`.

To fix Amazon cors issues, see the [aws documentation].


Image Server
------------

From version 3.6.3.1, tiles are created automatically for all new images. The
conversion of the renderer from "tile" to the standard "file" can be done with
the job in the config form.

Furthermore, an option in settings and site settings allows to specify the
default display: tile or large thumbnail. It can be selected directly in the
theme too (thumbnail "tile").

### Creation of static tiles

For big images that are not stored in a versatile format and cannot be processed
dynamically quickly, it is recommended to pre-tile them to load and zoom them
instantly. It can be done for any size of images. It may be recommended to
manage at least the big images (more than 10 to 50 MB, according to your server
and your public.

Tiles can be created in two formats: Deep Zoom and Zoomify. [Deep Zoom Image]
is a free proprietary format from Microsoft largely supported, and [Zoomify] is
an old format that was largely supported by proprietary image softwares and free
viewers, like the [OpenLayers Zoom]. They are manageable by the module [Archive Repertory].

The tiles are created via a background job for any image, uploaded from a file,
an url. The media type "Tile", that was a specific type for that, is no more
needed, except in some specific cases.

The tiles are created when the media "Tile" is used. The source can be an
uploaded file, a url or a local file (prepended with "file://" in the form, and
requires the module [File Sideload] to be installed). They can be created via
the modules [CSV Import] and [Bulk Import] too.

The tiles can be created in bulk via a job, that can be run via a button in the
config form of the module.

### Dynamic creation of tiles and transformation

The IIIF specifications allow to ask for any region of the original image, at
any size, eventually with a rotation and a specified quality and format. The
image server creates them dynamically from the original image, from the Omeka
thumbnails or from the tiles if any.

This dynamic creation is quick when the original is not too big or in the format
jpeg 2000.

The dynamic creation of tiles can be done with the php extensions GD or Imagick
and with the command line tool ImageMagick, default in Omeka. GD is generally a
little quicker, but ImageMagick manages many more formats. An option allows to
select the library to use according to your server and your documents or to let
the module chooses automagically.

In case of big files, it is recommended to use the command line version of
ImageMagick, that is not limited by the php memory.

Furthermore, the limit of the size (10000000 bytes by default) can be increased
if you have enough memory, so images won't appear blurry even if they are not tiled.

### Display of standard and tiled images

When created, the tiles are displayed automatically in admin and theme,
according to the setting in the section Image Server.

Any viewer that supports Deep Zoom or Zoomify can display them. [OpenSeadragon],
the viewer integrated by default in Omeka S, can display them directly (from
version 2.2.2), so it is quicker. This is the case for any derivated viewer too
(Universal Viewer, Mirador, etc.). The [OpenLayers] viewer supports the two
formats too.

The options for the default viewer can be changed in the theme (in partial "common/renderer/tile.phtml",
to copy in your theme, or by passing option `template` in the renderer).

When the viewer doesn’t support a format, but the IIIF protocol, the image can
be displayed through its IIIF url (https://example.org/iiif-img/{identifier}).
It can be done for any image, even if it is not tiled, because of the dynamic
transformation of images.

To display an image with the IIIF protocol, set its url (https://example.org/iiif-img/{identifier}/info.json)
in an attached media of type "IIIF" or use it directly in your viewer. The id is
the one of the media, not the item.

### Routes

All routes of the Image server are defined in `config/module.config.php`.
They follow the recommandations of the [IIIF specifications].

To view the json-ld manifests created for each resources of Omeka S, simply try
these urls (replace :id by a true id):

- https://example.org/iiif-img/:id/info.json for images files;
- https://example.org/iiif-img/:id/:region/:size/:rotation/:quality.:format for
images, for example: https://example.org/iiif-img/1/full/full/270/gray.png;
- https://example.org/ixif-media/:id/info.json for other files;
- https://example.org/ixif-media/:id.:format for the files.

By default, ids are the internal ids of Omeka S, but it is recommended to use
your own single and permanent identifiers that don’t depend on an internal
pointer in a database. The term `Dublin Core Identifier` is designed for that
and a record can have multiple single identifiers. There are many possibilities:
named number like in a library or a museum, isbn for books, or random id like
with ark, noid, doi, etc. They can be displayed in the public url with the
modules [Ark] and/or [Clean Url].

### Amazon S3

Currently, only the public files are available: let the option "expiration" to "0".
You should add CORS header `Access-Control-Allow-Origin` to make OpenSeadragon
and other viewers working. See [aws documentation].


TODO / Bugs
-----------

- [ ] Cache all original images as jpeg2000 to speed up dynamic requests or any region/size.
- [ ] Create thumbnails from the tiled image, not from the original.
- [ ] Support curl when allow_url_fopen and allow_url_include are forbidden.
- [ ] Automatically manage pdf as a list of canvas and images (extract size and page number, then manage it by the image server)
- [ ] Remove the specific choice of the processor and use the Omeka one (gd/imagemagick/imagick)
- [ ] Adapt the info.json to the image processor.
- [ ] Add the canonical link header.
- [ ] Use the tiled images when available for arbitrary size request.
- [ ] Update vendor tilers to manage Amazon directly.
- [ ] Add a limit (width/height) for dynamic extraction (used with zoning and annotations).

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


Copyright
---------

* Copyright Daniel Berthereau, 2015-2020 (see [Daniel-KM])
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
[IIP Image]: http://iipimage.sourceforge.net
[Iiif Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[OpenSeadragon]: https://openseadragon.github.io
[Universal Viewer]: https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Mirador]: https://gitlab.com/Daniel-KM/Omeka-S-module-Mirador
[Diva]: https://gitlab.com/Daniel-KM/Omeka-S-module-Diva
[Amazon S3]: https://gitlab.com/Daniel-KM/Omeka-S-module-AmazonS3
[`GD`]: https://secure.php.net/manual/en/book.image.php
[`Imagick`]: https://php.net/manual/en/book.imagick.php
[ImageMagick]: https://www.imagemagick.org/
[ImageServer.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/releases
[Universal Viewer]: https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Ark]: https://github.com/BibLibre/omeka-s-module-Ark
[Clean Url]: https://github.com/BibLibre/omeka-s-module-CleanUrl
[Collection Tree]: https://gitlab.com/Daniel-KM/Omeka-S-module-CollectionTree
[Deep Zoom]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Deep Zoom Image]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Zoomify]: http://www.zoomify.com/
[OpenLayers]: https://openlayers.org/
[threejs]: https://threejs.org
[Archive Repertory]: https://gitlab.com/Daniel-KM/Omeka-S-module-ArchiveRepertory
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[File Sideload]: https://github.com/omeka-s-modules/FileSideload
[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Deepzoom library]: https://gitlab.com/Daniel-KM/LibraryDeepzoom
[Zoomify library]: https://gitlab.com/Daniel-KM/LibraryZoomify
[Deepzoom]: https://github.com/jeremytubbs/deepzoom
[#6]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/issues/6
[aws documentation]: https://docs.aws.amazon.com/AmazonS3/latest/dev/cors.html
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-ImageServer/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Universal Viewer plugin for Omeka Classic]: https://gitlab.com/Daniel-KM/Omeka-plugin-UniversalViewer
[BibLibre]: https://github.com/biblibre
[OpenLayers Zoom]: https://gitlab.com/Daniel-KM/Omeka-S-module-OpenLayersZoom
[Omeka Classic]: https://omeka.org
[Bibliothèque patrimoniale]: https://patrimoine.mines-paristech.fr
[Mines ParisTech]: http://mines-paristech.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
