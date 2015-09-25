<?php

/**
 * @file
 * Contains \Drupal\views_bulk_operations\Tests\ViewsBulkOperationTest.
 */

namespace Drupal\views_bulk_operations\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\Component\Utility\Crypt;
use Drupal\views\Views;

/**
 * @group views_bulk_operations
 */
class ViewsBulkOperationTest extends WebTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = array('node', 'views', 'views_ui', 'action', 'views_bulk_operations', 'vbo_configurable_action_test');

  protected function setUp() {
    parent::setUp();

    if ($this->profile != 'standard') {
      $this->drupalCreateContentType(array(
        'type' => 'page',
        'name' => 'Basic page',
        'display_submitted' => FALSE,
      ));
      $this->drupalCreateContentType(array('type' => 'article', 'name' => 'Article'));
    }

    $this->adminUser = $this->drupalCreateUser(array('administer actions', 'administer views', 'create page content'));
    $this->drupalLogin($this->adminUser);

    $title_key = 'title[0][value]';
    $body_key = 'body[0][value]';
    // Create node to edit.
    $edit = array();
    $edit[$title_key] = $this->randomMachineName(8);
    $edit[$body_key] = $this->randomMachineName(16);
    $this->drupalPostForm('node/add/page', $edit, t('Save'));
  }

  public function X_testBulkOperationOnNonConfigurableAction() {
    $this->drupalGet('admin/config/system/actions');

    $option_value = Crypt::hashBase64('test_configurable_action');
    $this->assertOption('edit-action', $option_value);

    $this->drupalPostForm(NULL, ['action' => $option_value], t('Create'));

    $this->assertFieldByName('foo');

    $foo = $this->randomMachineName(8);

    $this->drupalPostForm(NULL, ['foo' => $foo, 'id' => strtolower($foo)], t('Save'));

    $this->assertOption('edit-action', strtolower($foo));
  }

  public function testBulkOperationOnConfigurableAction() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');

    $this->drupalGet('vbo-test');

    $this->assertOption('edit-action', '#test_configurable_action');

    $edit["vbo_node_bulk_form[0]"] = 'en-1';
    $edit["action"] = '#test_configurable_action';

    $this->drupalPostForm(NULL, $edit, t('Apply'));
    $this->assertFieldByName('foo');

    $title_prefix = $this->randomMachineName(8);
    $this->drupalPostForm(NULL, ['foo' => $title_prefix], t('Apply'));

    $node = $node_storage->load(1);
    $title = $node->label();

    $this->assertTrue(preg_match("/^$title_prefix - /", $title), 'The node was prefixed with the prefix');

    $this->assertUrl('vbo-test', [], "Test if we have been redirected to the views page." );
  }
}
