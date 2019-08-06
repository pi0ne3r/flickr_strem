<?php

namespace Drupal\flickr_stream;

use GuzzleHttp\Exception\ClientException;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Class FlickrStreamApi.
 *
 * @package Drupal\FlickrStreamApi
 */
class FlickrStreamApi implements ContainerInjectionInterface {

  /**
   * Flickr API services endpoint.
   */
  const FLICKR_API_URL = 'https://api.flickr.com/services/rest/';

  /**
   * Flickr module config.
   *
   * @var array
   */
  protected $flickrConf;
  /**
   * Plugin configuration.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Used for logging errors.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * FlickrStreamApi constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Plugin configuration.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   Used for logging errors.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger) {
    $this->configFactory = $config_factory;
    $this->client = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.factory')
    );
  }


  /**
   * Returns generic default configuration for flickr api.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    $flickConfig = $this->configFactory->get('flickr_stream.settings');
    return [
      'api_key' => $flickConfig->get('flickr_stream_api_key'),
      'default_count' => $flickConfig->get('flickr_stream_photo_count'),
      'uri' => Url::fromUri(self::FLICKR_API_URL)->toUriString(),
    ];
  }

  /**
   * FlickrStreamApi set configurations.
   *
   * @param string $userId
   *   Flickr user Id.
   * @param string $photosetId
   *   Flickr album Id.
   * @param string $photoCount
   *   Flickr photo count.
   *
   * @return array
   *   Define conf array.
   */
  public function setConfig($userId, $photosetId, $photoCount = NULL) {
    $defaultConf = $this->baseConfigurationDefaults();
    $photoCount = ($photoCount) ?: $defaultConf['default_count'];
    $this->flickrConf = NestedArray::mergeDeep(
      $defaultConf,
      [
        'photoset_id' => $photosetId,
        'user_id' => $userId,
        'default_count' => $photoCount,
      ]
    );
    return $this->flickrConf;
  }

  /**
   * Generate photo uri from flickr api result.
   *
   * @param array $flickr_photo
   *   Flickr api result array.
   *
   * @return string
   *   Uri to image in flickr.
   */
  public function generatePhotoUri(array $flickr_photo) {
    return 'https://farm' . $flickr_photo['farm'] .
      '.staticflickr.com/' . $flickr_photo['server'] .
      '/' . $flickr_photo['id'] .
      '_' . $flickr_photo['secret'] . '_b.jpg';
  }

  /**
   * Helper function to build images markup.
   *
   * @param array $flickrImages
   *   Flickr images array fron api.
   * @param string $apiType
   *   Api type to build images.
   * @param array $image_style
   *   Images output style.
   *
   * @return array
   *   Build images render html.
   */
  public function flickrBuildImages(array $flickrImages, $apiType, array $image_style) {
    $build = [];
    $list = [];
    // Detect flickr images type.
    $flickr_array = ($apiType == 'album') ? $flickrImages['photoset']['photo'] : $flickrImages['photos']['photo'];
    foreach ($flickr_array as $index => $flickr_photo) {
      switch ($image_style['flickr_images_style']) {
        case 'default':
          $list[] = [
            '#theme' => 'flickr_image',
            '#uri' => imagecache_external_generate_path($this::generatePhotoUri($flickr_photo)),
            '#alt' => $flickr_photo['title'],
          ];
          break;

        default:
          $style = ImageStyle::load($image_style['flickr_images_style']);
          $generated_uri = imagecache_external_generate_path($this::generatePhotoUri($flickr_photo));
          $styled_uri = $style->buildUrl($generated_uri);
          $list[] = [
            '#theme' => 'flickr_image',
            '#uri' => $styled_uri,
            '#alt' => $flickr_photo['title'],
          ];
      }
    }

    $build[] = [
      '#theme' => 'item_list',
      '#items' => $list,
      '#cache' => [
        'contexts' => ['session'],
        'tags' => [],
        'max-age' => Cache::PERMANENT,
      ],
      '#list_type' => 'ul',
      '#attributes' => ['class' => 'flickr-image-list'],
    ];
    return $build;
  }

  /**
   * Get photos from album flickr APIs.
   *
   * @param array $conf
   *   FlickrStreamApi configurations.
   *
   * @return array
   *   Flickr API result.
   */
  public function getAlbumPhotos(array $conf) {
    $flickr_results = [];
    try {
      $request = $this->client->get($conf['uri'], [
        'query' => [
          'method' => 'flickr.photosets.getPhotos',
          'api_key' => $conf['api_key'],
          'photoset_id' => $conf['photoset_id'],
          'user_id' => $conf['user_id'],
          'format' => 'json',
          'nojsoncallback' => 1,
          'per_page' => $conf['default_count'],
        ],
      ]);
      $response = $request->getBody();
      $flickr_results = json_decode($response->read($response->getSize()), TRUE);
      if ($flickr_results['stat'] == 'fail') {
        $this->logger->get('flickr_stream')->notice('Flickr api get @errorId error with message: @errorMessage', [
          '@errorId' => $flickr_results['stat'],
          '@errorMessage' => $flickr_results['message'],
        ]);
      }
    }
    catch (ClientException $exception) {
      $this->logger->get('flickr_stream')->notice($exception);
      $this->logger->get('flickr_stream')->alert('Please check flickrs credentials and flickr fields inputs. Go to logs for more information');
    }
    return $flickr_results;
  }

  /**
   * Get photos from users flickr APIs.
   *
   * @param array $conf
   *   FlickrStreamApi configurations.
   *
   * @return array
   *   Flickr API result.
   */
  public function getUserPhotos(array $conf) {
    $flickr_results = [];
    try {
      $request = $this->client->get($conf['uri'], [
        'query' => [
          'method' => 'flickr.people.getPublicPhotos',
          'api_key' => $conf['api_key'],
          'user_id' => $conf['user_id'],
          'format' => 'json',
          'nojsoncallback' => 1,
          'per_page' => $conf['default_count'],
        ],
      ]);
      $response = $request->getBody();
      $flickr_results = json_decode($response->read($response->getSize()), TRUE);
      if ($flickr_results['stat'] == 'fail') {
        $this->logger->get('flickr_stream')->notice('Flickr api get @errorId error with message: @errorMessage', [
          '@errorId' => $flickr_results['stat'],
          '@errorMessage' => $flickr_results['message'],
        ]);
      }
    }
    catch (ClientException $exception) {
      $this->logger->get('flickr_stream')->notice($exception);
      $this->logger->get('flickr_stream')->alert('Please check flickrs credentials and flickr fields inputs. Go to logs for more information');
    }
    return $flickr_results;
  }

}
