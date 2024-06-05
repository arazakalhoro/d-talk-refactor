<?php

/**
 * This file is part of the repository package.
 *
 * (c) DigitalTolk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * PHP Version 8.2
 *
 * @category Repository_Class
 * @package  DTApi\Repository
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */

namespace DTApi\Repository;

use Carbon\Carbon;
use DTApi\Events\JobWasCanceled;
use DTApi\Events\JobWasCreated;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Helpers\TeHelper;
use DTApi\Mailers\AppMailer;
use DTApi\Mailers\MailerInterface;
use DTApi\Models\Job;
use DTApi\Models\Language;
use DTApi\Models\Translator;
use DTApi\Models\User;
use DTApi\Models\UserLanguages;
use DTApi\Models\UserMeta;
use DTApi\Models\UsersBlacklist;
use DTApi\Notifications\PushNotification;
use Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class BookingRepository
 *
 * @category Controller_Class
 * @package  DTApi\Repository
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */
class BookingRepository extends BaseRepository
{
    /**
     * The Job model instance.
     *
     * @var Job
     */
    protected $model;
    /**
     * The MailerInterface instance.
     *
     * @var MailerInterface
     */
    protected $mailer;

    /**
     * The PushNotification instance.
     *
     * @var \PushNotification
     */
    protected $push_notifier;

    /**
     * The Logger instance for admin logs.
     *
     * @var Logger
     */
    protected $logger;
    /**
     * The Logger instance for push logs.
     *
     * @var Logger
     */
    protected $pusher_logger;
    /**
     * JobController constructor.
     *
     * @param Job             $model  The Job model instance.
     * @param MailerInterface $mailer The Mailer interface instance.
     */
    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->push_notifier = new PushNotification();
        $this->logger = new Logger('admin_logger');


        $this->logger->pushHandler(
            new StreamHandler(
                storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $this->logger->pushHandler(new FirePHPHandler());

        $this->pusher_logger = new Logger('push_logger');
        $this->pusher_logger->pushHandler(
            new StreamHandler(
                storage_path(
                    'logs/push/laravel-' . date('Y-m-d')
                    . '.log'
                ),
                Logger::DEBUG
            )
        );
        $this->pusher_logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Retrieves jobs associated with a given user, categorized into emergency and normal jobs.
     *
     * This method fetches jobs for the user based on their role (customer or translator).
     * It separates the jobs into two categories: emergency jobs (jobs marked as 'immediate')
     * and normal jobs (all other jobs). Normal jobs are further processed to include a 'usercheck'
     * status and are sorted by their due date.
     *
     * @param int $userId The ID of the user for whom to retrieve jobs.
     *
     * @return array An associative array containing the emergency and normal jobs,
     *               along with the current user object and the user's type.
     */
    public function getUsersJobs($userId)
    {
        $currentUser = User::find($userId);
        $emergencyJobs = [];
        $jobs = [];

        $userType = $currentUser && $currentUser->is('customer') ? 'customer' :
            ($currentUser && $currentUser->is('translator') ? 'translator' : '');

        if ($userType === 'customer') {
            $jobs = $currentUser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } elseif ($userType === 'translator') {
            $jobs = Job::getTranslatorJobs($currentUser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
        }

        if ($jobs) {
            $emergencyJobs = collect($jobs)->where('immediate', '=', 'yes')->toArray();

            $normalJobs = collect($jobs)
                ->where('immediate', '!=', 'yes')
                ->map(
                    function ($item) use ($userId) {
                        $item['usercheck'] = Job::checkParticularJob($userId, $item);
                        return $item;
                    }
                )
                ->sortBy('due')
                ->values()
                ->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'currentUser' => $currentUser,
            'userType' => $userType
        ];
    }

    /**
     * Fetches the job history for a given user.
     *
     * This method retrieves the job history for a user based on their role (customer or translator).
     * It supports pagination and filters jobs based on their completion status.
     *
     * @param int     $user_id The ID of the user whose job history is to be retrieved.
     * @param Request $request The current request instance containing pagination parameters.
     *
     * @return array An array containing job history data and related user information.
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', '1');
        $currentUser = User::find($user_id);
        $emergencyJobs = [];
        $normalJobs = [];
        $usertype = '';
        $jobs = [];
        $numpages = 0;
        $pagenum = $page;

        if ($currentUser && $currentUser->is('customer')) {
            $jobs = $currentUser->jobs()
                ->with(
                    'user.userMeta',
                    'user.average',
                    'translatorJobRel.user.average',
                    'language',
                    'feedback',
                    'distance'
                )
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
            $usertype = 'customer';
        } elseif ($currentUser && $currentUser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($currentUser->id, 'historic', $page);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);
            $usertype = 'translator';
            $normalJobs = $jobs_ids;
            $jobs = $jobs_ids;
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $currentUser,
            'usertype' => $usertype,
            'numpages' => $numpages,
            'pagenum' => $pagenum
        ];
    }
    /**
     * Validate the job data.
     *
     * @param array $data The job data to validate.
     *
     * @return array An array containing any validation errors, or an empty array if the data is valid.
     */
    private function validateJobData(array $data): array
    {
        $error = [];
        $rules = [
            'from_language_id' => 'required',
            'due_date' => 'required_if:immediate,no',
            'due_time' => 'required_if:immediate,no',
            'duration' => 'required',
            'customer_phone_type' => 'required_without:customer_physical_type',
            'customer_physical_type' => 'required_without:customer_phone_type',
        ];

        $messages = [
            'from_language_id.required' => 'Du måste fylla in alla fält',
            'due_date.required_if' => 'Du måste fylla in alla fält',
            'due_time.required_if' => 'Du måste fylla in alla fält',
            'duration.required' => 'Du måste fylla in alla fält',
            'customer_phone_type.required_without' => 'Du måste göra ett val här',
            'customer_physical_type.required_without' => 'Du måste göra ett val här',
        ];
        $validator = $this->validator($data, $rules, $messages);
        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = $errors->keys()[0];

            $error = [
                'status' => 'fail',
                'message' => $errors->first(),
                'field_name' => $firstError
            ];
        }
        return $error;
    }

    /**
     * Store a new job for the given user and data.
     *
     * @param DTApi\Models\User $user The user for whom the job is being created.
     * @param array             $data The data for the new job.
     *
     * @return array An array containing the status of the operation, the ID of the new job, and other relevant
     *                        information.
     */
    public function store(User $user, array $data): array
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;
        if ($user->user_type !== \config('customer_role_id')) {
            return $this->failResponse('Översättare kan inte skapa bokning');
        }

        $currentUser = $user;
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $due = '';
        $error = $this->validateJobData($data);
        if (!empty($error)) {
            return $error;
        }

        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            if ($due_carbon->isPast()) {
                $response['status'] = 'fail';
                $response['message'] = "Kan inte skapa bokning i det förflutna";
                return $response;
            }
        }
        $gender = array_intersect(['male', 'female'], $data['job_for']);
        $data['gender'] = ($gender[0] ?? null);
        $data['job_type'] = ($consumerType == 'rwsconsumer') ? 'rws' : (($consumerType == 'ngo') ? 'unpaid' : 'paid');
        $data['certified'] = 'normal';
        if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
            $data['certified'] = 'both';
        } elseif (
            in_array('certified_in_law', $data['job_for'])
            && in_array('certified', $data['job_for'])
        ) {
            $data['certified'] = 'law';
        } elseif (
            in_array('certified_in_helth', $data['job_for'])
            && in_array('certified', $data['job_for'])
        ) {
            $data['certified'] = 'health';
        } elseif (
            in_array('certified_in_law', $data['job_for'])
            && in_array('normal', $data['job_for'])
        ) {
            $data['certified'] = 'n_law';
        } elseif (
            in_array('certified_in_helth', $data['job_for'])
            && in_array('normal', $data['job_for'])
        ) {
            $data['certified'] = 'n_health';
        }
        $data['b_created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = ($due ? TeHelper::willExpireAt($due, $data['b_created_at']) : null);
        $data['by_admin'] = $data['by_admin'] ?? 'no';
        $job = $currentUser->jobs()->create($data);
        $response['status'] = 'success';
        $response['id'] = $job->id;

        $response['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        $response['job_for'][] = $job->certified != null
            ? ($job->certified == 'both'
                ? ['normal', 'certified']
                : ($job->certified == 'yes'
                    ? ['certified']
                    : [$job->certified]))
            : [];
        $response['job_for'] = array_filter(
            $response['job_for'],
            function ($value) {
                return !empty($value);
            }
        );
        $response['customer_town'] = $currentUser->userMeta->city;
        $response['customer_type'] = $currentUser->userMeta->customer_type;
        if ($data['immediate'] == 'yes') {
            Event::fire(new JobWasCreated($job, $data, '*'));
            //$this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
        }
        return $response;
    }
    /**
     * Store a new job email and send a confirmation email to the user.
     *
     * @param array $data The data for the new job email, including the user type, job ID, user email, reference, and
     *                    address information.
     *
     * @return array An array containing the response data, including the user type, job object, and status.
     **/
    public function storeJobEmail(array $data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail($data['user_email_job_id']);
        if (empty($job)) {
            return  [
                'fail' => true,
                'status' => 'error',
                'message' => 'Jobdetaljer hittades inte'
            ];
        }
        $job->user_email = ($data['user_email'] ?? '');
        $job->reference = ($data['reference'] ??  '');
        $user = $job->user()->get()->first();
        if (!empty($data['address'])) {
            $job->address = ($data['address'] ?? $user->userMeta->address);
            $job->instructions = ($data['instructions'] ?? $user->userMeta->instructions);
            $job->town = ($data['town'] ?? $user->userMeta->city);
        }
        $job->save();
        $name = $user->name;
        $email = ( $job->user_email ?? $user->email );

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }

    /**
     * Converts a Job object to a data array.
     *
     * @param Job $job The Job object to convert.
     *
     * @return array The data array representation of the Job object.
     */
    public function jobToData(Job $job): array
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
        ];
        // save job's information to data for sending Push

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } elseif ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }
        }
        return $data;
    }
    /**
     * Marks a job as completed and sends notifications to the user and translator.
     *
     * @param array $post_data The post data containing the job ID and user ID.
     *
     * @return void
     * @throws \Exception If the job or translator job relation cannot be found.
     */
    public function jobEnd(array $post_data = [])
    {
        try {
            $jobId = $post_data["job_id"];
            $job = Job::with('translatorJobRel')->find($jobId);
            if (!$job) {
                throw new \Exception("Job not found");
            }

            $completedDate = now();
            $dueDate = $job->due;
            $interval = $completedDate->diff($dueDate);

            $job->end_at = $completedDate;
            $job->status = 'completed';
            $job->session_time = $interval->format('%H:%I:%S');

            $user = $job->user()->first();
            if (!$user) {
                throw new \Exception("User not found");
            }

            $email = $job->user_email ?: $user->email;
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $sessionTime = $interval->format('%H tim %I min');
            $data = [
                'user' => $user,
                'job' => $job,
                'ession_time' => $sessionTime,
                'for_text' => 'faktura'
            ];

            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $job->save();

            $translatorJobRel = $job->translatorJobRel
                ->whereNull('completed_at')
                ->whereNull('cancel_at')
                ->first();
            if (!$translatorJobRel) {
                throw new \Exception("Translator job relation not found");
            }

            Event::fire(
                new SessionEnded(
                    $job,
                    ($post_data['userid'] == $job->user_id)
                    ? $translatorJobRel->user_id
                    : $job->user_id
                )
            );

            $translatorUser = $translatorJobRel->user()->first();
            if (!$translatorUser) {
                throw new \Exception("Translator user not found");
            }

            $email = $translatorUser->email;
            $name = $translatorUser->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $data = [
                'user' => $translatorUser,
                'job' => $job,
                'ession_time' => $sessionTime,
                'for_text' => 'lön'
            ];

            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $translatorJobRel->completed_at = $completedDate;
            $translatorJobRel->completed_by = $post_data['userid'];
            $translatorJobRel->save();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves an array of potential job IDs for a user based on their ID.
     *
     * @param int $userId The user ID.
     *
     * @return array An array of potential job IDs.
     */
    public function getPotentialJobIdsWithUserId(int $userId): array
    {
        try {
            $userMeta = UserMeta::where('user_id', $userId)->firstOrFail();
            $translatorType = $userMeta->translator_type;
            $jobType = match ($translatorType) {
                'professional' => 'paid',
                'rwstranslator' => 'rws',
                'volunteer' => 'unpaid',
                default => 'unpaid',
            };

            $languages = UserLanguages::where('user_id', $userId)->get();
            $userLanguages = $languages->pluck('lang_id')->all();
            $gender = $userMeta->gender;
            $translatorLevel = $userMeta->translator_level;

            $jobIds = Job::getJobs($userId, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

            $filteredJobIds = collect($jobIds)->filter(
                function ($job) use ($userId) {
                    $jobUser = Job::find($job->id);
                    $jobUserId = $jobUser->user_id;
                    $checkTown = Job::checkTowns($jobUserId, $userId);
                    return !($jobUser->customer_phone_type === 'no' || $jobUser->customer_phone_type === '') &&
                    $jobUser->customer_physical_type === 'yes' &&
                    $checkTown;
                }
            )->all();

            $jobs = TeHelper::convertJobIdsInObjs($filteredJobIds);
            return $jobs;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Sends a notification to suitable translators for a job.
     *
     * @param Job   $job           The job object.
     * @param array $data          The data array.
     * @param int   $excludeUserId The user ID to exclude.
     *
     * @return void
     */
    public function sendNotificationTranslator(Job $job, array $data, int $excludeUserId)
    {
        try {
            $users = User::where('user_type', 2)
                ->where('status', 1)
                ->where('id', '!=', $excludeUserId)
                ->get();

            $translatorArray = [];
            $delayedTranslatorArray = [];

            foreach ($users as $user) {
                if (!$this->isNeedToSendPush($user->id)) {
                    continue;
                }

                $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') {
                    continue;
                }

                $potentialJobs = $this->getPotentialJobIdsWithUserId($user->id);
                foreach ($potentialJobs as $potentialJob) {
                    if ($job->id == $potentialJob->id) {
                        $userId = $user->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $potentialJob->id);
                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $potentialJob);
                            if ($jobChecker != 'userCanNotAcceptJob') {
                                if ($this->isNeedToDelayPush($user->id)) {
                                    $delayedTranslatorArray[] = $user;
                                } else {
                                    $translatorArray[] = $user;
                                }
                            }
                        }
                    }
                }
            }

            $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
            $data['notification_type'] = 'suitable_job';

            $msgContents = $data['immediate'] == 'no'
                ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
                : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

            $msgText = [
                "en" => $msgContents,
            ];

            $this->push_notifier->sendPushNotificationToSpecificUsers(
                $translatorArray,
                $job->id,
                $data,
                $msgText,
                false
            );
            $this->push_notifier->sendPushNotificationToSpecificUsers(
                $delayedTranslatorArray,
                $job->id,
                $data,
                $msgText,
                true
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Sends SMS notifications to translators and returns the count of translators.
     *
     * @param Job $job The job object.
     *
     * @return int The count of translators.
     */
    public function sendSMSNotificationToTranslator(Job $job): int
    {
        try {
            $translators = $this->getPotentialTranslators($job);
            $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->firstOrFail();

            $date = date('d.m.Y', strtotime($job->due));
            $time = date('H:i', strtotime($job->due));
            $duration = $this->convertToHoursMins($job->duration);
            $jobId = $job->id;
            $city = $job->city ?: $jobPosterMeta->city;

            $messageTemplates = [
                'phone' => trans(
                    'sms.phone_job',
                    ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]
                ),
                'physical' => trans(
                    'sms.physical_job',
                    ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]
                ),
            ];

            $message = match (true) {
                $job->customer_physical_type === 'yes'
                && $job->customer_phone_type === 'no' => $messageTemplates['physical'],
                $job->customer_physical_type === 'no'
                && $job->customer_phone_type === 'yes' => $messageTemplates['phone'],
                $job->customer_physical_type === 'yes'
                && $job->customer_phone_type === 'yes' => $messageTemplates['phone'],
                default => '',
            };

            Log::info($message);

            foreach ($translators as $translator) {
                $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
                Log::info(
                    "Send SMS to {$translator->email} ({$translator->mobile}), status: "
                    . print_r($status, true)
                );
            }

            return count($translators);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Checks if a push notification should be delayed for a user.
     *
     * @param int $userId The user ID.
     *
     * @return bool Whether the push notification should be delayed.
     */
    public function isNeedToDelayPush(int $userId): bool
    {
        if (DateTimeHelper::isDayTime()) {
            return false;
        }

        $notGetNighttime = TeHelper::getUsermeta($userId, 'not_get_nighttime');

        return $notGetNighttime === 'yes';
    }
    /**
     * Checks if a push notification should be sent to a user.
     *
     * @param int $userId The user ID.
     *
     * @return bool Whether the push notification should be sent.
     */
    public function isNeedToSendPush(int $userId): bool
    {
        return TeHelper::getUsermeta($userId, 'not_get_notification') !== 'yes';
    }

    /**
     * Retrieves a list of translator levels based on the certification level.
     *
     * @param string|null $certified The certification level of the translator.
     *
     * @return array An array of levels that match the criteria.
     */
    private function getTranslatorLevel(?string $certified): array
    {
        $levels = [];

        if ($certified === 'yes' || $certified === 'both') {
            $levels[] = 'Certified';
            $levels[] = 'Certified with specialisation in law';
            $levels[] = 'Certified with specialisation in health care';
        } elseif ($certified === 'law' || $certified === 'n_law') {
            $levels[] = 'Certified with specialisation in law';
        } elseif ($certified === 'health' || $certified === 'n_health') {
            $levels[] = 'Certified with specialisation in health care';
        } elseif ($certified === 'normal' || $certified === 'both') {
            $levels[] = 'Layman';
            $levels[] = 'Read Translation courses';
        } else {
            $levels[] = 'Certified';
            $levels[] = 'Certified with specialisation in law';
            $levels[] = 'Certified with specialisation in health care';
            $levels[] = 'Layman';
            $levels[] = 'Read Translation courses';
        }

        return $levels;
    }
    /**
     * Retrieves a list of potential translators for a given job based on the job type, language, gender, and
     * certification level.
     * It also excludes any translators that are on the user's blacklist.
     *
     * @param Job $job The job object containing the job type, language, gender, and certification level.
     *
     * @return array An array of users that match the criteria.
     */
    public function getPotentialTranslators(Job $job): array
    {
        $translator_type = match ($job->job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
        };
        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = $this->getTranslatorLevel($job->certified);
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);
        return $users;
    }

    /**
     * Update job details against current user
     *
     * @param int   $id          primary id for job
     * @param array $data        data need to update
     * @param User  $currentUser current logged-in user
     *
     * @return array
     */
    public function updateJob(int $id, array $data, User $currentUser): array
    {
        $job = Job::find($id);
        $logData = [];
        $current_translator = $job->translatorJobRel->whereNull('cancel_at')->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];
        $this->logger->addInfo(
            "USER #{$currentUser->id} ({$currentUser->name}) has been updated booking <a class='openjob' " .
            "href='/admin/jobs/{$id}'>#{$id}</a> with data: ",
            $logData
        );
        $job->save();
        if ($job->due >= now()) {
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification(
                    $job,
                    $current_translator,
                    $changeTranslator['new_translator']
                );
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }

        return ['Updated'];
    }

    /**
     * Change the status of the job based on the provided data and the current status.
     *
     * @param Job   $job               The job instance whose status is to be changed.
     * @param array $data              The data array containing the new status and other relevant information.
     * @param bool  $changedTranslator Indicates if the translator has been changed.
     *
     * @return array An array containing 'statusChanged' (bool) and 'log_data' (array).
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedOutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
            }
        }
        return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
    }

    /**
     * Change the status of a timed-out job.
     *
     * @param Job   $job               The job instance whose status is to be changed.
     * @param array $data              The data array containing the new status and other relevant information.
     * @param bool  $changedTranslator Indicates if the translator has been changed.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changeTimedOutStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] === 'pending') {
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id)
                . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');
            // send Push to all suitable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }
        return false;
    }


    /**
     * Change the status of a completed job.
     *
     * @param Job   $job  The job instance whose status is to be changed.
     * @param array $data The data array containing the new status and other relevant information.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changeCompletedStatus(Job $job, array $data): bool
    {
        $job->status = $data['status'];

        if ($data['status'] === 'timedout') {
            if (empty($data['admin_comments'])) {
                return false;
            }
            $job->admin_comments = $data['admin_comments'];
        }

        $job->save();
        return true;
    }

    /**
     * Change the status of a started job.
     *
     * @param Job   $job  The job instance whose status is to be changed.
     * @param array $data The data array containing the new status and other relevant information.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changeStartedStatus(Job $job, array $data): bool
    {
        $job->status = $data['status'];

        if (empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] === 'completed') {
            $user = $job->user()->first();

            if (empty($data['sesion_time'])) {
                return false;
            }

            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $email = $job->user_email ?: $user->email;
            $name = $user->name;
            $dataEmail = [
                'user' => $user,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
            $email = $translator->user->email;
            $name = $translator->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $dataEmail = [
                'user' => $translator,
                'job' => $job,
                'session_time' => $session_time,
                'for_text' => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
        }

        $job->save();
        return true;
    }


    /**
     * Change the status of a pending job.
     *
     * @param Job   $job               The job instance whose status is to be changed.
     * @param array $data              The data array containing the new status and other relevant information.
     * @param bool  $changedTranslator Indicates if the translator has been changed.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changePendingStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        $job->status = $data['status'];

        if (empty($data['admin_comments']) && $data['status'] === 'timedout') {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job' => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send(
                $translator->email,
                $translator->name,
                $subject,
                'emails.job-changed-translator-new-translator',
                $dataEmail
            );

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            if ($this->isNeedToSendPush($user->id)) {
                $this->push_notifier->sendSessionStartRemindNotification(
                    $user,
                    $job,
                    $language,
                    $job->due,
                    $job->duration,
                    $this->isNeedToDelayPush($user->id)
                );
            }
            if ($this->isNeedToSendPush($translator->id)) {
                $this->push_notifier->sendSessionStartRemindNotification(
                    $translator,
                    $job,
                    $language,
                    $job->due,
                    $job->duration,
                    $this->isNeedToDelayPush($user->id)
                );
            }

            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                'emails.status-changed-from-pending-or-assigned-customer',
                $dataEmail
            );
            $job->save();

            return true;
        }
    }

    /**
     * Change the status of a job to "withdrawn after 24 hours" if the new status is "timed out".
     *
     * @param Job   $job  The job instance whose status is to be changed.
     * @param array $data The data array containing the new status and other relevant information.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changeWithdrawafter24Status(Job $job, array $data): bool
    {
        if (in_array($data['status'], ['timedout'])) {
            if (empty($data['admin_comments'])) {
                return false;
            }

            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }
    /**
     * Change the status of a job to "withdrawn before 24 hours", "withdrawn after 24 hours", or "timed out".
     *
     * @param Job   $job  The job instance whose status is to be changed.
     * @param array $data The data array containing the new status and other relevant information.
     *
     * @return bool True if the status was changed, false otherwise.
     */
    private function changeAssignedStatus(Job $job, array $data): bool
    {
        if (!in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            return false;
        }

        if (empty($data['admin_comments']) && $data['status'] == 'timedout') {
            return false;
        }

        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];

        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $user = $job->user()->first();
            $email = $job->user_email ?? $user->email;
            $name = $user->name;
            $dataEmail = ['user' => $user, 'job' => $job];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send(
                $email,
                $name,
                $subject,
                'emails.status-changed-from-pending-or-assigned-customer',
                $dataEmail
            );

            $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
            $translatorEmail = $translator->user->email;
            $translatorName = $translator->user->name;
            $translatorDataEmail = ['user' => $translator, 'job' => $job];

            $this->mailer->send(
                $translatorEmail,
                $translatorName,
                $subject,
                'emails.job-cancel-translator',
                $translatorDataEmail
            );
        }

        $job->save();
        return true;
    }

    /**
     * Change the translator of a job if necessary.
     *
     * @param Translator|null $current_translator The current translator relationship for the job.
     * @param array           $data               The data containing new translator information.
     * @param Job             $job                The job instance whose translator is to be changed.
     *
     * @return array The result of the translator change operation including whether it was changed, the new translator,
     *               and log data.
     */
    private function changeTranslator(?Translator $current_translator, array $data, Job $job): array
    {
        $translatorChanged = false;
        $log_data = [];

        if (isset($data['translator']) && $data['translator'] != 0) {
            $translatorId = $data['translator'];
        } elseif (!empty($data['translator_email'])) {
            $translatorId = User::where('email', $data['translator_email'])->first()->id;
        } else {
            $translatorId = null;
        }

        if ($current_translator && $translatorId && $current_translator->user_id != $translatorId) {
            $new_translator = $current_translator->replicate()->fill(['user_id' => $translatorId]);
            $new_translator->save();
            $current_translator->cancel_at = Carbon::now();
            $current_translator->save();
            $log_data[] = [
                'old_translator' => $current_translator->user->email,
                'new_translator' => $new_translator->user->email,
            ];
            $translatorChanged = true;
        } elseif (!$current_translator && $translatorId) {
            $new_translator = Translator::create(['user_id' => $translatorId, 'job_id' => $job->id]);
            $log_data[] = [
                'old_translator' => null,
                'new_translator' => $new_translator->user->email,
            ];
            $translatorChanged = true;
        }

        return [
            'translatorChanged' => $translatorChanged,
            'new_translator' => $new_translator ?? null,
            'log_data' => $log_data,
        ];
    }
    /**
     * Check and log if the due date of a job has changed.
     *
     * @param string $old_due The old due date.
     * @param string $new_due The new due date.
     *
     * @return array The result of the due date change operation including whether it was changed and log data.
     */
    private function changeDue(string $old_due, string $new_due): array
    {
        $dateChanged = false;
        $log_data = [];

        if ($old_due !== $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
        }

        return [
            'dateChanged' => $dateChanged,
            'log_data' => $log_data
        ];
    }
    /**
     * Send notifications when the translator of a job is changed.
     *
     * @param Job             $job                The job instance.
     * @param Translator|null $current_translator The current translator instance.
     * @param Translator      $new_translator     The new translator instance.
     *
     * @return void
     */
    public function sendChangedTranslatorNotification(
        Job $job,
        ?Translator $current_translator,
        Translator $new_translator
    ): void {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;

        $data = ['user' => $user, 'job' => $job];

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;
            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }


    /**
     * Send notifications when the date of a job is changed.
     *
     * @param Job    $job      The job instance.
     * @param string $old_time The old due date.
     *
     * @return void
     */
    public function sendChangedDateNotification(Job $job, string $old_time): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = ['user' => $user, 'job' => $job, 'old_time' => $old_time];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = ['user' => $translator, 'job' => $job, 'old_time' => $old_time];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }
    /**
     * Send notifications when the language of a job is changed.
     *
     * @param Job $job      The job instance.
     * @param int $old_lang The old language ID.
     *
     * @return void
     */
    public function sendChangedLangNotification(Job $job, int $old_lang): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = ['user' => $user, 'job' => $job, 'old_lang' => $old_lang];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }
    /**
     * Send a push notification for an expired job.
     *
     * @param Job  $job  The expired job.
     * @param User $user The user associated with the job.
     *
     * @return void
     */
    public function sendExpiredNotification(Job $job, User $user): void
    {
        $data = ['notification_type' => 'job_expired'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            'en' => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, '
                . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->push_notifier->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Send a notification for admin-cancelled job to suitable translators.
     *
     * @param int $job_id The ID of the cancelled job.
     *
     * @return void
     */
    public function sendNotificationByAdminCancelJob(int $job_id): void
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $user_meta->city,
            'customer_type' => $user_meta->customer_type,
            'due_date' => date('Y-m-d', strtotime($job->due)),
            'due_time' => date('H:i:s', strtotime($job->due)),
            'job_for' => [],
        ];

        if ($job->gender !== null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified !== null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = ($job->certified == 'yes') ? 'certified' : $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');
    }

    /**
     * Send a session start reminder notification.
     *
     * @param mixed  $user     The user to send the notification to.
     * @param mixed  $job      The job for which the reminder is sent.
     * @param string $language The language for the job.
     * @param string $due      The due date for the job.
     * @param string $duration The duration of the job.
     *
     * @return void
     */
    private function sendNotificationChangePending($user, $job, string $language, string $due, string $duration): void
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $msg_text = ($job->customer_physical_type == 'yes')
            ? ['en' => "Du har nu fått platstolkningen för $language kl $duration den $due. Vänligen säkerställ att " .
                "du är förberedd för den tiden. Tack!"]
            : ['en' => "Du har nu fått telefontolkningen för $language kl $duration den $due. Vänligen säkerställ att" .
                " du är förberedd för den tiden. Tack!"];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->push_notifier->sendPushNotificationToSpecificUsers(
                [$user],
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Accept a job request.
     *
     * @param array $data The job data.
     * @param User  $user The user accepting the job.
     *
     * @return array The response array.
     */
    public function acceptJob(array $data, User $user): array
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $response = [
            'status' => 'fail',
            'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
        ];
        if (!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $customer = $job->user()->first();
                $email = $customer->email;
                $name = $customer->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')';
                $data = ['user' => $customer, 'job' => $job];
                $mailer = new AppMailer();
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $jobs = $this->getPotentialJobs($user);

                $response = [
                    'status' => 'success',
                    'list' => json_encode(['jobs' => $jobs, 'job' => $job], true)
                ];
            }
        }
        return $response;
    }
    /**
     * Accept a job by its ID.
     *
     * @param int  $jobId The ID of the job to accept.
     * @param User $user  The user accepting the job.
     *
     * @return array The response array.
     */
    public function acceptJobWithId(int $jobId, User $user): array
    {
        $job = Job::findOrFail($jobId);
        $response = [
            'status' => 'fail',
            'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
        ];

        if (!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $jobId)) {
                $job->status = 'assigned';
                $job->save();
                $customer = $job->user()->first();
                $mailer = new AppMailer();

                $email = !empty($job->user_email) ? $job->user_email : $customer->email;
                $name = $customer->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')';
                $data = ['user' => $customer, 'job' => $job];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $notificationData = ['notification_type' => 'job_accepted'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msgText = ["en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, '
                    . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'];
                if ($this->isNeedToSendPush($customer->id)) {
                    $this->push_notifier->sendPushNotificationToSpecificUsers(
                        [$customer],
                        $jobId,
                        $notificationData,
                        $msgText,
                        $this->isNeedToDelayPush($customer->id)
                    );
                }

                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk '
                    . $job->duration . 'min ' . $job->due;
            } else {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . 'min ' . $job->due
                    . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        }

        return $response;
    }
    /**
     * Handles the cancellation of a job by a user.
     *
     * @param array $data The data containing the job ID and user ID.
     * @param User  $user The current user.
     *
     * @return array An array with a status of "success" or "fail" and a message if the cancellation is after 24 hours.
     */
    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to
            his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } else {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, '
                        . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->push_notifier->sendPushNotificationToSpecificUsers(
                        $users_array,
                        $job_id,
                        $data,
                        $msg_text,
                        $this->isNeedToDelayPush($translator->id)
                    );// send Session Cancel Push to Translaotor
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due
                            . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->push_notifier->sendPushNotificationToSpecificUsers(
                            $users_array,
                            $job_id,
                            $data,
                            $msg_text,
                            $this->isNeedToDelayPush($customer->id)
                        );     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
                //                Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. ' .
                    'Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }
    /**
     * Retrieves a list of potential jobs for a given user based on their language skills, gender, and translator level.
     *
     * @param User $currentUser The current user.
     *
     * @return Collection A collection of job IDs.
     */
    public function getPotentialJobs($currentUser)
    {
        $cuserMeta = $currentUser->userMeta;
        $jobType = 'unpaid';

        switch ($cuserMeta->translator_type) {
            case 'professional':
                $jobType = 'paid';
                break;
            case 'rwstranslator':
                $jobType = 'rws';
                break;
            case 'volunteer':
                $jobType = 'unpaid';
                break;
        }

        $languages = UserLanguages::whereUserId($currentUser->id)->get();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        $jobIds = Job::getJobs($currentUser->id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        foreach ($jobIds as $k => $job) {
            $jobUserId = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($currentUser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($currentUser->id, $job);
            $checkTown = Job::checkTowns($jobUserId, $currentUser->id);

            if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                unset($jobIds[$k]);
            }

            if (
                ($job->customer_phone_type == 'no' || $job->customer_phone_type == '')
                && $job->customer_physical_type == 'yes'
                && !$checkTown
            ) {
                unset($jobIds[$k]);
            }
        }

        return $jobIds;
    }
    /**
     * Ends a job and updates the status to "completed".
     *
     * @param array $postData The post data containing the job ID and user ID.
     *
     * @return array An array with a status of "success".
     */
    public function endJob($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        if ($jobDetail->status !== 'started') {
            return ['status' => 'success'];
        }

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $jobDetail->end_at = date('Y-m-d H:i:s');
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;

        $user = $jobDetail->user;
        $email = !empty($jobDetail->user_email) ? $jobDetail->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $sessionTime = explode(':', $jobDetail->session_time);
        $sessionTimeText = $sessionTime[0] . ' tim ' . $sessionTime[1] . ' min';
        $data = [
            'user' => $user,
            'job' => $jobDetail,
            'session_time' => $sessionTimeText,
            'for_text' => 'faktura'
        ];

        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $jobDetail->save();

        $translatorJobRel = $jobDetail
            ->translatorJobRel()
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        Event::fire(
            new SessionEnded(
                $jobDetail,
                ($postData['user_id'] == $jobDetail->user_id)
                ? $translatorJobRel->user_id
                : $jobDetail->user_id
            )
        );

        $translator = $translatorJobRel->user;
        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $data = [
            'user' => $translator,
            'job' => $jobDetail,
            'session_time' => $sessionTimeText,
            'for_text' => 'lön'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translatorJobRel->completed_at = $completedDate;
        $translatorJobRel->completed_by = $postData['user_id'];
        $translatorJobRel->save();

        return ['status' => 'success'];
    }
    /**
     * Updates the status of a job to "not_carried_out_customer" when the customer does not call the translator.
     *
     * @param array $postData The post data containing the job ID.
     *
     * @return array An array with a status of "success".
     */
    public function customerNotCall($postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'not_carried_out_customer';
        $jobDetail->session_time = $interval;

        $translatorJobRel = $jobDetail->translatorJobRel()
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        $translatorJobRel->completed_at = $completedDate;
        $translatorJobRel->completed_by = $translatorJobRel->user_id;

        $jobDetail->save();
        $translatorJobRel->save();

        return ['status' => 'success'];
    }
    /**
     * Applies various filters to a query for jobs based on the provided request data.
     *
     * @param Builder $allJobs     The query for jobs.
     * @param array   $requestData The request data containing the filter parameters.
     *
     * @return Builder The filtered query.
     */
    public function applyFilters(&$allJobs, $requestData)
    {
        $currentUser = auth()->user();
        $consumerType = $currentUser->consumer_type;

        $allJobs->when(
            !empty($requestData['id']),
            function ($query) use ($requestData) {
                $query->whereIn('id', is_array($requestData['id']) ? $requestData['id'] : [$requestData['id']]);
            }
        );

        $allJobs->when(
            !empty($requestData['feedback']),
            function ($query) {
                $query->where('ignore_feedback', 0)
                    ->whereHas(
                        'feedback',
                        function ($q) {
                            $q->where('rating', '<=', 3);
                        }
                    );
            }
        );

        $allJobs->when(
            !empty($requestData['lang']),
            function ($query) use ($requestData) {
                $query->whereIn('from_language_id', $requestData['lang']);
            }
        );

        $allJobs->when(
            !empty($requestData['status']),
            function ($query) use ($requestData) {
                $query->whereIn('status', $requestData['status']);
            }
        );

        $allJobs->when(
            !empty($requestData['job_type']),
            function ($query) use ($requestData) {
                $query->whereIn('job_type', $requestData['job_type']);
            }
        );

        $allJobs->when(
            !empty($requestData['filter_timetype']) && $requestData['filter_timetype'] === 'created',
            function ($query) use ($requestData) {
                $query->when(
                    !empty($requestData['from']),
                    function ($q) use ($requestData) {
                        $q->where('created_at', '>=', $requestData['from']);
                    }
                )
                ->when(
                    !empty($requestData['to']),
                    function ($q) use ($requestData) {
                        $q->where('created_at', '<=', $requestData['to'] . '3:59:00');
                    }
                )
                ->orderBy('created_at', 'desc');
            }
        );

        $allJobs->when(
            !empty($requestData['filter_timetype']) && $requestData['filter_timetype'] === 'due',
            function ($query) use ($requestData) {
                $query->when(
                    !empty($requestData['from']),
                    function ($q) use ($requestData) {
                        $q->where('due', '>=', $requestData['from']);
                    }
                )
                ->when(
                    !empty($requestData['to']),
                    function ($q) use ($requestData) {
                        $q->where('due', '<=', $requestData['to'] . '3:59:00');
                    }
                )
                ->orderBy('due', 'desc');
            }
        );

        if ($currentUser && $currentUser->user_type === config('Super_admin_role_id')) {
            $allJobs->when(
                !empty($requestData['expired_at']),
                function ($query) use ($requestData) {
                    $query->where('expired_at', '>=', $requestData['expired_at']);
                }
            );

            $allJobs->when(
                !empty($requestData['will_expire_at']),
                function ($query) use ($requestData) {
                    $query->where('will_expire_at', '>=', $requestData['will_expire_at']);
                }
            );

            $allJobs->when(
                !empty($requestData['translator_email']),
                function ($query) use ($requestData) {
                    $users = User::whereIn('email', $requestData['translator_email'])
                    ->get();

                    if ($users) {
                        $allJobIDs = DB::table('translator_job_rel')
                            ->whereNull('cancel_at')
                            ->whereIn('user_id', collect($users)->pluck('id')->all())
                            ->lists('job_id');

                        $query->whereIn('id', $allJobIDs);
                    }
                }
            );

            $allJobs->when(
                !empty($requestData['physical']),
                function ($query) use ($requestData) {
                    $query->where('customer_physical_type', $requestData['physical'])
                        ->where('ignore_physical', 0);
                }
            );

            $allJobs->when(
                !empty($requestData['phone']),
                function ($query) use ($requestData) {
                    $query->where('customer_phone_type', $requestData['phone'])
                        ->when(
                            !empty($requestData['physical']),
                            function ($q) {
                                $q->where('ignore_physical_phone', 0);
                            }
                        );
                }
            );

            $allJobs->when(
                !empty($requestData['flagged']),
                function ($query) use ($requestData) {
                    $query->where('flagged', $requestData['flagged'])
                        ->where('ignore_flagged', 0);
                }
            );

            $allJobs->when(
                !empty($requestData['distance']) && $requestData['distance'] === 'empty',
                function ($query) {
                    $query->whereDoesntHave('distance');
                }
            );

            $allJobs->when(
                !empty($requestData['salary']) && $requestData['salary'] == 'yes',
                function ($query) {
                    $query->whereDoesntHave('user.salaries');
                }
            );

            $allJobs->when(
                !empty($requestData['consumer_type']),
                function ($query) use ($requestData) {
                    $query->whereHas(
                        'user.userMeta',
                        function ($q) use ($requestData) {
                            $q->where('consumer_type', $requestData['consumer_type']);
                        }
                    );
                }
            );

            $allJobs->when(
                !empty($requestData['booking_type']),
                function ($query) use ($requestData) {
                    $query->where("customer_{$requestData['booking_type']}_type", 'yes');
                }
            );
        } else {
            $allJobs->where('job_type', '=', ($consumerType == 'RWS' ? 'rws' : 'unpaid'));
            $allJobs->when(
                !empty($requestData['customer_email']),
                function ($query) use ($requestData) {
                    $user = User::whereEmail($requestData['customer_email'])->first();
                    if ($user) {
                        $query->where('user_id', $user->id);
                    }
                }
            );
        }
        $allJobs->orderBy('created_at', 'desc')
            ->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
        return $allJobs;
    }
    /**
     * Retrieves a list of jobs based on the request data and applies filters to the query.
     *
     * @param Request  $request The request object containing the filters.
     * @param int|null $limit   The number of jobs to return. If set to "all", all jobs that match the filters will be
     *                          returned.
     *
     * @return mixed A list of jobs that match the filters.
     */
    public function getAll(Request $request, $limit = null): mixed
    {
        $requestData = $request->all();
        $allJobs = Job::query();
        $allJobs = $this->applyFilters($allJobs, $requestData);
        if ($requestData['count']) {
            $allJobs = $allJobs->count();
            return ['count' => $allJobs];
        }
        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }
    /**
     * Retrieves a list of jobs that have exceeded their session time and are due for completion.
     *
     * @return array An array containing the jobs and associated language data, paginated with 15 records per page.
     */
    public function getAlerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration && $diff[$i] >= $job->duration * 2) {
                    $sesJobs[$i] = $job;
                }

                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $request = app('request');
        $requestData = $request->all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $currentUser = Auth::user();
        $consumerType = TeHelper::getUsermeta($currentUser->id, 'consumer_type');
        $allJobs = Job::with(['language'])
            ->whereIn('jobs.id', $jobId);
        if ($currentUser && $currentUser->is('superadmin')) {
            $allJobs = $allJobs->when(
                isset($requestData['lang']) && $requestData['lang'] != '',
                function ($query) use ($requestData) {
                    return $query->whereIn('jobs.from_language_id', $requestData['lang'])
                        ->where('jobs.ignore', 0);
                }
            )
                ->when(
                    isset($requestData['status']) && $requestData['status'] != '',
                    function ($query) use ($requestData) {
                        return $query->whereIn('jobs.status', $requestData['status'])
                            ->where('jobs.ignore', 0);
                    }
                )
                ->when(
                    isset($requestData['customer_email']) && $requestData['customer_email'] != '',
                    function ($query) use ($requestData) {
                        $user = User::where('email', $requestData['customer_email'])->first();
                        if ($user) {
                            return $query->where('jobs.user_id', '=', $user->id)
                                ->where('jobs.ignore', 0);
                        }
                    }
                )
                ->when(
                    isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created",
                    function ($query) use ($requestData) {
                        return $query->when(
                            isset($requestData['from']) && $requestData['from'] != "",
                            function ($q) use ($requestData) {
                                return $q->where('jobs.created_at', '>=', $requestData["from"])
                                    ->where('jobs.ignore', 0);
                            }
                        )
                        ->when(
                            isset($requestData['to']) && $requestData['to'] != "",
                            function ($q) use ($requestData) {
                                $to = $requestData["to"] . " 23:59:00";
                                return $q->where('jobs.created_at', '<=', $to)
                                    ->where('jobs.ignore', 0);
                            }
                        )
                        ->orderBy('jobs.created_at', 'desc');
                    }
                )
                ->when(
                    isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due",
                    function ($query) use ($requestData) {
                        return $query->when(
                            isset($requestData['from']) && $requestData['from'] != "",
                            function ($q) use ($requestData) {
                                return $q->where('jobs.due', '>=', $requestData["from"])
                                    ->where('jobs.ignore', 0);
                            }
                        )
                        ->when(
                            isset($requestData['to']) && $requestData['to'] != "",
                            function ($q) use ($requestData) {
                                $to = $requestData["to"] . " 23:59:00";
                                return $q->where('jobs.due', '<=', $to)
                                    ->where('jobs.ignore', 0);
                            }
                        )
                        ->orderBy('jobs.due', 'desc');
                    }
                )
                ->when(
                    isset($requestData['job_type']) && $requestData['job_type'] != '',
                    function ($query) use ($requestData) {
                        return $query->whereIn('jobs.job_type', $requestData['job_type'])
                            ->where('jobs.ignore', 0);
                    }
                );
        } else {
            $allJobs = $allJobs->where('job_type', '=', ($consumerType == 'RWS' ? 'rws' : 'unpaid'));
        }

        $allJobs = $allJobs->select('jobs.*', 'languages.language')
            ->where('jobs.ignore', 0)
            ->whereIn('jobs.id', $jobId)
            ->orderBy('jobs.created_at', 'desc')
            ->paginate(15);
        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestData
        ];
    }

    /**
     * Retrieve bookings that have expired without being accepted.
     *
     * @return array
     */
    public function bookingExpireNoAccepted(): array
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $request = app('request');
        $requestData = $request->all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $currentUser = Auth::user();
        $consumerType = TeHelper::getUsermeta($currentUser->id, 'consumer_type');
        $allJobs = Job::with(['language'])
            ->where('jobs.ignore_expired', 0)
            ->whereIn('jobs.status', ['pending'])
            ->where('jobs.due', '>=', Carbon::now());

        if ($currentUser && ($currentUser->is('superadmin') || $currentUser->is('admin'))) {
            if (!empty($requestData['lang'])) {
                $allJobs->whereIn('jobs.from_language_id', $requestData['lang']);
            }
            if (!empty($requestData['status'])) {
                $allJobs->whereIn('jobs.status', $requestData['status']);
            }
            if (!empty($requestData['customer_email'])) {
                $user = User::where('email', $requestData['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id);
                }
            }
            if (!empty($requestData['translator_email'])) {
                $user = User::where('email', $requestData['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')
                        ->where('user_id', $user->id)
                        ->pluck('job_id')
                        ->toArray();
                    $allJobs->whereIn('jobs.id', $allJobIDs);
                }
            }
            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('jobs.created_at', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (!empty($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (!empty($requestData['from'])) {
                    $allJobs->where('jobs.due', '>=', $requestData["from"]);
                }
                if (!empty($requestData['to'])) {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (!empty($requestData['job_type'])) {
                $allJobs->whereIn('jobs.job_type', $requestData['job_type']);
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());
        } else {
            $allJobs = $allJobs->where('job_type', '=', ($consumerType == 'RWS' ? 'rws' : 'unpaid'));
        }
        $allJobs->orderBy('jobs.created_at', 'desc');
        $allJobs = $allJobs->paginate(15);

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestData
        ];
    }
    /**
     * Retrieves a list of throttle records for users who have exceeded the login attempt limit and have not been
     * ignored.
     *
     * @param int $page The page number to retrieve.
     *
     * @return array An array containing the throttle records and associated user data, paginated with 15 records per
     *               page.
     */
    public function userLoginFailed($page = 0)
    {
        $throttles = Throttles::where('ignore', 0)
            ->with('user')
            ->paginate(15, '*', '', $page);
        return ['throttles' => $throttles];
    }
    /**
     * Ignore a job or mark it as ignore expired.
     *
     * @param int    $id   The ID of the job to ignore.
     * @param string $type The type of ignore to perform. Can be 'expire' to mark the
     *                     job as ignore expired or 'ignore' to ignore the job.
     *
     * @return array An array containing a status message and a success or error
     *                        message.
     */
    public function ignoreJob($id, $type)
    {
        $job = Job::find($id);
        if ($type == 'expire') {
            $job->ignore_expired = 1;
        } elseif ($type == 'ignore') {
            $job->ignore = 1;
        } else {
            return ['error', 'Invalid type'];
        }
        $job->save();
        return ['success', 'Changes saved'];
    }
    /**
     * Ignores the throttle limit for a given job.
     *
     * @param int $id The ID of the throttle record to update.
     *
     * @return array An array containing a success message.
     */
    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }
    /**
     * Reopens a cancelled or timed out job for a given user.
     *
     * @param Request $request The request object containing the job ID and user ID.
     *
     * @return array An array containing a success or error message.
     */
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = [
            'created_at' => date('Y-m-d H:i:s'),
            'will_expire_at' => TeHelper::willExpireAt(
                $job['due'],
                date('Y-m-d H:i:s')
            ),
            'updated_at' => date('Y-m-d H:i:s'),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now()
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now())
        ];

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt(
                $job['due'],
                date('Y-m-d H:i:s')
            );
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #'
                . $jobid;

            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }

        Translator::where('job_id', $jobid)
            ->whereNull('cancel_at')
            ->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }
    /**
     * Convert number of minutes to hour and minute variant
     *
     * @param int    $time   The number of minutes to convert
     * @param string $format The format of the output string (default: '%02dh
     *                       %02dmin', where %02d pads with zeros to 2 digits)
     *
     * @return string The converted time in hours and minutes
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'in'; // added a space before 'in' for better readability
        } elseif ($time == 60) {
            return '1 hour'; // changed '1h' to '1 hour' for better readability
        }
        $hours = floor($time / 60);
        $minutes = $time % 60;
        return sprintf($format, $hours, $minutes);
    }
}
