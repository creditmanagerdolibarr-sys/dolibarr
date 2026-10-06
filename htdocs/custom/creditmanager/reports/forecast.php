<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr */

/**
 * \file        htdocs/custom/creditmanager/reports/forecast.php
 * \ingroup     creditmanager
 * \brief       Credit exhaustion forecast report with advanced filters.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
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

$search_socids = GETPOST('search_socids', 'array');
$search_typeids = GETPOST('search_typeids', 'array');
$search_projectids = GETPOST('search_projectids', 'array');
$alert_threshold = GETPOST('alert_threshold', 'aZ09');
$months_min = GETPOST('months_min', 'alphanohtml');
$months_max = GETPOST('months_max', 'alphanohtml');
$search_client_status = GETPOST('search_client_status', 'intcomma');
if ($search_client_status !== '' && $search_client_status !== null && !in_array((int) $search_client_status, array(0, 1), true)) {
	$search_client_status = '';
}
$period_months = GETPOSTINT('period_months');
if (!in_array($period_months, array(3, 6, 12), true)) {
	$period_months = 3;
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

$filters = array(
	'period_months' => $period_months,
	'fk_project' => $search_projectids,
	'client_status' => $search_client_status,
	'alert_threshold' => $alert_threshold,
	'months_min' => $months_min,
	'months_max' => $months_max,
	'scope' => $reportScope,
);

$forecastRows = $report->calculateForecast($search_socids, $search_typeids, $filters);
usort($forecastRows, function ($a, $b) {
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
$forecastChartConfig = $graph->buildForecastChart($forecastRows, false);
$distributionChartConfig = $graph->buildForecastDistributionChart($forecastRows);

$exportRows = array();
foreach ($forecastRows as $r) {
	$exportRows[] = array(
		$langs->trans('ThirdParty') => $r['socname'],
		$langs->trans('CreditReportGroupByCreditType') => $r['credit_code'].' - '.$r['credit_label'],
		$langs->trans('CreditReportForecastCurrentBalance') => creditmanagerFormatAmountNum($r['current_balance']),
		$langs->trans('CreditReportForecastAvgConsumption') => creditmanagerFormatAmountNum($r['avg_monthly_consumption']),
		$langs->trans('CreditReportForecastMonthsRemaining') => $r['months_remaining'] === null ? '' : creditmanagerFormatAmountNum($r['months_remaining']),
		$langs->trans('CreditReportForecastStatus') => $langs->trans('CreditReportForecastStatus'.ucfirst($r['status'])),
	);
}

$exportHeaders = array(
	$langs->trans('ThirdParty'),
	$langs->trans('CreditReportGroupByCreditType'),
	$langs->trans('CreditReportForecastCurrentBalance'),
	$langs->trans('CreditReportForecastAvgConsumption'),
	$langs->trans('CreditReportForecastMonthsRemaining'),
	$langs->trans('CreditReportForecastStatus'),
);

if ($action === 'export' && creditmanagerCanExport($user)) {
	$format = GETPOST('format', 'aZ09');
	$filenameBase = 'credit_forecast_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');

	if ($format === 'csv') {
		$exporter->exportCSV($filenameBase.'.csv', $exportHeaders, $exportRows);
	} elseif ($format === 'json') {
		$exporter->exportJSON($filenameBase.'.json', array('filters' => $_GET, 'rows' => $exportRows));
	} elseif ($format === 'pdf') {
		$exporter->exportPDF($filenameBase.'.pdf', $langs->trans('CreditReportForecastTitle'), $exportHeaders, $exportRows);
	} elseif ($format === 'excel') {
		$exporter->exportExcel($filenameBase.'.xlsx', $exportHeaders, $exportRows);
	}
}

if ($action === 'send_alert_emails' && !creditmanagerIsListFilterSubmit() && creditmanagerCanExport($user)) {
	$sent = 0;
	$errors = 0;
	$seen = array();

	foreach ($forecastRows as $r) {
		if (!in_array($r['status'], array('warning', 'critical'), true)) {
			continue;
		}
		$key = (int) $r['fk_soc'];
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;

		$soc = new Societe($db);
		if ($soc->fetch($key) <= 0 || empty($soc->email)) {
			$errors++;
			continue;
		}

		$subject = $langs->trans('CreditReportForecastEmailSubject', $soc->name);
		$body = $langs->trans('CreditReportForecastEmailBody', $soc->name, $r['status']);
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if (empty($from)) {
			$from = $user->email;
		}

		$mail = new CMailFile($subject, $soc->email, $from, $body);
		if ($mail->sendfile()) {
			$sent++;
		} else {
			$errors++;
		}
	}

	setEventMessages($langs->trans('CreditReportForecastEmailResult', $sent, $errors), null, $errors > 0 ? 'warnings' : 'mesgs');
}

if ($action === 'generate_report' && !creditmanagerIsListFilterSubmit() && creditmanagerCanExport($user)) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

	$dir = DOL_DATA_ROOT.'/creditmanager/reports';
	dol_mkdir($dir);
	$filename = 'forecast_'.dol_print_date(dol_now(), '%Y%m%d_%H%M%S').'.pdf';
	$filepath = $dir.'/'.$filename;

	$pdf = pdf_getInstance();
	if (is_object($pdf)) {
		$pdf->SetCreator('Dolibarr CreditManager');
		$pdf->SetTitle($langs->trans('CreditReportForecastTitle'));
		$pdf->AddPage();
		$pdf->SetFont('helvetica', '', 9);
		$html = '<h2>'.dol_escape_htmltag($langs->trans('CreditReportForecastTitle')).'</h2><table border="1" cellpadding="4"><thead><tr>';
		foreach ($exportHeaders as $h) {
			$html .= '<th><b>'.dol_escape_htmltag($h).'</b></th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($exportRows as $row) {
			$html .= '<tr>';
			foreach (array_values($row) as $cell) {
				$html .= '<td>'.dol_escape_htmltag((string) $cell).'</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
		$pdf->writeHTML($html, true, false, true, false, '');
		$pdf->Output($filepath, 'F');
		if (file_exists($filepath)) {
			setEventMessages($langs->trans('CreditReportForecastGenerated', $filename), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('Error').': '.$langs->trans('ErrorFileNotFound'), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('Error').': PDF', null, 'errors');
	}
}

$entitySoc = getEntity('societe');
$entityType = getEntity('credits_type');
$entityProject = getEntity('project');

llxHeader('', $langs->trans('CreditReportForecastTitle'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-forecast');

print load_fiche_titre($langs->trans('CreditReportForecastTitle'), '', 'title_generic');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditReportForecastSubtitle').'</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditReportFilters').'</th></tr>';

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
print '</select></td>';

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

print '<td><label>'.$langs->trans('CreditReportForecastAlertThreshold').'</label><br>';
print '<select name="alert_threshold" class="flat maxwidth200">';
print '<option value=""'.($alert_threshold === '' ? ' selected' : '').'></option>';
print '<option value="warning"'.($alert_threshold === 'warning' ? ' selected' : '').'>'.$langs->trans('CreditReportForecastStatusWarning').'</option>';
print '<option value="critical"'.($alert_threshold === 'critical' ? ' selected' : '').'>'.$langs->trans('CreditReportForecastStatusCritical').'</option>';
print '</select></td></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportForecastMonthsRemaining').'</label><br>';
print '<input class="flat width75" type="number" step="0.1" min="0" name="months_min" value="'.dol_escape_htmltag($months_min).'" placeholder="min"> ';
print '<input class="flat width75" type="number" step="0.1" min="0" name="months_max" value="'.dol_escape_htmltag($months_max).'" placeholder="max">';
print '</td>';
print '<td><label>'.$langs->trans('CreditReportForecastClientStatus').'</label><br>';
print '<select name="search_client_status" class="flat maxwidth200">';
print '<option value=""'.($search_client_status === '' ? ' selected' : '').'></option>';
print '<option value="1"'.((string) $search_client_status === '1' ? ' selected' : '').'>'.$langs->trans('InActivity').'</option>';
print '<option value="0"'.((string) $search_client_status === '0' ? ' selected' : '').'>'.$langs->trans('ActivityCeased').'</option>';
print '</select></td>';
print '<td><label>'.$langs->trans('CreditReportForecastCalcPeriod').'</label><br>';
print '<select name="period_months" class="flat maxwidth200">';
print '<option value="3"'.($period_months === 3 ? ' selected' : '').'>'.$langs->trans('CreditReportForecastPeriod3').'</option>';
print '<option value="6"'.($period_months === 6 ? ' selected' : '').'>'.$langs->trans('CreditReportForecastPeriod6').'</option>';
print '<option value="12"'.($period_months === 12 ? ' selected' : '').'>'.$langs->trans('CreditReportForecastPeriod12').'</option>';
print '</select></td>';
print '<td class="right valignbottom">';
print '<button class="button" type="submit">'.$langs->trans('CreditReportApplyFilters').'</button> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/custom/creditmanager/reports/forecast.php', 1).'">'.$langs->trans('CreditReportResetFilters').'</a>';
print '</td></tr>';

if (creditmanagerCanExport($user)) {
	print '<tr class="oddeven"><td colspan="4" class="right">';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'csv', 'token' => $token))).'">CSV</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'pdf', 'token' => $token))).'">PDF</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'excel', 'token' => $token))).'">Excel</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'send_alert_emails', 'token' => $token))).'" onclick="return confirm(\''.dol_escape_js($langs->trans('CreditReportForecastEmailConfirm')).'\');">'.$langs->trans('CreditReportForecastSendEmails').'</a> ';
	print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'generate_report', 'token' => $token))).'">'.$langs->trans('CreditReportForecastGenerateReport').'</a>';
	print '</td></tr>';
}
print '</table></div>';

print '<div class="fichecenter"><div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportForecastMainChart').'</th></tr>';
print '<tr class="oddeven"><td style="height:320px;"><canvas id="chartForecastMain" height="300"></canvas></td></tr>';
print '</table></div></div>';

print '<div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportForecastDistributionChart').'</th></tr>';
print '<tr class="oddeven"><td style="height:320px;"><canvas id="chartForecastDist" height="300"></canvas></td></tr>';
print '</table></div></div></div>';
print '<div class="clearboth"></div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('CreditReportGroupByCreditType').'</th>';
print '<th class="right">'.$langs->trans('CreditReportForecastCurrentBalance').'</th>';
print '<th class="right">'.$langs->trans('CreditReportForecastAvgConsumption').'</th>';
print '<th class="right">'.$langs->trans('CreditReportForecastMonthsRemaining').'</th>';
print '<th>'.$langs->trans('CreditReportForecastStatus').'</th>';
print '</tr>';

if (empty($forecastRows)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('NoData').'</td></tr>';
}

foreach ($forecastRows as $r) {
	$statusClass = 'badge-status4';
	if ($r['status'] === 'warning') {
		$statusClass = 'badge-status3';
	} elseif ($r['status'] === 'critical') {
		$statusClass = 'badge-status8';
	}
	print '<tr class="oddeven">';
	print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $r['fk_soc']).'">'.dol_escape_htmltag($r['socname']).'</a></td>';
	print '<td>'.dol_escape_htmltag($r['credit_code'].' - '.$r['credit_label']).'</td>';
	print '<td class="right">'.creditmanagerFormatAmount($r['current_balance']).'</td>';
	print '<td class="right">'.creditmanagerFormatAmount($r['avg_monthly_consumption']).'</td>';
	print '<td class="right">'.($r['months_remaining'] === null ? '-' : creditmanagerFormatAmount($r['months_remaining'])).'</td>';
	print '<td><span class="badge '.$statusClass.'">'.$langs->trans('CreditReportForecastStatus'.ucfirst($r['status'])).'</span></td>';
	print '</tr>';
}
print '</table></div>';
print '</form>';

$jsForecast = json_encode($forecastChartConfig);
$jsDist = json_encode($distributionChartConfig);

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script>
(function() {
	var forecastCfg = '.$jsForecast.';
	var distCfg = '.$jsDist.';
	if (window.Chart) {
		new Chart(document.getElementById("chartForecastMain"), forecastCfg);
		new Chart(document.getElementById("chartForecastDist"), distCfg);
	}
})();
</script>';

llxFooter();
$db->close();
