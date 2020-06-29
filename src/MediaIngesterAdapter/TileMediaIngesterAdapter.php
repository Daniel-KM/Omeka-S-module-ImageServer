<?php
namespace ImageServer\MediaIngesterAdapter;

use CSVImport\MediaIngesterAdapter\MediaIngesterAdapterInterface;

class TileMediaIngesterAdapter implements MediaIngesterAdapterInterface
{
    public function getJson($mediaDatum)
    {
        $mediaJson = [];
        $mediaJson['ingest_url'] = $mediaDatum;
        // TODO Support local files (sideload).
        // $mediaJson['tile'] = $mediaDatum;
        // $mediaJson['tile_index'] = $mediaDatum;
        return $mediaJson;
    }
}
