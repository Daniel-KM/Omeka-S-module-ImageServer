Image Server (module for Omeka S)
=================================

[![Build Status](https://travis-ci.org/Daniel-KM/Omeka-S-module-ImageServer.svg?branch=master)](https://travis-ci.org/Daniel-KM/Omeka-S-module-ImageServer)

[Image Server] is a module for [Omeka S] that integrates the [IIIF specifications]
and a simple image server (similar to a basic [IIP Image]) to allow to process
and share instantly images of any size and medias (pdf, audio, video, 3D…) in
the desired formats. It works with the module [Iiif Server], that provides main
manifests for items.

The full specifications of the [International Image Interoperability Framework]
standard are supported (level 2), so any widget that supports it can use it.
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

This [Omeka S] module is a rewrite of the [Universal Viewer plugin for Omeka] by
[BibLibre] with the same features as the original plugin, but separated into two
modules (the Image server and the widget Universal Viewer). It integrates the
tiler [Zoomify] that was used the plugin [OpenLayers Zoom] for [Omeka Classic]
and another tiler to support the [Deep Zoom Image] tile format.

The IIIF manifests can be displayed with many viewers, the integrated [OpenSeadragon],
the [Universal Viewer], the advanced [Mirador], or the ligher and themable [Diva],
or any other IIIF compatible viewer.


Installation
------------

PHP should be installed with the extension `exif` in order to get the size of
images. This is the case for all major distributions and providers. At least one
of the php extensions [`GD`] or [`Imagick`] are recommended. They are installed
by default in most servers. If not, the image server will use the command line
[ImageMagick] tool `convert`.

The module [Iiif Server] is currently required and should be installed first.

Note: To keep old options from [Universal Viewer], upgrade it to version 3.4.3
before enabling of ImageServer. Else, simply set them in the config form.

* From the zip

Download the last release [`ImageServer.zip`] from the list of releases (the
master does not contain the dependencies), uncompress it in the `modules`
directory, and rename the module folder `ImageServer`.

* From the source and for development:

If the module was installed from the source, check if the name of the folder of
the module is `ImageServer`, go to the root of the module, and run either:

```
    composer install
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


Notes
-----

When you need to display big images (bigger than 10 to 50 MB according to your
server), it is recommended to upload them as "Tile", so tiles will be
automatically created (see below).


Image Server
-----------

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

### Features

The image server has two roles.

* Dynamic creation of tiles and transformation

  The IIIF specifications allow to ask for any region of the original image, at
  any size, eventually with a rotation and a specified quality and formats. The
  image server creates them dynamically from the original image, from the Omeka
  thumbnails or from the tiles if any.

  It is recommended to use the php extensions GD or Imagick. The command line
  tool ImageMagick, default in Omeka, is supported, but slower. GD is generally
  a little quicker, but ImageMagick manages many more formats. An option allows
  to select the library to use according to your server and your documents or to
  let the module chooses automagically.

  In case of big files, it is recommended to use the command line version of
  ImageMagick, that is not limited by the php memory.

  Furthermore, the limit of the size (10000000 bytes by default) can be
  increased if you have enough memory, so images won't appear blurry even if
  they are not tiled.

* Creation of tiles

  For big images that are not stored in a versatile format and cannot be
  processed dynamically quickly, it is recommended to pre-tile them to load and
  zoom them instantly. It can be done for any size of images. It may be
  recommended to manage at least the big images (more than 10 to 50 MB,
  according to your server and your public).

  Tiles can be created in two formats: Deep Zoom and Zoomify. [Deep Zoom Image]
  is a free proprietary format from Microsoft largely supported, and [Zoomify]
  is an old format that was largely supported by proprietary image softwares and
  free viewers, like the [OpenLayers Zoom]. They are manageable by the module
  [Archive Repertory].

  The tiles are created via a background job from the media "Tile" (in item edit
  view).

  The tiles can be created in bulk via a job, that can be run via a button in
  the config form of the module.

* Display of tiled and simple images

When created, the tiles are displayed via their native format, so only viewers
that support them can display them. [OpenSeadragon], the viewer integrated by
default in Omeka S, can display the formats Deep Zoom and Zoomify directly (from
version 2.2.2), so it is quicker. The [OpenLayers] viewer support the two
formats too. The mode ("iiif" of "native") and other OpenSeadragon settings can
be changed when the renderer is called.

When the viewer doesn’t support a format, but the IIIF protocol, the image can
be displayed through its IIIF url (https://example.org/iiif-img/:id). This can
be done for any image, even if it is not tiled, because of the dynamic
transformation of images.

To display an image with the IIIF protocol, set its url (https://example.org/iiif-img/:id/info.json)
in an attached media of type "IIIF" or use it directly in your viewer. The id is
the one of the media, not the item.


TODO / Bugs
-----------

- Create thumbnails from the tiled image, not from the original.
- Support curl when allow_url_fopen and allow_url_include are forbidden.
- Automatically manage pdf as a list of canvas and images (extract size and
  page number, then manage it by the image server)
- Support a different route for iiif version 2 and iiif version 3, plus the
  default one.

See module [Iiif Server].


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
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

First version of this plugin was built for the [Bibliothèque patrimoniale] of
[Mines ParisTech].


[Image Server]: https://github.com/Daniel-KM/Omeka-S-module-ImageServer
[Omeka S]: https://omeka.org/s
[International Image Interoperability Framework]: http://iiif.io
[IIIF specifications]: http://iiif.io/api/
[IIP Image]: http://iipimage.sourceforge.net
[Iiif Server]: https://github.com/Daniel-KM/Omeka-S-module-IiifServer
[OpenSeadragon]: https://openseadragon.github.io
[Universal Viewer plugin for Omeka]: https://github.com/Daniel-KM/Omeka-plugin-UniversalViewer
[BibLibre]: https://github.com/biblibre
[OpenLayers Zoom]: https://github.com/Daniel-KM/Omeka-S-module-OpenLayersZoom
[Universal Viewer]: https://github.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Mirador]: https://github.com/Daniel-KM/Omeka-S-module-Mirador
[Diva]: https://github.com/Daniel-KM/Omeka-S-module-Diva
[Omeka Classic]: https://omeka.org
[`GD`]: https://secure.php.net/manual/en/book.image.php
[`Imagick`]: https://php.net/manual/en/book.imagick.php
[ImageMagick]: https://www.imagemagick.org/
[`ImageServer.zip`]: https://github.com/Daniel-KM/Omeka-S-module-ImageServer/releases
[Universal Viewer]: https://github.com/Daniel-KM/Omeka-S-module-UniversalViewer
[Ark]: https://github.com/BibLibre/omeka-s-module-Ark
[Clean Url]: https://github.com/BibLibre/omeka-s-module-CleanUrl
[Collection Tree]: https://github.com/Daniel-KM/Omeka-S-module-CollectionTree
[Deep Zoom]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Deep Zoom Image]: https://msdn.microsoft.com/en-us/library/cc645022(v=vs.95).aspx
[Zoomify]: http://www.zoomify.com/
[OpenLayers]: https://openlayers.org/
[threejs]: https://threejs.org
[Archive Repertory]: https://github.com/Daniel-KM/Omeka-S-module-ArchiveRepertory
[Deepzoom library]: https://github.com/Daniel-KM/LibraryDeepzoom
[Zoomify library]: https://github.com/Daniel-KM/LibraryZoomify
[Deepzoom]: https://github.com/jeremytubbs/deepzoom
[#6]: https://github.com/Daniel-KM/Omeka-S-module-ImageServer/issues/6
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-ImageServer/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Bibliothèque patrimoniale]: https://patrimoine.mines-paristech.fr
[Mines ParisTech]: http://mines-paristech.fr
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
