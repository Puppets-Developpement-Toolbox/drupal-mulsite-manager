<?php declare(strict_types=1);

namespace Drupal\multisite_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Formulaire de filtres pour la liste des sites (/admin/content/site).
 */
final class SiteListFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'multisite_manager_site_list_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $region TID de la région actuellement filtrée, ou NULL.
   * @param string|null $status Valeur du filtre statut ('1', '0') ou NULL.
   * @param string|null $http_auth Valeur du filtre protection HTTP ('1', '0') ou NULL.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?int $region = NULL,
    ?string $status = NULL,
    ?string $http_auth = NULL,
  ): array {
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter sites'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['filters']['region'] = [
      '#type' => 'select',
      '#title' => $this->t('Region'),
      '#options' => $this->getRegionOptions(),
      '#default_value' => $region ?? '',
    ];

    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        '' => $this->t('- Any status -'),
        '1' => $this->t('Enabled'),
        '0' => $this->t('Disabled'),
      ],
      '#default_value' => $status ?? '',
    ];

    $form['filters']['http_auth'] = [
      '#type' => 'select',
      '#title' => $this->t('Live/Sandbox'),
      '#options' => [
        '' => $this->t('- Any protection -'),
        '1' => $this->t('Sandbox'),
        '0' => $this->t('Live'),
      ],
      '#default_value' => $http_auth ?? '',
    ];

    $form['filters']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Filter'),
    ];

    // Afficher le bouton Reset uniquement si un filtre est actif.
    if ($region !== NULL || $status !== NULL || $http_auth !== NULL) {
      $form['filters']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#submit' => ['::resetForm'],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];

    $region = $form_state->getValue('region');
    if ($region !== '' && $region !== NULL) {
      $query['region'] = $region;
    }

    $status = $form_state->getValue('status');
    if ($status !== '' && $status !== NULL) {
      $query['status'] = $status;
    }

    $http_auth = $form_state->getValue('http_auth');
    if ($http_auth !== '' && $http_auth !== NULL) {
      $query['http_auth'] = $http_auth;
    }

    $form_state->setRedirect(
      'entity.multisite_manager_site.collection',
      [],
      ['query' => $query],
    );
  }

  /**
   * Réinitialise les filtres en redirigeant sans paramètres.
   */
  public function resetForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('entity.multisite_manager_site.collection');
  }

  /**
   * Construit les options du select Région selon les droits de l'utilisateur.
   *
   * Les utilisateurs avec la permission "bypass region restriction" (ex:
   * rôles marqués comme administrateur du site) voient toute la hiérarchie.
   * Les autres utilisateurs voient uniquement leurs régions assignées et
   * leurs enfants, ce qui est cohérent avec la restriction dans
   * SiteListBuilder::getEntityListQuery().
   *
   * @return array<string|int, string|\Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  private function getRegionOptions(): array {
    $options = ['' => $this->t('- All regions -')];

    $currentUser = User::load(\Drupal::currentUser()->id());
    $termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    if ($currentUser->hasPermission('bypass region restriction')) {
      // Charger toute la hiérarchie avec indentation visuelle.
      $terms = $termStorage->loadTree('region', 0, NULL, TRUE);
      foreach ($terms as $term) {
        $prefix = $term->depth > 0 ? str_repeat('– ', (int) $term->depth) : '';
        $options[$term->id()] = $prefix . $term->label();
      }
    }
    else {
      // Exposer uniquement les régions accessibles à l'utilisateur.
      foreach ($currentUser->field_region as $regionRef) {
        $tid = $regionRef->target_id;
        $term = $termStorage->load($tid);
        if ($term) {
          $options[$tid] = $term->label();
        }
        $children = $termStorage->loadTree('region', $tid, NULL, TRUE);
        foreach ($children as $child) {
          $prefix = str_repeat('– ', (int) $child->depth ?: 1);
          $options[$child->id()] = $prefix . $child->label();
        }
      }
    }

    return $options;
  }

}
