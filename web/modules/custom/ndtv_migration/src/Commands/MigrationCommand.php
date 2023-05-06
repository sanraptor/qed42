<?php

namespace Drupal\ndtv_migration\Commands;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;

/**
 * Create class for migration command.
 */
class MigrationCommand extends DrushCommands {

  /**
   * A EntityTypeManager instance.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * A DateFormatter instance.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * A FileSystem instance.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a Drupal\ndtv_migration\Commands\MigrationCommand object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   A entity manager instance.
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   *   A date formatter instance.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   A file system instance.
   */
  public function __construct(
    EntityTypeManager $entityTypeManager,
    DateFormatter $dateFormatter,
    FileSystemInterface $fileSystem,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
    $this->fileSystem = $fileSystem;
  }

  /**
   * XML migration.
   *
   * @command xml:migration
   * @alias xml-migration
   *
   * @param string $url
   *   The xml url.
   */
  public function migration($url) {
    $xml = simplexml_load_file($url);
    foreach ($xml->channel->item as $key => $value) {
      $date = new \DateTime($value->pubDate);
      $pubDate = $date->format('U');
      $image_url = $value->children("media", TRUE)->content->attributes()['url']->__toString();
      $file = system_retrieve_file($image_url, "public://articles", TRUE, FileSystemInterface::EXISTS_REPLACE);
      $source_data = explode('/', $url);
      unset($source_data[0]);
      unset($source_data[1]);
      $source = $this->addUpdateTerm(array_shift($source_data), 'tags');
      $category = $this->addUpdateTerm(array_pop($source_data), 'tags');
      $combined = array_merge($source, $category);
      $nodeObject = [
        "type" => 'article',
        "status" => 1,
        "uid" => 1,
        "title" => $value->title,
        "field_guid" => $value->guid,
        "field_link" => $value->link,
        "created" => $pubDate,
        "changed" => $pubDate,
        "body" => [
          "summary" => $value->description,
          "value" => $value->children("content", TRUE)->encoded,
          "format" => "full_html",
        ],
        "field_image" => $file,
        "field_tags" => $combined,
      ];
      $node = $this->entityTypeManager->getStorage('node');
      $nodeDetails = $node->create($nodeObject);
      $nodeDetails->save();
      $title_array[$key] = $value->title;
    }
    $this->output()->writeln(array_shift($source_data));
  }

  /**
   * Function to check and add term.
   */
  public function addUpdateTerm($source_data, $vid) {
    $properties = [
      'name' => $source_data,
      'vid' => $vid,
    ];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);
    if (!empty($term)) {
      $source[] = $term->id();
    }
    else {
      $new_term = Term::create([
        'vid' => $vid,
        'name' => $source_data,
      ]);
      $new_term->save();
      $source[] = $new_term->id();
    }
    return $source;
  }

}
