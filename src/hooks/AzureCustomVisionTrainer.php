<?php
/**
 * A model of the Azure Custom Vision trainer.
 *
 * @package OneMinuteExperienceApiV2
 * @author  Mace Ojala <maco@itu.dk>
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html GPL
 * @link    https://gifting.digital
 */

namespace OneMinuteExperienceApiV2;

use \Directus\Application\Application;
use \Directus\Services\FilesServices;
use \GuzzleHttp\Client;
use \GuzzleHttp\Psr7;
use \GuzzleHttp\Exception\ClientException;
use \Imagick;

class AzureCustomVisionTrainer
{
    /**
     * Class constructor.
     *
     * @param string $endpoint       Training endpoint URL, with region.
     * @param string $project_id     Project ID.
     * @param string $train_key      Training key.
     * @param string $pred_res_id    Prediction resource ID for publishing.
     * @param string $pub_model_name Name of the published iteration.
     *
     * @return void
     */
    function __construct(
        $endpoint, $project_id,
        $train_key,
        $pred_res_id, $pub_model_name
    ) {
        $container = Application::getInstance()->getContainer();
        $this->logger = $container->get('logger');

        $this->endpoint = $endpoint;
        $this->project_id = $project_id;
        $this->train_key = $train_key;
        $this->pred_res_id = $pred_res_id;
        $this->pub_model_name = $pub_model_name;

        $this->training_endpoint = $this->endpoint . '/customvision/v3.0/training/projects/' . $this->project_id;
        $this->training_delay = 2;
    }

    /**
     * An HTTP client factory.
     *
     * @return \GuzzleHttp\Client
     */
    function createClient()
    {
        $headers = [
            'Training-Key' => $this->train_key,
            'Content-Type' => 'application/json'
        ];

        $client = new Client(['headers' => $headers]);

        $this->logger->debug('Created an HTTP client');

        return $client;
    }

    /**
     * I should actually have a reasonable test framework in place
     * instead of these practices.
     *
     * @param array $image   Image data.
     * @param array $artwork Artwork data.
     *
     * @return void
     */
    function doTheProductiveThings(array $image, array $artwork)
    {
        $this->createImagesFromFiles($image, $artwork);
        $this->trainAndPublishIteration();
    }

    /**
     * Create images from URLs.
     *
     * @param array $image   List of image urls.
     * @param array $artwork Artwork description.
     *
     * @return void
     */
    function createImageFromUrls(array $image, array $artwork)
    {
        $this->logger->debug('Create with image data ', $image);
        $this->logger->debug('Create with artwork data', $artwork);

        // First make a tag for it.
        $tag = $this->createTagFromArtwork($artwork);

        $client = $this->createClient();

        // TODO: Expecting changes in the Artwork data model here, to
        // have 5 or more images, as required by Azure CV to train.

        $urls = [
            'images' => [
                [
                    // 'url' => $image['data'][0]['data']['full_url'],
                    'url' => $image['data']['data']['full_url']
                ]
            ],
            'tagIds' => [$tag->id]
        ];

        $this->logger->debug('Training data', $urls);

        $response = $client->post(
            $this->training_endpoint . '/images/urls',
            ['json' => $urls]
        );

        $this->logger->debug(
            'Azure CV training headers',
            $response->getHeaders()
        );
        $this->logger->debug(
            // 'Azure CV training body',
            $response->getBody()
        );
    }

    /**
     * Create images from data.
     *
     * @param array $image   An image.
     * @param array $artwork An artwork.
     *
     * @return void
     */
    function createImagesFromFiles(array $image, array $artwork)
    {
        $this->logger->debug('Create with image data', $image);
        $this->logger->debug('Create with artwork data', $artwork);

        $client = $this->createClient();

        $tag_id = $artwork['image_recognition_tag_id'];

        $fileurl = $image['data']['full_url'];
        // FIXME: Note thumbnails are scaled and squared.
        $fileurl = $image['data']['thumbnails'][0]['url'];
        $this->logger->debug('Reading file ' . $fileurl);
        $imagedata = file_get_contents($fileurl);
        $this->logger->debug('Read file len ' . strlen($imagedata));

        $base64 = base64_encode($imagedata);
        // $this->logger->debug('Base64' . $base64);

        $images = [
            'images' => [
                // Images are placed here as ['contents' => BASE64]
            ],
            'tagIds' => [$tag_id]
        ];

        // Transformations
        foreach ([-10, -5, 0, 5, 10] as $angle) {
            $imagick = new Imagick($fileurl);
            $imagick->setImageFormat('png');
            $imagick->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);

            $imagick->rotateimage('#00000000', $angle);
            $this->logger->debug('distorting');
            $imagick->distortImage(
                Imagick::DISTORTION_PERSPECTIVE,
                $this->createControlPoints($imagick->getImageWidth(), $imagick->getImageHeight(), 10, 10),
                true
            );
            $base64 = base64_encode($imagick->getImageBlob());
            $images['images'][] = ['contents' => $base64];
        }

        // $this->logger->debug('Body to send', $images);

        $response = $client->post(
            $this->training_endpoint . '/images/files',
            ['json' => $images]
        );

        // $response = $client->post(
        //     'http://localhost:5005' . '/images/files',
        //     ['json' => $images]
        // );

        $this->logger->debug(
            'Azure CV training headers',
            $response->getHeaders()
        );
        $this->logger->debug(
            // 'Azure CV training body',
            $response->getBody()
        );
    }

    /**
     * Create a new tag from an artwork
     *
     * @param array $artwork An artwork.
     *
     * @return array The tag.
     */
    function createTagFromArtwork(array $artwork)
    {
        $this->logger->debug('Creating a tag for artwork', $artwork);

        // $aid = $artwork['id'];
        $artist = $artwork['artist_name'];
        $title = $artwork['title'];
        // $tagname = $aid . ': ' . $artist . ' - ' . $title;
        $tagname = uniqid() . ': ' . $artist . ' - ' . $title;

        $tag = $this->createTag($tagname);

        return $tag;
    }

    /**
     * Create a new tag.
     *
     * @param string $tagname A tag name to create.
     *
     * @return array $tag     A tag.
     */
    function createTag(string $tagname)
    {
        $this->logger->debug('Creating tag named ' . $tagname);

        $client = $this->createClient();

        $response = $client->post(
            $this->training_endpoint . '/tags',
            ['query' => ['name' => $tagname]]
        );

        $tag = json_decode($response->getBody());

        return $tag;
    }

    /**
     * Delete a tag and it's associated images.
     *
     * @param string $id The UUID of the tag.
     *
     * @return void
     */
    function deleteTagAndImages(string $id)
    {
        $this->logger->debug('Deleting tag and its images' . $id);

        $images = $this->getTaggedImages($id);
        $this->deleteImages($images);
        $this->deleteTag($id);
    }

    /**
     * Get images tagged with a tag.
     *
     * @param string $id The tag id.
     *
     * @return array Of images
     */
    function getTaggedImages(string $id)
    {
        $this->logger->debug('Getting images tagged with ' . $id);

        $client = $this->createClient();

        $prod_model = $this->getProductionIteration();

        $response = $client->get(
            $this->training_endpoint . '/images/tagged',
            [
                'query' => [
                    'iterationId' => $prod_model->id,
                    'tagIds' => [$id]
                ]
            ]
        );

        $images = json_decode($response->getBody());

        return $images;
    }

    /**
     * Delete images.
     *
     * @param array $images Images.
     *
     * @return void
     */
    function deleteImages(array $images)
    {
        $this->logger->debug('Deleting images', $images);

        $client = $this->createClient();

        $image_ids = array_column($images, 'id');

        $response = $client->delete(
            $this->training_endpoint . '/images',
            ['query' => ['imageIds' => implode(',', $image_ids)]]
        );

        $this->logger->debug(
            'Azure CV image deletion headers',
            $response->getHeaders()
        );
        $this->logger->debug(
            // 'Azure CV iterations body',
            $response->getBody()
        );
    }

    /**
     * Delete a tag.
     *
     * @param string $id The tag id.
     *
     * @return void
     */
    function deleteTag(string $id)
    {
        $this->logger->debug('Deleting tag ' . $id);

        $client = $this->createClient();

        try {
            $response = $client->delete(
                $this->training_endpoint . '/tags/' . $id
            );
            $this->logger->debug(
                'Azure CV tag deletion headers',
                $response->getHeaders()
            );
            $this->logger->debug(
                // 'Azure CV iterations body',
                $response->getBody()
            );
        } catch (ClientException $e) {
            $this->logger->warning('Tag ' . $id . ' not deleted, did it exist?');
            $this->logger->debug(Psr7\str($e->getRequest()));
            $this->logger->debug(Psr7\str($e->getResponse()));
        }
    }

    /**
     * Train new iteration.
     *
     * @param boolean $force Force the training even if nothing changed.
     *
     * @return array iteration
     */
    function trainIteration($force = false)
    {
        $this->logger->debug('Training iteration, with ' . $force . ' force');

        $client = $this->createClient();

        try {
            if ($force) {
                $response = $client->post(
                    $this->training_endpoint . '/train',
                    ['query' => ['forceTrain' => 'true']]
                );
            } else {
                $response = $client->post($this->training_endpoint . '/train');
            }
            $this->logger->debug(
                'Azure CV iteration headers',
                $response->getHeaders()
            );
            $this->logger->debug(
                // 'Azure CV iterations body',
                $response->getBody()
            );

            $iteration = json_decode($response->getBody());
            return $iteration;
        } catch (ClientException $e) {
            $this->logger->warning('Tag ' . $id . ' not deleted, did it exist?');
            $this->logger->debug(Psr7\str($e->getRequest()));
            $this->logger->debug(Psr7\str($e->getResponse()));

            return false;
        }
    }

    /**
     * Train and publish a new iteration.
     *
     * @param bool $force  Force training even if nothing changed.
     * @param bool $delete Delete the previous production iteration.
     *
     * @return void
     */
    function trainAndPublishIteration($force = false, $delete = true)
    {
        $this->logger->debug('Training and publishing an iteration');
        $iteration = $this->trainIteration($force);
        if ($iteration) {
            while ($iteration->status != 'Completed') {
                $this->logger->debug('Waiting iteration ' . $iteration->id . ' for ' . $this->training_delay);
                sleep($this->training_delay);
                $iteration = $this->getIteration($iteration->id);
            }
            $this->logger->debug('Trained iteration ' . $iteration->id);

            // $current = $this->getProductionIteration();
            // $this->unpublishIteration($current);

            $this->unpublishProductionIteration($delete);
            $this->publishIteration($iteration->id, $delete);
        }

    }

    /**
     * Publish an iteration.
     *
     * @param string $iteration Iteration UUID.
     *
     * @return void
     */
    function publishIteration($iteration)
    {
        $this->logger->debug('Publishing iteration ' . $iteration);

        $client = $this->createClient();
        $client->post(
            $this->training_endpoint . '/iterations/' . $iteration . '/publish',
            ['query' => [
                'publishName' => $this->pub_model_name,
                'predictionId' => $this->pred_res_id
            ]]
        );
    }

    /**
     * Publish newest iteration.
     *
     * @return void
     */
    function publishNewestIteration()
    {
        $this->logger->debug('Publishing newest iteration');

        $newest = $this->getNewestIteration();
        $this->publishIteration($newest->id);
    }

    /**
     * Unpublish an iteration.
     *
     * @param string $iteration Iteration UUID.
     * @param bool   $delete    Delete the previous iteration.
     *
     * @return void
     */
    function unpublishIteration(string $iteration, bool $delete)
    {
        $this->logger->debug('Unpublishing iteration ' . $iteration);
        
        // unpublish it
        $client = $this->createClient();
        $client->delete($this->training_endpoint . '/iterations/' . $iteration . '/publish');
        if ($delete) {
            $this->deleteIteration($iteration);
        }
    }

    /**
     * Unpublish the current production iteration.
     *
     * @param bool $delete Delete the current production iteration.
     *
     * @return void
     */
    function unpublishProductionIteration(bool $delete)
    {
        $this->logger->debug('Unpublishing ' . $this->pub_model_name . ' iteration');
        $prod_model = $this->getProductionIteration();
        // FIXME: maybe throw an exception
        if (!$prod_model === false) {
            $this->unpublishIteration($prod_model->id, $delete);
        }
    }

    /**
     * Delete an iteration.
     *
     * @param string $id Iteration id.
     *
     * @return void
     */
    function deleteIteration(string $id)
    {
        $this->logger->debug('Deleting iteration ' . $id);

        $client = $this->createClient();
        $client->delete($this->training_endpoint . '/iterations/' . $id);
    }

    /**
     * Get iterations.
     *
     * @return array iterations
     */
    function getIterations()
    {
        $client = $this->createClient();
        $response = $client->get($this->training_endpoint . '/iterations');

        $this->logger->debug(
            'Azure CV iterations headers',
            $response->getHeaders()
        );
        $this->logger->debug(
            // 'Azure CV iterations body',
            $response->getBody()
        );

        $iterations = json_decode($response->getBody());
        
        return $iterations;
    }

    /**
     * Get the current publication iteration.
     *
     * @return array iteration.
     */
    function getProductionIteration()
    {
        $this->logger->debug('Getting the ' . $this->pub_model_name);

        $iterations = $this->getIterations();
        $idx = array_search(
            $this->pub_model_name,
            array_column($iterations, 'publishName')
        );

        if ($idx === false) {
            $this->logger->warning('No ' . $this->pub_model_name . ' active');
        } else {
            $prod_model = $iterations[$idx];
        }

        $this->logger->debug(
            $this->pub_model_name . ' model is ' . $prod_model->id
        );

        return $prod_model;
    }

    /**
     * Get iteration.
     *
     * @param string $id Iteration ID.
     *
     * @return array     Iteration.
     */
    function getIteration(string $id)
    {
        $this->logger->debug('Getting iteration ' . $id);

        $client = $this->createClient();
        $response = $client->get($this->training_endpoint . '/iterations/' . $id);

        $iteration = json_decode($response->getBody());

        return $iteration;
    }

    /**
     * Get newest iteration.
     *
     * @return array iteration.
     */
    function getNewestIteration()
    {
        $this->logger->debug('Getting the newest iteration');

        $selected = null;

        $iterations = $this->getIterations();
        // FIXME: There might be no iterations.
        // Sort them by trainedAt field, in place.
        usort(
            $data, function ($a, $b) {
                $a_dt = new DateTime($a->trainedAt);
                $b_dt = new DateTime($b->trainedAt);
                
                return $b_dt->getTimestamp() - $a_dt->getTimestamp();
            }
        );

        // Get the topmost
        $selected = $iterations[0];
        $this->logger->debug('The newest iteration ' . $selected->id . ' was trained at ' . $selected->trainedAt);
        
        return $selected;
    }

    /**
     * Get all non-procution iterations.
     *
     * @return array of iterations.
     */
    function getNonProductionIterations()
    {
        $this->logger->debug('Getting all non-production iterations');

        $iterations = $this->getIterations();
        $prod_model = $this->getProductionIteration();
        foreach ($iterations as $iteration) {
            if ($iteration->id != $prod_model->id) {
                $this->unpublishIteration($iteration->id);
            }
        }
    }

    /**
     * List the tags in this project.
     *
     * @return void
     */
    function getTags()
    {
        $client = $this->createClient();
        $response = $client->get($this->endpoint . '/customvision/v3.0/training/projects/' . $this->project_id . '/tags');

        $this->logger->debug(
            'Azure CV tag headers',
            $response->getHeaders()
        );
        $this->logger->debug(
            // 'Azure CV tag headers',
            $response->getBody()
        );
    }

    /**
     * Create control points for distortions.
     *
     * @param int $w      Image width.
     * @param int $h      Image height.
     * @param int $margin Margin.
     * @param int $r      Randomness. Between 0 and 50 maybe?
     *
     * @return array
     */
    function createControlPoints(int $w, int $h, int $margin, int $r)
    {
        $controlPoints = array(
            $margin, $margin,
            $margin * 0.1 * rand(-$r, $r), $margin * 0.1 * rand(-$r, $r),

            $margin, $h - $margin,
            $margin, $h - $margin * 0.1 * rand($r, -$r),

            $w - $margin, $margin,
            $w - $margin * 0.1 * rand(-$r, $r), $margin * 0.1 * rand(-$r, $r),

            $w - $margin, $h - $margin,
            $w - $margin * 0.1 * rand(-$r, $r), $h - $margin * 0.1 * rand(-$r, $r)
        );

        $this->logger->debug('Control points', $controlPoints);

        return $controlPoints;
    }
}
