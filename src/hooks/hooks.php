<?php
/**
 * Webhooks for One Minute Experience APIv2 under Directus.
 *
 * @author  Mace Ojala <maco@itu.dk>
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GPL
 * @link    https://gifting.digital
 */

use \Directus\Application\Application;
use \Directus\Services\FilesServices;
use \OneMinuteExperienceApiV2\AzureCustomVisionTrainer;

require_once 'AzureCustomVisionTrainer.php';

return [
    'actions' => [
        'item.create.artwork_photos' => function ($data) {
            $config = parse_ini_file(
                '/var/www/1mev2/directus/config/ome.ini',
                true
            );
            
            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Create artwork photos junction', $data);
        },
        'item.create.artwork' => function (array $data) {
            $config = parse_ini_file(
                '/var/www/1mev2/directus/config/ome.ini',
                true
            );

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data', $data);

            $filesService = new FilesServices($container);
            $image = $filesService->findByIds($data['image']);

            $logger->debug('Artwork image data', $image['data']);

            $logger->debug('Created data', $data);

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );
            // $azure->doTheProductiveThings($image, $data);
            $azure->doTheVerboseThings($data);
        },
        'item.update.artwork' => function (array $data) {
            $config = parse_ini_file(
                '/var/www/1mev2/directus/config/ome.ini',
                true
            );

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );
            $azure->doTheVerboseThings($data);
        }
    ]
];