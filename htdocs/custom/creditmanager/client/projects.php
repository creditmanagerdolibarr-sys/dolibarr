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
 * \file       htdocs/custom/creditmanager/client/projects.php
 * \ingroup    creditmanager
 * \brief      Client portal: associated projects for the linked third party
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
if (!getDolGlobalInt('CREDITMANAGER_CLIENT_SHOW_PROJECTS', 0)) {
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

$entityProject = getEntity('project');
$rows = array();
$sql = "SELECT pr.rowid, pr.ref, pr.title, pr.fk_statut, pr.dateo, pr.datee";
$sql .= " FROM ".$db->prefix()."projet as pr";
$sql .= " WHERE pr.entity IN (".$entityProject.")";
$sql .= " AND pr.fk_soc = ".((int) $socid);
$sql .= " ORDER BY pr.ref ASC";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = $obj;
	}
	$db->free($resql);
}

$morecss = array('/custom/creditmanager/css/creditmanager.css');
creditmanagerEnsureLeftMenuFlat($db);
llxHeader('', $langs->trans('CreditClientProjectsTitle'), '', '', 0, 0, '', $morecss, '', 'mod-creditmanager page-client-projects');

print load_fiche_titre($langs->trans('CreditClientProjectsTitle'), '', 'project');
print '<div class="opacitymedium marginbottomonly">'.dol_escape_htmltag($object->name).'</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/custom/creditmanager/client/index.php', 1).(empty($user->socid) ? '?socid='.$socid : '').'">'.$langs->trans('CreditClientMyCredits').'</a>';
print '</div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Label').'</th>';
print '<th>'.$langs->trans('DateStart').'</th>';
print '<th>'.$langs->trans('DateEnd').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans('CreditClientProjectsEmpty').'</td></tr>';
}

foreach ($rows as $obj) {
	$url = dol_buildpath('/projet/card.php', 1).'?id='.((int) $obj->rowid);
	$statusLabel = '';
	if ((int) $obj->fk_statut === 0) {
		$statusLabel = $langs->trans('CreditClientProjectDraft');
	} elseif ((int) $obj->fk_statut === 1) {
		$statusLabel = $langs->trans('CreditClientProjectOpen');
	} else {
		$statusLabel = $langs->trans('CreditClientProjectClosed');
	}

	print '<tr class="oddeven">';
	print '<td><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($obj->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($obj->title).'</td>';
	print '<td>'.($obj->dateo ? dol_print_date($db->jdate($obj->dateo), 'day') : '').'</td>';
	print '<td>'.($obj->datee ? dol_print_date($db->jdate($obj->datee), 'day') : '').'</td>';
	print '<td>'.dol_escape_htmltag($statusLabel).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
