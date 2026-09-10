<?php declare(strict_types = 1);

namespace Drupal\multisite_manager;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a site entity type.
 */
interface SiteInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {
  public function domain():string;

  public function published():bool;

  public function hasHttpAuth():bool;

}
