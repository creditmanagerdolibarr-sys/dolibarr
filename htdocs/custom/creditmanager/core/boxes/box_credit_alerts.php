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
 * \file       htdocs/custom/creditmanager/core/boxes/box_credit_alerts.php
 * \ingroup    creditmanager
 * \brief      Widget: clients in credit alert (warning/critical) with filters and auto-refresh
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Class to manage the credit alerts widget
 */
class box_credit_alerts extends ModeleBoxes
{
	/**
	 * @var string
	 */
	public $boxcode = 'creditmanager_credit_alerts';

	/**
	 * @var string
	 */
	public $boximg = 'object_warning';

	/**
	 * @var string
	 */
	public $boxlabel = 'CreditBoxCreditAlerts';

	/**
	 * @var string
	 */
	public $lang = 'creditmanager@creditmanager';

	/**
	 * @var string[]
	 */
	public $depends = array('creditmanager');

	/**
	 * Constructor
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $param More parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user, $langs;

		parent::__construct($db, $param);

		$langs->loadLangs(array('creditmanager@creditmanager', 'companies', 'projects', 'boxes'));

		$this->hidden = true;
		if (isModEnabled('creditmanager')) {
			require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
			$this->hidden = !creditmanagerCanAccessReports($user);
		}

		$this->urltoaddentry = dol_buildpath('/custom/creditmanager/reports/forecast.php', 1);
		$this->msgNoRecords = 'CreditBoxCreditAlertsEmpty';
	}

	/**
	 * Read a cookie/POST filter value and sanitize it as positive int (0 = all).
	 *
	 * @param string $cookieName
	 * @param string $postName
	 * @return int
	 */
	private function getFilterInt($cookieName, $postName)
	{
		$value = 0;
		if (GETPOSTISSET($postName)) {
			$value = GETPOSTINT($postName);
		} elseif (!empty($_COOKIE[$cookieName])) {
			$value = (int) preg_replace('/[^0-9]/', '', (string) $_COOKIE[$cookieName]);
		}
		return $value > 0 ? $value : 0;
	}

	/**
	 * Read alert threshold filter from POST/cookie.
	 *
	 * @param string $cookieName
	 * @return string ''|warning|critical
	 */
	private function getThresholdFilter($cookieName)
	{
		$allowed = array('', 'warning', 'critical');
		$value = '';
		if (GETPOSTISSET('box_alerts_threshold')) {
			$value = GETPOST('box_alerts_threshold', 'aZ09');
		} elseif (isset($_COOKIE[$cookieName])) {
			$value = preg_replace('/[^a-z]/', '', (string) $_COOKIE[$cookieName]);
		}
		return in_array($value, $allowed, true) ? $value : '';
	}

	/**
	 * Read sort field from POST/cookie.
	 *
	 * @param string $cookieName
	 * @return string
	 */
	private function getSortField($cookieName)
	{
		$allowed = array('balance', 'percent', 'client');
		$value = 'percent';
		if (GETPOSTISSET('box_alerts_sort')) {
			$value = GETPOST('box_alerts_sort', 'aZ09');
		} elseif (!empty($_COOKIE[$cookieName])) {
			$value = preg_replace('/[^a-z]/', '', (string) $_COOKIE[$cookieName]);
		}
		return in_array($value, $allowed, true) ? $value : 'percent';
	}

	/**
	 * Read auto-refresh interval (seconds) from POST/cookie.
	 *
	 * @param string $cookieName
	 * @return int
	 */
	private function getRefreshInterval($cookieName)
	{
		$allowed = array(0, 60, 120, 300);
		$value = 60;
		if (GETPOSTISSET('box_alerts_refresh')) {
			$value = GETPOSTINT('box_alerts_refresh');
		} elseif (isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] !== '') {
			$value = (int) preg_replace('/[^0-9]/', '', (string) $_COOKIE[$cookieName]);
		}
		return in_array($value, $allowed, true) ? $value : 60;
	}

	/**
	 * Persist filter cookies via JS (SameSite=Lax, 30 days).
	 *
	 * @param array<string,int|string> $cookies
	 * @return string
	 */
	private function buildCookieScript(array $cookies)
	{
		global $conf;

		if (empty($conf->use_javascript_ajax)) {
			return '';
		}

		$js = '<script nonce="'.getNonce().'">'."\n";
		$js .= 'date = new Date(); date.setTime(date.getTime()+(30*86400000));'."\n";
		foreach ($cookies as $name => $val) {
			$js .= 'document.cookie = "'.dol_escape_js((string) $name).'='.dol_escape_js((string) $val).'; expires= " + date.toGMTString() + "; path=/ ; SameSite=Lax";'."\n";
		}
		$js .= '</script>';
		return $js;
	}

	/**
	 * Compute remaining % = balance / total credited (positive movements) * 100.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function enrichWithRemainingPercent(array $rows)
	{
		if (empty($rows)) {
			return $rows;
		}

		$pairs = array();
		foreach ($rows as $r) {
			$pairs[] = ((int) $r['fk_soc']).'-'.((int) $r['fk_credit_type']);
		}
		$pairs = array_values(array_unique($pairs));

		$refs = array();
		$sql = "SELECT m.fk_soc, m.fk_credit_type, SUM(m.amount) as total_credited";
		$sql .= " FROM ".$this->db->prefix()."credits_movements as m";
		$sql .= " WHERE m.entity IN (".getEntity('credits_movement').")";
		$sql .= " AND m.amount > 0";
		$sql .= " AND (";
		$or = array();
		foreach ($pairs as $pair) {
			list($socId, $typeId) = array_map('intval', explode('-', $pair, 2));
			$or[] = "(m.fk_soc = ".$socId." AND m.fk_credit_type = ".$typeId.")";
		}
		$sql .= implode(' OR ', $or);
		$sql .= ") GROUP BY m.fk_soc, m.fk_credit_type";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$key = ((int) $obj->fk_soc).'-'.((int) $obj->fk_credit_type);
				$refs[$key] = (float) $obj->total_credited;
			}
			$this->db->free($resql);
		}

		foreach ($rows as $i => $r) {
			$key = ((int) $r['fk_soc']).'-'.((int) $r['fk_credit_type']);
			$ref = isset($refs[$key]) ? (float) $refs[$key] : 0.0;
			$rows[$i]['reference_value'] = $ref;
			$rows[$i]['percent_remaining'] = ($ref > 0) ? (((float) $r['current_balance'] / $ref) * 100.0) : null;
		}

		return $rows;
	}

	/**
	 * Sort alert rows.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param string                         $sortField balance|percent|client
	 * @return array<int,array<string,mixed>>
	 */
	private function sortAlertRows(array $rows, $sortField)
	{
		usort($rows, function ($a, $b) use ($sortField) {
			// Critical before warning as secondary key
			$order = array('critical' => 0, 'warning' => 1, 'safe' => 2);
			$oa = $order[$a['status']] ?? 9;
			$ob = $order[$b['status']] ?? 9;

			if ($sortField === 'client') {
				$cmp = strcasecmp((string) $a['socname'], (string) $b['socname']);
				if ($cmp !== 0) {
					return $cmp;
				}
				return $oa <=> $ob;
			}

			if ($sortField === 'balance') {
				$cmp = ((float) $a['current_balance']) <=> ((float) $b['current_balance']);
				if ($cmp !== 0) {
					return $cmp;
				}
				return $oa <=> $ob;
			}

			// percent (default): lowest remaining % first; nulls last
			$pa = $a['percent_remaining'];
			$pb = $b['percent_remaining'];
			if ($pa === null && $pb === null) {
				return $oa <=> $ob;
			}
			if ($pa === null) {
				return 1;
			}
			if ($pb === null) {
				return -1;
			}
			$cmp = ((float) $pa) <=> ((float) $pb);
			if ($cmp !== 0) {
				return $cmp;
			}
			return $oa <=> $ob;
		});

		return $rows;
	}

	/**
	 * Load data for box to show them later
	 *
	 * @param int $max Maximum number of records to load
	 * @return void
	 */
	public function loadBox($max = 8)
	{
		global $user, $langs, $conf;

		$this->max = $max;

		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/reports/class/CreditReport.class.php';

		$form = new Form($this->db);
		$forecastUrl = dol_buildpath('/custom/creditmanager/reports/forecast.php', 1);

		$cookieThreshold = 'DOLUSER_boxfilter_cm_alerts_threshold';
		$cookieType = 'DOLUSER_boxfilter_cm_alerts_type';
		$cookieProject = 'DOLUSER_boxfilter_cm_alerts_project';
		$cookieSort = 'DOLUSER_boxfilter_cm_alerts_sort';
		$cookieRefresh = 'DOLUSER_boxfilter_cm_alerts_refresh';

		$filterThreshold = $this->getThresholdFilter($cookieThreshold);
		$filterType = $this->getFilterInt($cookieType, 'box_alerts_typeid');
		$filterProject = $this->getFilterInt($cookieProject, 'box_alerts_projectid');
		$sortField = $this->getSortField($cookieSort);
		$refreshInterval = $this->getRefreshInterval($cookieRefresh);

		$scope = creditmanagerGetReportScope($this->db, $user);

		$report = new CreditReport($this->db);
		$forecastFilters = array(
			'period_months' => 3,
			'fk_project' => $filterProject > 0 ? array($filterProject) : array(),
			'alert_threshold' => $filterThreshold,
			'scope' => $scope,
			'client_status' => '1', // active clients only
		);
		$typeFilter = $filterType > 0 ? $filterType : 0;
		$alertRows = $report->calculateForecast(0, $typeFilter, $forecastFilters);

		// When threshold is empty, keep warning + critical only
		if ($filterThreshold === '') {
			$alertRows = array_values(array_filter($alertRows, function ($r) {
				return in_array($r['status'], array('warning', 'critical'), true);
			}));
		}

		$alertRows = $this->enrichWithRemainingPercent($alertRows);
		$alertRows = $this->sortAlertRows($alertRows, $sortField);

		$alertTotal = count($alertRows);

		$badgeClass = 'badge';
		if ($alertTotal > 0) {
			$hasCritical = false;
			foreach ($alertRows as $r) {
				if (($r['status'] ?? '') === 'critical') {
					$hasCritical = true;
					break;
				}
			}
			$badgeClass = $hasCritical ? 'badge badge-danger' : 'badge badge-warning';
		}
		$badge = ' <span class="'.$badgeClass.'">'.$alertTotal.'</span>';

		$textHead = $langs->trans('CreditBoxCreditAlertsTitle').$badge;
		$this->info_box_head = array(
			'text' => $textHead,
			'limit' => 0,
			'sublink' => $forecastUrl,
			'subtext' => $langs->trans('CreditBoxCreditAlertsOpenForecast'),
			'subpicto' => 'object_warning',
			'subclass' => 'linkobject',
			'target' => '',
		);

		if (!creditmanagerCanAccessReports($user)) {
			$this->info_box_contents[0][0] = array(
				'td' => 'class="nohover left"',
				'text' => '<span class="opacitymedium">'.$langs->trans('ReadPermissionNotAllowed').'</span>',
				'asis' => 1,
			);
			return;
		}

		$entityProject = getEntity('project');
		$entityType = getEntity('credits_type');

		$projectOptions = array();
		$allowedProjectIds = creditmanagerGetReportScopeProjectIdsForSelect($this->db, $scope, $entityProject);
		$sqlPr = "SELECT pr.rowid, pr.ref, pr.title FROM ".$this->db->prefix()."projet as pr";
		$sqlPr .= " WHERE pr.entity IN (".$entityProject.")";
		$sqlPr .= " AND pr.fk_statut = 1";
		if (!empty($allowedProjectIds)) {
			$sqlPr .= " AND pr.rowid IN (".implode(',', array_map('intval', $allowedProjectIds)).")";
		} elseif ($scope['type'] !== 'all') {
			$sqlPr .= " AND 1 = 0";
		}
		$sqlPr .= " ORDER BY pr.ref ASC";
		$sqlPr .= $this->db->plimit(200, 0);
		$resPr = $this->db->query($sqlPr);
		if ($resPr) {
			while ($obj = $this->db->fetch_object($resPr)) {
				$projectOptions[(int) $obj->rowid] = trim($obj->ref.' '.$obj->title);
			}
			$this->db->free($resPr);
		}

		$typeOptions = array();
		$sqlType = "SELECT rowid, code, label FROM ".$this->db->prefix()."credits_types";
		$sqlType .= " WHERE entity IN (".$entityType.") AND active = 1 ORDER BY code ASC";
		$resType = $this->db->query($sqlType);
		if ($resType) {
			while ($obj = $this->db->fetch_object($resType)) {
				$typeOptions[(int) $obj->rowid] = $obj->code.(!empty($obj->label) ? ' - '.$obj->label : '');
			}
			$this->db->free($resType);
		}

		$thresholdOptions = array(
			'' => $langs->trans('CreditDashboardAlertAll'),
			'warning' => $langs->trans('CreditReportForecastStatusWarning'),
			'critical' => $langs->trans('CreditReportForecastStatusCritical'),
		);

		$sortOptions = array(
			'balance' => $langs->trans('CreditBoxCreditAlertsSortBalance'),
			'percent' => $langs->trans('CreditBoxCreditAlertsSortPercent'),
			'client' => $langs->trans('ThirdParty'),
		);

		$refreshOptions = array(
			0 => $langs->trans('CreditDashboardRefreshOff'),
			60 => $langs->trans('CreditDashboardRefresh1min'),
			120 => $langs->trans('CreditDashboardRefresh2min'),
			300 => $langs->trans('CreditDashboardRefresh5min'),
		);

		// Filter form (toggle via box filter icon)
		$boxcontent = '';
		$boxcontent .= '<div id="ancor-idfilter'.$this->boxcode.'" style="display: block; position: absolute; margin-top: -100px"></div>'."\n";
		$boxcontent .= '<div id="idfilter'.$this->boxcode.'" class="center hideobject showiffilter'.$this->boxcode.'">'."\n";
		$boxcontent .= '<form class="flat formboxfilter" method="POST" action="'.$_SERVER['PHP_SELF'].'#ancor-idfilter'.$this->boxcode.'">'."\n";
		$boxcontent .= '<input type="hidden" name="token" value="'.newToken().'">'."\n";

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditReportForecastAlertThreshold').'</label><br>';
		$boxcontent .= $form->selectarray('box_alerts_threshold', $thresholdOptions, $filterThreshold, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditType').'</label><br>';
		$boxcontent .= $form->selectarray('box_alerts_typeid', $typeOptions, $filterType, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('Project').'</label><br>';
		$boxcontent .= $form->selectarray('box_alerts_projectid', $projectOptions, $filterProject, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditBoxCreditAlertsSort').'</label><br>';
		$boxcontent .= $form->selectarray('box_alerts_sort', $sortOptions, $sortField, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditDashboardAutoRefresh').'</label><br>';
		$boxcontent .= $form->selectarray('box_alerts_refresh', $refreshOptions, $refreshInterval, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<button type="submit" class="button buttongen button-save">'.$langs->trans('Refresh').'</button>';
		$boxcontent .= ' <a class="button buttongen" href="'.dol_escape_htmltag($forecastUrl).'">'.$langs->trans('CreditBoxCreditAlertsOpenForecast').'</a>';
		$boxcontent .= '</form>'."\n";
		$boxcontent .= '</div>'."\n";

		if (!empty($conf->use_javascript_ajax)) {
			$boxcontent .= '<script nonce="'.getNonce().'" type="text/javascript">
				jQuery(document).ready(function() {
					jQuery("#idsubimg'.$this->boxcode.'").click(function() {
						jQuery(".showiffilter'.$this->boxcode.'").toggle();
					});
				});
			</script>';
			$boxcontent .= $this->buildCookieScript(array(
				$cookieThreshold => $filterThreshold,
				$cookieType => $filterType,
				$cookieProject => $filterProject,
				$cookieSort => $sortField,
				$cookieRefresh => $refreshInterval,
			));
			if ($refreshInterval > 0) {
				$boxcontent .= '<script nonce="'.getNonce().'">
					(function() {
						var ms = '.((int) $refreshInterval * 1000).';
						setTimeout(function() { window.location.reload(); }, ms);
					})();
				</script>';
			}
		}

		// Expose filter toggle on the head
		$this->info_box_head['subtext'] = $langs->trans('Filter');
		$this->info_box_head['subpicto'] = 'filter.png';
		$this->info_box_head['subclass'] = 'linkobject boxfilter';
		$this->info_box_head['target'] = 'none';
		$this->info_box_head['text'] = $textHead.' <a href="'.dol_escape_htmltag($forecastUrl).'" title="'.dol_escape_htmltag($langs->trans('CreditBoxCreditAlertsOpenForecast')).'">'.img_picto($langs->trans('CreditBoxCreditAlertsOpenForecast'), 'object_warning', 'class="paddingleft"').'</a>';

		$line = 0;
		$this->info_box_contents[$line][] = array(
			'tr' => 'class="nohover showiffilter'.$this->boxcode.' hideobject"',
			'td' => 'class="nohover" colspan="5"',
			'textnoformat' => $boxcontent,
		);
		$line++;

		// Header columns
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('ThirdParty'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('CreditType'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre right"',
			'text' => $langs->trans('CreditReportForecastCurrentBalance'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre right"',
			'text' => $langs->trans('CreditBoxCreditAlertsPercentRemaining'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('CreditReportForecastStatus'),
		);
		$line++;

		$shown = 0;
		foreach ($alertRows as $r) {
			if ($shown >= $this->max) {
				break;
			}

			$status = $r['status'] ?? 'safe';
			$statusClass = $status === 'critical' ? 'error' : ($status === 'warning' ? 'warning' : '');
			$statusBadge = $status === 'critical' ? 'badge badge-danger' : ($status === 'warning' ? 'badge badge-warning' : 'badge');
			$clientUrl = dol_buildpath('/custom/creditmanager/tabs/thirdpartyCredits.php', 1).'?socid='.((int) $r['fk_soc']);
			$typeLabel = trim(($r['credit_code'] ?? '').(!empty($r['credit_label']) ? ' - '.$r['credit_label'] : ''));
			$percentLabel = ($r['percent_remaining'] === null)
				? '-'
				: creditmanagerFormatAmount($r['percent_remaining']).'%';

			$this->info_box_contents[$line][] = array(
				'td' => 'class="tdoverflowmax120"',
				'text' => dol_escape_htmltag($r['socname'] ?: ''),
				'url' => $clientUrl,
				'maxlength' => 40,
			);
			$this->info_box_contents[$line][] = array(
				'td' => 'class="tdoverflowmax100"',
				'text' => dol_escape_htmltag($typeLabel),
				'maxlength' => 30,
			);
			$this->info_box_contents[$line][] = array(
				'td' => 'class="nowrap right'.($statusClass !== '' ? ' '.$statusClass : '').'"',
				'text' => creditmanagerFormatAmount($r['current_balance']),
			);
			$this->info_box_contents[$line][] = array(
				'td' => 'class="nowrap right'.($statusClass !== '' ? ' '.$statusClass : '').'"',
				'text' => $percentLabel,
			);
			$this->info_box_contents[$line][] = array(
				'td' => 'class="nowrap"',
				'text' => '<span class="'.$statusBadge.'">'.$langs->trans('CreditReportForecastStatus'.ucfirst($status)).'</span>',
				'asis' => 1,
			);

			$line++;
			$shown++;
		}

		if ($alertTotal === 0) {
			$this->info_box_contents[$line][] = array(
				'td' => 'class="center opacitymedium" colspan="5"',
				'text' => $langs->trans('CreditBoxCreditAlertsEmpty'),
			);
		} elseif ($alertTotal > $shown) {
			$seeAllUrl = $forecastUrl;
			if ($filterThreshold !== '') {
				$seeAllUrl .= '?alert_threshold='.urlencode($filterThreshold);
			} else {
				$seeAllUrl .= '?alert_threshold=warning';
			}
			$this->info_box_contents[$line][] = array(
				'td' => 'class="right" colspan="5"',
				'text' => '<a href="'.dol_escape_htmltag($seeAllUrl).'">'.$langs->trans('CreditBoxCreditAlertsSeeAll', $alertTotal).'</a>',
				'asis' => 1,
			);
		}
	}

	/**
	 * Method to show box
	 *
	 * @param ?array $head     Array with properties of box title
	 * @param ?array $contents Array with properties of box lines
	 * @param int    $nooutput No print, only return string
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
