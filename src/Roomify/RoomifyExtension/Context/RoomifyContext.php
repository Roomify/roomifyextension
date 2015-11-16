<?php

namespace Roomify\RoomifyExtension\Context;

use Behat\Gherkin\Node\TableNode;

use Drupal\DrupalExtension\Context\DrupalContext,
    Drupal\Component\Utility\Random;

use Behat\Behat\Hook\Scope\BeforeScenarioScope,
    Behat\Behat\Hook\Scope\AfterScenarioScope;

use Behat\Behat\Hook\Scope\AfterStepScope;

use Behat\Behat\Context\CustomSnippetAcceptingContext;

use Drupal\DrupalDriverManager;

class RoomifyContext extends DrupalContext implements CustomSnippetAcceptingContext {

  protected $dateFormat = 'd/m/Y';

  /**
   * The Mink context
   *
   * @var Drupal\DrupalExtension\Context\MinkContext
   */
  private $minkContext;

  /**
   * The Maillog
   *
   * @var int
   */
  private $maillog_last_id;

  /**
   * Keep track of bookable units so they can be cleaned up.
   *
   * @var array
   */
  public $units = array();

  /**
   * Keep track of bookings so they can be cleaned up.
   *
   * @var array
   */
  public $bookings = array();

  /**
   * Keep track of customer profiles so they can be cleaned up.
   *
   * @var array
   */
  public $customerProfiles = array();
  
  public static function getAcceptedSnippetType() { return 'regex'; }

  /**
   * @BeforeScenario
   */
  public function before(BeforeScenarioScope $scope) {
    $environment = $scope->getEnvironment();
    $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');

    if (module_exists('maillog')) {
      $this->maillog_last_id = (int) db_query('SELECT MAX(idmaillog) FROM {maillog}')->fetchField();
      // maillog configuration
      variable_set('maillog_send', 0);
      variable_set('maillog_log', 1);
      variable_set('maillog_devel', 0);
      // Set the the maillog handler in all mail system types
      $mail_system = array(
       'default-system' => 'MaillogMailSystem',
       'htmlmail' => 'MaillogMailSystem',
       'variable_email' => 'MaillogMailSystem',
       'maillog' => 'MaillogMailSystem',
      );

      variable_set('mail_system', $mail_system);
    }
  }

  /**
   * @AfterScenario
   */
  public function after(AfterScenarioScope $scope) {
    if (!empty($this->bookings)) {
      rooms_booking_delete_multiple($this->bookings);
    }

    if (!empty($this->customerProfiles)) {
      commerce_customer_profile_delete_multiple($this->customerProfiles);
      db_delete('rooms_customers')
        ->condition('commerce_customer_id', $this->customerProfiles)
        ->execute();
    }

    if (!empty($this->units)) {
      foreach ($this->units as $unit) {
        $unit->delete();
      }
    }
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
      throw new \Exception('Could not find an id for field with locator: ' . $locator);
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

    if (!$driver instanceof \Behat\Mink\Driver\Selenium2Driver) {
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
        catch (\Exception $e) {
          throw new \Behat\Mink\Exception\ResponseTextException('Feature "' . $feature->info['name'] . '" is not in default state', $this->minkContext->getSession());
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

  /**
   * @When /^I am on the "([^"]*)" unit$/
   */
  public function iAmOnTheUnit($unit_name) {
    $this->iAmDoingOnTheUnit('view', $unit_name);
  }

  /**
   * @When /^I am editing the "([^"]*)" unit$/
   */
  public function iAmEditingTheUnit($unit_name) {
    $this->iAmDoingOnTheUnit('edit', $unit_name);
  }

  /**
   * @When /^I am deleting the "([^"]*)" unit$/
   */
  public function iAmDeletingTheUnit($unit_name) {
    $this->iAmDoingOnTheUnit('delete', $unit_name);
  }

  /**
   * Redirects user to the action page for the given unit.
   *
   * @param $action
   * @param $unit_name
   */
  protected function iAmDoingOnTheUnit($action, $unit_name) {
    $unit_id = $this->findBookableUnitByName($unit_name);
    $url = "admin/rooms/units/unit/$unit_id/$action";
    $this->getSession()->visit($this->locatePath($url));
  }

  /**
   * Returns a unit_id from its name.
   *
   * @param $unit_name
   * @return int
   * @throws RuntimeException
   */
  protected function findBookableUnitByName($unit_name) {
    $efq = new \EntityFieldQuery();
    $efq->entityCondition('entity_type', 'rooms_unit')
      ->propertyCondition('name', $unit_name);
    $results = $efq->execute();
    if ($results && isset($results['rooms_unit'])) {
      return key($results['rooms_unit']);
    }
    else {
      throw new \RuntimeException('Unable to find that bookable unit');
    }
  }

  /**
   * @When /^I visit the last unit created$/
   */
  public function iVisitTheLastUnitCreated() {
    $unit_id = $this->getLastUnit();
    $url = "unit/$unit_id";
    $this->getSession()->visit($this->locatePath($url));
  }  

  /**
   * Retrieves the last unit ID.
   *
   * @return int
   *   The last unit ID.
   *
   * @throws RuntimeException
   */
  protected function getLastUnit() {
    $efq = new \EntityFieldQuery();
    $efq->entityCondition('entity_type', 'rooms_unit')
      ->entityOrderBy('entity_id', 'DESC')
      ->range(0, 1);
    $result = $efq->execute();
    if (isset($result['rooms_unit'])) {
      $return = key($result['rooms_unit']);
      return $return;
    }
    else {
      throw new \RuntimeException('Unable to find the last booking');
    }
  }

  /**
   * @Given /^"(?P<type>[^"]*)" bookings:$/
   */
  public function createBookings($type, TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $profile_id = $this->customerProfiles[$nodeHash['profile_id']];

      $profile = commerce_customer_profile_load($profile_id);
      $client_name = isset($profile->commerce_customer_address['und'][0]['name_line']) ? $profile->commerce_customer_address['und'][0]['name_line'] : $nodeHash['profile_id'];

      // Save customer in rooms_customers table.
      db_merge('rooms_customers')
        ->key(array('name' => $client_name))
        ->fields(array(
          'name' => $client_name,
          'commerce_customer_id' => $profile_id,
        ))
        ->execute();

      // Get customer id from rooms_customers table.
      $client_id = db_select('rooms_customers')
        ->fields('rooms_customers', array('id'))
        ->condition('name', $client_name, '=')
        ->execute()->fetchField();

      $unit_id = $this->findBookableUnitByName($nodeHash['unit']);
      $unit = rooms_unit_load($unit_id);
      $unit_type = $unit->type;
      $data = array(
        'type' => $type,
        'name' => $client_name,
        'customer_id' => $client_id,
        'unit_id' => $unit_id,
        'unit_type' => $unit_type,
        'start_date' => $nodeHash['start_date'],
        'end_date' => $nodeHash['end_date'],
        'booking_status' => $nodeHash['status'],
        'data' => array(
          'group_size' => $nodeHash['guests'],
          'group_size_children' => $nodeHash['children'],
        ),
      );
      $booking = rooms_booking_create($data);
      $booking->save();

      $start_date = new \DateTime($nodeHash['start_date']);
      $end_date = new \DateTime($nodeHash['end_date']);
      $booking_parameters = array('adults' => $nodeHash['guests'], 'children' => $nodeHash['children']);
      $order = rooms_booking_manager_create_order($start_date, $end_date, $booking_parameters, $unit, $booking, $client_id);

      $booking->order_id = $order->order_number;
      $booking->save();

      $this->bookings[] = $booking->booking_id;
    }
  }

  /**
   * @Given /^customer profiles:$/
   */
  public function createCustomerProfiles(TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $profile = commerce_customer_profile_new('billing', isset($this->user->uid) ? $this->user->uid : 0);
      $wrapper = entity_metadata_wrapper('commerce_customer_profile', $profile);
      if (isset($nodeHash['country'])) {
        $wrapper->commerce_customer_address->country = $nodeHash['country'];
      }
      if (isset($nodeHash['name'])) {
        $wrapper->commerce_customer_address->name_line = $nodeHash['name'];
      }
      if (isset($nodeHash['address'])) {
        $wrapper->commerce_customer_address->thoroughfare = $nodeHash['address'];
      }
      if (isset($nodeHash['locality'])) {
        $wrapper->commerce_customer_address->locality = $nodeHash['locality'];
      }
      if (isset($nodeHash['postal_code'])) {
        $wrapper->commerce_customer_address->postal_code = $nodeHash['postal_code'];
      }
      $wrapper->save();
      if (isset($nodeHash['profile_id'])) {
        $this->customerProfiles[$nodeHash['profile_id']] = $wrapper->profile_id->value();
      }
      else {
        $this->customerProfiles[] = $wrapper->profile_id->value();
      }
    }
  }

  /**
   * @Given /^I am on the booking page with:$/
   */
  public function onTheBookingPage(TableNode $nodesTable) {
    foreach ($nodesTable->getHash() as $nodeHash) {
      $unit_id = $this->getLastUnit();
      $url = 'booking/' . $nodeHash['start_date'] . '/' . $nodeHash['end_date'] . '/1?bookable_units=' . $unit_id . '&rooms_group_size1=' . $nodeHash['rooms_group_size1'];
      $this->getSession()->visit($this->locatePath($url));
    }
  }

  /**
   * Replace the property tokens in a give string.
   *
   * @param string $string
   *   The string where the tokens will be find and replaced.
   *
   * @return string
   *   The string with the replaced value token or same input string if no token found.
   **/
  private function replaceTokens($string) {
    // Random string token
    if (preg_match("/<random>/", $string, $matches)) {
      $string = str_replace('<random>', $this->getRandom()->name(), $string);
    }

    // Member property tokens
    if (preg_match("/(?:<)(?P<property>.*)(?:>)/", $string, $matches)) {
      $property_name = $matches['property'];
      $string = str_replace("<$property_name>", $this->getCurrentMemberProperty($property_name), $string);
    }

    return $string;
  }

  /**
   * Returns TRUE if the actual email matches the expected email.
   *
   * @param array $actual
   *  An associative array of all the columns in the actual maillog database
   *  table row.
   *
   * @param array $expected
   *  The expected email regex values. Allowed, case insensitive keys are:
   *   - Subject, To, From, Reply to, Body.
   * @return bool
   */
  private function emailsMatch($actual, $expected) {
    $match_count = 0;

    foreach ($expected as $part => $expected_value) {
      $part = strtolower($part);
      if ('subject' == $part) {
        $actual_value = $actual['subject'];
      }
      elseif ('to' == $part) {
        $actual_value = $actual['header_to'];
      }
      elseif ('from' == $part) {
        $actual_value = $actual['header_from'];
      }
      elseif ('reply to' == $part) {
        $actual_value = $actual['header_reply_to'];
      }
      elseif ('body' == $part) {
        $actual_value = $actual['body'];
      }
      else {
        throw new \Exception("Unknown part in expected email '$part'");
      }

      // Replace <random> or member <property> token
      $expected_value = $this->replaceTokens($expected_value);

      if (preg_match("/$expected_value/i", $actual_value, $matches)) {
        $match_count++;
      }
    }

    if (count($expected) == $match_count) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @Then /^(\d+) emails? should be sent:$/i
   */
  public function emailsWereSent($num, TableNode $table) {
    $expected_emails = $table->getHash();

    $actual_emails = db_select('maillog', 'm')
      ->fields('m')
      ->condition('idmaillog', $this->maillog_last_id, '>')
      ->orderBy('idmaillog', 'DESC')
      ->execute()
      ->fetchAllAssoc('idmaillog', \PDO::FETCH_ASSOC);

    if (count($actual_emails) < $num) {
      throw new \Exception('Only ' . count($actual_emails) . ' were sent.');
    }

    $missing_emails = array();
    foreach ($expected_emails as $expected_email) {
      foreach ($actual_emails as $actual_email) {
        if ($this->emailsMatch($actual_email, $expected_email)) {
          continue 2;
        }
      }
      // No matches for the expected email.
      $missing_emails[] = $expected_email;
    }

    if (count($missing_emails) > 0) {
      $message = "Missing Emails:\n" . implode("\n", array_map('drupal_json_encode', $missing_emails));
      throw new \Exception($message);
    }
  }

  /**
   * @Then /^the metatag attribute "(?P<attribute>[^"]*)" should have the value "(?P<value>[^"]*)"$/
   *
   * @throws \Exception
   *   If region or link within it cannot be found.
   */
  public function assertMetaRegion($metatag, $value) {
    $element = $this->getSession()->getPage()->find('xpath', '/head/meta[@name="' . $metatag . '"]');

    if ($value == $element->getAttribute('content')) {
      $result = $value;
    }

    if (empty($result)) {
      throw new \Exception(sprintf('No link to "%s" on the page %s', $metatag, $this->getSession()->getCurrentUrl()));
    }
  }

  /**
   * @Then /^I should see "(?P<value>[^"]*)" in the title element$/
   *
   * @throws \Exception
   *   If region or link within it cannot be found.
   */
  public function assertTitleElement($value) {
    $element = $this->getSession()->getPage()->find('xpath', '/head/title');

    return $element->getText();
  }

}
