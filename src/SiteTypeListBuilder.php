<?php declare(strict_types = 1);

namespace Drupal\multisite_manager;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of site type entities.
 *
 * @see \Drupal\multisite_manager\Entity\SiteType
 */
final class SiteTypeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['label'] = $entity->label();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();

    $build['table']['#empty'] = $this->t(
      'No site types available. <a href=":link">Add site type</a>.',
      [':link' => Url::fromRoute('entity.multisite_manager_site_type.add_form')->toString()],
    );

    return $build;
  }

}
