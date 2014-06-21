<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link <http://www.gnu.org/licenses/>.
 * @author Niel Archer
 * @copyright 2013 nZEDb
 */

namespace li3_nzedb\controllers;

use lithium\security\Auth;
use \li3_nzedb\models\Sites;
use \li3_nzedb\models\Users;

class SitesController extends \lithium\action\Controller
{
	protected $_render = ['layout' => 'admin'];

	public function view()
	{
		$user = Auth::check('default', $this->request);
		if (!Users::isAdmin($user)) {
			return $this->redirect('/');
		} else {
			$settings = Sites::asArray();
			return compact('settings');
		}
	}
}

?>