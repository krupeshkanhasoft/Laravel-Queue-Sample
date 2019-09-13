<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Templating;
use App\Services\EmailFooter;
use App\EmailQueue;
use App\SmsQueue;
use Twilio\Rest\Client;
use App\Appointment;
use App\MessageHistory;
use App\ContactPermission;
use App\Message;
use Mail;

class Confirmation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automatic:confirmation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Amutomatic Confirmation Messages';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /**
         * Fetching all companies contact email and sms permissions
         * 
         */
        $permissionEmail = ContactPermission::where(['name' => 'Transactional', 'type' => 'email'])->first();
        $permissionSms = ContactPermission::where(['name' => 'Transactional', 'type' => 'sms'])->first();
      
        $footer = EmailFooter::get();
        $now = date("Y-m-d H:i:s");
        $dateto = date("Y-m-d H:i:s", strtotime($now." + 1 minute"));
        /**
         * Check for email queue set with exact or less than current + 1 minute time
         * 
         */
        $emails = EmailQueue::where([['time', '<=', $dateto]])->with(['automatic_message', 'appointment.customer.contactPermissions', 'appointment.appointment_services.add_ons', 'appointment.appointment_services.service', 'appointment.company', 'appointment.quote'])->get();
        foreach($emails as $email){
            $appointment = $email->appointment;
            $company = $appointment->company;
            $customer = $appointment->customer;

            /**
             * If no contact permission than not sending and delete relevent notification from queue
             * 
             */

            if(!$this->check_permissions($customer, $permissionEmail)){
                $email->delete();
                continue;
            }
            $quote = $appointment->quote;
            $template = Templating::single($email->automatic_message->template, $customer, $company, $quote, $appointment);
            $messagehistory = new MessageHistory([
                'email' => $customer->email, 
                'body' => $template.$footer,
                'type' => 'email',
                'main_type' => 'automatic',
            ]);
            switch($email->automatic_message->template_type) {
                case "Confirmation" : 
                    Mail::send([], [], function($message) use ($company, $customer, $template, $footer, $email) {
                        $message->to($customer->email);
                        if($company->email){
                            $message->replyTo($company->email);
                        }
                        $message->from(config('mail.from.address'), $company->name);
                        $message->subject($email->automatic_message->subject);
                        $message->setBody($template.$footer, 'text/html');
                    });
                    $messagehistory['sub_type'] = 'Confirmation';
                    $messagehistory['subject'] = $email->automatic_message->subject;
                    break;
                case "Reminder" :
                    Mail::send([], [], function($message) use ($company, $customer, $template, $footer, $email) {
                        $message->to($customer->email);
                        if($company->email){
                            $message->replyTo($company->email);
                        }
                        $message->from(config('mail.from.address'), $company->name);
                        $message->subject($email->automatic_message->subject);
                        $message->setBody($template.$footer, 'text/html');
                    });
                    $messagehistory['sub_type'] = 'Reminder';
                    $messagehistory['subject'] = $email->automatic_message->subject;
                    break;
                case "Followup" :
                    Mail::send([], [], function($message) use ($company, $customer, $template, $footer, $email) {
                        $message->to($customer->email);
                        if($company->email){
                            $message->replyTo($company->email);
                        }
                        $message->from(config('mail.from.address'), $company->name);
                        $message->subject($email->automatic_message->subject);
                        $message->setBody($template.$footer, 'text/html');
                    });
                    $messagehistory['sub_type'] = 'Followup';
                    $messagehistory['subject'] = $email->automatic_message->subject;
                    break;
                case "OnlineBooking":
                    Mail::send([], [], function($message) use ($company, $customer, $template, $footer, $email) {
                        $message->to($customer->email);
                        if($company->email){
                            $message->replyTo($company->email);
                        }
                        $message->from(config('mail.from.address'), $company->name);
                        $message->subject($email->automatic_message->subject);
                        $message->setBody($template.$footer, 'text/html');
                    });
                    $messagehistory['sub_type'] = 'OnlineBooking';
                    $messagehistory['subject'] = $email->automatic_message->subject;
            }
            $messagehistory->company_id = $company->id;
            $messagehistory->customer_id = $customer->id;
            $messagehistory->save();
            $email->delete();
        }
            /**
             * Fetch List of all sms queue 
             * 
             */
        $Sms = SmsQueue::all();
        echo sizeof($Sms);
        $Sms = SmsQueue::where([['time', '<=', $dateto]])->with(['automatic_message', 'appointment.customer.contactPermissions', 'appointment.company', 'appointment.quote', 'appointment.appointment_services.add_ons', 'appointment.appointment_services.service'])->get();
        foreach($Sms as $sms){
            $appointment = $sms->appointment;
            $quote = $appointment->quote;
            $company = $appointment->company;
            $customer = $appointment->customer;
            /**
             * Checked for permission
             * 
             */
            if(!$this->check_permissions($customer, $permissionSms)){
                $sms->delete();
                continue;
            }

            $template = Templating::single($sms->automatic_message->template, $customer, $company, $quote, $appointment);
            echo $template;
            $messagehistory = new MessageHistory([
                'email' => $customer->phone_number, 
                'body' => $template,
                'type' => 'sms',
                'main_type' => 'automatic',
                'subject' => ''
            ]);
            $success = false;
            switch($sms->automatic_message->template_type) {
                case "Confirmation" : 
                    $success = $this->sendsms($customer, $company, $template, $sms);        
                    $messagehistory['sub_type'] = 'Confirmation';
                    break;
                case "Reminder" :
                    $success = $this->sendsms($customer, $company, $template, $sms);
                    $messagehistory['sub_type'] = 'Reminder';
                    break;
                case "Followup" :
                    $success = $this->sendsms($customer, $company, $template, $sms);
                    $messagehistory['sub_type'] = 'Followup';
                    break;
                case "OnlineBooking" :
                    $success = $this->sendsms($customer, $company, $template, $sms);
                    $messagehistory['sub_type'] = 'OnlineBooking';
                    break;
            }

            if($success){
                $messagehistory->company_id = $company->id;
                $messagehistory->customer_id = $customer->id;
                $messagehistory->save();

                $message = new Message();
                $message->body = $template;
                $message->company_id = $company->id;
                $message->customer_id = $customer->id;
                $message->sender = 'company';
                $message->save();

                $customer->last_sms_activity = $message->created_at;
                $customer->save();
            } else $messagehistory = [];
            //add to chat
        }
    }

    private function sendsms($customer, $company, $message, $sms){
        echo "SENDING SMS";
        /**
         * Check for customer phone number else return false
         * 
         */
        if(!isset($customer['phone_number'])){
            echo " CUSTOMER NO PHONE NUMBER";
            return false;
        }

        $account_sid = config("app.twilio_sid");
        $auth_token = config("app.twilio_token");

        $twilio_number = $company->twilio_number;
         /**
         * Check for company contain twilio number else return false
         * 
         */
        if(!isset($company->twilio_number)){
            echo " COMPANY NO TWILIO NUMBER";
            return false;
        }
        /**
         * Check company have credits else return false
         * 
         */
        if($company->message_credits <= 0){
            echo " COMPANY NO CREDITS";
            return false;
        } else {
            $company->message_credits = $company->message_credits - ceil(strlen($message) / 160);
        }

        $client = new Client($account_sid, $auth_token);

        try{
            $twilio_message = $client->messages->create(
                // Where to send a text message (your cell phone?)
                $customer->phone_number,
            array(
                // 'from' => $twilio_number,
                'from' => $company->twilio_number,
                'body' => $message
                )
            );
        } catch (\Twilio\Exceptions\RestException $e){
            echo $e;
            return false;
        }
        $sms->delete(); // delete from queue after successfully sent
        return true;
    }
    /**
     *  Contact permission function for checking customer contact permission
     * 
     */
    private function check_permissions($customer, $permission)
    {
        foreach ($customer->contactPermissions as $contactPermission ) {
            if($contactPermission['id'] == $permission['id'])
                return true;
        }
        return false;
    }
}
