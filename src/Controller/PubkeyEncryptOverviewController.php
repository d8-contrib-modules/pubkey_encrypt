<?php

namespace Drupal\pubkey_encrypt\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders overview page for Pubkey Encrypt.
 */
class PubkeyEncryptOverviewController extends ControllerBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config object factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Creates a new PubkeyEncryptOverviewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * Renders overview of Encryption Profiles generated by Pubkey Encrypt.
   */
  public function overview() {
    $build['table'] = array(
      '#type' => 'table',
      '#header' => [
        'role' => $this->t('Role'),
        'encryption_profile' => $this->t('Encryption profile'),
        'ready-to-use' => $this->t('Ready to use'),
        'message' => $this->t('Message'),
      ],
      '#title' => 'Overview of Encryption Profiles generated by Pubkey Encrypt',
      '#rows' => array(),
      '#empty' => $this->t('There are no Encryption Profiles generated by Pubkey Encrypt. Make sure that the module has been initialized.'),
    );

    // The roles enabled from Pubkey Encrypt settings.
    $admin_settings = $this->configFactory
      ->get('pubkey_encrypt.admin_settings');
    $enabled_roles = $admin_settings->get('enabled_roles');

    // Pubkey Encrypt generates one Encryption Profile per role.
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $role) {
      $encryption_profile = $this->entityTypeManager
        ->getStorage('encryption_profile')
        ->load($role->id() . '_role_key_encryption_profile');

      if ($encryption_profile) {
        $row['role'] = $role->label();
        $row['encryption_profile'] = $encryption_profile->label();

        // Ensure that the corresponding role has been enabled in Pubkey Encrypt
        // settings.
        if (!in_array($role->id(), $enabled_roles)) {
          $row['ready-to-use'] = $this->t('No');
          $row['message'] = $this->t('The corresponding role is disabled from Pubkey Encrypt settings.');
        }
        else {
          // Count the number of users for this role who have yet to perform the
          // one-time login.
          $count = 0;
          $users = $this->entityTypeManager->getStorage('user')
            ->loadByProperties(['roles' => $role->id()]);
          foreach ($users as $user) {
            if ($user->get('field_private_key_protected')->getString() == "0") {
              $count++;
            }
          }

          // Render status and message for the Encryption Profiler as per the
          // count.
          if ($count > 1) {
            $row['ready-to-use'] = $this->t('No');
            $row['message'] = $this->t('@count users from the corresponding role have yet to perform the one-time login.', array('@count' => $count));
          }
          elseif ($count == 1) {
            $row['ready-to-use'] = $this->t('No');
            $row['message'] = $this->t('1 user from the corresponding role has yet to perform the one-time login.');
          }
          else {
            $row['ready-to-use'] = $this->t('Yes');
            $row['message'] = $this->t('None.');
          }
        }

        $build['table']['#rows'][] = $row;
      }
    }

    return $build;
  }

}