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
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditStatus.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditBalance.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/creditmanager/class/CreditStatusTypesAndTimesheets.class.php';

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

	public function printFieldListTitle($parameters, &$object, &$action)
	{
		global $langs, $user;
		$langs->load('creditmanager@creditmanager');
		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}
		if(! $this->userIsStaff($user)) {
			$this->resprints = '<td class="liste_titre">'.$langs->trans('CreditType').'</td>';
		}
		$this->resprints .= '<td class="liste_titre">'.$langs->trans('CreditStatus').'</td>';
		return 0;
	}

	public function printFieldListValue($parameters, &$object, &$action)
	{
		global $langs, $user;
		$langs->load('creditmanager@creditmanager');
		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}

		$mode = isset($parameters['mode']) ? (string) $parameters['mode'] : '';
		$timespent = isset($parameters['obj']) ? $parameters['obj'] : null;

		$this->resprints = '';
		if ($mode !== 'create' && !($action === 'editline' && GETPOSTINT('lineid') === (int) $timespent->rowid)) {
			["fk_credits_status_shows" => $fk_credits_status_shows, "fk_credits_types_shows" => $fk_credits_types_shows] = $this->withTimespentIdReturnCorrecpondingCreditStatusAndTypes($timespent->rowid);
			//credits types logics
			if(!$this->userIsStaff($user)) {
				if ($fk_credits_types_shows) {
					$creditTypes = new CreditType($this->db);
					$creditTypes->fetch($fk_credits_types_shows);
					$this->resprints .= '<td class="nowraponall">'.$creditTypes->label.'</td>';
				} else {
					$this->resprints .= '<td class="nowraponall">EMPTY</td>';
				}
			}
			
			// credit status affiche logics

			if ($fk_credits_status_shows) {
				$creditStatus = new CreditStatus($this->db);
				$creditStatus->fetch($fk_credits_status_shows);
				$this->resprints .= '<td class="nowraponall">'.$creditStatus->status_name.'</td>';
			} else {
				$this->resprints .= '<td class="nowraponall">EMPTY</td>';
			}
			
		}
		if ($mode === 'create') {
			if(!$this->userIsStaff($user)) {
				$this->resprints = '<td class="nowraponall">'.$this->renderCreditTypeSelect(GETPOSTINT('fk_credits_types'), 'fk_credits_types').'</td>';
			}
			$this->resprints .= '<td class="nowraponall">'.$this->renderCreditStatusSelect(GETPOSTINT('fk_credits_status'), 'fk_credits_status').'</td>';
			return 0;
		}

		if (!is_object($timespent)) {
			$this->resprints = '<td></td>';
			return 0;
		}

		if ($action === 'editline' && GETPOSTINT('lineid') === (int) $timespent->rowid) {
			$correspondingData = $this->withTimespentIdReturnCorrecpondingCreditStatusAndTypes($timespent->rowid);
			if(!$this->userIsStaff($user)) {
				$this->resprints = '<td class="nowraponall">'.$this->renderCreditTypeSelect(GETPOSTINT('fk_credits_types')?: (int) $correspondingData["fk_credits_types_shows"], 'fk_credits_types').'</td>';
			}
			$this->resprints .= '<td class="nowraponall">'.$this->renderCreditStatusSelect($correspondingData["fk_credits_status_shows"], 'fk_credits_status').'</td>';
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

		return 0;
	}

	public function printFieldListOption($parameters, &$object, &$action)
	{
		global $langs, $user;
		$langs->load('creditmanager@creditmanager');

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}
		if(!$this->userIsStaff($user)) {
			$this->resprints = '<td class="nowraponall">'.$this->renderCreditTypeSelect(GETPOSTINT('search_credits_types'), 'search_credits_types').'</td>';
		}
		$this->resprints .= '<td class="nowraponall">'.$this->renderCreditStatusSelect(GETPOSTINT('search_credits_status'), 'search_credits_status').'</td>';
	
	}

	public function printFieldListSelect($parameters, &$object, &$action, $hookmanager) {

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}
		$this->resprints = "cstt.fk_element_time, cstt.fk_credits_status, cstt.fk_credits_types";
	}

	public function printFieldListFrom($parameters, &$object, &$action, $hookmanager) {

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}
		$this->resprints = " LEFT JOIN ".MAIN_DB_PREFIX."credits_status_types_and_timesheets as cstt ON cstt.fk_element_time = t.rowid";
	}

	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager) 
	{

		if (!$this->isTaskTimeListContext($parameters)) {
			return 0;
		}
		$this->resprints = '';

    	if (!empty($_POST['search_credits_status'])) {
            $this->resprints .= " AND cstt.fk_credits_status = ".(int) $_POST['search_credits_status'];
        }

		if (!empty($_POST['search_credits_types'])) {
            $this->resprints .= " AND cstt.fk_credits_types = ".(int) $_POST['search_credits_types'];
        }
	}

	private function isTaskTimeListContext($parameters)
	{
		if (empty($parameters['context'])) {
			return false;
		}

		$contexts = explode(':', (string) $parameters['context']);
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
		global $langs, $user;
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

	private function renderCreditStatusSelect($selectedId, $htmlName)
	{
		global $langs, $user;
		$langs->load('creditmanager@creditmanager');

		$credit = new CreditStatus($this->db);
		$list = $credit->fetchAll($this->userIsStaff($user));
		if (!is_array($list)) {
			return '<span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span>';
		}

		$options = array('' => $langs->trans('SelectCreditStatus'));
		foreach ($list as $item) {
			$options[(string) $item->rowid] = $item->status_name;
		}

		$form = new Form($this->db);
		return $form->selectarray($htmlName, $options, $selectedId > 0 ? (string) $selectedId : '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200');
	}

	private function withTimespentIdReturnCorrecpondingCreditStatusAndTypes($rowid)
	{
		$creditTM = new CreditStatusTypesAndTimesheets($this->db);
		$creditTM->fetch($rowid);
		$fk_credits_status_shows = $creditTM->fk_credits_status ? (int) $creditTM->fk_credits_status : null;
		$fk_credits_types_shows = $creditTM->fk_credits_types ? (int) $creditTM->fk_credits_types : null;
		return ["fk_credits_status_shows" => $fk_credits_status_shows, "fk_credits_types_shows" => $fk_credits_types_shows];
	}

	private function userIsStaff($user) 
	{
		// Get group ID of "Staff"
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."usergroup WHERE nom = 'Credit Manager - Staff'";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) == 0) return false;
		$obj = $this->db->fetch_object($resql);
		$staffGroupId = $obj->rowid;

		// Check if current user is in that group
		$sql = "SELECT fk_user FROM ".MAIN_DB_PREFIX."usergroup_user WHERE fk_usergroup = ".(int)$staffGroupId." AND fk_user = ".(int)$user->id;
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}
	
	public function formAddObjectLine($parameters, &$object, &$action, $hookmanager) {
		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) && !in_array('invoicecard', $contexts)) return 0;

		$inputSelected = $this->renderCreditTypeSelect(GETPOSTINT('credits_types'), 'credits_types');
		$inputSelected = explode('<!--', $inputSelected)[0];
		
		print "<input type='hidden' name='fk_soc' value='$object->socid'>";
		?>
		<script>
		$(document).ready(function(){
			/*let arrayOfValueHours = document.querySelectorAll('.cm-h');
			let creditManagerLineInTable = `
			<span class="prod_entry_mode_free nowraponall">
			    <input checked id="cm-radio" class="prod_entry_mode_free" type="checkbox" name="prod_entry_mode" value="Credit Manager">
				<label>Add hours in Credit Manager</label>
			</span><br>
			`;
			$(creditManagerLineInTable).insertBefore($('table#tablelines').parent());*/


			/*let discount = arrayOfValueHours.length ? $('table#tablelines tr.liste_titre').children().eq(-4) : $('table#tablelines tr.liste_titre').children().eq(-1)
			$('<td class="chainHours">Hours</td><td class="chainHours">Type de credit</td>').insertBefore(discount);*/
			let elt = $('#addline').parent();
			let inputHours = '<input type="text" name="cm-hours" id="hour" class="flat width40 right chainHours" value="0">';
			let tdInputHours = '<td id="inputHours" class="nobottom right chainHours">' + inputHours + '</td>';
			$(tdInputHours).insertBefore(elt);
			let selectInputType = '<td id="inputCreditType" class="nobottom right chainHours">' + `<?php  echo ($inputSelected);?>` + '</td>';
			$(selectInputType).insertBefore(elt);
			
			
			/*$('#cm-radio').click(function() {
				if ($('#cm-radio').is(':checked')) {
					$('.chainHours').show();
				} else {
					$('.chainHours').hide();
				}
			})
			
			arrayOfValueHours.forEach((value) => {
				let previousNode = $(value).parent().next().children().eq(-4);
				let hours = $(value).children('.cm-h-hours').val();
				let credit_type = $(value).children('.cm-h-credit-type').val();
				$('<td class="chainHours">' + hours + '</td><td class="chainHours">' + credit_type + '</td>').insertBefore(previousNode);
				$(value).hide();
			})*/
			
		
		});
		</script>
		<?php
		
		return 0;
	}

	public function formEditObjectLine($parameters, &$object, &$action, $hookmanager) {
		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) && !in_array('invoicecard', $contexts)) return 0;

		if($action == "editline") {
			
			$lineId = GETPOST('lineid', 'int');
			$balanceAndCreditType = $this->renderBalanceAndRelatedCreditType($lineId);
			$inputSelected = $this->renderCreditTypeSelect($balanceAndCreditType['idCreditType'], 'credits_types');
			$inputSelected = explode('<!--', $inputSelected)[0];

		}
		?>
		<script>
			$(document).ready(function(){
			/*let arrayOfValueHours = document.querySelectorAll('.cm-h');
			let creditManagerLineInTable = `
			<span class="prod_entry_mode_free nowraponall">
			    <input checked id="cm-radio" class="prod_entry_mode_free" type="checkbox" name="prod_entry_mode" value="Credit Manager">
				<label>Add hours in Credit Manager</label>
			</span><br>
			`;
			$(creditManagerLineInTable).insertBefore($('#product_desc'));

			let discount = arrayOfValueHours.length ? $('table#tablelines tr.liste_titre').children().eq(-4) : $('table#tablelines tr.liste_titre').children().eq(-1)
			$('<td class="chainHours">Hours</td><td class="chainHours">Type de credit</td>').insertBefore(discount);*/
			let elt = $('#cancellinebutton').parent();
			
			/*$('#cm-radio').click(function() {
				if ($('#cm-radio').is(':checked')) {
					$('.chainHours').show();
				} else {
					$('.chainHours').hide();
				}
			})*/
			
			document.querySelectorAll('.cm-h').forEach((value) => {
				let previousNode = $(value).parent().next().children().eq(-4);
				let hours = $(value).children('.cm-h-hours').val();
				let credit_type = $(value).children('.cm-h-credit-type').val();

				//tredited
				let parent = $(value).parent().next().attr('class');

				if (parent.match('tredited')) {
					let inputHours = '<input type="text" name="cm-hours" id="hour" class="flat width40 right chainHours" value="'+hours+'">';
					let tdInputHours = '<td id="inputHours" class="nobottom right chainHours">' + inputHours + '</td>';
					$(tdInputHours).insertBefore(elt);
					let selectInputType = '<td id="inputCreditType" class="nobottom right chainHours">' + `<?php  echo ($inputSelected);?>` + '</td>';
					$(selectInputType).insertBefore(elt);
				} else {
					$('<td>' + hours + '</td><td>' + credit_type + '</td>').insertBefore(previousNode);
					$(value).hide();
				}
				
			})
			
		
		});
		</script>
		<?php
		return 0;
	}

	private function renderBalanceAndRelatedCreditType ($lineId) {
		$balance = new CreditBalance($this->db);
		
        $isThisrelatedDataExist = $balance->fetch(null, null, null, $lineId, true);
		$correspondingBalance = 'None';
		$correspondingCreditType = 'None';
		if ($isThisrelatedDataExist) {
			$correspondingBalance = $balance->balance;
			$creditType = new CreditType($this->db);
			if ($creditType->fetch((int) $balance->fk_credit_type) > 0) {
				$correspondingCreditType = $creditType->label;
			}
			return ['idCreditType' => $balance->fk_credit_type,'labelCreditType'=>$correspondingCreditType, 'balance'=>$correspondingBalance ];
		}
		return false;
	}

	function printObjectLine($parameters, &$object, &$action, $hookmanager)
    {
        // Pour afficher la valeur dans chaque ligne du contrat
        $contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) && !in_array('invoicecard', $contexts)) return 0;

		$balanceAndCreditType = $this->renderBalanceAndRelatedCreditType($parameters['line']->id);
		$correspondingBalance = $balanceAndCreditType['balance'];
		$correspondingCreditType = $balanceAndCreditType['labelCreditType'];

        print  "<td class='cm-h' style='display: none'><input type='hidden' class='cm-h-hours' value='$correspondingBalance'> <input type='hidden' class='cm-h-credit-type' value='$correspondingCreditType'></td>";
        return 0;
    }

	function formObjectOptions($parameters, &$object, &$action, $hookmanager) {
		$contexts = explode(':', $parameters['context']);
		if (!in_array('invoicecard', $contexts) && !in_array('invoicecard', $contexts)) return 0;
		
		?>
		<script>
			$(document).ready(function() {
				let arrayOfValueHours = document.querySelectorAll('.cm-h');
				let creditManagerLineInTable = `
				<span class="prod_entry_mode_free nowraponall">
					<input checked id="cm-radio" class="prod_entry_mode_free" type="checkbox" name="prod_entry_mode" value="Credit Manager">
					<label>Add hours in Credit Manager</label>
				</span><br>
				`;
				$(creditManagerLineInTable).insertBefore($('table#tablelines').parent());
				//
				let discount = arrayOfValueHours.length ? $('table#tablelines tr.liste_titre').children().eq(-4) : $('table#tablelines tr.liste_titre').children().eq(-1)
				$('<td class="chainHours">Hours</td><td class="chainHours">Type de credit</td>').insertBefore(discount);
				$('#cm-radio').click(function() {
					if ($('#cm-radio').is(':checked')) {
						$('.chainHours').show();
					} else {
						$('.chainHours').hide();
					}
				})
				//if($('.tredited').length) {}
				console.log(($('.tredited').length));
				if (! (($('.tredited').length))) {
					arrayOfValueHours.forEach((value) => {
						let previousNode = $(value).parent().next().children().eq(-4);
						let hours = $(value).children('.cm-h-hours').val();
						let credit_type = $(value).children('.cm-h-credit-type').val();

						//tredited
						let parent = $(value).parent().next().attr('class');

						if (parent.match('tredited')) {
							//test($('#cancellinebutton').parent());
						} else {
							$('<td class="chainHours">' + hours + '</td><td class="chainHours">' + credit_type + '</td>').insertBefore(previousNode);
							$(value).hide();
						}
						
					})
				}
				
			})
		</script>
		<?php
		return 0;
	}
	
}
