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
 *	\file       htdocs/custom/creditmanager/admin/tools.php
 *	\ingroup    creditmanager
 *	\brief      Maintenance tools for Credit Manager (balance recalculation, consistency checks)
 */

require '../../../main.inc.php';

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/creditmanager/lib/creditmanager.lib.php');

global $db, $conf, $langs, $user;

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('admin', 'creditmanager@creditmanager'));

$form = new Form($db);

if (!$user->admin && !$user->hasRight('creditmanager', 'creditmanager_admin')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$token = newToken();

/*
 * Actions
 */

$error = 0;

if ($action === 'recalculate_balances' && $confirm === 'yes' && $user->admin) {
	$db->begin();

	dol_syslog('CreditManager: Starting balance recalculation', LOG_INFO);

	$recalculated = 0;
	$errors = 0;

	$sql = "SELECT DISTINCT fk_soc, fk_credit_type FROM ".$db->prefix()."credits_movements";
	$sql .= " WHERE entity = ".((int) $conf->entity);

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('CreditManager: Error fetching movements: '.$db->lasterror(), LOG_ERR);
		setEventMessages($langs->trans('Error').': '.$db->lasterror(), null, 'errors');
		$db->rollback();
		$action = '';
	} else {
		$pairs = array();
		while ($obj = $db->fetch_object($resql)) {
			$pairs[] = array('fk_soc' => (int) $obj->fk_soc, 'fk_credit_type' => (int) $obj->fk_credit_type);
		}
		$db->free($resql);

		$sqlDelete = "DELETE FROM ".$db->prefix()."credits_balance WHERE entity = ".((int) $conf->entity);
		if (!$db->query($sqlDelete)) {
			dol_syslog('CreditManager: Error clearing balances: '.$db->lasterror(), LOG_ERR);
			setEventMessages($langs->trans('Error').': '.$db->lasterror(), null, 'errors');
			$db->rollback();
			$action = '';
		} else {
			foreach ($pairs as $pair) {
				$sqlSum = "SELECT SUM(amount) as total FROM ".$db->prefix()."credits_movements";
				$sqlSum .= " WHERE fk_soc = ".((int) $pair['fk_soc']);
				$sqlSum .= " AND fk_credit_type = ".((int) $pair['fk_credit_type']);
				$sqlSum .= " AND entity = ".((int) $conf->entity);

				$resSql = $db->query($sqlSum);
				if (!$resSql) {
					$errors++;
					dol_syslog('CreditManager: Error summing movements for pair: '.$db->lasterror(), LOG_ERR);
					continue;
				}

				$objSum = $db->fetch_object($resSql);
				$db->free($resSql);

				$balance = (float) ($objSum ? $objSum->total : 0);

				$sqlInsert = "INSERT INTO ".$db->prefix()."credits_balance (entity, fk_soc, fk_credit_type, balance, tms)";
				$sqlInsert .= " VALUES (".((int) $conf->entity).", ".((int) $pair['fk_soc']).", ".((int) $pair['fk_credit_type']).", ";
				$sqlInsert .= ((float) $balance).", NOW())";

				if (!$db->query($sqlInsert)) {
					$errors++;
					dol_syslog('CreditManager: Error inserting balance: '.$db->lasterror(), LOG_ERR);
				} else {
					$recalculated++;
				}
			}

			if ($errors === 0) {
				$db->commit();
				dol_syslog('CreditManager: Balance recalculation completed successfully ('.$recalculated.' pairs)', LOG_INFO);
				setEventMessages($langs->trans('CreditManagerBalancesRecalculated', $recalculated), null, 'mesgs');
			} else {
				$db->rollback();
				dol_syslog('CreditManager: Balance recalculation completed with '.$errors.' error(s)', LOG_WARNING);
				setEventMessages($langs->trans('CreditManagerBalancesRecalculatedWithErrors', $recalculated, $errors), null, 'warnings');
				$action = '';
			}
		}
	}
}

if ($action === 'check_consistency' && $user->admin) {
	dol_syslog('CreditManager: Starting consistency check', LOG_INFO);

	$issues = array();

	$sqlOrphaned = "SELECT DISTINCT m.fk_soc, m.fk_credit_type";
	$sqlOrphaned .= " FROM ".$db->prefix()."credits_movements as m";
	$sqlOrphaned .= " LEFT JOIN ".$db->prefix()."credits_balance as b ON (b.fk_soc = m.fk_soc AND b.fk_credit_type = m.fk_credit_type)";
	$sqlOrphaned .= " WHERE m.entity = ".((int) $conf->entity);
	$sqlOrphaned .= " AND b.rowid IS NULL";

	$resOrphaned = $db->query($sqlOrphaned);
	if ($resOrphaned) {
		$orphanedCount = 0;
		while ($obj = $db->fetch_object($resOrphaned)) {
			$orphanedCount++;
		}
		$db->free($resOrphaned);
		if ($orphanedCount > 0) {
			$issues[] = $langs->trans('CreditManagerOrphanedMovements', $orphanedCount);
		}
	}

	$sqlNegativeBalance = "SELECT COUNT(*) as cnt FROM ".$db->prefix()."credits_balance";
	$sqlNegativeBalance .= " WHERE entity = ".((int) $conf->entity);
	$sqlNegativeBalance .= " AND balance < 0";

	$resNegative = $db->query($sqlNegativeBalance);
	if ($resNegative) {
		$objNeg = $db->fetch_object($resNegative);
		$db->free($resNegative);
		if ($objNeg && (int) $objNeg->cnt > 0 && !getDolGlobalString('CREDITMANAGER_ALLOW_NEGATIVE_BALANCE')) {
			$issues[] = $langs->trans('CreditManagerNegativeBalances', (int) $objNeg->cnt);
		}
	}

	$sqlInvalidType = "SELECT DISTINCT m.fk_credit_type FROM ".$db->prefix()."credits_movements as m";
	$sqlInvalidType .= " LEFT JOIN ".$db->prefix()."credits_types as t ON t.rowid = m.fk_credit_type";
	$sqlInvalidType .= " WHERE t.rowid IS NULL AND m.entity = ".((int) $conf->entity);

	$resInvalid = $db->query($sqlInvalidType);
	if ($resInvalid) {
		$invalidCount = 0;
		while ($obj = $db->fetch_object($resInvalid)) {
			$invalidCount++;
		}
		$db->free($resInvalid);
		if ($invalidCount > 0) {
			$issues[] = $langs->trans('CreditManagerInvalidCreditTypes', $invalidCount);
		}
	}

	dol_syslog('CreditManager: Consistency check completed ('.count($issues).' issue(s))', LOG_INFO);

	if (count($issues) === 0) {
		setEventMessages($langs->trans('CreditManagerConsistencyCheckOK'), null, 'mesgs');
	} else {
		foreach ($issues as $issue) {
			setEventMessages($issue, null, 'warnings');
		}
	}
}

/*
 * View
 */

$title = $langs->trans('CreditManagerTools');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-creditmanager page-admin-tools');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';

print load_fiche_titre($title, $linkback, 'title_setup');

$head = creditmanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'tools', $langs->trans('CreditManager'), -1, 'creditmanager@creditmanager');

print '<div class="fichecenter">';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Tool').'</th>';
print '<th>'.$langs->trans('Description').'</th>';
print '<th class="center">'.$langs->trans('Action').'</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans('CreditManagerRecalculateBalances').'</strong></td>';
print '<td>'.$langs->trans('CreditManagerRecalculateBalancesDesc').'</td>';
print '<td class="center">';
print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST" style="display:inline;">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="recalculate_balances">';
print '<input type="submit" class="button" value="'.$langs->trans('Execute').'">';
print '</form>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans('CreditManagerConsistencyCheck').'</strong></td>';
print '<td>'.$langs->trans('CreditManagerConsistencyCheckDesc').'</td>';
print '<td class="center">';
print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST" style="display:inline;">';
print '<input type="hidden" name="token" value="'.$token.'">';
print '<input type="hidden" name="action" value="check_consistency">';
print '<input type="submit" class="button" value="'.$langs->trans('Execute').'">';
print '</form>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

print '</div>';

if ($action === 'recalculate_balances' && $confirm !== 'yes') {
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?action=recalculate_balances',
		$langs->trans('CreditManagerRecalculateBalances'),
		$langs->trans('CreditManagerConfirmRecalculateBalances'),
		'recalculate_balances',
		array(),
		0,
		1
	);
	print $formconfirm;
}

print dol_get_fiche_end();

llxFooter();
$db->close();
