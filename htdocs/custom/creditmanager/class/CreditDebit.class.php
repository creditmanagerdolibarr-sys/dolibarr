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
 *	\file       htdocs/custom/creditmanager/class/CreditDebit.class.php
 *	\ingroup    creditmanager
 *	\brief      Class for credit debit logic with validation
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/creditmanager/class/CreditBalance.class.php');
dol_include_once('/creditmanager/class/CreditMovement.class.php');
dol_include_once('/creditmanager/class/CreditType.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 *	Class to manage credit debit operations
 */
class CreditDebit
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error;

	/**
	 * @var array<string> Errors
	 */
	public $errors = array();

	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 *	Debit credits from client balance
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$amount			Amount to debit
	 *	@param	int		$fk_element_time	Timesheet entry ID (llx_element_time)
	 *	@param	string	$description	Description
	 *	@param	string	$timesheet_elementtype	Timesheet element type
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function debitCredits($fk_soc, $fk_credit_type, $amount, $fk_element_time = 0, $description = '', $timesheet_elementtype = '')
	{
		global $user;

		if ($amount <= 0) {
			$this->error = 'Amount must be positive';
			return -1;
		}

		if (!$this->validateDebit($fk_soc, $fk_credit_type, $amount, $fk_element_time, $timesheet_elementtype)) {
			return -2;
		}

		$this->db->begin();

		$balance = new CreditBalance($this->db);
		$result = $balance->updateBalance($fk_soc, $fk_credit_type, -$amount, $user);
		if ($result < 0) {
			$this->error = $balance->error;
			$this->db->rollback();
			return -3;
		}

		$movement = new CreditMovement($this->db);
		$movement->fk_soc = $fk_soc;
		$movement->fk_credit_type = $fk_credit_type;
		$movement->amount = -$amount;
		$movement->type_movement = 'DEBIT';
		$movement->description = $description;
		$movement->fk_timesheet = $fk_element_time > 0 ? $fk_element_time : null; // backward compatibility
		$movement->fk_element_time = $fk_element_time > 0 ? $fk_element_time : null;
		$movement->timesheet_elementtype = !empty($timesheet_elementtype) ? $timesheet_elementtype : null;
		$movement->date_movement = dol_now();

		$movementId = $movement->create($user);
		if ($movementId < 0) {
			$this->error = $movement->error;
			$this->db->rollback();
			return -4;
		}

		if ($fk_element_time > 0) {
			$reference = 'DEB-'.dol_print_date(dol_now(), '%Y%m%d').'-'.$movementId;
			if (!$this->updateTimesheetCreditFields($fk_element_time, array(
				'fk_credit_type' => (int) $fk_credit_type,
				'credit_status' => 'DEBITED',
				'credit_debit_reference' => $reference,
				'credit_debit_date' => '__NOW__',
				'credit_debit_amount' => (float) $amount,
			))) {
				$this->db->rollback();
				return -5;
			}
		}

		$this->db->commit();
		return $movementId;
	}

	/**
	 *	Debit credits from timesheet
	 *
	 *	@param	int		$fk_element_time	Timesheet entry ID (llx_element_time)
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	string	$description	Description
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function debitCreditsFromTimesheet($fk_element_time, $fk_credit_type, $description = '')
	{
		if ($fk_credit_type <= 0) {
			$this->error = 'Credit type is required';
			return -1;
		}

		$timesheet = $this->getTimesheetContext($fk_element_time);
		if ($timesheet === false) {
			$this->error = 'Timesheet not found';
			return -2;
		}

		$amount = $timesheet['duration'] / 3600;
		if ($amount <= 0) {
			$this->error = 'Timesheet duration is zero';
			return -3;
		}

		if (empty($description)) {
			$description = 'Debit timesheet '.$timesheet['ref'];
		}

		$movementId = $this->debitCredits(
			$timesheet['fk_soc'],
			$fk_credit_type,
			$amount,
			$fk_element_time,
			$description,
			$timesheet['elementtype']
		);

		return $movementId;
	}

	/**
	 *	Refund credits from timesheet
	 *
	 *	@param	int		$fk_element_time	Timesheet entry ID (llx_element_time)
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function refundCredits($fk_element_time)
	{
		global $user;

		$sql = "SELECT rowid, amount FROM ".$this->db->prefix()."credits_movements";
		$sql .= " WHERE fk_element_time = ".((int) $fk_element_time);
		$sql .= " AND type_movement = 'DEBIT'";
		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			$this->error = 'Debit movement not found';
			return -2;
		}

		$parentMovementId = (int) $obj->rowid;
		$refundAmount = abs((float) $obj->amount);

		$debitMovement = new CreditMovement($this->db);
		if ($debitMovement->fetch($parentMovementId) <= 0) {
			$this->error = 'Debit movement unavailable';
			return -3;
		}

		$this->db->begin();

		$balance = new CreditBalance($this->db);
		$result = $balance->updateBalance(
			$debitMovement->fk_soc,
			$debitMovement->fk_credit_type,
			$refundAmount,
			$user
		);
		if ($result < 0) {
			$this->error = $balance->error;
			$this->db->rollback();
			return -4;
		}

		$movement = new CreditMovement($this->db);
		$movement->fk_soc = $debitMovement->fk_soc;
		$movement->fk_credit_type = $debitMovement->fk_credit_type;
		$movement->amount = $refundAmount;
		$movement->type_movement = 'REFUND';
		$movement->description = 'Refund timesheet movement #'.$parentMovementId;
		$movement->fk_timesheet = $fk_element_time; // backward compatibility
		$movement->fk_element_time = $fk_element_time;
		$movement->timesheet_elementtype = $debitMovement->timesheet_elementtype;
		$movement->fk_parent_movement = $parentMovementId;
		$movement->date_movement = dol_now();

		$movementId = $movement->create($user);
		if ($movementId < 0) {
			$this->error = $movement->error;
			$this->db->rollback();
			return -5;
		}

		if (!$this->updateTimesheetCreditFields($fk_element_time, array(
			'fk_credit_type' => $debitMovement->fk_credit_type ? (int) $debitMovement->fk_credit_type : null,
			'credit_status' => 'APPROVED',
			'credit_debit_reference' => null,
			'credit_debit_date' => null,
			'credit_debit_amount' => null,
		))) {
			$this->db->rollback();
			return -6;
		}

		$this->db->commit();
		return $movementId;
	}

	public function saveTimesheetCreditType($fk_element_time, $fk_credit_type)
	{
		$fields = array(
			'fk_credit_type' => $fk_credit_type > 0 ? (int) $fk_credit_type : null,
			'credit_debit_reference' => null,
			'credit_debit_date' => null,
			'credit_debit_amount' => null,
			'credit_approval_date' => null,
		);

		return $this->updateTimesheetCreditFields($fk_element_time, $fields);
	}

	/**
	 * Approve a submitted timesheet and optionally auto-debit.
	 *
	 * @param int $fk_element_time
	 * @param int $fk_credit_type
	 * @return int 1 if OK, <0 if KO
	 */
	public function approveTimesheet($fk_element_time, $fk_credit_type)
	{
		global $user;

		$fk_element_time = (int) $fk_element_time;
		$fk_credit_type = (int) $fk_credit_type;
		if ($fk_element_time <= 0 || $fk_credit_type <= 0) {
			$this->error = 'Invalid parameters';
			return -1;
		}

		$sql = "SELECT rowid, credit_status FROM ".$this->db->prefix()."element_time";
		$sql .= " WHERE rowid = ".$fk_element_time;
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			$this->error = 'Timesheet not found';
			return -1;
		}
		$this->db->free($resql);

		if (strtoupper(trim((string) $obj->credit_status)) !== 'SUBMITTED') {
			$this->error = 'Timesheet is not in submitted status';
			return -1;
		}

		if (!$this->updateTimesheetCreditFields($fk_element_time, array(
			'fk_credit_type' => $fk_credit_type,
			'credit_status' => 'APPROVED',
			'credit_approval_date' => $this->db->idate(dol_now()),
		))) {
			return -1;
		}

		$creditType = new CreditType($this->db);
		if ($creditType->fetch($fk_credit_type) > 0 && !empty($creditType->auto_debit)) {
			$result = $this->debitCreditsFromTimesheet($fk_element_time, $fk_credit_type);
			if ($result < 0) {
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Reject a submitted timesheet.
	 *
	 * @param int    $fk_element_time
	 * @param string $comment
	 * @return int 1 if OK, <0 if KO
	 */
	public function rejectTimesheet($fk_element_time, $comment = '')
	{
		$fk_element_time = (int) $fk_element_time;
		if ($fk_element_time <= 0) {
			$this->error = 'Invalid parameters';
			return -1;
		}

		$sql = "SELECT rowid, credit_status, note FROM ".$this->db->prefix()."element_time";
		$sql .= " WHERE rowid = ".$fk_element_time;
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			$this->error = 'Timesheet not found';
			return -1;
		}
		$this->db->free($resql);

		if (strtoupper(trim((string) $obj->credit_status)) !== 'SUBMITTED') {
			$this->error = 'Timesheet is not in submitted status';
			return -1;
		}

		$fields = array(
			'credit_status' => 'REJECTED',
			'fk_credit_type' => null,
		);
		if ($comment !== '') {
			$note = trim((string) $obj->note);
			$fields['note'] = ($note !== '' ? $note."\n" : '').$comment;
		}

		if (!$this->updateTimesheetCreditFields($fk_element_time, $fields)) {
			return -1;
		}

		return 1;
	}

	public function hasActiveDebit($fk_element_time)
	{
		$sql  = "SELECT rowid FROM ".$this->db->prefix()."credits_movements";
		$sql .= " WHERE fk_element_time = ".((int) $fk_element_time);
		$sql .= " AND type_movement = 'DEBIT'";
		$sql .= " AND rowid NOT IN (";
		$sql .= "   SELECT fk_parent_movement FROM ".$this->db->prefix()."credits_movements";
		$sql .= "   WHERE fk_parent_movement IS NOT NULL AND type_movement = 'REFUND'";
		$sql .= " )";
		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? 1 : 0;
	}

	private function updateTimesheetCreditFields($fk_element_time, $fields)
	{
		if ($fk_element_time <= 0) {
			return true;
		}

		$sql = "UPDATE ".$this->db->prefix()."element_time SET ";
		$parts = array();
		foreach ($fields as $column => $value) {
			if ($value === '__NOW__') {
				$parts[] = $column." = NOW()";
			} elseif ($value === null) {
				$parts[] = $column." = NULL";
			} elseif (is_numeric($value) && $column !== 'credit_status' && $column !== 'credit_debit_reference') {
				$parts[] = $column." = ".((float) $value);
			} else {
				$parts[] = $column." = '".$this->db->escape((string) $value)."'";
			}
		}
		$sql .= implode(', ', $parts);
		$sql .= " WHERE rowid = ".((int) $fk_element_time);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 *	Validate debit operation
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$amount			Amount to debit
	 *	@param	int		$fk_element_time	Timesheet entry ID (llx_element_time)
	 *	@param	string	$timesheet_elementtype	Timesheet element type
	 *	@return	bool					true if valid, false otherwise
	 */
	private function validateDebit($fk_soc, $fk_credit_type, $amount, $fk_element_time = 0, $timesheet_elementtype = '')
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$societe = new Societe($this->db);
		if ($societe->fetch($fk_soc) <= 0) {
			$this->error = 'Client not found';
			return false;
		}

		if ($societe->status == 0) {
			$this->error = 'Client inactive';
			return false;
		}

		$creditType = new CreditType($this->db);
		if ($creditType->fetch($fk_credit_type) <= 0) {
			$this->error = 'Credit type not found';
			return false;
		}

		if ($creditType->active == 0) {
			$this->error = 'Credit type inactive';
			return false;
		}

		$balance = new CreditBalance($this->db);
		if (!$balance->checkSufficientBalance($fk_soc, $fk_credit_type, $amount)) {
			$this->error = 'Insufficient balance';
			return false;
		}

		if ($fk_element_time > 0) {
			$timesheet = $this->getTimesheetContext($fk_element_time);
			if ($timesheet === false) {
				$this->error = 'Timesheet not found';
				return false;
			}

			if (!empty($timesheet_elementtype) && $timesheet['elementtype'] !== $timesheet_elementtype) {
				$this->error = 'Timesheet element type mismatch';
				return false;
			}
		}

		return true;
	}

	/**
	 *	Load and normalize a timesheet context from llx_element_time.
	 *
	 *	@param	int		$fk_element_time	Timesheet entry ID
	 *	@return	array|false					Context array or false on error
	 */
	private function getTimesheetContext($fk_element_time)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/timespent.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

		$timespent = new TimeSpent($this->db);
		if ($timespent->fetch($fk_element_time) <= 0) {
			return false;
		}

		$elementtype = (string) $timespent->elementtype;
		$fk_soc = 0;
		$ref = 'TS-'.$fk_element_time;

		if ($elementtype === 'fichinter') {
			$fichinter = new Fichinter($this->db);
			if ($fichinter->fetch((int) $timespent->fk_element) <= 0) {
				return false;
			}
			$fk_soc = (int) $fichinter->socid;
			$ref = $fichinter->ref;
		} elseif ($elementtype === 'task') {
			$task = new Task($this->db);
			if ($task->fetch((int) $timespent->fk_element) <= 0) {
				return false;
			}
			if (!empty($task->socid)) {
				$fk_soc = (int) $task->socid;
			} elseif (!empty($task->fk_project)) {
				$project = new Project($this->db);
				if ($project->fetch((int) $task->fk_project) > 0) {
					$fk_soc = (int) $project->socid;
				}
			}
			$ref = !empty($task->ref) ? $task->ref : ('TASK-'.$task->id);
		} else {
			return false;
		}

		if ($fk_soc <= 0) {
			return false;
		}

		return array(
			'fk_soc' => $fk_soc,
			'duration' => (float) $timespent->element_duration,
			'elementtype' => $elementtype,
			'ref' => $ref,
		);
	}
}
