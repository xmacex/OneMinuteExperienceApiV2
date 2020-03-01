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
            $config = parse_ini_file(realpath('../../../../../config/ome.ini'), true);

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

            if (!is_null($artwork['image'])) {
                $tag = $azure->createTagFromArtwork($artwork);
                $payload->set('image_recognition_tag_id', $tag->id);
            }

            $logger->debug('Artwork after create filter', $artwork);

            return $payload;
        },
        'item.update.artwork:before' => function (Payload $payload) {
            $config = parse_ini_file(realpath('../../../../../config/ome.ini'), true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $artwork = $payload->getData();
            $logger->debug('Artwork update filter', $artwork);

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );

            // The image was changed. Drop the tag on Azure and make a new one.
            if ($payload->has('image')) {
                $itemsService = new ItemsService($container);
                $item = $itemsService->find('artwork', $artwork['id']);
                $logger->debug('Lifted', $item);
                $old_tag = $item['data']['image_recognition_tag_id'];

                $azure->deleteTagAndImages($old_tag);

                $tag = $azure->createTagFromArtwork($artwork);
                $payload->set('image_recognition_tag_id', $tag->id);
            }

            return $payload;
        }
    ],
    'actions' => [
        'item.create.artwork' => function (array $artwork) {
            $config = parse_ini_file(realpath('../../../../../config/ome.ini'), true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data', $artwork);

            if (!is_null($artwork['image'])) {
                $filesService = new FilesServices($container);
                $file = $filesService->findByIds($artwork['image']);
                $image = $file['data'];

                $logger->debug('Artwork image data', $image['data']);

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
            }
        },
        'item.update.artwork:before' => function (array $artwork) {
            $config = parse_ini_file(realpath('../../../../../config/ome.ini'), true);

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

            // The image was changed. Create the images and retrain.
            if (array_key_exists('image', $artwork)) {
                $filesService = new FilesServices($container);
                $file = $filesService->findByIds($artwork['image']);
                $image = $file['data'];

                $logger->debug('Artwork image data', $image);

                $azure->createImagesFromFiles($image, $artwork);
                $azure->trainAndPublishIteration();
            }
            // TODO: Also if artist_name or title was updated, rename
            // the tag.

            // The item was deleted. Drop the images and retrain.
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
                $azure->trainAndPublishIteration();
            }
        },
    ]
];
