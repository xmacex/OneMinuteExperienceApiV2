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
use \Directus\Services\ItemsService;
use \OneMinuteExperienceApiV2\AzureCustomVisionTrainer;

require_once 'AzureCustomVisionTrainer.php';

return [
    'filters' => [
        'item.create.artwork:before' => function (Payload $payload) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $artwork = $payload->getData();
            $logger->debug('Artwork create filter', $artwork);

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );

            $tag = $azure->createTagFromArtwork($artwork);
            $payload->set('image_recognition_tag_id', $tag->id);

            $logger->debug('Artwork after create filter', $artwork);

            return $payload;
        },
        'item.update.artwork:before' => function (Payload $payload) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $artwork = $payload->getData();
            $logger->debug('Artwork update filter', $artwork);

            // $azure = new AzureCustomVisionTrainer(
            //     $config['project']['endpoint'],
            //     $config['project']['id'],
            //     $config['training']['key'],
            //     $config['prediction']['resource_id'],
            //     $config['prediction']['production_model']
            // );

            // TODO: if $payload->has('image') or exists
            // $artwork['image'], then remove the tag, the images,
            // create a new tag tag and store it's UUID.

            return $payload;
        }
    ],
    'actions' => [
        'item.create.artwork' => function (array $artwork) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data', $artwork);

            $filesService = new FilesServices($container);
            $file = $filesService->findByIds($artwork['image']);
            $image = $file['data'];

            $logger->debug('Artwork image data', $image['data']);

            // $azure = new AzureCustomVisionTrainer();
            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );
            // $azure->doTheProductiveThings($image, $artwork);
            $azure->createImagesFromFiles($image, $artwork);
            $azure->trainAndPublishIteration();
        },
        'item.update.artwork:before' => function (array $artwork) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data', $artwork);

            // The received item contains, beside the id and
            // modification metadata, only the changed fields. So
            // let's use that knowledge.

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );

            // if (array_key_exists('image', $artwork)) {
            //     $filesService = new FilesServices($container);
            //     $file = $filesService->findByIds($artwork['image']);
            //     $image = $file['data'];

            //     $logger->debug('Artwork image data', $image);

            //     $azure->doTheProductiveThings($image, $artwork);
            // }
            // TODO: Also if artist_name or title was updated, rename
            // the tag.
            if ($artwork['status'] == "deleted") {
                // FIXME: Retrain and republish
                $logger->debug('Item deleted action', $artwork);
                $itemsService = new ItemsService($container);
                $item = $itemsService->find('artwork', $artwork['id']);
                $logger->debug('Lifted', $item);
                $tag = $item['data']['image_recognition_tag_id'];
                if (!is_null($tag)) {
                    $azure->deleteTagAndImages($tag);
                }
                //$azure->trainAndPublishIteration();
            }
        },
    ]
];
