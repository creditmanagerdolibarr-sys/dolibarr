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
 * \file       htdocs/custom/creditmanager/client/balance.php
 * \ingroup    creditmanager
 * \brief      Client portal: credit balances with charts and filters
 */

$shareToken = isset($_GET['share']) ? preg_replace('/[^A-Za-z0-9_\-=]/', '', (string) $_GET['share']) : '';
$isShareView = ($shareToken !== '');

if ($isShareView) {
	if (!defined('NOLOGIN')) {
		define('NOLOGIN', '1');
	}
	if (!defined('NOCSRFCHECK')) {
		define('NOCSRFCHECK', '1');
	}
	if (!defined('NOREQUIREMENU')) {
		define('NOREQUIREMENU', '1');
	}
}

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
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditGraph.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditExport.class.php';

$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'other'));

$socid = 0;
if ($isShareView) {
	if (!getDolGlobalInt('CREDITMANAGER_ENABLE_CLIENT_PORTAL', 0)) {
		accessforbidden();
	}
	$socid = creditmanagerValidateBalanceShareToken($shareToken);
	if ($socid <= 0) {
		accessforbidden($langs->trans('CreditClientShareInvalid'));
	}
} else {
	if (!creditmanagerCanAccessClientPortalPages($user)) {
		accessforbidden();
	}
	if (creditmanagerIsClientPortalUser($user)) {
		$socid = (int) $user->socid;
	} else {
		$socid = GETPOSTINT('socid');
		if ($socid <= 0) {
			accessforbidden($langs->trans('CreditClientBalanceNeedSocid'));
		}
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

$period = GETPOSTINT('period');
if (!in_array($period, array(7, 30, 90, 365), true)) {
	$period = 30;
}

$search_typeids = GETPOST('search_typeids', 'array');
if (!is_array($search_typeids)) {
	$search_typeids = array();
}
$search_typeids = array_values(array_unique(array_filter(array_map('intval', $search_typeids), function ($v) {
	return $v > 0;
})));

if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_typeids = array();
	$period = 30;
}

$entityBalance = getEntity('credits_balance');
$entityType = getEntity('credits_type');
$entityMovement = getEntity('credits_movement');

/*
 * Actions
 */

if (!$isShareView && $action === 'request_credits' && getDolGlobalInt('CREDITMANAGER_ALLOW_CLIENT_CREDIT_REQUEST', 0)) {
	$requestType = GETPOSTINT('request_credit_type');
	$requestAmount = price2num(GETPOST('request_amount', 'alphanohtml'), 'MT');
	$requestMessage = GETPOST('request_message', 'restricthtml');

	if ($requestAmount === '' || (float) $requestAmount <= 0) {
		setEventMessages($langs->trans('CreditClientRequestAmountRequired'), null, 'errors');
	} else {
		$typeLabel = '';
		if ($requestType > 0) {
			$resType = $db->query("SELECT code, label FROM ".$db->prefix()."credits_types WHERE rowid = ".((int) $requestType)." AND entity IN (".$entityType.")");
			if ($resType && ($objT = $db->fetch_object($resType))) {
				$typeLabel = $objT->code.(!empty($objT->label) ? ' - '.$objT->label : '');
			}
			if ($resType) {
				$db->free($resType);
			}
		}

		$to = getDolGlobalString('CREDITMANAGER_CREDIT_REQUEST_EMAIL', '');
		if ($to === '') {
			$to = getDolGlobalString('MAIN_INFO_SOCIETE_MAIL', '');
		}

		$subject = $langs->trans('CreditClientRequestEmailSubject', $object->name);
		$body = $langs->trans(
			'CreditClientRequestEmailBody',
			$object->name,
			$typeLabel !== '' ? $typeLabel : '-',
			creditmanagerFormatAmountNum($requestAmount),
			$requestMessage !== '' ? $requestMessage : '-',
			!empty($user->email) ? $user->email : ($user->login ?? '')
		);

		if ($to !== '') {
			$mail = new CMailFile($subject, $to, getDolGlobalString('MAIN_MAIL_EMAIL_FROM', ''), $body, array(), array(), array(), '', '', 0, 1);
			$ok = $mail->sendfile();
			if ($ok) {
				setEventMessages($langs->trans('CreditClientRequestSent'), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('CreditClientRequestSendError').' '.$mail->error, null, 'errors');
			}
		} else {
			dol_syslog('CreditManager client credit request: '.$subject.' / '.$body, LOG_WARNING);
			setEventMessages($langs->trans('CreditClientRequestLoggedNoEmail'), null, 'warnings');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].(!empty($user->socid) ? '' : '?socid='.$socid));
	exit;
}

if (!$isShareView && $action === 'create_share') {
	$ttl = (int) getDolGlobalString('CREDITMANAGER_CLIENT_SHARE_TTL_HOURS', '48');
	$share = creditmanagerCreateBalanceShareToken($socid, $ttl);
	$shareUrl = dol_buildpath('/custom/creditmanager/client/balance.php', 2).'?share='.urlencode($share);
	setEventMessages($langs->trans('CreditClientShareCreated', $ttl).'<br><a href="'.dol_escape_htmltag($shareUrl).'" target="_blank" rel="noopener">'.dol_escape_htmltag($shareUrl).'</a>', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].(!empty($user->socid) ? '' : '?socid='.$socid));
	exit;
}

if ($action === 'exportpdf') {
	// Load balances for export (same query as view)
	$sqlBal = "SELECT b.fk_credit_type, b.balance, b.tms, t.code, t.label";
	$sqlBal .= " FROM ".$db->prefix()."credits_balance as b";
	$sqlBal .= " INNER JOIN ".$db->prefix()."credits_types as t ON t.rowid = b.fk_credit_type";
	$sqlBal .= " WHERE b.fk_soc = ".((int) $socid);
	$sqlBal .= " AND b.entity IN (".$entityBalance.")";
	$sqlBal .= " AND t.entity IN (".$entityType.")";
	$sqlBal .= " AND t.active = 1";
	if (!empty($search_typeids)) {
		$sqlBal .= " AND b.fk_credit_type IN (".implode(',', array_map('intval', $search_typeids)).")";
	}
	$sqlBal .= " ORDER BY t.code ASC";

	$exportRows = array();
	$totalExport = 0.0;
	$resBal = $db->query($sqlBal);
	if ($resBal) {
		while ($obj = $db->fetch_object($resBal)) {
			$bal = (float) $obj->balance;
			$totalExport += $bal;
			$exportRows[] = array(
				$obj->code,
				$obj->label,
				creditmanagerFormatAmountNum($bal),
			);
		}
		$db->free($resBal);
	}
	$exportRows[] = array($langs->trans('Total'), '', creditmanagerFormatAmountNum($totalExport));

	$filename = 'credit_balance_'.$socid.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.pdf';
	$exporter->exportPDF(
		$filename,
		$langs->trans('CreditClientBalanceTitle').' - '.$object->name,
		array($langs->trans('Code'), $langs->trans('Label'), $langs->trans('CreditReportForecastCurrentBalance')),
		$exportRows
	);
}

/*
 * Data
 */

$sqlBal = "SELECT b.rowid, b.fk_credit_type, b.balance, b.tms, t.code, t.label";
$sqlBal .= " FROM ".$db->prefix()."credits_balance as b";
$sqlBal .= " INNER JOIN ".$db->prefix()."credits_types as t ON t.rowid = b.fk_credit_type";
$sqlBal .= " WHERE b.fk_soc = ".((int) $socid);
$sqlBal .= " AND b.entity IN (".$entityBalance.")";
$sqlBal .= " AND t.entity IN (".$entityType.")";
$sqlBal .= " AND t.active = 1";
if (!empty($search_typeids)) {
	$sqlBal .= " AND b.fk_credit_type IN (".implode(',', array_map('intval', $search_typeids)).")";
}
$sqlBal .= " ORDER BY t.code ASC";

$balances = array();
$totalBalance = 0.0;
$lastUpdate = null;
$resBal = $db->query($sqlBal);
if ($resBal) {
	while ($obj = $db->fetch_object($resBal)) {
		$balances[] = $obj;
		$totalBalance += (float) $obj->balance;
		$tms = $db->jdate($obj->tms);
		if ($tms && ($lastUpdate === null || $tms > $lastUpdate)) {
			$lastUpdate = $tms;
		}
	}
	$db->free($resBal);
}

// Reference credits (sum of positive movements) for remaining %
$refs = array();
if (!empty($balances)) {
	$typeIds = array();
	foreach ($balances as $b) {
		$typeIds[] = (int) $b->fk_credit_type;
	}
	$sqlRef = "SELECT fk_credit_type, SUM(amount) as total_credited";
	$sqlRef .= " FROM ".$db->prefix()."credits_movements";
	$sqlRef .= " WHERE fk_soc = ".((int) $socid);
	$sqlRef .= " AND entity IN (".$entityMovement.")";
	$sqlRef .= " AND amount > 0";
	$sqlRef .= " AND fk_credit_type IN (".implode(',', $typeIds).")";
	$sqlRef .= " GROUP BY fk_credit_type";
	$resRef = $db->query($sqlRef);
	if ($resRef) {
		while ($obj = $db->fetch_object($resRef)) {
			$refs[(int) $obj->fk_credit_type] = (float) $obj->total_credited;
		}
		$db->free($resRef);
	}
}

$balanceCards = array();
$distRows = array();
foreach ($balances as $b) {
	$typeId = (int) $b->fk_credit_type;
	$bal = (float) $b->balance;
	$ref = isset($refs[$typeId]) ? $refs[$typeId] : 0.0;
	$percent = ($ref > 0) ? (($bal / $ref) * 100.0) : null;
	$status = creditmanagerClientBalanceStatus($percent, $bal);
	$balanceCards[] = array(
		'fk_credit_type' => $typeId,
		'code' => $b->code,
		'label' => $b->label,
		'balance' => $bal,
		'percent' => $percent,
		'status' => $status,
		'tms' => $db->jdate($b->tms),
	);
	$distRows[] = array(
		'label' => $b->code.(!empty($b->label) ? ' - '.$b->label : ''),
		'balance' => $bal,
	);
}

// Evolution: reconstruct balance-after timeline from movements in period
$periodStart = dol_time_plus_duree(dol_now(), -$period, 'd');
$typeCodes = array();
foreach ($balanceCards as $card) {
	$typeCodes[] = $card['code'];
}

// Opening balances = current - net movements in period (per type)
$opening = array();
foreach ($balanceCards as $card) {
	$opening[$card['code']] = $card['balance'];
}
$sqlNet = "SELECT t.code, SUM(m.amount) as net_period";
$sqlNet .= " FROM ".$db->prefix()."credits_movements as m";
$sqlNet .= " INNER JOIN ".$db->prefix()."credits_types as t ON t.rowid = m.fk_credit_type";
$sqlNet .= " WHERE m.fk_soc = ".((int) $socid);
$sqlNet .= " AND m.entity IN (".$entityMovement.")";
$sqlNet .= " AND m.date_movement >= '".$db->idate($periodStart)."'";
if (!empty($search_typeids)) {
	$sqlNet .= " AND m.fk_credit_type IN (".implode(',', array_map('intval', $search_typeids)).")";
}
$sqlNet .= " GROUP BY t.code";
$resNet = $db->query($sqlNet);
if ($resNet) {
	while ($obj = $db->fetch_object($resNet)) {
		$code = $obj->code;
		if (isset($opening[$code])) {
			$opening[$code] = $opening[$code] - (float) $obj->net_period;
		}
	}
	$db->free($resNet);
}

$evoPoints = array();
$startLabel = dol_print_date($periodStart, '%Y-%m-%d');
foreach ($typeCodes as $code) {
	$evoPoints[] = array(
		'date' => $startLabel,
		'type_code' => $code,
		'balance' => isset($opening[$code]) ? $opening[$code] : 0.0,
	);
}

$sqlEvo = "SELECT m.date_movement, m.amount, m.balance_after, t.code";
$sqlEvo .= " FROM ".$db->prefix()."credits_movements as m";
$sqlEvo .= " INNER JOIN ".$db->prefix()."credits_types as t ON t.rowid = m.fk_credit_type";
$sqlEvo .= " WHERE m.fk_soc = ".((int) $socid);
$sqlEvo .= " AND m.entity IN (".$entityMovement.")";
$sqlEvo .= " AND m.date_movement >= '".$db->idate($periodStart)."'";
if (!empty($search_typeids)) {
	$sqlEvo .= " AND m.fk_credit_type IN (".implode(',', array_map('intval', $search_typeids)).")";
}
$sqlEvo .= " ORDER BY m.date_movement ASC, m.rowid ASC";

$running = $opening;
$resEvo = $db->query($sqlEvo);
if ($resEvo) {
	while ($obj = $db->fetch_object($resEvo)) {
		$code = $obj->code;
		if (!isset($running[$code])) {
			continue;
		}
		if (isset($obj->balance_after) && $obj->balance_after !== null && $obj->balance_after !== '') {
			$running[$code] = (float) $obj->balance_after;
		} else {
			$running[$code] += (float) $obj->amount;
		}
		$evoPoints[] = array(
			'date' => dol_print_date($db->jdate($obj->date_movement), '%Y-%m-%d'),
			'type_code' => $code,
			'balance' => $running[$code],
		);
	}
	$db->free($resEvo);
}

$todayLabel = dol_print_date(dol_now(), '%Y-%m-%d');
foreach ($typeCodes as $code) {
	$evoPoints[] = array(
		'date' => $todayLabel,
		'type_code' => $code,
		'balance' => isset($opening[$code]) ? (isset($running[$code]) ? $running[$code] : $opening[$code]) : 0.0,
	);
}

$evolutionChart = $graph->buildBalanceEvolutionChart($evoPoints, $typeCodes);
$distributionChart = $graph->buildBalanceDistributionChart($distRows);

// Type options for filters / request form
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

$periodOptions = array(
	7 => $langs->trans('CreditClientPeriod7'),
	30 => $langs->trans('CreditClientPeriod30'),
	90 => $langs->trans('CreditClientPeriod90'),
	365 => $langs->trans('CreditClientPeriod365'),
);

$allowRequest = !$isShareView && getDolGlobalInt('CREDITMANAGER_ALLOW_CLIENT_CREDIT_REQUEST', 0);
$canShare = !$isShareView;

/*
 * View
 */

$morecss = array('/custom/creditmanager/css/creditmanager.css');
if ($isShareView) {
	llxHeader('', $langs->trans('CreditClientBalanceTitle'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-client-balance');
} else {
	creditmanagerEnsureLeftMenuFlat($db);
	llxHeader('', $langs->trans('CreditClientBalanceTitle'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-client-balance');
}

print load_fiche_titre(
	$langs->trans('CreditClientBalanceTitle'),
	$isShareView ? '' : '',
	'object_bill'
);

if ($isShareView) {
	print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditClientShareReadOnly').'</div>';
}

print '<div class="opacitymedium marginbottomonly">';
print dol_escape_htmltag($object->name);
if ($lastUpdate) {
	print ' — '.$langs->trans('CreditClientLastUpdate').': '.dol_print_date($lastUpdate, 'dayhour');
}
print '</div>';

// Filters
if (!$isShareView) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].(!empty($user->socid) ? '' : '?socid='.$socid).'" class="marginbottomonly">';
	print '<input type="hidden" name="token" value="'.$token.'">';
	if (empty($user->socid)) {
		print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
	}
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditReportFilters').'</th></tr>';
	print '<tr class="oddeven">';
	print '<td><label>'.$langs->trans('CreditType').'</label><br>';
	print $form->multiselectarray('search_typeids', $typeOptions, $search_typeids, 0, 0, 'minwidth200', 0, 0);
	print '</td>';
	print '<td><label>'.$langs->trans('CreditClientPeriod').'</label><br>';
	print $form->selectarray('period', $periodOptions, $period, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print '</td>';
	print '<td class="right valignmiddle" colspan="2">';
	print '<button type="submit" class="button">'.$langs->trans('Refresh').'</button> ';
	print '<button type="submit" class="button button-cancel" name="button_removefilter" value="1">'.$langs->trans('CreditReportResetFilters').'</button>';
	print '</td></tr>';
	print '</table></div>';
	print '</form>';
}

// Actions
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=exportpdf&token='.$token;
if (!$isShareView && empty($user->socid)) {
	print '&socid='.((int) $socid);
}
if ($isShareView) {
	print '&share='.urlencode($shareToken);
}
foreach ($search_typeids as $tid) {
	print '&search_typeids[]='.((int) $tid);
}
print '&period='.((int) $period).'">'.$langs->trans('CreditClientExportPdf').'</a>';

if ($canShare) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=create_share&token='.$token.(!empty($user->socid) ? '' : '&socid='.$socid).'">'.$langs->trans('CreditClientShareBalance').'</a>';
}
if ($allowRequest) {
	print '<a class="butAction" href="#credit-request-form">'.$langs->trans('CreditClientRequestCredits').'</a>';
}
print '</div>';

// Consolidated total
print '<div class="creditmanager-balance-total">';
print '<span class="opacitymedium">'.$langs->trans('CreditClientTotalBalance').'</span> ';
print '<strong class="creditmanager-balance-total-value">'.creditmanagerFormatAmount($totalBalance).'</strong>';
print '</div>';

// Cards
print '<div class="creditmanager-balance-cards">';
if (empty($balanceCards)) {
	print '<div class="opacitymedium">'.$langs->trans('CreditClientNoBalances').'</div>';
} else {
	foreach ($balanceCards as $card) {
		$statusClass = 'creditmanager-card-'.$card['status'];
		print '<div class="creditmanager-balance-card '.$statusClass.'">';
		print '<div class="creditmanager-card-title">'.dol_escape_htmltag($card['code']).'</div>';
		print '<div class="creditmanager-card-label opacitymedium">'.dol_escape_htmltag($card['label']).'</div>';
		print '<div class="creditmanager-card-balance">'.creditmanagerFormatAmount($card['balance']).'</div>';
		$pct = ($card['percent'] === null) ? '—' : creditmanagerFormatAmount($card['percent']).'%';
		print '<div class="creditmanager-card-percent">'.$langs->trans('CreditClientPercentRemaining').': '.$pct.'</div>';
		print '<div class="creditmanager-card-status">'.$langs->trans('CreditReportForecastStatus'.ucfirst($card['status'])).'</div>';
		print '</div>';
	}
}
print '</div>';

// Charts
print '<div class="fichecenter">';
print '<div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditClientEvolutionChart');
print ' <button type="button" class="button small reposition" id="btnResetZoomEvo">'.$langs->trans('CreditReportResetChartZoom').'</button>';
print '</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartEvolution" height="180"></canvas></td></tr>';
print '</table></div></div>';

print '<div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditClientDistributionChart');
print ' <button type="button" class="button small reposition" id="btnResetZoomDist">'.$langs->trans('CreditReportResetChartZoom').'</button>';
print '</th></tr>';
print '<tr class="oddeven"><td><canvas id="chartDistribution" height="180"></canvas></td></tr>';
print '</table></div></div>';
print '</div>';
print '<div class="clearboth"></div>';

// Request form
if ($allowRequest) {
	print '<a id="credit-request-form"></a>';
	print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].(!empty($user->socid) ? '' : '?socid='.$socid).'">';
	print '<input type="hidden" name="token" value="'.$token.'">';
	print '<input type="hidden" name="action" value="request_credits">';
	if (empty($user->socid)) {
		print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
	}
	print '<div class="div-table-responsive-no-min"><table class="border centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('CreditClientRequestCredits').'</th></tr>';
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('CreditType').'</td><td>';
	print $form->selectarray('request_credit_type', $typeOptions, 0, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('CreditClientRequestAmount').'</td><td>';
	print '<input type="number" name="request_amount" class="maxwidth100" min="0.5" step="0.5" required>';
	print '</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('Message').'</td><td>';
	print '<textarea name="request_message" class="quatrevingtpercent" rows="3"></textarea>';
	print '</td></tr>';
	print '<tr class="oddeven"><td colspan="2" class="center">';
	print '<button type="submit" class="button">'.$langs->trans('Send').'</button>';
	print '</td></tr>';
	print '</table></div></form>';
}

$jsEvo = json_encode($evolutionChart);
$jsDist = json_encode($distributionChart);

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.2.0/dist/chartjs-plugin-zoom.min.js"></script>';
print '<script>
(function() {
	var evoCfg = '.$jsEvo.';
	var distCfg = '.$jsDist.';
	var optionsZoom = {
		pan: { enabled: true, mode: "xy" },
		zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: "xy" }
	};
	function bindResetZoom(btnId, chart) {
		var btn = document.getElementById(btnId);
		if (!btn || !chart) return;
		btn.addEventListener("click", function(e) {
			e.preventDefault();
			if (typeof chart.resetZoom === "function") chart.resetZoom();
		});
	}
	if (window.Chart) {
		if (!evoCfg.options) evoCfg.options = {};
		if (!evoCfg.options.plugins) evoCfg.options.plugins = {};
		evoCfg.options.plugins.zoom = optionsZoom;
		if (!distCfg.options) distCfg.options = {};
		if (!distCfg.options.plugins) distCfg.options.plugins = {};
		distCfg.options.plugins.zoom = optionsZoom;

		var chartEvo = new Chart(document.getElementById("chartEvolution"), evoCfg);
		var chartDist = new Chart(document.getElementById("chartDistribution"), distCfg);
		bindResetZoom("btnResetZoomEvo", chartEvo);
		bindResetZoom("btnResetZoomDist", chartDist);
	}
})();
</script>';

llxFooter();
$db->close();
