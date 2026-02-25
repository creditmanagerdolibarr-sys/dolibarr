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
 *	\file       htdocs/custom/creditmanager/lib/creditmanager.lib.php
 *	\ingroup    creditmanager
 *	\brief      Library of functions for module Credit Manager
 */

/**
 *  Prepare admin pages header (tabs)
 *
 *  @return	array		Array of tabs
 */
function creditmanagerAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("creditmanager@creditmanager");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/credit_types.php", 1);
	$head[$h][1] = $langs->trans("CreditTypes");
	$head[$h][2] = 'credit_types';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/attribution.php", 1);
	$head[$h][1] = $langs->trans("CreditAttribution");
	$head[$h][2] = 'attribution';
	$h++;

	$head[$h][0] = dol_buildpath("/custom/creditmanager/admin/tools.php", 1);
	$head[$h][1] = $langs->trans("CreditManagerTools");
	$head[$h][2] = 'tools';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'creditmanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'creditmanager', 'remove');

	return $head;
}
