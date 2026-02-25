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
 *	\file       htdocs/custom/creditmanager/admin/credit_types.php
 *	\ingroup    creditmanager
 *	\brief      CRUD interface for credit types (with soft-delete when type is used in movements/balances)
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
dol_include_once('/creditmanager/class/CreditType.class.php');
dol_include_once('/creditmanager/lib/creditmanager.lib.php');

if (!$user->admin && !$user->hasRight('creditmanager', 'creditmanager_admin')) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "creditmanager@creditmanager"));

$object = new CreditType($db);
$form = new Form($db);

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('rowid');
$massaction = GETPOST('massaction', 'alpha');
$toselect = GETPOST('toselect', 'array');
$backtopage = GETPOST('backtopage', 'alpha');

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;

if (!$sortfield) {
	$sortfield = 'ct.code';
}
if (!$sortorder) {
	$sortorder = 'ASC';
}

$search_code = GETPOST('search_code', 'alpha');
$search_label = GETPOST('search_label', 'alpha');
$search_active = GETPOST('search_active', 'intcomma');

$param = '';
if (!empty($search_code)) {
	$param .= '&search_code='.urlencode($search_code);
}
if (!empty($search_label)) {
	$param .= '&search_label='.urlencode($search_label);
}
if ($search_active !== '' && $search_active !== '-1') {
	$param .= '&search_active='.urlencode($search_active);
}


/*
 * Actions
 */

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_code = '';
	$search_label = '';
	$search_active = '';
	$param = '';
}

if ($action == 'add' && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$error = 0;

	$object->code = strtoupper(trim(GETPOST('code', 'aZ09')));
	$object->label = trim(GETPOST('label', 'alphanohtml'));
	$object->unit = GETPOST('unit', 'alpha') ? trim(GETPOST('unit', 'alpha')) : 'hour';
	$object->auto_debit = GETPOSTINT('auto_debit') ? 1 : 0;
	$object->debit_delay_days = (GETPOSTINT('debit_delay_days') > 0) ? GETPOSTINT('debit_delay_days') : null;
	$object->precision_unit = GETPOST('precision_unit', 'alpha') ? trim(GETPOST('precision_unit', 'alpha')) : 'hour';
	$object->active = 1;

	if (empty($object->code)) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Code")), null, 'errors');
		$error++;
	}
	if (empty($object->label)) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Label")), null, 'errors');
		$error++;
	}

	// auto_debit=1 ⇒ debit_delay_days forced NULL
	if ($object->auto_debit) {
		$object->debit_delay_days = null;
	}

	if (!$error) {
		$db->begin();

		$result = $object->create($user);
		if ($result > 0) {
			$db->commit();
			setEventMessages($langs->trans("CreditTypeCreated"), null, 'mesgs');
			header("Location: ".$_SERVER['PHP_SELF']);
			exit;
		} else {
			$db->rollback();
			if ($db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
				setEventMessages($langs->trans("ErrorCodeAlreadyExists", $object->code), null, 'errors');
			} else {
				setEventMessages($object->error, $object->errors, 'errors');
			}
			$action = 'create';
		}
	} else {
		$action = 'create';
	}
}

if ($action == 'update' && $id > 0 && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$error = 0;

	$result = $object->fetch($id);
	if ($result <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
		$action = '';
	} else {
		$object->code = strtoupper(trim(GETPOST('code', 'aZ09')));
		$object->label = trim(GETPOST('label', 'alphanohtml'));
		$object->unit = GETPOST('unit', 'alpha') ? trim(GETPOST('unit', 'alpha')) : 'hour';
		$object->auto_debit = GETPOSTINT('auto_debit') ? 1 : 0;
		$object->debit_delay_days = (GETPOSTINT('debit_delay_days') > 0) ? GETPOSTINT('debit_delay_days') : null;
		$object->precision_unit = GETPOST('precision_unit', 'alpha') ? trim(GETPOST('precision_unit', 'alpha')) : 'hour';

		if ($object->auto_debit) {
			$object->debit_delay_days = null;
		}

		if (empty($object->code)) {
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Code")), null, 'errors');
			$error++;
		}
		if (empty($object->label)) {
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Label")), null, 'errors');
			$error++;
		}

		if (!$error) {
			$result = $object->update($user);
			if ($result > 0) {
				setEventMessages($langs->trans("CreditTypeUpdated"), null, 'mesgs');
				header("Location: ".$_SERVER['PHP_SELF']);
				exit;
			} else {
				if ($db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
					setEventMessages($langs->trans("ErrorCodeAlreadyExists", $object->code), null, 'errors');
				} else {
					setEventMessages($object->error, $object->errors, 'errors');
				}
				$action = 'edit';
			}
		} else {
			$action = 'edit';
		}
	}
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0 && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$result = $object->fetch($id);
	if ($result > 0) {
		// CreditType::delete() handles soft-delete internally (sets active=0 if used)
		$result = $object->delete($user);
		if ($result > 0) {
			if ($object->active == 0) {
				setEventMessages($langs->trans("CreditTypeDeactivated"), null, 'warnings');
			} else {
				setEventMessages($langs->trans("CreditTypeDeleted"), null, 'mesgs');
			}
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} else {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	}
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'activate' && $id > 0 && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$result = $object->fetch($id);
	if ($result > 0) {
		$object->active = 1;
		$object->update($user);
		setEventMessages($langs->trans("CreditTypeActivated"), null, 'mesgs');
	}
	header("Location: ".$_SERVER['PHP_SELF'].'?'.$param);
	exit;
}

if ($action == 'disable' && $id > 0 && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$result = $object->fetch($id);
	if ($result > 0) {
		$object->active = 0;
		$object->update($user);
		setEventMessages($langs->trans("CreditTypeDisabled"), null, 'mesgs');
	}
	header("Location: ".$_SERVER['PHP_SELF'].'?'.$param);
	exit;
}

if ($massaction == 'delete' && !empty($toselect) && ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin'))) {
	$nbok = 0;
	foreach ($toselect as $toselectid) {
		$objecttmp = new CreditType($db);
		$result = $objecttmp->fetch((int) $toselectid);
		if ($result > 0) {
			$result = $objecttmp->delete($user);
			if ($result > 0) {
				$nbok++;
			}
		}
	}
	if ($nbok > 0) {
		setEventMessages($langs->trans("RecordsDeleted", $nbok), null, 'mesgs');
	}
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'export_csv') {
	$sql = "SELECT ct.rowid, ct.code, ct.label, ct.unit, ct.auto_debit, ct.debit_delay_days, ct.active, ct.precision_unit";
	$sql .= " FROM ".$db->prefix()."credits_types as ct";
	$sql .= " WHERE ct.entity IN (".getEntity('credits_type').")";
	$sql .= $db->order('ct.code', 'ASC');

	$resql = $db->query($sql);
	if ($resql) {
		$filename = 'credit_types_'.dol_print_date(dol_now(), '%Y%m%d_%H%M').'.csv';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename='.$filename);
		header('Cache-Control: max-age=0');

		$output = fopen('php://output', 'w');
		fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 (Excel)

		fputcsv($output, array(
			$langs->transnoentitiesnoconv("Code"),
			$langs->transnoentitiesnoconv("Label"),
			$langs->transnoentitiesnoconv("Unit"),
			$langs->transnoentitiesnoconv("AutoDebit"),
			$langs->transnoentitiesnoconv("DebitDelayDays"),
			$langs->transnoentitiesnoconv("Status"),
			$langs->transnoentitiesnoconv("PrecisionUnit")
		), ';');

		while ($obj = $db->fetch_object($resql)) {
			if ($obj->auto_debit) {
				$debitMode = $langs->transnoentitiesnoconv("ImmediateDebit");
			} elseif ($obj->debit_delay_days > 0) {
				$debitMode = $langs->trans("DelayedDebitDays", $obj->debit_delay_days);
			} else {
				$debitMode = $langs->transnoentitiesnoconv("ManualDebit");
			}

			fputcsv($output, array(
				$obj->code,
				$obj->label,
				$obj->unit,
				$debitMode,
				($obj->debit_delay_days > 0 ? $obj->debit_delay_days : ''),
				($obj->active ? $langs->transnoentitiesnoconv("Enabled") : $langs->transnoentitiesnoconv("Disabled")),
				$obj->precision_unit
			), ';');
		}
		fclose($output);
		$db->free($resql);
		exit;
	}
}


/*
 * View
 */

$title = $langs->trans("CreditTypesManagement");
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-creditmanager page-admin-credit-types');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($title, $linkback, 'title_setup');

$head = creditmanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'credit_types', $langs->trans("CreditManager"), -1, 'creditmanager@creditmanager');


// Delete confirmation — warns about soft-delete if type is referenced in movements/balances
if ($action == 'delete' && $id > 0) {
	$object->fetch($id);

	$sqlCheck = "SELECT";
	$sqlCheck .= " (SELECT COUNT(*) FROM ".$db->prefix()."credits_movements WHERE fk_credit_type = ".((int) $id).") as mv,";
	$sqlCheck .= " (SELECT COUNT(*) FROM ".$db->prefix()."credits_balance WHERE fk_credit_type = ".((int) $id).") as bl";
	$resqlCheck = $db->query($sqlCheck);
	$objCheck = $db->fetch_object($resqlCheck);
	$isUsed = ($objCheck && ((int) $objCheck->mv > 0 || (int) $objCheck->bl > 0));

	$message = $langs->trans("ConfirmDeleteCreditType", dol_escape_htmltag($object->label));
	if ($isUsed) {
		$message .= '<br><span class="warning">'.$langs->trans("CreditTypeUsedWillBeDeactivated").'</span>';
	}

	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?rowid='.$id,
		$langs->trans("DeleteCreditType"),
		$message,
		'confirm_delete',
		'',
		0,
		1
	);
}


if ($action == 'create') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';

	print load_fiche_titre($langs->trans("NewCreditType"), '', '');

	print dol_get_fiche_head(array(), '');

	print '<table class="border centpercent tableforfieldcreate">';

	print '<tr>';
	print '<td class="fieldrequired titlefieldcreate">'.$langs->trans("Code").'</td>';
	print '<td><input type="text" name="code" value="'.dol_escape_htmltag(GETPOST('code', 'aZ09')).'" class="maxwidth200" maxlength="32" required autofocus>';
	print '<br><span class="opacitymedium small">'.$langs->trans("CreditTypeCodeHelp").'</span>';
	print '</td>';
	print '</tr>';

	print '<tr>';
	print '<td class="fieldrequired">'.$langs->trans("Label").'</td>';
	print '<td><input type="text" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'alphanohtml')).'" class="maxwidth300" maxlength="255" required></td>';
	print '</tr>';

	print '<tr>';
	print '<td>'.$langs->trans("Unit").'</td>';
	print '<td>';
	$selectedUnit = GETPOST('unit', 'alpha') ? GETPOST('unit', 'alpha') : 'hour';
	print '<select name="unit" class="flat maxwidth150">';
	print '<option value="hour"'.($selectedUnit == 'hour' ? ' selected' : '').'>'.$langs->trans("Hours").'</option>';
	print '<option value="day"'.($selectedUnit == 'day' ? ' selected' : '').'>'.$langs->trans("Days").'</option>';
	print '<option value="credit"'.($selectedUnit == 'credit' ? ' selected' : '').'>'.$langs->trans("Credits").'</option>';
	print '</select>';
	print '</td>';
	print '</tr>';

	print '<tr>';
	print '<td>'.$langs->trans("DebitMode").'</td>';
	print '<td>';
	$selectedDebitMode = getDolGlobalString('CREDITMANAGER_DEFAULT_DEBIT_MODE', 'manual');
	if (GETPOSTISSET('auto_debit')) {
		$selectedDebitMode = GETPOSTINT('auto_debit') ? 'auto' : (GETPOSTINT('debit_delay_days') > 0 ? 'delayed' : 'manual');
	}
	print '<select name="debit_mode" id="debit_mode_select" class="flat maxwidth250">';
	print '<option value="auto"'.($selectedDebitMode == 'auto' ? ' selected' : '').'>'.$langs->trans("ImmediateDebit").' - '.$langs->trans("ImmediateDebitDesc").'</option>';
	print '<option value="delayed"'.($selectedDebitMode == 'delayed' ? ' selected' : '').'>'.$langs->trans("DelayedDebit").' - '.$langs->trans("DelayedDebitDesc").'</option>';
	print '<option value="manual"'.($selectedDebitMode == 'manual' ? ' selected' : '').'>'.$langs->trans("ManualDebit").' - '.$langs->trans("ManualDebitDesc").'</option>';
	print '</select>';
	print '<input type="hidden" name="auto_debit" id="auto_debit_hidden" value="'.(GETPOSTINT('auto_debit') ? '1' : '0').'">';
	print '</td>';
	print '</tr>';

	// Visible only when debit_mode=delayed (toggled via JS)
	print '<tr id="debit_delay_row">';
	print '<td>'.$langs->trans("DebitDelayDays").'</td>';
	print '<td>';
	$defaultDelay = GETPOST('debit_delay_days', 'int') ? GETPOST('debit_delay_days', 'int') : getDolGlobalString('CREDITMANAGER_DEFAULT_DEBIT_DELAY', '30');
	print '<input type="number" name="debit_delay_days" id="debit_delay_days_input" value="'.dol_escape_htmltag($defaultDelay).'" class="maxwidth100" min="1" max="365">';
	print ' <span class="opacitymedium">'.$langs->trans("DebitDelayDaysUnit").'</span>';
	print '</td>';
	print '</tr>';

	print '</table>';

	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans("Create").'">';
	print ' &nbsp; <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans("Cancel").'</a>';
	print '</div>';

	print '</form>';
	print '<br>';
}


if ($action == 'edit' && $id > 0) {
	$result = $object->fetch($id);
	if ($result > 0) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="update">';
		print '<input type="hidden" name="rowid" value="'.$id.'">';

		print load_fiche_titre($langs->trans("EditCreditType"), '', '');

		print dol_get_fiche_head(array(), '');

		print '<table class="border centpercent tableforfieldcreate">';

		print '<tr>';
		print '<td class="fieldrequired titlefieldcreate">'.$langs->trans("Code").'</td>';
		print '<td><input type="text" name="code" value="'.dol_escape_htmltag($object->code).'" class="maxwidth200" maxlength="32" required autofocus></td>';
		print '</tr>';

		print '<tr>';
		print '<td class="fieldrequired">'.$langs->trans("Label").'</td>';
		print '<td><input type="text" name="label" value="'.dol_escape_htmltag($object->label).'" class="maxwidth300" maxlength="255" required></td>';
		print '</tr>';

		print '<tr>';
		print '<td>'.$langs->trans("Unit").'</td>';
		print '<td>';
		print '<select name="unit" class="flat maxwidth150">';
		print '<option value="hour"'.($object->unit == 'hour' ? ' selected' : '').'>'.$langs->trans("Hours").'</option>';
		print '<option value="day"'.($object->unit == 'day' ? ' selected' : '').'>'.$langs->trans("Days").'</option>';
		print '<option value="credit"'.($object->unit == 'credit' ? ' selected' : '').'>'.$langs->trans("Credits").'</option>';
		print '</select>';
		print '</td>';
		print '</tr>';

		print '<tr>';
		print '<td>'.$langs->trans("DebitMode").'</td>';
		print '<td>';
		$currentMode = 'manual';
		if ($object->isAutoDebit()) {
			$currentMode = 'auto';
		} elseif ($object->isDelayedDebit()) {
			$currentMode = 'delayed';
		}
		print '<select name="debit_mode" id="debit_mode_select" class="flat maxwidth250">';
		print '<option value="auto"'.($currentMode == 'auto' ? ' selected' : '').'>'.$langs->trans("ImmediateDebit").' - '.$langs->trans("ImmediateDebitDesc").'</option>';
		print '<option value="delayed"'.($currentMode == 'delayed' ? ' selected' : '').'>'.$langs->trans("DelayedDebit").' - '.$langs->trans("DelayedDebitDesc").'</option>';
		print '<option value="manual"'.($currentMode == 'manual' ? ' selected' : '').'>'.$langs->trans("ManualDebit").' - '.$langs->trans("ManualDebitDesc").'</option>';
		print '</select>';
		print '<input type="hidden" name="auto_debit" id="auto_debit_hidden" value="'.($object->auto_debit ? '1' : '0').'">';
		print '</td>';
		print '</tr>';

		print '<tr id="debit_delay_row">';
		print '<td>'.$langs->trans("DebitDelayDays").'</td>';
		print '<td>';
		print '<input type="number" name="debit_delay_days" id="debit_delay_days_input" value="'.($object->debit_delay_days !== null ? (int) $object->debit_delay_days : '').'" class="maxwidth100" min="1" max="365">';
		print ' <span class="opacitymedium">'.$langs->trans("DebitDelayDaysUnit").'</span>';
		print '</td>';
		print '</tr>';

		print '</table>';

		print dol_get_fiche_end();

		print '<div class="center">';
		print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
		print ' &nbsp; <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans("Cancel").'</a>';
		print '</div>';

		print '</form>';
		print '<br>';
	} else {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	}
}


// --- List query ---

$sql = "SELECT ct.rowid, ct.code, ct.label, ct.unit, ct.auto_debit, ct.debit_delay_days, ct.active, ct.precision_unit, ct.date_creation, ct.tms";
$sql .= " FROM ".$db->prefix()."credits_types as ct";
$sql .= " WHERE ct.entity IN (".getEntity('credits_type').")";

if (!empty($search_code)) {
	$sql .= natural_search('ct.code', $search_code);
}
if (!empty($search_label)) {
	$sql .= natural_search('ct.label', $search_label);
}
if ($search_active !== '' && $search_active != '-1') {
	$sql .= " AND ct.active = ".((int) $search_active);
}

$sqlcount = preg_replace('/^SELECT[\s\S]*FROM/Uis', 'SELECT COUNT(*) as total FROM', $sql);
$resqlcount = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resqlcount) {
	$objcount = $db->fetch_object($resqlcount);
	$nbtotalofrecords = (int) $objcount->total;
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
$num = 0;
if ($resql) {
	$num = $db->num_rows($resql);
}

$newcardbutton = '';
if ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin')) {
	$newcardbutton .= dolGetButtonTitle($langs->trans('NewCreditType'), '', 'fa fa-plus-circle', $_SERVER['PHP_SELF'].'?action=create&token='.newToken(), '', ($action != 'create' && $action != 'edit'));
}
$newcardbutton .= ' '.dolGetButtonTitle($langs->trans('ExportCSV'), '', 'fa fa-download', $_SERVER['PHP_SELF'].'?action=export_csv&token='.newToken(), '', ($action != 'create' && $action != 'edit'));

$massactionbutton = '';
if ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin')) {
	$arrayofmassactions = array(
		'delete' => img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete"),
	);
	$massactionbutton = $form->selectMassAction('', $arrayofmassactions);
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'" name="formlist">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="limit" value="'.$limit.'">';

print_barre_liste(
	$langs->trans("CreditTypesList"),
	$page,
	$_SERVER['PHP_SELF'],
	$param,
	$sortfield,
	$sortorder,
	$massactionbutton,
	$num,
	$nbtotalofrecords,
	'object_creditmanager@creditmanager',
	0,
	$newcardbutton,
	'',
	$limit,
	0,
	0,
	1
);

$arrayfields = array(
	'ct.code' => array('label' => "Code", 'checked' => 1, 'position' => 10),
	'ct.label' => array('label' => "Label", 'checked' => 1, 'position' => 20),
	'ct.unit' => array('label' => "Unit", 'checked' => 1, 'position' => 30),
	'ct.debit_mode' => array('label' => "DebitMode", 'checked' => 1, 'position' => 40),
	'ct.debit_delay_days' => array('label' => "DebitDelayDays", 'checked' => 1, 'position' => 50),
	'ct.active' => array('label' => "Status", 'checked' => 1, 'position' => 60),
	'ct.date_creation' => array('label' => "DateCreation", 'checked' => 0, 'position' => 70),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste'.($num > 0 ? '' : ' listempty').'">';

// Filter row
print '<tr class="liste_titre_filter">';

if ($massactionbutton) {
	print '<td class="liste_titre center maxwidthsearch">';
	$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, '');
	print $selectedfields;
	print '</td>';
}

print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_code" value="'.dol_escape_htmltag($search_code).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth200" name="search_label" value="'.dol_escape_htmltag($search_label).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';

print '<td class="liste_titre center">';
print '<select class="flat" name="search_active">';
print '<option value="-1"'.($search_active === '' || $search_active === '-1' ? ' selected' : '').'>&nbsp;</option>';
print '<option value="1"'.($search_active === '1' ? ' selected' : '').'>'.$langs->trans("Enabled").'</option>';
print '<option value="0"'.($search_active === '0' ? ' selected' : '').'>'.$langs->trans("Disabled").'</option>';
print '</select>';
print '</td>';

print '<td class="liste_titre center maxwidthsearch">';
$searchpicto = $form->showFilterAndCheckAddButtons(0);
print $searchpicto;
print '</td>';

print '</tr>';

// Column titles
print '<tr class="liste_titre">';

if ($massactionbutton) {
	print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', 'class="center"', $sortfield, $sortorder, 'maxwidthsearch ');
}

print_liste_field_titre("Code", $_SERVER['PHP_SELF'], "ct.code", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("Label", $_SERVER['PHP_SELF'], "ct.label", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("Unit", $_SERVER['PHP_SELF'], "ct.unit", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("DebitMode", $_SERVER['PHP_SELF'], "ct.auto_debit", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("DebitDelayDays", $_SERVER['PHP_SELF'], "ct.debit_delay_days", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER['PHP_SELF'], "ct.active", '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER['PHP_SELF'], '', '', $param, 'class="center"', $sortfield, $sortorder, 'maxwidthsearch ');

print '</tr>';

// Data rows
if ($resql) {
	$i = 0;
	while ($i < min($num, $limit)) {
		$obj = $db->fetch_object($resql);
		if (!$obj) {
			break;
		}

		print '<tr class="oddeven">';

		if ($massactionbutton) {
			print '<td class="nowrap center">';
			print '<input id="cb'.$obj->rowid.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$obj->rowid.'"';
			if (is_array($toselect) && in_array($obj->rowid, $toselect)) {
				print ' checked="checked"';
			}
			print '>';
			print '</td>';
		}

		print '<td class="tdoverflowmax150"><strong>'.dol_escape_htmltag($obj->code).'</strong></td>';
		print '<td class="tdoverflowmax250">'.dol_escape_htmltag($obj->label).'</td>';
		print '<td>'.dol_escape_htmltag($obj->unit ? $langs->trans(ucfirst($obj->unit).'s') : $obj->unit).'</td>';

		// Debit mode badge: auto_debit=1 → immediate, delay>0 → delayed, else → manual
		print '<td class="nowraponall">';
		if ($obj->auto_debit) {
			print '<span class="badge badge-status4 badge-status">'.$langs->trans("ImmediateDebit").'</span>';
		} elseif ($obj->debit_delay_days > 0) {
			print '<span class="badge badge-status1 badge-status">'.$langs->trans("DelayedDebit", $obj->debit_delay_days).'</span>';
		} else {
			print '<span class="badge badge-status0 badge-status">'.$langs->trans("ManualDebit").'</span>';
		}
		print '</td>';

		print '<td>';
		if ($obj->debit_delay_days > 0) {
			print (int) $obj->debit_delay_days.' '.$langs->trans("DaysShort");
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';

		// Active toggle (switch_on / switch_off)
		print '<td class="center nowraponall">';
		if ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin')) {
			if ($obj->active) {
				print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=disable&rowid='.$obj->rowid.'&token='.newToken().$param.'">';
				print img_picto($langs->trans("Activated"), 'switch_on', 'class="size15x"');
				print '</a>';
			} else {
				print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=activate&rowid='.$obj->rowid.'&token='.newToken().$param.'">';
				print img_picto($langs->trans("Disabled"), 'switch_off', 'class="size15x"');
				print '</a>';
			}
		} else {
			if ($obj->active) {
				print img_picto($langs->trans("Activated"), 'switch_on', 'class="size15x"');
			} else {
				print img_picto($langs->trans("Disabled"), 'switch_off', 'class="size15x"');
			}
		}
		print '</td>';

		print '<td class="center nowraponall">';
		if ($user->admin || $user->hasRight('creditmanager', 'creditmanager_admin')) {
			print '<a class="reposition editfielda paddingleft paddingright" href="'.$_SERVER['PHP_SELF'].'?action=edit&rowid='.$obj->rowid.'&token='.newToken().'">';
			print img_edit();
			print '</a>';
			print '<a class="reposition paddingleft paddingright" href="'.$_SERVER['PHP_SELF'].'?action=delete&rowid='.$obj->rowid.'&token='.newToken().'">';
			print img_delete();
			print '</a>';
		}
		print '</td>';

		print '</tr>';
		$i++;
	}

	$db->free($resql);
} else {
	dol_syslog("SQL Error: ".$db->lasterror(), LOG_ERR);
	setEventMessages($db->lasterror(), null, 'errors');
}

if ($num == 0) {
	$colspan = 7;
	if ($massactionbutton) {
		$colspan++;
	}
	print '<tr class="oddeven"><td colspan="'.$colspan.'">';
	print '<span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span>';
	print '</td></tr>';
}

print '</table>';
print '</div>';

print '</form>';


// JS: sync debit_mode select → auto_debit hidden + delay row visibility
print '<script type="text/javascript">
$(document).ready(function() {
	function updateDebitMode() {
		var mode = $("#debit_mode_select").val();
		if (!mode) return;

		if (mode === "auto") {
			$("#auto_debit_hidden").val("1");
			$("#debit_delay_row").hide();
			$("#debit_delay_days_input").val("");
		} else if (mode === "delayed") {
			$("#auto_debit_hidden").val("0");
			$("#debit_delay_row").show();
			if (!$("#debit_delay_days_input").val()) {
				$("#debit_delay_days_input").val("30");
			}
		} else {
			$("#auto_debit_hidden").val("0");
			$("#debit_delay_row").hide();
			$("#debit_delay_days_input").val("");
		}
	}

	updateDebitMode();

	$("#debit_mode_select").on("change", function() {
		updateDebitMode();
	});
});
</script>';


print dol_get_fiche_end();

llxFooter();
$db->close();
