<?php declare(strict_types=1);

namespace Drupal\multisite_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;

/**
 * Form controller for the site entity edit forms.
 */
final class SiteForm extends ContentEntityForm
{
  public function form(array $form, FormStateInterface $form_state)
  {
    $form = parent::form($form, $form_state);

    $form["http_auth"] = [
      "#type" => "fieldset",
      "#title" => $this->t("HTTP Authentication"),
      "#collapsible" => true,
      "#collapsed" => true,
      "#weight" => 11,
    ];
    $form["http_auth_enabled"]["#group"] = "http_auth";
    $form["http_auth_username"]["#group"] = "http_auth";
    $form["http_auth_password"]["#group"] = "http_auth";
    $ifCheckedState = [
      "visible" => [
        ':input[name="http_auth_enabled[value]"]' => [
          "checked" => true,
        ],
      ],
    ];
    $form["http_auth_username"]["#states"] = $ifCheckedState;
    $form["http_auth_password"]["#states"] = $ifCheckedState;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int
  {
    $result = parent::save($form, $form_state);

    $message_args = ["%label" => $this->entity->toLink()->toString()];
    $logger_args = [
      "%label" => $this->entity->label(),
      "link" => $this->entity->toLink($this->t("View"))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus(
          $this->t("New site %label has been created.", $message_args),
        );
        $this->logger("multisite_manager")->notice(
          "New site %label has been created.",
          $logger_args,
        );
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus(
          $this->t("The site %label has been updated.", $message_args),
        );
        $this->logger("multisite_manager")->notice(
          "The site %label has been updated.",
          $logger_args,
        );
        break;

      default:
        throw new \LogicException("Could not save the entity.");
    }

    // Site cloning is now handled asynchronously via queue system
    // The queue is triggered in SiteStorage::save() when a site is published

    return $result;
  }
}
