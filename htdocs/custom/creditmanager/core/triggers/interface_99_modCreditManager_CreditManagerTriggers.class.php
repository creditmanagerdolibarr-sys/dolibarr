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
 * \file    core/triggers/interface_99_modCreditManager_CreditManagerTriggers.class.php
 * \ingroup creditmanager
 * \brief   Triggers for Credit Manager - refund credits when a timesheet entry is deleted
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditDebit.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditType.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditStatusTypesAndTimesheets.class.php';

/**
 * Class of triggers for Credit Manager module
 */
class InterfaceCreditManagerTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "financial";
		$this->description = "Credit Manager triggers - synchronize credit workflow on llx_element_time entries";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'creditmanager@creditmanager';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param string       $action     Event action code
	 * @param CommonObject $object     Object
	 * @param User         $user       User
	 * @param Translate    $langs      Langs
	 * @param Conf         $conf       Conf
	 * @return int                     <0 if KO, 0 if nothing done, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('creditmanager')) {
			return 0;
		}

		if ($action === 'TASK_TIMESPENT_CREATE') {
			$creditTM = new CreditStatusTypesAndTimesheets($this->db);
			$creditTM->fk_element_time = (int)$object->timespent_id;
			$fk_credits_types_selected = GETPOST('fk_credits_types');
			$fk_credits_status_selected = GETPOST('fk_credits_status');
			$creditTM->fk_credits_status = (int) $fk_credits_status_selected ? (int) $fk_credits_status_selected : "NULL";
			$creditTM->fk_credits_types = (int) $fk_credits_types_selected ? (int) $fk_credits_types_selected : "NULL";

			$creditTM->create();
		}

		if ($action === 'TASK_TIMESPENT_MODIFY') {

			$creditTM = new CreditStatusTypesAndTimesheets($this->db);
			$creditTM->fk_element_time = (int)$object->timespent_id;
			$fk_credits_types_selected = GETPOSTINT('fk_credits_types');
			$fk_credits_status_selected = GETPOSTINT('fk_credits_status');
			$creditTM->fk_credits_status = (int) $fk_credits_status_selected ? (int) $fk_credits_status_selected : "NULL";
			$creditTM->fk_credits_types = (int) $fk_credits_types_selected ? (int) $fk_credits_types_selected : "NULL";
			
			$creditTM->update();
		}

		// Timesheet entry deleted → refund any debit movement linked to it
		if ($action === 'TASK_TIMESPENT_DELETE' || $action === 'TIMESPENT_DELETE') {
			//return $this->handleTimespentDelete($object);
		}

		return 0;
	}

	/**
	 * Refund credits when a single timesheet entry is deleted.
	 *
	 * @param  CommonObject $object  The TimeSpent object being deleted (id = fk_element_time)
	 * @return int                   <0 if KO, 0 if nothing to do, 1 if refund issued
	 */
	private function handleTimespentDelete($object)
	{
		$fk_element_time = !empty($object->timespent_id) ? (int) $object->timespent_id : (int) $object->id;
		if ($fk_element_time <= 0) {
			return 0;
		}

		// Check whether an unrefunded DEBIT movement exists for this entry
		$sql  = "SELECT rowid FROM ".$this->db->prefix()."credits_movements";
		$sql .= " WHERE fk_element_time = ".$fk_element_time;
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

		if (!$obj) {
			return 0; // No active debit — nothing to refund
		}

		$creditDebit = new CreditDebit($this->db);
		$result = $creditDebit->refundCredits($fk_element_time);
		if ($result < 0) {
			$this->error = $creditDebit->error;
			return -1;
		}

		return 1;
	}

	private function handleTaskTimespentUpsert($action, $object)
	{
		$fk_element_time = (int) $object->timespent_id;
		if ($fk_element_time <= 0) {
			return 0;
		}

		$creditDebit = new CreditDebit($this->db);
		$currentData = $this->getTimesheetCreditData($fk_element_time);
		$newCreditTypeId = GETPOSTISSET('fk_credit_type') ? GETPOSTINT('fk_credit_type') : (int) $currentData['fk_credit_type'];
		$hadActiveDebit = $creditDebit->hasActiveDebit($fk_element_time);
		if ($hadActiveDebit < 0) {
			$this->error = $creditDebit->error;
			return -1;
		}

		$typeChanged = ((int) $currentData['fk_credit_type'] !== $newCreditTypeId);
		$durationChanged = ($action === 'TASK_TIMESPENT_MODIFY' && isset($object->timespent_old_duration) && (int) $object->timespent_old_duration !== (int) $object->timespent_duration);

		if (($typeChanged || $durationChanged) && $hadActiveDebit > 0) {
			$result = $creditDebit->refundCredits($fk_element_time);
			if ($result < 0) {
				$this->error = $creditDebit->error;
				return -1;
			}
			$hadActiveDebit = 0;
		}

		if (!$creditDebit->saveTimesheetCreditType($fk_element_time, $newCreditTypeId)) {
			$this->error = $creditDebit->error;
			return -1;
		}

		if ($newCreditTypeId <= 0) {
			return 0;
		}

		$creditType = new CreditType($this->db);
		if ($creditType->fetch($newCreditTypeId) <= 0) {
			$this->error = 'Credit type not found';
			return -1;
		}

		if ($creditType->isAutoDebit() && $hadActiveDebit === 0) {
			$result = $creditDebit->debitCreditsFromTimesheet($fk_element_time, $newCreditTypeId);
			if ($result < 0) {
				$this->error = $creditDebit->error;
				return -1;
			}
			return 1;
		}

		return 0;
	}

	private function getTimesheetCreditData($fk_element_time)
	{
		$sql = "SELECT fk_credit_type, credit_status FROM ".$this->db->prefix()."element_time WHERE rowid = ".((int) $fk_element_time);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return array('fk_credit_type' => 0, 'credit_status' => '');
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj) {
			return array('fk_credit_type' => 0, 'credit_status' => '');
		}

		return array(
			'fk_credit_type' => $obj->fk_credit_type ? (int) $obj->fk_credit_type : 0,
			'credit_status' => (string) $obj->credit_status,
		);
	}
}
