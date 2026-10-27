<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdCreativeInterface;
use Drupal\ad_inventory\Inventory;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a creative; names any rendition that is off its format.
 */
final class AdCreativeForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected Inventory $inventory,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('ad_inventory.inventory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($entity instanceof AdCreativeInterface) {
      $seen = [];
      foreach ($entity->get('renditions') as $delta => $item) {
        $format = (string) $item->format;
        if ($item->target_id && $format === '') {
          $form_state->setErrorByName('renditions][' . $delta . '][format', $this->t('Choose the format of each rendition.'));
        }
        if ($format !== '' && isset($seen[$format])) {
          $form_state->setErrorByName('renditions][' . $delta . '][format', $this->t('One rendition per format.'));
        }
        $seen[$format] = TRUE;
      }
      foreach ($this->inventory->renditionProblems($entity, $entity->renditionFormats()) as $problem) {
        $form_state->setErrorByName('renditions', $problem);
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the creative %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
