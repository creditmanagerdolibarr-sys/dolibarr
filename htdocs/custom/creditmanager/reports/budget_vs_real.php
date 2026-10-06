<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr */

/**
 * \file        htdocs/custom/creditmanager/reports/budget_vs_real.php
 * \ingroup     creditmanager
 * \brief       Budget vs real comparison report with advanced filters.
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

$year_preset = GETPOST('year_preset', 'aZ09');
$search_year = GETPOSTINT('search_year');
$yearNow = (int) dol_print_date(dol_now(), '%Y');
if ($year_preset === 'current') {
	$search_year = $yearNow;
} elseif ($year_preset === 'previous') {
	$search_year = $yearNow - 1;
} elseif ($search_year <= 0) {
	$search_year = $yearNow;
}

$search_socids = GETPOST('search_socids', 'array');
$search_typeids = GETPOST('search_typeids', 'array');
$search_projectids = GETPOST('search_projectids', 'array');
$variance_min = GETPOST('variance_min', 'alphanohtml');
$variance_max = GETPOST('variance_max', 'alphanohtml');
$usage_status = GETPOST('usage_status', 'aZ09');
$drill_soc = GETPOSTINT('drill_soc');
$drill_type = GETPOSTINT('drill_type');

$isFilterSubmit = GETPOST('button_search', 'alpha') !== '';
if ($isFilterSubmit) {
	$show_col_budget = GETPOSTINT('show_col_budget');
	$show_col_real = GETPOSTINT('show_col_real');
	$show_col_diff = GETPOSTINT('show_col_diff');
	$show_col_usage = GETPOSTINT('show_col_usage');
	$show_col_variance = GETPOSTINT('show_col_variance');
} else {
	$show_col_budget = 1;
	$show_col_real = 1;
	$show_col_diff = 1;
	$show_col_usage = 1;
	$show_col_variance = 1;
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
	'fk_soc' => $search_socids,
	'fk_credit_type' => $search_typeids,
	'fk_project' => $search_projectids,
	'variance_min' => $variance_min,
	'variance_max' => $variance_max,
	'usage_status' => $usage_status,
	'scope' => $reportScope,
);

$budgetRows = $report->compareBudgetVsReal($search_year, $filters);
$budgetChartConfig = $graph->buildBudgetVsRealChart($budgetRows);
$varianceChartConfig = $graph->buildVarianceChart($budgetRows);

$drillRows = array();
$drillLabel = '';
if ($drill_soc > 0 && $drill_type > 0) {
	if (!creditmanagerReportCanAccessSoc($reportScope, $drill_soc, $db)) {
		accessforbidden();
	}
	$drillRows = $report->getBudgetVsRealMonthlyDetail($search_year, $drill_soc, $drill_type);
	foreach ($budgetRows as $r) {
		if ((int) $r['fk_soc'] === $drill_soc && (int) $r['fk_credit_type'] === $drill_type) {
			$drillLabel = $r['socname'].' - '.$r['credit_code'];
			break;
		}
	}
}

$exportRows = array();
foreach ($budgetRows as $r) {
	$exportRows[] = array(
		$langs->trans('ThirdParty') => $r['socname'],
		$langs->trans('CreditReportGroupByCreditType') => $r['credit_code'].' - '.$r['credit_label'],
		$langs->trans('CreditReportBudgetHours') => creditmanagerFormatAmountNum($r['budget_hours']),
		$langs->trans('CreditReportRealHours') => creditmanagerFormatAmountNum($r['real_hours']),
		$langs->trans('CreditReportDifference') => creditmanagerFormatAmountNum($r['difference_hours']),
		$langs->trans('CreditReportUsagePercent') => creditmanagerFormatAmountNum($r['usage_percent']),
		$langs->trans('CreditReportVariance') => creditmanagerFormatAmountNum($r['variance_percent']),
		$langs->trans('CreditReportForecastStatus') => $langs->trans('CreditReportBudgetStatus'.ucfirst($r['status'])),
	);
}

$exportHeaders = array(
	$langs->trans('ThirdParty'),
	$langs->trans('CreditReportGroupByCreditType'),
	$langs->trans('CreditReportBudgetHours'),
	$langs->trans('CreditReportRealHours'),
	$langs->trans('CreditReportDifference'),
	$langs->trans('CreditReportUsagePercent'),
	$langs->trans('CreditReportVariance'),
	$langs->trans('CreditReportForecastStatus'),
);

if ($action === 'export' && creditmanagerCanExport($user)) {
	$format = GETPOST('format', 'aZ09');
	$filenameBase = 'credit_budget_vs_real_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');

	if ($format === 'csv') {
		$exporter->exportCSV($filenameBase.'.csv', $exportHeaders, $exportRows);
	} elseif ($format === 'json') {
		$exporter->exportJSON($filenameBase.'.json', array('year' => $search_year, 'filters' => $_GET, 'rows' => $exportRows));
	} elseif ($format === 'pdf') {
		$exporter->exportPDF($filenameBase.'.pdf', $langs->trans('CreditReportBudgetVsRealTitle'), $exportHeaders, $exportRows);
	} elseif ($format === 'excel') {
		$exporter->exportExcel($filenameBase.'.xlsx', $exportHeaders, $exportRows);
	}
}

if ($action === 'generate_report' && !creditmanagerIsListFilterSubmit() && creditmanagerCanExport($user)) {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

	$dir = DOL_DATA_ROOT.'/creditmanager/reports';
	dol_mkdir($dir);
	$filename = 'budget_vs_real_'.dol_print_date(dol_now(), '%Y%m%d_%H%M%S').'.pdf';
	$filepath = $dir.'/'.$filename;

	$pdf = pdf_getInstance();
	if (is_object($pdf)) {
		$pdf->SetCreator('Dolibarr CreditManager');
		$pdf->SetTitle($langs->trans('CreditReportBudgetVsRealTitle'));
		$pdf->AddPage();
		$pdf->SetFont('helvetica', '', 9);
		$html = '<h2>'.dol_escape_htmltag($langs->trans('CreditReportBudgetVsRealTitle')).' ('.$search_year.')</h2>';
		$html .= '<table border="1" cellpadding="4"><thead><tr>';
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
			setEventMessages($langs->trans('CreditReportBudgetGenerated', $filename), null, 'mesgs');
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

llxHeader('', $langs->trans('CreditReportBudgetVsRealTitle'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-budget_vs_real');

print load_fiche_titre($langs->trans('CreditReportBudgetVsRealTitle'), '', 'title_generic');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditReportBudgetVsRealSubtitle').'</div>';
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('CreditReportActivePeriod').': '.$search_year.'</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">'.$langs->trans('CreditReportFilters').'</th></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportBudgetYear').'</label><br>';
print '<select name="year_preset" class="flat maxwidth150">';
print '<option value="current"'.($year_preset === 'current' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetYearCurrent').'</option>';
print '<option value="previous"'.($year_preset === 'previous' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetYearPrevious').'</option>';
print '<option value="custom"'.($year_preset === 'custom' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetYearCustom').'</option>';
print '</select> ';
print '<input class="flat width75" type="number" name="search_year" min="2000" max="2100" value="'.((int) $search_year).'">';
print '</td>';

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
print '</select></td></tr>';

print '<tr class="oddeven">';
print '<td><label>'.$langs->trans('CreditReportVarianceThreshold').'</label><br>';
print '<input class="flat width75" type="number" step="0.1" min="0" max="200" name="variance_min" value="'.dol_escape_htmltag($variance_min).'" placeholder="min %"> ';
print '<input class="flat width75" type="number" step="0.1" min="0" max="200" name="variance_max" value="'.dol_escape_htmltag($variance_max).'" placeholder="max %">';
print '</td>';
print '<td><label>'.$langs->trans('CreditReportBudgetUsageStatus').'</label><br>';
print '<select name="usage_status" class="flat maxwidth200">';
print '<option value=""'.($usage_status === '' ? ' selected' : '').'></option>';
print '<option value="ok"'.($usage_status === 'ok' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetStatusOk').'</option>';
print '<option value="warning"'.($usage_status === 'warning' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetStatusWarning').'</option>';
print '<option value="over"'.($usage_status === 'over' ? ' selected' : '').'>'.$langs->trans('CreditReportBudgetStatusOver').'</option>';
print '</select></td>';
print '<td colspan="2"><label>'.$langs->trans('CreditReportConfigurableColumns').'</label><br>';
print '<label><input type="checkbox" name="show_col_budget" value="1"'.($show_col_budget ? ' checked' : '').'> '.$langs->trans('CreditReportBudgetHours').'</label> ';
print '<label><input type="checkbox" name="show_col_real" value="1"'.($show_col_real ? ' checked' : '').'> '.$langs->trans('CreditReportRealHours').'</label> ';
print '<label><input type="checkbox" name="show_col_diff" value="1"'.($show_col_diff ? ' checked' : '').'> '.$langs->trans('CreditReportDifference').'</label> ';
print '<label><input type="checkbox" name="show_col_usage" value="1"'.($show_col_usage ? ' checked' : '').'> '.$langs->trans('CreditReportUsagePercent').'</label> ';
print '<label><input type="checkbox" name="show_col_variance" value="1"'.($show_col_variance ? ' checked' : '').'> '.$langs->trans('CreditReportVariance').'</label>';
print '</td></tr>';

print '<tr class="oddeven"><td colspan="4" class="right">';
print '<button class="button" type="submit" name="button_search" value="1">'.$langs->trans('CreditReportApplyFilters').'</button> ';
print '<a class="button button-cancel" href="'.dol_buildpath('/custom/creditmanager/reports/budget_vs_real.php', 1).'">'.$langs->trans('CreditReportResetFilters').'</a>';
if (creditmanagerCanExport($user)) {
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'csv', 'token' => $token))).'">CSV</a>';
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'pdf', 'token' => $token))).'">PDF</a>';
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'export', 'format' => 'excel', 'token' => $token))).'">Excel</a>';
	print ' <a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($_GET, array('action' => 'generate_report', 'token' => $token))).'">'.$langs->trans('CreditReportBudgetGenerateReport').'</a>';
}
print '</td></tr>';
print '</table></div>';

print '<div class="fichecenter"><div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportBudgetVsReal').'</th></tr>';
print '<tr class="oddeven"><td style="height:320px;"><canvas id="chartBudgetMain" height="300"></canvas></td></tr>';
print '</table></div></div>';

print '<div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('CreditReportVarianceChart').'</th></tr>';
print '<tr class="oddeven"><td style="height:320px;"><canvas id="chartVariance" height="300"></canvas></td></tr>';
print '</table></div></div></div>';
print '<div class="clearboth"></div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('CreditReportGroupByCreditType').'</th>';
if ($show_col_budget) {
	print '<th class="right">'.$langs->trans('CreditReportBudgetHours').'</th>';
}
if ($show_col_real) {
	print '<th class="right">'.$langs->trans('CreditReportRealHours').'</th>';
}
if ($show_col_diff) {
	print '<th class="right">'.$langs->trans('CreditReportDifference').'</th>';
}
if ($show_col_usage) {
	print '<th class="right">'.$langs->trans('CreditReportUsagePercent').'</th>';
}
if ($show_col_variance) {
	print '<th class="right">'.$langs->trans('CreditReportVariance').'</th>';
}
print '<th>'.$langs->trans('CreditReportForecastStatus').'</th>';
print '<th class="center">'.$langs->trans('CreditManagerActions').'</th>';
print '</tr>';

if (empty($budgetRows)) {
	$colspan = 3 + ($show_col_budget ? 1 : 0) + ($show_col_real ? 1 : 0) + ($show_col_diff ? 1 : 0) + ($show_col_usage ? 1 : 0) + ($show_col_variance ? 1 : 0);
	print '<tr class="oddeven"><td colspan="'.((int) $colspan).'" class="opacitymedium center">'.$langs->trans('NoData').'</td></tr>';
}

foreach ($budgetRows as $r) {
	$statusClass = 'badge-status4';
	if ($r['status'] === 'warning') {
		$statusClass = 'badge-status3';
	} elseif ($r['status'] === 'over') {
		$statusClass = 'badge-status8';
	}

	$drillParams = $_GET;
	$drillParams['drill_soc'] = (int) $r['fk_soc'];
	$drillParams['drill_type'] = (int) $r['fk_credit_type'];

	print '<tr class="oddeven">';
	print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $r['fk_soc']).'">'.dol_escape_htmltag($r['socname']).'</a></td>';
	print '<td>'.dol_escape_htmltag($r['credit_code'].' - '.$r['credit_label']).'</td>';
	if ($show_col_budget) {
		print '<td class="right">'.creditmanagerFormatAmount($r['budget_hours']).'</td>';
	}
	if ($show_col_real) {
		print '<td class="right">'.creditmanagerFormatAmount($r['real_hours']).'</td>';
	}
	if ($show_col_diff) {
		print '<td class="right">'.creditmanagerFormatAmount($r['difference_hours']).'</td>';
	}
	if ($show_col_usage) {
		print '<td class="right">'.creditmanagerFormatAmount($r['usage_percent']).'%</td>';
	}
	if ($show_col_variance) {
		print '<td class="right">'.creditmanagerFormatAmount($r['variance_percent']).'%</td>';
	}
	print '<td><span class="badge '.$statusClass.'">'.$langs->trans('CreditReportBudgetStatus'.ucfirst($r['status'])).'</span></td>';
	print '<td class="center"><a class="button small" href="'.$_SERVER['PHP_SELF'].'?'.http_build_query($drillParams).'">'.$langs->trans('CreditReportDrillDown').'</a></td>';
	print '</tr>';
}
print '</table></div>';

if ($drill_soc > 0 && $drill_type > 0) {
	print '<br>';
	print '<div class="titre">'.dol_escape_htmltag($langs->trans('CreditReportMonthlyDetail').': '.$drillLabel).'</div>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('CreditReportGroupByMonth').'</th>';
	print '<th class="right">'.$langs->trans('CreditReportBudgetHours').'</th>';
	print '<th class="right">'.$langs->trans('CreditReportRealHours').'</th>';
	print '<th class="right">'.$langs->trans('CreditReportDifference').'</th>';
	print '<th class="right">'.$langs->trans('CreditReportUsagePercent').'</th>';
	print '<th>'.$langs->trans('CreditReportForecastStatus').'</th>';
	print '</tr>';
	if (empty($drillRows)) {
		print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('NoData').'</td></tr>';
	}
	foreach ($drillRows as $m) {
		$statusClass = 'badge-status4';
		if ($m['status'] === 'warning') {
			$statusClass = 'badge-status3';
		} elseif ($m['status'] === 'over') {
			$statusClass = 'badge-status8';
		}
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($m['month_key']).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($m['budget_hours']).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($m['real_hours']).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($m['difference_hours']).'</td>';
		print '<td class="right">'.creditmanagerFormatAmount($m['usage_percent']).'%</td>';
		print '<td><span class="badge '.$statusClass.'">'.$langs->trans('CreditReportBudgetStatus'.ucfirst($m['status'])).'</span></td>';
		print '</tr>';
	}
	print '</table></div>';
}

print '</form>';

$jsBudget = json_encode($budgetChartConfig);
$jsVariance = json_encode($varianceChartConfig);

print '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
print '<script>
(function() {
	var budgetCfg = '.$jsBudget.';
	var varianceCfg = '.$jsVariance.';
	if (window.Chart) {
		new Chart(document.getElementById("chartBudgetMain"), budgetCfg);
		var varianceChart = new Chart(document.getElementById("chartVariance"), varianceCfg);
		if (varianceCfg.thresholds && varianceChart.options.plugins) {
			// threshold guides drawn via y-axis max; labels in subtitle
		}
	}
})();
</script>';

llxFooter();
$db->close();
