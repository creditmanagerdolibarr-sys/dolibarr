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
 *	\file       htdocs/custom/creditmanager/class/CreditType.class.php
 *	\ingroup    creditmanager
 *	\brief      Class to manage credit types (CRUD and debit configuration)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 *	Class to manage credit types
 */
class CreditType extends CommonObject
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of table without prefix
	 */
	public $table_element = 'credits_types';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'credits_type';

	/**
	 * @var int<0,1>|string Multi-entity: 1 = test entity
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var string Unique code (e.g. SUPPORT_HOURS, PROJECT_HOURS)
	 */
	public $code;

	/**
	 * @var string Label
	 */
	public $label;

	/**
	 * @var string Unit (e.g. hour)
	 */
	public $unit;

	/**
	 * @var int<0,1> Auto debit on approval (1) or not (0)
	 */
	public $auto_debit;

	/**
	 * @var int|null Delay in days before auto debit (when auto_debit=0)
	 */
	public $debit_delay_days;

	/**
	 * @var int<0,1> Active (1) or inactive (0)
	 */
	public $active;

	/**
	 * @var string Precision unit (e.g. hour) for future use
	 */
	public $precision_unit;

	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf;
		$this->db = $db;
		$this->active = 1;
		$this->auto_debit = 0;
		$this->unit = 'hour';
		$this->precision_unit = 'hour';
	}

	/**
	 *	Load object in memory from database
	 *
	 *	@param	int		$id		Id of object
	 *	@param	string	$code	Code of type (alternative to id)
	 *	@return	int				<0 if KO, >0 if OK
	 */
	public function fetch($id, $code = '')
	{
		$sql = "SELECT rowid, entity, code, label, unit, auto_debit, debit_delay_days, active, precision_unit, tms, date_creation, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity('credits_type').")";
		if ($id > 0) {
			$sql .= " AND rowid = ".((int) $id);
		} elseif ($code !== '') {
			$sql .= " AND code = '".$this->db->escape($code)."'";
		} else {
			return -1;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = (int) $obj->rowid;
				$this->entity = (int) $obj->entity;
				$this->code = $obj->code;
				$this->label = $obj->label;
				$this->unit = $obj->unit;
				$this->auto_debit = (int) $obj->auto_debit;
				$this->debit_delay_days = $obj->debit_delay_days !== null ? (int) $obj->debit_delay_days : null;
				$this->active = (int) $obj->active;
				$this->precision_unit = $obj->precision_unit;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->fk_user_creat = $obj->fk_user_creat ? (int) $obj->fk_user_creat : null;
				$this->fk_user_modif = $obj->fk_user_modif ? (int) $obj->fk_user_modif : null;
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
	 *	Load all credit types (optionally only active)
	 *
	 *	@param	int		$activeOnly	1 = only active types
	 *	@return	array|int			Array of CreditType, or <0 if KO
	 */
	public function fetchAll($activeOnly = 1)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity('credits_type').")";
		if ($activeOnly) {
			$sql .= " AND active = 1";
		}
		$sql .= " ORDER BY code";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new CreditType($this->db);
			$item->fetch((int) $obj->rowid);
			$list[] = $item;
		}
		$this->db->free($resql);
		return $list;
	}

	/**
	 *	Create credit type in database
	 *
	 *	@param	User	$user		User making creation
	 *	@param	int		$notrigger	1 = do not run triggers
	 *	@return	int					<0 if KO, id of created record if OK
	 */
	public function create($user, $notrigger = 0)
	{
		global $conf;

		$this->code = trim($this->code);
		$this->label = trim($this->label);
		if (empty($this->code) || empty($this->label)) {
			$this->error = 'Code and label are required';
			return -1;
		}

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (entity, code, label, unit, auto_debit, debit_delay_days, active, precision_unit, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($this->code)."', '".$this->db->escape($this->label)."', '".$this->db->escape($this->unit ?: 'hour')."', ";
		$sql .= ((int) $this->auto_debit).", ";
		$sql .= ($this->debit_delay_days !== null && $this->debit_delay_days !== '' ? (int) $this->debit_delay_days : "NULL").", ";
		$sql .= ((int) $this->active).", '".$this->db->escape($this->precision_unit ?: 'hour')."', NOW(), ".((int) $user->id).")";

		dol_syslog(get_class($this).'::create', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->id = $this->db->last_insert_id($this->db->prefix().$this->table_element);
		$this->db->commit();
		return $this->id;
	}

	/**
	 *	Update credit type in database
	 *
	 *	@param	User	$user		User making update
	 *	@param	int		$notrigger	1 = do not run triggers
	 *	@return	int					<0 if KO, >0 if OK
	 */
	public function update($user, $notrigger = 0)
	{
		$this->code = trim($this->code);
		$this->label = trim($this->label);
		if (empty($this->code) || empty($this->label)) {
			$this->error = 'Code and label are required';
			return -1;
		}

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " code = '".$this->db->escape($this->code)."',";
		$sql .= " label = '".$this->db->escape($this->label)."',";
		$sql .= " unit = '".$this->db->escape($this->unit ?: 'hour')."',";
		$sql .= " auto_debit = ".((int) $this->auto_debit).",";
		$sql .= " debit_delay_days = ".($this->debit_delay_days !== null && $this->debit_delay_days !== '' ? (int) $this->debit_delay_days : "NULL").",";
		$sql .= " active = ".((int) $this->active).",";
		$sql .= " precision_unit = '".$this->db->escape($this->precision_unit ?: 'hour')."',";
		$sql .= " fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id)." AND entity IN (".getEntity('credits_type').")";

		dol_syslog(get_class($this).'::update', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 *	Delete credit type from database (physical delete if never used, else soft delete)
	 *
	 *	If the type has movements or balances: active is set to 0 (soft delete), no physical delete.
	 *	If the type has never been used: physical DELETE is performed.
	 *
	 *	@param	User	$user		User making delete
	 *	@param	int		$notrigger	1 = do not run triggers
	 *	@return	int					<0 if KO, >0 if OK
	 */
	public function delete($user, $notrigger = 0)
	{
		$id = (int) $this->id;
		$entityFilter = getEntity('credits_type');

		// Check if type is used in balances or movements
		$sqlCheck = "SELECT (SELECT COUNT(*) FROM ".$this->db->prefix()."credits_balance WHERE fk_credit_type = ".$id.")";
		$sqlCheck .= " + (SELECT COUNT(*) FROM ".$this->db->prefix()."credits_movements WHERE fk_credit_type = ".$id.") AS cnt";
		$resql = $this->db->query($sqlCheck);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$isUsed = ($obj && (int) $obj->cnt > 0);

		if ($isUsed) {
			// Soft delete: set active = 0 (keeps history, type no longer appears in attribution choices)
			$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET active = 0, fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".$id." AND entity IN (".$entityFilter.")";
			dol_syslog(get_class($this).'::delete (soft)', LOG_DEBUG);
		} else {
			// Physical delete: type has never been used
			$sql = "DELETE FROM ".$this->db->prefix().$this->table_element;
			$sql .= " WHERE rowid = ".$id." AND entity IN (".$entityFilter.")";
			dol_syslog(get_class($this).'::delete (physical)', LOG_DEBUG);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($isUsed) {
			$this->active = 0;
		}
		return 1;
	}

	/**
	 *	Whether this type is debited automatically on approval
	 *
	 *	@return	bool
	 */
	public function isAutoDebit()
	{
		return (int) $this->auto_debit === 1;
	}

	/**
	 *	Whether this type is manual debit only (no delay)
	 *
	 *	@return	bool
	 */
	public function isManualDebit()
	{
		return (int) $this->auto_debit === 0 && ($this->debit_delay_days === null || $this->debit_delay_days === '');
	}

	/**
	 *	Whether this type has delayed auto debit
	 *
	 *	@return	bool
	 */
	public function isDelayedDebit()
	{
		return (int) $this->auto_debit === 0 && $this->debit_delay_days !== null && $this->debit_delay_days !== '';
	}

	/**
	 *	Compute debit date from approval date according to type configuration
	 *
	 *	@param	int|string	$approval_date	Timestamp or datetime of approval
	 *	@return	int|null		Timestamp of debit date, or null if manual
	 */
	public function getDebitDate($approval_date)
	{
		if ($this->isAutoDebit()) {
			return is_numeric($approval_date) ? (int) $approval_date : strtotime($approval_date);
		}
		if ($this->isDelayedDebit()) {
			$ts = is_numeric($approval_date) ? (int) $approval_date : strtotime($approval_date);
			return strtotime('+'.(int) $this->debit_delay_days.' days', $ts);
		}
		return null;
	}
}
