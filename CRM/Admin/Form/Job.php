<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.6                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2015                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2015
 * $Id: $
 *
 */

/**
 *
 */
class CRM_Admin_Form_Job extends CRM_Admin_Form {
  protected $_id = NULL;

  public function preProcess() {

    parent::preProcess();

    CRM_Utils_System::setTitle(ts('Manage - Scheduled Jobs'));

    if ($this->_id) {
      $refreshURL = CRM_Utils_System::url('civicrm/admin/job',
        "reset=1&action=update&id={$this->_id}",
        FALSE, NULL, FALSE
      );
    }
    else {
      $refreshURL = CRM_Utils_System::url('civicrm/admin/job',
        "reset=1&action=add",
        FALSE, NULL, FALSE
      );
    }

    $this->assign('refreshURL', $refreshURL);
  }

  /**
   * Build the form object.
   *
   * @param bool $check
   *
   * @return void
   */
  public function buildQuickForm($check = FALSE) {
    parent::buildQuickForm();

    if ($this->_action & CRM_Core_Action::DELETE) {
      return;
    }

    $attributes = CRM_Core_DAO::getAttribute('CRM_Core_DAO_Job');

    $this->add('text', 'name', ts('Name'),
      $attributes['name'], TRUE
    );

    $this->addRule('name', ts('Name already exists in Database.'), 'objectExists', array(
        'CRM_Core_DAO_Job',
        $this->_id,
      ));

    $this->add('text', 'description', ts('Description'),
      $attributes['description']
    );

    $this->add('text', 'api_entity', ts('API Call Entity'),
      $attributes['api_entity'], TRUE
    );

    $this->add('text', 'api_action', ts('API Call Action'),
      $attributes['api_action'], TRUE
    );

    $this->add('select', 'run_frequency', ts('Run frequency'), CRM_Core_SelectValues::getJobFrequency());

    /************************************
     * begin com.klangsoft.flexiblejobs *
     ************************************/
    $this->addDateTime('schedule_at', ts(($this->_id ? 'Next' : 'First') . ' Run Date / Time'), FALSE, array('formatType' => 'activityDateTime'));
    /**********************************
     * end com.klangsoft.flexiblejobs *
     **********************************/

    $this->add('textarea', 'parameters', ts('Command parameters'),
      "cols=50 rows=6"
    );

    // is this job active ?
    $this->add('checkbox', 'is_active', ts('Is this Scheduled Job active?'));

    $this->addFormRule(array('CRM_Admin_Form_Job', 'formRule'));
  }

  /**
   * @param $fields
   *
   * @return array|bool
   * @throws API_Exception
   */
  public static function formRule($fields) {

    $errors = array();

    require_once 'api/api.php';

    /** @var \Civi\API\Kernel $apiKernel */
    $apiKernel = \Civi\Core\Container::singleton()->get('civi_api_kernel');
    $apiRequest = \Civi\API\Request::create($fields['api_entity'], $fields['api_action'], array('version' => 3), NULL);
    try {
      $apiKernel->resolve($apiRequest);
    }
    catch (\Civi\API\Exception\NotImplementedException $e) {
      $errors['api_action'] = ts('Given API command is not defined.');
    }

    if (!empty($errors)) {
      return $errors;
    }

    return empty($errors) ? TRUE : $errors;
  }

  /**
   * @return array
   */
  public function setDefaultValues() {
    $defaults = array();

    if (!$this->_id) {
      $defaults['is_active'] = $defaults['is_default'] = 1;
      return $defaults;
    }
    $domainID = CRM_Core_Config::domainID();

    $dao = new CRM_Core_DAO_Job();
    $dao->id = $this->_id;
    $dao->domain_id = $domainID;
    if (!$dao->find(TRUE)) {
      return $defaults;
    }

    CRM_Core_DAO::storeValues($dao, $defaults);

    /************************************
     * begin com.klangsoft.flexiblejobs *
     ************************************/
    if ($ts = CRM_Core_BAO_Setting::getItem('com.klangsoft.flexiblejobs', 'job_' . $this->_id)) {
      $defaults['schedule_at'] = date('m/d/Y', $ts);
      $defaults['schedule_at_time'] = date('h:iA', $ts);
    }
    /**********************************
     * end com.klangsoft.flexiblejobs *
     **********************************/

    // CRM-10708
    // job entity thats shipped with core is all lower case.
    // this makes sure camel casing is followed for proper working of default population.
    if (!empty($defaults['api_entity'])) {
      $defaults['api_entity'] = ucfirst($defaults['api_entity']);
    }

    return $defaults;
  }

  /**
   * Process the form submission.
   *
   *
   * @return void
   */
  public function postProcess() {

    CRM_Utils_System::flushCache('CRM_Core_DAO_Job');

    if ($this->_action & CRM_Core_Action::DELETE) {
      CRM_Core_BAO_Job::del($this->_id);
      CRM_Core_Session::setStatus("", ts('Scheduled Job Deleted.'), "success");
      return;
    }

    $values = $this->controller->exportValues($this->_name);
    $domainID = CRM_Core_Config::domainID();

    $dao = new CRM_Core_DAO_Job();

    $dao->id = $this->_id;
    $dao->domain_id = $domainID;
    $dao->run_frequency = $values['run_frequency'];
    $dao->parameters = $values['parameters'];
    $dao->name = $values['name'];
    $dao->api_entity = $values['api_entity'];
    $dao->api_action = $values['api_action'];
    $dao->description = $values['description'];
    $dao->is_active = CRM_Utils_Array::value('is_active', $values, 0);

    $dao->save();

    /************************************
     * begin com.klangsoft.flexiblejobs *
     ************************************/
    $ts = strtotime(trim("{$values['schedule_at']} {$values['schedule_at_time']}"));
    if ($ts < time()) {
      $ts = NULL;
    }
    else {
      // warn about monthly/quarterly scheduling, if applicable
      if (($dao->run_frequency == 'Monthly') || ($dao->run_frequency == 'Quarter')) {
        $info = getdate($ts);
        if ($info['mday'] > 28) {
          CRM_Core_Session::setStatus(
            ts('Relative month values are calculated based on the length of month(s) that they pass through.
              The result will land on the same day of the month except for days 29-31 when the target month contains fewer days than the previous month.
              For example, if a job is scheduled to run on August 31st, the following invocation will occur on October 1st, and then the 1st of every month thereafter.
              To avoid this issue, please schedule Monthly and Quarterly jobs to run within the first 28 days of the month.'),
            ts('Warning'), 'info', array('expires' => 0));
        }
      }
    }
    CRM_Core_BAO_Setting::setItem($ts ?: NULL, 'com.klangsoft.flexiblejobs', 'job_' . $dao->id);
    /**********************************
     * end com.klangsoft.flexiblejobs *
     **********************************/

    // CRM-11143 - Give warning message if update_greetings is Enabled (is_active) since it generally should not be run automatically via execute action or runjobs url.
    if ($values['api_action'] == 'update_greeting' && CRM_Utils_Array::value('is_active', $values) == 1) {
      // pass "wiki" as 6th param to docURL2 if you are linking to a page in wiki.civicrm.org
      $docLink = CRM_Utils_System::docURL2("Managing Scheduled Jobs", NULL, NULL, NULL, NULL, "wiki");
      $msg = ts('The update greeting job can be very resource intensive and is typically not necessary to run on a regular basis. If you do choose to enable the job, we recommend you do not run it with the force=1 option, which would rebuild greetings on all records. Leaving that option absent, or setting it to force=0, will only rebuild greetings for contacts that do not currently have a value stored. %1', array(1 => $docLink));
      CRM_Core_Session::setStatus($msg, ts('Warning: Update Greeting job enabled'), 'alert');
    }

  }

}
