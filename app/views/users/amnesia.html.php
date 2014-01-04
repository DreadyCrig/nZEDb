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
 * @author niel
 * @copyright 2013 nZEDb
 */
?>
<?=$this->flashMessage->show(); ?>

<div>
	<p>Enter your email address in the space below to have an email sent reminding you of your username.</p>
</div>

<div class="login">
			<?=$this->form->create(null); ?>

				<?=$this->form->field('email'); ?>
				<div><p> </p></div>
				<?=$this->form->submit('Send'); ?>

			<?=$this->form->end(); ?>
</div>
