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
 *	\brief      Class to manage credit status types and timesheets (CRUD and debit configuration)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 *	Class to manage credit types
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
	 *	Load object in memory from database
	 *	@param	string	$code	Code of type (alternative to id)
	 *	@return	int				<0 if KO, >0 if OK
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, fk_element_time, fk_credits_status, fk_credits_types";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;

		if ($id > 0) {
			$sql .= " WHERE fk_element_time = ".((int) $id);
		} else {
			return -1;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->rowid = (int) $obj->rowid;
				$this->fk_element_time = (int) $obj->fk_element_time;
				$this->fk_credits_status = (int) $obj->fk_credits_status;
				$this->fk_credits_types = (int) $obj->fk_credits_types;
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
	 *	Load all credit_status_types_and_timesheets elements (optionally only active)
	 *
	 *	@param	int		$activeOnly	1 = only active types
	 *	@return	array|int			Array of CreditType, or <0 if KO
	 */
	public function fetchAll()
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new CreditStatusTypesAndTimesheets($this->db);
			$item->fetch((int) $obj->rowid);
			$list[] = $item;
		}
		$this->db->free($resql);
		return $list;
	}

	/**
	 *	Create credit_status_types_and_timesheets item in database
	 *
	 *	@return	int					<0 if KO, id of created record if OK
	 */
	public function create()
	{
		global $conf;

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (fk_element_time, fk_credits_status, fk_credits_types)";
		$sql .= " VALUES (".((int) $this->fk_element_time).", ".((int) $this->fk_credits_status).", ".((int) $this->fk_credits_types).")";
		
		dol_syslog(get_class($this).'::create', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->rowid = $this->db->last_insert_id($this->db->prefix().$this->table_element);
		$this->db->commit();
		return $this->rowid;
	}

	/**
	 *	Update credit_status_types_and_timesheets in database
	 *
	 *	
	 *	@return	int					<0 if KO, >0 if OK
	*/
	public function update()
	{
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " fk_credits_status = ". (int) $this->fk_credits_status .",";
		$sql .= " fk_credits_types = ". (int) $this->fk_credits_types;
		$sql .= " WHERE fk_element_time = ".(int) $this->fk_element_time."";

		dol_syslog(get_class($this).'::update', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
