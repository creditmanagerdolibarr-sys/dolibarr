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

/**
 * \file       htdocs/custom/creditmanager/tabs/thirdpartyCredits.php
 * \ingroup    creditmanager
 * \brief      Credits tab in thirdparty (client) card - balance and history
 */

// Load Dolibarr environment
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditType.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("companies", "creditmanager@creditmanager"));

// Security check
$socid = GETPOSTINT('socid');
if (!empty($user->socid)) {
	$socid = $user->socid;
}

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortorder) {
	$sortorder = "DESC";
}
if (!$sortfield) {
	$sortfield = "m.date_movement";
}

// Access control
if (!creditmanagerCanViewFinancialData($user) && !creditmanagerCanReadClientPortal($user)) {
	accessforbidden();
	exit;
}

$financialScope = creditmanagerGetReportScope($db, $user);

$object = new Societe($db);
if ($socid > 0) {
	if (!creditmanagerCanViewFinancialData($user)) {
		if (empty($user->socid) || (int) $user->socid !== (int) $socid) {
			accessforbidden();
		}
	} elseif ($financialScope['type'] !== 'all' && !creditmanagerReportCanAccessSoc($financialScope, $socid, $db)) {
		accessforbidden();
	}
	$object->fetch($socid);
}

// Restricted area
$result = restrictedArea($user, 'societe', $object->id, '');

// Filters
$search_credit_type = GETPOSTINT('search_credit_type');
$search_movement_type = GETPOST('search_movement_type', 'aZ09'); // credit, debit, or empty=all
$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_start_month'), GETPOSTINT('search_date_start_day'), GETPOSTINT('search_date_start_year'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_end_month'), GETPOSTINT('search_date_end_day'), GETPOSTINT('search_date_end_year'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_credit_type = 0;
	$search_movement_type = '';
	$search_date_start = '';
	$search_date_end = '';
}

$action = GETPOST('action', 'aZ09');
$token = newToken();

// Export actions
if ($action === 'exportcsv' && $socid > 0 && creditmanagerCanExport($user)) {
	$filename = 'credit_history_' . $socid . '_' . dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '.csv';
	header('Content-Type: text/csv');
	header('Content-Disposition: attachment; filename="' . $filename . '"');

	$out = fopen('php://output', 'w');
	if ($out !== false) {
		$csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
		fputcsv($out, array('id', 'date', 'credit_type', 'amount', 'balance_after', 'type_movement', 'description', 'user'), $csvSep);

		$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,';
		$sql .= ' t.code as type_code, t.label as type_label, u.login';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'credits_movements as m';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'credits_types as t ON t.rowid = m.fk_credit_type';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = m.fk_user_creat';
		$sql .= ' WHERE m.fk_soc = ' . ((int) $socid);
		$sql .= ' AND m.entity = ' . ((int) $conf->entity);
		if ($search_credit_type > 0) {
			$sql .= ' AND m.fk_credit_type = ' . ((int) $search_credit_type);
		}
		if ($search_movement_type === 'credit') {
			$sql .= ' AND m.amount > 0';
		} elseif ($search_movement_type === 'debit') {
			$sql .= ' AND m.amount < 0';
		}
		if (!empty($search_date_start)) {
			$sql .= " AND m.date_movement >= '" . $db->idate($search_date_start) . "'";
		}
		if (!empty($search_date_end)) {
			$sql .= " AND m.date_movement <= '" . $db->idate($search_date_end) . "'";
		}
		$sql .= ' ORDER BY m.date_movement DESC';

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				fputcsv($out, array(
					$obj->rowid,
					$db->jdate($obj->date_movement) ? dol_print_date($db->jdate($obj->date_movement), 'dayhour') : '',
					$obj->type_code . ' - ' . $obj->type_label,
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

if ($action === 'exportpdf' && $socid > 0 && creditmanagerCanExport($user)) {
	// PDF export - use Dolibarr PDF utilities
	require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
	$outputlangs = $langs;
	$outputlangs->load("creditmanager@creditmanager");
	$filename = 'credit_history_' . $socid . '_' . dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '.pdf';

	$pdf = pdf_getInstance();
	$pdf->SetTitle($outputlangs->trans("CreditHistory"));
	$pdf->SetSubject($outputlangs->trans("Credits"));
	$pdf->AddPage();

	$pdf->SetFont('', '', 10);
	$pdf->MultiCell(0, 6, $outputlangs->trans("ThirdParty") . ': ' . $object->name, 0, 'L');
	$pdf->Ln(4);

	$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,';
	$sql .= ' t.code as type_code, t.label as type_label';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'credits_movements as m';
	$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'credits_types as t ON t.rowid = m.fk_credit_type';
	$sql .= ' WHERE m.fk_soc = ' . ((int) $socid);
	$sql .= ' AND m.entity = ' . ((int) $conf->entity);
	if ($search_credit_type > 0) {
		$sql .= ' AND m.fk_credit_type = ' . ((int) $search_credit_type);
	}
	if ($search_movement_type === 'credit') {
		$sql .= ' AND m.amount > 0';
	} elseif ($search_movement_type === 'debit') {
		$sql .= ' AND m.amount < 0';
	}
	if (!empty($search_date_start)) {
		$sql .= " AND m.date_movement >= '" . $db->idate($search_date_start) . "'";
	}
	if (!empty($search_date_end)) {
		$sql .= " AND m.date_movement <= '" . $db->idate($search_date_end) . "'";
	}
	$sql .= ' ORDER BY m.date_movement DESC LIMIT 500';

	$resql = $db->query($sql);
	if ($resql) {
		$pdf->SetFont('', 'B', 8);
		$pdf->Cell(30, 6, $outputlangs->trans("Date"), 1, 0, 'L');
		$pdf->Cell(40, 6, $outputlangs->trans("CreditType"), 1, 0, 'L');
		$pdf->Cell(25, 6, $outputlangs->trans("Amount"), 1, 0, 'R');
		$pdf->Cell(30, 6, $outputlangs->trans("TypeMovement"), 1, 0, 'L');
		$pdf->Cell(0, 6, $outputlangs->trans("Description"), 1, 1, 'L');

		$pdf->SetFont('', '', 8);
		while ($obj = $db->fetch_object($resql)) {
			$pdf->Cell(30, 6, dol_print_date($db->jdate($obj->date_movement), 'day'), 1, 0, 'L');
			$pdf->Cell(40, 6, $obj->type_code, 1, 0, 'L');
			$pdf->Cell(25, 6, creditmanagerFormatAmount($obj->amount), 1, 0, 'R');
			$pdf->Cell(30, 6, $obj->type_movement, 1, 0, 'L');
			$pdf->Cell(0, 6, dol_trunc($obj->description, 40), 1, 1, 'L');
		}
		$db->free($resql);
	}

	$pdf->Output($filename, 'D');
	exit;
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("ThirdParty") . ' - ' . $langs->trans("Credits");
if (getDolGlobalString('MAIN_HTML_TITLE') && preg_match('/thirdpartynameonly/', getDolGlobalString('MAIN_HTML_TITLE')) && $object->name) {
	$title = $object->name . ' - ' . $langs->trans("Credits");
}
$help_url = 'EN:Module_Third_Parties|FR:Module_Tiers';
llxHeader('', $title, $help_url, '', 0, 0, '', array('/custom/creditmanager/css/creditmanager.css'), '', 'mod-creditmanager page-tabs_thirdpartycredits');

$param = "&socid=" . $socid;
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}
if ($search_credit_type > 0) {
	$param .= '&search_credit_type=' . ((int) $search_credit_type);
}
if ($search_movement_type !== '') {
	$param .= '&search_movement_type=' . urlencode($search_movement_type);
}
if (!empty($search_date_start)) {
	$param .= '&search_date_start_day=' . dol_print_date($search_date_start, '%d') . '&search_date_start_month=' . dol_print_date($search_date_start, '%m') . '&search_date_start_year=' . dol_print_date($search_date_start, '%Y');
}
if (!empty($search_date_end)) {
	$param .= '&search_date_end_day=' . dol_print_date($search_date_end, '%d') . '&search_date_end_month=' . dol_print_date($search_date_end, '%m') . '&search_date_end_year=' . dol_print_date($search_date_end, '%Y');
}

if ($socid > 0) {
	$object->fetch($socid);

	// Show tabs
	$head = societe_prepare_head($object);
	print dol_get_fiche_head($head, 'creditmanager', $langs->trans("ThirdParty"), -1, 'company');

	$linkback = '<a href="' . DOL_URL_ROOT . '/societe/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
	dol_banner_tab($object, 'socid', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';

	// Section 1: Balances by type
	print '<table class="border tableforfield centpercent">';
	print '<tr class="liste_titre">';
	print '<th colspan="2">' . $langs->trans("CreditBalances") . '</th>';
	print '</tr>';

	$sqlBal = 'SELECT b.rowid, b.fk_credit_type, b.balance, t.code, t.label';
	$sqlBal .= ' FROM ' . MAIN_DB_PREFIX . 'credits_balance as b';
	$sqlBal .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'credits_types as t ON t.rowid = b.fk_credit_type';
	$sqlBal .= ' WHERE b.fk_soc = ' . ((int) $socid);
	$sqlBal .= ' AND b.entity = ' . ((int) $conf->entity);
	$sqlBal .= ' AND t.active = 1';
	$sqlBal .= ' ORDER BY t.code';

	$resBal = $db->query($sqlBal);
	$balances = array();
	if ($resBal) {
		while ($objB = $db->fetch_object($resBal)) {
			$balances[] = $objB;
		}
		$db->free($resBal);
	}

	if (count($balances) > 0) {
		foreach ($balances as $objB) {
			print '<tr class="oddeven">';
			print '<td class="titlefield">' . dol_escape_htmltag($objB->code . ' - ' . $objB->label) . '</td>';
			print '<td class="right"><strong>' . creditmanagerFormatAmount($objB->balance) . '</strong></td>';
			print '</tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="2" class="center opacitymedium">' . $langs->trans("None") . '</td></tr>';
	}

	// Action buttons (if admin/finance)
	if (creditmanagerCanManageAttributions($user) && empty($user->socid)) {
		print '<tr class="liste_titre">';
		print '<td colspan="2">';
		$attrUrl = DOL_URL_ROOT . '/custom/creditmanager/admin/attribution.php?socid=' . $socid . '&action=add&token=' . $token;
		print '<a class="butAction" href="' . $attrUrl . '">' . $langs->trans("CreditManagerNewAttribution") . '</a>';
		print '</td>';
		print '</tr>';
	}

	print '</table>';

	// Simple evolution chart (last 12 months by type)
	$chartData = array();
	$sqlChart = 'SELECT t.code, t.label, YEAR(m.date_movement) as y, MONTH(m.date_movement) as mo, SUM(m.amount) as total';
	$sqlChart .= ' FROM ' . MAIN_DB_PREFIX . 'credits_movements as m';
	$sqlChart .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'credits_types as t ON t.rowid = m.fk_credit_type';
	$sqlChart .= ' WHERE m.fk_soc = ' . ((int) $socid);
	$sqlChart .= ' AND m.entity = ' . ((int) $conf->entity);
	$sqlChart .= ' AND m.date_movement >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
	$sqlChart .= ' GROUP BY t.code, t.label, y, mo';
	$sqlChart .= ' ORDER BY y, mo';

	$resChart = $db->query($sqlChart);
	if ($resChart && $db->num_rows($resChart) > 0) {
		print '<br>';
		print '<table class="border tableforfield centpercent">';
		print '<tr class="liste_titre"><th colspan="4">' . $langs->trans("EvolutionLast12Months") . '</th></tr>';
		$byMonth = array();
		while ($o = $db->fetch_object($resChart)) {
			$k = $o->y . '-' . sprintf('%02d', $o->mo);
			if (!isset($byMonth[$k])) {
				$byMonth[$k] = array();
			}
			$byMonth[$k][$o->code] = $o->total;
		}
		$db->free($resChart);
		krsort($byMonth);
		$shown = 0;
		foreach (array_slice($byMonth, 0, 12, true) as $mo => $types) {
			$shown++;
			$totalMo = array_sum($types);
			print '<tr class="oddeven">';
			print '<td>' . dol_escape_htmltag($mo) . '</td>';
			print '<td>' . $langs->trans("Total") . '</td>';
			print '<td class="right">' . creditmanagerFormatAmount($totalMo) . '</td>';
			print '<td></td></tr>';
		}
		if ($shown === 0) {
			print '<tr class="oddeven"><td colspan="4" class="center opacitymedium">' . $langs->trans("NoData") . '</td></tr>';
		}
		print '</table>';
	}

	print '<br>';

	// Section 2: Movement history with filters
	$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.balance_after, m.type_movement, m.description,';
	$sql .= ' '.creditmanagerSqlCancelledAttributionFlag('m').' as is_cancelled,';
	$sql .= ' t.code as type_code, t.label as type_label,';
	$sql .= ' u.login';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'credits_movements as m';
	$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'credits_types as t ON t.rowid = m.fk_credit_type';
	$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'user as u ON u.rowid = m.fk_user_creat';
	$sql .= ' WHERE m.fk_soc = ' . ((int) $socid);
	$sql .= ' AND m.entity = ' . ((int) $conf->entity);

	if ($search_credit_type > 0) {
		$sql .= ' AND m.fk_credit_type = ' . ((int) $search_credit_type);
	}
	if ($search_movement_type === 'credit') {
		$sql .= ' AND m.amount > 0';
	} elseif ($search_movement_type === 'debit') {
		$sql .= ' AND m.amount < 0';
	}
	if (!empty($search_date_start)) {
		$sql .= " AND m.date_movement >= '" . $db->idate($search_date_start) . "'";
	}
	if (!empty($search_date_end)) {
		$sql .= " AND m.date_movement <= '" . $db->idate($search_date_end) . "'";
	}

	$sqlCount = preg_replace('/SELECT .+ FROM/', 'SELECT COUNT(m.rowid) as nb FROM', $sql);
	$resCount = $db->query($sqlCount);
	$totalRecords = 0;
	if ($resCount) {
		$objCount = $db->fetch_object($resCount);
		$totalRecords = (int) ($objCount->nb ?? 0);
		$db->free($resCount);
	}

	$sql .= $db->order($sortfield, $sortorder);
	$sql .= $db->plimit($limit + 1, $offset);

	$resql = $db->query($sql);

	creditmanagerPrintDetachedSearchForm($sortfield, $sortorder, 'searchFormList', array('socid' => (int) $socid));

	$newcardbutton = '';
	if (creditmanagerCanExport($user)) {
		$newcardbutton .= dolGetButtonTitle($langs->trans("ExportCSV"), '', 'fa fa-download', $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=exportcsv&token=' . $token . $param, '', 1);
		$newcardbutton .= dolGetButtonTitle($langs->trans("Export") . ' PDF', '', 'fa fa-file-pdf', $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=exportpdf&token=' . $token . $param, '', 1);
	}

	print_barre_liste($langs->trans("CreditMovements"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $totalRecords, $totalRecords, '', 0, $newcardbutton);

	print '<div class="div-table-responsive">';
	print '<table class="tagtable nobottomiftotal liste">';
	print '<tr class="liste_titre_filter">';
	print '<td class="liste_titre nowrap">';
	print $form->selectDate($search_date_start ?: -1, 'search_date_start_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
	print ' ';
	print $form->selectDate($search_date_end ?: -1, 'search_date_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('To'));
	print '</td>';
	print '<td class="liste_titre">';
	$sqlTypes = 'SELECT rowid, code, label FROM ' . MAIN_DB_PREFIX . 'credits_types';
	$sqlTypes .= ' WHERE entity = ' . ((int) $conf->entity) . ' AND active = 1 ORDER BY code';
	$resTypes = $db->query($sqlTypes);
	print '<select name="search_credit_type" class="flat maxwidth150">';
	print '<option value="0"></option>';
	if ($resTypes) {
		while ($objT = $db->fetch_object($resTypes)) {
			$sel = ($search_credit_type > 0 && (int) $objT->rowid === $search_credit_type) ? ' selected' : '';
			print '<option value="' . (int) $objT->rowid . '"' . $sel . '>' . dol_escape_htmltag($objT->code) . '</option>';
		}
		$db->free($resTypes);
	}
	print '</select></td>';
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre">';
	print '<select name="search_movement_type" class="flat maxwidth100">';
	print '<option value=""' . ($search_movement_type === '' ? ' selected' : '') . '></option>';
	print '<option value="credit"' . ($search_movement_type === 'credit' ? ' selected' : '') . '>' . $langs->trans("Credit") . '</option>';
	print '<option value="debit"' . ($search_movement_type === 'debit' ? ' selected' : '') . '>' . $langs->trans("Debit") . '</option>';
	print '</select></td>';
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre center maxwidthsearch">';
	creditmanagerPrintListFilterButtons('searchFormList');
	print '</td>';
	print '</tr>';

	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans("Date"), $_SERVER["PHP_SELF"], "m.date_movement", "", $param, '', $sortfield, $sortorder);
	print_liste_field_titre($langs->trans("CreditType"), $_SERVER["PHP_SELF"], "t.code", "", $param, '', $sortfield, $sortorder);
	print_liste_field_titre($langs->trans("Amount"), $_SERVER["PHP_SELF"], "m.amount", "", $param, 'class="right"', $sortfield, $sortorder);
	print_liste_field_titre($langs->trans("BalanceAfter"), $_SERVER["PHP_SELF"], "m.balance_after", "", $param, 'class="right"', $sortfield, $sortorder);
	print_liste_field_titre($langs->trans("Type"), $_SERVER["PHP_SELF"], "m.type_movement", "", $param, '', $sortfield, $sortorder);
	print '<th>' . $langs->trans("Description") . '</th>';
	print '<th>' . $langs->trans("User") . '</th>';
	print '<th class="center">' . $langs->trans("Action") . '</th>';
	print '</tr>';

	if ($resql) {
		$num = $db->num_rows($resql);
		$i = 0;
		$imax = $limit ? min($num, $limit) : $num;
		while ($i < $imax) {
			$obj = $db->fetch_object($resql);
			if (!$obj) {
				break;
			}
			$trClass = 'oddeven';
			if (!empty($obj->is_cancelled)) {
				$trClass .= ' creditmanager-cancelled';
			}
			print '<tr class="' . $trClass . '">';
			print '<td>' . dol_print_date($db->jdate($obj->date_movement), 'dayhour') . '</td>';
			print '<td>' . dol_escape_htmltag($obj->type_code . ' - ' . $obj->type_label) . '</td>';
			$amountClass = $obj->amount >= 0 ? 'amount' : 'amountnegative';
			print '<td class="right ' . $amountClass . '">' . creditmanagerFormatAmount($obj->amount) . '</td>';
			print '<td class="right">' . (isset($obj->balance_after) ? creditmanagerFormatAmount($obj->balance_after) : '-') . '</td>';
			print '<td>' . dol_escape_htmltag($obj->type_movement) . '</td>';
			print '<td>' . dol_escape_htmltag($obj->description) . '</td>';
			print '<td>' . dol_escape_htmltag($obj->login) . '</td>';
			print '<td class="center nowrap">';
			creditmanagerPrintAttributionListActions($user, $obj->type_movement, (int) $obj->rowid, (int) $obj->is_cancelled);
			print '</td>';
			print '</tr>';
			$i++;
		}
	} else {
		dol_print_error($db);
	}

	print '</table>';
	print '</div>';
	creditmanagerPrintBindFiltersToSearchForm('searchFormList');

	$db->free($resql);

	print dol_get_fiche_end();
} else {
	dol_print_error(null, 'Parameter socid not defined');
}

llxFooter();
$db->close();
