<?php

/**
 * @file
 * Contains \Drupal\views_bulk_operations\Plugin\views\field\
.
 */

namespace Drupal\views_bulk_operations\Plugin\views\field;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\user\PrivateTempStoreFactory;
use Drupal\views\Entity\Render\EntityTranslationRenderTrait;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a actions-based bulk operation form element.
 *
 * @ViewsField("vbo_bulk_form")
 */
class BulkForm extends FieldPluginBase implements CacheableDependencyInterface {

  use RedirectDestinationTrait;
  use UncacheableFieldHandlerTrait;
  use EntityTranslationRenderTrait;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The action storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $actionStorage;

  /**
   * An array of actions that can be executed.
   *
   * @var \Drupal\system\ActionConfigEntityInterface[]
   */
  protected $actions = array();

  /**
   * An array of configurable actions that can be executed.
   *
   * @var \Drupal\system\ConfigurableActionBase[]
   */
  protected $configurable_actions = array();

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new BulkForm object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Action\ActionManager $action_manager
   *   The action plugin manager.
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityManagerInterface $entity_manager, LanguageManagerInterface $language_manager, ActionManager $action_manager, PrivateTempStoreFactory $temp_store_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->actionStorage = $entity_manager->getStorage('action');
    $this->languageManager = $language_manager;
    $this->actionManager = $action_manager;
    $this->temp_store_factory = $temp_store_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.manager'),
      $container->get('language_manager'),
      $container->get('plugin.manager.action'),
      $container->get('user.private_tempstore')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);

    $entity_type = $this->getEntityType();
    // Filter the actions to only include those for this entity type.
    $this->actions = array_filter($this->actionStorage->loadMultiple(), function ($action) use ($entity_type) {
      return $action->getType() == $entity_type;
    });

    foreach ($this->actionManager->getDefinitions() as $id => $definition) {
      if (is_subclass_of($definition['class'], '\Drupal\Core\Plugin\PluginFormInterface') && $definition['type'] == $this->getEntityType()) {
        $this->configurable_actions[$id] = $definition;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
   return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->languageManager->isMultilingual() ? $this->getEntityTranslationRenderer()->getCacheContexts() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->getEntityType();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityManager() {
    return $this->entityManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getLanguageManager() {
    return $this->languageManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getView() {
    return $this->view;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['action_title'] = array('default' => $this->t('With selection'));
    $options['include_exclude'] = array(
      'default' => 'exclude',
    );
    $options['selected_actions'] = array(
      'default' => array(),
    );
    $options['batching'] = array(
      'default' => FALSE,
    );
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['action_title'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Action title'),
      '#default_value' => $this->options['action_title'],
      '#description' => $this->t('The title shown above the actions dropdown.'),
    );

    $form['include_exclude'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Available actions'),
      '#options' => array(
        'exclude' => $this->t('All actions, except selected'),
        'include' => $this->t('Only selected actions'),
      ),
      '#default_value' => $this->options['include_exclude'],
    );
    $form['selected_actions'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Selected actions'),
      '#options' => $this->getBulkOptions(FALSE),
      '#default_value' => $this->options['selected_actions'],
    );

    $form['batching'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Batching'),
      '#options' => array('use_batching' => $this->t('Use batching')),
      '#default_value' => $this->options['batching'],
    );

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    parent::validateOptionsForm($form, $form_state);

    $selected_actions = $form_state->getValue(array('options', 'selected_actions'));
    $form_state->setValue(array('options', 'selected_actions'), array_values(array_filter($selected_actions)));

    $selected_batching = $form_state->getValue(array('options', 'batching'));
    $form_state->setValue(array('options', 'batching'), array_values(array_filter($selected_batching)));
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    parent::preRender($values);

    // If the view is using a table style, provide a placeholder for a
    // "select all" checkbox.
    if (!empty($this->view->style_plugin) && $this->view->style_plugin instanceof Table) {
      // Add the tableselect css classes.
      $this->options['element_label_class'] .= 'select-all';
      // Hide the actual label of the field on the table header.
      $this->options['label'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $row->index . '-->';
  }

  /**
   * Form constructor for the bulk form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function viewsForm(&$form, FormStateInterface $form_state) {
    // Make sure we do not accidentally cache this form.
    // @todo Evaluate this again in https://www.drupal.org/node/2503009.
    $form['#cache']['max-age'] = 0;

    // Add the tableselect javascript.
    $form['#attached']['library'][] = 'core/drupal.tableselect';
    $use_revision = array_key_exists('revision', $this->view->getQuery()->getEntityTableInfo());

    // Only add the bulk form options and buttons if there are results.
    if (!empty($this->view->result)) {
      // Render checkboxes for all rows.
      $form[$this->options['id']]['#tree'] = TRUE;
      foreach ($this->view->result as $row_index => $row) {
        $entity = $this->getEntityTranslation($this->getEntity($row), $row);

        $form[$this->options['id']][$row_index] = array(
          '#type' => 'checkbox',
          // We are not able to determine a main "title" for each row, so we can
          // only output a generic label.
          '#title' => $this->t('Update this item'),
          '#title_display' => 'invisible',
          '#default_value' => !empty($form_state->getValue($this->options['id'])[$row_index]) ? 1 : NULL,
          '#return_value' => $this->calculateEntityBulkFormKey($entity, $use_revision),
        );
      }

      // Replace the form submit button label.
      $form['actions']['submit']['#value'] = $this->t('Apply');

      // Ensure a consistent container for filters/operations in the view header.
      $form['header'] = array(
        '#type' => 'container',
        '#weight' => -100,
      );

      // Build the bulk operations action widget for the header.
      // Allow themes to apply .container-inline on this separate container.
      $form['header'][$this->options['id']] = array(
        '#type' => 'container',
      );
      $form['header'][$this->options['id']]['action'] = array(
        '#type' => 'select',
        '#title' => $this->options['action_title'],
        '#options' => $this->getBulkOptions(),
      );

      // Duplicate the form actions into the action container in the header.
      $form['header'][$this->options['id']]['actions'] = $form['actions'];
    }
    else {
      // Remove the default actions build array.
      unset($form['actions']);
    }

    $enable_all_pages = FALSE;

    /* TODO: Make batch on all results work and enable the checkbox.*/
    $pager = $this->view->getPager();
    if ($pager && $pager->getTotalItems() > $pager->getItemsPerPage()) {
      $enable_all_pages = true;
    }

    // $form['header'] might be empty e.g. for empty results.
    if (is_array($form['header'])) {
      $form['header'] += $this->selectAllForm($enable_all_pages);
    }
  }

  /**
   * Returns the available operations for this form.
   *
   * @param bool $filtered
   *   (optional) Whether to filter actions to selected actions.
   * @return array
   *   An associative array of operations, suitable for a select element.
   */
  protected function getBulkOptions($filtered = TRUE) {
    $options = array();
    // Filter the action list.
    foreach ($this->actions as $id => $action) {
      if ($filtered) {
        $in_selected = in_array($id, $this->options['selected_actions']);
        // If the field is configured to include only the selected actions,
        // skip actions that were not selected.
        if (($this->options['include_exclude'] == 'include') && !$in_selected) {
          continue;
        }
        // Otherwise, if the field is configured to exclude the selected
        // actions, skip actions that were selected.
        elseif (($this->options['include_exclude'] == 'exclude') && $in_selected) {
          continue;
        }
      }

      $options[$id] = $action->label();
    }

    // Append configurable actions to the options.
    foreach ($this->configurable_actions as $id => $definition) {
      // # is not allowd as an entitiy machine name, this way we will
      // detect configurabel actions and avoid conflicts.
      $options["#" . $id] = $definition['label'] . '...';
    }

    return $options;
  }

  /**
   * Submit handler for the bulk form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user tried to access an action without access to it.
   */
  public function viewsFormSubmit(&$form, FormStateInterface $form_state) {
    if ($form_state->get('step') == 'views_form_views_form') {
      // Filter only selected checkboxes.
      $selected = array_filter($form_state->getValue($this->options['id']));
      $entities = array();
      $action_id = $form_state->getValue('action');

      // Configurable action
      if (preg_match('/^#/', $action_id)) {
        $action_id = preg_replace('/#/', '', $action_id);

        $info = [
          'selected' => $selected,
          'entity_type' => $this->getEntityType(),
        ];
        $options = array(
          'query' => $this->getDestinationArray(),
        );

        $tempStore = $this->temp_store_factory->get($action_id);
        $tempStore->set($this->view->getUser()->id(), $info);
        $form_state->setRedirect('views_bulk_operations.configure_action', array('action_id' => $action_id), $options);
      }
      // Non-configurable action
      elseif (isset($this->actions[$action_id])) {
        $action = $this->actions[$action_id];
        $all_pages = $form_state->getValue('all_pages');
        if (in_array('use_batching', $this->options['batching'])) {
          $batch_operations = $this->prepareBatchOperations($action_id, $selected, $all_pages);
          if ($all_pages) {
              $total_rows = $this->view->total_rows;
              /*$batch['operations'][] = array(
                'views_bulk_operations_adjust_selection', array($action_id),
              );*/
              $batch_operations = $this->views_bulk_operations_adjust_selection($action_id, $all_pages);
          }
          $batch = array(
            'title' => t('Apply action %label to selected items', array('%label' => $action->label())),
            'operations' => $batch_operations,
          );
          batch_set($batch);

          return;
        }

        foreach ($selected as $bulk_form_key) {
          $storage = $this->entityManager->getStorage($this->getEntityType());
          $entity = self::loadEntityFromBulkFormKey($bulk_form_key, $storage);
          $entities[$bulk_form_key] = $entity;
        }

        $entities = $this->filterEntitiesByActionAccess($entities, $action->getPlugin(), $this->view->getUser());

        $action->execute($entities);

        $operation_definition = $action->getPluginDefinition();
        if (!empty($operation_definition['confirm_form_route_name'])) {
          $options = array(
            'query' => $this->getDestinationArray(),
          );
          $form_state->setRedirect($operation_definition['confirm_form_route_name'], array(), $options);
        }
        else {
          // Don't display the message unless there are some elements affected and
          // there is no confirmation form.
          $count = count(array_filter($form_state->getValue($this->options['id'])));
          if ($count) {
            drupal_set_message($this->formatPlural($count, '%action was applied to @count item.', '%action was applied to @count items.', array(
              '%action' => $action->label(),
            )));
          }
        }
      }
    }
  }

  /**
   * Returns the message to be displayed when there are no selected items.
   *
   * @return string
   *  Message displayed when no items are selected.
   */
  protected function emptySelectedMessage() {
    return $this->t('No items selected.');
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormValidate(&$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue($this->options['id']));
    if (empty($selected)) {
      $form_state->setErrorByName('', $this->emptySelectedMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    if ($this->languageManager->isMultilingual()) {
      $this->getEntityTranslationRenderer()->query($this->query, $this->relationship);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

  /**
   * Wraps drupal_set_message().
   */
  protected function drupalSetMessage($message = NULL, $type = 'status', $repeat = FALSE) {
    drupal_set_message($message, $type, $repeat);
  }

  /**
   * Calculates a bulk form key.
   *
   * This generates a key that is used as the checkbox return value when
   * submitting a bulk form. This key allows the entity for the row to be loaded
   * totally independently of the executed view row.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to calculate a bulk form key for.
   * @param bool $use_revision
   *   Whether the revision id should be added to the bulk form key. This should
   *   be set to TRUE only if the view is listing entity revisions.
   *
   * @return string
   *   The bulk form key representing the entity's id, language and revision (if
   *   applicable) as one string.
   *
   * @see self::loadEntityFromBulkFormKey()
   */
  protected function calculateEntityBulkFormKey(EntityInterface $entity, $use_revision) {
    $key_parts = [$entity->language()->getId(), $entity->id()];

    if ($entity instanceof RevisionableInterface && $use_revision) {
      $key_parts[] = $entity->getRevisionId();
    }

    return implode('-', $key_parts);
  }

  /**
   * Loads an entity based on a bulk form key.
   *
   * @param string $bulk_form_key
   *   The bulk form key representing the entity's id, language and revision (if
   *   applicable) as one string.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity loaded in the state (language, optionally revision) specified
   *   as part of the bulk form key.
   */
  public static function loadEntityFromBulkFormKey($bulk_form_key, $storage) {
    $key_parts = explode('-', $bulk_form_key);
    $revision_id = NULL;

    // If there are 3 items, vid will be last.
    if (count($key_parts) === 3) {
      $revision_id = array_pop($key_parts);
    }

    // The first two items will always be langcode and ID.
    $id = array_pop($key_parts);
    $langcode = array_pop($key_parts);

    // Load the entity or a specific revision depending on the given key.
    $entity = $revision_id ? $storage->loadRevision($revision_id) : $storage->load($id);

    if ($entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    return $entity;
  }

  public static function filterEntitiesByActionAccess($entities, $plugin, $user) {
    foreach ($entities as $key => $entity) {
      // Skip execution if the user did not have access.
      if (!$plugin->access($entity, $user)) {
        drupal_set_message(t('No access to execute %action on the @entity_type_label %entity_label.', [
          '%action' => $plugin->pluginDefinition['label'],
          '@entity_type_label' => $entity->getEntityType()->getLabel(),
          '%entity_label' => $entity->label()
        ]), 'error');
        unset($entities[$key]);
      }
    }

    return $entities;
  }

  /**
   * Return form configuration for the select all checkboxes.
   *
   * @param bool $enable_select_all_pages
   *   whether to display the select all pages option.

   * @return array $form
   */
  protected function selectAllForm($enable_select_all_pages = FALSE) {
    $form = array();

    $form['#attached']['library'][] = 'views_bulk_operations/vbo.selectAll';

    $form['select_all'] = array(
      '#type' => 'fieldset',
      '#attributes' => array('class' => array('vbo-fieldset-select-all')),
    );
    $form['select_all']['this_page'] = array(
      '#type' => 'checkbox',
      '#title' => t('Select all items on this page'),
      '#default_value' => '',
      '#attributes' => array('class' => array('vbo-select-this-page')),
    );

    if ($enable_select_all_pages) {
      $form['select_all']['or'] = array(
        '#type' => 'markup',
        '#markup' => '<em>' . t('OR') . '</em>',
      );
      $form['select_all']['all_pages'] = array(
        '#type' => 'checkbox',
        '#title' => t('Select all items on all pages'),
        '#default_value' => '',
        '#attributes' => array('class' => array('vbo-select-all-pages')),
      );
    }

    return $form;
  }

  protected function prepareBatchOperations($action_id, $selected, $all_pages) {
    $operations = array();

    $user_id = \Drupal::currentUser()->id();

    foreach ($selected as $bulk_form_key) {

      $key_parts = explode('-', $bulk_form_key);
      $revision_id = NULL;

      // If there are 3 items, vid will be last.
      if (count($key_parts) === 3) {
        $revision_id = array_pop($key_parts);
      }

      // The first two items will always be langcode and ID.
      $id = array_pop($key_parts);
      $langcode = array_pop($key_parts);

      $operations[] = array(array(__CLASS__, 'batchOperation'), array($action_id, $id, $revision_id, $langcode, $user_id));
      break;
    }

    return $operations;
  }

  public static function batchOperation($action_id, $id, $revision_id, $langcode, $user_id) {
    $entityManager = \Drupal::getContainer()->get('entity.manager');

    $actionStorage = $entityManager->getStorage('action');
    $action = $actionStorage->load($action_id);

    $entityType = $action->getType();
    $entityStorage = $entityManager->getStorage($entityType);

    $entity = $revision_id ? $entityStorage->loadRevision($revision_id) : $entityStorage->load($id);

    if ($entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    $user = $entityManager->getStorage('user')->load($user_id);

    $plugin = $action->getPlugin();
    if (!$plugin->access($entity, $user)) {
      drupal_set_message(t('No access to execute %action on the @entity_type_label %entity_label.', [
        '%action' => $plugin->pluginDefinition['label'],
        '@entity_type_label' => $entity->getEntityType()->getLabel(),
        '%entity_label' => $entity->label()
      ]), 'error');
      return;
    }

    $action->execute(array($entity));
  }

  /**
   * Batch API callback: loads the view page by page and enqueues all items.
   *
   * @param $queue_name
   *   The name of the queue to which the items should be added.
   * @param $operation
   *   The operation object.
   * @param $options
   *   An array of options that affect execution (revision, entity_load_capacity,
   *   view_info). Passed along with each new queue item.
   */
  public function views_bulk_operations_adjust_selection($action_id, $all_pages) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = 0;
    }

    /** @var \Drupal\views\ViewExecutable $view */
    $this->view->pager->hasMoreRecords();

    $view = \Drupal\views\Views::getView($this->view->storage->id());
    $view->setExposedInput($this->view->getExposedInput());
    $view->setArguments($this->view->argument);
    $view->setDisplay($view->current_display);
    $view->setItemsPerPage(0);
    $view->build();
    $view->execute($view->current_display);
    /*// Note the total number of rows.
    if (empty($context['sandbox']['max'])) {
      $context['sandbox']['max'] = $view->total_rows;
      $context['sandbox']['rows'] = $view->result;
    }*/
    $user_id = \Drupal::currentUser()->id();
    $revision_id = NULL;
    foreach($view->result AS $row) {
      $operations[] = array(array(__CLASS__, 'batchOperation'), array($action_id, $row->nid, $revision_id, $row->_entity->language()->getId(), $user_id));
    }
    return $operations;
  }
}


