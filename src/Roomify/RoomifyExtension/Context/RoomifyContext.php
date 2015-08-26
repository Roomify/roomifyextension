<?php

namespace Roomify\RoomifyExtension\Context;

use Drupal\DrupalExtension\Context\DrupalSubContextBase,
    Drupal\Component\Utility\Random;

use Behat\Behat\Hook\Scope\BeforeScenarioScope,
    Behat\Behat\Hook\Scope\AfterScenarioScope;

use Behat\Behat\Hook\Scope\AfterStepScope;

use Behat\Behat\Context\CustomSnippetAcceptingContext;

use Drupal\DrupalDriverManager;

class RoomifyContext extends DrupalSubContextBase implements CustomSnippetAcceptingContext {

  protected $dateFormat = 'd/m/Y';

  /**
   * The Mink context
   *
   * @var Drupal\DrupalExtension\Context\MinkContext
   */
  private $minkContext;

  /**
   * Initializes context.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct(DrupalDriverManager $drupal) {
    parent::__construct($drupal);
  }

  public static function getAcceptedSnippetType() { return 'regex'; }

  /**
   * @BeforeScenario
   */
  public function before(BeforeScenarioScope $scope) {
    $environment = $scope->getEnvironment();
    $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');
  }

  /**
   * @AfterScenario
   */
  public function after(AfterScenarioScope $scope) {
  }

  /**
   * Click some text
   *
   * @When /^I click on the text "(?P<text>[^"]*)"$/
   */
  public function clickOnTheText($text) {
    $session = $this->getSession();
    $selector = $session->getSelectorsHandler()->selectorToXpath('xpath', '//*[text()="'. $text .'"]');
    $element = $session->getPage()->find('xpath', $selector);
    if ($element === NULL) {
      throw new \InvalidArgumentException(sprintf('Cannot find text: "%s"', $text));
    }

    $element->click();
  }

  /**
   * Sets an id for the first iframe situated in the element specified by id.
   * Needed when wanting to fill in WYSIWYG editor situated in an iframe without identifier.
   *
   * @Given /^the iframe in element "(?P<element>[^"]*)" has id "(?P<id>[^"]*)"$/
   */
  public function theIframeInElementHasId($element_id, $iframe_id) {
    $function = <<<JS
(function(){
  var elem = document.getElementById("$element_id");
  var iframes = elem.getElementsByTagName('iframe');
  var f = iframes[0];
  f.id = "$iframe_id";
})()
JS;
    try {
      $this->getSession()->executeScript($function);
    }
    catch(Exception $e) {
      throw new \Exception(sprintf('No iframe found in the element "%s" on the page "%s".', $element_id, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * Fills in WYSIWYG editor with specified id.
   *
   * @Then /^I fill in "(?P<text>[^"]*)" in WYSIWYG editor "(?P<iframe>[^"]*)"$/
   */
  public function iFillInInWYSIWYGEditor($text, $iframe) {
    try {
      $this->getSession()->switchToIFrame($iframe);
    }
    catch (Exception $e) {
      throw new \Exception(sprintf("No iframe with id '%s' found on the page '%s'.", $iframe, $this->getSession()->getCurrentUrl()));
    } 
    $this->getSession()->executeScript("document.body.innerHTML = '<p>".$text."</p>'");      
    $this->getSession()->switchToIFrame();
  }

  /**
   * @Then /^I fill in chosen on field "([^"]*)" with "([^"]*)"$/
   */
  public function iFillInChosenOnFieldWith($field, $value) {
    $js = <<<HEREDOC
        jQuery("select[name='$field']").css('visibility', 'visible');
        jQuery("select[name='$field']").show();
HEREDOC;
    $this->getSession()->executeScript($js);
    $this->minkContext->fillField($field, $value);
  }

  /**
   * @Then /^I index all units in search api$/
   */
  public function iIndexUnitsSearchApi() {
    $index = search_api_index_load('units');
    search_api_index_items($index, 100);
  }

  /**
   * Wait for N seconds.
   *
   * @Given /^I wait (\d+) seconds$/
   */
  public function waitSeconds($seconds) {
    $this->getSession()->wait(1000 * $seconds);
  }

  /**
   * Click on the element with the provided CSS Selector
   *
   * @Given /^I click on the element with css selector "([^"]*)"$/
   */
  public function iClickOnTheElementWithCSSSelector($cssSelector) {
    $session = $this->getSession();
    $element = $session->getPage()->find(
      'xpath',
      $session->getSelectorsHandler()->selectorToXpath('css', $cssSelector)
    );
    if ($element === NULL) {
      throw new \Exception(sprintf('Could not evaluate CSS Selector: "%s"', $cssSelector));
    }

    $element->click();
  }

  /**
   * Check logged in status.
   *
   * Overrides RawDrupalContext::loggedIn().
   * @see https://github.com/jhedstrom/drupalextension/pull/131.
   */
  public function loggedIn() {
    $session = $this->getSession();
    $page = $session->getPage();

    // Body class check from pull/131.
    $body = $page->find('css', 'body');
    return $body->hasClass('logged-in');
  }

  /**
  * @Then /^I fill in wysiwyg on field "([^"]*)" with "([^"]*)"$/
  */
  public function iFillInWysiwygOnFieldWith($locator, $value) {
    $el = $this->getSession()->getPage()->findField($locator);
    $fieldId = $el->getAttribute('id');

    if (empty($fieldId)) {
    throw new Exception('Could not find an id for field with locator: ' . $locator);
    }

    $this->getSession()->executeScript("CKEDITOR.instances[\"$fieldId\"].setData(\"$value\");");
  }

  /**
   * RawDrupalContext::assertAnonymousUser() with better logged in check.
   *
   * @Given I am an anonymous user on this site
   * @Given I am not logged in on this site
   */
  public function assertAnonymousUserOnThisSite() {
    // Verify the user is logged out.
    if ($this->loggedIn()) {
      $this->logout();
    }
  }

  /**
   * Creates and authenticates a user with the given role(s).
   *
   * RawDrupalContext::assertAuthenticatedByRole() with better logged in check.
   *
   * @Given I am logged in as a user with the :role role(s) on this site
   */
  public function assertAuthenticatedByRoleOnThisSite($role) {
    // Check if a user with this role is already logged in.
    if (!$this->loggedInWithRole($role)) {
      // Create user (and project)
      $user = (object) array(
        'name' => $this->getRandom()->name(8),
        'pass' => $this->getRandom()->name(16),
        'role' => $role,
      );
      $user->mail = "{$user->name}@example.com";

      $this->userCreate($user);

      $roles = explode(',', $role);
      $roles = array_map('trim', $roles);
      foreach ($roles as $role) {
        if (!in_array(strtolower($role), array('authenticated', 'authenticated user'))) {
          // Only add roles other than 'authenticated user'.
          $this->getDriver()->userAddRole($user, $role);
        }
      }

      // Login.
      $this->login();
    }
  }

  /**
   * Transforms relative date statements compatible with strtotime().
   * Example: "date 2 days ago" might return "2013-10-10" if its currently
   * the 12th of October 2013. Customize through {@link setDateFormat()}.
   *
   * @Transform /^(?:(the|a)) date of (.*)$/
   */
  public function castRelativeToAbsoluteDate($prefix, $val) {
    $timestamp = strtotime($val);
    if (!$timestamp) {
      throw new \InvalidArgumentException(sprintf(
        "Can't resolve '%s' into a valid datetime value",
        $val
      ));
    }
    return date($this->dateFormat, $timestamp);
  }

  public function getDateFormat() {
    return $this->dateFormat;
  }

  public function setDateFormat($format) {
    $this->dateFormat = $format;
  }

  /**
   * @Given I am logged in with the :permissions permission(s)
   */
  public function assertLoggedInWithThePermissions($permissions) {
    // Create user.
    $user = (object) array(
      'name' => $this->getRandom()->name(8),
      'pass' => $this->getRandom()->name(16),
    );
    $user->mail = "{$user->name}@example.com";
    $this->userCreate($user);

    // Create and assign a temporary role with given permissions.
    $permissions = explode(',', $permissions);
    $rid = $this->getDriver()->roleCreate($permissions);
    $this->getDriver()->userAddRole($user, $rid);
    $this->roles[] = $rid;

    // Login.
    $this->login();
  }

  /**
   * @AfterStep
   */
  public function takeScreenshotAfterFailedStep(AfterStepScope $scope) {
    if (99 === $scope->getTestResult()->getResultCode()) {
      $this->takeScreenshot();
    }
  }

  /**
   * @Given /^I take a screenshot$/
   */
  public function iTakeAScreenshot() {
    $this->takeScreenshot();
  }

  private function takeScreenshot() {
    $screenShotPath = '/tmp/screenshots';
    $driver = $this->getSession()->getDriver();

    if (!$driver instanceof Behat\Mink\Driver\Selenium2Driver) {
      return;
    }

    if (!is_dir($screenShotPath)) {
      mkdir($screenShotPath, 0777, true);
    }

    $baseUrl = $this->getMinkParameter('base_url');
    $fileName = date('d-m-y') . '-' . uniqid() . '.png';

    $this->saveScreenshot($fileName, $screenShotPath);
    print 'Screenshot at: https://github.com/Roomify/agency_build/blob/gh-pages/' . $fileName;
  }

  /**
   * @When /^I click on "([^"]*)" on the row containing "([^"]*)"$/
   */
  public function iClickOnOnTheRowContaining($linkName, $rowText) {
    $row = $this->getSession()->getPage()->find('css', sprintf('table tr:contains("%s")', $rowText));
    if (!$row) {
      throw new \Exception(sprintf('Cannot find any row on the page containing the text "%s"', $rowText));
    }
    $row->clickLink($linkName);
  }

  /**
   * Selects option in select field with the provided CSS Selector
   *
   * @When /^(?:|I )select "(?P<option>(?:[^"]|\\")*)" from css selector "([^"]*)"$/
   */
  public function selectOptionFromCSSSelector($option, $select) {
    $element = $this->getSession()->getPage()->find(
      'xpath',
      $this->getSession()->getSelectorsHandler()->selectorToXpath('css', $select)
    );

    if (!$element) {
      throw new \InvalidArgumentException(sprintf('Not found select with CSS selector: "%s"', $select));
    }

    $element->selectOption($option);
  }

  /**
   * @Then /^I should see values in row table:$/
   */
  public function iShouldSeeValuesInTable(TableNode $nodesTable) {
    $page = $this->getSession()->getPage();
    $rows = $page->findAll('css', 'tr');
    if (!$rows) {
      throw new \Exception(sprintf('No rows found on the page %s', $this->getSession()->getCurrentUrl()));
    }

    foreach ($nodesTable->getHash() as $row_texts) {
      $found = TRUE;
      foreach ($rows as $row) {
        $found = TRUE;
        foreach ($row_texts as $row_text) {
          if (!empty($row_text) && strpos($row->getText(), $row_text) === FALSE) {
            $found = FALSE;
          }
        }
        if ($found) {
          break;
        }
      }
      if (!$found) {
        throw new \Exception(sprintf('Not found a row containing the desired texts'));
      }
    }
  }

  /**
   * @Given /^all features in the package "(?P<text>(?:[^"]|\\")*)" are in default state$/
   */
  public function featuresInThePackageAreInDefaultState($package) {
    module_load_include('inc', 'features', "features.admin");

    $features = _features_get_features_list();

    foreach ($features as $feature) {
      if ($feature->info['package'] == $package && $feature->status == '1') {
        $this->minkContext->assertAtPath('admin/structure/features/' . $feature->name . '/status');

        try {
          $this->minkContext->assertPageContainsText('{"storage":0}');
        }
        catch (Exception $e) {
          throw new Behat\Mink\Exception\ResponseTextException('Feature "' . $feature->info['name'] . '" is not in default state', $this->minkContext->getSession());
        }
      }
    }
  }

  /**
   * @When I select the first option after filling :value in :field
   */
  public function iFillInSelectInputWithAndSelect($value, $field) {
    $page = $this->getSession()->getPage();
    $this->minkContext->fillField($field, $value);

    $element = $page->findField($field);
    $this->getSession()->getDriver()->keyDown($element->getXpath(), '', null);
    $this->getSession()->wait(2000);
    $chosenResults = $page->findAll('css', '.ui-autocomplete a');
    foreach ($chosenResults as $result) {
      $result->click();
      return;
    }
    throw new \Exception(sprintf('No option was found'));
  }

}
