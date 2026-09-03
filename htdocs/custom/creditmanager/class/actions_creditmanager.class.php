<?php
/* Copyright (C) 2026  Credit Manager module for Dolibarr
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditType.class.php';

class ActionsCreditmanager extends CommonHookActions
{
	/**
	 * @var DoliDB
	 */
	public $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function printFieldListSelect($parameters, &$object, &$action)
	{
		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}

		$this->resprints = ', t.fk_credit_type, t.credit_status';
		return 0;
	}

	/*public function printFieldListTitle($parameters, &$object, &$action)
	{
		global $langs;
		$langs->load('creditmanager@creditmanager');

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}

		$this->resprints = '<td class="liste_titre">'.$langs->trans('CreditType').'</td>';
		return 0;
	}*/

	public function printFieldListOption($parameters, &$object, &$action)
	{
		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}

		$this->resprints = '<td class="liste_titre"></td>';
		return 0;
	}

	/*public function printFieldListValue($parameters, &$object, &$action)
	{
		global $langs;
		$langs->load('creditmanager@creditmanager');

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}

		$mode = isset($parameters['mode']) ? (string) $parameters['mode'] : '';
		$timespent = isset($parameters['obj']) ? $parameters['obj'] : null;

		if ($mode === 'create') {
			$this->resprints = '<td class="nowraponall">'.$this->renderCreditTypeSelect(GETPOSTINT('fk_credit_type'), 'fk_credit_type').'</td>';
			return 0;
		}

		if (!is_object($timespent)) {
			$this->resprints = '<td></td>';
			return 0;
		}

		if ($action === 'editline' && GETPOSTINT('lineid') === (int) $timespent->rowid) {
			$this->resprints = '<td class="nowraponall">'.$this->renderCreditTypeSelect(GETPOSTINT('fk_credit_type') ?: (int) $timespent->fk_credit_type, 'fk_credit_type').'</td>';
			return 0;
		}

		if ($mode === 'split1' || $mode === 'split2') {
			$this->resprints = '<td></td>';
			return 0;
		}

		$label = '';
		if (!empty($timespent->fk_credit_type)) {
			$creditType = new CreditType($this->db);
			if ($creditType->fetch((int) $timespent->fk_credit_type) > 0) {
				$label = $creditType->label;
			}
		}

		if ($label === '') {
			$label = '<span class="opacitymedium">'.$langs->trans('None').'</span>';
		}

		$status = !empty($timespent->credit_status) ? ' <span class="opacitymedium">('.dol_escape_htmltag($timespent->credit_status).')</span>' : '';
		$this->resprints = '<td class="nowraponall">'.$label.$status.'</td>';
		return 0;
	}*/

	private function isTaskTimeListContext($parameters)
	{
		if (empty($parameters['currentcontext'])) {
			return false;
		}

		$contexts = explode(':', (string) $parameters['currentcontext']);
		return in_array('tasktimelist', $contexts, true);
	}

	/**
	 * Inject balance badge into external portal left menu "Current balance".
	 *
	 * @param array $parameters
	 * @param array $menu_array
	 * @return int 1 = replace menu array
	 */
	public function menuLeftMenuItems($parameters, &$menu_array)
	{
		global $user, $langs;

		require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/lib/creditmanager.lib.php';

		if (empty($user->socid) || !creditmanagerIsClientPortalUser($user)) {
			return 0;
		}
		if (empty($parameters['mainmenu']) || $parameters['mainmenu'] !== 'creditmanager') {
			return 0;
		}
		if (!is_array($menu_array) || empty($menu_array)) {
			return 0;
		}

		$langs->load('creditmanager@creditmanager');

		$total = creditmanagerGetClientTotalBalance($this->db, (int) $user->socid);
		$badge = creditmanagerFormatBalanceBadge($total);
		$balanceLabel = $langs->trans('CreditClientBalanceMenu');
		$myCreditsLabel = $langs->trans('CreditClientMyCredits');

		foreach ($menu_array as $key => $entry) {
			$url = isset($entry['url']) ? (string) $entry['url'] : '';
			$titre = isset($entry['titre']) ? (string) $entry['titre'] : '';
			$isBalanceEntry = (strpos($url, '/custom/creditmanager/client/balance.php') !== false)
				&& (strpos($url, '#credit-request-form') === false);
			$isHomeEntry = (strpos($url, '/custom/creditmanager/client/index.php') !== false);

			if ($isBalanceEntry || $isHomeEntry) {
				if (strpos($titre, 'badge') === false) {
					$base = $isHomeEntry ? $myCreditsLabel : $balanceLabel;
					// Keep existing translated title when present
					if ($titre !== '' && $titre !== 'CreditClientBalanceMenu' && $titre !== 'CreditClientMyCredits') {
						$base = $titre;
					}
					$menu_array[$key]['titre'] = $base.$badge;
				}
			}
		}

		$this->resArray = $menu_array;
		return 1;
	}

	private function renderCreditTypeSelect($selectedId, $htmlName)
	{
		global $langs;
		$langs->load('creditmanager@creditmanager');

		$creditType = new CreditType($this->db);
		$list = $creditType->fetchAll(1);
		if (!is_array($list)) {
			return '<span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span>';
		}

		$options = array('' => $langs->trans('SelectCreditType'));
		foreach ($list as $item) {
			$options[(string) $item->id] = $item->label;
		}

		$form = new Form($this->db);
		return $form->selectarray($htmlName, $options, $selectedId > 0 ? (string) $selectedId : '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
	}
}
