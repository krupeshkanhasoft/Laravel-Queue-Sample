<?php

namespace App\Console\Commands;
use App\Services\GetClientTime;

use App\AutomaticMessage;
use App\EmailQueue;
use App\SmsQueue;
use App\Appointment;
use Illuminate\Console\Command;

class AutomaticRemindersFollowups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automatic:reminderfollowup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
         * Fetch list of enable automatic messages campaign with general settings and services
         * 
         */
        $time = 
        $automatic_messages = AutomaticMessage::where('enabled', true)
                                ->whereIn('template_type', ['Followup', 'Reminder'])
                                ->with(['company.general_settings', 'services' => function($q){
                                    $q->select(['service_id']);
                                }])
                                ->get();

        foreach ($automatic_messages as $automatic_message) {
            $company = $automatic_message->company; 
            $timezone = $company->general_settings->timezone;
            $time = GetClientTime::get_current_client_time($timezone);
            $server_time = date('Y-m-d H:i:s');
            
            $start_time = null;
            $end_time = null;

            $service_ids = array_column($automatic_message->services->toArray(), 'service_id');
            
            /**
             * Fetch list of Confirmed appointments with services where in service id list
             * 
             */

            $appointments = Appointment::where('company_id', $automatic_message->company_id)
                                ->where('status', 'Confirmed')
                                ->whereHas('appointment_services', function($query) use ($service_ids) {
                                    $query->whereIn('service_id', $service_ids);
                                });

            if($automatic_message->template_type == 'Followup'){
                $end_time = date('Y-m-d H:i:00', strtotime($time." - ".$automatic_message->offset." minutes"));
                echo $end_time;
                $appointments->where('end_time', $end_time);
            } else {
                $start_time = date('Y-m-d H:i:00', strtotime($time." + ".$automatic_message->offset." minutes"));
                $appointments->where('start_time', $start_time);
            }
            /**
             * Appointment list matching with respective campaign time
             * 
             */
            $appointments = $appointments->get(['id']);

            echo $automatic_message->company_id." ".sizeof($appointments)."\n";
            /**
             * Adding appointemnt in sms and email queue
             * 
             */
            foreach ($appointments as $appointment) {
                if($automatic_message->type == 'email'){
                    $queue = new EmailQueue();
                } else {
                    $queue = new SmsQueue();
                }
                $queue->appointment_id = $appointment->id;
                $queue->automatic_message_id = $automatic_message->id;
                $queue->time = $server_time;
                $queue->save();
            }
        }
    }
}
