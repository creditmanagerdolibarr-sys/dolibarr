<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr */

/**
 * \file        htdocs/custom/creditmanager/timesheets/approve.php
 * \ingroup     creditmanager
 * \brief       Review and approve submitted timesheets (assign credit type)
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
dol_include_once('/creditmanager/class/CreditDebit.class.php');

creditmanagerEnsureLeftMenuFlat($db);

$langs->loadLangs(array("projects", "companies", "creditmanager@creditmanager"));

if (!creditmanagerCanApproveTimesheets($user)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$token = newToken();
$form = new Form($db);
$formproject = new FormProjets($db);
$debit = new CreditDebit($db);

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
if (empty($sortfield)) {
	$sortfield = 'et.element_date';
}

$search_socid = GETPOSTINT('search_socid');
$search_projectid = GETPOSTINT('search_projectid');
$search_fk_user = GETPOSTINT('search_fk_user');
$search_text = trim(GETPOST('search_text', 'restricthtml'));
$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_start_month'), GETPOSTINT('search_date_start_day'), GETPOSTINT('search_date_start_year'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_end_month'), GETPOSTINT('search_date_end_day'), GETPOSTINT('search_date_end_year'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_socid = 0;
	$search_projectid = 0;
	$search_fk_user = 0;
	$search_text = '';
	$search_date_start = '';
	$search_date_end = '';
}

$entityType = getEntity('credits_type');
$entitySoc = getEntity('societe');
$financialScope = creditmanagerGetReportScope($db, $user);
creditmanagerValidateFinancialScopeSocId($financialScope, $search_socid, $db);

/**
 * @param DoliDB $db
 * @param User $user
 * @param array $filters
 * @return string
 */
function creditmanager_approve_list_sql($db, $user, $filters)
{
	$sql = " FROM ".$db->prefix()."element_time AS et";
	$sql .= " LEFT JOIN ".$db->prefix()."credits_types AS ct ON ct.rowid = et.fk_credit_type AND ct.entity IN (".getEntity('credits_type').")";
	$sql .= " LEFT JOIN ".$db->prefix()."projet_task AS tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
	$sql .= " LEFT JOIN ".$db->prefix()."projet AS pr ON pr.rowid = tsk.fk_projet";
	$sql .= " LEFT JOIN ".$db->prefix()."societe AS s ON s.rowid = pr.fk_soc";
	$sql .= " LEFT JOIN ".$db->prefix()."user AS u ON u.rowid = et.fk_user";
	$sql .= " WHERE et.elementtype = 'task'";
	$sql .= " AND et.fk_element > 0";
	$sql .= " AND UPPER(TRIM(et.credit_status)) = 'SUBMITTED'";
	$sql .= " AND (et.credit_debit_reference IS NULL OR et.credit_debit_reference = '')";

	$sql .= creditmanagerTimesheetScopeProjectWhereSql($db, $user, 'pr');

	if (!empty($filters['socid'])) {
		$sql .= " AND pr.fk_soc = ".((int) $filters['socid']);
	}
	if (!empty($filters['projectid'])) {
		$sql .= " AND pr.rowid = ".((int) $filters['projectid']);
	}
	if (!empty($filters['fk_user'])) {
		$sql .= " AND et.fk_user = ".((int) $filters['fk_user']);
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

	return $sql;
}

$filters = array(
	'socid' => $search_socid,
	'projectid' => $search_projectid,
	'fk_user' => $search_fk_user,
	'date_start' => $search_date_start,
	'date_end' => $search_date_end,
	'text' => $search_text,
);
$sqlBase = creditmanager_approve_list_sql($db, $user, $filters);

if ($action === 'approve' && GETPOST('token', 'alpha')) {
	$tid = GETPOSTINT('tid');
	$typeid = GETPOSTINT('fk_credit_type');
	if ($tid > 0 && $typeid > 0) {
		$resApprove = $debit->approveTimesheet($tid, $typeid);
		if ($resApprove > 0) {
			setEventMessages($langs->trans('CreditTimesheetApproveDone'), null, 'mesgs');
		} else {
			setEventMessages($debit->error ? $debit->error : $langs->trans('Error'), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('CreditTimesheetApproveNeedType'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'reject' && GETPOST('token', 'alpha')) {
	$tid = GETPOSTINT('tid');
	$comment = trim(GETPOST('reject_comment', 'restricthtml'));
	if ($tid > 0) {
		$resReject = $debit->rejectTimesheet($tid, $comment);
		if ($resReject > 0) {
			setEventMessages($langs->trans('CreditTimesheetRejectDone'), null, 'mesgs');
		} else {
			setEventMessages($debit->error ? $debit->error : $langs->trans('Error'), null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$sortSql = ' ORDER BY '.$db->escape($sortfield).' '.$db->escape($sortorder);

$sqlSelect = "SELECT et.rowid, et.element_duration, et.element_date, et.note, et.fk_user, et.fk_credit_type,";
$sqlSelect .= " et.ref_ext, u.login,";
$sqlSelect .= " COALESCE(NULLIF(TRIM(et.ref_ext), ''), tsk.ref, '') AS origin_ref,";
$sqlSelect .= " pr.rowid AS line_proj, pr.ref AS project_ref, pr.title AS project_title,";
$sqlSelect .= " s.nom AS socname, ct.code AS type_code";
$sqlSelect .= $sqlBase;

$sqlCount = "SELECT COUNT(DISTINCT et.rowid) AS nb".$sqlBase;
$resCount = $db->query($sqlCount);
$totalRecords = 0;
if ($resCount) {
	$oc = $db->fetch_object($resCount);
	$totalRecords = (int) ($oc->nb ?? 0);
	$db->free($resCount);
}

$resql = $db->query($sqlSelect.$sortSql.$db->plimit($limit + 1, $offset));

$creditTypes = array();
$sqlTypes = 'SELECT rowid, code, label FROM '.$db->prefix().'credits_types WHERE entity IN ('.$entityType.') AND active = 1 ORDER BY code';
$resTypes = $db->query($sqlTypes);
if ($resTypes) {
	while ($t = $db->fetch_object($resTypes)) {
		$creditTypes[] = $t;
	}
	$db->free($resTypes);
}

$param = '';
foreach (array('search_socid' => $search_socid, 'search_projectid' => $search_projectid, 'search_fk_user' => $search_fk_user) as $k => $v) {
	if ((int) $v > 0) {
		$param .= '&'.$k.'='.((int) $v);
	}
}
if ($search_text !== '') {
	$param .= '&search_text='.urlencode($search_text);
}

llxHeader('', $langs->trans('CreditTimesheetApproveTitle'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-timesheets-approve');

print load_fiche_titre($langs->trans('CreditTimesheetApproveTitle'), '', 'object_credit@creditmanager');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditTimesheetApproveHelp').'</div>';

if (creditmanagerCanManualDebit($user)) {
	print '<p class="marginbottomonly"><a href="'.dol_buildpath('/custom/creditmanager/timesheets/debit.php', 1).'">'.$langs->trans('CreditManagerManualDebit').'</a></p>';
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"/>';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'"/>';

print_barre_liste($langs->trans('CreditTimesheetApproveListTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $totalRecords, '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre liste_titre_filter">';
print '<td>'.$langs->trans('ThirdParty').'</td>';
print '<td>'.$langs->trans('Project').'</td>';
print '<td colspan="2">'.$langs->trans('DateRange').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td class="right">'.$langs->trans('CreditManagerActions').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>';
creditmanagerPrintScopedCompanySelect($form, $db, $financialScope, $search_socid, 'search_socid', $entitySoc);
print '</td><td>';
print $formproject->select_projects($search_socid > 0 ? $search_socid : -1, $search_projectid, 'search_projectid', 24, 0, 1, 0, 0, 0, '', '', 0, 0, 'maxwidth300', '', '');
print '</td><td colspan="2" class="nowrap">';
print $form->selectDate($search_date_start ?: -1, 'search_date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print ' ';
print $form->selectDate($search_date_end ?: -1, 'search_date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('To'));
print '</td><td>';
print $form->select_users($search_fk_user, 'search_fk_user', 1);
print '</td><td class="right nowrap">';
print '<input type="submit" class="button small" name="button_search" value="'.$langs->trans('Search').'"> ';
print '<input type="submit" class="button small" name="button_removefilter" value="'.$langs->trans('Reset').'">';
print '</td></tr>';
print '<tr class="oddeven"><td colspan="6"><input type="text" class="flat minwidth300" name="search_text" value="'.dol_escape_htmltag($search_text).'" placeholder="'.$langs->trans('Search').'"></td></tr>';
print '</table></div>';
print '</form>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'et.element_date', '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('Project').'</th>';
print '<th class="right">'.$langs->trans('Duration').'</th>';
print '<th>'.$langs->trans('CreditType').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th class="center">'.$langs->trans('CreditManagerActions').'</th>';
print '</tr>';

if ($resql) {
	$num = $db->num_rows($resql);
	$nmax = $limit ? min($num, $limit) : $num;
	$i = 0;
	while ($i < $nmax) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			break;
		}
		$h = ((float) $obj->element_duration) / 3600;
		print '<tr class="oddeven">';
		print '<td>'.($obj->element_date ? dol_print_date($db->jdate($obj->element_date), 'day') : '').'</td>';
		print '<td>'.dol_escape_htmltag($obj->origin_ref ?: ('ET-'.(int) $obj->rowid)).'</td>';
		print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
		print '<td>'.dol_escape_htmltag(trim($obj->project_ref.' '.$obj->project_title)).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($h).'</td>';
		print '<td>';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="approve">';
		print '<input type="hidden" name="tid" value="'.((int) $obj->rowid).'">';
		print '<select name="fk_credit_type" class="flat maxwidth150" required>';
		print '<option value=""></option>';
		foreach ($creditTypes as $t) {
			$sel = ((int) $obj->fk_credit_type === (int) $t->rowid) ? ' selected' : '';
			print '<option value="'.((int) $t->rowid).'"'.$sel.'>'.dol_escape_htmltag($t->code).'</option>';
		}
		print '</select> ';
		print '<input type="submit" class="button small" value="'.$langs->trans('Approve').'">';
		print '</form>';
		print '</td>';
		print '<td>'.dol_escape_htmltag($obj->login).'</td>';
		print '<td class="center nowrap">';
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('CreditTimesheetRejectConfirm')).'\');">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="reject">';
		print '<input type="hidden" name="tid" value="'.((int) $obj->rowid).'">';
		print '<input type="text" name="reject_comment" class="flat maxwidth150" placeholder="'.$langs->trans('Comment').'">';
		print ' <input type="submit" class="button small button-delete" value="'.$langs->trans('Reject').'">';
		print '</form>';
		print '</td>';
		print '</tr>';
		$i++;
	}
	if ($num === 0) {
		print '<tr class="oddeven"><td colspan="8" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
	}
	$db->free($resql);
} else {
	dol_print_error($db);
}

print '</table></div>';

llxFooter();
$db->close();
