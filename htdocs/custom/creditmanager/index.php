<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/creditmanager/index.php
 * \ingroup    creditmanager
 * \brief      Dashboard / home page for Credit Manager module
 */

// Load Dolibarr environment
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';

creditmanagerEnsureLeftMenuFlat($db);

$langs->loadLangs(array("creditmanager@creditmanager"));

// Access control
if (!creditmanagerCanReadModule($user)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');


/*
 * View
 */

llxHeader("", $langs->trans("CreditManager"), '', '', 0, 0, '', '', '', 'mod-creditmanager page-index');

print load_fiche_titre($langs->trans("CreditManager"), '', 'object_credit@creditmanager');

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// Left column - summary boxes will go here
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans("CreditManagerDashboard").'</th></tr>';
print '<tr class="oddeven"><td colspan="2" class="opacitymedium">';
print $langs->trans("CreditManagerDashboardDescription");
print '</td></tr>';
if (creditmanagerCanApproveTimesheets($user) || creditmanagerCanManageAdmin($user)) {
	print '<tr class="oddeven"><td colspan="2">';
	print '<a class="button" href="'.dol_buildpath('/custom/creditmanager/dashboard/dashboard_pm.php', 1).'">';
	print $langs->trans('CreditDashboardPmHomeLink');
	print '</a>';
	print '</td></tr>';
}
print '</table>';
print '</div>';

print '</div>';
print '<div class="fichetwothirdright">';

// Right column - stats / graphs will go here
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans("Statistics").'</th></tr>';
print '<tr class="oddeven"><td class="opacitymedium">';
print $langs->trans("CreditManagerStatsPlaceholder");
print '</td></tr>';
print '</table>';
print '</div>';

print '</div>';
print '</div>';

// End of page
llxFooter();
$db->close();
