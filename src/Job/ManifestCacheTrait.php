<?php declare(strict_types=1);

namespace ImageServer\Job;

/**
 * Remove the manifests cached by module Iiif Server when media data change.
 */
trait ManifestCacheTrait
{
    /**
     * @var array Item ids as keys.
     */
    protected $outdatedManifestItemIds = [];

    /**
     * @param \Omeka\Entity\Media|\Omeka\Entity\Resource $mediaEntity
     */
    protected function markManifestOutdated($mediaEntity): void
    {
        $item = method_exists($mediaEntity, 'getItem') ? $mediaEntity->getItem() : null;
        if ($item && $item->getId()) {
            $this->outdatedManifestItemIds[(int) $item->getId()] = true;
        }
    }

    /**
     * To be called after the flush, so a manifest is not rebuilt with old data.
     */
    protected function removeOutdatedManifests(): void
    {
        if (!$this->outdatedManifestItemIds) {
            return;
        }
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        if ($plugins->has('iiifAccessCache')
            && method_exists($plugin = $plugins->get('iiifAccessCache'), 'removeManifests')
        ) {
            $config = $services->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $plugin->removeManifests($basePath, array_keys($this->outdatedManifestItemIds));
        }
        $this->outdatedManifestItemIds = [];
    }
}
