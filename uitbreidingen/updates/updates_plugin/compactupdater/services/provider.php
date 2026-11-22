<?php

/**
 * Compact Updater - service provider.
 *
 * Registreert de plugin bij Joomla. De namespace wordt hier ook zelf
 * aangemeld: de monitor zet deze plugin via FTP neer, en dan staat hij nog
 * niet in Joomla's namespace-cache (administrator/cache/autoload_psr4.php).
 * Zonder deze regel zou Joomla de pluginklasse niet kunnen vinden.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

\JLoader::registerNamespace('CompactWeb\\Plugin\\System\\Compactupdater', \dirname(__DIR__) . '/src');

return new class () implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config     = (array) PluginHelper::getPlugin('system', 'compactupdater');
                $dispatcher = $container->get(DispatcherInterface::class);

                // Joomla 4 verwacht de dispatcher als eerste argument, Joomla 5
                // en 6 alleen nog de configuratie.
                if (version_compare(JVERSION, '5.0', '<')) {
                    $plugin = new \CompactWeb\Plugin\System\Compactupdater\Extension\Compactupdater($dispatcher, $config);
                } else {
                    $plugin = new \CompactWeb\Plugin\System\Compactupdater\Extension\Compactupdater($config);
                    $plugin->setDispatcher($dispatcher);
                }

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
