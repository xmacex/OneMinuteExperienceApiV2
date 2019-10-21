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
use \Directus\Database\TableGatewayFactory;
use \OneMinuteExperienceApiV2\AzureCustomVisionTrainer;

require_once 'AzureCustomVisionTrainer.php';

return [
    'actions' => [
        'item.create.artwork' => function (array $data) {
            // $config = parse_ini_file('config.ini', true);
            // $config = parse_ini_file('../../../../../ome.ini', true);
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

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
        'item.update.artwork' => function (array $artwork) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data', $artwork);

            // The received item contains, beside the id and
            // modification metadata, only the changed fields. So
            // let's use that knowledge.
            if (array_key_exists('image', $artwork)) {
                // image was updated. Do stuff.
                $azure = new AzureCustomVisionTrainer(
                    $config['project']['endpoint'],
                    $config['project']['id'],
                    $config['training']['key'],
                    $config['prediction']['resource_id'],
                    $config['prediction']['production_model']
                );

                $filesService = new FilesServices($container);
                $file = $filesService->findByIds($artwork['image']);
                $image = $file['data'];

                $logger->debug('Artwork image data', $image);

                // $azure->createImageFromUrls($image, $artwork);
                $azure->createImagesFromFiles($image, $artwork);
                // $azure->trainAndPublishIteration();
            }
            // TODO: Also if artist_name or title was updated, rename
            // the tag.
        },
    ]
];
