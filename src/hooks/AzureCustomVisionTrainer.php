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
use \GuzzleHttp\Client;

class AzureCustomVisionTrainer
{
    /**
     * Class constructor.
     */
    function __construct($endpoint, $project_id, $train_key, $pred_res_id, $pub_model_name)
    {
        $container = Application::getInstance()->getContainer();
        $this->logger = $container->get('logger');

        $this->endpoint = $endpoint;
        $this->project_id = $project_id;
        $this->train_key = $train_key;
        $this->pred_res_id = $pred_res_id;
        $this->pub_model_name = $pub_model_name;

        $this->training_endpoint = $this->endpoint . '/customvision/v3.0/training/projects/' . $this->project_id;        
        $this->training_delay = 5;
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
     * @param array $image Image data.
     * @param array $data  Artwork data.
     *
     * @return void
     */
    function doTheProductiveThings(array $image, array $data)
    {
        $this->createImageFromImageData($image, $data);
        $this->trainAndPublishIteration($force = true);
    }

    /**
     * Create an image on Azure Custom Vision.
     *
     * @param array $image   List of image urls.
     * @param array $artwork Artwork description.
     *
     * @return void
     */
    function createImageFromImageData(array $image, array $artwork)
    {
        $this->logger->debug('Create with image data ', $image);
        $this->logger->debug('Create with artwork data', $artwork);

        $this->logger->debug('First making a tag for artwork.', $artwork);
        $tagname = $artwork['artist_name'] . ': ' . $artwork['title'];
        $tag = $this->createTag($tagname);

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
            // FIXME: this needs to actually come from data.
            //'tagIds' => ['2cab4fc8-1e0c-4fbc-98f7-ac2332878738']
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
        if ($force) {
            $response = $client->post(
                $this->training_endpoint . '/train',
                ['query' => ['forceTrain' => 'true']]
            );
        } else {
            $response = $client->post($this->training_endpoint . '/train');
        }

        if ($response->getStatusCode() == 200) {
            $iteration = json_decode($response->getBody());
            return $iteration;
        } else {
            // Something weird happened with training.
            $this->logger->warning('Training error', json_decode($response->getBody()));
            return false;
        }
    }

    /**
     * Train and publish a new iteration.
     *
     * @param boolean $force Force training even if nothing changed.
     *
     * @return void
     */
    function trainAndPublishIteration($force = false)
    {
        $this->logger->debug('Training and publishing an iteration');
        $iteration = $this->trainIteration($force);
        sleep($this->training_delay); // FIXME: Lulz

        $this->publishIteration($iteration->id);
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
     *
     * @return void
     */
    function unpublishIteration($iteration)
    {
        $this->logger->debug('Unpublishing iteration ' . $iteration);
        
        // unpublish it
        $client = $this->createClient();
        $client->delete($this->training_endpoint . '/iterations/' . $iteration . '/publish');
    }

    /**
     * Unpublish the current production iteration.
     *
     * @return void
     */
    function unpublishProductionIteration()
    {
        $this->logger->debug('Unpublishing ' . $this->pub_model_name . ' iteration');
        $prod_model = $this->getProductionIteration();
        // FIXME: maybe throw an exception
        if (!$prod_model === false) {
            $this->unpublishIteration($prod_model->id);
        }
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
            'production',
            array_column($iterations, 'publishName')
        );

        if ($idx === false) {
            $this->logger->warning('No ' . $this->pub_model_name . ' active');
        } else {
            $prod_model = $iteration[$idx];
        }

        $this->logger->debug(
            $this->pub_model_name . ' model is ' . $prod_model->id
        );

        return $prod_model;
    }

    /**
     * Get newest iteration.
     *
     * @return void
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
}
