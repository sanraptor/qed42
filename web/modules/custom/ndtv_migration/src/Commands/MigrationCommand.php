<?php

namespace Drupal\ndtv_migration\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\NodeInterface;
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
   * A Database instance.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a Drupal\ndtv_migration\Commands\MigrationCommand object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   A entity manager instance.
   * @param \Drupal\Core\Database\Connection $connection
   *   A database instance.
   */
  public function __construct(
    EntityTypeManager $entityTypeManager,
    Connection $connection
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->connection = $connection;
  }

  /**
   * XML migration.
   *
   * @param string $url
   *   The xml url.
   *
   * @command xml:migration
   * @alias xml-migration
   */
  public function migration($url) {
    $xml = simplexml_load_file($url);
    $i = $j = 0;
    foreach ($xml->channel->item as $value) {
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
      $node_data = $this->checkExistingContent($value->title->__toString());
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
      if ($node_data instanceof NodeInterface) {
        // Update Node.
      }
      else {
        $node = $this->entityTypeManager->getStorage('node');
        $nodeDetails = $node->create($nodeObject);
        $nodeDetails->save();
        $j++;
      }
      $title_array[$i] = $value->title->__toString();
      $i++;
    }
    $delete_count = 0;
    if (!empty($title_array)) {
      $delete_count = $this->deleteExistingPost($title_array);
    }
    $this->output()->writeln($j . ' migration completed out of ' . count($xml->channel->item) . ' and ' . $delete_count . ' existing nodes deleted.');
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

  /**
   * Check content exists or not.
   */
  public function checkExistingContent($title) {
    $node = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'article',
      'title' => $title,
    ]);
    if (empty($node)) {
      return FALSE;
    }
    else {
      return reset($node);
    }
  }

  /**
   * Delete existing post.
   */
  public function deleteExistingPost($title) {
    $query = $this->connection->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid']);
    $query->condition('nfd.type', 'article');
    $query->condition('nfd.title', $title, 'NOT IN');
    $res = $query->execute()->fetchAllKeyed(0, 0);
    if (!empty($res)) {
      foreach ($res as $node_id) {
        $node = $this->entityTypeManager->getStorage('node')->load($node_id);
        $node->delete();
      }
    }
    return count($res);
  }

}
