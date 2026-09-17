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
 *	\file       htdocs/custom/creditmanager/class/CreditStatusTypesAndTimesheets.class.php
 *	\ingroup    creditmanager
 *	\brief      Persistent Credit Manager metadata for Dolibarr timesheet rows
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 *	Class to manage the link between a timesheet, credit type and workflow status
 */
class CreditStatusTypesAndTimesheets extends CommonObject
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of table without prefix
	 */
	public $table_element = 'credits_status_types_and_timesheets';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'credits_status_types_and_timesheets';

	/**
	 * @var int id
	 */
	public $rowid;

	/**
	 * @var int element time id
	 */
	public $fk_element_time;

	/**
	 * @var int credit status id
	 */
	public $fk_credits_status;

	/**
	 * @var int credit type id
	 */
	public $fk_credits_types;

	/** @var int|string|null Date of latest transition to APPROVED */
	public $approval_date;


	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf;
		$this->db = $db;
	}

	/**
	 * Load object by element_time id.
	 *
	 * @param int $fk_element_time Timesheet row id
	 * @return int <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($fk_element_time)
	{
		$sql = "SELECT rowid, fk_element_time, fk_credits_status, fk_credits_types, approval_date";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		if ($fk_element_time <= 0) {
			return -1;
		}
		$sql .= " WHERE fk_element_time = ".((int) $fk_element_time);
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1, 0);

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->rowid = (int) $obj->rowid;
				$this->fk_element_time = (int) $obj->fk_element_time;
				$this->fk_credits_status = (int) $obj->fk_credits_status;
				$this->fk_credits_types = (int) $obj->fk_credits_types;
				$this->approval_date = $obj->approval_date ? $this->db->jdate($obj->approval_date) : null;
				$this->db->free($resql);
				return 1;
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 0;
	}

	/**
	 * Load all timesheet metadata rows.
	 *
	 * @return array|int Array of CreditStatusTypesAndTimesheets, or <0 if KO
	 */
	public function fetchAll()
	{
		$sql = "SELECT fk_element_time FROM ".$this->db->prefix().$this->table_element;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new CreditStatusTypesAndTimesheets($this->db);
			$item->fetch((int) $obj->fk_element_time);
			$list[] = $item;
		}
		$this->db->free($resql);
		return $list;
	}

	/**
	 * Create metadata for a timesheet.
	 *
	 *	@return	int					<0 if KO, id of created record if OK
	 */
	public function create()
	{
		if ((int) $this->fk_element_time <= 0) {
			$this->error = 'fk_element_time is required';
			return -1;
		}

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (fk_element_time, fk_credits_status, fk_credits_types, approval_date)";
		$sql .= " VALUES (".((int) $this->fk_element_time).", ";
		$sql .= ($this->fk_credits_status ? (int) $this->fk_credits_status : "NULL").", ";
		$sql .= ($this->fk_credits_types ? (int) $this->fk_credits_types : "NULL").", ";
		$sql .= ($this->approval_date ? "'".$this->db->idate($this->approval_date)."'" : "NULL").")";
		
		dol_syslog(get_class($this).'::create', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->rowid = $this->db->last_insert_id($this->db->prefix().$this->table_element);
		return $this->rowid;
	}

	/**
	 * Update metadata for a timesheet.
	 *
	 *	
	 *	@return	int					<0 if KO, >0 if OK
	*/
	public function update()
	{
		if ((int) $this->fk_element_time <= 0) {
			$this->error = 'fk_element_time is required';
			return -1;
		}
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " fk_credits_status = ".($this->fk_credits_status ? (int) $this->fk_credits_status : "NULL").",";
		$sql .= " fk_credits_types = ".($this->fk_credits_types ? (int) $this->fk_credits_types : "NULL").",";
		$sql .= " approval_date = ".($this->approval_date ? "'".$this->db->idate($this->approval_date)."'" : "NULL");
		$sql .= " WHERE fk_element_time = ".(int) $this->fk_element_time;

		dol_syslog(get_class($this).'::update', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Insert or update metadata for a timesheet.
	 *
	 * @return int <0 if KO, >0 if OK
	 */
	public function upsert()
	{
		$timesheetId = (int) $this->fk_element_time;
		$statusId = $this->fk_credits_status;
		$typeId = $this->fk_credits_types;
		$result = $this->fetch((int) $this->fk_element_time);
		if ($result < 0) {
			return -1;
		}
		$this->fk_element_time = $timesheetId;
		$this->fk_credits_status = $statusId;
		$this->fk_credits_types = $typeId;
		return $result > 0 ? $this->update() : $this->create();
	}

	/**
	 * Set type and status by status name, preserving omitted values.
	 *
	 * @param int         $fk_element_time Timesheet row id
	 * @param int|null    $fk_credit_type  Credit type, null to preserve
	 * @param string|null $statusName      Status name, null to preserve
	 * @param int|null    $approvalDate    Approval timestamp, null to preserve
	 * @return int <0 if KO, >0 if OK
	 */
	public function setMetadata($fk_element_time, $fk_credit_type = null, $statusName = null, $approvalDate = null)
	{
		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditStatus.class.php';

		$this->fk_element_time = (int) $fk_element_time;
		$fetched = $this->fetch($this->fk_element_time);
		if ($fetched < 0) {
			return -1;
		}
		if ($fetched === 0) {
			$this->fk_element_time = (int) $fk_element_time;
			$this->fk_credits_types = null;
			$this->fk_credits_status = null;
		}
		if ($fk_credit_type !== null) {
			$this->fk_credits_types = (int) $fk_credit_type > 0 ? (int) $fk_credit_type : null;
		}
		if ($statusName !== null) {
			$status = new CreditStatus($this->db);
			$statusId = $status->getIdByName($statusName);
			if ($statusId <= 0) {
				$this->error = 'Unknown credit status: '.$statusName;
				return -1;
			}
			$this->fk_credits_status = $statusId;
		}
		if ($approvalDate !== null) {
			$this->approval_date = $approvalDate;
		}
		return $fetched > 0 ? $this->update() : $this->create();
	}

	/**
	 * Fetch metadata with status name.
	 *
	 * @param int $fk_element_time Timesheet row id
	 * @param bool $forUpdate Lock row until transaction end
	 * @return array{fk_credit_type:int,status:string}|null
	 */
	public function getMetadata($fk_element_time, $forUpdate = false)
	{
		$sql = "SELECT rel.fk_credits_types, cs.status_name";
		$sql .= " FROM ".$this->db->prefix().$this->table_element." as rel";
		$sql .= " LEFT JOIN ".$this->db->prefix()."credits_status as cs ON cs.rowid = rel.fk_credits_status";
		$sql .= " WHERE rel.fk_element_time = ".((int) $fk_element_time);
		$sql .= " ORDER BY rel.rowid DESC";
		$sql .= $this->db->plimit(1, 0);
		if ($forUpdate) {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array(
			'fk_credit_type' => $obj->fk_credits_types ? (int) $obj->fk_credits_types : 0,
			'status' => strtoupper(trim((string) $obj->status_name)),
		);
	}

	/**
	 * Delete metadata for a timesheet.
	 *
	 * @param int $fk_element_time Timesheet row id
	 * @return int <0 if KO, >0 if OK
	 */
	public function deleteByTimesheet($fk_element_time)
	{
		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_element_time = ".((int) $fk_element_time);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
