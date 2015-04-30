<?php
/**
 * Raw List controller class.
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2015 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 * @since       1.6
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

require_once 'fabcontrollerform.php';

/**
 * Raw List controller class.
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @since       3.0
 */
class FabrikAdminControllerList extends FabControllerForm
{
	/**
	 * The prefix to use with controller messages.
	 *
	 * @var    string
	 */
	protected $text_prefix = 'COM_FABRIK_LIST';

	/**
	 * Ajax load drop down of all columns in a given table
	 *
	 * @return  null
	 */
	public function ajax_loadTableDropDown()
	{
		$input = $this->app->input;
		$conn  = $input->getInt('conn', 1);
		$oCnn  = new Fabrik\Admin\Models\Connection;
		$oCnn->set('id', $conn);
		$oCnn->getItem();
		$db         = $oCnn->getDb();
		$table      = $input->get('table', '');
		$fieldNames = array();
		$name       = $input->get('name', 'jform[params][table_key][]', '', 'string');

		if ($table != '')
		{
			$table = FabrikString::safeColName($table);
			$sql   = 'DESCRIBE ' . $table;
			$db->setQuery($sql);
			$aFields = $db->loadObjectList();

			if (is_array($aFields))
			{
				foreach ($aFields as $oField)
				{
					$fieldNames[] = JHTML::_('select.option', $oField->Field);
				}
			}
		}

		$fieldDropDown = JHTML::_('select.genericlist', $fieldNames, $name, "class=\"inputbox\"  size=\"1\" ", 'value', 'text', '');
		echo $fieldDropDown;
	}

	/**
	 * Delete list items
	 *
	 * @return  null
	 */
	public function delete()
	{
		// Check for request forgeries
		JSession::checkToken() or die('Invalid Token');
		$input  = $this->app->input;
		$model  = JModelLegacy::getInstance('List', 'FabrikFEModel');
		$listId = $input->getInt('listid');
		$model->setId($listId);
		$ids        = $input->get('ids', array(), 'array');
		$limitStart = $input->getInt('limitstart' . $listId);
		$length     = $input->getInt('limit' . $listId);
		$oldTotal   = $model->getTotalRecords();
		$model->deleteRows($ids);
		$total = $oldTotal - count($ids);

		if ($total >= $limitStart)
		{
			$newLimitStart = $limitStart - $length;

			if ($newLimitStart < 0)
			{
				$newLimitStart = 0;
			}

			$context = 'com_fabrik.list' . $model->getRenderContext() . '.list.';
			$this->app->setUserState($context . 'limitstart' . $listId, $newLimitStart);
		}

		$input->set('view', 'list');
		$this->view();
	}

	/**
	 * Show the lists data in the admin
	 *
	 * @param   object $model list model
	 *
	 * @return  void
	 */
	public function view($model = null)
	{
		$input   = $this->app->input;
		$cid     = $input->get('cid', array(0), 'array');
		$cid     = $cid[0];
		$cid     = $input->getInt('listid', $cid);
		$listRef = $input->getString('listref');

		if (is_null($model))
		{
			$cid = (int) $input->get('listid', $cid);

			// Grab the model and set its id
			$model = JModelLegacy::getInstance('List', 'FabrikFEModel');
			$model->setState('list.id', $cid);
		}

		if (strstr($listRef, 'mod_'))
		{
			$bits     = explode('_', $listRef);
			$moduleId = array_pop($bits);
			$this->bootFromModule($moduleId, $model);
		}

		$viewType = JFactory::getDocument()->getType();

		// Use the front end renderer to show the table
		$this->setPath('view', COM_FABRIK_FRONTEND . '/views');
		$viewLayout = $input->get('layout', 'default');
		$view       = $this->getView($this->view_item, $viewType, 'FabrikView');
		$view->setModel($model, true);

		// Set the layout
		$view->setLayout($viewLayout);
		$view->display();
	}

	/**
	 * Load up module prefilters etc
	 *
	 * @param   int          $moduleId Module id
	 * @param   JModelLegacy $model    List model
	 *
	 * @return  void
	 */
	private function bootFromModule($moduleId, &$model)
	{
		require_once JPATH_ADMINISTRATOR . '/modules/mod_fabrik_list/helper.php';
		$listParams = $model->getParams();

		// Load module parameters
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('params')->from('#__modules')->where('id = ' . (int) $moduleId);
		$db->setQuery($query);
		$params = $db->loadResult();
		$params = new JRegistry($params);

		ModFabrikListHelper::applyParams($params, $model);
		$model->setRenderContext($moduleId);
	}

	/**
	 * Order the lists
	 *
	 * @return  null
	 */
	public function order()
	{
		// Check for request forgeries
		JSession::checkToken() or die('Invalid Token');
		$input = $this->app->input;
		$model = JModelLegacy::getInstance('List', 'FabrikFEModel');
		$id    = $input->getInt('listid');
		$model->setId($id);
		$input->set('cid', $id);
		$model->setOrderByAndDir();

		// $$$ hugh - unset 'resetfilters' in case it was set on QS of original table load.
		$input->set('resetfilters', 0);
		$input->set('clearfilters', 0);
		$this->view();
	}

	/**
	 * Clear filters
	 *
	 * @return  null
	 */
	public function clearfilter()
	{
		$this->app->enqueueMessage(FText::_('COM_FABRIK_FILTERS_CLEARED'));
		$this->app->input->set('clearfilters', 1);
		$this->filter();
	}

	/**
	 * Filter list items
	 *
	 * @return  null
	 */
	public function filter()
	{
		// Check for request forgeries
		JSession::checkToken() or die('Invalid Token');
		$model = JModelLegacy::getInstance('List', 'FabrikFEModel');
		$id    = $this->app->input->getInt('listid');
		$model->setId($id);
		$this->app->input->set('cid', $id);
		$request = $model->getRequestData();
		$model->storeRequestData($request);

		// Pass in the model otherwise display() rebuilds it and the request data is rebuilt
		$this->view($model);
	}

	/**
	 * Called via ajax when element selected in advanced search popup window
	 * OR in update_col plugin
	 *
	 * @return  null
	 */
	public function elementFilter()
	{
		$input = $this->app->input;
		$id    = $input->getInt('id');
		$model = $this->getModel('list', 'FabrikFEModel');
		$model->setId($id);
		echo $model->getAdvancedElementFilter();
	}
}
