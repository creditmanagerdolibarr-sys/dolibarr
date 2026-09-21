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
 * \file       htdocs/custom/creditmanager/core/boxes/box_pending_timesheets.php
 * \ingroup    creditmanager
 * \brief      Widget: pending timesheets awaiting PM approval (filters + auto-refresh)
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Class to manage the pending timesheets widget
 */
class box_pending_timesheets extends ModeleBoxes
{
	/**
	 * @var string
	 */
	public $boxcode = 'creditmanager_pending_timesheets';

	/**
	 * @var string
	 */
	public $boximg = 'object_projecttask';

	/**
	 * @var string
	 */
	public $boxlabel = 'CreditBoxPendingTimesheets';

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
			$this->hidden = !(creditmanagerCanApproveTimesheets($user) || creditmanagerCanManageAdmin($user));
		}

		$this->urltoaddentry = dol_buildpath('/custom/creditmanager/timesheets/approve.php', 1);
		$this->msgNoRecords = 'CreditBoxPendingTimesheetsEmpty';
	}

	/**
	 * Read a cookie filter value and sanitize it as positive int (0 = all).
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
	 * Read sort field from POST/cookie.
	 *
	 * @param string $cookieName
	 * @return string
	 */
	private function getSortField($cookieName)
	{
		$allowed = array('date', 'project', 'client', 'hours');
		$value = 'date';
		if (GETPOSTISSET('box_pending_sort')) {
			$value = GETPOST('box_pending_sort', 'aZ09');
		} elseif (!empty($_COOKIE[$cookieName])) {
			$value = preg_replace('/[^a-z]/', '', (string) $_COOKIE[$cookieName]);
		}
		return in_array($value, $allowed, true) ? $value : 'date';
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
		if (GETPOSTISSET('box_pending_refresh')) {
			$value = GETPOSTINT('box_pending_refresh');
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

		$form = new Form($this->db);
		$approveUrl = dol_buildpath('/custom/creditmanager/timesheets/approve.php', 1);

		$cookieSoc = 'DOLUSER_boxfilter_cm_pending_soc';
		$cookieProject = 'DOLUSER_boxfilter_cm_pending_project';
		$cookieType = 'DOLUSER_boxfilter_cm_pending_type';
		$cookieSort = 'DOLUSER_boxfilter_cm_pending_sort';
		$cookieRefresh = 'DOLUSER_boxfilter_cm_pending_refresh';

		$filterSoc = $this->getFilterInt($cookieSoc, 'box_pending_socid');
		$filterProject = $this->getFilterInt($cookieProject, 'box_pending_projectid');
		$filterType = $this->getFilterInt($cookieType, 'box_pending_typeid');
		$sortField = $this->getSortField($cookieSort);
		$refreshInterval = $this->getRefreshInterval($cookieRefresh);

		$scope = creditmanagerGetReportScope($this->db, $user);
		creditmanagerValidateFinancialScopeSocId($scope, $filterSoc, $this->db);

		// Count all pending (badge) with same scope + filters
		$sqlCount = "SELECT COUNT(DISTINCT et.rowid) as nb";
		$sqlFrom = " FROM ".$this->db->prefix()."element_time as et";
		$sqlFrom .= " LEFT JOIN ".$this->db->prefix()."credits_types as ct ON ct.rowid = et.fk_credit_type AND ct.entity IN (".getEntity('credits_type').")";
		$sqlFrom .= " LEFT JOIN ".$this->db->prefix()."projet_task as tsk ON tsk.rowid = et.fk_element AND et.elementtype = 'task'";
		$sqlFrom .= " LEFT JOIN ".$this->db->prefix()."projet as pr ON pr.rowid = tsk.fk_projet";
		$sqlFrom .= " LEFT JOIN ".$this->db->prefix()."societe as s ON s.rowid = pr.fk_soc";
		$sqlWhere = " WHERE et.elementtype = 'task'";
		$sqlWhere .= " AND et.fk_element > 0";
		$sqlWhere .= " AND UPPER(TRIM(et.credit_status)) = 'SUBMITTED'";
		$sqlWhere .= " AND (et.credit_debit_reference IS NULL OR et.credit_debit_reference = '')";
		$sqlWhere .= creditmanagerTimesheetScopeProjectWhereSql($this->db, $user, 'pr');

		if ($filterSoc > 0) {
			$sqlWhere .= " AND pr.fk_soc = ".((int) $filterSoc);
		}
		if ($filterProject > 0) {
			$sqlWhere .= " AND pr.rowid = ".((int) $filterProject);
		}
		if ($filterType > 0) {
			$sqlWhere .= " AND et.fk_credit_type = ".((int) $filterType);
		}

		$pendingTotal = 0;
		$resCount = $this->db->query($sqlCount.$sqlFrom.$sqlWhere);
		if ($resCount) {
			$objCount = $this->db->fetch_object($resCount);
			$pendingTotal = (int) ($objCount->nb ?? 0);
			$this->db->free($resCount);
		}

		$badge = $pendingTotal > 0
			? ' <span class="badge badge-warning">'.$pendingTotal.'</span>'
			: ' <span class="badge">0</span>';

		$textHead = $langs->trans('CreditBoxPendingTimesheetsTitle').$badge;
		$this->info_box_head = array(
			'text' => $textHead,
			'limit' => 0,
			'sublink' => $approveUrl,
			'subtext' => $langs->trans('CreditBoxPendingTimesheetsOpenApprove'),
			'subpicto' => 'object_projecttask',
			'subclass' => 'linkobject',
			'target' => '',
		);

		if (!(creditmanagerCanApproveTimesheets($user) || creditmanagerCanManageAdmin($user))) {
			$this->info_box_contents[0][0] = array(
				'td' => 'class="nohover left"',
				'text' => '<span class="opacitymedium">'.$langs->trans('ReadPermissionNotAllowed').'</span>',
				'asis' => 1,
			);
			return;
		}

		// Build filter dropdown data (scoped)
		$entitySoc = getEntity('societe');
		$entityProject = getEntity('project');
		$entityType = getEntity('credits_type');

		$socOptions = array();
		$allowedSocIds = creditmanagerGetReportScopeSocIdsForSelect($this->db, $scope, $entitySoc);
		$sqlSoc = "SELECT DISTINCT s.rowid, s.nom";
		$sqlSoc .= " FROM ".$this->db->prefix()."societe as s";
		$sqlSoc .= " INNER JOIN ".$this->db->prefix()."projet as pr ON pr.fk_soc = s.rowid";
		$sqlSoc .= " INNER JOIN ".$this->db->prefix()."projet_task as tsk ON tsk.fk_projet = pr.rowid";
		$sqlSoc .= " INNER JOIN ".$this->db->prefix()."element_time as et ON et.fk_element = tsk.rowid AND et.elementtype = 'task'";
		$sqlSoc .= " WHERE s.entity IN (".$entitySoc.")";
		$sqlSoc .= " AND UPPER(TRIM(et.credit_status)) = 'SUBMITTED'";
		$sqlSoc .= creditmanagerTimesheetScopeProjectWhereSql($this->db, $user, 'pr');
		if (!empty($allowedSocIds) && $scope['type'] !== 'all') {
			$sqlSoc .= " AND s.rowid IN (".implode(',', array_map('intval', $allowedSocIds)).")";
		}
		$sqlSoc .= " ORDER BY s.nom ASC";
		$sqlSoc .= $this->db->plimit(200, 0);
		$resSoc = $this->db->query($sqlSoc);
		if ($resSoc) {
			while ($obj = $this->db->fetch_object($resSoc)) {
				$socOptions[(int) $obj->rowid] = $obj->nom;
			}
			$this->db->free($resSoc);
		}
		// Keep currently selected client visible even if no longer pending
		if ($filterSoc > 0 && empty($socOptions[$filterSoc]) && (empty($allowedSocIds) || $scope['type'] === 'all' || in_array($filterSoc, $allowedSocIds, true))) {
			$resOne = $this->db->query("SELECT rowid, nom FROM ".$this->db->prefix()."societe WHERE rowid = ".((int) $filterSoc));
			if ($resOne && ($objOne = $this->db->fetch_object($resOne))) {
				$socOptions[(int) $objOne->rowid] = $objOne->nom;
			}
			if ($resOne) {
				$this->db->free($resOne);
			}
		}

		$projectOptions = array();
		$allowedProjectIds = creditmanagerGetReportScopeProjectIdsForSelect($this->db, $scope, $entityProject);
		$sqlPr = "SELECT pr.rowid, pr.ref, pr.title FROM ".$this->db->prefix()."projet as pr";
		$sqlPr .= " WHERE pr.entity IN (".$entityProject.")";
		if (!empty($allowedProjectIds)) {
			$sqlPr .= " AND pr.rowid IN (".implode(',', array_map('intval', $allowedProjectIds)).")";
		} elseif ($scope['type'] !== 'all') {
			$sqlPr .= " AND 1 = 0";
		}
		if ($filterSoc > 0) {
			$sqlPr .= " AND pr.fk_soc = ".((int) $filterSoc);
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

		$sortOptions = array(
			'date' => $langs->trans('Date'),
			'project' => $langs->trans('Project'),
			'client' => $langs->trans('ThirdParty'),
			'hours' => $langs->trans('Duration'),
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
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('ThirdParty').'</label><br>';
		$boxcontent .= $form->selectarray('box_pending_socid', $socOptions, $filterSoc, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('Project').'</label><br>';
		$boxcontent .= $form->selectarray('box_pending_projectid', $projectOptions, $filterProject, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditType').'</label><br>';
		$boxcontent .= $form->selectarray('box_pending_typeid', $typeOptions, $filterType, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditBoxPendingTimesheetsSort').'</label><br>';
		$boxcontent .= $form->selectarray('box_pending_sort', $sortOptions, $sortField, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<div class="marginbottomonly">';
		$boxcontent .= '<label class="opacitymedium">'.$langs->trans('CreditDashboardAutoRefresh').'</label><br>';
		$boxcontent .= $form->selectarray('box_pending_refresh', $refreshOptions, $refreshInterval, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
		$boxcontent .= '</div>';

		$boxcontent .= '<button type="submit" class="button buttongen button-save">'.$langs->trans('Refresh').'</button>';
		$boxcontent .= ' <a class="button buttongen" href="'.dol_escape_htmltag($approveUrl).'">'.$langs->trans('CreditBoxPendingTimesheetsOpenApprove').'</a>';
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
				$cookieSoc => $filterSoc,
				$cookieProject => $filterProject,
				$cookieType => $filterType,
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
		$this->info_box_head['text'] = $textHead.' <a href="'.dol_escape_htmltag($approveUrl).'" title="'.dol_escape_htmltag($langs->trans('CreditBoxPendingTimesheetsOpenApprove')).'">'.img_picto($langs->trans('CreditBoxPendingTimesheetsOpenApprove'), 'object_projecttask', 'class="paddingleft"').'</a>';

		$line = 0;
		$this->info_box_contents[$line][] = array(
			'tr' => 'class="nohover showiffilter'.$this->boxcode.' hideobject"',
			'td' => 'class="nohover" colspan="4"',
			'textnoformat' => $boxcontent,
		);
		$line++;

		// Header columns
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('Date'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('ThirdParty'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre"',
			'text' => $langs->trans('Project'),
		);
		$this->info_box_contents[$line][] = array(
			'td' => 'class="liste_titre right"',
			'text' => $langs->trans('Duration'),
		);
		$line++;

		$orderSql = ' ORDER BY et.element_date DESC, et.rowid DESC';
		if ($sortField === 'project') {
			$orderSql = ' ORDER BY pr.ref ASC, et.element_date DESC';
		} elseif ($sortField === 'client') {
			$orderSql = ' ORDER BY s.nom ASC, et.element_date DESC';
		} elseif ($sortField === 'hours') {
			$orderSql = ' ORDER BY et.element_duration DESC, et.element_date DESC';
		}

		$sqlSelect = "SELECT et.rowid, et.element_duration, et.element_date, et.fk_credit_type,";
		$sqlSelect .= " COALESCE(NULLIF(TRIM(et.ref_ext), ''), tsk.ref, '') AS origin_ref,";
		$sqlSelect .= " pr.rowid AS project_id, pr.ref AS project_ref, pr.title AS project_title,";
		$sqlSelect .= " s.rowid AS socid, s.nom AS socname, ct.code AS type_code";
		$sqlSelect .= $sqlFrom.$sqlWhere.$orderSql;
		$sqlSelect .= $this->db->plimit($this->max, 0);

		dol_syslog(get_class($this).'::loadBox', LOG_DEBUG);
		$resql = $this->db->query($sqlSelect);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < $num) {
				$obj = $this->db->fetch_object($resql);
				if (!$obj) {
					break;
				}

				$hours = ((float) $obj->element_duration) / 3600.0;
				$dateLabel = $obj->element_date ? dol_print_date($this->db->jdate($obj->element_date), 'day') : '';
				$projectLabel = trim(($obj->project_ref ?: '').' '.($obj->project_title ?: ''));
				$lineUrl = $approveUrl;

				$this->info_box_contents[$line][] = array(
					'td' => 'class="nowraponall"',
					'text' => dol_escape_htmltag($dateLabel),
					'url' => $lineUrl,
				);
				$this->info_box_contents[$line][] = array(
					'td' => 'class="tdoverflowmax120"',
					'text' => dol_escape_htmltag($obj->socname ?: ''),
					'url' => $lineUrl,
					'maxlength' => 40,
				);
				$this->info_box_contents[$line][] = array(
					'td' => 'class="tdoverflowmax120"',
					'text' => dol_escape_htmltag($projectLabel !== '' ? $projectLabel : ($obj->origin_ref ?: ('ET-'.(int) $obj->rowid))),
					'url' => $lineUrl,
					'maxlength' => 40,
				);
				$this->info_box_contents[$line][] = array(
					'td' => 'class="nowrap right"',
					'text' => creditmanagerFormatAmount($hours),
				);

				$line++;
				$i++;
			}

			if ($num === 0) {
				$this->info_box_contents[$line][] = array(
					'td' => 'class="center opacitymedium" colspan="4"',
					'text' => $langs->trans('CreditBoxPendingTimesheetsEmpty'),
				);
			} elseif ($pendingTotal > $num) {
				$this->info_box_contents[$line][] = array(
					'td' => 'class="right" colspan="4"',
					'text' => '<a href="'.dol_escape_htmltag($approveUrl).'">'.$langs->trans('CreditBoxPendingTimesheetsSeeAll', $pendingTotal).'</a>',
					'asis' => 1,
				);
			}

			$this->db->free($resql);
		} else {
			$this->info_box_contents[$line][] = array(
				'td' => 'class="left" colspan="4"',
				'maxlength' => 500,
				'text' => ($this->db->error()),
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
