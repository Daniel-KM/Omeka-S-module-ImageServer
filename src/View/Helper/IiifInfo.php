<?php

/*
 * Copyright 2015-2020 Daniel Berthereau
 * Copyright 2016-2017 BibLibre
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace ImageServer\View\Helper;

use Omeka\Api\Representation\MediaRepresentation;
use Zend\View\Helper\AbstractHelper;

/**
 * Helper to get a IIIF info.json for a file.
 */
class IiifInfo extends AbstractHelper
{
    /**
     * Get the IIIF info for the specified record.
     *
     * @todo Replace all data by standard classes.
     *
     * @link https://iiif.io/api/image/2.1
     *
     * @param MediaRepresentation|null $media
     * @param string $version
     * @throws \IiifServer\Iiif\Exception\RuntimeException
     * @return Object|null
     */
    public function __invoke(MediaRepresentation $media, $version = null)
    {
        $view = $this->getView();

        if (is_null($version)) {
            $version = $view->setting('imageserver_info_version', '2.1');
        } else {
            $version = $version === '3.0' ? '3.0' : '2.1';
        }

        return $version === '3.0'
            ? $view->iiifInfo30($media)
            : $view->iiifInfo21($media);
    }
}
