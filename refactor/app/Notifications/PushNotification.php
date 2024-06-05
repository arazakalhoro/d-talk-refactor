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
 * @category Notification_Class
 * @package  DTApi\Notification
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */

namespace DTApi\Notifications;

use DTApi\Services\OneSingleService;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class PushNotification
 *
 * @category Notification_Class
 * @package  DTApi\Notification
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */
class PushNotification
{
    /**
     * The Logger instance for push logs.
     *
     * @var Logger
     */
    protected $pusher_logger;

    /**
     * The OneSingleService instance.
     *
     * @var OneSingleService
     */
    protected $one_signal;

    /**
     * PushNotification constructor.
     * Initializes the OneSingleService and Logger for push notifications.
     */
    public function __construct()
    {
        $this->one_signal = new OneSingleService();
        $this->pusher_logger = new Logger('push_logger');
        $this->pusher_logger->pushHandler(
            new StreamHandler(
                storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'),
                Logger::DEBUG
            )
        );
        $this->pusher_logger->pushHandler(new FirePHPHandler());
    }

    /**
     * TEMP method to send session start remind notification.
     *
     * @param object $user        The user object.
     * @param object $job         The job object.
     * @param string $language    The language of the job.
     * @param string $due         The due time of the job.
     * @param int    $duration    The duration of the job in minutes.
     * @param bool   $isNeedDelay Boolean indicating if the notification should be
     *                            delayed.
     *
     * @return void
     */
    public function sendSessionStartRemindNotification(
        $user,
        $job,
        $language,
        $due,
        $duration,
        $isNeedDelay
    ) {
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        $msg_text = [
            "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl '
                . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och ' .
                'kom ihåg att ge feedback efter utförd tolkning!'
        ];
        if ($job->customer_physical_type == 'yes') {
            $msg_text = [
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town
                    . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration
                    . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ];
        }
        $users_array = [$user];
        $this->pusher_logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $isNeedDelay);
    }

    /**
     * Sends a push notification to a list of specific users.
     *
     * @param array $users       An array of users to send the push notification to.
     * @param int   $jobId       The ID of the job associated with the push notification.
     * @param array $data        An array of data to include in the push notification.
     * @param array $msgText     The text of the push notification.
     * @param bool  $isNeedDelay A boolean indicating whether or not the notification should be delayed.
     *
     * @return void
     */
    public function sendPushNotificationToSpecificUsers(
        array $users,
        int $jobId,
        array $data,
        array $msgText,
        bool $isNeedDelay
    ) {
        $this->pusher_logger->addInfo("Push send for job $jobId", [$users, $data, $msgText, $isNeedDelay]);
        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] === 'uitable_job') {
            $androidSound = $data['immediate'] ? 'normal_booking' : 'emergency_booking';
            $iosSound = $androidSound .= '.mp3';
        }

        $fields = [
            'tags' => $userTags,
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msgText,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $androidSound,
            'ios_sound' => $iosSound,
        ];

        if ($isNeedDelay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }
        $response = $this->one_signal->sendPushNotification($fields, $jobId);
        $this->pusher_logger->addInfo("Push send for job $jobId curl answer", $response);
    }

    /**
     * Create a user_tags string from an array of users for OneSignal notifications.
     *
     * @param array $users An array of users.
     *
     * @return string The user_tags string.
     */
    private function getUserTagsStringFromArray(array $users): string
    {
        $userTags = [];
        foreach ($users as $oneUser) {
            $userTags[] = [
                "key" => "email",
                "relation" => "=",
                "value" => strtolower($oneUser->email)
            ];
        }
        return json_encode($userTags);
    }
}
