<?php declare(strict_types=1);

namespace ImageServer\Service;

use ImageServer\Stdlib\CliNoLog;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Build a CliNoLog instance (Omeka Cli with a silent logger).
 *
 * The default Omeka\Cli logs every failed probe as an error, which floods the
 * log when a command is optional: magick is absent on Debian 12 (which still
 * ships convert), vips is absent on most hosts, etc. CliNoLog is used for
 * feature detection only; real errors are reported upstream by the caller.
 *
 * @see \ImageServer\Stdlib\CliNoLog
 */
class CliNoLogFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $logger = new Logger();
        $logger->addWriter(new Noop());
        $config = $services->get('Config');
        return new CliNoLog($logger, $config['cli']['execute_strategy']);
    }
}
