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
 *	\file       htdocs/custom/creditmanager/admin/setup.php
 *	\ingroup    creditmanager
 *	\brief      General settings page for Credit Manager module
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
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

global $db, $conf, $langs, $user;

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/creditmanager/lib/creditmanager.lib.php');

if (!$user->admin && !$user->hasRight('creditmanager', 'creditmanager_admin')) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "creditmanager@creditmanager"));

$action = GETPOST('action', 'aZ09');
$form = new Form($db);


/*
 * Actions
 */

if ($action == 'setparam' && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	// Low balance alert threshold
	$low_balance_threshold = GETPOST('CREDITMANAGER_LOW_BALANCE_THRESHOLD', 'alphanohtml');
	if ($low_balance_threshold !== '') {
		dolibarr_set_const($db, 'CREDITMANAGER_LOW_BALANCE_THRESHOLD', $low_balance_threshold, 'chaine', 0, '', $conf->entity);
	}

	// Enable/disable email alerts
	$enable_alerts = GETPOSTINT('CREDITMANAGER_ENABLE_ALERTS');
	dolibarr_set_const($db, 'CREDITMANAGER_ENABLE_ALERTS', $enable_alerts, 'chaine', 0, '', $conf->entity);

	// Default debit mode
	$default_debit_mode = GETPOST('CREDITMANAGER_DEFAULT_DEBIT_MODE', 'alpha');
	if (in_array($default_debit_mode, array('auto', 'delayed', 'manual'))) {
		dolibarr_set_const($db, 'CREDITMANAGER_DEFAULT_DEBIT_MODE', $default_debit_mode, 'chaine', 0, '', $conf->entity);
	}

	// Default debit delay days
	$default_delay = GETPOSTINT('CREDITMANAGER_DEFAULT_DEBIT_DELAY');
	if ($default_delay > 0) {
		dolibarr_set_const($db, 'CREDITMANAGER_DEFAULT_DEBIT_DELAY', $default_delay, 'chaine', 0, '', $conf->entity);
	}

	// Enable client portal
	$enable_portal = GETPOSTINT('CREDITMANAGER_ENABLE_CLIENT_PORTAL');
	dolibarr_set_const($db, 'CREDITMANAGER_ENABLE_CLIENT_PORTAL', $enable_portal, 'chaine', 0, '', $conf->entity);

	// CSV separator for import/export
	$csv_sep = GETPOST('CREDITMANAGER_CSV_SEPARATOR', 'alphanohtml');
	if (in_array($csv_sep, array(';', ',', '\t', '|'))) {
		dolibarr_set_const($db, 'CREDITMANAGER_CSV_SEPARATOR', $csv_sep, 'chaine', 0, '', $conf->entity);
	}

	// Allow negative amounts in attribution
	$allow_neg = GETPOSTINT('CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION');
	dolibarr_set_const($db, 'CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION', $allow_neg, 'chaine', 0, '', $conf->entity);

	// Allow balance to go below zero
	$allow_neg_bal = GETPOSTINT('CREDITMANAGER_ALLOW_NEGATIVE_BALANCE');
	dolibarr_set_const($db, 'CREDITMANAGER_ALLOW_NEGATIVE_BALANCE', $allow_neg_bal, 'chaine', 0, '', $conf->entity);

	// Send notification after attribution
	$notify = GETPOSTINT('CREDITMANAGER_NOTIFY_ON_ATTRIBUTION');
	dolibarr_set_const($db, 'CREDITMANAGER_NOTIFY_ON_ATTRIBUTION', $notify, 'chaine', 0, '', $conf->entity);

	// Max amount per single attribution (0 = unlimited)
	$max_amount = price2num(GETPOST('CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', 'alphanohtml'), 'MT');
	dolibarr_set_const($db, 'CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', ($max_amount >= 0 ? $max_amount : 0), 'chaine', 0, '', $conf->entity);

	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$title = $langs->trans("CreditManagerSettings");
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-creditmanager page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($title, $linkback, 'title_setup');

$head = creditmanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("CreditManager"), -1, 'creditmanager@creditmanager');


print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setparam">';

print '<table class="noborder centpercent">';

// --- Section: General ---
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("GeneralSettings").'</td>';
print '</tr>';

// Default debit mode
print '<tr class="oddeven">';
print '<td>'.$langs->trans("DefaultDebitMode").'</td>';
print '<td>';
$currentMode = getDolGlobalString('CREDITMANAGER_DEFAULT_DEBIT_MODE', 'manual');
print '<select name="CREDITMANAGER_DEFAULT_DEBIT_MODE" class="flat maxwidth250">';
print '<option value="auto"'.($currentMode == 'auto' ? ' selected' : '').'>'.$langs->trans("ImmediateDebit").' - '.$langs->trans("ImmediateDebitDesc").'</option>';
print '<option value="delayed"'.($currentMode == 'delayed' ? ' selected' : '').'>'.$langs->trans("DelayedDebit").' - '.$langs->trans("DelayedDebitDesc").'</option>';
print '<option value="manual"'.($currentMode == 'manual' ? ' selected' : '').'>'.$langs->trans("ManualDebit").' - '.$langs->trans("ManualDebitDesc").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Default debit delay days
print '<tr class="oddeven">';
print '<td>'.$langs->trans("DefaultDebitDelay").'</td>';
print '<td>';
$currentDelay = getDolGlobalString('CREDITMANAGER_DEFAULT_DEBIT_DELAY', '30');
print '<input type="number" name="CREDITMANAGER_DEFAULT_DEBIT_DELAY" value="'.dol_escape_htmltag($currentDelay).'" class="maxwidth100" min="1" max="365">';
print ' <span class="opacitymedium">'.$langs->trans("DebitDelayDaysUnit").'</span>';
print '</td>';
print '</tr>';

// --- Section: Alerts ---
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("AlertSettings").'</td>';
print '</tr>';

// Enable email alerts
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EnableEmailAlerts").'</td>';
print '<td>';
$enableAlerts = getDolGlobalString('CREDITMANAGER_ENABLE_ALERTS', '0');
print '<input type="checkbox" name="CREDITMANAGER_ENABLE_ALERTS" value="1"'.($enableAlerts ? ' checked' : '').'>';
print '</td>';
print '</tr>';

// Low balance threshold
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LowBalanceThreshold").'</td>';
print '<td>';
$threshold = getDolGlobalString('CREDITMANAGER_LOW_BALANCE_THRESHOLD', '10');
print '<input type="number" name="CREDITMANAGER_LOW_BALANCE_THRESHOLD" value="'.dol_escape_htmltag($threshold).'" class="maxwidth100" min="0" step="0.5">';
print ' <span class="opacitymedium">'.$langs->trans("Hours").'</span>';
print '</td>';
print '</tr>';

// --- Section: Client Portal ---
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("ClientPortalSettings").'</td>';
print '</tr>';

// Enable client portal
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EnableClientPortal").'</td>';
print '<td>';
$enablePortal = getDolGlobalString('CREDITMANAGER_ENABLE_CLIENT_PORTAL', '0');
print '<input type="checkbox" name="CREDITMANAGER_ENABLE_CLIENT_PORTAL" value="1"'.($enablePortal ? ' checked' : '').'>';
print ' <span class="opacitymedium">'.$langs->trans("EnableClientPortalDesc").'</span>';
print '</td>';
print '</tr>';

// --- Section: Attribution ---
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("AttributionSettings").'</td>';
print '</tr>';

// Max amount per attribution
print '<tr class="oddeven">';
print '<td>'.$langs->trans("MaxAttributionAmount").'</td>';
print '<td>';
$maxAmount = getDolGlobalString('CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', '0');
print '<input type="number" name="CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT" value="'.dol_escape_htmltag($maxAmount).'" class="maxwidth100" min="0" step="0.5">';
print ' <span class="opacitymedium">'.$langs->trans("MaxAttributionAmountDesc").'</span>';
print '</td>';
print '</tr>';

// Allow negative amounts
print '<tr class="oddeven">';
print '<td>'.$langs->trans("AllowNegativeAttribution").'</td>';
print '<td>';
$allowNeg = getDolGlobalString('CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION', '0');
print '<input type="checkbox" name="CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION" value="1"'.($allowNeg ? ' checked' : '').'>';
print ' <span class="opacitymedium">'.$langs->trans("AllowNegativeAttributionDesc").'</span>';
print '</td>';
print '</tr>';

// Allow negative balance
print '<tr class="oddeven">';
print '<td>'.$langs->trans("AllowNegativeBalance").'</td>';
print '<td>';
$allowNegBal = getDolGlobalString('CREDITMANAGER_ALLOW_NEGATIVE_BALANCE', '0');
print '<input type="checkbox" name="CREDITMANAGER_ALLOW_NEGATIVE_BALANCE" value="1"'.($allowNegBal ? ' checked' : '').'>';
print ' <span class="opacitymedium">'.$langs->trans("AllowNegativeBalanceDesc").'</span>';
print '</td>';
print '</tr>';

// Notify after attribution
print '<tr class="oddeven">';
print '<td>'.$langs->trans("NotifyOnAttribution").'</td>';
print '<td>';
$notify = getDolGlobalString('CREDITMANAGER_NOTIFY_ON_ATTRIBUTION', '0');
print '<input type="checkbox" name="CREDITMANAGER_NOTIFY_ON_ATTRIBUTION" value="1"'.($notify ? ' checked' : '').'>';
print ' <span class="opacitymedium">'.$langs->trans("NotifyOnAttributionDesc").'</span>';
print '</td>';
print '</tr>';

// --- Section: Import/Export ---
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("ImportExportSettings").'</td>';
print '</tr>';

// CSV separator
print '<tr class="oddeven">';
print '<td>'.$langs->trans("CsvSeparator").'</td>';
print '<td>';
$csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
print '<select name="CREDITMANAGER_CSV_SEPARATOR" class="flat maxwidth100">';
print '<option value=";"'.($csvSep == ';' ? ' selected' : '').'>; ('. $langs->trans("Semicolon").')</option>';
print '<option value=","'.($csvSep == ',' ? ' selected' : '').'>, ('.$langs->trans("Comma").')</option>';
print '<option value="\t"'.($csvSep == "\t" ? ' selected' : '').'>'.$langs->trans("Tab").'</option>';
print '<option value="|"'.($csvSep == '|' ? ' selected' : '').'>| ('.$langs->trans("Pipe").')</option>';
print '</select>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
