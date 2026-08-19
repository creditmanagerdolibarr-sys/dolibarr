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
 */

/**
 *	\file       htdocs/custom/creditmanager/lib/creditmanager.lib.php
 *	\ingroup    creditmanager
 *	\brief      Library of functions for module Credit Manager
 */

/**
 * Configured decimal precision for credit amounts (0-5, default 2).
 *
 * @return int
 */
function creditmanagerGetDecimalPrecision()
{
	$precision = (int) getDolGlobalString('CREDITMANAGER_DECIMAL_PRECISION', '2');
	if ($precision < 0) {
		$precision = 0;
	}
	if ($precision > 5) {
		$precision = 5;
	}
	return $precision;
}

/**
 * Remove trailing decimal zeros from a formatted amount (30,00 → 30).
 *
 * @param string $formatted
 * @return string
 */
function creditmanagerTrimAmountDisplayDecimals($formatted)
{
	global $langs;

	if ($formatted === '' || !is_string($formatted)) {
		return (string) $formatted;
	}

	$decSep = ',';
	if ($langs->transnoentitiesnoconv('SeparatorDecimal') != 'SeparatorDecimal') {
		$decSep = $langs->transnoentitiesnoconv('SeparatorDecimal');
	}
	if ($decSep === '' || strpos($formatted, $decSep) === false) {
		return $formatted;
	}

	$suffix = '';
	if (preg_match('/(\.\.\.)$/', $formatted, $m)) {
		$suffix = $m[1];
		$formatted = substr($formatted, 0, -strlen($suffix));
	}

	$pos = strrpos($formatted, $decSep);
	if ($pos === false) {
		return $formatted.$suffix;
	}

	$intPart = substr($formatted, 0, $pos);
	$decPart = rtrim(substr($formatted, $pos + strlen($decSep)), '0');
	if ($decPart === '') {
		return $intPart.$suffix;
	}

	return $intPart.$decSep.$decPart.$suffix;
}

/**
 * Format credit amount/hours for HTML display.
 *
 * @param float|string|null $amount
 * @param int               $form 1 = input-friendly format
 * @return string
 */
function creditmanagerFormatAmount($amount, $form = 0)
{
	$dec = creditmanagerGetDecimalPrecision();
	return creditmanagerTrimAmountDisplayDecimals(price($amount, $form, '', 1, $dec, $dec));
}

/**
 * Round credit amount for exports and machine-readable output.
 *
 * @param float|string|null $amount
 * @return float|string
 */
function creditmanagerFormatAmountNum($amount)
{
	if ($amount === '' || $amount === null) {
		return '';
	}
	$rounded = round((float) $amount, creditmanagerGetDecimalPrecision());
	if (abs($rounded - round($rounded)) < 0.0000001) {
		return (int) round($rounded);
	}
	return $rounded;
}

/**
 * Force all Credit Manager left menu entries to the same level as Dashboard (Menubase::menuLeftCharger adds them with level 0 when fk_menu=-1 and fk_leftmenu is empty).
 * If fk_menu points to another left entry, Eldy shows those lines as level > 0 and omits pictos.
 *
 * @param DoliDB $db             Database handler
 * @param bool   $useSessionCache  If true, run at most once per session (for web pages)
 * @return void
 */
function creditmanagerEnsureLeftMenuFlat(DoliDB $db, $useSessionCache = true)
{
	global $conf;

	if (!isModEnabled('creditmanager')) {
		return;
	}

	if ($useSessionCache && !empty($_SESSION['creditmanager_menu_flat_ok'])) {
		return;
	}

	$module = 'creditmanager';
	$sql = "UPDATE ".$db->prefix()."menu SET";
	$sql .= " fk_menu = -1,";
	$sql .= " fk_mainmenu = 'creditmanager',";
	$sql .= " fk_leftmenu = NULL";
	$sql .= " WHERE module = '".$db->escape($module)."'";
	$sql .= " AND type = 'left'";
	$sql .= " AND mainmenu = 'creditmanager'";
	$sql .= " AND entity IN (0, ".((int) $conf->entity).")";

	$db->query($sql);

	if ($useSessionCache) {
		$_SESSION['creditmanager_menu_flat_ok'] = 1;
	}
}

/**
 *  Prepare admin pages header (tabs)
 *
 *  @return	array		Array of tabs
 */
function creditmanagerAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("creditmanager@creditmanager");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/credit_types.php", 1);
	$head[$h][1] = $langs->trans("CreditTypes");
	$head[$h][2] = 'credit_types';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/attribution.php", 1);
	$head[$h][1] = $langs->trans("CreditAttribution");
	$head[$h][2] = 'attribution';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/tools.php", 1);
	$head[$h][1] = $langs->trans("CreditManagerTools");
	$head[$h][2] = 'tools';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'creditmanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'creditmanager', 'remove');

	return $head;
}

/**
 * Read access for internal users (admin/finance/pm/staff).
 * Portal users are handled separately with creditmanagerCanReadClientPortal().
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanReadModule($user)
{
	return !empty($user->rights->creditmanager->read);
}

/**
 * Access to admin setup/maintenance screens.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanManageAdmin($user)
{
	return !empty($user->admin) || !empty($user->rights->creditmanager->creditmanager_admin);
}

/**
 * Access to credit types management (finance/admin).
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanManageCreditTypes($user)
{
	return creditmanagerCanManageAdmin($user) || !empty($user->rights->creditmanager->credit_types_manage);
}

/**
 * Access to credit attribution management (finance/admin).
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanManageAttributions($user)
{
	return creditmanagerCanManageAdmin($user) || !empty($user->rights->creditmanager->attribution_manage);
}

/**
 * Access to manual timesheet debit (pm/admin).
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanManualDebit($user)
{
	return creditmanagerCanManageAdmin($user) || !empty($user->rights->creditmanager->timesheet_manual_debit);
}

/**
 * Export permission for module reports/lists.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanExport($user)
{
	return creditmanagerCanManageAdmin($user) || !empty($user->rights->creditmanager->reports_export);
}

/**
 * Portal read access for users linked to a thirdparty.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanReadClientPortal($user)
{
	return !empty($user->rights->creditmanager->client_portal_read) || !empty($user->rights->creditmanager->creditmanager_client);
}

/**
 * User group names for current user.
 *
 * @param DoliDB $db
 * @param User $user
 * @return array<int,string>
 */
function creditmanagerGetUserGroupNames(DoliDB $db, $user)
{
	require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';

	$names = array();
	$usergroup = new UserGroup($db);
	$groupslist = $usergroup->listGroupsForUser($user->id, false);
	if (is_array($groupslist)) {
		foreach ($groupslist as $group) {
			if (!empty($group->nom)) {
				$names[] = $group->nom;
			}
		}
	}
	return $names;
}

/**
 * Staff-only users have read but no elevated creditmanager permissions.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerIsStaffOnlyUser($user)
{
	if (!creditmanagerCanReadModule($user)) {
		return false;
	}
	if (creditmanagerCanManageAdmin($user)) {
		return false;
	}
	if (creditmanagerCanManageAttributions($user)) {
		return false;
	}
	if (creditmanagerCanManageCreditTypes($user)) {
		return false;
	}
	if (creditmanagerCanManualDebit($user)) {
		return false;
	}
	if (!empty($user->rights->creditmanager->timesheet_approve)) {
		return false;
	}
	if (creditmanagerCanExport($user)) {
		return false;
	}
	if (creditmanagerIsClientPortalUser($user)) {
		return false;
	}
	return true;
}

/**
 * External client portal user linked to a thirdparty.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerIsClientPortalUser($user)
{
	return !empty($user->socid) && creditmanagerCanReadClientPortal($user);
}

/**
 * Whether client portal pages are enabled and the user may open them.
 * Portal users need client_portal_read (+ socid). Admins can open for testing when a socid is provided.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanAccessClientPortalPages($user)
{
	if (!isModEnabled('creditmanager')) {
		return false;
	}
	if (!getDolGlobalInt('CREDITMANAGER_ENABLE_CLIENT_PORTAL', 0)) {
		return false;
	}
	if (creditmanagerIsClientPortalUser($user)) {
		return true;
	}
	return creditmanagerCanManageAdmin($user);
}

/**
 * HMAC signing key for temporary client balance share links.
 *
 * @return string
 */
function creditmanagerGetShareSigningKey()
{
	global $conf;

	$salt = getDolGlobalString('MAIN_SECURITY_SALT', '');
	if ($salt !== '') {
		return $salt;
	}
	if (!empty($conf->file->instance_unique_id)) {
		return (string) $conf->file->instance_unique_id;
	}
	return 'creditmanager-share';
}

/**
 * Build a temporary share token for a client balance snapshot.
 *
 * @param int $socid
 * @param int $ttlHours
 * @return string
 */
function creditmanagerCreateBalanceShareToken($socid, $ttlHours = 48)
{
	$socid = (int) $socid;
	$ttlHours = max(1, (int) $ttlHours);
	$expiry = dol_now() + ($ttlHours * 3600);
	$payload = $socid.'|'.$expiry;
	$sig = hash_hmac('sha256', $payload, creditmanagerGetShareSigningKey());
	return rtrim(strtr(base64_encode($payload.'|'.$sig), '+/', '-_'), '=');
}

/**
 * Validate a share token and return socid, or 0 if invalid/expired.
 *
 * @param string $token
 * @return int
 */
function creditmanagerValidateBalanceShareToken($token)
{
	$token = trim((string) $token);
	if ($token === '') {
		return 0;
	}
	$raw = base64_decode(strtr($token, '-_', '+/'), true);
	if ($raw === false) {
		return 0;
	}
	$parts = explode('|', $raw);
	if (count($parts) !== 3) {
		return 0;
	}
	$socid = (int) $parts[0];
	$expiry = (int) $parts[1];
	$sig = (string) $parts[2];
	if ($socid <= 0 || $expiry < dol_now()) {
		return 0;
	}
	$expected = hash_hmac('sha256', $socid.'|'.$expiry, creditmanagerGetShareSigningKey());
	if (!hash_equals($expected, $sig)) {
		return 0;
	}
	return $socid;
}

/**
 * Status badge from remaining percent (critical <5, warning <20, else safe).
 *
 * @param float|null $percentRemaining
 * @param float      $balance
 * @return string safe|warning|critical
 */
function creditmanagerClientBalanceStatus($percentRemaining, $balance = 0.0)
{
	$absThreshold = (float) getDolGlobalString('CREDITMANAGER_LOW_BALANCE_THRESHOLD', '10');
	if ($percentRemaining !== null) {
		if ($percentRemaining < 5) {
			return 'critical';
		}
		if ($percentRemaining < 20) {
			return 'warning';
		}
		return 'safe';
	}
	if ($absThreshold > 0 && (float) $balance <= $absThreshold) {
		return ((float) $balance <= ($absThreshold / 2)) ? 'critical' : 'warning';
	}
	return 'safe';
}

/**
 * PM / admin can approve submitted timesheets.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanApproveTimesheets($user)
{
	return creditmanagerCanManageAdmin($user) || !empty($user->rights->creditmanager->timesheet_approve);
}

/**
 * Restrict timesheet lists to PM projects / client soc.
 *
 * @param DoliDB $db
 * @param User $user
 * @param string $projectAlias
 * @return string
 */
function creditmanagerTimesheetScopeProjectWhereSql(DoliDB $db, $user, $projectAlias = 'pr')
{
	$scope = creditmanagerGetReportScope($db, $user);
	if (empty($scope['type']) || $scope['type'] === 'all') {
		return '';
	}
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		return ' AND '.$projectAlias.'.fk_soc = '.((int) $scope['fk_soc']);
	}
	if ($scope['type'] === 'pm') {
		$projectIds = !empty($scope['project_ids']) ? $scope['project_ids'] : array();
		if (empty($projectIds)) {
			return ' AND 1 = 0';
		}
		return ' AND '.$projectAlias.'.rowid IN ('.implode(',', array_map('intval', $projectIds)).')';
	}
	return ' AND 1 = 0';
}

/**
 * Access to advanced report pages (consumption, forecast, budget vs real).
 * Staff is excluded.
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanAccessReports($user)
{
	if (creditmanagerIsStaffOnlyUser($user)) {
		return false;
	}
	if (creditmanagerCanManageAdmin($user)) {
		return true;
	}
	if (creditmanagerCanManageAttributions($user) || creditmanagerCanExport($user)) {
		return true;
	}
	if (creditmanagerCanManualDebit($user) || !empty($user->rights->creditmanager->timesheet_approve)) {
		return true;
	}
	if (creditmanagerIsClientPortalUser($user)) {
		return true;
	}
	return false;
}

/**
 * Access to balances, movements and other financial client data (not staff).
 *
 * @param User $user
 * @return bool
 */
function creditmanagerCanViewFinancialData($user)
{
	return creditmanagerCanAccessReports($user);
}

/**
 * Menu enabled expression for financial pages (balances, movements, reports).
 * Includes portal rights (used by some shared checks / legacy menus).
 *
 * @return string
 */
function creditmanagerFinancialMenuEnabledExpr()
{
	return 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","creditmanager_admin") || $user->hasRight("creditmanager","reports_export") || $user->hasRight("creditmanager","attribution_manage") || $user->hasRight("creditmanager","timesheet_approve") || $user->hasRight("creditmanager","timesheet_manual_debit") || $user->hasRight("creditmanager","client_portal_read") || $user->hasRight("creditmanager","creditmanager_client"))';
}

/**
 * Left-menu enabled expression for internal financial pages only (not portal-only users).
 *
 * @return string
 */
function creditmanagerInternalFinancialMenuEnabledExpr()
{
	return 'isModEnabled("creditmanager") && ($user->hasRight("creditmanager","creditmanager_admin") || $user->hasRight("creditmanager","reports_export") || $user->hasRight("creditmanager","attribution_manage") || $user->hasRight("creditmanager","timesheet_approve") || $user->hasRight("creditmanager","timesheet_manual_debit"))';
}

/**
 * Validate / force client filter from financial scope (list pages).
 *
 * @param array $scope
 * @param int   $search_socid
 * @param DoliDB $db
 * @return void
 */
function creditmanagerValidateFinancialScopeSocId($scope, &$search_socid, DoliDB $db)
{
	if (empty($scope['type']) || $scope['type'] === 'all') {
		return;
	}
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		$search_socid = (int) $scope['fk_soc'];
		return;
	}
	if ($scope['type'] === 'pm') {
		if ($search_socid > 0 && !creditmanagerReportCanAccessSoc($scope, $search_socid, $db)) {
			accessforbidden();
		}
		return;
	}
	accessforbidden();
}

/**
 * Third-party select limited to financial scope (PM / client).
 *
 * @param Form   $form
 * @param DoliDB $db
 * @param array  $scope
 * @param int    $selected
 * @param string $htmlname
 * @param string $entitySoc
 * @return void
 */
function creditmanagerPrintScopedCompanySelect($form, DoliDB $db, $scope, $selected, $htmlname, $entitySoc)
{
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	if (empty($scope['type']) || $scope['type'] === 'all') {
		print $form->select_company($selected, $htmlname, '', 1, 0, 0, array(), 0, 'minwidth200', '', 0, 0, array(), false);
		return;
	}

	$allowed = creditmanagerGetReportScopeSocIdsForSelect($db, $scope, $entitySoc);
	if ($scope['type'] === 'client' && count($allowed) === 1) {
		$soc = new Societe($db);
		if ($soc->fetch($allowed[0]) > 0) {
			print dol_escape_htmltag($soc->name);
			print '<input type="hidden" name="'.dol_escape_htmltag($htmlname).'" value="'.((int) $allowed[0]).'">';
		}
		return;
	}

	print '<select name="'.dol_escape_htmltag($htmlname).'" class="flat minwidth200">';
	print '<option value="0"></option>';
	if (!empty($allowed)) {
		$res = $db->query('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe WHERE rowid IN ('.implode(',', array_map('intval', $allowed)).') ORDER BY nom');
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$sel = ((int) $selected === (int) $obj->rowid) ? ' selected' : '';
				print '<option value="'.((int) $obj->rowid).'"'.$sel.'>'.dol_escape_htmltag($obj->nom).'</option>';
			}
			$db->free($res);
		}
	}
	print '</select>';
}

/**
 * Report data scope: all (admin/finance), pm (projects), client (own soc).
 *
 * @param DoliDB $db
 * @param User $user
 * @return array{type:string,fk_soc?:int,project_ids?:array<int,int>}
 */
function creditmanagerGetReportScope(DoliDB $db, $user)
{
	if (creditmanagerCanManageAdmin($user) || creditmanagerCanManageAttributions($user) || creditmanagerCanExport($user)) {
		return array('type' => 'all');
	}
	if (creditmanagerIsClientPortalUser($user)) {
		return array('type' => 'client', 'fk_soc' => (int) $user->socid);
	}
	if (creditmanagerCanManualDebit($user) || !empty($user->rights->creditmanager->timesheet_approve)) {
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$projectstatic = new Project($db);
		$list = $projectstatic->getProjectsAuthorizedForUser($user, 0, 1);
		$ids = array();
		if ($list !== '' && $list !== '0') {
			foreach (explode(',', $list) as $id) {
				$i = (int) $id;
				if ($i > 0) {
					$ids[] = $i;
				}
			}
		}
		return array('type' => 'pm', 'project_ids' => $ids);
	}
	return array('type' => 'none');
}

/**
 * SQL fragment restricting movements to report scope.
 *
 * @param array $scope
 * @param string $movementAlias
 * @param string $projectAlias
 * @return string
 */
function creditmanagerReportScopeWhereSql($scope, $movementAlias = 'm', $projectAlias = 'pr')
{
	if (empty($scope['type']) || $scope['type'] === 'all') {
		return '';
	}
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		return ' AND '.$movementAlias.'.fk_soc = '.((int) $scope['fk_soc']);
	}
	if ($scope['type'] === 'pm') {
		$projectIds = !empty($scope['project_ids']) ? $scope['project_ids'] : array();
		if (empty($projectIds)) {
			return ' AND 1 = 0';
		}
		$in = implode(',', array_map('intval', $projectIds));
		$sql = ' AND (';
		$sql .= $projectAlias.'.rowid IN ('.$in.')';
		$sql .= ' OR '.$movementAlias.'.fk_soc IN (';
		$sql .= ' SELECT DISTINCT pr_scope.fk_soc FROM '.MAIN_DB_PREFIX.'projet as pr_scope';
		$sql .= ' WHERE pr_scope.rowid IN ('.$in.') AND pr_scope.fk_soc IS NOT NULL AND pr_scope.fk_soc > 0';
		$sql .= ' )';
		$sql .= ' )';
		return $sql;
	}
	return ' AND 1 = 0';
}

/**
 * SQL fragment for balance-based reports (forecast).
 *
 * @param array $scope
 * @param string $balanceAlias
 * @return string
 */
function creditmanagerReportScopeBalanceWhereSql($scope, $balanceAlias = 'b')
{
	if (empty($scope['type']) || $scope['type'] === 'all') {
		return '';
	}
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		return ' AND '.$balanceAlias.'.fk_soc = '.((int) $scope['fk_soc']);
	}
	if ($scope['type'] === 'pm') {
		$projectIds = !empty($scope['project_ids']) ? $scope['project_ids'] : array();
		if (empty($projectIds)) {
			return ' AND 1 = 0';
		}
		$in = implode(',', array_map('intval', $projectIds));
		return ' AND '.$balanceAlias.'.fk_soc IN (SELECT DISTINCT pr_scope.fk_soc FROM '.MAIN_DB_PREFIX.'projet as pr_scope WHERE pr_scope.rowid IN ('.$in.') AND pr_scope.fk_soc IS NOT NULL AND pr_scope.fk_soc > 0)';
	}
	return ' AND 1 = 0';
}

/**
 * Apply scope to user-selected client/project filters.
 *
 * @param array        $scope
 * @param array<int>   $search_socids
 * @param array<int>   $search_projectids
 * @param DoliDB|null  $db
 * @return void
 */
function creditmanagerApplyReportScopeToFilters($scope, &$search_socids, &$search_projectids, DoliDB $db = null)
{
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		$search_socids = array((int) $scope['fk_soc']);
		if ($db) {
			$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE fk_soc = '.((int) $scope['fk_soc']));
			if ($res) {
				$search_projectids = array();
				while ($obj = $db->fetch_object($res)) {
					$search_projectids[] = (int) $obj->rowid;
				}
				$db->free($res);
			}
		}
		return;
	}
	if ($scope['type'] === 'pm' && !empty($scope['project_ids'])) {
		$allowedProjects = $scope['project_ids'];
		if (!empty($search_projectids)) {
			$search_projectids = array_values(array_intersect($search_projectids, $allowedProjects));
		} else {
			$search_projectids = $allowedProjects;
		}
		if ($db && empty($search_socids)) {
			$in = implode(',', array_map('intval', $allowedProjects));
			$res = $db->query('SELECT DISTINCT fk_soc FROM '.MAIN_DB_PREFIX.'projet WHERE rowid IN ('.$in.') AND fk_soc IS NOT NULL AND fk_soc > 0');
			if ($res) {
				while ($obj = $db->fetch_object($res)) {
					$search_socids[] = (int) $obj->fk_soc;
				}
				$db->free($res);
				$search_socids = array_values(array_unique($search_socids));
			}
		} elseif (!empty($search_socids) && $db) {
			$in = implode(',', array_map('intval', $allowedProjects));
			$res = $db->query('SELECT DISTINCT fk_soc FROM '.MAIN_DB_PREFIX.'projet WHERE rowid IN ('.$in.') AND fk_soc IN ('.implode(',', array_map('intval', $search_socids)).')');
			$allowedSoc = array();
			if ($res) {
				while ($obj = $db->fetch_object($res)) {
					$allowedSoc[] = (int) $obj->fk_soc;
				}
				$db->free($res);
			}
			$search_socids = $allowedSoc;
		}
	}
}

/**
 * Allowed project ids for filter dropdowns.
 *
 * @param DoliDB $db
 * @param array  $scope
 * @param string $entityProject
 * @return array<int,int>
 */
function creditmanagerGetReportScopeProjectIdsForSelect(DoliDB $db, $scope, $entityProject)
{
	if ($scope['type'] === 'all') {
		$ids = array();
		$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE entity IN ('.$entityProject.') ORDER BY ref');
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$ids[] = (int) $obj->rowid;
			}
			$db->free($res);
		}
		return $ids;
	}
	if ($scope['type'] === 'pm') {
		return !empty($scope['project_ids']) ? $scope['project_ids'] : array();
	}
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		$ids = array();
		$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE entity IN ('.$entityProject.') AND fk_soc = '.((int) $scope['fk_soc']).' ORDER BY ref');
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$ids[] = (int) $obj->rowid;
			}
			$db->free($res);
		}
		return $ids;
	}
	return array();
}

/**
 * Allowed client ids for filter dropdowns.
 *
 * @param DoliDB $db
 * @param array  $scope
 * @param string $entitySoc
 * @return array<int,int>
 */
function creditmanagerGetReportScopeSocIdsForSelect(DoliDB $db, $scope, $entitySoc)
{
	if ($scope['type'] === 'client' && !empty($scope['fk_soc'])) {
		return array((int) $scope['fk_soc']);
	}
	if ($scope['type'] === 'pm' && !empty($scope['project_ids'])) {
		$in = implode(',', array_map('intval', $scope['project_ids']));
		$ids = array();
		$res = $db->query('SELECT DISTINCT fk_soc FROM '.MAIN_DB_PREFIX.'projet WHERE rowid IN ('.$in.') AND fk_soc IS NOT NULL AND fk_soc > 0');
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$ids[] = (int) $obj->fk_soc;
			}
			$db->free($res);
		}
		return $ids;
	}
	$ids = array();
	$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE entity IN ('.$entitySoc.') AND client IN (1,2,3) ORDER BY nom');
	if ($res) {
		while ($obj = $db->fetch_object($res)) {
			$ids[] = (int) $obj->rowid;
		}
		$db->free($res);
	}
	return $ids;
}

/**
 * Project filter including attributions without element_time link.
 *
 * @param array<int> $projectIds
 * @param string     $movementAlias
 * @param string     $projectAlias
 * @return string
 */
function creditmanagerReportProjectIdsWhereCondition($projectIds, $movementAlias = 'm', $projectAlias = 'pr')
{
	if (empty($projectIds)) {
		return '';
	}
	$in = implode(',', array_map('intval', $projectIds));
	$sql = '('.$projectAlias.'.rowid IN ('.$in.')';
	$sql .= ' OR '.$movementAlias.'.fk_soc IN (';
	$sql .= ' SELECT DISTINCT pr_f.fk_soc FROM '.MAIN_DB_PREFIX.'projet as pr_f';
	$sql .= ' WHERE pr_f.rowid IN ('.$in.') AND pr_f.fk_soc IS NOT NULL AND pr_f.fk_soc > 0';
	$sql .= ' ))';
	return $sql;
}

/**
 * Check if user can access a client row in reports.
 *
 * @param array $scope
 * @param int   $fk_soc
 * @param DoliDB|null $db
 * @return bool
 */
function creditmanagerReportCanAccessSoc($scope, $fk_soc, DoliDB $db = null)
{
	$fk_soc = (int) $fk_soc;
	if ($fk_soc <= 0) {
		return false;
	}
	if (empty($scope['type']) || $scope['type'] === 'all') {
		return true;
	}
	if ($scope['type'] === 'client') {
		return $fk_soc === (int) ($scope['fk_soc'] ?? 0);
	}
	if ($scope['type'] === 'pm' && $db && !empty($scope['project_ids'])) {
		$in = implode(',', array_map('intval', $scope['project_ids']));
		$res = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE rowid IN ('.$in.') AND fk_soc = '.$fk_soc.' LIMIT 1');
		if ($res) {
			$ok = ($db->num_rows($res) > 0);
			$db->free($res);
			return $ok;
		}
	}
	return false;
}

/**
 * Menu enabled expression for report pages.
 *
 * @return string
 */
function creditmanagerReportsMenuEnabledExpr()
{
	return creditmanagerFinancialMenuEnabledExpr();
}
