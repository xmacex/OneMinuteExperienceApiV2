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
    // FIXME: Filters are not triggering, sadly. Reported at
    // https://github.com/directus/api/issues/1364. This is where I
    // want to store the UUID of the tag returned by upstream Azure
    // Custom Vision service.
    'filters' => [
        'item.create.artwork' => function (Payload $payload) {
            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

            $logger->debug('Artwork data in filter', (array)$payload);

            $azure = new AzureCustomVisionTrainer(
                $config['project']['endpoint'],
                $config['project']['id'],
                $config['training']['key'],
                $config['prediction']['resource_id'],
                $config['prediction']['production_model']
            );

            $tag = $azure->createTagFromImage($image);
            $payload->set('image_recognition_tag_id', $tag['id']);

            $logger->debug('After setting the tag UUID.', (array)$payload);

            return $payload;
        }
    ],
    'actions' => [
        'item.create.artwork' => function (array $data) {
            $config = parse_ini_file('/var/www/1mev2/directus/config/ome.ini', true);

            $container = Application::getInstance()->getContainer();
            $logger = $container->get('logger');

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

                $azure->createImagesFromFiles($image, $artwork);
                // $azure->trainAndPublishIteration();
            }
            // TODO: Also if artist_name or title was updated, rename
            // the tag.
        },
    ]
];
