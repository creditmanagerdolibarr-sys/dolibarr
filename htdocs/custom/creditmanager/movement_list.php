<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * Contributor of this script: https://github.com/joelmpunga Joel MPUNGA
 */

/**
 * \file       htdocs/custom/creditmanager/movement_list.php
 * \ingroup    creditmanager
 * \brief      List credit movements (all clients or filtered by client)
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';

creditmanagerEnsureLeftMenuFlat($db);

$langs->loadLangs(array("companies", "compta", "creditmanager@creditmanager"));

if (!creditmanagerCanReadModule($user)) {
	accessforbidden();
}
if (!creditmanagerCanViewFinancialData($user)) {
	accessforbidden();
}

$financialScope = creditmanagerGetReportScope($db, $user);
if ($financialScope['type'] === 'none') {
	accessforbidden();
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortorder) {
	$sortorder = "DESC";
}
$allowedSort = array(
	'm.date_movement' => 'm.date_movement',
	'm.amount' => 'm.amount',
	'm.balance_after' => 'm.balance_after',
	's.nom' => 's.nom',
	't.code' => 't.code',
	'm.type_movement' => 'm.type_movement',
);
if (empty($sortfield) || !isset($allowedSort[$sortfield])) {
	$sortfield = 'm.date_movement';
}

$search_socid = GETPOSTINT('search_socid');
$search_credit_type = GETPOSTINT('search_credit_type');
$search_movement_type = GETPOST('search_movement_type', 'aZ09');
$search_desc = trim(GETPOST('search_desc', 'restricthtml'));
$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_start_month'), GETPOSTINT('search_date_start_day'), GETPOSTINT('search_date_start_year'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_end_month'), GETPOSTINT('search_date_end_day'), GETPOSTINT('search_date_end_year'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_socid = 0;
	$search_credit_type = 0;
	$search_movement_type = '';
	$search_desc = '';
	$search_date_start = '';
	$search_date_end = '';
}

$action = GETPOST('action', 'aZ09');
$token = newToken();

$entityMovement = getEntity('credits_movement');
$entitySoc = getEntity('societe');
$entityType = getEntity('credits_type');

/**
 * @param DoliDB $db
 * @param array  $filters
 * @return string SQL fragment starting with WHERE
 */
function creditmanager_movement_sql_where($db, $filters)
{
	global $entityMovement, $entitySoc, $entityType;

	$sql = " WHERE m.entity IN (".$entityMovement.")";
	$sql .= " AND s.entity IN (".$entitySoc.")";
	$sql .= " AND t.entity IN (".$entityType.")";

	if (!empty($filters['fk_soc'])) {
		$sql .= " AND m.fk_soc = ".((int) $filters['fk_soc']);
	}
	if (!empty($filters['fk_credit_type'])) {
		$sql .= " AND m.fk_credit_type = ".((int) $filters['fk_credit_type']);
	}
	if (!empty($filters['type_movement']) && $filters['type_movement'] === 'credit') {
		$sql .= " AND m.amount > 0";
	} elseif (!empty($filters['type_movement']) && $filters['type_movement'] === 'debit') {
		$sql .= " AND m.amount < 0";
	}
	if (!empty($filters['date_start'])) {
		$sql .= " AND m.date_movement >= '".$db->idate($filters['date_start'])."'";
	}
	if (!empty($filters['date_end'])) {
		$sql .= " AND m.date_movement <= '".$db->idate($filters['date_end'])."'";
	}
	if (!empty($filters['search_desc'])) {
		$sql .= natural_search(array('m.description', 't.code', 't.label'), $filters['search_desc']);
	}
	if (!empty($filters['scope']) && is_array($filters['scope']) && !empty($filters['scope']['type']) && $filters['scope']['type'] !== 'all') {
		$sql .= creditmanagerReportScopeBalanceWhereSql($filters['scope'], 'm');
	}
	return $sql;
}

creditmanagerValidateFinancialScopeSocId($financialScope, $search_socid, $db);

$filters = array(
	'fk_soc' => $search_socid > 0 ? $search_socid : 0,
	'fk_credit_type' => $search_credit_type > 0 ? $search_credit_type : 0,
	'type_movement' => $search_movement_type,
	'date_start' => $search_date_start,
	'date_end' => $search_date_end,
	'search_desc' => $search_desc,
	'scope' => $financialScope,
);
$whereSql = creditmanager_movement_sql_where($db, $filters);

if ($action === 'exportcsv' && creditmanagerCanExport($user)) {
	$filename = 'credit_movements_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	$csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
	$out = fopen('php://output', 'w');
	if ($out !== false) {
		fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
		fputcsv($out, array('id', 'date', 'client', 'credit_type', 'amount', 'balance_after', 'type_movement', 'description', 'user'), $csvSep);
		$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,';
		$sql .= ' t.code as type_code, t.label as type_label, s.nom as socname, u.login';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'credits_movements as m';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'credits_types as t ON t.rowid = m.fk_credit_type';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = m.fk_soc';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = m.fk_user_creat';
		$sql .= $whereSql;
		$sql .= ' ORDER BY m.date_movement DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				fputcsv($out, array(
					$obj->rowid,
					$db->jdate($obj->date_movement) ? dol_print_date($db->jdate($obj->date_movement), 'dayhour') : '',
					$obj->socname,
					$obj->type_code.' - '.$obj->type_label,
					creditmanagerFormatAmountNum($obj->amount),
					isset($obj->balance_after) ? creditmanagerFormatAmountNum($obj->balance_after) : '',
					$obj->type_movement,
					$obj->description,
					$obj->login,
				), $csvSep);
			}
			$db->free($resql);
		}
		fclose($out);
	}
	exit;
}

$form = new Form($db);

$sqlCount = 'SELECT COUNT(m.rowid) as nb FROM '.MAIN_DB_PREFIX.'credits_movements as m';
$sqlCount .= ' INNER JOIN '.MAIN_DB_PREFIX.'credits_types as t ON t.rowid = m.fk_credit_type';
$sqlCount .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = m.fk_soc';
$sqlCount .= $whereSql;
$resCount = $db->query($sqlCount);
$totalRecords = 0;
if ($resCount) {
	$o = $db->fetch_object($resCount);
	$totalRecords = (int) ($o->nb ?? 0);
	$db->free($resCount);
}

$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description, m.fk_soc,';
$sql .= ' '.creditmanagerSqlCancelledAttributionFlag('m').' as is_cancelled,';
$sql .= ' t.code as type_code, t.label as type_label, s.nom as socname, u.login';
$sql .= ' FROM '.MAIN_DB_PREFIX.'credits_movements as m';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'credits_types as t ON t.rowid = m.fk_credit_type';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = m.fk_soc';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = m.fk_user_creat';
$sql .= $whereSql;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_socid > 0) {
	$param .= '&search_socid='.((int) $search_socid);
}
if ($search_credit_type > 0) {
	$param .= '&search_credit_type='.((int) $search_credit_type);
}
if ($search_movement_type !== '') {
	$param .= '&search_movement_type='.urlencode($search_movement_type);
}
if ($search_desc !== '') {
	$param .= '&search_desc='.urlencode($search_desc);
}
if (!empty($search_date_start)) {
	$param .= '&search_date_start_day='.dol_print_date($search_date_start, '%d').'&search_date_start_month='.dol_print_date($search_date_start, '%m').'&search_date_start_year='.dol_print_date($search_date_start, '%Y');
}
if (!empty($search_date_end)) {
	$param .= '&search_date_end_day='.dol_print_date($search_date_end, '%d').'&search_date_end_month='.dol_print_date($search_date_end, '%m').'&search_date_end_year='.dol_print_date($search_date_end, '%Y');
}

$morecss = array('/custom/creditmanager/css/creditmanager.css');
llxHeader('', $langs->trans('CreditMovements'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-movement_list');

print load_fiche_titre($langs->trans('CreditMovements'), '', 'object_credit@creditmanager');

if ($search_socid > 0) {
	print '<p class="marginbottomonly"><a href="'.dol_buildpath('/custom/creditmanager/balance_list.php', 1).'?search_socid='.((int) $search_socid).'">'.$langs->trans('CreditBalances').'</a> <span class="opacitymedium">('.dol_escape_htmltag($langs->trans('CreditManagerFilteredByClient')).')</span></p>';
}

print '<div class="opacitymedium marginbottomonly">';
print $langs->trans('CreditMovementsListHelp');
print '</div>';

$newcardbutton = '';
if (creditmanagerCanExport($user)) {
	$newcardbutton .= dolGetButtonTitle($langs->trans('ExportCSV'), '', 'fa fa-download', dol_buildpath('/custom/creditmanager/movement_list.php', 1).'?action=exportcsv&token='.$token.$param, '', 1);
}

creditmanagerPrintDetachedSearchForm($sortfield, $sortorder, 'searchFormList');

print_barre_liste($langs->trans('CreditMovements'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $totalRecords, $totalRecords, '', 0, $newcardbutton);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre nowrap">';
print $form->selectDate($search_date_start ?: -1, 'search_date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print '<br>';
print $form->selectDate($search_date_end ?: -1, 'search_date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('To'));
print '</td>';
print '<td class="liste_titre">';
creditmanagerPrintScopedCompanySelect($form, $db, $financialScope, $search_socid, 'search_socid', $entitySoc);
print '</td>';
print '<td class="liste_titre">';
$sqlTypes = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.'credits_types';
$sqlTypes .= ' WHERE entity IN ('.$entityType.') AND active = 1 ORDER BY code';
$resTypes = $db->query($sqlTypes);
print '<select name="search_credit_type" class="flat maxwidth200">';
print '<option value="0"></option>';
if ($resTypes) {
	while ($objT = $db->fetch_object($resTypes)) {
		$sel = ($search_credit_type > 0 && (int) $objT->rowid === $search_credit_type) ? ' selected' : '';
		print '<option value="'.(int) $objT->rowid.'"'.$sel.'>'.dol_escape_htmltag($objT->code).'</option>';
	}
	$db->free($resTypes);
}
print '</select></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre">';
print '<select name="search_movement_type" class="flat maxwidth100">';
print '<option value=""'.($search_movement_type === '' ? ' selected' : '').'></option>';
print '<option value="credit"'.($search_movement_type === 'credit' ? ' selected' : '').'>'.$langs->trans('Credit').'</option>';
print '<option value="debit"'.($search_movement_type === 'debit' ? ' selected' : '').'>'.$langs->trans('Debit').'</option>';
print '</select></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth200" name="search_desc" value="'.dol_escape_htmltag($search_desc).'" placeholder="'.$langs->trans('CreditMovementSearchDesc').'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch">';
creditmanagerPrintListFilterButtons('searchFormList');
print '</td>';
print '</tr>';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'm.date_movement', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('ThirdParty'), $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('CreditType'), $_SERVER['PHP_SELF'], 't.code', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Amount'), $_SERVER['PHP_SELF'], 'm.amount', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('BalanceAfter'), $_SERVER['PHP_SELF'], 'm.balance_after', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Type'), $_SERVER['PHP_SELF'], 'm.type_movement', '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('Description').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th class="center">'.$langs->trans('CreditManagerActions').'</th>';
print '</tr>';

$linkCreditsTab = DOL_URL_ROOT.'/custom/creditmanager/tabs/thirdpartyCredits.php';

if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;
	$nmax = $limit ? min($num, $limit) : $num;
	while ($i < $nmax) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			break;
		}
		$trClass = 'oddeven';
		if (!empty($obj->is_cancelled)) {
			$trClass .= ' creditmanager-cancelled';
		}
		print '<tr class="'.$trClass.'">';
		print '<td>'.dol_print_date($db->jdate($obj->date_movement), 'dayhour').'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $obj->fk_soc).'">'.dol_escape_htmltag($obj->socname).'</a></td>';
		print '<td>'.dol_escape_htmltag($obj->type_code.' - '.$obj->type_label).'</td>';
		$amountClass = $obj->amount >= 0 ? 'amount' : 'amountnegative';
		print '<td class="right '.$amountClass.'">'.creditmanagerFormatAmount($obj->amount).'</td>';
		print '<td class="right">'.(isset($obj->balance_after) ? creditmanagerFormatAmount($obj->balance_after) : '-').'</td>';
		print '<td>'.dol_escape_htmltag($obj->type_movement).'</td>';
		print '<td>'.dol_escape_htmltag($obj->description).'</td>';
		print '<td>'.dol_escape_htmltag($obj->login).'</td>';
		print '<td class="center nowrap">';
		print '<a class="paddingright" href="'.$linkCreditsTab.'?socid='.((int) $obj->fk_soc).'" title="'.dol_escape_htmltag($langs->trans('Credits')).'">'.img_picto('', 'company').'</a>';
		creditmanagerPrintAttributionListActions($user, $obj->type_movement, (int) $obj->rowid, (int) $obj->is_cancelled);
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
creditmanagerPrintBindFiltersToSearchForm('searchFormList');

llxFooter();
$db->close();
