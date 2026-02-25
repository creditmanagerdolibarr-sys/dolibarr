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
 *  \file       htdocs/custom/creditmanager/admin/attribution.php
 *  \ingroup    creditmanager
 *  \brief      Credit attribution management (CRUD + batch + import/export)
 *
 *  This page is limited to the scope of commit:
 *  "Add credit attribution management with CRUD and batch operations".
 */

// Load Dolibarr environment
// File is in htdocs/custom/creditmanager/admin/, main.inc.php is in htdocs/
require '../../../main.inc.php';

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
dol_include_once('/creditmanager/class/CreditType.class.php');
dol_include_once('/creditmanager/class/CreditBalance.class.php');
dol_include_once('/creditmanager/class/CreditMovement.class.php');
dol_include_once('/creditmanager/lib/creditmanager.lib.php');

global $db, $conf, $langs, $user;

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var Translate $langs */
/** @var User $user */

// Load translation files
$langs->loadLangs(array('admin', 'errors', 'companies', 'creditmanager@creditmanager'));

// Security check: restrict to users with write rights on creditmanager
if (empty($user->rights->creditmanager->write)) {
    accessforbidden();
}

// Parameters
$action           = GETPOST('action', 'aZ09');
$confirm          = GETPOST('confirm', 'alpha');
$token            = newToken();

$attrid           = GETPOST('attrid', 'int');          // Attribution id = movement.id of the base attribution
$socid            = GETPOST('socid', 'int');           // For create form
$credit_type_id   = GETPOST('credit_type_id', 'int');
$amount           = price2num(GETPOST('amount', 'alphanohtml'), 'MT');
$description      = GETPOST('description', 'restricthtml');
$projectid        = GETPOST('projectid', 'int');

// Filters
$search_socid       = GETPOST('search_socid', 'int');
$search_credit_type = GETPOST('search_credit_type', 'int');
$search_user        = GETPOST('search_user', 'int');
$search_date_start  = dol_mktime(0, 0, 0, GETPOSTINT('search_month_start'), GETPOSTINT('search_day_start'), GETPOSTINT('search_year_start'));
$search_date_end    = dol_mktime(23, 59, 59, GETPOSTINT('search_month_end'), GETPOSTINT('search_day_end'), GETPOSTINT('search_year_end'));

$sortfield = GETPOST('sortfield', 'alpha');
$sortorder = GETPOST('sortorder', 'alpha');
$page      = GETPOSTISSET('page') ? GETPOSTINT('page') : 0;
if ($page < 0) {
    $page = 0;
}
$limit = $conf->liste_limit;
$offset = $limit * $page;

if (empty($sortfield)) {
    $sortfield = 'm.date_movement';
}
if (empty($sortorder)) {
    $sortorder = 'DESC';
}

$massaction = GETPOST('massaction', 'alpha'); // Used for batch attribution (apply to selected)

// Constants for movement type values used by this page
define('CREDITMVT_TYPE_ATTRIBUTION', 'ATTRIBUTION');
define('CREDITMVT_TYPE_ATTRIBUTION_ADJUST', 'ATTRIBUTION_ADJUSTMENT');
define('CREDITMVT_TYPE_ATTRIBUTION_CANCEL', 'ATTRIBUTION_CANCEL');


/*
 * Actions
 */

$error = 0;

// Add single attribution
if ($action === 'add' && $user->rights->creditmanager->write) {
    if (!GETPOST('cancel', 'alpha')) {
        $allowNegative = getDolGlobalInt('CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION', 0);
        $maxAmount = (float) getDolGlobalString('CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', '0');

        if ($socid <= 0 || $credit_type_id <= 0 || (!$allowNegative && $amount <= 0)) {
            setEventMessages($langs->trans('ErrorBadParameters'), null, 'errors');
            $error++;
        }
        if (!$error && $maxAmount > 0 && abs($amount) > $maxAmount) {
            setEventMessages($langs->trans('ErrorAmountExceedsMax', $maxAmount), null, 'errors');
            $error++;
        }

        if (!$error) {
            $movement = new CreditMovement($db);
            $movId = $movement->createAttribution($socid, $credit_type_id, $amount, $description, $user);
            if ($movId < 0) {
                setEventMessages($movement->error ? $movement->error : $langs->trans('Error'), null, 'errors');
            } else {
                setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
            }
        }
    }
    $action = ''; // back to list
}

// Edit attribution (amount/description only)
if ($action === 'update' && $user->rights->creditmanager->write && $attrid > 0) {
    if (!GETPOST('cancel', 'alpha')) {
        $db->begin();

        // Fetch original attribution movement
        $sql = 'SELECT rowid, fk_soc, fk_credit_type, amount, description';
        $sql .= ' FROM ' . $db->prefix() . 'credits_movements';
        $sql .= " WHERE rowid = " . (int) $attrid;
        $sql .= " AND type_movement = '" . $db->escape(CREDITMVT_TYPE_ATTRIBUTION) . "'";

        $res = $db->query($sql);
        if (!$res) {
            $error++;
            setEventMessages($db->lasterror(), null, 'errors');
        } else {
            $obj = $db->fetch_object($res);
            $db->free($res);

            if (!$obj) {
                $error++;
                setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
            } else {
                $oldAmount = (float) $obj->amount;
                $oldDesc = $obj->description;
                $socidAttr = (int) $obj->fk_soc;
                $typeAttr = (int) $obj->fk_credit_type;

                $newAmount = (float) $amount;
                $newDesc = $description !== '' ? $description : $oldDesc;

                // Diff for balance and adjustment
                $diff = $newAmount - $oldAmount;

                // Update base movement (amount + description)
                $sqlUpd = 'UPDATE ' . $db->prefix() . 'credits_movements';
                $sqlUpd .= ' SET amount = ' . price2num($newAmount, 'MT');
                $sqlUpd .= ", description = '" . $db->escape($newDesc) . "'";
                $sqlUpd .= ' WHERE rowid = ' . (int) $attrid;

                if (!$db->query($sqlUpd)) {
                    $error++;
                    setEventMessages($db->lasterror(), null, 'errors');
                }

                // If amount changed, log adjustment movement + update balance
                if (!$error && abs($diff) > 0) {
                    $balance = new CreditBalance($db);
                    $result = $balance->updateBalance($socidAttr, $typeAttr, $diff, $user);
                    if ($result < 0) {
                        $error++;
                    }
                    if (!$error) {
                        $labelAdj = $langs->transnoentitiesnoconv('CreditManagerAttributionAdjustment');
                        if ($labelAdj === 'CreditManagerAttributionAdjustment') {
                            $labelAdj = 'Attribution adjustment';
                        }
                        $movement = new CreditMovement($db);
                        $movement->fk_soc = $socidAttr;
                        $movement->fk_credit_type = $typeAttr;
                        $movement->amount = $diff;
                        $movement->type_movement = CREDITMVT_TYPE_ATTRIBUTION_ADJUST;
                        $movement->description = $labelAdj;
                        $movement->fk_attribution = $attrid;
                        $movAdjId = $movement->create($user);
                        if ($movAdjId < 0) {
                            $error++;
                        }
                    }
                }
            }
        }

        if ($error) {
            $db->rollback();
        } else {
            $db->commit();
            setEventMessages($langs->trans('RecordModified'), null, 'mesgs');
        }
    }
    $action = '';
}

// Cancel attribution (business delete)
if ($action === 'confirm_delete' && $confirm === 'yes' && $user->rights->creditmanager->write && $attrid > 0) {
    $db->begin();

    $sql = 'SELECT rowid, fk_soc, fk_credit_type, amount';
    $sql .= ' FROM ' . $db->prefix() . 'credits_movements';
    $sql .= " WHERE rowid = " . (int) $attrid;
    $sql .= " AND type_movement = '" . $db->escape(CREDITMVT_TYPE_ATTRIBUTION) . "'";

    $res = $db->query($sql);
    if (!$res) {
        $error++;
        setEventMessages($db->lasterror(), null, 'errors');
    } else {
        $obj = $db->fetch_object($res);
        $db->free($res);

        if (!$obj) {
            $error++;
            setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
        } else {
            $socidAttr = (int) $obj->fk_soc;
            $typeAttr = (int) $obj->fk_credit_type;
            $baseAmount = (float) $obj->amount;

            $balance = new CreditBalance($db);
            $result = $balance->updateBalance($socidAttr, $typeAttr, -$baseAmount, $user);
            if ($result < 0) {
                $error++;
            }
            if (!$error) {
                $labelCancel = $langs->transnoentitiesnoconv('CreditManagerAttributionCancel');
                if ($labelCancel === 'CreditManagerAttributionCancel') {
                    $labelCancel = 'Attribution cancellation';
                }
                $movement = new CreditMovement($db);
                $movement->fk_soc = $socidAttr;
                $movement->fk_credit_type = $typeAttr;
                $movement->amount = -$baseAmount;
                $movement->type_movement = CREDITMVT_TYPE_ATTRIBUTION_CANCEL;
                $movement->description = $labelCancel;
                $movement->fk_attribution = $attrid;
                $movCancelId = $movement->create($user);
                if ($movCancelId < 0) {
                    $error++;
                }
            }
        }
    }

    if ($error) {
        $db->rollback();
    } else {
        $db->commit();
        setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
    }

    $action = '';
}

// Batch attribution (same type + amount for multiple clients)
if ($action === 'addbatch' && $user->rights->creditmanager->write) {
    $toselect = GETPOST('toselect', 'array:int'); // array of socid
    if (!is_array($toselect)) {
        $toselect = array();
    }

    $allowNegative = getDolGlobalInt('CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION', 0);
    $maxAmount = (float) getDolGlobalString('CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', '0');

    if (empty($toselect) || $credit_type_id <= 0 || (!$allowNegative && $amount <= 0)) {
        setEventMessages($langs->trans('ErrorBadParameters'), null, 'errors');
    } else {
        $nbok = 0;
        foreach ($toselect as $socidBatch) {
            $socidBatch = (int) $socidBatch;
            if ($socidBatch <= 0) {
                continue;
            }

            $movement = new CreditMovement($db);
            $movId = $movement->createAttribution($socidBatch, $credit_type_id, $amount, $description, $user);
            if ($movId < 0) {
                $error++;
                break;
            }
            $nbok++;
        }

        if ($error) {
            setEventMessages($langs->trans('Error'), null, 'errors');
        } else {
            setEventMessages($langs->trans('BatchOperationCompleted', $nbok), null, 'mesgs');
        }
    }
    $action = '';
}

// Import CSV (simple implementation: socid;credit_type_code;amount;description)
if ($action === 'importcsv' && $user->rights->creditmanager->write) {
    if (!empty($_FILES['importfile']['tmp_name'])) {
        $importFile = $_FILES['importfile']['tmp_name'];
        $handle = fopen($importFile, 'r');
        if ($handle) {
            $lineNum = 0;
            $nbok = 0;

            $csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
            while (($row = fgetcsv($handle, 0, $csvSep)) !== false) {
                $lineNum++;
                if (count($row) < 3) {
                    continue;
                }
                $socidCsv = (int) trim($row[0]);
                $codeCsv = trim($row[1]);
                $amountCsv = price2num(trim($row[2]), 'MT');
                $descCsv = isset($row[3]) ? trim($row[3]) : '';

                $allowNegativeCsv = getDolGlobalInt('CREDITMANAGER_ALLOW_NEGATIVE_ATTRIBUTION', 0);
                $maxAmountCsv = (float) getDolGlobalString('CREDITMANAGER_MAX_ATTRIBUTION_AMOUNT', '0');
                if ($socidCsv <= 0 || $codeCsv === '' || (!$allowNegativeCsv && $amountCsv <= 0)) {
                    continue;
                }
                if ($maxAmountCsv > 0 && abs($amountCsv) > $maxAmountCsv) {
                    continue;
                }

                $creditType = new CreditType($db);
                if ($creditType->fetch(0, $codeCsv) <= 0 || $creditType->id <= 0) {
                    continue;
                }

                $movement = new CreditMovement($db);
                $movId = $movement->createAttribution($socidCsv, $creditType->id, $amountCsv, $descCsv, $user);
                if ($movId < 0) {
                    $error++;
                    break;
                }
                $nbok++;
            }

            fclose($handle);

            if ($error) {
                setEventMessages($langs->trans('Error'), null, 'errors');
            } else {
                setEventMessages($langs->trans('ImportCompleted', $nbok), null, 'mesgs');
            }
        } else {
            setEventMessages($langs->trans('ErrorFailedToOpenFile'), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans('ErrorFileNotFound'), null, 'errors');
    }
    $action = '';
}

// Export CSV of attribution history (with current filters)
if ($action === 'exportcsv' && $user->rights->creditmanager->read) {
    $filename = 'credit_attributions_' . dol_print_date(dol_now(), '%Y%m%d%H%M%S') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }

    // Header
    $csvSep = getDolGlobalString('CREDITMANAGER_CSV_SEPARATOR', ';');
    fputcsv($out, array('id', 'date', 'client_id', 'client_name', 'credit_type_code', 'credit_type_label', 'amount', 'user_login', 'description'), $csvSep);

    $sql = 'SELECT m.rowid, m.date_movement, m.amount, m.description,';
    $sql .= ' s.rowid as socid, s.nom as client_name,';
    $sql .= ' t.code as type_code, t.label as type_label,';
    $sql .= ' u.login';
    $sql .= ' FROM ' . $db->prefix() . 'credits_movements as m';
    $sql .= ' INNER JOIN ' . $db->prefix() . 'societe as s ON s.rowid = m.fk_soc';
    $sql .= ' INNER JOIN ' . $db->prefix() . 'credits_types as t ON t.rowid = m.fk_credit_type';
    $sql .= ' LEFT JOIN ' . $db->prefix() . 'user as u ON u.rowid = m.fk_user_creat';
    $sql .= " WHERE m.type_movement = '" . $db->escape(CREDITMVT_TYPE_ATTRIBUTION) . "'";
    $sql .= ' AND m.entity = ' . ((int) $conf->entity);

    if ($search_socid > 0) {
        $sql .= ' AND m.fk_soc = ' . ((int) $search_socid);
    }
    if ($search_credit_type > 0) {
        $sql .= ' AND m.fk_credit_type = ' . ((int) $search_credit_type);
    }
    if ($search_user > 0) {
        $sql .= ' AND m.fk_user_creat = ' . ((int) $search_user);
    }
    if (!empty($search_date_start)) {
        $sql .= " AND m.date_movement >= '" . $db->idate($search_date_start) . "'";
    }
    if (!empty($search_date_end)) {
        $sql .= " AND m.date_movement <= '" . $db->idate($search_date_end) . "'";
    }

    $sql .= ' ORDER BY ' . $db->escape($sortfield) . ' ' . $db->escape($sortorder);

    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            fputcsv(
                $out,
                array(
                    $obj->rowid,
                    $db->jdate($obj->date_movement) ? dol_print_date($db->jdate($obj->date_movement), 'dayhour') : '',
                    $obj->socid,
                    $obj->client_name,
                    $obj->type_code,
                    $obj->type_label,
                    price($obj->amount, 0, '', 1, -1, -1, $conf->currency),
                    $obj->login,
                    $obj->description,
                ),
                $csvSep
            );
        }
        $db->free($resql);
    }

    fclose($out);
    exit;
}


/*
 * View
 */

$form = new Form($db);
$formcompany = new Form($db); // reuse

llxHeader('', $langs->trans('CreditManagerAttribution'), '', '', 0, 0, '', '', '', 'mod-creditmanager page-creditmanager-attribution');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans('CreditManagerAttribution'), $linkback, 'title_setup');

$head = creditmanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'attribution', $langs->trans('CreditManager'), -1, 'creditmanager@creditmanager');

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// Attribution form (single)
print load_fiche_titre($langs->trans('CreditManagerNewAttribution'), '', '');

print '<form action="' . $_SERVER['PHP_SELF'] . '" method="POST">';
print '<input type="hidden" name="token" value="' . $token . '">';
print '<input type="hidden" name="action" value="add">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Customer') . '</td>';
print '<td>' . $langs->trans('CreditType') . '</td>';
print '<td>' . $langs->trans('Amount') . '</td>';
print '<td>' . $langs->trans('Description') . '</td>';
print '<td class="center">' . $langs->trans('Action') . '</td>';
print '</tr>';

print '<tr class="oddeven">';
// Customer
print '<td>';
print $form->select_company($socid, 'socid', '', 1, 0, 0, array(), 0, 'minwidth300');
print '</td>';

// Credit type
print '<td>';
$sqlTypes = 'SELECT rowid, code, label FROM ' . $db->prefix() . 'credits_types';
$sqlTypes .= ' WHERE entity = ' . ((int) $conf->entity) . ' AND active = 1';
$sqlTypes .= ' ORDER BY code';
$resTypes = $db->query($sqlTypes);
print '<select name="credit_type_id" class="flat minwidth200">';
print '<option value="0">&nbsp;</option>';
if ($resTypes) {
    while ($objT = $db->fetch_object($resTypes)) {
        $selected = ($credit_type_id > 0 && (int) $objT->rowid === $credit_type_id) ? ' selected' : '';
        print '<option value="' . (int) $objT->rowid . '"' . $selected . '>' . dol_escape_htmltag($objT->code . ' - ' . $objT->label) . '</option>';
    }
    $db->free($resTypes);
}
print '</select>';
print '</td>';

// Amount
print '<td>';
print '<input type="text" name="amount" class="flat maxwidth75" value="' . dol_escape_htmltag(GETPOST('amount', 'alphanohtml')) . '">';
print '</td>';

// Description
print '<td>';
print '<input type="text" name="description" class="flat minwidth200" value="' . dol_escape_htmltag(GETPOST('description', 'restricthtml')) . '">';
print '</td>';

// Submit
print '<td class="center">';
print '<input type="submit" class="button" value="' . $langs->trans('Add') . '">';
print '</td>';

print '</tr>';
print '</table>';
print '</form>';

print '<br>';

// Batch attribution form (select multiple customers)
print load_fiche_titre($langs->trans('CreditManagerBatchAttribution'), '', '');

print '<form action="' . $_SERVER['PHP_SELF'] . '" method="POST">';
print '<input type="hidden" name="token" value="' . $token . '">';
print '<input type="hidden" name="action" value="addbatch">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Customers') . '</td>';
print '<td>' . $langs->trans('CreditType') . '</td>';
print '<td>' . $langs->trans('Amount') . '</td>';
print '<td>' . $langs->trans('Description') . '</td>';
print '<td class="center">' . $langs->trans('Action') . '</td>';
print '</tr>';

print '<tr class="oddeven">';

// Multi-select customers (simple select; for large bases, to be improved later)
print '<td>';
$sqlSoc = 'SELECT rowid, nom FROM ' . $db->prefix() . 'societe';
$sqlSoc .= ' WHERE entity IN (' . getEntity('societe') . ')';
$sqlSoc .= ' ORDER BY nom';
$resSoc = $db->query($sqlSoc);
print '<select name="toselect[]" class="flat minwidth300" multiple size="5">';
if ($resSoc) {
    while ($objS = $db->fetch_object($resSoc)) {
        print '<option value="' . (int) $objS->rowid . '">' . dol_escape_htmltag($objS->nom) . '</option>';
    }
    $db->free($resSoc);
}
print '</select>';
print '</td>';

// Credit type (reuse code above, but no preselect)
print '<td>';
$resTypes2 = $db->query($sqlTypes);
print '<select name="credit_type_id" class="flat minwidth200">';
print '<option value="0">&nbsp;</option>';
if ($resTypes2) {
    while ($objT = $db->fetch_object($resTypes2)) {
        print '<option value="' . (int) $objT->rowid . '">' . dol_escape_htmltag($objT->code . ' - ' . $objT->label) . '</option>';
    }
    $db->free($resTypes2);
}
print '</select>';
print '</td>';

print '<td>';
print '<input type="text" name="amount" class="flat maxwidth75">';
print '</td>';

print '<td>';
print '<input type="text" name="description" class="flat minwidth200">';
print '</td>';

print '<td class="center">';
print '<input type="submit" class="button" value="' . $langs->trans('Add') . '">';
print '</td>';

print '</tr>';
print '</table>';
print '</form>';

print '</div>'; // fichethirdleft


// Right panel: balances for selected client (if any)
print '<div class="fichethirdright">';

print load_fiche_titre($langs->trans('CreditManagerClientBalances'), '', '');

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . $token . '">';

print $langs->trans('Customer') . ' ';
print $form->select_company($search_socid, 'search_socid', '', 1, 0, 0, array(), 0, 'maxwidth300');
print ' <input type="submit" class="button small" value="' . $langs->trans('Refresh') . '">';
print '</form>';

if ($search_socid > 0) {
    $sqlBal = 'SELECT b.rowid, b.fk_credit_type, b.balance, t.code, t.label';
    $sqlBal .= ' FROM ' . $db->prefix() . 'credits_balance as b';
    $sqlBal .= ' INNER JOIN ' . $db->prefix() . 'credits_types as t ON t.rowid = b.fk_credit_type';
    $sqlBal .= ' WHERE b.fk_soc = ' . (int) $search_socid;
    $sqlBal .= ' AND b.entity = ' . ((int) $conf->entity);
    $sqlBal .= ' ORDER BY t.code';

    $resBal = $db->query($sqlBal);

    print '<br>';
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>' . $langs->trans('CreditType') . '</td>';
    print '<td class="right">' . $langs->trans('Balance') . '</td>';
    print '</tr>';

    if ($resBal) {
        $numBal = $db->num_rows($resBal);
        if ($numBal > 0) {
            while ($objB = $db->fetch_object($resBal)) {
                print '<tr class="oddeven">';
                print '<td>' . dol_escape_htmltag($objB->code . ' - ' . $objB->label) . '</td>';
                print '<td class="right">' . price($objB->balance, 0, '', 1, -1, -1, 'h') . '</td>';
                print '</tr>';
            }
        } else {
            print '<tr class="oddeven"><td colspan="2" class="center opacitymedium">' . $langs->trans('None') . '</td></tr>';
        }
        $db->free($resBal);
    } else {
        print '<tr class="oddeven"><td colspan="2" class="center">' . $db->lasterror() . '</td></tr>';
    }

    print '</table>';
    print '</div>';
}

print '</div>'; // fichethirdright
print '</div>'; // fichecenter

print '<div class="clearboth"></div>';

// Attribution list + filters + import/export

// Import form
print load_fiche_titre($langs->trans('CreditManagerImportAttributionsCSV'), '', '');
print '<form action="' . $_SERVER['PHP_SELF'] . '" method="POST" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . $token . '">';
print '<input type="hidden" name="action" value="importcsv">';
print $langs->trans('File') . ' ';
print '<input type="file" name="importfile" class="flat">';
print ' <input type="submit" class="button small" value="' . $langs->trans('Import') . '">';
print '</form>';

print '<br>';

// Filters + export button
print load_fiche_titre($langs->trans('CreditManagerAttributionHistory'), '', '');

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . $token . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans('Customer') . '</td>';
print '<td>' . $langs->trans('CreditType') . '</td>';
print '<td>' . $langs->trans('User') . '</td>';
print '<td>' . $langs->trans('From') . '</td>';
print '<td>' . $langs->trans('To') . '</td>';
print '<td class="right">' . $langs->trans('Action') . '</td>';
print '</tr>';

print '<tr class="oddeven">';

// Filter customer
print '<td>';
print $form->select_company($search_socid, 'search_socid', '', 1, 0, 0, array(), 0, 'maxwidth200');
print '</td>';

// Filter credit type
print '<td>';
$resTypesF = $db->query($sqlTypes);
print '<select name="search_credit_type" class="flat maxwidth200">';
print '<option value="0">&nbsp;</option>';
if ($resTypesF) {
    while ($objT = $db->fetch_object($resTypesF)) {
        $sel = ($search_credit_type > 0 && (int) $objT->rowid === $search_credit_type) ? ' selected' : '';
        print '<option value="' . (int) $objT->rowid . '"' . $sel . '>' . dol_escape_htmltag($objT->code . ' - ' . $objT->label) . '</option>';
    }
    $db->free($resTypesF);
}
print '</select>';
print '</td>';

// Filter user
print '<td>';
print $form->select_dolusers($search_user, 'search_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth200');
print '</td>';

// Date from / to
print '<td>';
print $form->selectdate($search_date_start, 'search_', 0, 0, 1, '', 1, 0, 0, '', '', '', '');
print '</td>';

print '<td>';
print $form->selectdate($search_date_end, 'search_end_', 0, 0, 1, '', 1, 0, 0, '', '', '', '');
print '</td>';

// Buttons
print '<td class="right">';
print '<input type="submit" class="button small" value="' . $langs->trans('Search') . '"> ';
print '<a class="button small" href="' . $_SERVER['PHP_SELF'] . '?token=' . $token . '">' . $langs->trans('Reset') . '</a> ';
print '<a class="button small" href="' . $_SERVER['PHP_SELF'] . '?action=exportcsv&amp;token=' . $token . '">' . $langs->trans('Export') . '</a>';
print '</td>';

print '</tr>';
print '</table>';
print '</form>';

print '<br>';

// List of attributions
$sql = 'SELECT m.rowid, m.date_movement, m.amount, m.description,';
$sql .= ' s.rowid as socid, s.nom as client_name,';
$sql .= ' t.code as type_code, t.label as type_label,';
$sql .= ' u.login';
$sql .= ' FROM ' . $db->prefix() . 'credits_movements as m';
$sql .= ' INNER JOIN ' . $db->prefix() . 'societe as s ON s.rowid = m.fk_soc';
$sql .= ' INNER JOIN ' . $db->prefix() . 'credits_types as t ON t.rowid = m.fk_credit_type';
$sql .= ' LEFT JOIN ' . $db->prefix() . 'user as u ON u.rowid = m.fk_user_creat';
$sql .= " WHERE m.type_movement = '" . $db->escape(CREDITMVT_TYPE_ATTRIBUTION) . "'";
$sql .= ' AND m.entity = ' . ((int) $conf->entity);

if ($search_socid > 0) {
    $sql .= ' AND m.fk_soc = ' . ((int) $search_socid);
}
if ($search_credit_type > 0) {
    $sql .= ' AND m.fk_credit_type = ' . ((int) $search_credit_type);
}
if ($search_user > 0) {
    $sql .= ' AND m.fk_user_creat = ' . ((int) $search_user);
}
if (!empty($search_date_start)) {
    $sql .= " AND m.date_movement >= '" . $db->idate($search_date_start) . "'";
}
if (!empty($search_date_end)) {
    $sql .= " AND m.date_movement <= '" . $db->idate($search_date_end) . "'";
}

$sql .= ' ORDER BY ' . $db->escape($sortfield) . ' ' . $db->escape($sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);

if (!$resql) {
    dol_print_error($db);
} else {
    $num = $db->num_rows($resql);

    $param = '';
    if ($search_socid > 0) {
        $param .= '&search_socid=' . urlencode($search_socid);
    }
    if ($search_credit_type > 0) {
        $param .= '&search_credit_type=' . urlencode($search_credit_type);
    }
    if ($search_user > 0) {
        $param .= '&search_user=' . urlencode($search_user);
    }

    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';

    print '<tr class="liste_titre">';
    print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'm.rowid', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('Date'), $_SERVER['PHP_SELF'], 'm.date_movement', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('Customer'), $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('CreditType'), $_SERVER['PHP_SELF'], 't.code', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('Amount'), $_SERVER['PHP_SELF'], 'm.amount', '', $param, 'class="right"', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('User'), $_SERVER['PHP_SELF'], 'u.login', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre($langs->trans('Description'), $_SERVER['PHP_SELF'], 'm.description', '', $param, '', $sortfield, $sortorder);
    print '<th class="center">' . $langs->trans('Action') . '</th>';
    print '</tr>';

    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (!$obj) {
            break;
        }

        print '<tr class="oddeven">';

        print '<td>' . (int) $obj->rowid . '</td>';
        print '<td>' . dol_print_date($db->jdate($obj->date_movement), 'dayhour') . '</td>';

        // Client
        $thirdpartyUrl = DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $obj->socid;
        print '<td><a href="' . $thirdpartyUrl . '">' . dol_escape_htmltag($obj->client_name) . '</a></td>';

        // Credit type
        print '<td>' . dol_escape_htmltag($obj->type_code . ' - ' . $obj->type_label) . '</td>';

        // Amount
        print '<td class="right">' . price($obj->amount, 0, '', 1, -1, -1, 'h') . '</td>';

        // User
        print '<td>' . dol_escape_htmltag($obj->login) . '</td>';

        // Description
        print '<td>' . dol_escape_htmltag($obj->description) . '</td>';

        // Actions: edit + delete (cancel)
        print '<td class="center">';
        $editUrl = $_SERVER['PHP_SELF'] . '?action=edit&attrid=' . (int) $obj->rowid . '&token=' . $token;
        $delUrl = $_SERVER['PHP_SELF'] . '?action=delete&attrid=' . (int) $obj->rowid . '&token=' . $token;
        print '<a class="editfielda" href="' . $editUrl . '">' . img_edit() . '</a> ';
        print '<a class="deletefielda" href="' . $delUrl . '">' . img_delete() . '</a>';
        print '</td>';

        print '</tr>';

        $i++;
    }

    print '</table>';
    print '</div>';

    $db->free($resql);

    print '<br>';
}

// Simple modal-like workflow for edit/delete (using separate action-handling above)
if ($action === 'edit' && $attrid > 0) {
    // Fetch row for inline edit form
    $sql = 'SELECT rowid, amount, description';
    $sql .= ' FROM ' . $db->prefix() . 'credits_movements';
    $sql .= " WHERE rowid = " . (int) $attrid;
    $sql .= " AND type_movement = '" . $db->escape(CREDITMVT_TYPE_ATTRIBUTION) . "'";

    $res = $db->query($sql);
    if ($res) {
        $obj = $db->fetch_object($res);
        $db->free($res);
        if ($obj) {
            print '<div class="fichecenter">';
            print '<form action="' . $_SERVER['PHP_SELF'] . '" method="POST">';
            print '<input type="hidden" name="token" value="' . $token . '">';
            print '<input type="hidden" name="action" value="update">';
            print '<input type="hidden" name="attrid" value="' . (int) $attrid . '">';

            print load_fiche_titre($langs->trans('Modify'), '', '');

            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td>' . $langs->trans('Amount') . '</td><td>' . $langs->trans('Description') . '</td></tr>';
            print '<tr class="oddeven">';
            print '<td><input type="text" name="amount" class="flat maxwidth100" value="' . price($obj->amount, 0, '', 1, -1, -1, 'h') . '"></td>';
            print '<td><input type="text" name="description" class="flat minwidth300" value="' . dol_escape_htmltag($obj->description) . '"></td>';
            print '</tr>';
            print '</table>';

            print '<div class="center">';
            print '<input type="submit" class="button" value="' . $langs->trans('Save') . '"> ';
            print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?token=' . $token . '">' . $langs->trans('Cancel') . '</a>';
            print '</div>';

            print '</form>';
            print '</div>';
        }
    }
}

if ($action === 'delete' && $attrid > 0) {
    $formconfirm = $form->formconfirm(
        $_SERVER['PHP_SELF'] . '?attrid=' . (int) $attrid,
        $langs->trans('Delete'),
        $langs->trans('CreditManagerConfirmDeleteAttribution'),
        'confirm_delete',
        array(),
        0,
        1
    );
    print $formconfirm;
}

print dol_get_fiche_end();

llxFooter();
$db->close();

