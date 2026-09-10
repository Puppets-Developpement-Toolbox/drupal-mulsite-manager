<?php declare(strict_types=1);

namespace Drupal\multisite_manager;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Render\Element\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
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
    $header["status"] = $this->t("Status");
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

    // $row["status"]["class"] = ["views-field"];
    $row["status"]["data"] = [
      "#type" => "markup",
      "#markup" => $entity->get("status")->value
        ? '<span class="gin-new-flag">' . $this->t("Enabled") . "</span>"
        : '<span class="gin-status">' . $this->t("Disabled") . "</span>",
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

}
