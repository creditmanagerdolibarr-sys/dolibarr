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
 * \file       htdocs/custom/creditmanager/balance_list.php
 * \ingroup    creditmanager
 * \brief      List credit balances per client and credit type (module menu)
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
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;

$sortorder = GETPOST('sortorder', 'aZ09comma');
$sortfield = GETPOST('sortfield', 'aZ09comma');
if (!$sortorder) {
	$sortorder = "ASC";
}
$allowedSort = array('s.nom' => 's.nom', 't.code' => 't.code', 'b.balance' => 'b.balance', 'b.tms' => 'b.tms');
if (empty($sortfield) || !isset($allowedSort[$sortfield])) {
	$sortfield = 's.nom';
}

$search_socid = GETPOSTINT('search_socid');
$search_credit_type = GETPOSTINT('search_credit_type');
$search_hide_zero = GETPOSTINT('search_hide_zero');

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_socid = 0;
	$search_credit_type = 0;
	$search_hide_zero = 0;
}

$action = GETPOST('action', 'aZ09');
$token = newToken();

$entityBalance = getEntity('credits_balance');
$entitySoc = getEntity('societe');
$entityType = getEntity('credits_type');

/**
 * Build WHERE clause for balance list (no ORDER/LIMIT).
 *
 * @param DoliDB $db
 * @param int    $search_socid
 * @param int    $search_credit_type
 * @param int    $search_hide_zero
 * @return string
 */
function creditmanager_balance_list_where($db, $search_socid, $search_credit_type, $search_hide_zero, $scope = null)
{
	global $entityBalance, $entitySoc, $entityType;

	$sql = " WHERE b.entity IN (".$entityBalance.")";
	$sql .= " AND s.entity IN (".$entitySoc.")";
	$sql .= " AND t.entity IN (".$entityType.")";
	$sql .= " AND t.active = 1";

	if ($search_socid > 0) {
		$sql .= " AND b.fk_soc = ".((int) $search_socid);
	}
	if ($search_credit_type > 0) {
		$sql .= " AND b.fk_credit_type = ".((int) $search_credit_type);
	}
	if (!empty($search_hide_zero)) {
		$sql .= " AND ABS(b.balance) > 0.00001";
	}
	if (is_array($scope) && !empty($scope['type']) && $scope['type'] !== 'all') {
		$sql .= creditmanagerReportScopeBalanceWhereSql($scope, 'b');
	}
	return $sql;
}

/**
 * @param DoliDB $db
 * @param string $sqlFromWhere
 * @return float
 */
function creditmanager_balance_sum_total($db, $sqlFromWhere)
{
	$sql = "SELECT SUM(b.balance) as total FROM ".MAIN_DB_PREFIX."credits_balance as b";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = b.fk_soc";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
	$sql .= $sqlFromWhere;
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return $obj && isset($obj->total) ? (float) $obj->total : 0;
	}
	return 0;
}

creditmanagerValidateFinancialScopeSocId($financialScope, $search_socid, $db);

$whereBase = creditmanager_balance_list_where($db, $search_socid, $search_credit_type, $search_hide_zero, $financialScope);

if ($action === 'exportcsv' && creditmanagerCanExport($user)) {
	$filename = 'credit_balances_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	$csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
	$out = fopen('php://output', 'w');
	if ($out !== false) {
		fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
		fputcsv($out, array('socid', 'client', 'credit_type_code', 'credit_type_label', 'balance', 'updated'), $csvSep);
		$sql = "SELECT b.rowid, b.fk_soc, b.balance, b.tms, s.nom as socname, t.code, t.label as type_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_balance as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = b.fk_soc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
		$sql .= $whereBase;
		$sql .= $db->order($sortfield, $sortorder);
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				fputcsv($out, array(
					$obj->fk_soc,
					$obj->socname,
					$obj->code,
					$obj->type_label,
					creditmanagerFormatAmountNum($obj->balance),
					$db->jdate($obj->tms) ? dol_print_date($db->jdate($obj->tms), 'dayhour') : '',
				), $csvSep);
			}
			$db->free($resql);
		}
		fclose($out);
	}
	exit;
}

$form = new Form($db);

$sqlCount = "SELECT COUNT(b.rowid) as nb FROM ".MAIN_DB_PREFIX."credits_balance as b";
$sqlCount .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = b.fk_soc";
$sqlCount .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
$sqlCount .= $whereBase;
$resCount = $db->query($sqlCount);
$totalRecords = 0;
if ($resCount) {
	$o = $db->fetch_object($resCount);
	$totalRecords = (int) ($o->nb ?? 0);
	$db->free($resCount);
}

$totalBalanceSum = creditmanager_balance_sum_total($db, $whereBase);

$sql = "SELECT b.rowid, b.fk_soc, b.fk_credit_type, b.balance, b.tms, s.nom as socname, t.code, t.label as type_label";
$sql .= " FROM ".MAIN_DB_PREFIX."credits_balance as b";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = b.fk_soc";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
$sql .= $whereBase;
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
if (!empty($search_hide_zero)) {
	$param .= '&search_hide_zero=1';
}

llxHeader('', $langs->trans('CreditBalances'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-balance_list');

print load_fiche_titre($langs->trans('CreditBalances'), '', 'object_credit@creditmanager');

if ($search_socid > 0) {
	print '<p class="marginbottomonly"><a href="'.dol_buildpath('/custom/creditmanager/movement_list.php', 1).'?search_socid='.((int) $search_socid).'">'.$langs->trans('CreditMovements').'</a> <span class="opacitymedium">('.dol_escape_htmltag($langs->trans('CreditManagerFilteredByClient')).')</span></p>';
}

print '<div class="opacitymedium marginbottomonly">';
print $langs->trans('CreditBalancesListHelp');
print '</div>';

creditmanagerPrintDetachedSearchForm($sortfield, $sortorder, 'searchFormList');

$newcardbutton = '';
if (creditmanagerCanExport($user)) {
	$newcardbutton .= dolGetButtonTitle($langs->trans('ExportCSV'), '', 'fa fa-download', dol_buildpath('/custom/creditmanager/balance_list.php', 1).'?action=exportcsv&token='.$token.$param, '', 1);
}

print_barre_liste($langs->trans('CreditBalances'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $totalRecords, $totalRecords, '', 0, $newcardbutton);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre_filter">';
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
print '<td class="liste_titre"><label><input type="checkbox" name="search_hide_zero" value="1"'.(!empty($search_hide_zero) ? ' checked' : '').'> '.$langs->trans('CreditBalancesHideZero').'</label></td>';
print '<td class="liste_titre right"><span class="opacitymedium">'.$langs->trans('Total').':</span> <strong>'.creditmanagerFormatAmount($totalBalanceSum).'</strong></td>';
print '<td class="liste_titre center maxwidthsearch">';
creditmanagerPrintListFilterButtons('searchFormList');
print '</td>';
print '</tr>';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('ThirdParty'), $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('CreditType'), $_SERVER['PHP_SELF'], 't.code', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Balance'), $_SERVER['PHP_SELF'], 'b.balance', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('DateModificationShort'), $_SERVER['PHP_SELF'], 'b.tms', '', $param, '', $sortfield, $sortorder);
print '<th class="center">'.$langs->trans('CreditManagerActions').'</th>';
print '</tr>';

if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;
	$nmax = $limit ? min($num, $limit) : $num;
	$linkMove = dol_buildpath('/custom/creditmanager/movement_list.php', 1);
	$linkCreditsTab = DOL_URL_ROOT.'/custom/creditmanager/tabs/thirdpartyCredits.php';
	while ($i < $nmax) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			break;
		}
		print '<tr class="oddeven">';
		print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $obj->fk_soc).'">'.dol_escape_htmltag($obj->socname).'</a></td>';
		print '<td>'.dol_escape_htmltag($obj->code.' - '.$obj->type_label).'</td>';
		print '<td class="right"><strong>'.creditmanagerFormatAmount($obj->balance).'</strong></td>';
		print '<td>'.($db->jdate($obj->tms) ? dol_print_date($db->jdate($obj->tms), 'dayhour') : '').'</td>';
		print '<td class="center nowrap">';
		print '<a class="paddingright" href="'.$linkCreditsTab.'?socid='.((int) $obj->fk_soc).'" title="'.dol_escape_htmltag($langs->trans('Credits')).'">'.img_picto('', 'company').'</a>';
		print '<a class="paddingright" href="'.$linkMove.'?search_socid='.((int) $obj->fk_soc).'" title="'.dol_escape_htmltag($langs->trans('CreditMovements')).'">'.img_picto('', 'list').'</a>';
		print '</td>';
		print '</tr>';
		$i++;
	}
	if ($num === 0) {
		print '<tr class="oddeven"><td colspan="5" class="center opacitymedium">'.$langs->trans('NoData').'</td></tr>';
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
