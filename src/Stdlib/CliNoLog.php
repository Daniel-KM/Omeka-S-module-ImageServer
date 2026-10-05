<?php declare(strict_types=1);

namespace ImageServer\Stdlib;

use Laminas\Log\LoggerInterface;
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
    /**
     * Build a silent cli with the same execute strategy than another one.
     *
     * The strategy of the cli of Omeka is resolved from the config ("auto" is
     * replaced by "proc_open" or "exec" according to the available functions),
     * so it is reused as is.
     */
    public static function fromCli(Cli $cli, LoggerInterface $logger): self
    {
        return new self($logger, $cli->executeStrategy);
    }
}
