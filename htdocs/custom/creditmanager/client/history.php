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
 * \file       htdocs/custom/creditmanager/client/history.php
 * \ingroup    creditmanager
 * \brief      Client portal: credit movement history with filters, charts and export
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditGraph.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditExport.class.php';

$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'projects', 'other', 'bills'));

if (!creditmanagerCanAccessClientPortalPages($user)) {
	accessforbidden();
}

$socid = 0;
if (creditmanagerIsClientPortalUser($user)) {
	$socid = (int) $user->socid;
} else {
	$socid = GETPOSTINT('socid');
	if ($socid <= 0) {
		accessforbidden($langs->trans('CreditClientBalanceNeedSocid'));
	}
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	accessforbidden();
}

$form = new Form($db);
$graph = new CreditGraph();
$exporter = new CreditExport();
$token = newToken();
$action = GETPOST('action', 'aZ09');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;

$allowedSort = array(
	'm.date_movement' => 'm.date_movement',
	't.code' => 't.code',
	'm.type_movement' => 'm.type_movement',
	'm.amount' => 'm.amount',
	'm.description' => 'm.description',
	'm.rowid' => 'm.rowid',
);
if (empty($sortfield) || !isset($allowedSort[$sortfield])) {
	$sortfield = 'm.date_movement';
}
if (!in_array(strtoupper((string) $sortorder), array('ASC', 'DESC'), true)) {
	$sortorder = 'DESC';
}

$search_typeids = GETPOST('search_typeids', 'array');
if (!is_array($search_typeids)) {
	$search_typeids = array();
}
$search_typeids = array_values(array_unique(array_filter(array_map('intval', $search_typeids), function ($v) {
	return $v > 0;
})));

$search_movement_type = GETPOST('search_movement_type', 'aZ09'); // credit|debit|''
if (!in_array($search_movement_type, array('', 'credit', 'debit'), true)) {
	$search_movement_type = '';
}

$search_status = GETPOST('search_status', 'aZ09'); // ''|validated|pending
if (!in_array($search_status, array('', 'validated', 'pending'), true)) {
	$search_status = '';
}

$search_projectid = GETPOSTINT('search_projectid');
$search_text = trim(GETPOST('search_text', 'restricthtml'));
$search_amount_min = GETPOST('search_amount_min', 'alphanohtml');
$search_amount_max = GETPOST('search_amount_max', 'alphanohtml');
$search_amount_min = ($search_amount_min !== '' && is_numeric($search_amount_min)) ? (float) price2num($search_amount_min, 'MT') : null;
$search_amount_max = ($search_amount_max !== '' && is_numeric($search_amount_max)) ? (float) price2num($search_amount_max, 'MT') : null;

$period_preset = GETPOST('period_preset', 'aZ09');
$allowedPresets = array('', 'week', 'month', 'quarter', 'year', 'custom');
if (!in_array($period_preset, $allowedPresets, true)) {
	$period_preset = 'month';
}

$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_start_month'), GETPOSTINT('search_date_start_day'), GETPOSTINT('search_date_start_year'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_end_month'), GETPOSTINT('search_date_end_day'), GETPOSTINT('search_date_end_year'));

if ($period_preset !== 'custom' && $period_preset !== '') {
	$now = dol_now();
	if ($period_preset === 'week') {
		$search_date_start = dol_time_plus_duree($now, -7, 'd');
	} elseif ($period_preset === 'month') {
		$search_date_start = dol_time_plus_duree($now, -1, 'm');
	} elseif ($period_preset === 'quarter') {
		$search_date_start = dol_time_plus_duree($now, -3, 'm');
	} elseif ($period_preset === 'year') {
		$search_date_start = dol_time_plus_duree($now, -1, 'y');
	}
	$search_date_end = $now;
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_typeids = array();
	$search_movement_type = '';
	$search_status = '';
	$search_projectid = 0;
	$search_text = '';
	$search_amount_min = null;
	$search_amount_max = null;
	$period_preset = 'month';
	$now = dol_now();
	$search_date_start = dol_time_plus_duree($now, -1, 'm');
	$search_date_end = $now;
}

$entityMovement = getEntity('credits_movement');
$entityType = getEntity('credits_type');
$entityProject = getEntity('project');

/**
 * Build WHERE clause for client history.
 *
 * @param DoliDB $db
 * @param array  $filters
 * @return string
 */
function creditmanager_client_history_where($db, $filters)
{
	$sql = " WHERE m.entity IN (".$filters['entity_movement'].")";
	$sql .= " AND m.fk_soc = ".((int) $filters['fk_soc']);
	$sql .= " AND t.entity IN (".$filters['entity_type'].")";

	if (!empty($filters['typeids']) && is_array($filters['typeids'])) {
		$sql .= " AND m.fk_credit_type IN (".implode(',', array_map('intval', $filters['typeids'])).")";
	}
	if (!empty($filters['movement_type']) && $filters['movement_type'] === 'credit') {
		$sql .= " AND m.amount > 0";
	} elseif (!empty($filters['movement_type']) && $filters['movement_type'] === 'debit') {
		$sql .= " AND m.amount < 0";
	}
	if (!empty($filters['date_start'])) {
		$sql .= " AND m.date_movement >= '".$db->idate($filters['date_start'])."'";
	}
	if (!empty($filters['date_end'])) {
		$sql .= " AND m.date_movement <= '".$db->idate($filters['date_end'])."'";
	}
	if ($filters['amount_min'] !== null) {
		$sql .= " AND ABS(m.amount) >= ".((float) $filters['amount_min']);
	}
	if ($filters['amount_max'] !== null) {
		$sql .= " AND ABS(m.amount) <= ".((float) $filters['amount_max']);
	}
	if (!empty($filters['search_text'])) {
		$sql .= natural_search(
			array('m.description', 't.code', 't.label', 'et.credit_debit_reference'),
			$filters['search_text']
		);
	}
	if (!empty($filters['fk_project'])) {
		$sql .= " AND pr.rowid = ".((int) $filters['fk_project']);
	}
	// Status: validated = posted movements; pending = debit linked to non-DEBITED timesheet (edge), else attributions always validated
	if (!empty($filters['status']) && $filters['status'] === 'validated') {
		$sql .= " AND (et.rowid IS NULL OR UPPER(TRIM(COALESCE(et.credit_status, ''))) IN ('DEBITED', 'APPROVED', '') OR m.amount > 0)";
	} elseif (!empty($filters['status']) && $filters['status'] === 'pending') {
		$sql .= " AND et.rowid IS NOT NULL AND UPPER(TRIM(et.credit_status)) IN ('SUBMITTED', 'APPROVED') AND m.amount < 0";
		$sql .= " AND (et.credit_debit_reference IS NULL OR et.credit_debit_reference = '')";
	}

	return $sql;
}

$filters = array(
	'fk_soc' => $socid,
	'typeids' => $search_typeids,
	'movement_type' => $search_movement_type,
	'date_start' => $search_date_start,
	'date_end' => $search_date_end,
	'amount_min' => $search_amount_min,
	'amount_max' => $search_amount_max,
	'search_text' => $search_text,
	'fk_project' => $search_projectid > 0 ? $search_projectid : 0,
	'status' => $search_status,
	'entity_movement' => $entityMovement,
	'entity_type' => $entityType,
);

$fromSql = " FROM ".$db->prefix()."credits_movements as m";
$fromSql .= " INNER JOIN ".$db->prefix()."credits_types as t ON t.rowid = m.fk_credit_type";
$fromSql .= " LEFT JOIN ".$db->prefix()."element_time as et ON et.rowid = COALESCE(m.fk_element_time, m.fk_timesheet)";
$fromSql .= " LEFT JOIN ".$db->prefix()."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
$fromSql .= " LEFT JOIN ".$db->prefix()."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
$fromSql .= " LEFT JOIN ".$db->prefix()."facture as f ON f.rowid = m.fk_invoice";

$whereSql = creditmanager_client_history_where($db, $filters);

/*
 * Export
 */
if (in_array($action, array('exportcsv', 'exportpdf'), true)) {
	$sqlExport = "SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,";
	$sqlExport .= " t.code as type_code, t.label as type_label,";
	$sqlExport .= " et.credit_debit_reference, et.credit_status,";
	$sqlExport .= " pr.ref as project_ref, f.ref as invoice_ref";
	$sqlExport .= $fromSql.$whereSql;
	$sqlExport .= " ORDER BY m.date_movement DESC, m.rowid DESC";

	$exportRows = array();
	$resExport = $db->query($sqlExport);
	if ($resExport) {
		while ($obj = $db->fetch_object($resExport)) {
			$ref = '';
			if (!empty($obj->credit_debit_reference)) {
				$ref = $obj->credit_debit_reference;
			} elseif (!empty($obj->invoice_ref)) {
				$ref = $obj->invoice_ref;
			} else {
				$ref = 'MVT-'.((int) $obj->rowid);
			}
			$exportRows[] = array(
				$db->jdate($obj->date_movement) ? dol_print_date($db->jdate($obj->date_movement), 'dayhour') : '',
				$obj->type_code.(!empty($obj->type_label) ? ' - '.$obj->type_label : ''),
				$obj->type_movement,
				creditmanagerFormatAmountNum($obj->amount),
				$obj->description,
				$ref,
			);
		}
		$db->free($resExport);
	}

	$headers = array(
		$langs->trans('Date'),
		$langs->trans('CreditType'),
		$langs->trans('CreditClientMovementType'),
		$langs->trans('Amount'),
		$langs->trans('Description'),
		$langs->trans('Ref'),
	);
	$filenameBase = 'credit_history_'.$socid.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');

	if ($action === 'exportcsv') {
		$exporter->exportCSV($filenameBase.'.csv', $headers, $exportRows);
	}
	$exporter->exportPDF(
		$filenameBase.'.pdf',
		$langs->trans('CreditClientHistoryTitle').' - '.$object->name,
		$headers,
		$exportRows
	);
}

/*
 * Data for list + charts
 */
$sqlCount = "SELECT COUNT(m.rowid) as nb".$fromSql.$whereSql;
$totalRecords = 0;
$resCount = $db->query($sqlCount);
if ($resCount) {
	$objCount = $db->fetch_object($resCount);
	$totalRecords = (int) ($objCount->nb ?? 0);
	$db->free($resCount);
}

$sqlList = "SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,";
$sqlList .= " m.fk_invoice, m.fk_element_time, m.fk_timesheet,";
$sqlList .= " t.code as type_code, t.label as type_label,";
$sqlList .= " et.credit_debit_reference, et.credit_status, et.rowid as timesheet_id,";
$sqlList .= " pr.rowid as project_id, pr.ref as project_ref, pr.title as project_title,";
$sqlList .= " f.rowid as invoice_id, f.ref as invoice_ref, f.last_main_doc as invoice_doc";
$sqlList .= $fromSql.$whereSql;
$sqlList .= $db->order($sortfield, $sortorder);
$sqlList .= $db->plimit($limit + 1, $offset);

$resList = $db->query($sqlList);
$listRows = array();
if ($resList) {
	$num = $db->num_rows($resList);
	$i = 0;
	while ($i < min($num, $limit)) {
		$obj = $db->fetch_object($resList);
		if (!$obj) {
			break;
		}
		$listRows[] = $obj;
		$i++;
	}
	$db->free($resList);
}

// Chart aggregates (same filters, no pagination)
$sqlAgg = "SELECT m.date_movement, m.amount, m.balance_after, t.code";
$sqlAgg .= $fromSql.$whereSql;
$sqlAgg .= " ORDER BY m.date_movement ASC, m.rowid ASC";

$evoPoints = array();
$typeCodes = array();
$creditTotal = 0.0;
$debitTotal = 0.0;
$monthly = array();

$resAgg = $db->query($sqlAgg);
if ($resAgg) {
	$runningByType = array();
	while ($obj = $db->fetch_object($resAgg)) {
		$code = $obj->code;
		$amount = (float) $obj->amount;
		if (!isset($runningByType[$code])) {
			$runningByType[$code] = 0.0;
			$typeCodes[] = $code;
		}
		if (isset($obj->balance_after) && $obj->balance_after !== null && $obj->balance_after !== '') {
			$runningByType[$code] = (float) $obj->balance_after;
		} else {
			$runningByType[$code] += $amount;
		}
		$evoPoints[] = array(
			'date' => dol_print_date($db->jdate($obj->date_movement), '%Y-%m-%d'),
			'type_code' => $code,
			'balance' => $runningByType[$code],
		);

		if ($amount >= 0) {
			$creditTotal += $amount;
		} else {
			$debitTotal += abs($amount);
		}

		$monthKey = dol_print_date($db->jdate($obj->date_movement), '%Y-%m');
		if (!isset($monthly[$monthKey])) {
			$monthly[$monthKey] = array('month' => $monthKey, 'credit' => 0.0, 'debit' => 0.0);
		}
		if ($amount >= 0) {
			$monthly[$monthKey]['credit'] += $amount;
		} else {
			$monthly[$monthKey]['debit'] += abs($amount);
		}
	}
	$db->free($resAgg);
}

ksort($monthly);
$monthlyRows = array_values($monthly);

$evolutionChart = $graph->buildBalanceEvolutionChart($evoPoints, $typeCodes);
$creditDebitChart = $graph->buildMovementCreditDebitChart(
	$creditTotal,
	$debitTotal,
	$langs->trans('CreditClientMovementCredit'),
	$langs->trans('CreditClientMovementDebit')
);
$monthlyChart = $graph->buildMonthlyMovementsChart(
	$monthlyRows,
	$langs->trans('CreditClientMovementCredit'),
	$langs->trans('CreditClientMovementDebit')
);

// Filter option data
$typeOptions = array();
$sqlType = "SELECT rowid, code, label FROM ".$db->prefix()."credits_types";
$sqlType .= " WHERE entity IN (".$entityType.") AND active = 1 ORDER BY code ASC";
$resType = $db->query($sqlType);
if ($resType) {
	while ($obj = $db->fetch_object($resType)) {
		$typeOptions[(int) $obj->rowid] = $obj->code.(!empty($obj->label) ? ' - '.$obj->label : '');
	}
	$db->free($resType);
}

$projectOptions = array();
$sqlPr = "SELECT DISTINCT pr.rowid, pr.ref, pr.title";
$sqlPr .= " FROM ".$db->prefix()."projet as pr";
$sqlPr .= " WHERE pr.entity IN (".$entityProject.")";
$sqlPr .= " AND pr.fk_soc = ".((int) $socid);
$sqlPr .= " ORDER BY pr.ref ASC";
$sqlPr .= $db->plimit(200, 0);
$resPr = $db->query($sqlPr);
if ($resPr) {
	while ($obj = $db->fetch_object($resPr)) {
		$projectOptions[(int) $obj->rowid] = trim($obj->ref.' '.$obj->title);
	}
	$db->free($resPr);
}

$presetOptions = array(
	'week' => $langs->trans('CreditClientPresetWeek'),
	'month' => $langs->trans('CreditClientPresetMonth'),
	'quarter' => $langs->trans('CreditClientPresetQuarter'),
	'year' => $langs->trans('CreditClientPresetYear'),
	'custom' => $langs->trans('CreditClientPresetCustom'),
);

$movementTypeOptions = array(
	'' => $langs->trans('CreditClientMovementAll'),
	'credit' => $langs->trans('CreditClientMovementCredit'),
	'debit' => $langs->trans('CreditClientMovementDebit'),
);

$statusOptions = array(
	'' => $langs->trans('CreditClientStatusAll'),
	'validated' => $langs->trans('CreditClientStatusValidated'),
	'pending' => $langs->trans('CreditClientStatusPending'),
);

$selfUrl = dol_buildpath('/custom/creditmanager/client/history.php', 1);
$balanceUrl = dol_buildpath('/custom/creditmanager/client/balance.php', 1);
$queryBase = array();
if (empty($user->socid)) {
	$queryBase['socid'] = $socid;
}

$param = '';
if (!empty($queryBase['socid'])) {
	$param .= '&socid='.((int) $socid);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
foreach ($search_typeids as $tid) {
	$param .= '&search_typeids[]='.((int) $tid);
}
if ($search_movement_type !== '') {
	$param .= '&search_movement_type='.urlencode($search_movement_type);
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}
if ($search_projectid > 0) {
	$param .= '&search_projectid='.((int) $search_projectid);
}
if ($search_text !== '') {
	$param .= '&search_text='.urlencode($search_text);
}
if ($search_amount_min !== null) {
	$param .= '&search_amount_min='.urlencode((string) $search_amount_min);
}
if ($search_amount_max !== null) {
	$param .= '&search_amount_max='.urlencode((string) $search_amount_max);
}
if ($period_preset !== '') {
	$param .= '&period_preset='.urlencode($period_preset);
}
if (!empty($search_date_start)) {
	$param .= '&search_date_start_day='.dol_print_date($search_date_start, '%d').'&search_date_start_month='.dol_print_date($search_date_start, '%m').'&search_date_start_year='.dol_print_date($search_date_start, '%Y');
}
if (!empty($search_date_end)) {
	$param .= '&search_date_end_day='.dol_print_date($search_date_end, '%d').'&search_date_end_month='.dol_print_date($search_date_end, '%m').'&search_date_end_year='.dol_print_date($search_date_end, '%Y');
}

$exportQuery = $queryBase;
$exportQuery['token'] = $token;
$exportQuery['period_preset'] = $period_preset;
$exportQuery['search_movement_type'] = $search_movement_type;
$exportQuery['search_status'] = $search_status;
$exportQuery['search_projectid'] = $search_projectid;
$exportQuery['search_text'] = $search_text;
if ($search_amount_min !== null) {
	$exportQuery['search_amount_min'] = $search_amount_min;
}
if ($search_amount_max !== null) {
	$exportQuery['search_amount_max'] = $search_amount_max;
}
foreach ($search_typeids as $tid) {
	$exportQuery['search_typeids'][] = $tid;
}
if (!empty($search_date_start)) {
	$exportQuery['search_date_start_day'] = dol_print_date($search_date_start, '%d');
	$exportQuery['search_date_start_month'] = dol_print_date($search_date_start, '%m');
	$exportQuery['search_date_start_year'] = dol_print_date($search_date_start, '%Y');
}
if (!empty($search_date_end)) {
	$exportQuery['search_date_end_day'] = dol_print_date($search_date_end, '%d');
	$exportQuery['search_date_end_month'] = dol_print_date($search_date_end, '%m');
	$exportQuery['search_date_end_year'] = dol_print_date($search_date_end, '%Y');
}

/*
 * View
 */
$morecss = array('/custom/creditmanager/css/creditmanager.css');
creditmanagerEnsureLeftMenuFlat($db);
llxHeader('', $langs->trans('CreditClientHistoryTitle'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-client-history');

print load_fiche_titre($langs->trans('CreditClientHistoryTitle'), '', 'object_bill');
print '<div class="opacitymedium marginbottomonly">'.dol_escape_htmltag($object->name).'</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_escape_htmltag($balanceUrl.(empty($user->socid) ? '?socid='.$socid : '')).'">'.$langs->trans('CreditClientBalanceTitle').'</a>';
$csvQ = $exportQuery;
$csvQ['action'] = 'exportcsv';
$pdfQ = $exportQuery;
$pdfQ['action'] = 'exportpdf';
print '<a class="butAction" href="'.dol_escape_htmltag($selfUrl.'?'.http_build_query($csvQ)).'">'.$langs->trans('CreditClientExportCsv').'</a>';
print '<a class="butAction" href="'.dol_escape_htmltag($selfUrl.'?'.http_build_query($pdfQ)).'">'.$langs->trans('CreditClientExportPdf').'</a>';
print '</div>';

// Filters
print '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'" name="history_filters">';
print '<input type="hidden" name="token" value="'.$token.'">';
if (empty($user->socid)) {
	print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
}
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditReportFilters').'</th></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditClientPeriodPreset').'</label><br>';
print $form->selectarray('period_preset', $presetOptions, $period_preset, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td>';
print '<td><label>'.$langs->trans('DateStart').'</label><br>';
print $form->selectDate($search_date_start ? $search_date_start : '', 'search_date_start_', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td><label>'.$langs->trans('DateEnd').'</label><br>';
print $form->selectDate($search_date_end ? $search_date_end : '', 'search_date_end_', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td><label>'.$langs->trans('CreditType').'</label><br>';
print $form->multiselectarray('search_typeids', $typeOptions, $search_typeids, 0, 0, 'minwidth200', 0, 0);
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditClientMovementType').'</label><br>';
print $form->selectarray('search_movement_type', $movementTypeOptions, $search_movement_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td>';
print '<td><label>'.$langs->trans('CreditClientAmountMin').'</label><br>';
print '<input type="number" class="maxwidth100" name="search_amount_min" step="0.01" min="0" value="'.($search_amount_min !== null ? dol_escape_htmltag((string) $search_amount_min) : '').'">';
print '</td>';
print '<td><label>'.$langs->trans('CreditClientAmountMax').'</label><br>';
print '<input type="number" class="maxwidth100" name="search_amount_max" step="0.01" min="0" value="'.($search_amount_max !== null ? dol_escape_htmltag((string) $search_amount_max) : '').'">';
print '</td>';
print '<td><label>'.$langs->trans('Project').'</label><br>';
print $form->selectarray('search_projectid', $projectOptions, $search_projectid, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditClientStatus').'</label><br>';
print $form->selectarray('search_status', $statusOptions, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td>';
print '<td colspan="2"><label>'.$langs->trans('Search').'</label><br>';
print '<input type="text" class="minwidth300" name="search_text" value="'.dol_escape_htmltag($search_text).'" placeholder="'.dol_escape_htmltag($langs->trans('CreditClientSearchPlaceholder')).'">';
print '</td>';
print '<td class="right valignmiddle">';
print '<button type="submit" class="button" name="button_search" value="1">'.$langs->trans('Refresh').'</button> ';
print '<button type="submit" class="button button-cancel" name="button_removefilter" value="1">'.$langs->trans('CreditReportResetFilters').'</button>';
print '</td>';
print '</tr>';
print '</table></div>';

// Charts
print '<div class="fichecenter">';
print '<div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('CreditClientHistoryEvolutionChart').'</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartHistoryEvo" height="160"></canvas></td></tr></table></div></div>';
print '<div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('CreditClientHistoryCreditDebitChart').'</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartHistoryPie" height="160"></canvas></td></tr></table></div></div>';
print '</div><div class="clearboth"></div>';

print '<div class="div-table-responsive-no-min" style="margin-top:12px;">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('CreditClientHistoryMonthlyChart').'</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartHistoryMonthly" height="140"></canvas></td></tr></table></div>';

print_barre_liste($langs->trans('CreditClientHistoryList'), $page, $selfUrl, $param, $sortfield, $sortorder, '', $totalRecords, $totalRecords, '', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'), $selfUrl, 'm.date_movement', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('CreditType'), $selfUrl, 't.code', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('CreditClientMovementType'), $selfUrl, 'm.type_movement', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Amount'), $selfUrl, 'm.amount', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Description'), $selfUrl, 'm.description', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Ref'), $selfUrl, 'm.rowid', '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('Actions').'</th>';
print '</tr>';

if (empty($listRows)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('CreditClientHistoryEmpty').'</td></tr>';
}

foreach ($listRows as $obj) {
	$amount = (float) $obj->amount;
	$cls = $amount < 0 ? 'amountnegative' : 'amount';
	$ref = '';
	if (!empty($obj->credit_debit_reference)) {
		$ref = $obj->credit_debit_reference;
	} elseif (!empty($obj->invoice_ref)) {
		$ref = $obj->invoice_ref;
	} else {
		$ref = 'MVT-'.((int) $obj->rowid);
	}

	$timesheetId = !empty($obj->timesheet_id) ? (int) $obj->timesheet_id : (!empty($obj->fk_element_time) ? (int) $obj->fk_element_time : (int) $obj->fk_timesheet);
	$invoiceId = !empty($obj->invoice_id) ? (int) $obj->invoice_id : (int) $obj->fk_invoice;

	print '<tr class="oddeven">';
	print '<td>'.($obj->date_movement ? dol_print_date($db->jdate($obj->date_movement), 'dayhour') : '').'</td>';
	print '<td>'.dol_escape_htmltag($obj->type_code.(!empty($obj->type_label) ? ' - '.$obj->type_label : '')).'</td>';
	print '<td>'.dol_escape_htmltag($obj->type_movement).'</td>';
	print '<td class="right '.$cls.'">'.creditmanagerFormatAmount($amount).'</td>';
	print '<td>'.dol_escape_htmltag($obj->description).'</td>';
	print '<td>'.dol_escape_htmltag($ref).'</td>';
	print '<td class="nowraponall">';
	$actions = array();
	if ($timesheetId > 0) {
		// Timesheet lines are often opened from project task time; use approve list as fallback for clients
		$tsUrl = dol_buildpath('/projet/tasks/time.php', 1);
		$actions[] = '<a href="'.dol_escape_htmltag($tsUrl).'" title="'.dol_escape_htmltag($langs->trans('CreditClientLinkTimesheet')).'">'.img_picto($langs->trans('CreditClientLinkTimesheet'), 'projecttask').'</a>';
	}
	if ($invoiceId > 0) {
		$invUrl = dol_buildpath('/compta/facture/card.php', 1).'?id='.$invoiceId;
		$actions[] = '<a href="'.dol_escape_htmltag($invUrl).'" title="'.dol_escape_htmltag($langs->trans('CreditClientLinkInvoice')).'">'.img_picto($langs->trans('CreditClientLinkInvoice'), 'bill').'</a>';
		if (!empty($obj->invoice_doc)) {
			$docUrl = DOL_URL_ROOT.'/document.php?modulepart=facture&file='.urlencode($obj->invoice_doc);
			$actions[] = '<a href="'.dol_escape_htmltag($docUrl).'" title="'.dol_escape_htmltag($langs->trans('CreditClientDownloadReceipt')).'">'.img_picto($langs->trans('CreditClientDownloadReceipt'), 'download').'</a>';
		}
	}
	if (!empty($obj->project_id)) {
		$prUrl = dol_buildpath('/projet/card.php', 1).'?id='.((int) $obj->project_id);
		$actions[] = '<a href="'.dol_escape_htmltag($prUrl).'" title="'.dol_escape_htmltag($obj->project_ref).'">'.img_picto($langs->trans('Project'), 'project').'</a>';
	}
	print !empty($actions) ? implode(' ', $actions) : '<span class="opacitymedium">-</span>';
	print '</td>';
	print '</tr>';
}
print '</table></div>';
print '</form>';

$jsEvo = json_encode($evolutionChart);
$jsPie = json_encode($creditDebitChart);
$jsMonthly = json_encode($monthlyChart);

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.2.0/dist/chartjs-plugin-zoom.min.js"></script>';
print '<script>
(function() {
	var evoCfg = '.$jsEvo.';
	var pieCfg = '.$jsPie.';
	var monthlyCfg = '.$jsMonthly.';
	var optionsZoom = {
		pan: { enabled: true, mode: "xy" },
		zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: "xy" }
	};
	if (window.Chart) {
		if (!evoCfg.options) evoCfg.options = {};
		if (!evoCfg.options.plugins) evoCfg.options.plugins = {};
		evoCfg.options.plugins.zoom = optionsZoom;
		if (!monthlyCfg.options) monthlyCfg.options = {};
		if (!monthlyCfg.options.plugins) monthlyCfg.options.plugins = {};
		monthlyCfg.options.plugins.zoom = optionsZoom;
		new Chart(document.getElementById("chartHistoryEvo"), evoCfg);
		new Chart(document.getElementById("chartHistoryPie"), pieCfg);
		new Chart(document.getElementById("chartHistoryMonthly"), monthlyCfg);
	}
})();
</script>';

llxFooter();
$db->close();
