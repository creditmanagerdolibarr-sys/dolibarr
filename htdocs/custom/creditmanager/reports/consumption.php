<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr */

/**
 * \file        htdocs/custom/creditmanager/reports/consumption.php
 * \ingroup     creditmanager
 * \brief       Advanced consumption report with filters, charts and exports.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditReport.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditGraph.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditExport.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

creditmanagerEnsureLeftMenuFlat($db);
$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'projects', 'users', 'other'));

if (!creditmanagerCanAccessReports($user)) {
	accessforbidden();
}

$reportScope = creditmanagerGetReportScope($db, $user);

$form = new Form($db);
$report = new CreditReport($db);
$graph = new CreditGraph();
$exporter = new CreditExport();

$token = newToken();
$action = GETPOST('action', 'aZ09');
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : (int) $conf->liste_limit;
if ($limit <= 0) {
	$limit = 25;
}
$page = max(0, GETPOSTINT('page'));
$offset = $page * $limit;
$searchText = trim(GETPOST('search_text', 'alphanohtml'));
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
if (empty($sortfield)) {
	$sortfield = 'm.date_movement';
}
if (empty($sortorder)) {
	$sortorder = 'DESC';
}

$preset = GETPOST('preset', 'aZ09');
$now = dol_now();
$date_start = dol_mktime(0, 0, 0, GETPOSTINT('date_start_month'), GETPOSTINT('date_start_day'), GETPOSTINT('date_start_year'));
$date_end = dol_mktime(23, 59, 59, GETPOSTINT('date_end_month'), GETPOSTINT('date_end_day'), GETPOSTINT('date_end_year'));

if (!$date_start || !$date_end || $preset !== '') {
	$yearNow = (int) dol_print_date($now, '%Y');
	$monthNow = (int) dol_print_date($now, '%m');
	if ($preset === '') {
		$preset = 'year';
	}
	if ($preset === 'month') {
		$date_start = dol_get_first_day($yearNow, $monthNow);
		$date_end = dol_get_last_day($yearNow, $monthNow);
	} elseif ($preset === 'quarter') {
		$qMonth = ((int) floor(($monthNow - 1) / 3) * 3) + 1;
		$date_start = dol_mktime(0, 0, 0, (int) $qMonth, 1, $yearNow);
		$date_end = dol_time_plus_duree($date_start, 3, 'm') - 1;
	} elseif ($preset === 'year') {
		$date_start = dol_get_first_day($yearNow, 1);
		$date_end = dol_get_last_day($yearNow, 12);
	} else {
		$date_start = dol_get_first_day($yearNow, $monthNow);
		$date_end = dol_get_last_day($yearNow, $monthNow);
	}
}

$search_socids = GETPOST('search_socids', 'array');
$search_typeids = GETPOST('search_typeids', 'array');
$search_projectids = GETPOST('search_projectids', 'array');
$search_userids = GETPOST('search_userids', 'array');
$group_by = GETPOST('group_by', 'array');
$movement_type = GETPOST('movement_type', 'aZ09');
$amount_min = GETPOST('amount_min', 'alphanohtml');
$amount_max = GETPOST('amount_max', 'alphanohtml');
$chart_type = GETPOST('chart_type', 'aZ09');
if (!in_array($chart_type, array('bar', 'line', 'pie'))) {
	$chart_type = 'bar';
}

$save_view = GETPOST('save_view', 'aZ09');
$view_name = trim(GETPOST('view_name', 'alphanohtml'));
$load_view = GETPOST('load_view', 'aZ09');
$delete_view = GETPOST('delete_view', 'aZ09');
$selected_view = trim(GETPOST('selected_view', 'alphanohtml'));

/**
 * Load saved views from llx_user_param for current user/entity.
 *
 * @param DoliDB $db
 * @param Conf $conf
 * @param User $user
 * @return array<string,string>
 */
function creditmanager_get_saved_consumption_views($db, $conf, $user)
{
	$list = array();
	$sql = "SELECT param, value";
	$sql .= " FROM ".MAIN_DB_PREFIX."user_param";
	$sql .= " WHERE fk_user = ".((int) $user->id);
	$sql .= " AND entity = ".((int) $conf->entity);
	$sql .= " AND param LIKE 'CREDITMANAGER_CONSUMPTION_VIEW\_%'";
	$sql .= " ORDER BY param ASC";
	$resql = $db->query($sql);
	if (!$resql) {
		return $list;
	}

	while ($obj = $db->fetch_object($resql)) {
		$key = (string) $obj->param;
		$name = preg_replace('/^CREDITMANAGER_CONSUMPTION_VIEW_/', '', $key);
		$label = trim(str_replace('_', ' ', (string) $name));
		$list[$key] = $label !== '' ? $label : $key;
	}
	$db->free($resql);
	return $list;
}

if ($delete_view === '1' && $selected_view !== '') {
	dol_set_user_param($db, $conf, $user, array($selected_view => ''));
	setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
	$selected_view = '';
}

if ($load_view === '1' && $selected_view !== '') {
	$sqlLoad = "SELECT value FROM ".MAIN_DB_PREFIX."user_param";
	$sqlLoad .= " WHERE fk_user = ".((int) $user->id);
	$sqlLoad .= " AND entity = ".((int) $conf->entity);
	$sqlLoad .= " AND param = '".$db->escape($selected_view)."'";
	$sqlLoad .= " LIMIT 1";
	$resLoad = $db->query($sqlLoad);
	if ($resLoad && ($objLoad = $db->fetch_object($resLoad))) {
		$payload = json_decode((string) $objLoad->value, true);
		if (is_array($payload)) {
			$preset = isset($payload['preset']) ? (string) $payload['preset'] : $preset;
			$date_start = !empty($payload['date_start']) ? (int) $payload['date_start'] : $date_start;
			$date_end = !empty($payload['date_end']) ? (int) $payload['date_end'] : $date_end;
			$search_socids = isset($payload['search_socids']) && is_array($payload['search_socids']) ? $payload['search_socids'] : $search_socids;
			$search_typeids = isset($payload['search_typeids']) && is_array($payload['search_typeids']) ? $payload['search_typeids'] : $search_typeids;
			$search_projectids = isset($payload['search_projectids']) && is_array($payload['search_projectids']) ? $payload['search_projectids'] : $search_projectids;
			$search_userids = isset($payload['search_userids']) && is_array($payload['search_userids']) ? $payload['search_userids'] : $search_userids;
			$group_by = isset($payload['group_by']) && is_array($payload['group_by']) ? $payload['group_by'] : $group_by;
			$movement_type = isset($payload['movement_type']) ? (string) $payload['movement_type'] : $movement_type;
			$amount_min = isset($payload['amount_min']) ? (string) $payload['amount_min'] : $amount_min;
			$amount_max = isset($payload['amount_max']) ? (string) $payload['amount_max'] : $amount_max;
			$chart_type = isset($payload['chart_type']) ? (string) $payload['chart_type'] : $chart_type;
			setEventMessages($langs->trans('RecordModified'), null, 'mesgs');
		}
		$db->free($resLoad);
	}
}

if ($save_view === '1' && $view_name !== '') {
	$prefValue = json_encode(array(
		'preset' => $preset,
		'date_start' => $date_start,
		'date_end' => $date_end,
		'search_socids' => $search_socids,
		'search_typeids' => $search_typeids,
		'search_projectids' => $search_projectids,
		'search_userids' => $search_userids,
		'group_by' => $group_by,
		'movement_type' => $movement_type,
		'amount_min' => $amount_min,
		'amount_max' => $amount_max,
		'chart_type' => $chart_type,
	));
	$paramName = 'CREDITMANAGER_CONSUMPTION_VIEW_'.preg_replace('/[^A-Za-z0-9_]/', '_', strtoupper($view_name));
	dol_set_user_param($db, $conf, $user, array($paramName => $prefValue));
	setEventMessages($langs->trans('RecordCreated'), null, 'mesgs');
	$selected_view = $paramName;
}

$savedViews = creditmanager_get_saved_consumption_views($db, $conf, $user);

$allowedGroups = array('client', 'project', 'credit_type', 'month', 'collaborator');
$group_by = array_values(array_intersect($allowedGroups, is_array($group_by) ? $group_by : array()));
if (empty($group_by)) {
	$group_by = array('client', 'credit_type', 'month');
}

$cleanIds = function ($arr) {
	if (!is_array($arr)) {
		return array();
	}
	$ids = array();
	foreach ($arr as $v) {
		$i = (int) $v;
		if ($i > 0) {
			$ids[] = $i;
		}
	}
	return array_values(array_unique($ids));
};

$search_socids = $cleanIds($search_socids);
$search_typeids = $cleanIds($search_typeids);
$search_projectids = $cleanIds($search_projectids);
$search_userids = $cleanIds($search_userids);

creditmanagerApplyReportScopeToFilters($reportScope, $search_socids, $search_projectids, $db);

$entityMovement = getEntity('credits_movement');
$entitySoc = getEntity('societe');
$entityType = getEntity('credits_type');
$entityProject = getEntity('project');
$entityUser = getEntity('user');

$where = array(
	"m.entity IN (".$entityMovement.")",
	"s.entity IN (".$entitySoc.")",
	"t.entity IN (".$entityType.")",
	"m.date_movement >= '".$db->idate($date_start)."'",
	"m.date_movement <= '".$db->idate($date_end)."'",
);

if (!empty($search_socids)) {
	$where[] = "m.fk_soc IN (".implode(',', $search_socids).")";
}
if (!empty($search_typeids)) {
	$where[] = "m.fk_credit_type IN (".implode(',', $search_typeids).")";
}
if (!empty($search_projectids)) {
	$where[] = creditmanagerReportProjectIdsWhereCondition($search_projectids, 'm', 'pr');
} elseif ($reportScope['type'] !== 'all') {
	$scopeCond = creditmanagerReportScopeWhereSql($reportScope, 'm', 'pr');
	if ($scopeCond !== '') {
		$scopeCond = preg_replace('/^\s*AND\s+/i', '', $scopeCond);
		$where[] = $scopeCond;
	}
}
if (!empty($search_userids)) {
	$where[] = "et.fk_user IN (".implode(',', $search_userids).")";
}
if ($movement_type === 'credit') {
	$where[] = "m.amount > 0";
} elseif ($movement_type === 'debit') {
	$where[] = "m.amount < 0";
}
if ($amount_min !== '' && is_numeric($amount_min)) {
	$where[] = "ABS(m.amount) >= ".((float) $amount_min);
}
if ($amount_max !== '' && is_numeric($amount_max)) {
	$where[] = "ABS(m.amount) <= ".((float) $amount_max);
}
if ($searchText !== '') {
	$searchSql = natural_search(array('m.description', 's.nom', 't.code', 't.label'), $searchText);
	$searchSql = preg_replace('/^\s*AND\s+/i', '', (string) $searchSql);
	if (!empty($searchSql)) {
		$where[] = $searchSql;
	}
}

$whereSql = ' WHERE '.implode(' AND ', $where);

$detailSql = "SELECT m.rowid, m.date_movement, m.amount, m.description, m.type_movement,";
$detailSql .= " m.fk_soc, s.nom as socname, m.fk_credit_type, t.code as credit_code, t.label as credit_label,";
$detailSql .= " pr.rowid as fk_project, pr.ref as project_ref, pr.title as project_title,";
$detailSql .= " et.fk_user, u.login as user_login";
$detailSql .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
$detailSql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = m.fk_soc";
$detailSql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = m.fk_credit_type";
$detailSql .= " LEFT JOIN ".MAIN_DB_PREFIX."element_time as et ON et.rowid = m.fk_element_time";
$detailSql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
$detailSql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
$detailSql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = et.fk_user AND u.entity IN (".$entityUser.")";
$detailSql .= $whereSql;
$detailSql .= $db->order($sortfield, $sortorder);

$countSql = "SELECT COUNT(m.rowid) as nb FROM ".MAIN_DB_PREFIX."credits_movements as m";
$countSql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = m.fk_soc";
$countSql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = m.fk_credit_type";
$countSql .= " LEFT JOIN ".MAIN_DB_PREFIX."element_time as et ON et.rowid = m.fk_element_time";
$countSql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
$countSql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
$countSql .= $whereSql;
$resCount = $db->query($countSql);
$total = 0;
if ($resCount) {
	$objC = $db->fetch_object($resCount);
	$total = (int) ($objC->nb ?? 0);
	$db->free($resCount);
}

$resDetail = $db->query($detailSql.$db->plimit($limit + 1, $offset));
$detailRows = array();
$detailQueryError = '';
if ($resDetail) {
	while ($obj = $db->fetch_object($resDetail)) {
		$detailRows[] = array(
			'rowid' => (int) $obj->rowid,
			'date_movement' => $db->jdate($obj->date_movement),
			'socname' => $obj->socname,
			'credit_code' => $obj->credit_code,
			'credit_label' => $obj->credit_label,
			'project_ref' => $obj->project_ref ?: '',
			'user_login' => $obj->user_login ?: '',
			'type_movement' => $obj->type_movement,
			'amount' => (float) $obj->amount,
			'description' => $obj->description,
		);
	}
	$db->free($resDetail);
} else {
	$detailQueryError = $db->lasterror();
}

$consumptionRows = $report->generateConsumptionReport($date_start, $date_end, $search_socids, $search_typeids);
$forecastFilters = array('scope' => $reportScope);
$forecastRows = $report->calculateForecast($search_socids, $search_typeids, $forecastFilters);
$budgetFilters = array('fk_soc' => $search_socids, 'fk_credit_type' => $search_typeids, 'fk_project' => $search_projectids, 'scope' => $reportScope);
$budgetRows = $report->compareBudgetVsReal((int) dol_print_date($date_start, '%Y'), $budgetFilters);

$mainChartConfig = $graph->buildMonthlyConsumptionChart($consumptionRows, $chart_type);
$forecastChartConfig = $graph->buildForecastChart($forecastRows);
$budgetChartConfig = $graph->buildBudgetVsRealChart($budgetRows);

$exportRows = array();
foreach ($detailRows as $r) {
	$exportRows[] = array(
		'Date' => dol_print_date($r['date_movement'], 'dayhour'),
		'Client' => $r['socname'],
		'Type' => $r['credit_code'].' - '.$r['credit_label'],
		'Projet' => $r['project_ref'],
		'Collaborateur' => $r['user_login'],
		'Mouvement' => $r['type_movement'],
		'Montant' => creditmanagerFormatAmountNum($r['amount']),
		'Description' => $r['description'],
	);
}

if ($action === 'export' && creditmanagerCanExport($user)) {
	$format = GETPOST('format', 'aZ09');
	$filenameBase = 'credit_consumption_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	$headers = array('Date', 'Client', 'Type', 'Projet', 'Collaborateur', 'Mouvement', 'Montant', 'Description');

	if ($format === 'csv') {
		$exporter->exportCSV($filenameBase.'.csv', $headers, $exportRows);
	} elseif ($format === 'json') {
		$exporter->exportJSON($filenameBase.'.json', array('filters' => $_GET, 'rows' => $exportRows));
	} elseif ($format === 'pdf') {
		$exporter->exportPDF($filenameBase.'.pdf', 'Credit Consumption Report', $headers, $exportRows);
	} elseif ($format === 'excel') {
		$exporter->exportExcel($filenameBase.'.xlsx', $headers, $exportRows);
	}
}

llxHeader('', $langs->trans('CreditMovements'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-consumption');

print load_fiche_titre($langs->trans('CreditReportConsumptionTitle'), '', 'title_generic');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditReportConsumptionSubtitle').'</div>';
print '<div class="opacitymedium marginbottomonly">';
print $langs->trans('CreditReportActivePeriod').': '.dol_print_date($date_start, 'day').' - '.dol_print_date($date_end, 'day');
print '</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" id="consumption_filters">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditReportFilters').'</th></tr>';
print '<tr class="oddeven">';
print '<td>';
print '<label>'.$langs->trans('CreditReportPreset').'</label><br>';
print '<select name="preset" class="flat maxwidth200">';
print '<option value=""'.($preset === '' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetCustom').'</option>';
print '<option value="month"'.($preset === 'month' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetMonth').'</option>';
print '<option value="quarter"'.($preset === 'quarter' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetQuarter').'</option>';
print '<option value="year"'.($preset === 'year' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetYear').'</option>';
print '</select>';
print '</td>';
print '<td><label>'.$langs->trans('CreditReportDateStartEnd').'</label><br>';
print $form->selectDate($date_start, 'date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1);
print ' ';
print $form->selectDate($date_end, 'date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1);
print '</td>';
print '<td><label>'.$langs->trans('CreditReportMovementType').'</label><br>';
print '<select name="movement_type" class="flat maxwidth120">';
print '<option value=""'.($movement_type === '' ? ' selected' : '').'></option>';
print '<option value="credit"'.($movement_type === 'credit' ? ' selected' : '').'>'.$langs->trans('Credit').'</option>';
print '<option value="debit"'.($movement_type === 'debit' ? ' selected' : '').'>'.$langs->trans('Debit').'</option>';
print '</select></td>';
print '<td><label>'.$langs->trans('CreditReportAmountMinMax').'</label><br>';
print '<input class="flat width75" type="number" step="0.01" name="amount_min" value="'.dol_escape_htmltag($amount_min).'"> ';
print '<input class="flat width75" type="number" step="0.01" name="amount_max" value="'.dol_escape_htmltag($amount_max).'">';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportClients').'</label><br>';
$allowedSocIds = creditmanagerGetReportScopeSocIdsForSelect($db, $reportScope, $entitySoc);
$sqlClients = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".$entitySoc.") AND client IN (1,2,3)";
if ($reportScope['type'] !== 'all') {
	$sqlClients .= !empty($allowedSocIds) ? " AND rowid IN (".implode(',', $allowedSocIds).")" : " AND 1=0";
}
$sqlClients .= " ORDER BY nom";
$resClients = $db->query($sqlClients);
print '<select class="flat minwidth250" name="search_socids[]" multiple>';
if ($resClients) {
	while ($o = $db->fetch_object($resClients)) {
		$sel = in_array((int) $o->rowid, $search_socids) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag($o->nom).'</option>';
	}
	$db->free($resClients);
}
print '</select>';
print '</td>';

$sqlTypes = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."credits_types WHERE entity IN (".$entityType.") AND active = 1 ORDER BY code";
$resTypes = $db->query($sqlTypes);
print '<td><label>'.$langs->trans('CreditReportCreditTypes').'</label><br><select class="flat minwidth250" name="search_typeids[]" multiple>';
if ($resTypes) {
	while ($o = $db->fetch_object($resTypes)) {
		$sel = in_array((int) $o->rowid, $search_typeids) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag($o->code.' - '.$o->label).'</option>';
	}
	$db->free($resTypes);
}
print '</select></td>';

$allowedProjectIds = creditmanagerGetReportScopeProjectIdsForSelect($db, $reportScope, $entityProject);
$sqlProjects = "SELECT rowid, ref, title FROM ".MAIN_DB_PREFIX."projet WHERE entity IN (".$entityProject.")";
if ($reportScope['type'] !== 'all') {
	$sqlProjects .= !empty($allowedProjectIds) ? " AND rowid IN (".implode(',', $allowedProjectIds).")" : " AND 1=0";
}
$sqlProjects .= " ORDER BY ref";
$resProjects = $db->query($sqlProjects);
print '<td><label>'.$langs->trans('CreditReportProjects').'</label><br><select class="flat minwidth250" name="search_projectids[]" multiple>';
if ($resProjects) {
	while ($o = $db->fetch_object($resProjects)) {
		$sel = in_array((int) $o->rowid, $search_projectids) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag(trim($o->ref.' '.$o->title)).'</option>';
	}
	$db->free($resProjects);
}
print '</select></td>';

$sqlUsers = "SELECT rowid, login, lastname, firstname FROM ".MAIN_DB_PREFIX."user WHERE entity IN (".$entityUser.") ORDER BY login";
$resUsers = $db->query($sqlUsers);
print '<td><label>'.$langs->trans('CreditReportCollaborators').'</label><br><select class="flat minwidth250" name="search_userids[]" multiple>';
if ($resUsers) {
	while ($o = $db->fetch_object($resUsers)) {
		$sel = in_array((int) $o->rowid, $search_userids) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag($o->login.' - '.trim($o->firstname.' '.$o->lastname)).'</option>';
	}
	$db->free($resUsers);
}
print '</select></td></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportGroupBy').'</label><br><select class="flat minwidth250" name="group_by[]" multiple>';
$groupOptions = array(
	'client' => $langs->trans('CreditReportGroupByClient'),
	'project' => $langs->trans('CreditReportGroupByProject'),
	'credit_type' => $langs->trans('CreditReportGroupByCreditType'),
	'month' => $langs->trans('CreditReportGroupByMonth'),
	'collaborator' => $langs->trans('CreditReportGroupByCollaborator'),
);
foreach ($groupOptions as $k => $lbl) {
	$sel = in_array($k, $group_by) ? ' selected' : '';
	print '<option value="'.$k.'"'.$sel.'>'.$lbl.'</option>';
}
print '</select></td>';
print '<td><label>'.$langs->trans('CreditReportMainChart').'</label><br><select name="chart_type" class="flat maxwidth150">';
print '<option value="bar"'.($chart_type === 'bar' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartBars').'</option>';
print '<option value="line"'.($chart_type === 'line' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartLines').'</option>';
print '<option value="pie"'.($chart_type === 'pie' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartPie').'</option>';
print '</select></td>';
print '<td><label>'.$langs->trans('CreditReportTableSearch').'</label><br>';
print '<input class="flat minwidth250" name="search_text" value="'.dol_escape_htmltag($searchText).'" placeholder="'.dol_escape_htmltag($langs->trans('CreditReportTableSearchPlaceholder')).'">';
print '</td>';
print '<td><label>'.$langs->trans('CreditReportSaveView').'</label><br>';
print '<input class="flat minwidth200" name="view_name" value="" placeholder="'.dol_escape_htmltag($langs->trans('CreditReportViewNamePlaceholder')).'"> ';
print '<button class="button small" name="save_view" value="1" type="submit">'.$langs->trans('CreditReportSaveView').'</button>';
if (!empty($savedViews)) {
	print '<br><span class="opacitymedium">'.$langs->trans('CreditReportSavedViews').'</span><br>';
	print '<select class="flat minwidth250" name="selected_view">';
	print '<option value="">'.dol_escape_htmltag($langs->trans('CreditReportSelectSavedView')).'</option>';
	foreach ($savedViews as $viewKey => $viewLabel) {
		$sel = ($selected_view === $viewKey) ? ' selected' : '';
		print '<option value="'.dol_escape_htmltag($viewKey).'"'.$sel.'>'.dol_escape_htmltag($viewLabel).'</option>';
	}
	print '</select> ';
	print '<button class="button small" name="load_view" value="1" type="submit">'.$langs->trans('CreditReportLoadView').'</button> ';
	print '<button class="button small button-delete" name="delete_view" value="1" type="submit" onclick="return confirm(\''.dol_escape_js($langs->trans('CreditReportDeleteViewConfirm')).'\');">'.$langs->trans('CreditReportDeleteView').'</button>';
}
print '</td></tr>';

print '<tr class="oddeven"><td colspan="4" class="right">';
print '<button class="button" type="submit">'.$langs->trans('CreditReportApplyFilters').'</button> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/custom/creditmanager/reports/consumption.php', 1).'">'.$langs->trans('CreditReportResetFilters').'</a> ';
if (creditmanagerCanExport($user)) {
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'csv', 'token' => $token))).'">CSV</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'pdf', 'token' => $token))).'" >PDF</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'excel', 'token' => $token))).'" >Excel</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'json', 'token' => $token))).'">JSON</a>';
}
print '</td></tr>';
print '</table></div>';

print '<div class="fichecenter"><div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportMainChart');
print ' <button type="button" class="button small reposition" id="btnResetZoomMain" title="'.dol_escape_htmltag($langs->trans('CreditReportResetChartZoom')).'">'.$langs->trans('CreditReportResetChartZoom').'</button>';
print '</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartMain" height="160"></canvas></td></tr>';
print '</table></div></div>';

print '<div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportSecondaryChart');
print ' <button type="button" class="button small reposition" id="btnResetZoomForecast" title="'.dol_escape_htmltag($langs->trans('CreditReportResetChartZoom')).'">'.$langs->trans('CreditReportResetChartZoom').'</button>';
print '</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartForecast" height="160"></canvas></td></tr>';
print '</table></div></div></div>';

print '<div class="clearboth"></div>';
print '<div class="div-table-responsive-no-min marginbottom" style="margin-top: 12px; width: 100%;">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportBudgetVsReal');
print ' <button type="button" class="button small reposition" id="btnResetZoomBudget" title="'.dol_escape_htmltag($langs->trans('CreditReportResetChartZoom')).'">'.$langs->trans('CreditReportResetChartZoom').'</button>';
print '</th></tr>';
print '<tr class="oddeven"><td style="height: 340px; min-width: 200px;"><canvas id="chartBudget" height="320"></canvas></td></tr>';
print '</table></div>';

$paramsForList = $_GET;
unset($paramsForList['page']);
$param = '';
if (!empty($paramsForList)) {
	$param = '&'.http_build_query($paramsForList);
}

print_barre_liste($langs->trans('CreditReportDetailedMovements'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $total, '', '');

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'm.date_movement', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('ThirdParty'), $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('CreditReportGroupByCreditType').'</th><th>'.$langs->trans('CreditReportGroupByProject').'</th><th>'.$langs->trans('CreditReportGroupByCollaborator').'</th><th>'.$langs->trans('CreditReportMovementType').'</th>';
print_liste_field_titre($langs->trans('Amount'), $_SERVER['PHP_SELF'], 'm.amount', '', $param, 'class="right"', $sortfield, $sortorder);
print '<th>'.$langs->trans('Description').'</th></tr>';

$visibleRows = $limit > 0 ? array_slice($detailRows, 0, $limit) : $detailRows;
if ($detailQueryError !== '') {
	print '<tr class="oddeven"><td colspan="8" class="error">'.dol_escape_htmltag($detailQueryError).'</td></tr>';
}
if (empty($visibleRows)) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">'.$langs->trans('NoData').'</td></tr>';
}
foreach ($visibleRows as $r) {
	$cls = $r['amount'] < 0 ? 'amountnegative' : 'amount';
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($r['date_movement'], 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($r['socname']).'</td>';
	print '<td>'.dol_escape_htmltag($r['credit_code'].' - '.$r['credit_label']).'</td>';
	print '<td>'.dol_escape_htmltag($r['project_ref']).'</td>';
	print '<td>'.dol_escape_htmltag($r['user_login']).'</td>';
	print '<td>'.dol_escape_htmltag($r['type_movement']).'</td>';
	print '<td class="right '.$cls.'">'.creditmanagerFormatAmount($r['amount']).'</td>';
	print '<td>'.dol_escape_htmltag($r['description']).'</td>';
	print '</tr>';
}
print '</table></div>';
print '</form>';

$jsMain = json_encode($mainChartConfig);
$jsForecast = json_encode($forecastChartConfig);
$jsBudget = json_encode($budgetChartConfig);

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.2.0/dist/chartjs-plugin-zoom.min.js"></script>';
print '<script>
(function() {
	var mainCfg = '.$jsMain.';
	var forecastCfg = '.$jsForecast.';
	var budgetCfg = '.$jsBudget.';
	var optionsZoom = {
		pan: { enabled: true, mode: "xy" },
		zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: "xy" }
	};

	function bindResetZoom(btnId, chart) {
		var btn = document.getElementById(btnId);
		if (!btn || !chart) return;
		btn.addEventListener("click", function(e) {
			e.preventDefault();
			if (typeof chart.resetZoom === "function") {
				chart.resetZoom();
			}
		});
	}

	if (window.Chart) {
		if (!mainCfg.options.plugins) mainCfg.options.plugins = {};
		mainCfg.options.plugins.zoom = optionsZoom;
		mainCfg.options.plugins.legend = mainCfg.options.plugins.legend || {};
		mainCfg.options.plugins.legend.onClick = Chart.defaults.plugins.legend.onClick;

		if (!forecastCfg.options.plugins) forecastCfg.options.plugins = {};
		forecastCfg.options.plugins.zoom = optionsZoom;

		if (!budgetCfg.options.plugins) budgetCfg.options.plugins = {};
		budgetCfg.options.plugins.zoom = optionsZoom;

		var chartMain = new Chart(document.getElementById("chartMain"), mainCfg);
		var chartForecast = new Chart(document.getElementById("chartForecast"), forecastCfg);
		var chartBudget = new Chart(document.getElementById("chartBudget"), budgetCfg);

		bindResetZoom("btnResetZoomMain", chartMain);
		bindResetZoom("btnResetZoomForecast", chartForecast);
		bindResetZoom("btnResetZoomBudget", chartBudget);
	}
})();
</script>';

llxFooter();
$db->close();
