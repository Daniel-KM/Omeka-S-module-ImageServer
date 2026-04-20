<?php declare(strict_types=1);

namespace ImageServer\Stdlib;

use Omeka\Stdlib\Cli;

/**
 * Cli variant whose logger is a Noop, for feature probing.
 *
 * Omeka\Stdlib\Cli logs every failed `command -v` as an error, which is noise
 * for optional binaries (magick, vips). Instantiate this class via the service
 * manager (`ImageServer\Stdlib\CliNoLog`) to run silent probes without
 * affecting the real Omeka logger.
 *
 * @see \ImageServer\Service\CliNoLogFactory
 */
class CliNoLog extends Cli
{
}
