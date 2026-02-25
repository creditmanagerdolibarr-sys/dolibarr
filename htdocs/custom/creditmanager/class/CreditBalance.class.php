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
 *	\file       htdocs/custom/creditmanager/class/CreditBalance.class.php
 *	\ingroup    creditmanager
 *	\brief      Class to manage client credit balances
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 *	Class to manage credit balances
 */
class CreditBalance extends CommonObject
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Name of table without prefix
	 */
	public $table_element = 'credits_balance';

	/**
	 * @var string ID to identify managed object
	 */
	public $element = 'credits_balance';

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
	 * @var float Balance amount
	 */
	public $balance;

	/**
	 *	Constructor
	 *
	 *	@param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->balance = 0;
	}

	/**
	 *	Load object in memory from database
	 *
	 *	@param	int		$id				Id of object
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@return	int						<0 if KO, >0 if OK, 0 if not found
	 */
	public function fetch($id = 0, $fk_soc = 0, $fk_credit_type = 0)
	{
		global $conf;

		$sql = "SELECT rowid, entity, fk_soc, fk_credit_type, balance, tms";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE entity IN (".getEntity('credits_balance').")";

		if ($id > 0) {
			$sql .= " AND rowid = ".((int) $id);
		} elseif ($fk_soc > 0 && $fk_credit_type > 0) {
			$sql .= " AND fk_soc = ".((int) $fk_soc);
			$sql .= " AND fk_credit_type = ".((int) $fk_credit_type);
		} else {
			return -1;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$this->id = (int) $obj->rowid;
				$this->entity = (int) $obj->entity;
				$this->fk_soc = (int) $obj->fk_soc;
				$this->fk_credit_type = (int) $obj->fk_credit_type;
				$this->balance = (float) $obj->balance;
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
	 *	Create balance in database
	 *
	 *	@param	User	$user		User making creation
	 *	@return	int					<0 if KO, id if OK
	 */
	public function create($user)
	{
		global $conf;

		if (empty($this->fk_soc) || empty($this->fk_credit_type)) {
			$this->error = 'fk_soc and fk_credit_type are required';
			return -1;
		}

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (entity, fk_soc, fk_credit_type, balance, tms)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $this->fk_soc).", ".((int) $this->fk_credit_type).", ";
		$sql .= ((float) $this->balance).", NOW())";

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
	 *	Update balance in database
	 *
	 *	@param	User	$user		User making update
	 *	@return	int					<0 if KO, >0 if OK
	 */
	public function update($user)
	{
		global $conf;

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " balance = ".((float) $this->balance).",";
		$sql .= " tms = NOW()";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity IN (".getEntity('credits_balance').")";

		dol_syslog(get_class($this).'::update', LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 *	Get balance for a client and credit type
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@return	float|int				Balance amount, or -1 if error
	 */
	public function getBalance($fk_soc, $fk_credit_type)
	{
		$result = $this->fetch(0, $fk_soc, $fk_credit_type);
		if ($result > 0) {
			return $this->balance;
		} elseif ($result == 0) {
			return 0;
		}
		return -1;
	}

	/**
	 *	Update balance by delta amount
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$delta			Amount to add (positive) or subtract (negative)
	 *	@param	User	$user			User making update
	 *	@return	int						<0 if KO, >0 if OK
	 */
	public function updateBalance($fk_soc, $fk_credit_type, $delta, $user)
	{
		global $conf;

		$this->db->begin();

		$sql = "SELECT rowid, balance FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE fk_soc = ".((int) $fk_soc);
		$sql .= " AND fk_credit_type = ".((int) $fk_credit_type);
		$sql .= " AND entity IN (".getEntity('credits_balance').")";
		$sql .= " FOR UPDATE";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($obj) {
			$newBalance = (float) $obj->balance + (float) $delta;

			if ($newBalance < 0 && !getDolGlobalInt('CREDITMANAGER_ALLOW_NEGATIVE_BALANCE', 0)) {
				$this->error = 'Insufficient balance';
				$this->db->rollback();
				return -2;
			}

			$sqlUpdate = "UPDATE ".$this->db->prefix().$this->table_element;
			$sqlUpdate .= " SET balance = ".((float) $newBalance).", tms = NOW()";
			$sqlUpdate .= " WHERE rowid = ".((int) $obj->rowid);

			if (!$this->db->query($sqlUpdate)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		} else {
			if ((float) $delta < 0 && !getDolGlobalInt('CREDITMANAGER_ALLOW_NEGATIVE_BALANCE', 0)) {
				$this->error = 'Insufficient balance';
				$this->db->rollback();
				return -2;
			}

			$sqlInsert = "INSERT INTO ".$this->db->prefix().$this->table_element;
			$sqlInsert .= " (entity, fk_soc, fk_credit_type, balance, tms)";
			$sqlInsert .= " VALUES (".((int) $conf->entity).", ".((int) $fk_soc).", ".((int) $fk_credit_type).", ";
			$sqlInsert .= ((float) $delta).", NOW())";

			if (!$this->db->query($sqlInsert)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 *	Check if sufficient balance exists
	 *
	 *	@param	int		$fk_soc			Client ID
	 *	@param	int		$fk_credit_type	Credit type ID
	 *	@param	float	$amount			Amount to check
	 *	@return	bool					true if sufficient, false otherwise
	 */
	public function checkSufficientBalance($fk_soc, $fk_credit_type, $amount)
	{
		$currentBalance = $this->getBalance($fk_soc, $fk_credit_type);
		if ($currentBalance < 0) {
			return false;
		}
		return $currentBalance >= $amount;
	}
}
