<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Controller;

use Drupal\ad_inventory\AdMailProviderManager;
use Drupal\ad_inventory\EmailServer;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\ad_inventory\Inventory;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Email slots: the public image and click, and the snippets for editors.
 */
final class EmailController extends ControllerBase {

  public function __construct(
    private readonly EmailServer $server,
    private readonly Inventory $inventory,
    private readonly AdMailProviderManager $mailProviders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ad_inventory.email'),
      $container->get('ad_inventory.inventory'),
      $container->get('plugin.manager.ad_mail_provider'),
    );
  }

  /**
   * GET /ad/e/{slot}.png.
   */
  public function image(string $image): CacheableResponse {
    return $this->server->image($this->emailSlot(substr($image, 0, -4)));
  }

  /**
   * GET /ad/e/{slot}/go.
   */
  public function go(string $slot): TrustedRedirectResponse {
    return $this->server->go($this->emailSlot($slot));
  }

  /**
   * The snippets of an email slot, one per mail provider plugin.
   */
  public function snippets(AdSlotInterface $ad_slot): array {
    $build = ['#cache' => ['tags' => $ad_slot->getCacheTags()]];
    if ($ad_slot->channel() !== 'email') {
      $build['web'] = ['#markup' => '<p>' . $this->t('Only email slots have snippets; a web slot is placed as a block or printed by a template.') . '</p>'];
      return $build;
    }
    $format = $this->inventory->format($ad_slot->desktopFormat());
    $label = (string) $this->config('ad_inventory.settings')->get('label');
    $alt = (string) $this->config('ad_inventory.settings')->get('email_alt') ?: $label;
    $slot = [
      'slot' => (string) $ad_slot->id(),
      'label' => $label,
      'image' => Url::fromRoute('ad_inventory.email_image', ['image' => $ad_slot->id() . '.png'], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'go' => Url::fromRoute('ad_inventory.email_go', ['slot' => $ad_slot->id()], ['absolute' => TRUE])->toString(TRUE)->getGeneratedUrl(),
      'width' => $format ? $format->width() : 0,
      'height' => $format ? $format->height() : 0,
      'alt' => $alt,
    ];
    $build['intro'] = ['#markup' => '<p>' . $this->t('The image shows the creative in force on the day a mail is opened (booking, else house). Clicks on the link are counted. The mail module sends nothing.') . '</p>'];
    foreach ($this->mailProviders->getDefinitions() as $id => $definition) {
      $snippet = $this->mailProviders->createInstance($id)->snippet($slot);
      $build[$id] = [
        '#type' => 'details',
        '#title' => $definition['label'],
        '#open' => TRUE,
        'help' => ['#markup' => '<p>' . $snippet['help'] . '</p>'],
        'code' => [
          '#type' => 'textarea',
          '#value' => $snippet['code'],
          '#rows' => 4,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ];
    }
    return $build;
  }

  /**
   * An email slot by id, or a 404.
   */
  private function emailSlot(string $id): AdSlotInterface {
    $slot = $this->inventory->slot($id);
    if ($slot === NULL || $slot->channel() !== 'email') {
      throw new NotFoundHttpException();
    }
    return $slot;
  }

}
