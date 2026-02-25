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
	 *	@param	int		$fk_timesheet	Timesheet ID
	 *	@param	string	$description	Description
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function debitCredits($fk_soc, $fk_credit_type, $amount, $fk_timesheet = 0, $description = '')
	{
		global $user;

		if ($amount <= 0) {
			$this->error = 'Amount must be positive';
			return -1;
		}

		if (!$this->validateDebit($fk_soc, $fk_credit_type, $amount, $fk_timesheet)) {
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
		$movement->fk_timesheet = $fk_timesheet > 0 ? $fk_timesheet : null;
		$movement->date_movement = dol_now();

		$movementId = $movement->create($user);
		if ($movementId < 0) {
			$this->error = $movement->error;
			$this->db->rollback();
			return -4;
		}

		$this->db->commit();
		return $movementId;
	}

	/**
	 *	Debit credits from timesheet
	 *
	 *	@param	int		$fk_timesheet	Timesheet ID
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function debitCreditsFromTimesheet($fk_timesheet)
	{
		require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';

		$fichinter = new Fichinter($this->db);
		$result = $fichinter->fetch($fk_timesheet);
		if ($result <= 0) {
			$this->error = 'Timesheet not found';
			return -1;
		}

		if (empty($fichinter->fk_credit_type)) {
			$this->error = 'Credit type not assigned';
			return -2;
		}

		if ($fichinter->credit_status !== 'APPROVED') {
			$this->error = 'Timesheet not approved';
			return -3;
		}

		if (!empty($fichinter->credit_debit_reference)) {
			$this->error = 'Timesheet already debited';
			return -4;
		}

		$amount = $fichinter->duree / 3600;

		$description = 'Débit timesheet '.$fichinter->ref;

		$movementId = $this->debitCredits(
			$fichinter->socid,
			$fichinter->fk_credit_type,
			$amount,
			$fk_timesheet,
			$description
		);

		if ($movementId > 0) {
			$reference = 'DEB-'.date('Ymd').'-'.$movementId;

			$sql = "UPDATE ".$this->db->prefix()."ficheinter SET";
			$sql .= " credit_status = 'DEBITED',";
			$sql .= " credit_debit_reference = '".$this->db->escape($reference)."',";
			$sql .= " credit_debit_date = NOW(),";
			$sql .= " credit_debit_amount = ".((float) $amount);
			$sql .= " WHERE rowid = ".((int) $fk_timesheet);

			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -5;
			}
		}

		return $movementId;
	}

	/**
	 *	Refund credits from timesheet
	 *
	 *	@param	int		$fk_timesheet	Timesheet ID
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function refundCredits($fk_timesheet)
	{
		global $user;

		require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';

		$fichinter = new Fichinter($this->db);
		$result = $fichinter->fetch($fk_timesheet);
		if ($result <= 0) {
			$this->error = 'Timesheet not found';
			return -1;
		}

		if ($fichinter->credit_status !== 'DEBITED') {
			$this->error = 'Timesheet not debited';
			return -2;
		}

		$sql = "SELECT rowid, amount FROM ".$this->db->prefix()."credits_movements";
		$sql .= " WHERE fk_timesheet = ".((int) $fk_timesheet);
		$sql .= " AND type_movement = 'DEBIT'";
		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -3;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			$this->error = 'Debit movement not found';
			return -4;
		}

		$parentMovementId = (int) $obj->rowid;
		$refundAmount = abs((float) $obj->amount);

		$this->db->begin();

		$balance = new CreditBalance($this->db);
		$result = $balance->updateBalance(
			$fichinter->socid,
			$fichinter->fk_credit_type,
			$refundAmount,
			$user
		);
		if ($result < 0) {
			$this->error = $balance->error;
			$this->db->rollback();
			return -5;
		}

		$movement = new CreditMovement($this->db);
		$movement->fk_soc = $fichinter->socid;
		$movement->fk_credit_type = $fichinter->fk_credit_type;
		$movement->amount = $refundAmount;
		$movement->type_movement = 'REFUND';
		$movement->description = 'Remboursement timesheet '.$fichinter->ref;
		$movement->fk_timesheet = $fk_timesheet;
		$movement->fk_parent_movement = $parentMovementId;
		$movement->date_movement = dol_now();

		$movementId = $movement->create($user);
		if ($movementId < 0) {
			$this->error = $movement->error;
			$this->db->rollback();
			return -6;
		}

		$sql = "UPDATE ".$this->db->prefix()."ficheinter SET";
		$sql .= " credit_status = 'APPROVED',";
		$sql .= " credit_debit_reference = NULL,";
		$sql .= " credit_debit_date = NULL,";
		$sql .= " credit_debit_amount = NULL";
		$sql .= " WHERE rowid = ".((int) $fk_timesheet);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -7;
		}

		$this->db->commit();
		return $movementId;
	}

	/**
	 *	Validate debit operation
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$amount			Amount to debit
	 *	@param	int		$fk_timesheet	Timesheet ID
	 *	@return	bool					true if valid, false otherwise
	 */
	private function validateDebit($fk_soc, $fk_credit_type, $amount, $fk_timesheet = 0)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

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

		if ($fk_timesheet > 0) {
			require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';

			$fichinter = new Fichinter($this->db);
			if ($fichinter->fetch($fk_timesheet) <= 0) {
				$this->error = 'Timesheet not found';
				return false;
			}

			if ($fichinter->credit_status !== 'APPROVED') {
				$this->error = 'Timesheet not approved';
				return false;
			}

			if (empty($fichinter->fk_credit_type)) {
				$this->error = 'Credit type not assigned to timesheet';
				return false;
			}

			if ($fichinter->fk_projet > 0) {
				$project = new Project($this->db);
				if ($project->fetch($fichinter->fk_projet) > 0) {
					if ($project->statut == Project::STATUS_CLOSED) {
						$this->error = 'Project closed';
						return false;
					}
				}
			}
		}

		return true;
	}
}
