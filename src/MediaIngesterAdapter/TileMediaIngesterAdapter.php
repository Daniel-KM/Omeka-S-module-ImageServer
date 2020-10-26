<?php declare(strict_types=1);
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
