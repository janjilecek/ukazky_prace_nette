<?php

namespace App\Presenters;

use Nette;
use \PavelMaca\Captcha;
use Nette\Utils\DateTime;
use App\Model;
use Nette\Application\UI;
use Curse\Smite;
use Nextras\Forms\Rendering\Bs3FormRenderer;
use PavelMaca\Captcha\CaptchaControl;


class RegistrationPresenter extends BasePresenter
{
    /**
     * @var \MyCache
     * @inject
     */
    public $mycache;


    /** @var Nette\Database\Context */
    private $database;


    public function __construct(Nette\Database\Context $database)
    {
        $this->database = $database;
    }


    public function renderDefault()
    {
        $this->template->mainName = "Registration";


    }


    protected function createComponentRegistrationForm()
    {
        $session = $this->getSession();

        CaptchaControl::register($session);

        $form = new UI\Form;
        $form->setRenderer(new Bs3FormRenderer());
        $form->getElementPrototype()->class[] = "ajax";
        $form->addProtection('Time limit reached, fill in the form again please');
        $form->addText('name', 'Name:')->setRequired('Please enter your username')->addRule(\Nette\Forms\Form::MAX_LENGTH, "max length is 20", 20);
        $form->addText('smitename', 'Smite Player name:')
            ->setRequired('Please use an existing account name');
        $form->addText('email', 'E-mail:')->setRequired('Please enter an e-mail address')
            ->addRule(\Nette\Forms\Form::EMAIL, 'Invalid e-mail address');
        $form->addPassword('password', 'Password:')->setRequired('Choose a password')
            ->addRule(\Nette\Forms\Form::MIN_LENGTH, 'Password must be at least %d characters long', 6);
        $form->addPassword('password_test', 'Password again:')->setRequired('Please enter password one more time')
            ->addRule(\Nette\Forms\Form::EQUAL, 'Passwords do not match', $form['password']);
        $form->addText('twitchname', 'Twitch name:');
        $form->addText('referral', 'Referral code:')
            ->setDefaultValue($this->getParameter('refcode'))
            ->addCondition(\Nette\Forms\Form::FILLED)
            ->addRule(\Nette\Forms\Form::LENGTH, 'Code must be 8 characters long', 8);

        $form->addCaptcha('captcha')
                   ->addRule(\Nette\Forms\Form::FILLED, "Rewrite text from image.")
                   ->addRule($form["captcha"]->getValidator(), 'Try it again.')
                   ->setFontSize(13)
                   ->setLength(7) //word length
                   ->setTextMargin(20) //px, set text margin on left and right side
                   ->setTextColor(\Nette\Utils\Image::rgb(255,0,0)) //array("red" => 0-255, "green" => 0-255, "blue" => 0-255)
                   ->setBackgroundColor(\Nette\Utils\Image::rgb(255,255,255)) //array("red" => 0-255, "green" => 0-255, "blue" => 0-255)
                   ->setImageHeight(50) //px, if not set (0), image height will be generated by font size
                   ->setImageWidth(0) //px, if not set (0), image width will be generated by font size
                   //->setExpire(10000 ) //ms, set expiration time to session
                   ->setFilterSmooth(false) //int or false (disable)
                   ->setFilterContrast(false)  //int or false (disable)
                   ->useNumbers(true)->addRule(\Nette\Forms\Form::MAX_LENGTH, "max length is 7", 7); // bool or void

        $form->addSubmit('login', 'Register');

        $form->onValidate[] = array($this, 'testExistingSmiteName');
        $form->onSuccess[] = array($this, 'registrationFormSucceeded');
        return $form;
    }

    // success
    public function registrationFormSucceeded(UI\Form $form, $values)
    {
        $vals = $form->getValues();


        try{
            $this->userManager->add( // add values from form 
                $vals['name'],
                $vals['smitename'],
                $vals['email'],
                $vals['password'],
                $vals['twitchname'],
                $vals['referral']
            );


            $eligibles = $this->database->table('users')->where('stageEligible != stageSent')->fetchAll();


            foreach($eligibles as $eligible)
            {
                $this->saveSendAndUpdate($eligible);
            }

            $dets = $this->database->table('users')->where('name', $vals['name'])->fetch();
            $rcode = $this->database->table('referrals')->where('id_caller', $dets['id'])->fetch();

            $mail = new \Nette\Mail\Message;
            $mail->setFrom($this->myemail)
            ->addTo($vals['email'])
            ->setSubject('Edited registration')
            ->setHtmlBody('<h1>Registration Complete</h1>
            <h2>edited='. $rcode['code_gened'] .'</a></h2>
            <ul>
            <li>'.$vals["name"].'</li>
             <li>'.$vals["smitename"].'</li></ul>');

            $this->mymailer->send($mail);
            
            
            $mb = \imap_open("{imap.gmail.com:993/imap/ssl}INBOX","email edited@gmail.com", "pw edited" );

            $messageCount = \imap_num_msg($mb);
            for( $MID = 1; $MID <= $messageCount; $MID++ )
            {
                $EmailHeaders = \imap_headerinfo( $mb, $MID );
                $Body = \imap_fetchbody( $mb, $MID, 1 );
                imap_delete($mb, $MID);
                if ($EmailHeaders->subject == 'Delivery Status Notification (Failure)') // if mail was sent to non-existent email
                {
                    $small = substr($Body, 64, 209);
                    preg_match('/^(.*)/', $small, $re);
                    if (count($re) > 0)
                    {
                        $addr =trim($re[0]);
                        $this->database->query('UPDATE users SET delivered = 0 WHERE email=?', $addr);
                    }
                }
            }
            imap_expunge($mb);

           $this->redirect('RegComplete:'); 
        } catch(Model\DuplicateNameException $e)
        {
            $form->addError($e->err);
        } catch (Model\MyException $e)
        {
            $form->addError($e->err);
        }


    }

    public function testExistingSmiteName($form)
    {
        $vals = $form->getValues();
        $api = new Smite\API('key edited', 'key edited'); // if playre name exists

        $api->useCache($this->mycache);
        $api->preferredFormat('array');
        try{
			$datatest = $api->getplayer('player');
			if (empty($datatest))
			{
				$form->addError('Smite is having an update now, we cannot check the validity of your username. Please try again after Smite update is over.');
			}
            $playerData = $api->getplayer($form->getValues()->smitename);
			
			
            $playerFriends = $api->getfriends($form->getValues()->smitename);
			
			$myfkres = $this->database->table('users')->where('ip',$_SERVER['REMOTE_ADDR'])->count('*');
            if ($myfkres > 1 || strpos($vals['email'], 'trbvn') !== false || strpos($vals['email'], 'euaqa') !== false)
            {
                $form->addError("Please do not be a naughty boy or you will get a spanking."); // warning for cheaters
            }
			

            if (empty($playerData))
            {
                $form->addError('Smite player name not recognized or does not exist.');
            }
            else if ($playerData[0]['Level'] <= 10)
            {
                $form->addError('Player level must be at least 11');
            }
            else
            {
                $today = time();
                $created = DateTime::createFromFormat('n/j/Y g:i:s A', $playerData[0]['Created_Datetime'])->getTimestamp();

                if ($today - $created < 2629743)
                {
                    $form->addError('Smite profile must be older than 1 month.');
                }

                $referral = $vals['referral'];
                if ($referral != null)
                {
                    $errd = false;
                    foreach ($playerFriends as $friendData)
                    {
                        $friendName = $friendData['name'];
                        $testRes = $this->userManager->testFriend($friendName, $referral);
                        if ($testRes == 1)
                        {
                            $errd = false;
                            break;
                        }
                        else if ($testRes == 0)
                        {
                            $errd = true;
                        }
                        else // returned -1, code not valid, we already have an error for that
                        {
                            $errd = false;
                        }
                    }
                    if ($errd)
                    {
                        $form->addError("The referral code must be from a friend from your Smite Ingame Friends List. You CAN register without it.");
                    }
                    $bansel = $this->database->query('
                    select u.banned from users u,referrals r
                    where u.id = r.id_caller and r.code_gened like ?', $referral)->fetch();
                    if ($bansel['banned'] == 1)
                    {
                        $form->addError('User who gave you the referral code has been banned. Please register without the code.');
                    }
                }
            }
        } catch (Smite\ApiException $e)
        {
            $form->addError('Error while checking Smite name. Please try again.');
        }

    }

    private function saveSendAndUpdate($eligible) // sent codes based on user level
    {


        $this->database->query('UPDATE users SET stageSent = ? WHERE id=?', $eligible['stageEligible'], $eligible['id']);

        if ($eligible['stageEligible'] == 1 and $eligible['banned'] == 0)
        {
            $this->database->query('INSERT INTO codes', array(
                'type' => '200',
                'free' => 1,
                'user' => $eligible['id']
            ));
        }
        else if ($eligible['stageEligible'] == 2 and $eligible['banned'] == 0)
        {
            $this->database->query('INSERT INTO codes', array(
                'type' => '400',
                'free' => 1,
                'user' => $eligible['id']
            ));
        }

		if ($eligible['banned'] == 0)
		{
        $mail = new \Nette\Mail\Message;
        $mail->setFrom($this->myemail)
            ->addTo($eligible['email'])
            ->addTo($this->myemail)
            //,'.$eligible['name'].',
            ->setSubject('You have earned the gem code!')
            ->setHtmlBody('<h1>Congratulations! You have invited enough players to get the code!</h1><h2>Expect the code in another email and on your account information page in the next 30 minutes (but may take up to 24 hours!)</h2>');

        //dump($this->mymailer);
        $this->mymailer->send($mail);
		}
    }
}
