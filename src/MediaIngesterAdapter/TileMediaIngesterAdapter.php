<?php
namespace ImageServer\MediaIngesterAdapter;

use CSVImport\MediaIngesterAdapter\MediaIngesterAdapterInterface;

class TileMediaIngesterAdapter implements MediaIngesterAdapterInterface
{
    public function getJson($mediaDatum)
    {
        $mediaJson = [];
        $mediaJson['ingest_url'] = $mediaDatum;
        return $mediaJson;
    }
}
