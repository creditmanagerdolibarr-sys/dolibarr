<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * Contributor of this script: https://github.com/joelmpunga Joel MPUNGA
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modCreditManager extends DolibarrModules
{
	public function __construct($db)
	{
		global $langs;
		$langs->loadLangs(array("creditmanager@creditmanager"));

		$this->db = $db;
		$this->numero = 560000;

		$this->family = "financial";
		$this->module_position = '55';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Gestion des crédits heures (types, soldes, débit timesheets)";

		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->editor_name = 'Joel MPUNGA and Doddy MATABARO';

		$this->dirs = array();

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array(
				'data' => array('tasktimelist', 'main'),
				'entity' => '0',
			),
			// Allow top/left menus of this module for external (client) users
			'moduleforexternal' => 1,
		);

		$this->depends = array('modSociete', 'modProjet', 'modFicheinter', 'modContrat', 'modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(7, 4);
		$this->langfiles = array("creditmanager@creditmanager");

		$this->config_page_url = array();

		$this->const = array();

		$this->boxes = array(
			0 => array(
				'file' => 'box_pending_timesheets.php@creditmanager',
				'note' => 'Pending timesheets awaiting PM approval',
				'enabledbydefaulton' => 'Home',
			),
			1 => array(
				'file' => 'box_credit_alerts.php@creditmanager',
				'note' => 'Clients with low credit balances (warning/critical)',
				'enabledbydefaulton' => 'Home',
			),
		);

		// Tabs for thirdparty (client card)
		$this->tabs = array(
			'thirdparty:+creditmanager:Credits:creditmanager:(empty($user->socid) && ($user->hasRight("creditmanager","creditmanager_admin") || $user->hasRight("creditmanager","reports_export") || $user->hasRight("creditmanager","attribution_manage") || $user->hasRight("creditmanager","timesheet_approve") || $user->hasRight("creditmanager","timesheet_manual_debit"))) || (!empty($user->socid) && ($user->hasRight("creditmanager","client_portal_read") || $user->hasRight("creditmanager","creditmanager_client"))):/custom/creditmanager/tabs/thirdpartyCredits.php?socid=__ID__',
		);

		$this->rights = array();
		$this->rights_class = 'creditmanager';
		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermRead');
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermWrite');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermDelete');
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'delete';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermAdmin');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'creditmanager_admin';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermClientLegacy');
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'creditmanager_client';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermCreditTypesManage');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'credit_types_manage';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermAttributionManage');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'attribution_manage';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermTimesheetApprove');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'timesheet_approve';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermTimesheetManualDebit');
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'timesheet_manual_debit';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermReportsExport');
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'reports_export';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = $langs->trans('CreditManagerPermClientPortalRead');
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'client_portal_read';

		// Menus
		$this->menu = array();
		$r = 0;
		$menuTopPrefix = 'fas fa-credit-card fa-fw pictofixedwidth';

		// Top menu - internal users
		$this->menu[$r++] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'CreditManager',
			'prefix'   => $menuTopPrefix,
			'mainmenu' => 'creditmanager',
			'leftmenu' => '',
			'url'      => '/custom/creditmanager/index.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 100,
			'enabled'  => 'isModEnabled("creditmanager")',
			'perms'    => '$user->hasRight("creditmanager","read") || $user->hasRight("creditmanager","creditmanager_admin")',
			'target'   => '',
			'user'     => 0,
		);

		// Top menu - external portal ("My credits")
		$this->menu[$r++] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'CreditClientMyCredits',
			'prefix'   => $menuTopPrefix,
			'mainmenu' => 'creditmanager',
			'leftmenu' => '',
			'url'      => '/custom/creditmanager/client/index.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 101,
			'enabled'  => 'isModEnabled("creditmanager") && getDolGlobalInt("CREDITMANAGER_ENABLE_CLIENT_PORTAL")',
			'perms'    => '$user->hasRight("creditmanager","client_portal_read") || $user->hasRight("creditmanager","creditmanager_client")',
			'target'   => '',
			'user'     => 1,
		);

		// Left menu - Dashboard (internal)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditManagerDashboard',
			'prefix'   => 'fas fa-file-invoice-dollar fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_dashboard',
			'url'      => '/custom/creditmanager/index.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1001,
			'enabled'  => 'isModEnabled("creditmanager") && $user->hasRight("creditmanager","read")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - PM Dashboard
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditDashboardPmMenu',
			'prefix'   => 'fas fa-tachometer-alt fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_dashboard_pm',
			'url'      => '/custom/creditmanager/dashboard/dashboard_pm.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1002,
			'enabled'  => 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","timesheet_approve") || $user->hasRight("creditmanager","creditmanager_admin"))',
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		$portalEnabledExpr = 'isModEnabled("creditmanager") && getDolGlobalInt("CREDITMANAGER_ENABLE_CLIENT_PORTAL") && ($user->hasRight("creditmanager","client_portal_read") || $user->hasRight("creditmanager","creditmanager_client"))';

		// Left menu - Portal home "My credits"
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditClientMyCredits',
			'prefix'   => 'fas fa-wallet fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_client_home',
			'url'      => '/custom/creditmanager/client/index.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1003,
			'enabled'  => $portalEnabledExpr,
			'perms'    => '1',
			'target'   => '',
			'user'     => 1,
		);

		// Left menu - Current balance (badge injected by hook)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditClientBalanceMenu',
			'prefix'   => 'fas fa-balance-scale fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_client_portal',
			'url'      => '/custom/creditmanager/client/balance.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1004,
			'enabled'  => $portalEnabledExpr,
			'perms'    => '1',
			'target'   => '',
			'user'     => 1,
		);

		// Left menu - History
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditClientHistoryMenu',
			'prefix'   => 'fas fa-history fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_client_history',
			'url'      => '/custom/creditmanager/client/history.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1005,
			'enabled'  => $portalEnabledExpr,
			'perms'    => '1',
			'target'   => '',
			'user'     => 1,
		);

		// Left menu - Associated projects (optional)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditClientProjectsMenu',
			'prefix'   => 'fas fa-project-diagram fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_client_projects',
			'url'      => '/custom/creditmanager/client/projects.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1006,
			'enabled'  => $portalEnabledExpr.' && getDolGlobalInt("CREDITMANAGER_CLIENT_SHOW_PROJECTS")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 1,
		);

		// Left menu - Credit requests (optional)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditClientRequestsMenu',
			'prefix'   => 'fas fa-hand-holding-usd fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_client_requests',
			'url'      => '/custom/creditmanager/client/balance.php#credit-request-form',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1007,
			'enabled'  => $portalEnabledExpr.' && getDolGlobalInt("CREDITMANAGER_ALLOW_CLIENT_CREDIT_REQUEST")',
			'perms'    => '1',
			'target'   => '',
			'user'     => 1,
		);

		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
		$reportsMenuEnabled = creditmanagerInternalFinancialMenuEnabledExpr();

		// Left menu - Balances
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditBalances',
			'prefix'   => 'fas fa-balance-scale fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_balances',
			'url'      => '/custom/creditmanager/balance_list.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1010,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Movements
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditMovements',
			'prefix'   => 'fas fa-exchange-alt fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_movements',
			'url'      => '/custom/creditmanager/movement_list.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1020,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Consumption report
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditReportConsumptionMenu',
			'prefix'   => 'fas fa-chart-bar fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_report_consumption',
			'url'      => '/custom/creditmanager/reports/consumption.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1021,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Forecast report
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditReportForecastMenu',
			'prefix'   => 'fas fa-chart-line fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_report_forecast',
			'url'      => '/custom/creditmanager/reports/forecast.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1022,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Budget vs real report
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditReportBudgetMenu',
			'prefix'   => 'fas fa-chart-pie fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_report_budget',
			'url'      => '/custom/creditmanager/reports/budget_vs_real.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1023,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Manual timesheet debit (MVP)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditTimesheetApproveMenu',
			'prefix'   => 'fas fa-check-circle fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_timesheet_approve',
			'url'      => '/custom/creditmanager/timesheets/approve.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1024,
			'enabled'  => 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","timesheet_approve") || $user->hasRight("creditmanager","creditmanager_admin"))',
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Manual timesheet debit (MVP)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditManagerManualDebit',
			'prefix'   => 'fas fa-hand-holding-usd fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_manual_debit',
			'url'      => '/custom/creditmanager/timesheets/debit.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1025,
			'enabled'  => 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","timesheet_manual_debit") || $user->hasRight("creditmanager","creditmanager_admin"))',
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Alerts
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditAlerts',
			'prefix'   => 'fas fa-bell fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_alerts',
			'url'      => '/custom/creditmanager/alert_list.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1030,
			'enabled'  => $reportsMenuEnabled,
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Admin section1 (separator)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditManagerAllTimeSheet',
			'prefix'   => 'fas fa-clock fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_alltm',
			'url'      => '/projet/tasks/time.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1035,
			'enabled'  => 'isModEnabled("creditmanager")',
			'perms'    => '$user->hasRight("creditmanager","read")',
			'target'   => '',
			'user'     => 0,
		);

		// Left menu - Admin section (separator)
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=creditmanager',
			'type'     => 'left',
			'titre'    => 'CreditManagerSetup',
			'prefix'   => 'fas fa-cog fa-fw paddingright pictofixedwidth',
			'mainmenu' => 'creditmanager',
			'leftmenu' => 'creditmanager_admin',
			'url'      => '/custom/creditmanager/admin/credit_types.php',
			'langs'    => 'creditmanager@creditmanager',
			'position' => 1090,
			'enabled'  => 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","creditmanager_admin") || $user->hasRight("creditmanager","credit_types_manage") || $user->hasRight("creditmanager","attribution_manage"))',
			'perms'    => '1',
			'target'   => '',
			'user'     => 0,
		);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/custom/creditmanager/sql/', '');
		if ($result < 0) {
			return -1;
		}

		// Backward compatibility for existing installs that predate timesheet linkage columns.
		if ($this->addCreditsMovementsColumns() < 0) {
			return -1;
		}

		if ($this->upgradeTimesheetMetadataSchema() < 0) {
			return -1;
		}

		// Import data from legacy core columns when upgrading, without altering Dolibarr core.
		if ($this->migrateLegacyElementTimeCreditData() < 0) {
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
		creditmanagerEnsureLeftMenuFlat($this->db, false);

		$initResult = $this->_init(array(), $options);
		if ($initResult > 0) {
			$this->ensureDefaultUserGroups();
		}

		return $initResult;
	}

	/**
	 * Create and (re)apply default Credit Manager groups.
	 *
	 * @return void
	 */
	private function ensureDefaultUserGroups()
	{
		global $conf;
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

		$rightIds = $this->getCreditManagerRightIds();
		if (empty($rightIds)) {
			return;
		}

		$groupDefs = array(
			array(
				'name' => $langs->trans('CreditManagerGroupAdminName'),
				'note' => $langs->trans('CreditManagerGroupAdminDesc'),
				'rights' => array_keys($rightIds),
			),
			array(
				'name' => $langs->trans('CreditManagerGroupFinanceName'),
				'note' => $langs->trans('CreditManagerGroupFinanceDesc'),
				'rights' => array('read', 'credit_types_manage', 'attribution_manage', 'timesheet_manual_debit', 'reports_export'),
			),
			array(
				'name' => $langs->trans('CreditManagerGroupPmName'),
				'note' => $langs->trans('CreditManagerGroupPmDesc'),
				'rights' => array('read', 'timesheet_approve', 'timesheet_manual_debit'),
			),
			array(
				'name' => $langs->trans('CreditManagerGroupStaffName'),
				'note' => $langs->trans('CreditManagerGroupStaffDesc'),
				'rights' => array('read'),
			),
			array(
				'name' => $langs->trans('CreditManagerGroupClientName'),
				'note' => $langs->trans('CreditManagerGroupClientDesc'),
				'rights' => array('creditmanager_client', 'client_portal_read'),
			),
		);

		foreach ($groupDefs as $groupDef) {
			$group = new UserGroup($this->db);
			$fetched = $group->fetch(0, $groupDef['name']);
			if ($fetched <= 0) {
				$group->name = $groupDef['name'];
				$group->note = $groupDef['note'];
				$group->entity = $conf->entity;
				$created = $group->create();
				if ($created <= 0) {
					continue;
				}
			}

			$group->delrights(0, 'creditmanager');
			foreach ($groupDef['rights'] as $rightCode) {
				if (isset($rightIds[$rightCode])) {
					$group->addrights($rightIds[$rightCode]);
				}
			}
		}
	}

	/**
	 * Return right code => right id for Credit Manager.
	 *
	 * @return array<string,int>
	 */
	private function getCreditManagerRightIds()
	{
		global $conf;

		$ids = array();
		$sql = "SELECT id, perms";
		$sql .= " FROM ".$this->db->prefix()."rights_def";
		$sql .= " WHERE module = 'creditmanager'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $ids;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$ids[$obj->perms] = (int) $obj->id;
		}
		$this->db->free($resql);

		return $ids;
	}

	/**
	 * Import legacy Credit Manager fields formerly added to llx_element_time.
	 *
	 * The legacy columns are deliberately left untouched: removing a core
	 * column is an administrator/database migration decision.
	 *
	 * @return int 1 when migration/backfill succeeds, <0 on error
	 */
	private function migrateLegacyElementTimeCreditData()
	{
		$table = MAIN_DB_PREFIX.'element_time';
		$hasLegacyColumns = true;
		foreach (array('fk_credit_type', 'credit_status') as $col) {
			$res = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE '".$this->db->escape($col)."'");
			if (!$res || $this->db->num_rows($res) === 0) {
				$hasLegacyColumns = false;
			}
			if ($res) {
				$this->db->free($res);
			}
		}
		if (!$hasLegacyColumns) {
			return $this->backfillTimesheetApprovalDates();
		}

		$approvalExpression = "NULL";
		$resApproval = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'credit_approval_date'");
		if ($resApproval && $this->db->num_rows($resApproval) > 0) {
			$approvalExpression = "et.credit_approval_date";
		}
		if ($resApproval) {
			$this->db->free($resApproval);
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."credits_status_types_and_timesheets";
		$sql .= " (fk_element_time, fk_credits_status, fk_credits_types, date_creation, approval_date)";
		$sql .= " SELECT et.rowid, COALESCE(cs.rowid, draft_status.rowid), NULLIF(et.fk_credit_type, 0), NOW(), ".$approvalExpression;
		$sql .= " FROM ".$table." as et";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."credits_status as cs";
		$sql .= " ON UPPER(cs.status_name) = UPPER(COALESCE(NULLIF(TRIM(et.credit_status), ''), 'DRAFT'))";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."credits_status as draft_status";
		$sql .= " ON UPPER(draft_status.status_name) = 'DRAFT'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."credits_status_types_and_timesheets as rel";
		$sql .= " ON rel.fk_element_time = et.rowid";
		$sql .= " WHERE rel.rowid IS NULL";
		$sql .= " AND (et.fk_credit_type > 0 OR TRIM(COALESCE(et.credit_status, '')) <> '')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if ($approvalExpression !== "NULL") {
			$sqlApproval = "UPDATE ".MAIN_DB_PREFIX."credits_status_types_and_timesheets as rel";
			$sqlApproval .= " INNER JOIN ".$table." as et ON et.rowid = rel.fk_element_time";
			$sqlApproval .= " SET rel.approval_date = et.credit_approval_date";
			$sqlApproval .= " WHERE rel.approval_date IS NULL AND et.credit_approval_date IS NOT NULL";
			if (!$this->db->query($sqlApproval)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		$resReference = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'credit_debit_reference'");
		$hasLegacyReference = $resReference && $this->db->num_rows($resReference) > 0;
		if ($resReference) {
			$this->db->free($resReference);
		}
		if ($hasLegacyReference) {
			$sqlReference = "UPDATE ".MAIN_DB_PREFIX."credits_movements as m";
			$sqlReference .= " INNER JOIN (SELECT MAX(rowid) as movement_id";
			$sqlReference .= " FROM ".MAIN_DB_PREFIX."credits_movements";
			$sqlReference .= " WHERE type_movement = 'DEBIT'";
			$sqlReference .= " GROUP BY COALESCE(fk_element_time, fk_timesheet)) as latest_debit";
			$sqlReference .= " ON latest_debit.movement_id = m.rowid";
			$sqlReference .= " INNER JOIN ".$table." as et ON et.rowid = COALESCE(m.fk_element_time, m.fk_timesheet)";
			$sqlReference .= " SET m.reference = et.credit_debit_reference";
			$sqlReference .= " WHERE (m.reference IS NULL OR m.reference = '')";
			$sqlReference .= " AND et.credit_debit_reference IS NOT NULL AND et.credit_debit_reference <> ''";
			if (!$this->db->query($sqlReference)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return $this->backfillTimesheetApprovalDates();
	}

	/**
	 * Give pre-upgrade approved rows a stable fallback approval date.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	private function backfillTimesheetApprovalDates()
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."credits_status_types_and_timesheets as rel";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_status as cs ON cs.rowid = rel.fk_credits_status";
		$sql .= " SET rel.approval_date = rel.tms";
		$sql .= " WHERE rel.approval_date IS NULL";
		$sql .= " AND UPPER(cs.status_name) IN ('APPROVED', 'DEBITED')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Upgrade the module-owned timesheet metadata table on existing installs.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	private function upgradeTimesheetMetadataSchema()
	{
		$table = MAIN_DB_PREFIX.'credits_status_types_and_timesheets';
		$res = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'approval_date'");
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($res) === 0 && !$this->db->query("ALTER TABLE ".$table." ADD COLUMN approval_date DATETIME NULL")) {
			$this->db->free($res);
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->db->free($res);

		$sqlDedupe = "DELETE old_rel FROM ".$table." as old_rel";
		$sqlDedupe .= " INNER JOIN ".$table." as new_rel";
		$sqlDedupe .= " ON new_rel.fk_element_time = old_rel.fk_element_time";
		$sqlDedupe .= " AND new_rel.rowid > old_rel.rowid";
		if (!$this->db->query($sqlDedupe)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$indexes = array(
			'uk_credits_status_timesheet' => "CREATE UNIQUE INDEX uk_credits_status_timesheet ON ".$table." (fk_element_time)",
			'idx_credits_status_type' => "CREATE INDEX idx_credits_status_type ON ".$table." (fk_credits_types)",
			'idx_credits_status_status' => "CREATE INDEX idx_credits_status_status ON ".$table." (fk_credits_status)",
		);
		foreach ($indexes as $name => $createSql) {
			$resIndex = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = '".$this->db->escape($name)."'");
			if (!$resIndex) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$exists = $this->db->num_rows($resIndex) > 0;
			$this->db->free($resIndex);
			if (!$exists && !$this->db->query($createSql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Upgrade module-owned movement columns and indexes.
	 *
	 * @return int 1 if OK, <0 on error
	 */
	private function addCreditsMovementsColumns()
	{
		$table = MAIN_DB_PREFIX.'credits_movements';
		$cols = array(
			'fk_element_time' => "ALTER TABLE ".$table." ADD COLUMN fk_element_time INTEGER NULL",
			'timesheet_elementtype' => "ALTER TABLE ".$table." ADD COLUMN timesheet_elementtype VARCHAR(32) NULL",
			'reference' => "ALTER TABLE ".$table." ADD COLUMN reference VARCHAR(50) NULL",
		);

		foreach ($cols as $col => $sql) {
			$res = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE '".$this->db->escape($col)."'");
			if ($res && $this->db->num_rows($res) == 0) {
				if (!$this->db->query($sql)) {
					$this->db->free($res);
					$this->error = $this->db->lasterror();
					return -1;
				}
			}
			if (!$res) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$this->db->free($res);
		}

		$resIndex = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = 'uk_credits_movements_reference'");
		if (!$resIndex) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$exists = $this->db->num_rows($resIndex) > 0;
		$this->db->free($resIndex);
		if (!$exists && !$this->db->query("CREATE UNIQUE INDEX uk_credits_movements_reference ON ".$table." (entity, reference)")) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

}
