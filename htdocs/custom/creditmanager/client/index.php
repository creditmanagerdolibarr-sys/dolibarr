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
 * \file       htdocs/custom/creditmanager/client/index.php
 * \ingroup    creditmanager
 * \brief      Client portal hub: My credits (balance badge + shortcuts)
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';

$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'projects', 'other'));

if (!creditmanagerCanAccessClientPortalPages($user)) {
	accessforbidden();
}

$socid = 0;
if (creditmanagerIsClientPortalUser($user)) {
	$socid = (int) $user->socid;
} else {
	$socid = GETPOSTINT('socid');
	if ($socid <= 0) {
		accessforbidden($langs->trans('CreditClientBalanceNeedSocid'));
	}
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	accessforbidden();
}

$totalBalance = creditmanagerGetClientTotalBalance($db, $socid);
$showProjects = getDolGlobalInt('CREDITMANAGER_CLIENT_SHOW_PROJECTS', 0);
$allowRequest = getDolGlobalInt('CREDITMANAGER_ALLOW_CLIENT_CREDIT_REQUEST', 0);

$balanceUrl = dol_buildpath('/custom/creditmanager/client/balance.php', 1).(empty($user->socid) ? '?socid='.$socid : '');
$historyUrl = dol_buildpath('/custom/creditmanager/client/history.php', 1).(empty($user->socid) ? '?socid='.$socid : '');
$projectsUrl = dol_buildpath('/custom/creditmanager/client/projects.php', 1).(empty($user->socid) ? '?socid='.$socid : '');
$requestUrl = dol_buildpath('/custom/creditmanager/client/balance.php', 1).(empty($user->socid) ? '?socid='.$socid : '').'#credit-request-form';

$projectCount = 0;
if ($showProjects) {
	$sqlPr = "SELECT COUNT(rowid) as nb FROM ".$db->prefix()."projet";
	$sqlPr .= " WHERE entity IN (".getEntity('project').")";
	$sqlPr .= " AND fk_soc = ".((int) $socid);
	$resPr = $db->query($sqlPr);
	if ($resPr) {
		$objPr = $db->fetch_object($resPr);
		$projectCount = (int) ($objPr->nb ?? 0);
		$db->free($resPr);
	}
}

$morecss = array('/custom/creditmanager/css/creditmanager.css');
creditmanagerEnsureLeftMenuFlat($db);
llxHeader('', $langs->trans('CreditClientMyCredits'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-client-home');

print load_fiche_titre($langs->trans('CreditClientMyCredits'), '', 'object_bill');
print '<div class="opacitymedium marginbottomonly">'.dol_escape_htmltag($object->name).'</div>';

print '<div class="creditmanager-balance-total">';
print '<span class="opacitymedium">'.$langs->trans('CreditClientTotalBalance').'</span> ';
print '<strong class="creditmanager-balance-total-value">'.creditmanagerFormatAmount($totalBalance).'</strong>';
print creditmanagerFormatBalanceBadge($totalBalance);
print '</div>';

print '<div class="creditmanager-balance-cards">';

print '<a class="creditmanager-balance-card creditmanager-card-safe" href="'.dol_escape_htmltag($balanceUrl).'" style="text-decoration:none;color:inherit;">';
print '<div class="creditmanager-card-title">'.$langs->trans('CreditClientBalanceMenu').'</div>';
print '<div class="creditmanager-card-label opacitymedium">'.$langs->trans('CreditClientBalanceHubHint').'</div>';
print '<div class="creditmanager-card-balance">'.creditmanagerFormatAmount($totalBalance).'</div>';
print '</a>';

print '<a class="creditmanager-balance-card creditmanager-card-safe" href="'.dol_escape_htmltag($historyUrl).'" style="text-decoration:none;color:inherit;">';
print '<div class="creditmanager-card-title">'.$langs->trans('CreditClientHistoryMenu').'</div>';
print '<div class="creditmanager-card-label opacitymedium">'.$langs->trans('CreditClientHistoryHubHint').'</div>';
print '</a>';

if ($showProjects) {
	print '<a class="creditmanager-balance-card creditmanager-card-safe" href="'.dol_escape_htmltag($projectsUrl).'" style="text-decoration:none;color:inherit;">';
	print '<div class="creditmanager-card-title">'.$langs->trans('CreditClientProjectsMenu').'</div>';
	print '<div class="creditmanager-card-label opacitymedium">'.$langs->trans('CreditClientProjectsHubHint').'</div>';
	print '<div class="creditmanager-card-balance"><span class="badge">'.$projectCount.'</span></div>';
	print '</a>';
}

if ($allowRequest) {
	print '<a class="creditmanager-balance-card creditmanager-card-warning" href="'.dol_escape_htmltag($requestUrl).'" style="text-decoration:none;color:inherit;">';
	print '<div class="creditmanager-card-title">'.$langs->trans('CreditClientRequestsMenu').'</div>';
	print '<div class="creditmanager-card-label opacitymedium">'.$langs->trans('CreditClientRequestsHubHint').'</div>';
	print '</a>';
}

print '</div>';

llxFooter();
$db->close();
