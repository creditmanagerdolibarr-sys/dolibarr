<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr */

/**
 * \file        htdocs/custom/creditmanager/dashboard/dashboard_pm.php
 * \ingroup     creditmanager
 * \brief       Project Manager dashboard with filters and configurable widgets.
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
$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'projects', 'other'));

if (!creditmanagerCanApproveTimesheets($user) && !creditmanagerCanManageAdmin($user)) {
	accessforbidden();
}

$reportScope = creditmanagerGetReportScope($db, $user);

$form = new Form($db);
$report = new CreditReport($db);
$graph = new CreditGraph();
$exporter = new CreditExport();

$token = newToken();
$action = GETPOST('action', 'aZ09');

$PARAM_WIDGETS = 'CREDITMANAGER_PM_DASHBOARD_WIDGETS';
$PARAM_REFRESH = 'CREDITMANAGER_PM_DASHBOARD_REFRESH';

$defaultWidgets = array(
	'show_alerts' => 1,
	'show_projects' => 1,
	'show_quick_actions' => 1,
	'show_consumption' => 1,
);

/**
 * @param DoliDB $db
 * @param Conf $conf
 * @param User $user
 * @param string $param
 * @return string
 */
function creditmanager_pm_get_user_param($db, $conf, $user, $param)
{
	$sql = "SELECT value FROM ".MAIN_DB_PREFIX."user_param";
	$sql .= " WHERE fk_user = ".((int) $user->id);
	$sql .= " AND entity = ".((int) $conf->entity);
	$sql .= " AND param = '".$db->escape($param)."'";
	$sql .= " LIMIT 1";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$val = (string) $obj->value;
		$db->free($resql);
		return $val;
	}
	return '';
}

$widgetPrefs = $defaultWidgets;
$savedWidgetsRaw = creditmanager_pm_get_user_param($db, $conf, $user, $PARAM_WIDGETS);
if ($savedWidgetsRaw !== '') {
	$decoded = json_decode($savedWidgetsRaw, true);
	if (is_array($decoded)) {
		foreach ($defaultWidgets as $k => $v) {
			if (array_key_exists($k, $decoded)) {
				$widgetPrefs[$k] = !empty($decoded[$k]) ? 1 : 0;
			}
		}
	}
}

$refreshInterval = (int) creditmanager_pm_get_user_param($db, $conf, $user, $PARAM_REFRESH);
if (!in_array($refreshInterval, array(0, 60, 120, 300, 600), true)) {
	$refreshInterval = 0;
}

if ($action === 'save_widgets' && GETPOST('token', 'alpha')) {
	$widgetPrefs = array(
		'show_alerts' => GETPOSTINT('show_alerts') ? 1 : 0,
		'show_projects' => GETPOSTINT('show_projects') ? 1 : 0,
		'show_quick_actions' => GETPOSTINT('show_quick_actions') ? 1 : 0,
		'show_consumption' => GETPOSTINT('show_consumption') ? 1 : 0,
	);
	$refreshInterval = GETPOSTINT('refresh_interval');
	if (!in_array($refreshInterval, array(0, 60, 120, 300, 600), true)) {
		$refreshInterval = 0;
	}
	dol_set_user_param($db, $conf, $user, array(
		$PARAM_WIDGETS => json_encode($widgetPrefs),
		$PARAM_REFRESH => (string) $refreshInterval,
	));
	setEventMessages($langs->trans('CreditDashboardPmPrefsSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query(array_diff_key($_GET, array('action' => 1, 'token' => 1))));
	exit;
}

$preset = GETPOST('preset', 'aZ09');
$now = dol_now();
$yearNow = (int) dol_print_date($now, '%Y');
$monthNow = (int) dol_print_date($now, '%m');
$dayNow = (int) dol_print_date($now, '%d');

$ds_day = GETPOSTINT('date_start_day');
$ds_month = GETPOSTINT('date_start_month');
$ds_year = GETPOSTINT('date_start_year');
$de_day = GETPOSTINT('date_end_day');
$de_month = GETPOSTINT('date_end_month');
$de_year = GETPOSTINT('date_end_year');
$hasCustomDates = ($ds_day > 0 && $ds_month > 0 && $ds_year > 1970 && $de_day > 0 && $de_month > 0 && $de_year > 1970);

// Preset wins over custom fields; default to current month on first load.
if ($preset === '' && !$hasCustomDates) {
	$preset = 'month';
}

if ($preset !== '' || !$hasCustomDates) {
	if ($preset === 'week') {
		$date_end = dol_mktime(23, 59, 59, $monthNow, $dayNow, $yearNow);
		$tmpStart = dol_time_plus_duree($date_end, -6, 'd');
		$date_start = dol_mktime(
			0,
			0,
			0,
			(int) dol_print_date($tmpStart, '%m'),
			(int) dol_print_date($tmpStart, '%d'),
			(int) dol_print_date($tmpStart, '%Y')
		);
	} elseif ($preset === 'quarter') {
		$qMonth = ((int) floor(($monthNow - 1) / 3) * 3) + 1;
		$qEndMonth = $qMonth + 2;
		$date_start = dol_get_first_day($yearNow, $qMonth);
		$date_end = dol_get_last_day($yearNow, $qEndMonth);
	} elseif ($preset === 'year') {
		$date_start = dol_get_first_day($yearNow, 1);
		$date_end = dol_get_last_day($yearNow, 12);
	} else {
		$preset = 'month';
		$date_start = dol_get_first_day($yearNow, $monthNow);
		$date_end = dol_get_last_day($yearNow, $monthNow);
	}
} else {
	$date_start = dol_mktime(0, 0, 0, $ds_month, $ds_day, $ds_year);
	$date_end = dol_mktime(23, 59, 59, $de_month, $de_day, $de_year);
}

// Never allow an inverted period (e.g. 01/01/2027 - 12/31/2026).
if (empty($date_start) || empty($date_end) || $date_start > $date_end) {
	$preset = 'month';
	$date_start = dol_get_first_day($yearNow, $monthNow);
	$date_end = dol_get_last_day($yearNow, $monthNow);
}

$search_socids = GETPOST('search_socids', 'array');
$search_typeids = GETPOST('search_typeids', 'array');
$search_projectids = GETPOST('search_projectids', 'array');
$alert_threshold = GETPOST('alert_threshold', 'aZ09');
$project_status = GETPOST('project_status', 'aZ09');
$chart_type = GETPOST('chart_type', 'aZ09');
$chart_period = GETPOSTINT('chart_period');
$chart_group_by = GETPOST('chart_group_by', 'aZ09');

if (!in_array($chart_type, array('bar', 'line', 'pie'), true)) {
	$chart_type = 'bar';
}
if (!in_array($chart_period, array(7, 14, 30, 90), true)) {
	$chart_period = 30;
}
if (!in_array($chart_group_by, array('client', 'project', 'credit_type', 'collaborator'), true)) {
	$chart_group_by = 'client';
}
if (!in_array($alert_threshold, array('', 'warning', 'critical'), true)) {
	$alert_threshold = '';
}
if (!in_array($project_status, array('', 'active', 'closed'), true)) {
	$project_status = 'active';
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

creditmanagerApplyReportScopeToFilters($reportScope, $search_socids, $search_projectids, $db);

$entitySoc = getEntity('societe');
$entityType = getEntity('credits_type');
$entityProject = getEntity('project');
$entityBalance = getEntity('credits_balance');
$entityMovement = getEntity('credits_movement');
$entityUser = getEntity('user');

// ---------------------------------------------------------------------------
// Alerts widget data (forecast-based, filtered by alert status)
// ---------------------------------------------------------------------------
$forecastFilters = array(
	'period_months' => 3,
	'fk_project' => $search_projectids,
	'alert_threshold' => $alert_threshold,
	'scope' => $reportScope,
);
$alertRows = $report->calculateForecast($search_socids, $search_typeids, $forecastFilters);
if ($alert_threshold === '') {
	$alertRows = array_values(array_filter($alertRows, function ($r) {
		return in_array($r['status'], array('warning', 'critical'), true);
	}));
}
usort($alertRows, function ($a, $b) {
	$order = array('critical' => 0, 'warning' => 1, 'safe' => 2);
	$oa = $order[$a['status']] ?? 9;
	$ob = $order[$b['status']] ?? 9;
	if ($oa !== $ob) {
		return $oa <=> $ob;
	}
	$ma = $a['months_remaining'];
	$mb = $b['months_remaining'];
	if ($ma === null && $mb === null) {
		return 0;
	}
	if ($ma === null) {
		return 1;
	}
	if ($mb === null) {
		return -1;
	}
	return $ma <=> $mb;
});

// ---------------------------------------------------------------------------
// Multi-project widget data
// ---------------------------------------------------------------------------
$projectRows = array();
$allowedProjectIds = creditmanagerGetReportScopeProjectIdsForSelect($db, $reportScope, $entityProject);

$sqlProjects = "SELECT pr.rowid, pr.ref, pr.title, pr.fk_statut, pr.fk_soc, s.nom as socname";
$sqlProjects .= " FROM ".MAIN_DB_PREFIX."projet as pr";
$sqlProjects .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = pr.fk_soc AND s.entity IN (".$entitySoc.")";
$sqlProjects .= " WHERE pr.entity IN (".$entityProject.")";
if ($reportScope['type'] !== 'all') {
	$sqlProjects .= !empty($allowedProjectIds) ? " AND pr.rowid IN (".implode(',', $allowedProjectIds).")" : " AND 1=0";
}
if (!empty($search_projectids)) {
	$sqlProjects .= " AND pr.rowid IN (".implode(',', $search_projectids).")";
}
if (!empty($search_socids)) {
	$sqlProjects .= " AND pr.fk_soc IN (".implode(',', $search_socids).")";
}
if ($project_status === 'active') {
	$sqlProjects .= " AND pr.fk_statut = 1";
} elseif ($project_status === 'closed') {
	$sqlProjects .= " AND pr.fk_statut = 2";
}
$sqlProjects .= " ORDER BY pr.ref ASC";

$resProjectsList = $db->query($sqlProjects);
if ($resProjectsList) {
	while ($obj = $db->fetch_object($resProjectsList)) {
		$projectRows[(int) $obj->rowid] = array(
			'rowid' => (int) $obj->rowid,
			'ref' => $obj->ref,
			'title' => $obj->title,
			'fk_statut' => (int) $obj->fk_statut,
			'fk_soc' => (int) $obj->fk_soc,
			'socname' => $obj->socname ?: '',
			'pending_count' => 0,
			'total_hours' => 0.0,
			'client_balance' => 0.0,
		);
	}
	$db->free($resProjectsList);
}

if (!empty($projectRows)) {
	$projectIdList = implode(',', array_keys($projectRows));

	$sqlPending = "SELECT pr.rowid as fk_project, COUNT(DISTINCT et.rowid) as nb_pending";
	$sqlPending .= " FROM ".MAIN_DB_PREFIX."element_time as et";
	$sqlPending .= " INNER JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
	$sqlPending .= " INNER JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet";
	$sqlPending .= " WHERE et.elementtype = 'task'";
	$sqlPending .= " AND UPPER(TRIM(et.credit_status)) = 'SUBMITTED'";
	$sqlPending .= " AND pr.rowid IN (".$projectIdList.")";
	$sqlPending .= creditmanagerTimesheetScopeProjectWhereSql($db, $user, 'pr');
	$sqlPending .= " GROUP BY pr.rowid";
	$resPending = $db->query($sqlPending);
	if ($resPending) {
		while ($obj = $db->fetch_object($resPending)) {
			$pid = (int) $obj->fk_project;
			if (isset($projectRows[$pid])) {
				$projectRows[$pid]['pending_count'] = (int) $obj->nb_pending;
			}
		}
		$db->free($resPending);
	}

	$sqlHours = "SELECT pr.rowid as fk_project, SUM(COALESCE(et.element_duration, 0)) as total_seconds";
	$sqlHours .= " FROM ".MAIN_DB_PREFIX."element_time as et";
	$sqlHours .= " INNER JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
	$sqlHours .= " INNER JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet";
	$sqlHours .= " WHERE et.elementtype = 'task'";
	$sqlHours .= " AND UPPER(TRIM(et.credit_status)) IN ('SUBMITTED','APPROVED','DEBITED')";
	$sqlHours .= " AND et.element_date >= '".$db->idate($date_start)."'";
	$sqlHours .= " AND et.element_date <= '".$db->idate($date_end)."'";
	$sqlHours .= " AND pr.rowid IN (".$projectIdList.")";
	if (!empty($search_typeids)) {
		$sqlHours .= " AND et.fk_credit_type IN (".implode(',', $search_typeids).")";
	}
	$sqlHours .= creditmanagerTimesheetScopeProjectWhereSql($db, $user, 'pr');
	$sqlHours .= " GROUP BY pr.rowid";
	$resHours = $db->query($sqlHours);
	if ($resHours) {
		while ($obj = $db->fetch_object($resHours)) {
			$pid = (int) $obj->fk_project;
			if (isset($projectRows[$pid])) {
				$projectRows[$pid]['total_hours'] = ((float) $obj->total_seconds) / 3600.0;
			}
		}
		$db->free($resHours);
	}

	$socIdsForBalance = array();
	foreach ($projectRows as $pr) {
		if ($pr['fk_soc'] > 0) {
			$socIdsForBalance[$pr['fk_soc']] = $pr['fk_soc'];
		}
	}
	$balancesBySoc = array();
	if (!empty($socIdsForBalance)) {
		$sqlBal = "SELECT b.fk_soc, SUM(b.balance) as total_balance";
		$sqlBal .= " FROM ".MAIN_DB_PREFIX."credits_balance as b";
		$sqlBal .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
		$sqlBal .= " WHERE b.entity IN (".$entityBalance.")";
		$sqlBal .= " AND t.entity IN (".$entityType.")";
		$sqlBal .= " AND t.active = 1";
		$sqlBal .= " AND b.fk_soc IN (".implode(',', $socIdsForBalance).")";
		if (!empty($search_typeids)) {
			$sqlBal .= " AND b.fk_credit_type IN (".implode(',', $search_typeids).")";
		}
		$sqlBal .= " GROUP BY b.fk_soc";
		$resBal = $db->query($sqlBal);
		if ($resBal) {
			while ($obj = $db->fetch_object($resBal)) {
				$balancesBySoc[(int) $obj->fk_soc] = (float) $obj->total_balance;
			}
			$db->free($resBal);
		}
	}
	foreach ($projectRows as $pid => $pr) {
		$projectRows[$pid]['client_balance'] = isset($balancesBySoc[$pr['fk_soc']]) ? $balancesBySoc[$pr['fk_soc']] : 0.0;
	}
}
$projectRows = array_values($projectRows);

// ---------------------------------------------------------------------------
// Pending timesheets count (quick actions badge)
// ---------------------------------------------------------------------------
$sqlPendingCount = "SELECT COUNT(DISTINCT et.rowid) as nb";
$sqlPendingCount .= " FROM ".MAIN_DB_PREFIX."element_time as et";
$sqlPendingCount .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
$sqlPendingCount .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet";
$sqlPendingCount .= " WHERE et.elementtype = 'task'";
$sqlPendingCount .= " AND UPPER(TRIM(et.credit_status)) = 'SUBMITTED'";
$sqlPendingCount .= creditmanagerTimesheetScopeProjectWhereSql($db, $user, 'pr');
if (!empty($search_socids)) {
	$sqlPendingCount .= " AND pr.fk_soc IN (".implode(',', $search_socids).")";
}
if (!empty($search_projectids)) {
	$sqlPendingCount .= " AND pr.rowid IN (".implode(',', $search_projectids).")";
}
$pendingTotal = 0;
$resPendingTotal = $db->query($sqlPendingCount);
if ($resPendingTotal) {
	$objP = $db->fetch_object($resPendingTotal);
	$pendingTotal = (int) ($objP->nb ?? 0);
	$db->free($resPendingTotal);
}

// ---------------------------------------------------------------------------
// Recent consumption chart
// ---------------------------------------------------------------------------
$chartDateEnd = dol_mktime(23, 59, 59, (int) dol_print_date($now, '%m'), (int) dol_print_date($now, '%d'), (int) dol_print_date($now, '%Y'));
$chartDateStart = dol_time_plus_duree($chartDateEnd, -($chart_period - 1), 'd');
$chartDateStart = dol_mktime(0, 0, 0, (int) dol_print_date($chartDateStart, '%m'), (int) dol_print_date($chartDateStart, '%d'), (int) dol_print_date($chartDateStart, '%Y'));

$unknownLabel = $db->escape($langs->trans('Unknown'));
$chartSelectLabel = "s.nom as group_label";
$chartGroupSql = "s.nom";
if ($chart_group_by === 'project') {
	$chartSelectLabel = "COALESCE(NULLIF(TRIM(CONCAT(pr.ref, ' ', pr.title)), ''), '".$unknownLabel."') as group_label";
	$chartGroupSql = "pr.rowid, pr.ref, pr.title";
} elseif ($chart_group_by === 'credit_type') {
	$chartSelectLabel = "CONCAT(t.code, ' - ', t.label) as group_label";
	$chartGroupSql = "t.code, t.label";
} elseif ($chart_group_by === 'collaborator') {
	$chartSelectLabel = "COALESCE(NULLIF(TRIM(u.login), ''), '".$unknownLabel."') as group_label";
	$chartGroupSql = "u.login";
}

$sqlChart = "SELECT ".$chartSelectLabel.",";
$sqlChart .= " DATE_FORMAT(m.date_movement, '%Y-%m-%d') as day_key,";
$sqlChart .= " SUM(CASE WHEN m.amount < 0 THEN ABS(m.amount) ELSE 0 END) as consumed_hours";
$sqlChart .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
$sqlChart .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = m.fk_soc";
$sqlChart .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = m.fk_credit_type";
$sqlChart .= " LEFT JOIN ".MAIN_DB_PREFIX."element_time as et ON et.rowid = m.fk_element_time";
$sqlChart .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
$sqlChart .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
$sqlChart .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = et.fk_user AND u.entity IN (".$entityUser.")";
$sqlChart .= " WHERE m.entity IN (".$entityMovement.")";
$sqlChart .= " AND s.entity IN (".$entitySoc.")";
$sqlChart .= " AND t.entity IN (".$entityType.")";
$sqlChart .= " AND m.amount < 0";
$sqlChart .= " AND m.date_movement >= '".$db->idate($chartDateStart)."'";
$sqlChart .= " AND m.date_movement <= '".$db->idate($chartDateEnd)."'";
if (!empty($search_socids)) {
	$sqlChart .= " AND m.fk_soc IN (".implode(',', $search_socids).")";
}
if (!empty($search_typeids)) {
	$sqlChart .= " AND m.fk_credit_type IN (".implode(',', $search_typeids).")";
}
if (!empty($search_projectids)) {
	$sqlChart .= " AND ".creditmanagerReportProjectIdsWhereCondition($search_projectids, 'm', 'pr');
} elseif ($reportScope['type'] !== 'all') {
	$scopeCond = creditmanagerReportScopeWhereSql($reportScope, 'm', 'pr');
	if ($scopeCond !== '') {
		$sqlChart .= $scopeCond;
	}
}
$sqlChart .= " GROUP BY ".$chartGroupSql.", day_key";
$sqlChart .= " ORDER BY day_key ASC";

$chartSeries = array();
$chartDays = array();
$resChart = $db->query($sqlChart);
if ($resChart) {
	while ($obj = $db->fetch_object($resChart)) {
		$label = (string) $obj->group_label;
		$day = (string) $obj->day_key;
		$chartDays[$day] = $day;
		if (!isset($chartSeries[$label])) {
			$chartSeries[$label] = array();
		}
		$chartSeries[$label][$day] = (float) $obj->consumed_hours;
	}
	$db->free($resChart);
}
$dayLabels = array_values($chartDays);
sort($dayLabels);

// Fill missing days in range for continuous axis
$filledDays = array();
$cursor = $chartDateStart;
while ($cursor <= $chartDateEnd) {
	$key = dol_print_date($cursor, '%Y-%m-%d');
	$filledDays[] = $key;
	$cursor = dol_time_plus_duree($cursor, 1, 'd');
}
if (count($filledDays) <= 92) {
	$dayLabels = $filledDays;
}

$chartDatasets = array();
$colorIdx = 0;
$palette = array(
	array(54, 162, 235),
	array(255, 99, 132),
	array(75, 192, 192),
	array(255, 159, 64),
	array(153, 102, 255),
	array(201, 203, 207),
);
foreach ($chartSeries as $name => $points) {
	$color = $palette[$colorIdx % count($palette)];
	$data = array();
	foreach ($dayLabels as $d) {
		$data[] = isset($points[$d]) ? (float) creditmanagerFormatAmountNum($points[$d]) : 0;
	}
	$chartDatasets[] = array(
		'label' => $name,
		'data' => $data,
		'backgroundColor' => 'rgba('.$color[0].','.$color[1].','.$color[2].',0.35)',
		'borderColor' => 'rgba('.$color[0].','.$color[1].','.$color[2].',1)',
		'borderWidth' => 1,
		'tension' => 0.25,
	);
	$colorIdx++;
}

if ($chart_type === 'pie') {
	$pieLabels = array();
	$pieData = array();
	$pieColors = array();
	foreach ($chartSeries as $name => $points) {
		$sum = 0.0;
		foreach ($points as $v) {
			$sum += (float) $v;
		}
		$pieLabels[] = $name;
		$pieData[] = (float) creditmanagerFormatAmountNum($sum);
		$color = $palette[count($pieLabels) % count($palette)];
		$pieColors[] = 'rgba('.$color[0].','.$color[1].','.$color[2].',0.7)';
	}
	$consumptionChartConfig = array(
		'type' => 'pie',
		'data' => array(
			'labels' => $pieLabels,
			'datasets' => array(array(
				'data' => $pieData,
				'backgroundColor' => $pieColors,
			)),
		),
		'options' => array(
			'responsive' => true,
			'plugins' => array(
				'legend' => array('display' => true, 'position' => 'bottom'),
			),
		),
	);
} else {
	$consumptionChartConfig = array(
		'type' => $chart_type,
		'data' => array('labels' => $dayLabels, 'datasets' => $chartDatasets),
		'options' => array(
			'responsive' => true,
			'plugins' => array(
				'legend' => array('display' => true, 'position' => 'bottom'),
				'tooltip' => array('mode' => 'index', 'intersect' => false),
			),
			'scales' => array(
				'y' => array('beginAtZero' => true, 'title' => array('display' => true, 'text' => $langs->trans('Hours'))),
			),
		),
	);
}

// ---------------------------------------------------------------------------
// Export PDF (dashboard summary)
// ---------------------------------------------------------------------------
$exportRows = array();
foreach ($alertRows as $r) {
	$exportRows[] = array(
		$langs->trans('CreditDashboardPmExportSection') => $langs->trans('CreditDashboardWidgetAlerts'),
		$langs->trans('ThirdParty') => $r['socname'],
		$langs->trans('CreditReportGroupByCreditType') => $r['credit_code'].' - '.$r['credit_label'],
		$langs->trans('CreditReportForecastCurrentBalance') => creditmanagerFormatAmountNum($r['current_balance']),
		$langs->trans('CreditReportForecastMonthsRemaining') => $r['months_remaining'] === null ? '' : creditmanagerFormatAmountNum($r['months_remaining']),
		$langs->trans('CreditReportForecastStatus') => $langs->trans('CreditReportForecastStatus'.ucfirst($r['status'])),
		$langs->trans('Project') => '',
		$langs->trans('CreditDashboardPendingTimesheets') => '',
		$langs->trans('CreditDashboardTotalHours') => '',
	);
}
foreach ($projectRows as $r) {
	$exportRows[] = array(
		$langs->trans('CreditDashboardPmExportSection') => $langs->trans('CreditDashboardWidgetProjects'),
		$langs->trans('ThirdParty') => $r['socname'],
		$langs->trans('CreditReportGroupByCreditType') => '',
		$langs->trans('CreditReportForecastCurrentBalance') => creditmanagerFormatAmountNum($r['client_balance']),
		$langs->trans('CreditReportForecastMonthsRemaining') => '',
		$langs->trans('CreditReportForecastStatus') => '',
		$langs->trans('Project') => trim($r['ref'].' '.$r['title']),
		$langs->trans('CreditDashboardPendingTimesheets') => (string) $r['pending_count'],
		$langs->trans('CreditDashboardTotalHours') => creditmanagerFormatAmountNum($r['total_hours']),
	);
}

$exportHeaders = array(
	$langs->trans('CreditDashboardPmExportSection'),
	$langs->trans('ThirdParty'),
	$langs->trans('CreditReportGroupByCreditType'),
	$langs->trans('CreditReportForecastCurrentBalance'),
	$langs->trans('CreditReportForecastMonthsRemaining'),
	$langs->trans('CreditReportForecastStatus'),
	$langs->trans('Project'),
	$langs->trans('CreditDashboardPendingTimesheets'),
	$langs->trans('CreditDashboardTotalHours'),
);

if ($action === 'export' && creditmanagerCanExport($user)) {
	$format = GETPOST('format', 'aZ09');
	$filenameBase = 'credit_pm_dashboard_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	if ($format === 'pdf') {
		$exporter->exportPDF($filenameBase.'.pdf', $langs->trans('CreditDashboardPmTitle'), $exportHeaders, $exportRows);
	} elseif ($format === 'csv') {
		$exporter->exportCSV($filenameBase.'.csv', $exportHeaders, $exportRows);
	}
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$queryBase = array(
	'preset' => $preset,
	'date_start_day' => (int) dol_print_date($date_start, '%d'),
	'date_start_month' => (int) dol_print_date($date_start, '%m'),
	'date_start_year' => (int) dol_print_date($date_start, '%Y'),
	'date_end_day' => (int) dol_print_date($date_end, '%d'),
	'date_end_month' => (int) dol_print_date($date_end, '%m'),
	'date_end_year' => (int) dol_print_date($date_end, '%Y'),
	'alert_threshold' => $alert_threshold,
	'project_status' => $project_status,
	'chart_type' => $chart_type,
	'chart_period' => $chart_period,
	'chart_group_by' => $chart_group_by,
);
foreach ($search_socids as $id) {
	$queryBase['search_socids'][] = $id;
}
foreach ($search_typeids as $id) {
	$queryBase['search_typeids'][] = $id;
}
foreach ($search_projectids as $id) {
	$queryBase['search_projectids'][] = $id;
}

llxHeader('', $langs->trans('CreditDashboardPmTitle'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-dashboard-pm');

print load_fiche_titre($langs->trans('CreditDashboardPmTitle'), '', 'object_credit@creditmanager');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditDashboardPmSubtitle').'</div>';
print '<div class="opacitymedium marginbottomonly">';
print $langs->trans('CreditReportActivePeriod').': '.dol_print_date($date_start, 'day').' - '.dol_print_date($date_end, 'day');
print '</div>';

// Global filters
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" id="pm_dashboard_filters">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditDashboardPmFilters').'</th></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportPreset').'</label><br>';
print '<select name="preset" class="flat maxwidth200">';
print '<option value=""'.($preset === '' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetCustom').'</option>';
print '<option value="week"'.($preset === 'week' ? ' selected' : '').'>'.$langs->trans('CreditDashboardPresetWeek').'</option>';
print '<option value="month"'.($preset === 'month' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetMonth').'</option>';
print '<option value="quarter"'.($preset === 'quarter' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetQuarter').'</option>';
print '<option value="year"'.($preset === 'year' ? ' selected' : '').'>'.$langs->trans('CreditReportPresetYear').'</option>';
print '</select></td>';
print '<td><label>'.$langs->trans('CreditReportDateStartEnd').'</label><br>';
print $form->selectDate($date_start, 'date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1);
print ' ';
print $form->selectDate($date_end, 'date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1);
print '</td>';
print '<td><label>'.$langs->trans('CreditReportForecastAlertThreshold').'</label><br>';
print '<select name="alert_threshold" class="flat maxwidth200">';
print '<option value=""'.($alert_threshold === '' ? ' selected' : '').'>'.$langs->trans('CreditDashboardAlertAll').'</option>';
print '<option value="warning"'.($alert_threshold === 'warning' ? ' selected' : '').'>'.$langs->trans('CreditReportForecastStatusWarning').'</option>';
print '<option value="critical"'.($alert_threshold === 'critical' ? ' selected' : '').'>'.$langs->trans('CreditReportForecastStatusCritical').'</option>';
print '</select></td>';
print '<td><label>'.$langs->trans('CreditDashboardProjectStatus').'</label><br>';
print '<select name="project_status" class="flat maxwidth200">';
print '<option value=""'.($project_status === '' ? ' selected' : '').'>'.$langs->trans('All').'</option>';
print '<option value="active"'.($project_status === 'active' ? ' selected' : '').'>'.$langs->trans('CreditDashboardProjectActive').'</option>';
print '<option value="closed"'.($project_status === 'closed' ? ' selected' : '').'>'.$langs->trans('CreditDashboardProjectClosed').'</option>';
print '</select></td>';
print '</tr>';

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
		$sel = in_array((int) $o->rowid, $search_socids, true) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag($o->nom).'</option>';
	}
	$db->free($resClients);
}
print '</select></td>';

$sqlTypes = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."credits_types WHERE entity IN (".$entityType.") AND active = 1 ORDER BY code";
$resTypes = $db->query($sqlTypes);
print '<td><label>'.$langs->trans('CreditReportCreditTypes').'</label><br><select class="flat minwidth250" name="search_typeids[]" multiple>';
if ($resTypes) {
	while ($o = $db->fetch_object($resTypes)) {
		$sel = in_array((int) $o->rowid, $search_typeids, true) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag($o->code.' - '.$o->label).'</option>';
	}
	$db->free($resTypes);
}
print '</select></td>';

$allowedProjectIdsSelect = creditmanagerGetReportScopeProjectIdsForSelect($db, $reportScope, $entityProject);
$sqlProjectsSel = "SELECT rowid, ref, title FROM ".MAIN_DB_PREFIX."projet WHERE entity IN (".$entityProject.")";
if ($reportScope['type'] !== 'all') {
	$sqlProjectsSel .= !empty($allowedProjectIdsSelect) ? " AND rowid IN (".implode(',', $allowedProjectIdsSelect).")" : " AND 1=0";
}
$sqlProjectsSel .= " ORDER BY ref";
$resProjectsSel = $db->query($sqlProjectsSel);
print '<td><label>'.$langs->trans('CreditReportProjects').'</label><br><select class="flat minwidth250" name="search_projectids[]" multiple>';
if ($resProjectsSel) {
	while ($o = $db->fetch_object($resProjectsSel)) {
		$sel = in_array((int) $o->rowid, $search_projectids, true) ? ' selected' : '';
		print '<option value="'.((int) $o->rowid).'"'.$sel.'>'.dol_escape_htmltag(trim($o->ref.' '.$o->title)).'</option>';
	}
	$db->free($resProjectsSel);
}
print '</select></td>';

print '<td class="right valignmiddle">';
print '<button class="button" type="submit">'.$langs->trans('CreditReportApplyFilters').'</button> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/custom/creditmanager/dashboard/dashboard_pm.php', 1).'">'.$langs->trans('CreditReportResetFilters').'</a>';
if (creditmanagerCanExport($user)) {
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($queryBase, array('action' => 'export', 'format' => 'pdf', 'token' => $token))).'">'.$langs->trans('CreditDashboardExportPdf').'</a>';
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($queryBase, array('action' => 'export', 'format' => 'csv', 'token' => $token))).'">CSV</a>';
}
print '</td></tr>';
print '</table></div>';

// Widget preferences
print '<div class="div-table-responsive-no-min marginbottomonly" style="margin-top:10px;"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('CreditDashboardWidgetConfig').'</th></tr>';
print '<tr class="oddeven"><td>';
print '<label><input type="checkbox" name="show_alerts" value="1"'.(!empty($widgetPrefs['show_alerts']) ? ' checked' : '').'> '.$langs->trans('CreditDashboardWidgetAlerts').'</label> &nbsp; ';
print '<label><input type="checkbox" name="show_projects" value="1"'.(!empty($widgetPrefs['show_projects']) ? ' checked' : '').'> '.$langs->trans('CreditDashboardWidgetProjects').'</label> &nbsp; ';
print '<label><input type="checkbox" name="show_quick_actions" value="1"'.(!empty($widgetPrefs['show_quick_actions']) ? ' checked' : '').'> '.$langs->trans('CreditDashboardWidgetQuickActions').'</label> &nbsp; ';
print '<label><input type="checkbox" name="show_consumption" value="1"'.(!empty($widgetPrefs['show_consumption']) ? ' checked' : '').'> '.$langs->trans('CreditDashboardWidgetConsumption').'</label>';
print '</td><td>';
print '<label>'.$langs->trans('CreditDashboardAutoRefresh').'</label> ';
print '<select name="refresh_interval" class="flat maxwidth150">';
$refreshOptions = array(
	0 => $langs->trans('CreditDashboardRefreshOff'),
	60 => $langs->trans('CreditDashboardRefresh1min'),
	120 => $langs->trans('CreditDashboardRefresh2min'),
	300 => $langs->trans('CreditDashboardRefresh5min'),
	600 => $langs->trans('CreditDashboardRefresh10min'),
);
foreach ($refreshOptions as $sec => $lbl) {
	print '<option value="'.$sec.'"'.($refreshInterval === (int) $sec ? ' selected' : '').'>'.$lbl.'</option>';
}
print '</select> ';
print '<button class="button small" type="submit" name="action" value="save_widgets">'.$langs->trans('CreditDashboardSaveWidgets').'</button>';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($token).'">';
print '</td></tr></table></div>';

// Quick actions
if (!empty($widgetPrefs['show_quick_actions'])) {
	print '<div class="div-table-responsive-no-min marginbottomonly"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('CreditDashboardWidgetQuickActions').'</th></tr>';
	print '<tr class="oddeven"><td>';
	print '<div class="creditmanager-pm-quick-actions">';

	print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/timesheets/approve.php', 1).'">';
	print dol_escape_htmltag($langs->trans('CreditTimesheetApproveMenu'));
	if ($pendingTotal > 0) {
		print ' <span class="badge badge-warning">'.$pendingTotal.'</span>';
	}
	print '</a>';

	print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/reports/consumption.php', 1).'">';
	print dol_escape_htmltag($langs->trans('CreditReportConsumptionMenu'));
	print '</a>';

	print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/reports/forecast.php', 1).'">';
	print dol_escape_htmltag($langs->trans('CreditReportForecastMenu'));
	print '</a>';

	print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/reports/budget_vs_real.php', 1).'">';
	print dol_escape_htmltag($langs->trans('CreditReportBudgetMenu'));
	print '</a>';

	print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/reports/forecast.php', 1).'?alert_threshold=warning">';
	print dol_escape_htmltag($langs->trans('CreditDashboardLinkAlerts'));
	print '</a>';

	if (creditmanagerCanManageAttributions($user)) {
		print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/admin/attribution.php', 1).'">';
		print dol_escape_htmltag($langs->trans('CreditManagerNewAttribution'));
		print '</a>';
	} else {
		print '<span class="opacitymedium valignmiddle">'.dol_escape_htmltag($langs->trans('CreditDashboardAttributionRestricted')).'</span>';
	}

	print '</div>';
	print '<style>
.creditmanager-pm-quick-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}
.creditmanager-pm-quick-actions .butAction {
	margin: 0 !important;
	float: none !important;
}
</style>';
	print '</td></tr></table></div>';
}

print '<div class="fichecenter">';

// Alerts widget
if (!empty($widgetPrefs['show_alerts'])) {
	print '<div class="fichehalfleft"><div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th colspan="4">'.$langs->trans('CreditDashboardWidgetAlerts');
	print ' <span class="badge">'.count($alertRows).'</span>';
	print '</th></tr>';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('ThirdParty').'</th>';
	print '<th>'.$langs->trans('CreditReportGroupByCreditType').'</th>';
	print '<th class="right">'.$langs->trans('CreditReportForecastCurrentBalance').'</th>';
	print '<th>'.$langs->trans('CreditReportForecastStatus').'</th>';
	print '</tr>';
	if (empty($alertRows)) {
		print '<tr class="oddeven"><td colspan="4" class="opacitymedium center">'.$langs->trans('CreditDashboardNoAlerts').'</td></tr>';
	} else {
		$shown = 0;
		foreach ($alertRows as $r) {
			if ($shown >= 15) {
				break;
			}
			$statusClass = $r['status'] === 'critical' ? 'error' : ($r['status'] === 'warning' ? 'warning' : '');
			$socUrl = dol_buildpath('/societe/card.php', 1).'?socid='.((int) $r['fk_soc']);
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_escape_htmltag($socUrl).'">'.dol_escape_htmltag($r['socname']).'</a></td>';
			print '<td>'.dol_escape_htmltag($r['credit_code'].' - '.$r['credit_label']).'</td>';
			print '<td class="right">'.creditmanagerFormatAmount($r['current_balance']).'</td>';
			print '<td class="'.$statusClass.'">'.$langs->trans('CreditReportForecastStatus'.ucfirst($r['status'])).'</td>';
			print '</tr>';
			$shown++;
		}
	}
	print '</table></div></div>';
}

// Multi-project widget
if (!empty($widgetPrefs['show_projects'])) {
	$colClass = !empty($widgetPrefs['show_alerts']) ? 'fichehalfright' : 'fichehalfleft';
	print '<div class="'.$colClass.'"><div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="5">'.$langs->trans('CreditDashboardWidgetProjects');
	print ' <span class="badge">'.count($projectRows).'</span></th></tr>';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Project').'</th>';
	print '<th>'.$langs->trans('ThirdParty').'</th>';
	print '<th class="right">'.$langs->trans('CreditDashboardPendingTimesheets').'</th>';
	print '<th class="right">'.$langs->trans('CreditDashboardTotalHours').'</th>';
	print '<th class="right">'.$langs->trans('CreditDashboardClientBalance').'</th>';
	print '</tr>';
	if (empty($projectRows)) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans('NoData').'</td></tr>';
	} else {
		$shown = 0;
		foreach ($projectRows as $r) {
			if ($shown >= 20) {
				break;
			}
			$projUrl = dol_buildpath('/projet/card.php', 1).'?id='.((int) $r['rowid']);
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_escape_htmltag($projUrl).'">'.dol_escape_htmltag(trim($r['ref'].' '.$r['title'])).'</a></td>';
			print '<td>'.dol_escape_htmltag($r['socname']).'</td>';
			print '<td class="right">'.((int) $r['pending_count']).'</td>';
			print '<td class="right">'.creditmanagerFormatAmount($r['total_hours']).'</td>';
			print '<td class="right">'.creditmanagerFormatAmount($r['client_balance']).'</td>';
			print '</tr>';
			$shown++;
		}
	}
	print '</table></div></div>';
}

print '</div>'; // fichecenter
print '<div class="clearboth"></div>';

// Consumption chart widget
if (!empty($widgetPrefs['show_consumption'])) {
	print '<div class="div-table-responsive-no-min" style="margin-top:12px;"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditDashboardWidgetConsumption').'</th></tr>';
	print '<tr class="oddeven">';
	print '<td><label>'.$langs->trans('CreditReportMainChart').'</label><br>';
	print '<select name="chart_type" class="flat maxwidth150" onchange="this.form.submit()">';
	print '<option value="bar"'.($chart_type === 'bar' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartBars').'</option>';
	print '<option value="line"'.($chart_type === 'line' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartLines').'</option>';
	print '<option value="pie"'.($chart_type === 'pie' ? ' selected' : '').'>'.$langs->trans('CreditReportMainChartPie').'</option>';
	print '</select></td>';
	print '<td><label>'.$langs->trans('CreditDashboardChartPeriod').'</label><br>';
	print '<select name="chart_period" class="flat maxwidth150" onchange="this.form.submit()">';
	foreach (array(7, 14, 30, 90) as $days) {
		print '<option value="'.$days.'"'.($chart_period === $days ? ' selected' : '').'>'.$langs->trans('CreditDashboardChartPeriodDays', $days).'</option>';
	}
	print '</select></td>';
	print '<td><label>'.$langs->trans('CreditReportGroupBy').'</label><br>';
	print '<select name="chart_group_by" class="flat maxwidth200" onchange="this.form.submit()">';
	$groupOpts = array(
		'client' => $langs->trans('CreditReportGroupByClient'),
		'project' => $langs->trans('CreditReportGroupByProject'),
		'credit_type' => $langs->trans('CreditReportGroupByCreditType'),
		'collaborator' => $langs->trans('CreditReportGroupByCollaborator'),
	);
	foreach ($groupOpts as $k => $lbl) {
		print '<option value="'.$k.'"'.($chart_group_by === $k ? ' selected' : '').'>'.$lbl.'</option>';
	}
	print '</select></td>';
	print '<td class="opacitymedium">'.$langs->trans('CreditDashboardChartPeriodHint', dol_print_date($chartDateStart, 'day'), dol_print_date($chartDateEnd, 'day')).'</td>';
	print '</tr>';
	print '<tr class="oddeven"><td colspan="4"><canvas id="chartPmConsumption" height="160"></canvas></td></tr>';
	print '</table></div>';
}

print '</form>';

$jsChart = json_encode($consumptionChartConfig);
print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.2.0/dist/chartjs-plugin-zoom.min.js"></script>';
print '<script>
(function() {
	var cfg = '.$jsChart.';
	if (!window.Chart || !document.getElementById("chartPmConsumption")) return;
	if (!cfg.options) cfg.options = {};
	if (!cfg.options.plugins) cfg.options.plugins = {};
	cfg.options.plugins.zoom = {
		pan: { enabled: true, mode: "xy" },
		zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: "xy" }
	};
	cfg.options.plugins.legend = cfg.options.plugins.legend || {};
	cfg.options.plugins.legend.onClick = Chart.defaults.plugins.legend.onClick;
	new Chart(document.getElementById("chartPmConsumption"), cfg);
})();
</script>';

if ($refreshInterval > 0) {
	print '<script>
(function() {
	var ms = '.((int) $refreshInterval * 1000).';
	setTimeout(function() { window.location.reload(); }, ms);
})();
</script>';
}

llxFooter();
$db->close();
