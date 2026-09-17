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
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditStatus.class.php';
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
			$creditTM->fk_element_time = (int) $object->timespent_id;
			$creditTM->fk_credits_types = GETPOSTINT('fk_credits_types') ?: null;
			$creditTM->fk_credits_status = GETPOSTINT('fk_credits_status') ?: null;
			if (!empty($creditTM->fk_credits_types)) {
				$creditType = new CreditType($this->db);
				if ($creditType->fetch((int) $creditTM->fk_credits_types) <= 0 || empty($creditType->active)) {
					$this->error = 'Credit type not found or inactive';
					return -1;
				}
			}
			$status = new CreditStatus($this->db);
			if (empty($creditTM->fk_credits_status)) {
				$creditTM->fk_credits_status = $status->getIdByName('DRAFT') ?: null;
			} elseif ($status->fetch((int) $creditTM->fk_credits_status) <= 0
				|| !in_array(strtoupper($status->status_name), array('DRAFT', 'SUBMITTED'), true)) {
				$this->error = 'Only DRAFT or SUBMITTED is allowed when creating a timesheet';
				return -1;
			}
			if ($creditTM->fk_element_time > 0 && $creditTM->create() < 0) {
				$this->error = $creditTM->error;
				return -1;
			}
		}

		if ($action === 'TASK_TIMESPENT_MODIFY') {
			$creditTM = new CreditStatusTypesAndTimesheets($this->db);
			$timespentId = (int) $object->timespent_id;
			if ($timespentId <= 0) {
				return 0;
			}
			$current = $creditTM->fetch($timespentId);
			if ($current < 0) {
				$this->error = $creditTM->error;
				return -1;
			}
			$creditTM->fk_element_time = $timespentId;
			$oldTypeId = $current > 0 ? (int) $creditTM->fk_credits_types : 0;
			$oldStatusId = $current > 0 ? (int) $creditTM->fk_credits_status : 0;
			$oldStatusName = 'DRAFT';
			if ($oldStatusId > 0) {
				$status = new CreditStatus($this->db);
				if ($status->fetch($oldStatusId) <= 0) {
					$this->error = 'Invalid current credit status';
					return -1;
				}
				$oldStatusName = strtoupper(trim($status->status_name));
			}
			if ($current === 0) {
				$status = new CreditStatus($this->db);
				$creditTM->fk_credits_status = $status->getIdByName('DRAFT') ?: null;
				$creditTM->fk_credits_types = null;
			}
			if (GETPOSTISSET('fk_credits_types')) {
				$creditTM->fk_credits_types = GETPOSTINT('fk_credits_types') ?: null;
				if (!empty($creditTM->fk_credits_types)) {
					$creditType = new CreditType($this->db);
					if ($creditType->fetch((int) $creditTM->fk_credits_types) <= 0 || empty($creditType->active)) {
						$this->error = 'Credit type not found or inactive';
						return -1;
					}
				}
			}
			if (GETPOSTISSET('fk_credits_status')) {
				$newStatusId = GETPOSTINT('fk_credits_status');
				$status = new CreditStatus($this->db);
				if ($newStatusId <= 0 || $status->fetch($newStatusId) <= 0) {
					$this->error = 'Invalid credit status';
					return -1;
				}
				$newStatusName = strtoupper(trim($status->status_name));
				$isSubmissionTransition = in_array($oldStatusName, array('DRAFT', 'SUBMITTED'), true)
					&& in_array($newStatusName, array('DRAFT', 'SUBMITTED'), true);
				if ($newStatusName !== $oldStatusName && !$isSubmissionTransition) {
					$this->error = 'This credit status transition requires the dedicated workflow action';
					return -1;
				}
				$creditTM->fk_credits_status = $newStatusId;
			}
			$hasChanges = $current === 0
				|| $oldTypeId !== (int) $creditTM->fk_credits_types
				|| $oldStatusId !== (int) $creditTM->fk_credits_status;
			$durationChanged = isset($object->timespent_old_duration)
				&& (int) $object->timespent_old_duration !== (int) $object->timespent_duration;
			if ($current > 0 && ($hasChanges || $durationChanged)) {
				$creditDebit = new CreditDebit($this->db);
				$hasActiveDebit = $creditDebit->hasActiveDebit($timespentId);
				if ($hasActiveDebit < 0) {
					$this->error = $creditDebit->error;
					return -1;
				}
				if ($hasActiveDebit > 0) {
					$status = new CreditStatus($this->db);
					if ($status->fetch((int) $creditTM->fk_credits_status) <= 0) {
						$this->error = 'Invalid credit status';
						return -1;
					}
					$desiredStatus = strtoupper(trim($status->status_name));
					if ($creditDebit->refundCredits($timespentId) < 0) {
						$this->error = $creditDebit->error;
						return -1;
					}
					if ($desiredStatus === 'DEBITED') {
						if (empty($creditTM->fk_credits_types) || $creditDebit->debitCreditsFromTimesheet($timespentId, (int) $creditTM->fk_credits_types) < 0) {
							$this->error = $creditDebit->error ?: 'Credit type is required';
							return -1;
						}
					} else {
						$link = new CreditStatusTypesAndTimesheets($this->db);
						if ($link->setMetadata($timespentId, (int) $creditTM->fk_credits_types, $desiredStatus) < 0) {
							$this->error = $link->error;
							return -1;
						}
					}
					return 1;
				}
			}
			$result = !$hasChanges ? 1 : ($current > 0 ? $creditTM->update() : $creditTM->create());
			if ($result < 0) {
				$this->error = $creditTM->error;
				return -1;
			}
		}

		// Timesheet entry deleted → refund any debit movement linked to it
		if ($action === 'TASK_TIMESPENT_DELETE' || $action === 'TIMESPENT_DELETE') {
			$result = $this->handleTimespentDelete($object);
			if ($result < 0) {
				return -1;
			}
			$timespentId = !empty($object->timespent_id) ? (int) $object->timespent_id : (int) $object->id;
			$link = new CreditStatusTypesAndTimesheets($this->db);
			if ($timespentId > 0 && $link->deleteByTimesheet($timespentId) < 0) {
				$this->error = $link->error;
				return -1;
			}
			return $result;
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

}
