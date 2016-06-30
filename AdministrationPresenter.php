<?php
/**
 * Created by PhpStorm.
 * User: john
 * Date: 4/2/2016
 * Time: 12:21 AM
 */


namespace App\Presenters;

use Nette;
use Nette\Utils\DateTime;
use App\Model;
use Nette\Application\UI;
use Curse\Smite;
use Nextras\Forms\Rendering\Bs3FormRenderer;

class AdministrationPresenter extends BasePresenter
{
    /** @var Nette\Database\Context */
    private $database;

    public function __construct(Nette\Database\Context $database)
    {
        $this->database = $database;
    }

    public function renderDefault() // get params 
    {
        $orderId = $this->getParameter('OrderParameterIdNumber');
        $codeId = $this->getParameter('CodeParameterIdNumber');
        $this->template->mainName = "Administration";

        
        
        

    }


    protected function createComponentOrderForm() // form for bought codes input
    {
        $form = new UI\Form;
        $form->setRenderer(new Bs3FormRenderer());
        $form->addText('id', 'Order ID:')->setDefaultValue($this->getParameter('orderId'));
        $form->addText('code1', 'Code 1:');
        $form->addText('code2', 'Code 2:');
        $form->addText('code3', 'Code 3:');
        $form->addText('code4', 'Code 4:');
        $form->addText('code5', 'Code 5:');
        $form->addSubmit('save', 'Save and send');

        $form->onSuccess[] = array($this, 'orderFormSucceeded');
        return $form;
    }

    protected function createComponentCodeForm() // saving codes
    {
        $form = new UI\Form;
        $form->setRenderer(new Bs3FormRenderer());
        $form->addText('id', 'Code ID:')->setDefaultValue($this->getParameter('codeId'));
        $form->addText('code', 'Code:')->setRequired('Please enter the code');
        $form->addSubmit('save', 'Save and send');

        $form->onSuccess[] = array($this, 'codeFormSucceeded');
        return $form;
    }

    public function orderFormSucceeded(UI\Form $form, $values) // user ordered codes, inserting into db
    {
        $vals = $form->getValues();
        $details = $this->database->table('orders')->where('id', $vals['id'])->fetch();
        $quant = $details['quantity'];

        dump($vals);

        $orderCodeIDs = array();
        $formInputCodes = array();

        $orderCodes = $this->database->table('codes')->where('orderId', $vals['id'])->fetchAll();
        foreach ($orderCodes as $oc)
        {
            array_push($orderCodeIDs, $oc['id']);
        }

        for ($i = 1; $i < $quant+1; $i++)
        {
            array_push($formInputCodes, $vals['code'.$i]);
        }

        $mytmpord = $this->database->table('orders')->where('id', $vals['id'])->fetch();
        $thatuser = $this->database->table('users')->where('id', $mytmpord['author'])->fetch();
        $hisemail = $thatuser['email'];
        dump($hisemail);

        dump($orderCodeIDs);
        dump($formInputCodes);


        for ($i = 0; $i < $quant; $i++)
        {
            $this->database->query('UPDATE codes SET code=? WHERE id=?', $formInputCodes[$i], $orderCodeIDs[$i]);
        }

        $this->database->query('UPDATE orders SET completed=? WHERE id=?', 1, $vals['id']);


        $tmpstr = "<ul>";
        for ($i = 0; $i < $quant; $i++)
        {
            $tmpstr = $tmpstr . "<li>". $formInputCodes[$i] . "</li>";
        }
        $tmpstr = $tmpstr . "</ul>";


        $emailOrderId = 100000 + intval($vals['id']);
        $mail_body = '<h1>Here are your Gems codes for order ID ' . $emailOrderId . '!</h1>
            <p>'.
            $tmpstr
            .'</p>';



        $mail = new \Nette\Mail\Message;
        $mail->setFrom($this->myemail)
            ->addTo($hisemail)
            ->addTo($this->myemail)
            ->setSubject('Here are your Gems codes!')
            ->setHtmlBody($mail_body);

        $this->mymailer->send($mail);

        $this->flashMessage("Order processed successfully.", 'success');

    }

    public function codeFormSucceeded(UI\Form $form, $values)
    {
        $vals = $form->getValues();

        $this->database->query('UPDATE codes SET code=? WHERE id=?', $vals['code'], $vals['id']);


        $tmpstr = "<ul>";
        $tmpstr = $tmpstr . "<li>".  $vals['code'] . "</li>";
        $tmpstr = $tmpstr . "</ul>";


        $emailOrderId = 100000 + intval($vals['id']);
        $mail_body = '<h1>Below is your Gems code - ID ' . $emailOrderId . '!</h1>
            <p>'.
            $tmpstr
            .'</p>';

        $mytmpord = $this->database->table('codes')->where('id', $vals['id'])->fetch();
        $thatuser = $this->database->table('users')->where('id', $mytmpord['user'])->fetch();
        $hisemail = $thatuser['email'];
        dump($hisemail);


        $mail = new \Nette\Mail\Message; // send email with codes if user completed the payment
        $mail->setFrom($this->myemail)
            ->addTo($hisemail)
            ->addTo($this->myemail)
            ->setSubject('Here is your Free Gems code!')
            ->setHtmlBody($mail_body);

        $this->mymailer->send($mail);

        $this->flashMessage("Code processed successfully.", 'success');
    }

    public function actionOrder()
    {
        $this->template->mainName = "Administration::Orders";

        $res = $this->database->table('orders')->where('completed', 0)->fetchAll();

        $orderArr = array();
        foreach ($res as $r)
        {
            $orderArr[$r['id']] = ($r['gems'] == chr(0x01)) ? 400 : 200;
        }

        dump($orderArr);
        $this->template->mygems = $orderArr;
        $this->template->orders = $res;
    }

    public function actionCode()
    {
        $this->template->mainName = "Administration::Codes";

        $res = $this->database->table('codes')->where('free', TRUE)->fetchAll();
        $this->template->codes = $res;
    }


    public function actionSingleCode($codeId) // view code detail
    {
        $this->template->mainName = "Administration::FreeCode";
        $this->template->details = $this->database->table('codes')->where('id', $codeId)->fetch();
    }

    public function actionSingleOrder($orderId) // view our orders
    {
        $this->template->mainName = "Administration::Order";

        $this->template->details = $this->database->table('orders')->where('id', $orderId)->fetch();

        $this->template->det = ($this->template->details['gems'] == chr(0x01)) ? 400 : 200;


    }
}

