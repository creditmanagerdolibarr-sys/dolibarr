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
 *	\file       htdocs/custom/creditmanager/class/CreditMovement.class.php
 *	\ingroup    creditmanager
 *	\brief      Class to manage credit movements history
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 *	Class to manage credit movements
 */
class CreditMovement extends CommonObject
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of table without prefix
	 */
	public $table_element = 'credits_movements';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'credits_movement';

	/**
	 * @var int<0,1>|string Multi-entity: 1 = test entity
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int Client ID
	 */
	public $fk_soc;

	/**
	 * @var int Credit type ID
	 */
	public $fk_credit_type;

	/**
	 * @var int|string Movement date
	 */
	public $date_movement;

	/**
	 * @var float Movement amount
	 */
	public $amount;

	/**
	 * @var float Balance after movement
	 */
	public $balance_after;

	/**
	 * @var string Movement type
	 */
	public $type_movement;

	/**
	 * @var string Description
	 */
	public $description;

	/**
	 * @var int|null Timesheet ID
	 */
	public $fk_timesheet;

	/**
	 * @var int|null Invoice ID
	 */
	public $fk_invoice;

	/**
	 * @var int|null Attribution movement ID
	 */
	public $fk_attribution;

	/**
	 * @var int|null Parent movement ID
	 */
	public $fk_parent_movement;

	/**
	 * @var int User who created
	 */
	public $fk_user_creat;

	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->amount = 0;
		$this->balance_after = 0;
	}

	/**
	 *	Load object in memory from database
	 *
	 *	@param	int		$id		Id of object
	 *	@return	int				<0 if KO, >0 if OK, 0 if not found
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, fk_soc, fk_credit_type, date_movement, amount, balance_after,";
		$sql .= " type_movement, description, fk_timesheet, fk_invoice, fk_attribution,";
		$sql .= " fk_parent_movement, fk_user_creat, tms";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $id);
		$sql .= " AND entity IN (".getEntity('credits_movement').")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = (int) $obj->rowid;
				$this->entity = (int) $obj->entity;
				$this->fk_soc = (int) $obj->fk_soc;
				$this->fk_credit_type = (int) $obj->fk_credit_type;
				$this->date_movement = $this->db->jdate($obj->date_movement);
				$this->amount = (float) $obj->amount;
				$this->balance_after = (float) $obj->balance_after;
				$this->type_movement = $obj->type_movement;
				$this->description = $obj->description;
				$this->fk_timesheet = $obj->fk_timesheet ? (int) $obj->fk_timesheet : null;
				$this->fk_invoice = $obj->fk_invoice ? (int) $obj->fk_invoice : null;
				$this->fk_attribution = $obj->fk_attribution ? (int) $obj->fk_attribution : null;
				$this->fk_parent_movement = $obj->fk_parent_movement ? (int) $obj->fk_parent_movement : null;
				$this->fk_user_creat = (int) $obj->fk_user_creat;
				$this->db->free($resql);
				return 1;
			}
			$this->db->free($resql);
			return 0;
		} else {
			$this->error = $this->db->lasterror();
			return -1;
		}
	}

	/**
	 *	Create movement in database
	 *
	 *	@param	User	$user		User making creation
	 *	@return	int					<0 if KO, id if OK
	 */
	public function create($user)
	{
		global $conf;

		if (empty($this->fk_soc) || empty($this->fk_credit_type) || empty($this->type_movement)) {
			$this->error = 'fk_soc, fk_credit_type and type_movement are required';
			return -1;
		}

		if (empty($this->date_movement)) {
			$this->date_movement = dol_now();
		}

		dol_include_once('/creditmanager/class/CreditBalance.class.php');
		$balance = new CreditBalance($this->db);
		$currentBalance = $balance->getBalance($this->fk_soc, $this->fk_credit_type);
		if ($currentBalance < 0) {
			$currentBalance = 0;
		}
		$this->balance_after = $currentBalance + $this->amount;

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element;
		$sql .= " (entity, fk_soc, fk_credit_type, date_movement, amount, balance_after,";
		$sql .= " type_movement, description, fk_timesheet, fk_invoice, fk_attribution,";
		$sql .= " fk_parent_movement, fk_user_creat, tms)";
		$sql .= " VALUES (";
		$sql .= ((int) $conf->entity).", ";
		$sql .= ((int) $this->fk_soc).", ";
		$sql .= ((int) $this->fk_credit_type).", ";
		$sql .= "'".$this->db->idate($this->date_movement)."', ";
		$sql .= ((float) $this->amount).", ";
		$sql .= ((float) $this->balance_after).", ";
		$sql .= "'".$this->db->escape($this->type_movement)."', ";
		$sql .= ($this->description ? "'".$this->db->escape($this->description)."'" : "NULL").", ";
		$sql .= ($this->fk_timesheet ? ((int) $this->fk_timesheet) : "NULL").", ";
		$sql .= ($this->fk_invoice ? ((int) $this->fk_invoice) : "NULL").", ";
		$sql .= ($this->fk_attribution ? ((int) $this->fk_attribution) : "NULL").", ";
		$sql .= ($this->fk_parent_movement ? ((int) $this->fk_parent_movement) : "NULL").", ";
		$sql .= ((int) $user->id).", ";
		$sql .= "NOW()";
		$sql .= ")";

		dol_syslog(get_class($this).'::create', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->id = $this->db->last_insert_id($this->db->prefix().$this->table_element);

		if ($this->fk_attribution === 0 && $this->type_movement === 'ATTRIBUTION' && $this->id > 0) {
			$sqlUpd = "UPDATE ".$this->db->prefix().$this->table_element;
			$sqlUpd .= " SET fk_attribution = ".((int) $this->id);
			$sqlUpd .= " WHERE rowid = ".((int) $this->id);
			$this->db->query($sqlUpd);
		}

		$this->db->commit();
		return $this->id;
	}

	/**
	 *	Get all movements with filters
	 *
	 *	@param	array	$filters	Array of filters (fk_soc, fk_credit_type, type_movement, date_start, date_end)
	 *	@param	string	$sortfield	Sort field
	 *	@param	string	$sortorder	Sort order
	 *	@param	int		$limit		Limit
	 *	@param	int		$offset		Offset
	 *	@return	array|int			Array of movements, or <0 if error
	 */
	public function fetchAll($filters = array(), $sortfield = 'date_movement', $sortorder = 'DESC', $limit = 0, $offset = 0)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity('credits_movement').")";

		if (!empty($filters['fk_soc'])) {
			$sql .= " AND fk_soc = ".((int) $filters['fk_soc']);
		}
		if (!empty($filters['fk_credit_type'])) {
			$sql .= " AND fk_credit_type = ".((int) $filters['fk_credit_type']);
		}
		if (!empty($filters['type_movement'])) {
			$sql .= " AND type_movement = '".$this->db->escape($filters['type_movement'])."'";
		}
		if (!empty($filters['fk_timesheet'])) {
			$sql .= " AND fk_timesheet = ".((int) $filters['fk_timesheet']);
		}
		if (!empty($filters['date_start'])) {
			$sql .= " AND date_movement >= '".$this->db->idate($filters['date_start'])."'";
		}
		if (!empty($filters['date_end'])) {
			$sql .= " AND date_movement <= '".$this->db->idate($filters['date_end'])."'";
		}

		$sql .= $this->db->order($sortfield, $sortorder);

		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$item = new CreditMovement($this->db);
			$item->fetch((int) $obj->rowid);
			$list[] = $item;
		}
		$this->db->free($resql);
		return $list;
	}

	/**
	 *	Get history by client
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID (0 for all)
	 *	@param	int		$limit			Limit
	 *	@return	array|int				Array of movements, or <0 if error
	 */
	public function getHistoryByClient($fk_soc, $fk_credit_type = 0, $limit = 100)
	{
		$filters = array('fk_soc' => $fk_soc);
		if ($fk_credit_type > 0) {
			$filters['fk_credit_type'] = $fk_credit_type;
		}
		return $this->fetchAll($filters, 'date_movement', 'DESC', $limit);
	}

	/**
	 *	Create attribution with balance update (transactional)
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$amount			Amount
	 *	@param	string	$description	Description
	 *	@param	User	$user			User
	 *	@return	int						Movement ID if OK, <0 if KO
	 */
	public function createAttribution($fk_soc, $fk_credit_type, $amount, $description, $user)
	{
		dol_include_once('/creditmanager/class/CreditBalance.class.php');

		$this->db->begin();

		$balance = new CreditBalance($this->db);
		$result = $balance->updateBalance($fk_soc, $fk_credit_type, $amount, $user);
		if ($result < 0) {
			$this->error = $balance->error;
			$this->db->rollback();
			return -1;
		}

		$this->fk_soc = $fk_soc;
		$this->fk_credit_type = $fk_credit_type;
		$this->amount = $amount;
		$this->type_movement = 'ATTRIBUTION';
		$this->description = $description;
		$this->fk_attribution = 0;

		$movementId = $this->create($user);
		if ($movementId < 0) {
			$this->db->rollback();
			return -2;
		}

		$this->db->commit();
		return $movementId;
	}
}
