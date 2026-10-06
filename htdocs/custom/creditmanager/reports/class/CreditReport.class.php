<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/creditmanager/reports/class/CreditReport.class.php
 * \ingroup     creditmanager
 * \brief       Reporting queries for credit consumption/forecast/budget.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
dol_include_once('/creditmanager/lib/creditmanager.lib.php');

class CreditReport extends CommonObject
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @param DoliDB $db
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Build SQL IN list from scalar/array values.
	 *
	 * @param int|array $value
	 * @return string
	 */
	private function sqlInList($value)
	{
		if (is_array($value)) {
			$values = array_filter(array_map('intval', $value), function ($v) {
				return $v > 0;
			});
		} else {
			$v = (int) $value;
			$values = $v > 0 ? array($v) : array();
		}

		return empty($values) ? '' : implode(',', $values);
	}

	/**
	 * Consumption report for a period, optionally filtered.
	 *
	 * @param int     $date_start
	 * @param int     $date_end
	 * @param int|array $fk_soc
	 * @param int|array $fk_credit_type
	 * @return array<int,array<string,mixed>>
	 */
	public function generateConsumptionReport($date_start, $date_end, $fk_soc = 0, $fk_credit_type = 0)
	{
		$rows = array();
		$entityMovement = getEntity('credits_movement');
		$entitySoc = getEntity('societe');
		$entityType = getEntity('credits_type');

		$sql = "SELECT m.fk_soc, s.nom as socname, m.fk_credit_type, t.code as credit_code, t.label as credit_label,";
		$sql .= " DATE_FORMAT(m.date_movement, '%Y-%m') as month_key,";
		$sql .= " SUM(CASE WHEN m.amount < 0 THEN ABS(m.amount) ELSE 0 END) as consumed_hours,";
		$sql .= " SUM(CASE WHEN m.amount > 0 THEN m.amount ELSE 0 END) as added_hours,";
		$sql .= " SUM(m.amount) as net_amount,";
		$sql .= " COUNT(m.rowid) as nb_movements";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = m.fk_soc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = m.fk_credit_type";
		$sql .= " WHERE m.entity IN (".$entityMovement.")";
		$sql .= " AND s.entity IN (".$entitySoc.")";
		$sql .= " AND t.entity IN (".$entityType.")";
		$sql .= " AND m.date_movement >= '".$this->db->idate($date_start)."'";
		$sql .= " AND m.date_movement <= '".$this->db->idate($date_end)."'";

		$socIn = $this->sqlInList($fk_soc);
		if ($socIn !== '') {
			$sql .= " AND m.fk_soc IN (".$socIn.")";
		}
		$typeIn = $this->sqlInList($fk_credit_type);
		if ($typeIn !== '') {
			$sql .= " AND m.fk_credit_type IN (".$typeIn.")";
		}

		$sql .= creditmanagerSqlExcludeCancelledAttributionMovements('m');

		$sql .= " GROUP BY m.fk_soc, s.nom, m.fk_credit_type, t.code, t.label, month_key";
		$sql .= " ORDER BY month_key ASC, s.nom ASC, t.code ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'fk_soc' => (int) $obj->fk_soc,
				'socname' => $obj->socname,
				'fk_credit_type' => (int) $obj->fk_credit_type,
				'credit_code' => $obj->credit_code,
				'credit_label' => $obj->credit_label,
				'month_key' => $obj->month_key,
				'consumed_hours' => (float) $obj->consumed_hours,
				'added_hours' => (float) $obj->added_hours,
				'net_amount' => (float) $obj->net_amount,
				'nb_movements' => (int) $obj->nb_movements,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Forecast balance exhaustion from recent average consumption.
	 *
	 * @param int|array $fk_soc
	 * @param int|array $fk_credit_type
	 * @param array     $filters period_months, fk_project, client_status, alert_threshold, months_min, months_max
	 * @return array<int,array<string,mixed>>
	 */
	public function calculateForecast($fk_soc = 0, $fk_credit_type = 0, $filters = array())
	{
		$rows = array();
		$entityBalance = getEntity('credits_balance');
		$entitySoc = getEntity('societe');
		$entityType = getEntity('credits_type');
		$entityProject = getEntity('project');

		$periodMonths = (int) ($filters['period_months'] ?? 3);
		if (!in_array($periodMonths, array(3, 6, 12), true)) {
			$periodMonths = 3;
		}
		$periodStart = dol_time_plus_duree(dol_now(), -$periodMonths, 'm');

		$sql = "SELECT b.fk_soc, s.nom as socname, s.status as soc_status, s.email as soc_email,";
		$sql .= " b.fk_credit_type, t.code as credit_code, t.label as credit_label,";
		$sql .= " b.balance as current_balance,";
		$sql .= " COALESCE(cons.avg_monthly_consumption, 0) as avg_monthly_consumption";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_balance as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = b.fk_soc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = b.fk_credit_type";
		$sql .= " LEFT JOIN (";
		$sql .= " SELECT m.fk_soc, m.fk_credit_type, SUM(ABS(m.amount)) / ".$periodMonths." as avg_monthly_consumption";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."element_time as et ON et.rowid = m.fk_element_time";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
		$sql .= " WHERE m.entity IN (".getEntity('credits_movement').")";
		$sql .= " AND m.amount < 0";
		$sql .= " AND m.date_movement >= '".$this->db->idate($periodStart)."'";
		if (!empty($filters['scope'])) {
			dol_include_once('/custom/creditmanager/lib/creditmanager.lib.php');
			$sql .= creditmanagerReportScopeWhereSql($filters['scope'], 'm', 'pr');
		}
		$sql .= " GROUP BY m.fk_soc, m.fk_credit_type";
		$sql .= " ) as cons ON cons.fk_soc = b.fk_soc AND cons.fk_credit_type = b.fk_credit_type";
		$sql .= " WHERE b.entity IN (".$entityBalance.")";
		$sql .= " AND s.entity IN (".$entitySoc.")";
		$sql .= " AND t.entity IN (".$entityType.")";
		$sql .= " AND t.active = 1";

		$socIn = $this->sqlInList($fk_soc);
		if ($socIn !== '') {
			$sql .= " AND b.fk_soc IN (".$socIn.")";
		}
		$typeIn = $this->sqlInList($fk_credit_type);
		if ($typeIn !== '') {
			$sql .= " AND b.fk_credit_type IN (".$typeIn.")";
		}

		$projectIn = $this->sqlInList($filters['fk_project'] ?? 0);
		if ($projectIn !== '') {
			$sql .= " AND b.fk_soc IN (";
			$sql .= " SELECT DISTINCT pr.fk_soc FROM ".MAIN_DB_PREFIX."projet as pr";
			$sql .= " WHERE pr.rowid IN (".$projectIn.")";
			$sql .= " AND pr.entity IN (".$entityProject.")";
			$sql .= " )";
		}

		if (isset($filters['client_status']) && $filters['client_status'] !== '' && is_numeric($filters['client_status'])) {
			$sql .= " AND s.status = ".((int) $filters['client_status']);
		}

		if (!empty($filters['scope'])) {
			dol_include_once('/custom/creditmanager/lib/creditmanager.lib.php');
			$sql .= creditmanagerReportScopeBalanceWhereSql($filters['scope'], 'b');
		}

		$sql .= " ORDER BY s.nom ASC, t.code ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}

		$alertThreshold = $filters['alert_threshold'] ?? '';
		$monthsMin = isset($filters['months_min']) && $filters['months_min'] !== '' && is_numeric($filters['months_min']) ? (float) $filters['months_min'] : null;
		$monthsMax = isset($filters['months_max']) && $filters['months_max'] !== '' && is_numeric($filters['months_max']) ? (float) $filters['months_max'] : null;

		while ($obj = $this->db->fetch_object($resql)) {
			$avg = (float) $obj->avg_monthly_consumption;
			$balance = (float) $obj->current_balance;
			$months = $avg > 0 ? ($balance / $avg) : null;

			$status = $this->forecastStatusFromMonths($months);

			if ($alertThreshold === 'critical' && $status !== 'critical') {
				continue;
			}
			if ($alertThreshold === 'warning' && !in_array($status, array('warning', 'critical'), true)) {
				continue;
			}
			if ($monthsMin !== null && ($months === null || $months < $monthsMin)) {
				continue;
			}
			if ($monthsMax !== null && ($months === null || $months > $monthsMax)) {
				continue;
			}

			$rows[] = array(
				'fk_soc' => (int) $obj->fk_soc,
				'socname' => $obj->socname,
				'soc_status' => (int) $obj->soc_status,
				'soc_email' => $obj->soc_email,
				'fk_credit_type' => (int) $obj->fk_credit_type,
				'credit_code' => $obj->credit_code,
				'credit_label' => $obj->credit_label,
				'current_balance' => $balance,
				'avg_monthly_consumption' => $avg,
				'months_remaining' => $months,
				'status' => $status,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * @param float|null $months
	 * @return string safe|warning|critical
	 */
	private function forecastStatusFromMonths($months)
	{
		if ($months !== null && $months < 1) {
			return 'critical';
		}
		if ($months !== null && $months < 3) {
			return 'warning';
		}
		return 'safe';
	}

	/**
	 * Compare yearly budget (attributions) versus real usage (debits).
	 *
	 * @param int   $year
	 * @param array $filters fk_soc, fk_credit_type, fk_project, variance_min, variance_max, usage_status
	 * @return array<int,array<string,mixed>>
	 */
	public function compareBudgetVsReal($year, $filters = array())
	{
		$rows = array();
		$start = dol_mktime(0, 0, 0, 1, 1, (int) $year);
		$end = dol_mktime(23, 59, 59, 12, 31, (int) $year);
		$entityProject = getEntity('project');

		$sql = "SELECT m.fk_soc, s.nom as socname, m.fk_credit_type, t.code as credit_code, t.label as credit_label,";
		$sql .= " SUM(CASE WHEN m.amount > 0 THEN m.amount ELSE 0 END) as budget_hours,";
		$sql .= " SUM(CASE WHEN m.amount < 0 THEN ABS(m.amount) ELSE 0 END) as real_hours";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = m.fk_soc";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."credits_types as t ON t.rowid = m.fk_credit_type";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."element_time as et ON et.rowid = m.fk_element_time";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."projet as pr ON pr.rowid = tsk.fk_projet AND pr.entity IN (".$entityProject.")";
		$sql .= " WHERE m.entity IN (".getEntity('credits_movement').")";
		$sql .= " AND s.entity IN (".getEntity('societe').")";
		$sql .= " AND t.entity IN (".getEntity('credits_type').")";
		$sql .= " AND m.date_movement >= '".$this->db->idate($start)."'";
		$sql .= " AND m.date_movement <= '".$this->db->idate($end)."'";

		$socIn = $this->sqlInList($filters['fk_soc'] ?? 0);
		if ($socIn !== '') {
			$sql .= " AND m.fk_soc IN (".$socIn.")";
		}
		$typeIn = $this->sqlInList($filters['fk_credit_type'] ?? 0);
		if ($typeIn !== '') {
			$sql .= " AND m.fk_credit_type IN (".$typeIn.")";
		}
		$projectIn = $this->sqlInList($filters['fk_project'] ?? 0);
		if ($projectIn !== '') {
			dol_include_once('/custom/creditmanager/lib/creditmanager.lib.php');
			$projectIds = array_filter(array_map('intval', explode(',', $projectIn)));
			$sql .= " AND ".creditmanagerReportProjectIdsWhereCondition($projectIds, 'm', 'pr');
		} elseif (!empty($filters['scope'])) {
			dol_include_once('/custom/creditmanager/lib/creditmanager.lib.php');
			$sql .= creditmanagerReportScopeWhereSql($filters['scope'], 'm', 'pr');
		}

		$sql .= " GROUP BY m.fk_soc, s.nom, m.fk_credit_type, t.code, t.label";
		$sql .= " ORDER BY s.nom ASC, t.code ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}

		$varianceMin = isset($filters['variance_min']) && $filters['variance_min'] !== '' && is_numeric($filters['variance_min']) ? (float) $filters['variance_min'] : null;
		$varianceMax = isset($filters['variance_max']) && $filters['variance_max'] !== '' && is_numeric($filters['variance_max']) ? (float) $filters['variance_max'] : null;
		$usageStatus = $filters['usage_status'] ?? '';

		while ($obj = $this->db->fetch_object($resql)) {
			$budget = (float) $obj->budget_hours;
			$real = (float) $obj->real_hours;
			$diff = $budget - $real;
			$usage = $budget > 0 ? (($real / $budget) * 100) : ($real > 0 ? 100 : 0);
			$status = $this->budgetUsageStatus($usage);

			if ($varianceMin !== null && $usage < $varianceMin) {
				continue;
			}
			if ($varianceMax !== null && $usage > $varianceMax) {
				continue;
			}
			if ($usageStatus === 'ok' && $status !== 'ok') {
				continue;
			}
			if ($usageStatus === 'warning' && $status !== 'warning') {
				continue;
			}
			if ($usageStatus === 'over' && $status !== 'over') {
				continue;
			}

			$rows[] = array(
				'fk_soc' => (int) $obj->fk_soc,
				'socname' => $obj->socname,
				'fk_credit_type' => (int) $obj->fk_credit_type,
				'credit_code' => $obj->credit_code,
				'credit_label' => $obj->credit_label,
				'budget_hours' => $budget,
				'real_hours' => $real,
				'difference_hours' => $diff,
				'usage_percent' => $usage,
				'variance_percent' => $usage,
				'status' => $status,
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Monthly drill-down for one client / credit type.
	 *
	 * @param int $year
	 * @param int $fk_soc
	 * @param int $fk_credit_type
	 * @return array<int,array<string,mixed>>
	 */
	public function getBudgetVsRealMonthlyDetail($year, $fk_soc, $fk_credit_type)
	{
		$rows = array();
		$start = dol_mktime(0, 0, 0, 1, 1, (int) $year);
		$end = dol_mktime(23, 59, 59, 12, 31, (int) $year);

		$sql = "SELECT DATE_FORMAT(m.date_movement, '%Y-%m') as month_key,";
		$sql .= " SUM(CASE WHEN m.amount > 0 THEN m.amount ELSE 0 END) as budget_hours,";
		$sql .= " SUM(CASE WHEN m.amount < 0 THEN ABS(m.amount) ELSE 0 END) as real_hours";
		$sql .= " FROM ".MAIN_DB_PREFIX."credits_movements as m";
		$sql .= " WHERE m.entity IN (".getEntity('credits_movement').")";
		$sql .= " AND m.fk_soc = ".((int) $fk_soc);
		$sql .= " AND m.fk_credit_type = ".((int) $fk_credit_type);
		$sql .= " AND m.date_movement >= '".$this->db->idate($start)."'";
		$sql .= " AND m.date_movement <= '".$this->db->idate($end)."'";
		$sql .= " GROUP BY month_key";
		$sql .= " ORDER BY month_key ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$budget = (float) $obj->budget_hours;
			$real = (float) $obj->real_hours;
			$usage = $budget > 0 ? (($real / $budget) * 100) : ($real > 0 ? 100 : 0);
			$rows[] = array(
				'month_key' => $obj->month_key,
				'budget_hours' => $budget,
				'real_hours' => $real,
				'difference_hours' => $budget - $real,
				'usage_percent' => $usage,
				'status' => $this->budgetUsageStatus($usage),
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * @param float $usagePercent
	 * @return string ok|warning|over
	 */
	private function budgetUsageStatus($usagePercent)
	{
		if ($usagePercent >= 90) {
			return 'over';
		}
		if ($usagePercent >= 75) {
			return 'warning';
		}
		return 'ok';
	}
}
