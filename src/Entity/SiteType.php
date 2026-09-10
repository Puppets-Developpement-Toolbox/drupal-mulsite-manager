<?php declare(strict_types = 1);

namespace Drupal\multisite_manager\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the Site type configuration entity.
 *
 * @ConfigEntityType(
 *   id = "multisite_manager_site_type",
 *   label = @Translation("Site type"),
 *   label_collection = @Translation("Site types"),
 *   label_singular = @Translation("site type"),
 *   label_plural = @Translation("sites types"),
 *   label_count = @PluralTranslation(
 *     singular = "@count sites type",
 *     plural = "@count sites types",
 *   ),
 *   handlers = {
 *     "form" = {
 *       "add" = "Drupal\multisite_manager\Form\SiteTypeForm",
 *       "edit" = "Drupal\multisite_manager\Form\SiteTypeForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "list_builder" = "Drupal\multisite_manager\SiteTypeListBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer multisite_manager_site types",
 *   bundle_of = "multisite_manager_site",
 *   config_prefix = "multisite_manager_site_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "add-form" = "/admin/structure/multisite_manager_site_types/add",
 *     "edit-form" = "/admin/structure/multisite_manager_site_types/manage/{multisite_manager_site_type}",
 *     "delete-form" = "/admin/structure/multisite_manager_site_types/manage/{multisite_manager_site_type}/delete",
 *     "collection" = "/admin/structure/multisite_manager_site_types",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *   },
 * )
 */
final class SiteType extends ConfigEntityBundleBase {

  /**
   * The machine name of this site type.
   */
  protected string $id;

  /**
   * The human-readable name of the site type.
   */
  protected string $label;

}
