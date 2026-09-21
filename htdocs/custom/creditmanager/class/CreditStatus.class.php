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
class CreditStatus extends CommonObject
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of table without prefix
	 */
	public $table_element = 'credits_status';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'credits_status';

	/**
	 * @var int id
	 */
	public $rowid;

	/**
	 * @var string Status name
	 */
	public $status_name;


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
	 *
	 *	@param	int		$id		Id of object
	 *	@param	string	$code	Code of type (alternative to id)
	 *	@return	int				<0 if KO, >0 if OK
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, status_name";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;

		if ($id > 0) {
			$sql .= " WHERE rowid = ".((int) $id);
		} else {
			return -1;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->rowid = (int) $obj->rowid;
				$this->status_name = $obj->status_name;
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
	public function fetchAll()
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		
		$sql .= " ORDER BY rowid";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new CreditStatus($this->db);
			$item->fetch((int) $obj->rowid);
			$list[] = $item;
		}
		$this->db->free($resql);
		return $list;
	}
}
