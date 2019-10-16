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
use \Directus\Hook\Payload;
use \OneMinuteExperienceApiV2\AzureCustomVisionTrainer;

require_once 'AzureCustomVisionTrainer.php';

return [
    'actions' => [
        'item.create.artwork' => function (array $data) {
            // $config = parse_ini_file('config.ini', true);
            // $config = parse_ini_file('../../../../../ome.ini', true);
            $config = parse_ini_file(
                '/var/www/1mev2/directus/config/ome.ini',
                true
            );

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Config', $config);
            
            $logger->debug('Artwork data', $data);

            $filesService = new FilesServices($container);
            $image = $filesService->findByIds($data['image']);

            $logger->debug('Artwork image data', $image['data']);

            // $azure = new AzureCustomVisionTrainer();
            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );
            $azure->doTheProductiveThings($image, $data);
        },
        'item.read' => function (Payload $payload) {
            $config = parse_ini_file(
                '/var/www/1mev2/directus/config/ome.ini',
                true
            );

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            // photos_of_artwork_on_display
            //  "fields": "created_by,title,image,artist_name,year,story_segment.*,image.*" or something

            $filesService = new FilesServices($container);
            $image = $filesService->findByIds($payload['image']);

            $logger->debug('Artwork image data', $payload['data']);
        }
    ]
];
