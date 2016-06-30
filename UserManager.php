<?php

namespace App\Model;

use Nette;
use Nette\Security\Passwords;
use Nette\Mail\Message;
use Nette\Mail\SendmailMailer;


/**
 * Users management.
 */
class UserManager extends Nette\Object implements Nette\Security\IAuthenticator
{
	const
		TABLE_NAME = 'users',
		COLUMN_ID = 'id',
		COLUMN_NAME = 'name',
		COLUMN_PASSWORD = 'password',
		COLUMN_PASSWORD_SALT = 'hash edited',
		COLUMN_EMAIL = 'email',
		COLUMN_TWITCHNAME = 'twitchName',
		COLUMN_SMITENAME = 'smiteName',
		COLUMN_REGISTERED = 'registered',
		COLUMN_ACTIVATIONCODE = 'activationCode',
		COLUMN_ACTIVATED = 'activated',
		COLUMN_IP = 'ip',
		COLUMN_CODE = 'code';




	/** @var Nette\Database\Context */
	private $database;



	public function __construct(Nette\Database\Context $database)
	{
		$this->database = $database;

	}


	/**
	 * Performs an authentication.
	 * @return Nette\Security\Identity
	 * @throws Nette\Security\AuthenticationException
	 */
	public function authenticate(array $credentials)
	{
		list($username, $password) = $credentials;

		$row = $this->database->table(self::TABLE_NAME)->where(self::COLUMN_NAME, $username)->fetch();

		if (!$row) {
			throw new Nette\Security\AuthenticationException('The username is incorrect.', self::IDENTITY_NOT_FOUND);

		} elseif (!Passwords::verify($password, $row[self::COLUMN_PASSWORD])) {
			throw new Nette\Security\AuthenticationException('The password is incorrect.', self::INVALID_CREDENTIAL);

		} elseif (Passwords::needsRehash($row[self::COLUMN_PASSWORD])) {
			$row->update(array(
				self::COLUMN_PASSWORD => Passwords::hash($password),
			));
		}

		$arr = $row->toArray();
		unset($arr[self::COLUMN_PASSWORD]);
		return new Nette\Security\Identity($row[self::COLUMN_ID], $row[self::COLUMN_NAME], $arr);
	}


	/**
	 * Adds new user.
	 * @param  string
	 * @param  string
	 * @return void
	 */
	public function add($name, $smitename, $email,$password,$twitchname,$refferal)
	{
		try {
			$iv_size =  mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
			$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);

			$b64_email = base64_encode($email);
			$b64_password = base64_encode($password);
			$b64_millisec = base64_encode(round(microtime(true) * 1000));
			$b64 = $b64_millisec . $b64_email . $b64_password;
			$reff_code = substr(md5($b64), 0, 8);

			if ($refferal != null)
			{
				$rs = $this->database->table('referrals')->where('code_gened', $refferal)->count('*');
				if ($rs == 0)
				{
					throw new MyException('Referral code is not valid. You CAN register without it.');
				}
			}

			$res = $this->database->table(self::TABLE_NAME)->insert(array(
				self::COLUMN_NAME => $name,
				self::COLUMN_PASSWORD => Passwords::hash($password),
				self::COLUMN_SMITENAME => $smitename,
				self::COLUMN_EMAIL => $email,
				self::COLUMN_TWITCHNAME => $twitchname,
				self::COLUMN_IP => $_SERVER['REMOTE_ADDR']
			));


			$lastId = $res->getPrimary(); // vlozeny zaznam

			$this->database->table("referrals")->insert(array(
				"id_caller" => $lastId,
				"code_gened" => $reff_code
			));



			if ($refferal != null)
			{
				$this->database->table("referrees")->insert(array(
					"id_callee" => $lastId,
					"code_used" => $refferal
				));

$result = $this->database->query('select count(g.code_used) as cnt from referrees g WHERE g.code_used = ?', $refferal);
				$res_row = $result->fetch();

				$sres = $this->database->query('select id_caller FROM referrals WHERE code_gened LIKE ?', $refferal);
				$sr = $sres->fetch();
				dump($res_row);
				dump($sr);
				
				//banning
				$counttoban = $this->database->query('select u.id as userId, r.code_gened, g.code_used, uu.id, count(uu.delivered) as failed
				from users u
				join referrals r
				on u.id = r.id_caller
				join referrees g
				on r.code_gened = g.code_used
				join users uu
				on g.id_callee = uu.id
				where uu.delivered = 0 and r.code_gened LIKE ?
				group by u.id', $refferal)->fetch();
				$actualcount = intval($counttoban['failed']);
				$theincrimuser = $counttoban['userId'];
				dump($theincrimuser);
				if ($actualcount > 0)
				{
					$this->database->query('UPDATE users SET banned = 1 WHERE id=?', $theincrimuser);
				}

				if ($res_row['cnt'] >= 50)
				{
					$stage = 2;
					$firstRes = $this->database->table('users')->where('id', $sr['id_caller'])->fetch();
					if ($firstRes['stageEligible'] == 1 and $firstRes['banned'] == 0)
					{
						$this->database->query('UPDATE users SET stageEligible = ? WHERE id=?', $stage, $sr['id_caller']);
						$this->sendMailReg();
					}
				}
				else if ($res_row['cnt'] >= 10)
				{
					$stage = 1;
					$firstRes = $this->database->table('users')->where('id', $sr['id_caller'])->fetch();
					if ($firstRes['stageEligible'] == 0 and $firstRes['banned'] == 0)
					{
						$this->database->query('UPDATE users SET stageEligible = ? WHERE id=?', $stage, $sr['id_caller']);
					}

				}


			}

		} catch (Nette\Database\UniqueConstraintViolationException $e) {
			throw new DuplicateNameException($e);
		}

	}

	public function testFriend($name, $code) // if user has referral in friends
	{
		$rs = $this->database->table('referrals')->where('code_gened', $code)->count('*'); // if code exists
		if ($rs != 0)
		{
			$sel = $this->database->query('
			select COUNT(*) as cnt from users u, referrals r where r.code_gened LIKE ? and u.id = r.id_caller and u.smiteName LIKE ?
			;', $code, $name)->fetch();

			return $sel['cnt'];

		}
		return -1;
	}

	public function sendMailReg()
	{


	}
}





class DuplicateNameException extends \Exception
{
	public function __construct(Nette\Database\UniqueConstraintViolationException $e)
	{
		//parent::__construct($message, $code, $previous);
		$this->error_dup = $e->errorInfo[2];
		$this->strs = explode(" ", $this->error_dup);
		$this->errors = str_replace("'", "", $this->strs);

		$row_name = $this->errors[5];
		$row_val = $this->errors[2];

		$this->err = "";
		switch ($row_name)
		{
			case (UserManager::COLUMN_NAME):
				$this->err .= "Name " . $row_val . " is already registered by someone else.";
				break;
			case (UserManager::COLUMN_SMITENAME):
				$this->err .= "Smite Name " . $row_val . " is already registered by someone else.";
				break;
			case (UserManager::COLUMN_EMAIL):
				$this->err .= "Email " . $row_val . " is already registered by someone else.";
				break;
		}
	}
}

class MyException extends \Exception
{
	public function __construct($e)
	{
		//parent::__construct($message, $code, $previous);
		$this->err = $e;
	}
}
