<?php declare(strict_types=1);

namespace Drupal\multisite_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Render\Element\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\multisite_manager\Form\SiteListFilterForm;
use Drupal\multisite_manager\SiteCloningQueueManager;

/**
 * Provides a list controller for the site entity type.
 */
final class SiteListBuilder extends EntityListBuilder
{
  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array
  {
    $header["id"] = $this->t("ID");
    $header["label"] = $this->t("Label");
    $header["domain"] = $this->t("Domain");
    $header["region"] = $this->t("Region");
    $header["status"] = $this->t("Status");
    $header["http_auth"] = $this->t("Live/Sandbox");
    $header["clone_status"] = $this->t("Ready");
    $header["uid"] = $this->t("Author");
    $header["created"] = $this->t("Created");
    $header["changed"] = $this->t("Updated");
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array
  {
    /** @var \Drupal\multisite_manager\SiteInterface $entity */
    $row["id"] = $entity->id();
    $row["label"] = $entity->label();
    $domain = $entity->domain();
    $fileSystem = \Drupal::service("file_system");
    $currentState = SiteState::loadState($entity, $fileSystem);
    $canOpen = $entity->published() && $currentState->cloned;
    if ($canOpen) {
      $auth = '';
      if($entity->hasHttpAuth()) {
        $auth = "{$entity->get('http_auth_username')->value}:{$entity->get('http_auth_password')->value}@";
      }
      $target = "https://{$auth}{$domain}";
      $row["domain"]["data"] = [
        "#type" => "link",
        "#title" => $domain,
        "#url" => Url::fromUri($target),
        "#attributes" => [
          "target" => "site_" . $entity->id(),
        ],
      ];
    } else {
      $row["domain"]["data"] = $domain;
    }

    $row["region"]["data"] =
      !$entity->field_region->isEmpty() && $entity->field_region->entity
        ? $entity->field_region->entity->label()
        : null;
    // $row["status"]["class"] = ["views-field"];
    $row["status"]["data"] = [
      "#type" => "markup",
      "#markup" => $entity->get("status")->value
        ? '<span class="gin-new-flag">' . $this->t("Enabled") . "</span>"
        : '<span class="gin-status">' . $this->t("Disabled") . "</span>",
    ];
    $row["http_auth"]["data"] = [
      "#type" => "markup",
      "#markup" => $entity->hasHttpAuth()
        ? '<span class="gin-status gin-status--warning">' . $this->t("Sandbox") . "</span>"
        : '<span class="gin-new-flag">' . $this->t("Live") . "</span>",
    ];

    /** @var SiteCloningQueueManager */
    $queue_manager = \Drupal::service(
      "multisite_manager.site_cloning_queue_manager",
    );

    // @todo mark cloned / cloning / not clone and not plublished
    // $row["clone_status"]["class"] = ["views-field"];
    $row["clone_status"]["data"] = [
      "#type" => "markup",
      "#markup" => $currentState->cloned
        ? '<span class="gin-new-flag">' . $this->t("Ready") . "</span>"
        : ($currentState->published
          ? '<span class="gin-status gin-status--warning ">' .
            $this->t("Cloning") .
            "</span>"
          : '<span class="gin-status">' . $this->t("Not Cloned") . "</span>"),
    ];

    $username_options = [
      "label" => "hidden",
      "settings" => ["link" => $entity->get("uid")->entity->isAuthenticated()],
    ];
    $row["uid"]["data"] = $entity->get("uid")->view($username_options);
    $row["created"]["data"] = $entity
      ->get("created")
      ->view(["label" => "hidden"]);
    $row["changed"]["data"] = $entity
      ->get("changed")
      ->view(["label" => "hidden"]);
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $request = \Drupal::request();
    $regionParam = $request->query->get('region');
    $statusParam = $request->query->get('status');
    $httpAuthParam = $request->query->get('http_auth');

    $regionFilter = ($regionParam !== NULL && $regionParam !== '') ? (int) $regionParam : NULL;
    $statusFilter = ($statusParam !== NULL && $statusParam !== '') ? (string) $statusParam : NULL;
    $httpAuthFilter = ($httpAuthParam !== NULL && $httpAuthParam !== '') ? (string) $httpAuthParam : NULL;

    $build['filter_form'] = \Drupal::formBuilder()->getForm(
      SiteListFilterForm::class,
      $regionFilter,
      $statusFilter,
      $httpAuthFilter,
    );

    $build += parent::render();

    // Varier le cache Drupal selon les paramètres de filtre actifs.
    $build['table']['#cache']['contexts'][] = 'url.query_args:region';
    $build['table']['#cache']['contexts'][] = 'url.query_args:status';
    $build['table']['#cache']['contexts'][] = 'url.query_args:http_auth';

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface
  {
    $query = $this->getStorage()
      ->getQuery()
      ->accessCheck(true)
      ->sort($this->entityType->getKey(static::SORT_KEY));

    $currentUser = User::load(\Drupal::currentUser()->id());
    if (!$currentUser->hasPermission('bypass region restriction')) {
      $termStorage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
      $regions = [];
      foreach ($currentUser->field_region as $region) {
        $regions[] = $region->target_id;
        $child_terms = $termStorage->loadTree(
          "region",
          $region->target_id,
          null,
          false,
        );
        foreach ($child_terms as $child_term) {
          $regions[] = $child_term->tid;
        }
      }
      array_unique($regions);
      if (empty($regions)) {
        // Aucune région assignée à cet utilisateur : aucun site ne doit
        // apparaître, mais une condition IN() vide provoque une erreur
        // fatale côté base de données plutôt qu'une liste vide.
        $query = $query->condition($this->entityType->getKey('id'), -1);
      }
      else {
        $query = $query->condition("field_region", $regions, "in");
      }
    }

    // Appliquer les filtres issus des paramètres d'URL
    $request = \Drupal::request();

    $regionParam = $request->query->get('region');
    if ($regionParam !== NULL && $regionParam !== '') {
      $filterTid = (int) $regionParam;
      $termStorage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
      $regionTids = [$filterTid];
      foreach ($termStorage->loadTree("region", $filterTid, null, false) as $child) {
        $regionTids[] = $child->tid;
      }
      $query->condition("field_region", $regionTids, "in");
    }

    $statusParam = $request->query->get('status');
    if ($statusParam !== NULL && $statusParam !== '') {
      $query->condition("status", (int) $statusParam);
    }

    $httpAuthParam = $request->query->get('http_auth');
    if ($httpAuthParam !== NULL && $httpAuthParam !== '') {
      $query->condition("http_auth_enabled", (int) $httpAuthParam);
    }

    // Only add the pager if a limit is specified.
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query;
  }

}
