<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * Contributor of this script: https://github.com/joelmpunga Joel MPUNGA
 * 
 *  */

/**
 * \file       htdocs/custom/creditmanager/timesheets/debit.php
 * \ingroup    creditmanager
 * \brief      Manual debit of approved timesheets (Element_time) — MVP
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/credit_functions.lib.php';
dol_include_once('/creditmanager/class/CreditDebit.class.php');

creditmanagerEnsureLeftMenuFlat($db);

$langs->loadLangs(array("projects", "companies", "creditmanager@creditmanager"));

if (!creditmanagerCanManualDebit($user)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$token = newToken();
$form = new Form($db);
$formproject = new FormProjets($db);

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
if (!$sortorder) {
	$sortorder = 'DESC';
}
$allowedSort = array(
	'et.element_date' => 'et.element_date',
	'et.rowid' => 'et.rowid',
	'line_soc' => 'line_soc',
	'hours' => 'hours',
);
if (empty($sortfield) || !isset($allowedSort[$sortfield])) {
	$sortfield = 'et.element_date';
}

$search_socid = GETPOSTINT('search_socid');
$search_projectid = GETPOSTINT('search_projectid');
$search_credit_type = GETPOSTINT('search_credit_type');
$search_manual_only = GETPOSTINT('search_manual_only');
$search_deferred_due = GETPOSTINT('search_deferred_due');
$search_fk_user = GETPOSTINT('search_fk_user');
$search_hours_min = GETPOST('search_hours_min', 'alphanohtml');
$search_hours_max = GETPOST('search_hours_max', 'alphanohtml');
$search_text = trim(GETPOST('search_text', 'restricthtml'));
$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_start_month'), GETPOSTINT('search_date_start_day'), GETPOSTINT('search_date_start_year'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_end_month'), GETPOSTINT('search_date_end_day'), GETPOSTINT('search_date_end_year'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_socid = 0;
	$search_projectid = 0;
	$search_credit_type = 0;
	$search_manual_only = 0;
	$search_deferred_due = 0;
	$search_fk_user = 0;
	$search_hours_min = '';
	$search_hours_max = '';
	$search_text = '';
	$search_date_start = '';
	$search_date_end = '';
}

$entityType = getEntity('credits_type');

/**
 * Build FROM/WHERE for approved, not debited timesheets with credit type.
 *
 * @param DoliDB $db
 * @param Conf   $conf
 * @param array  $filters
 * @return string
 */
function creditmanager_manual_debit_list_sql($db, $conf, $filters)
{
	global $user;

	// List is driven only by llx_element_time + task/project (no llx_fichinter).
	// Intervention context is carried by et.intervention_id / ref_ext on the row.
	$sql = " FROM ".$db->prefix()."element_time AS et";
	$sql .= " INNER JOIN ".$db->prefix()."credits_types AS ct ON ct.rowid = et.fk_credit_type AND ct.entity IN (".getEntity('credits_type').")";
	$sql .= " LEFT JOIN ".$db->prefix()."projet_task AS tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
	$sql .= " LEFT JOIN ".$db->prefix()."projet AS pr ON pr.rowid = tsk.fk_projet";
	$sql .= " LEFT JOIN ".$db->prefix()."societe AS s ON s.rowid = pr.fk_soc";
	$sql .= " LEFT JOIN ".$db->prefix()."user AS u ON u.rowid = et.fk_user";
	$sql .= " WHERE et.elementtype = 'task'";
	$sql .= " AND et.fk_element > 0";
	$sql .= " AND et.fk_credit_type IS NOT NULL AND et.fk_credit_type > 0";
	$sql .= " AND UPPER(TRIM(et.credit_status)) = 'APPROVED'";
	$sql .= " AND (et.credit_debit_reference IS NULL OR et.credit_debit_reference = '')";

	if (!empty($filters['socid'])) {
		$sql .= " AND pr.fk_soc = ".((int) $filters['socid']);
	}
	if (!empty($filters['projectid'])) {
		$sql .= " AND pr.rowid = ".((int) $filters['projectid']);
	}
	if (!empty($filters['credit_type'])) {
		$sql .= " AND et.fk_credit_type = ".((int) $filters['credit_type']);
	}
	if (!empty($filters['manual_only'])) {
		$sql .= " AND ct.auto_debit = 0 AND ct.debit_delay_days IS NULL";
	}
	if (!empty($filters['deferred_due'])) {
		$sql .= " AND ct.auto_debit = 0 AND ct.debit_delay_days IS NOT NULL AND et.credit_approval_date IS NOT NULL";
		$sql .= " AND DATE_ADD(et.credit_approval_date, INTERVAL ct.debit_delay_days DAY) <= '".$db->idate(dol_now())."'";
	}
	if (!empty($filters['fk_user'])) {
		$sql .= " AND et.fk_user = ".((int) $filters['fk_user']);
	}
	if ($filters['hours_min'] !== '' && $filters['hours_min'] !== null && is_numeric($filters['hours_min'])) {
		$sql .= " AND (et.element_duration / 3600) >= ".((float) $filters['hours_min']);
	}
	if ($filters['hours_max'] !== '' && $filters['hours_max'] !== null && is_numeric($filters['hours_max'])) {
		$sql .= " AND (et.element_duration / 3600) <= ".((float) $filters['hours_max']);
	}
	if (!empty($filters['date_start'])) {
		$sql .= " AND et.element_date >= '".$db->idate($filters['date_start'])."'";
	}
	if (!empty($filters['date_end'])) {
		$sql .= " AND et.element_date <= '".$db->idate($filters['date_end'])."'";
	}
	if (!empty($filters['text'])) {
		$sql .= natural_search(array('et.note', 'et.ref_ext', 'tsk.ref'), $filters['text']);
	}

	$sql .= creditmanagerTimesheetScopeProjectWhereSql($db, $user, 'pr');

	return $sql;
}

/**
 * @param DoliDB $db
 * @param int    $fk_element_time
 * @return array{fk_soc:int,fk_proj:int,hours:float}|null
 */
function creditmanager_element_time_debit_preview($db, $fk_element_time)
{
	$sql = "SELECT et.rowid, et.element_duration, et.fk_credit_type,";
	$sql .= " COALESCE(pr.fk_soc, 0) AS line_soc,";
	$sql .= " COALESCE(pr.rowid, 0) AS line_proj";
	$sql .= " FROM ".$db->prefix()."element_time AS et";
	$sql .= " LEFT JOIN ".$db->prefix()."projet_task AS tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
	$sql .= " LEFT JOIN ".$db->prefix()."projet AS pr ON pr.rowid = tsk.fk_projet";
	$sql .= " WHERE et.rowid = ".((int) $fk_element_time);
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) {
		return null;
	}
	$hours = ((float) $obj->element_duration) / 3600;
	return array(
		'fk_soc' => (int) $obj->line_soc,
		'fk_proj' => (int) $obj->line_proj,
		'hours' => $hours,
		'fk_credit_type' => (int) $obj->fk_credit_type,
	);
}

$filters = array(
	'socid' => $search_socid,
	'projectid' => $search_projectid,
	'credit_type' => $search_credit_type,
	'manual_only' => $search_manual_only,
	'deferred_due' => $search_deferred_due,
	'fk_user' => $search_fk_user,
	'hours_min' => $search_hours_min,
	'hours_max' => $search_hours_max,
	'date_start' => $search_date_start,
	'date_end' => $search_date_end,
	'text' => $search_text,
);

$sqlBase = creditmanager_manual_debit_list_sql($db, $conf, $filters);

$sortSql = ' ORDER BY ';
if ($sortfield === 'hours') {
	$sortSql .= '(et.element_duration/3600)';
} elseif ($sortfield === 'line_soc') {
	$sortSql .= " COALESCE(pr.fk_soc, 0)";
} else {
	$sortSql .= $allowedSort[$sortfield];
}
$sortSql .= ' '.$sortorder;

$sqlSelect = "SELECT et.rowid, et.elementtype, et.fk_element, et.element_duration, et.element_date, et.note, et.fk_user, et.fk_credit_type,";
$sqlSelect .= " et.ref_ext, et.intervention_id, et.intervention_line_id,";
$sqlSelect .= " et.credit_approval_date, ct.code AS type_code, ct.label AS type_label, ct.auto_debit, ct.debit_delay_days,";
$sqlSelect .= " u.login, u.firstname, u.lastname,";
$sqlSelect .= " COALESCE(NULLIF(TRIM(et.ref_ext), ''), tsk.ref, '') AS origin_ref,";
$sqlSelect .= " COALESCE(pr.fk_soc, 0) AS line_soc,";
$sqlSelect .= " COALESCE(pr.rowid, 0) AS line_proj,";
$sqlSelect .= " pr.ref AS project_ref,";
$sqlSelect .= " pr.title AS project_title,";
$sqlSelect .= " s.nom AS socname";
$sqlSelect .= $sqlBase;

// --- Action: single debit (GET with token) ---
$debit = new CreditDebit($db);

if ($action === 'debit' && GETPOST('token', 'alpha')) {
	$tid = GETPOSTINT('tid');
	if ($tid > 0) {
		$prev = creditmanager_element_time_debit_preview($db, $tid);
		if ($prev && $prev['fk_soc'] > 0) {
			$chk = checkDebitPossible($prev['fk_soc'], $prev['fk_proj'], $prev['fk_credit_type'], $prev['hours'], $db);
			if (!empty($chk['ok'])) {
				$resd = $debit->debitCreditsFromTimesheet($tid, $prev['fk_credit_type'], '');
				if ($resd > 0) {
					setEventMessages($langs->trans('CreditManualDebitDone'), null, 'mesgs');
				} else {
					setEventMessages($debit->error ? $debit->error : $langs->trans('Error'), null, 'errors');
				}
			} else {
				setEventMessages($langs->trans($chk['error']), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'exportcsv') {
	$filename = 'manual_debit_timesheets_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	$csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
	$out = fopen('php://output', 'w');
	if ($out !== false) {
		fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
		fputcsv($out, array('rowid', 'elementtype', 'origin_ref', 'soc', 'project', 'hours', 'type', 'debit_mode', 'date', 'user'), $csvSep);
		$sqlCsv = $sqlSelect.$sortSql;
		$resCsv = $db->query($sqlCsv);
		if ($resCsv) {
			while ($o = $db->fetch_object($resCsv)) {
				$h = ((float) $o->element_duration) / 3600;
				$mode = 'manual';
				if (!empty($o->auto_debit)) {
					$mode = 'auto';
				} elseif (!empty($o->debit_delay_days)) {
					$mode = 'delayed';
				}
				fputcsv($out, array(
					$o->rowid,
					$o->elementtype,
					$o->origin_ref,
					$o->socname,
					$o->project_ref,
					(string) creditmanagerFormatAmountNum($h),
					$o->type_code,
					$mode,
					$o->element_date ? dol_print_date($db->jdate($o->element_date), 'day') : '',
					$o->login,
				), $csvSep);
			}
			$db->free($resCsv);
		}
		fclose($out);
	}
	exit;
}

$sqlCount = "SELECT COUNT(DISTINCT et.rowid) AS nb".$sqlBase;
$resCount = $db->query($sqlCount);
$totalRecords = 0;
if ($resCount) {
	$oc = $db->fetch_object($resCount);
	$totalRecords = (int) ($oc->nb ?? 0);
	$db->free($resCount);
}

$sqlList = $sqlSelect.$sortSql.$db->plimit($limit + 1, $offset);
$resql = $db->query($sqlList);

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
foreach (array(
	'search_socid' => $search_socid,
	'search_projectid' => $search_projectid,
	'search_credit_type' => $search_credit_type,
	'search_manual_only' => $search_manual_only,
	'search_deferred_due' => $search_deferred_due,
	'search_fk_user' => $search_fk_user,
	'search_hours_min' => $search_hours_min,
	'search_hours_max' => $search_hours_max,
	'search_text' => $search_text,
) as $k => $v) {
	if ($v === '' || $v === null) {
		continue;
	}
	if ($k === 'search_text') {
		$param .= '&'.$k.'='.urlencode((string) $v);
	} elseif ($k === 'search_hours_min' || $k === 'search_hours_max') {
		$param .= '&'.$k.'='.urlencode((string) $v);
	} elseif ((int) $v !== 0) {
		$param .= '&'.$k.'='.((int) $v);
	}
}
if (!empty($search_date_start)) {
	$param .= '&search_date_start_day='.dol_print_date($search_date_start, '%d').'&search_date_start_month='.dol_print_date($search_date_start, '%m').'&search_date_start_year='.dol_print_date($search_date_start, '%Y');
}
if (!empty($search_date_end)) {
	$param .= '&search_date_end_day='.dol_print_date($search_date_end, '%d').'&search_date_end_month='.dol_print_date($search_date_end, '%m').'&search_date_end_year='.dol_print_date($search_date_end, '%Y');
}

llxHeader('', $langs->trans('CreditManagerManualDebit'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-timesheets-debit');

print load_fiche_titre($langs->trans('CreditManagerManualDebit'), '', 'object_credit@creditmanager');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditManagerManualDebitHelp').'</div>';
if (creditmanagerCanApproveTimesheets($user)) {
	print '<p class="marginbottomonly"><a href="'.dol_buildpath('/custom/creditmanager/timesheets/approve.php', 1).'">'.$langs->trans('CreditTimesheetApproveTitle').'</a> <span class="opacitymedium">('.$langs->trans('CreditTimesheetApproveLinkHint').')</span></p>';
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="search_form_debit">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"/>';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'"/>';

print_barre_liste($langs->trans('CreditManualDebitListTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $totalRecords, '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre liste_titre_filter">';
print '<td>'.$langs->trans('ThirdParty').'</td>';
print '<td>'.$langs->trans('Project').'</td>';
print '<td>'.$langs->trans('CreditType').'</td>';
print '<td colspan="2">'.$langs->trans('DateRange').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td class="right">'.$langs->trans('CreditManagerActions').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>';
print $form->select_company($search_socid, 'search_socid', '', 1, 0, 0, array(), 0, 'minwidth200', '', 0, 0, array(), false);
print '</td><td>';
print $formproject->select_projects($search_socid > 0 ? $search_socid : -1, $search_projectid, 'search_projectid', 24, 0, 1, 0, 0, 0, '', '', 0, 0, 'maxwidth300', '', '');
print '</td><td>';
$sqlTypes = 'SELECT rowid, code FROM '.$db->prefix().'credits_types WHERE entity IN ('.$entityType.') AND active = 1 ORDER BY code';
$resTypes = $db->query($sqlTypes);
print '<select name="search_credit_type" class="flat maxwidth200"><option value="0"></option>';
if ($resTypes) {
	while ($t = $db->fetch_object($resTypes)) {
		$sel = ($search_credit_type > 0 && (int) $t->rowid === $search_credit_type) ? ' selected' : '';
		print '<option value="'.(int) $t->rowid.'"'.$sel.'>'.dol_escape_htmltag($t->code).'</option>';
	}
	$db->free($resTypes);
}
print '</select></td>';
print '<td colspan="2" class="nowrap">';
print $form->selectDate($search_date_start ?: -1, 'search_date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print ' ';
print $form->selectDate($search_date_end ?: -1, 'search_date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('To'));
print '</td>';
print '<td>';
print $form->select_users($search_fk_user, 'search_fk_user', 1);
print '</td>';
print '<td class="right nowrap">';
print '<input type="submit" class="button small" name="button_search" value="'.$langs->trans('Search').'"> ';
print '<input type="submit" class="button small" name="button_removefilter" value="'.$langs->trans('Reset').'"> ';
print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?action=exportcsv&token='.urlencode($token).$param.'">'.$langs->trans('ExportCSV').'</a>';
print '</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td colspan="2"><label><input type="checkbox" name="search_manual_only" value="1"'.(!empty($search_manual_only) ? ' checked' : '').'> ';
print $langs->trans('CreditManualDebitFilterManualOnly').'</label></td>';
print '<td colspan="2"><label><input type="checkbox" name="search_deferred_due" value="1"'.(!empty($search_deferred_due) ? ' checked' : '').'> ';
print $langs->trans('CreditManualDebitFilterDeferredDue').'</label></td>';
print '<td class="nowrap">'.$langs->trans('HoursMin').' <input type="text" size="4" name="search_hours_min" value="'.dol_escape_htmltag($search_hours_min).'"> ';
print $langs->trans('HoursMax').' <input type="text" size="4" name="search_hours_max" value="'.dol_escape_htmltag($search_hours_max).'"></td>';
print '<td colspan="2"><input type="text" class="flat minwidth200" name="search_text" value="'.dol_escape_htmltag($search_text).'" placeholder="'.$langs->trans('Search').'"></td>';
print '</tr>';
print '</table>';
print '</div>';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'et.element_date', '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('Project').'</th>';
print_liste_field_titre($langs->trans('Duration'), $_SERVER['PHP_SELF'], 'hours', '', $param, 'class="right"', $sortfield, $sortorder);
print '<th>'.$langs->trans('CreditType').'</th>';
print '<th>'.$langs->trans('DebitMode').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th class="center">'.$langs->trans('CreditManagerActions').'</th>';
print '</tr>';

if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;
	$nmax = $limit ? min($num, $limit) : $num;
	while ($i < $nmax) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			break;
		}
		$h = ((float) $obj->element_duration) / 3600;
		$debitMode = $langs->trans('ManualDebit');
		if (!empty($obj->auto_debit)) {
			$debitMode = $langs->trans('ImmediateDebit');
		} elseif (!empty($obj->debit_delay_days)) {
			$debitMode = $langs->trans('DelayedDebit').' ('.(int) $obj->debit_delay_days.')';
		}
		print '<tr class="oddeven">';
		print '<td>'.($obj->element_date ? dol_print_date($db->jdate($obj->element_date), 'day') : '').'</td>';
		$originRef = !empty($obj->origin_ref) ? $obj->origin_ref : ('ET-'.(int) $obj->rowid);
		if (!empty($obj->intervention_id)) {
			$originRef .= ' (FI '.(int) $obj->intervention_id.')';
		}
		print '<td>'.dol_escape_htmltag($originRef).'</td>';
		print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
		print '<td>'.dol_escape_htmltag($obj->project_ref.($obj->project_title ? ' — '.$obj->project_title : '')).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($h).'</td>';
		print '<td>'.dol_escape_htmltag($obj->type_code).'</td>';
		print '<td>'.dol_escape_htmltag($debitMode).'</td>';
		print '<td>'.dol_escape_htmltag($obj->login).'</td>';
		$prev = creditmanager_element_time_debit_preview($db, (int) $obj->rowid);
		print '<td class="center nowrap">';
		$chk = $prev ? checkDebitPossible($prev['fk_soc'], $prev['fk_proj'], $prev['fk_credit_type'], $prev['hours'], $db) : array('ok' => false);
		if (!empty($chk['ok'])) {
			$url = $_SERVER['PHP_SELF'].'?action=debit&tid='.((int) $obj->rowid).'&token='.urlencode($token).$param;
			print '<a class="butAction" href="'.dol_escape_htmltag($url).'" onclick="return confirm(\''.dol_escape_js($langs->trans('CreditManualDebitOneConfirm')).'\');">'.$langs->trans('Debit').'</a>';
		} else {
			print '<span class="opacitymedium" title="'.dol_escape_htmltag(!empty($chk['error']) ? $langs->trans($chk['error']) : '').'">—</span>';
		}
		print '</td>';
		print '</tr>';
		$i++;
	}
	if ($num === 0) {
		print '<tr class="oddeven"><td colspan="9" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
	}
	$db->free($resql);
} else {
	dol_print_error($db);
}

print '</table>';
print '</div>';

print '</form>';

llxFooter();
$db->close();
