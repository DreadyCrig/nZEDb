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

namespace li3_nzedb\controllers;

use lithium\core\Environment;
use lithium\security\Auth;
use lithium\util\Validator;
use lithium\storage\Session;
use li3_flash_message\extensions\storage\FlashMessage;
use li3_mailer\action\Mailer;
//use li3_mailer\net\mail\Deliver;
use li3_mailer\net\mail\Message;
use li3_nzedb\models\Users;

/**
 * The Users controller allows creating, viewing, or modifying users; either by
 * the user themselves, or by the site's administrator(s).
 */
class UsersController extends \lithium\action\Controller
{
	public function _init()
	{
		parent::_init();
		$self = $this;

		Validator::add('emailInUse',
			function($value)
			{
				$result = Users::find('first', ['conditions' => ['email' => ['=' => $value]]]);
				return !count($result) > 0;
			}
		);

		Validator::add('usernameTaken',
			function($value)
			{
				$result = Users::findByUsername($value);
				return !count($result) > 0;
			}
		);

		Validator::add('matchesPassword',
			function($value) use (&$self)
			{
				$result = $self->request->get('data:password');
var_dump($result);
				return $value == $result;
			}
		);
	}

	/**
	 * Allows admins to create an account manually.
	 */
	public function add()
	{
/*
		$this->_render['layout'] = 'admin';
		$user = Users::create($this->request->data);

		if (($this->request->data) && $user->save()) {
			return $this->redirect('Users::index');
		}
		return compact('user');
 */
	}

	/**
	 * Facility for users that have forgotten their usernames to have a reminder
	 * sent to their registered email address.
	 */
	public function amnesia()
	{
		$this->_render['layout'] = 'login';
		if ($this->request->data) {
			$user = Users::find('first', ['conditions' => ['email' => ['=' => $this->request->data['email']]]]);
			if (!$user) {
				FlashMessage::write('Unable to find your account, please check your spelling.');
				return;
			}

			// TODO add mail sending code.
			$body = 'Your username is "' . $user->data('username') . "'";
			$subject = 'Your username reminder';
			if ($this->_sendmail(['subject' => $subject, 'body' => $body, 'email' => $user->data('email')])) {
				$message = 'An email with your user name, has been sent.';
			} else {
				$message = 'An error occured while trying to send your email. Please inform the site admin';
			}
			FlashMessage::write($message);
			return $this->redirect('/');
		}
	}

	/**
	 * Action to accept confirmation code sent out in email during registration.
	 *
	 * @param type $param code from email link
	 */
	public function confirm($param)
	{
		$this->_render['template'] = 'confirm';  //Just to make it complain if not finished before testing.
	}

	/**
	 * Displays a list of all usernames.
	 */
	public function index()
	{
		$this->_render['layout'] = 'admin';
		$user = Auth::check('default', $this->request);
		if (!Users::isAdmin($user)) {
			FlashMessage::write('Only admins can view the user list');
			return $this->redirect('/');
		}
		$users = Users::all();
		return compact('users');
	}

	/**
	 * Log in to the site with username/password, saving the user's details into
	 * the session for easier retrieval.
	 */
	public function login()
	{
		//$this->_render['layout'] = 'login';
		$this->set(['member' => ['label' => 'Register', 'link' => '/users/register']]);

		$request = $this->request;
		if ($request->data) {
			$user = Auth::check('default', $this->request);
			if ($user) {
				if (Users::isDisabled($user)) {
					FlashMessage::write('Your account has been disabled');
					return $this->redirect('/logout');
				} else {
					return $this->redirect('/');
				}
			} else {
				FlashMessage::write('Username/Password do not match!');
				return;
			}
		}
	}

	/**
	 * Log out of the site by clearing the user from the session.
	 *
	 * @return Redirect Sends user back to the home page.
	 */
	public function logout()
	{
		Auth::clear('default');
		return $this->redirect('/');
	}

	public function read()
	{
	}

	/**
	 * Allow a new user to register an account.
	 *
	 * TODO create page and logic for proper signup. Once confirmation (if
	 * enabled) is done, redirect to profile.
	 */
	public function register()
	{
		$this->set(['log' => 'in']);
		$confirm = true;  // TODO This needs to be dynamically set depending upon the site settings.
		$user = Users::create($this->request->data);
		if ($this->request->data) {
			$role = $confirm ? Users::STATUS_DISABLED : Users::STATUS_REGISTERED;
			$user->set(['role' => $role]);

			$result = $user->save();
			var_dump($result);
			if($result) {
				if ($confirm) {
					FlashMessage::write('Your account has been set up. Please check your email for a confimation message.');
				}
				$this->redirect('/');
			}
			FlashMessage::write('Please correct the errors below.');
		}
		$this->set(compact('user'));
	}

	/**
	 * Send email to allow password reset.
	 */
	public function reset()
	{
		$this->_render['layout'] = 'login';
		$nopass = true;
		if ($this->request->data) {
			$user = Users::find('first', ['conditions' => ['username' => ['=' => $this->request->data['username']]]]);
			if (!$user) {
				FlashMessage::write('Unable to find your account, please check your username is correct.');
				return;
			}

			// TODO add mail sending code.
			FlashMessage::write('Email with reset link, sent to your address.');
			return $this->redirect('/');
		}
		return compact('nopass');
	}

	public function test()
	{
		$this->_render['layout'] = 'login';
		$user = array();
		$request = $this->request->data;
		if (!empty($request)) {
			$this->set(['test' => true, 'user' => $user, 'used' => '']);
		} else {
			$this->set(['test' => false, 'user' => $user, 'used' => '']);
		}
	}

	protected function _sendmail($params)
	{
		$message = new Message([
			'baseURL' => 'nZEDb', // replace with site domain.
			'body' => ['text/plain' => $params['body']],
			'from' => 'nZEDb', // replace with field from sites table.
			'subject' => $params['subject'],
			'to' => $params['email'],
			]
		);
		$message->ensureStandardCompliance();

		return Mailer::deliver('test', ['delivery' => 'default', ]);
		//$transport = new \li3_mailer\tests\mocks\net\mail\Transport();
		//return $transport->deliver($message);
	}
}

?>